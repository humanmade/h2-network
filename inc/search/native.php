<?php
/** Reuse network-search highlights in native WordPress search queries. */

namespace H2\Network\Search\Native;

use const H2\Network\Search\HIGHLIGHT_END;
use const H2\Network\Search\HIGHLIGHT_START;
use function H2\Network\Search\get_types;
use function H2\Network\Search\prepare_highlight;
use WP_Query;

/** Register opt-in ElasticPress integration. */
function bootstrap() : void {
	add_filter( 'ep_formatted_args', __NAMESPACE__ . '\\format_query', 100, 2 );
	add_filter( 'ep_es_query_results', __NAMESPACE__ . '\\prepare_results', 10, 5 );
}

/** Add shared highlights without replacing WordPress's search constraints or scoring.
 *
 * Set `h2_network_search` on a WP_Query to opt in. Highlighted fragments are
 * returned separately in its `h2_network_search_highlights` query variable.
 *
 * @param array $formatted ElasticPress request.
 * @param array $args WordPress query arguments.
 * @return array
 */
function format_query( array $formatted, array $args ) : array {
	if ( empty( $args['h2_network_search'] ) || empty( $args['s'] ) ) {
		return $formatted;
	}
	$highlight_fields = [];
	foreach ( get_types()['post']['highlight'] as $key => $options ) {
		$field = $options['field'];
		unset( $options['field'] );
		// Highlight the reader's words, not filter terms such as post_type=page.
		// This also works when Altis searches a separate filtered-content field.
		$options['highlight_query'] = [ 'match' => [ $field => $args['s'] ] ];
		if ( $key === 'content' ) {
			// Directory rows need one useful passage, rather than isolated hits.
			$options['order'] = 'score';
			$options['fragment_size'] = 220;
			$options['number_of_fragments'] = 1;
		}
		$highlight_fields[ $field ] = $options;
	}
	$formatted['highlight'] = [
		'pre_tags' => [ HIGHLIGHT_START ],
		'post_tags' => [ HIGHLIGHT_END ],
		'fields' => $highlight_fields,
	];
	return $formatted;
}

/** Keep sanitized snippets separate from the original WordPress post attributes.
 *
 * ElasticPress otherwise replaces titles/content with raw highlight markup.
 * Keeping originals intact also preserves owner data and archive summaries.
 *
 * @param array $results ElasticPress results.
 * @param array $response Raw response.
 * @param array $formatted Search request.
 * @param array $args WordPress query arguments.
 * @param mixed $query WordPress query object.
 * @return array
 */
function prepare_results( array $results, array $response, array $formatted, array $args, $query ) : array {
	if ( empty( $args['h2_network_search'] ) || ! $query instanceof WP_Query ) {
		return $results;
	}
	$highlights = [];
	$config = get_types()['post']['highlight'];
	foreach ( $results['documents'] as &$document ) {
		$highlights[ (int) $document['post_id'] ] = prepare_highlight(
			$document['highlight'] ?? [],
			$config
		);
		unset( $document['highlight'] );
	}
	unset( $document );
	$query->set( 'h2_network_search_highlights', $highlights );
	return $results;
}
