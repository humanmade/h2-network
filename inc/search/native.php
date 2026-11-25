<?php
/**
 * Reuse network-search highlights in native WordPress search queries.
 */

namespace H2\Network\Search\Native;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use H2\Network\Search;
use WP_Error;
use WP_Query;

/**
 * Register opt-in ElasticPress integration.
 */
function bootstrap() : void {
	add_filter( 'ep_query_request_args', __NAMESPACE__ . '\\add_highlights', 100, 7 );
	add_filter( 'http_response', __NAMESPACE__ . '\\retry_without_highlights', 10, 2 );
	add_filter( 'ep_es_query_results', __NAMESPACE__ . '\\prepare_results', 10, 5 );
}

/**
 * Add matching passages to a search against the current site's post index.
 *
 * Set `h2_network_search` on a WP_Query to opt in. Fragments are available by
 * post ID in the `h2_network_search_highlights` query variable.
 *
 * @param array  $request   ElasticPress HTTP request.
 * @param string $path      Elasticsearch request path.
 * @param string $index     Index or alias being searched.
 * @param string $type      ElasticPress indexable type.
 * @param array  $formatted Formatted Elasticsearch query.
 * @param array  $args      WordPress query arguments.
 * @param mixed  $query     WordPress query object.
 * @return array
 */
function add_highlights( array $request, string $path, string $index, string $type, array $formatted, array $args, $query ) : array {
	if ( empty( $args['h2_network_search'] ) || ! $query instanceof WP_Query ) {
		return $request;
	}

	$query->set( 'h2_network_search_highlights', [] );
	$query->set( 'h2_network_search_highlight_fields', [] );
	if ( empty( $args['s'] ) || $type !== 'post' || $index !== Indexables::factory()->get( 'post' )->get_index_name() ) {
		return $request;
	}

	$config = Search\get_types()['post']['highlight'] ?? [];
	if ( ! $config ) {
		return $request;
	}

	// Keep rendered fragments separate until they have been checked for the reader.
	$content_field = $config['content']['field'] ?? '';
	if ( $content_field === 'post_content_filtered' ) {
		$config['rendered_content'] = $config['content'];
		unset( $config['content'] );
	} elseif ( $content_field === 'post_content' && apply_filters( 'ep_allow_post_content_filtered_index', true ) ) {
		$config['rendered_content'] = $config['content'];
		$config['rendered_content']['field'] = 'post_content_filtered';
	}

	$fields = [];
	foreach ( $config as $key => $options ) {
		$field = $options['field'];
		unset( $options['field'] );
		// Highlight the search terms without including filters such as post_type.
		$options['highlight_query'] = [ 'match' => [ $field => $args['s'] ] ];
		if ( $key === 'content' || $key === 'rendered_content' ) {
			$options['order'] = 'score';
			$options['fragment_size'] = 220;
			$options['number_of_fragments'] = 1;
		}
		$fields[ $field ] = $options;
	}

	$body = is_string( $request['body'] ?? null ) ? json_decode( $request['body'] ) : null;
	if ( ! is_object( $body ) ) {
		return $request;
	}

	// Keep the same search body for a retry, including any other request filters.
	$retry = $request;
	if ( isset( $body->highlight ) ) {
		unset( $body->highlight );
		$retry['body'] = wp_json_encode( $body );
	}
	$request['h2_network_search_retry'] = [
		'path' => $path,
		'request' => $retry,
		'query_args' => $args,
	];

	$body->highlight = [
		'pre_tags' => [ Search\HIGHLIGHT_START ],
		'post_tags' => [ Search\HIGHLIGHT_END ],
		'fields' => $fields,
	];
	$request['body'] = wp_json_encode( $body );
	$query->set( 'h2_network_search_highlight_fields', $config );

	return $request;
}

/**
 * Recover hits when a field exceeds Elasticsearch's highlighting analysis limit.
 *
 * Older Elasticsearch versions fail the affected shard instead of truncating
 * the snippet. Retry through ElasticPress with the original filters and without
 * highlighting so request authentication and routing run for the new body.
 *
 * @param array|WP_Error $response WordPress HTTP response.
 * @param array          $request  WordPress HTTP request arguments.
 * @return array|WP_Error
 */
function retry_without_highlights( $response, array $request ) {
	$retry = $request['h2_network_search_retry'] ?? null;
	if ( ! $retry || $response instanceof WP_Error ) {
		return $response;
	}

	$body = wp_remote_retrieve_body( $response );
	$limit = 'index.highlight.max_analyzed_offset';
	if ( strpos( $body, $limit ) === false ) {
		return $response;
	}

	$data = json_decode( $body, true );
	$failures = $data['error']['root_cause'] ?? array_column( $data['_shards']['failures'] ?? [], 'reason' );
	foreach ( $failures as $failure ) {
		if ( strpos( $failure['reason'] ?? '', $limit ) !== false ) {
			$result = Elasticsearch::factory()->remote_request( $retry['path'], $retry['request'], $retry['query_args'], 'query' );
			return is_array( $result ) ? $result : $response;
		}
	}

	return $response;
}

/**
 * Keep sanitized snippets separate from the original WordPress post attributes.
 *
 * ElasticPress otherwise replaces titles and content with raw highlight markup.
 * Discard snippets if the response spans sites, as a post ID identifies a post
 * only within its own site.
 *
 * @param array $results   ElasticPress results.
 * @param array $response  Raw response.
 * @param array $formatted Search request.
 * @param array $args      WordPress query arguments.
 * @param mixed $query     WordPress query object.
 * @return array
 */
function prepare_results( array $results, array $response, array $formatted, array $args, $query ) : array {
	if ( empty( $args['h2_network_search'] ) || ! $query instanceof WP_Query ) {
		return $results;
	}

	$config = $query->get( 'h2_network_search_highlight_fields' );
	if ( ! $config ) {
		return $results;
	}
	$query->set( 'h2_network_search_highlight_fields', [] );

	$site_ids = array_column( $results['documents'], 'site_id' );
	$local = count( $site_ids ) === count( $results['documents'] ) && ! array_diff( $site_ids, [ get_current_blog_id() ] );
	$highlights = [];
	foreach ( $results['documents'] as &$document ) {
		if ( $local && ! empty( $document['post_id'] ) && ! empty( $document['highlight'] ) && empty( $document['post_password'] ) ) {
			$post_id = (int) $document['post_id'];
			$matches = Search\prepare_highlight( $document['highlight'], $config );
			$rendered_match = $matches['rendered_content'][0] ?? '';
			if ( empty( $matches['content'] ) && $rendered_match && is_rendered_match_visible( $post_id, $rendered_match ) ) {
				$matches['content'] = [ $rendered_match ];
			}
			unset( $matches['rendered_content'] );
			if ( $matches ) {
				$highlights[ $post_id ] = $matches;
			}
		}
		unset( $document['highlight'] );
	}
	unset( $document );

	$query->set( 'h2_network_search_highlights', $highlights );
	return $results;
}

/**
 * Check a rendered-only passage against the post as seen by the current reader.
 *
 * ElasticPress runs the_content when indexing. Dynamic blocks and shortcodes
 * can produce different output for the indexing user.
 *
 * @param int    $post_id Post ID on the current site.
 * @param string $passage Sanitized passage from the rendered content field.
 * @return bool
 */
function is_rendered_match_visible( int $post_id, string $passage ) : bool {
	$source = get_post( $post_id );
	if ( ! $source || $source->post_password || ( ! is_post_publicly_viewable( $source ) && ! current_user_can( 'read_post', $post_id ) ) ) {
		return false;
	}

	$previous_post = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = $source;
	try {
		$rendered = apply_filters( 'the_content', $source->post_content );
	} finally {
		$GLOBALS['post'] = $previous_post;
	}

	$visible_text = wp_specialchars_decode( wp_strip_all_tags( $rendered, true ), ENT_QUOTES );
	$match_text = wp_specialchars_decode( wp_strip_all_tags( $passage, true ), ENT_QUOTES );
	return $match_text !== '' && strpos( $visible_text, $match_text ) !== false;
}
