<?php
/**
 * Network-wide ("universal") search.
 *
 * Searches every H2 site on the network in a single request by querying the
 * ElasticPress indices for all of the sites at once. Hits are then resolved
 * back to the WordPress objects they represent on their own sites.
 */

namespace H2\Network\Search;

use DateTimeInterface;
use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use H2\Network;
use WP_Comment;
use WP_Error;
use WP_Post;
use WP_Site;

/**
 * Maximum number of results which can be paged through.
 *
 * Matches Elasticsearch's default `index.max_result_window`; paging deeper
 * than this is expensive, and rarely useful.
 */
const MAX_RESULTS = 10000;

/**
 * Markers wrapped around matched terms in highlighted fragments.
 *
 * Private-use Unicode characters, so that they survive having HTML stripped
 * and escaped from the fragment before being swapped for <mark> elements.
 */
const HIGHLIGHT_START = "\u{E000}";
const HIGHLIGHT_END = "\u{E001}";

/**
 * Bootstrap search functionality.
 */
function bootstrap() : void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );
	add_filter( 'ep_feature_active', __NAMESPACE__ . '\\activate_comments_feature', 10, 3 );
}

/**
 * Register the search REST API route.
 */
function register_rest_routes() : void {
	$controller = new REST_Controller();
	$controller->register_routes();
}

/**
 * Check whether ElasticPress is available to run searches.
 *
 * @return bool
 */
function is_available() : bool {
	return class_exists( 'ElasticPress\\Elasticsearch' ) && class_exists( 'ElasticPress\\Indexables' );
}

/**
 * Force the ElasticPress Comments feature on.
 *
 * ElasticPress only indexes comments while the feature is active, and search
 * covers comments across the whole network.
 *
 * @param bool   $active   Whether the feature is active.
 * @param array  $settings Settings for all features.
 * @param object $feature  The feature being checked.
 * @return bool
 */
function activate_comments_feature( $active, $settings, $feature ) {
	// Only apply on H2 sites.
	if ( ! Network\is_h2() ) {
		return $active;
	}

	if ( empty( $feature->slug ) || $feature->slug !== 'comments' ) {
		return $active;
	}

	return isset( get_types()['comment'] ) ? true : $active;
}

/**
 * Get the sites the current user is allowed to search.
 *
 * Defaults to the sites shown in the site switcher which the current user can
 * access: public sites, plus any the user is a member of.
 *
 * @return WP_Site[] Sites, keyed by ID.
 */
function get_searchable_sites() : array {
	$sites = Network\get_active_sites( true );

	/**
	 * Filter the sites the current user is allowed to search.
	 *
	 * @param WP_Site[] $sites Sites the current user can search.
	 */
	$sites = apply_filters( 'h2_network_searchable_sites', $sites );

	$keyed = [];
	foreach ( $sites as $site ) {
		$keyed[ (int) $site->id ] = $site;
	}

	return $keyed;
}

/**
 * Get the post types included in search.
 *
 * @return string[] Post type slugs.
 */
function get_searchable_post_types() : array {
	/**
	 * Filter the post types included in network search.
	 *
	 * Applies to both posts and the comments left on them.
	 *
	 * @param string[] $post_types Post type slugs.
	 */
	return apply_filters( 'h2_network_search_post_types', [ 'post', 'page' ] );
}

/**
 * Get the available result types.
 *
 * Each type corresponds to an ElasticPress indexable, and describes how to
 * query it and how to turn hits back into WordPress objects.
 *
 * @return array<string, array> Map of type slug to definition. Each definition has:
 *   - string   `indexable`  ElasticPress indexable slug.
 *   - string   `id_field`   Indexed field holding the object's ID. Only present on this type's documents.
 *   - string   `date_field` Indexed field holding the (GMT) publication date.
 *   - array    `highlight`  Map of result highlight keys to Elasticsearch highlight options,
 *                           each including the indexed `field` to highlight.
 *   - callable `query`      Builds the Elasticsearch query for the type: fn( Query $query ) : array
 *   - callable `resolve`    Resolves a hit's source document to a WordPress object, or null if it
 *                           is no longer available: fn( array $source, WP_Site $site ) : ?object
 *                           Called with the site switched to.
 */
function get_types() : array {
	$types = [
		'post' => [
			'indexable' => 'post',
			'id_field' => 'post_id',
			'date_field' => 'post_date_gmt',
			'highlight' => [
				'title' => [
					'field' => 'post_title',
					'number_of_fragments' => 0,
				],
				'content' => [
					'field' => 'post_content',
					'fragment_size' => 150,
					'number_of_fragments' => 2,
				],
			],
			'query' => __NAMESPACE__ . '\\build_post_query',
			'resolve' => __NAMESPACE__ . '\\resolve_post',
		],
		'comment' => [
			'indexable' => 'comment',
			'id_field' => 'comment_ID',
			'date_field' => 'comment_date_gmt',
			'highlight' => [
				'content' => [
					'field' => 'comment_content',
					'fragment_size' => 150,
					'number_of_fragments' => 2,
				],
			],
			'query' => __NAMESPACE__ . '\\build_comment_query',
			'resolve' => __NAMESPACE__ . '\\resolve_comment',
		],
	];

	/**
	 * Filter the result types available to network search.
	 *
	 * @param array<string, array> $types Map of type slug to definition. See get_types().
	 */
	return apply_filters( 'h2_network_search_types', $types );
}

/**
 * Run a search across the network.
 *
 * Only sites the current user can access are searched, regardless of the
 * sites requested in the query.
 *
 * @param Query $query Search parameters.
 * @return array|WP_Error {
 *     Search results, or an error if the search could not be run.
 *
 *     @type int      $total   Total number of matching results across all pages.
 *     @type Result[] $results Results for the requested page.
 * }
 */
function search( Query $query ) {
	if ( ! is_available() ) {
		return new WP_Error(
			'h2_search_unavailable',
			__( 'Search is not available on this network.', 'h2' ),
			[ 'status' => 503 ]
		);
	}

	$empty = [
		'total' => 0,
		'results' => [],
	];
	if ( trim( $query->search ) === '' ) {
		return $empty;
	}

	$sites = get_searchable_sites();
	if ( ! empty( $query->sites ) ) {
		$sites = array_intersect_key( $sites, array_flip( $query->sites ) );
	}

	$types = get_types();
	if ( ! empty( $query->types ) ) {
		$types = array_intersect_key( $types, array_flip( $query->types ) );
	}

	if ( empty( $sites ) || empty( $types ) ) {
		return $empty;
	}

	$request = build_request( $query, $sites, $types );
	if ( is_wp_error( $request ) ) {
		return $request;
	}

	$response = send_request( $request['indices'], $request['body'] );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$results = [];
	foreach ( $response['hits']['hits'] ?? [] as $hit ) {
		$result = resolve_hit( $hit, $request['index_map'], $types, $sites );
		if ( $result ) {
			$results[] = $result;
		}
	}

	// Elasticsearch 7+ reports the total as an object.
	$total = $response['hits']['total'] ?? 0;
	if ( is_array( $total ) ) {
		$total = $total['value'] ?? 0;
	}

	return [
		'total' => (int) $total,
		'results' => $results,
	];
}

/**
 * Build the Elasticsearch request for a search.
 *
 * @param Query     $query Search parameters.
 * @param WP_Site[] $sites Sites to search, keyed by ID.
 * @param array     $types Result types to include, keyed by slug. See get_types().
 * @return array|WP_Error {
 *     Request parts, or an error if none of the types can be searched.
 *
 *     @type string[] $indices   Indices to search.
 *     @type array    $index_map Map of index name to `type` slug and `site` ID.
 *     @type array    $body      Search request body.
 * }
 */
function build_request( Query $query, array $sites, array $types ) {
	$indexables = Indexables::factory();
	$indices = [];
	$index_map = [];
	$clauses = [];
	$date_fields = [];
	$source_fields = [];
	$highlight_fields = [];

	foreach ( $types as $slug => $type ) {
		$indexable = $indexables->get( $type['indexable'] );
		if ( empty( $indexable ) ) {
			// Indexable isn't registered (e.g. its feature is unavailable).
			continue;
		}

		foreach ( $sites as $site ) {
			$index = $indexable->get_index_name( $site->id );
			$indices[] = $index;
			$index_map[ $index ] = [
				'type' => $slug,
				'site' => (int) $site->id,
			];
		}

		$clauses[] = call_user_func( $type['query'], $query );
		$date_fields[] = $type['date_field'];
		$source_fields[] = $type['id_field'];

		foreach ( $type['highlight'] as $options ) {
			$field = $options['field'];
			unset( $options['field'] );
			$highlight_fields[ $field ] = $options;
		}
	}

	if ( empty( $clauses ) ) {
		return new WP_Error(
			'h2_search_unavailable',
			__( 'None of the requested result types are indexed.', 'h2' ),
			[ 'status' => 503 ]
		);
	}

	$body = [
		'from' => $query->get_offset(),
		'size' => $query->per_page,
		// Results are loaded fresh from the database, so only the IDs are needed.
		'_source' => array_values( array_unique( $source_fields ) ),
		'query' => [
			'bool' => [
				// Each type's clause filters to its own documents, so a
				// document can only ever match one of them.
				'should' => $clauses,
				'minimum_should_match' => 1,
			],
		],
		'highlight' => [
			'pre_tags' => [ HIGHLIGHT_START ],
			'post_tags' => [ HIGHLIGHT_END ],
			'fields' => $highlight_fields,
		],
	];

	if ( $query->orderby === 'date' ) {
		$body['sort'] = [
			[
				'_script' => [
					'type' => 'number',
					'script' => [
						'lang' => 'painless',
						'source' => get_date_script( $date_fields ) . ' return date == null ? 0L : date.toInstant().toEpochMilli();',
					],
					'order' => $query->order,
				],
			],
		];
	} else {
		$body['query'] = apply_recency_boost( $body['query'], $date_fields );
		$body['sort'] = [
			[
				'_score' => [
					'order' => $query->order,
				],
			],
		];
	}

	/**
	 * Filter the Elasticsearch request body for a network search.
	 *
	 * @param array $body  Search request body.
	 * @param Query $query Search parameters.
	 */
	$body = apply_filters( 'h2_network_search_request', $body, $query );

	return [
		'indices' => $indices,
		'index_map' => $index_map,
		'body' => $body,
	];
}

/**
 * Send a search request to Elasticsearch.
 *
 * Uses the Multi Search API rather than a plain search, as it accepts the
 * index list and search options in the request body. ElasticPress escapes
 * request URLs for display, which mangles query-string parameters, and a long
 * list of indices would exceed URL length limits.
 *
 * @param string[] $indices Indices to search.
 * @param array    $body    Search request body.
 * @return array|WP_Error Decoded search response, or an error.
 */
function send_request( array $indices, array $body ) {
	$header = [
		'index' => array_values( array_unique( $indices ) ),
		// Sites which have never been indexed have no index yet.
		'ignore_unavailable' => true,
		// Use global term statistics, so that scores are comparable across
		// indices of very different sizes.
		'search_type' => 'dfs_query_then_fetch',
	];

	$response = Elasticsearch::factory()->remote_request(
		'_msearch',
		[
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/x-ndjson',
			],
			'body' => wp_json_encode( $header ) . "\n" . wp_json_encode( $body ) . "\n",
		],
		[],
		'query'
	);

	if ( is_wp_error( $response ) ) {
		return search_error( __( 'Unable to reach the search service.', 'h2' ), $response->get_error_message() );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$result = $data['responses'][0] ?? null;

	$failed = (int) wp_remote_retrieve_response_code( $response ) !== 200
		|| ! is_array( $result )
		|| ! empty( $result['error'] )
		|| ! isset( $result['hits'] );
	if ( $failed ) {
		$error = $result['error'] ?? $data['error'] ?? null;
		$details = is_array( $error ) ? ( $error['reason'] ?? $error['type'] ?? null ) : $error;
		return search_error( __( 'The search request failed.', 'h2' ), is_scalar( $details ) ? (string) $details : null );
	}

	return $result;
}

/**
 * Create an error for a failed search request.
 *
 * Details of the failure are only exposed when debugging.
 *
 * @param string      $message User-facing message.
 * @param string|null $details Details of the underlying failure.
 * @return WP_Error
 */
function search_error( string $message, ?string $details = null ) : WP_Error {
	$data = [
		'status' => 502,
	];
	if ( $details && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$data['details'] = $details;
	}

	return new WP_Error( 'h2_search_failed', $message, $data );
}

/**
 * Build the Elasticsearch query for posts.
 *
 * @param Query $query Search parameters.
 * @return array Elasticsearch bool query.
 */
function build_post_query( Query $query ) : array {
	/**
	 * Filter the fields searched for posts.
	 *
	 * Fields may include a boost, e.g. `post_title^2`.
	 *
	 * @param string[] $fields Indexed field names.
	 */
	$fields = apply_filters( 'h2_network_search_post_fields', [ 'post_title^2', 'post_excerpt', 'post_content' ] );

	$filter = [
		[
			'terms' => [
				'post_type.raw' => get_searchable_post_types(),
			],
		],
		[
			'term' => [
				'post_status' => 'publish',
			],
		],
	];
	if ( $query->author ) {
		$filter[] = [
			'term' => [
				'post_author.id' => $query->author,
			],
		];
	}
	$date_range = build_date_range( $query );
	if ( ! empty( $date_range ) ) {
		$filter[] = [
			'range' => [
				'post_date_gmt' => $date_range,
			],
		];
	}

	return [
		'bool' => [
			'filter' => $filter,
			'must_not' => [
				// Exclude password-protected posts. An `exists` query can't be
				// used, as an empty password is still indexed as a value.
				[
					'wildcard' => [
						'post_password' => '*',
					],
				],
			],
			'should' => build_match_clauses( $query, $fields ),
			'minimum_should_match' => 1,
		],
	];
}

/**
 * Build the Elasticsearch query for comments.
 *
 * @param Query $query Search parameters.
 * @return array Elasticsearch bool query.
 */
function build_comment_query( Query $query ) : array {
	/**
	 * Filter the fields searched for comments.
	 *
	 * Fields may include a boost, e.g. `comment_content^2`.
	 *
	 * @param string[] $fields Indexed field names.
	 */
	$fields = apply_filters( 'h2_network_search_comment_fields', [ 'comment_content' ] );

	$filter = [
		[
			'term' => [
				'comment_approved.raw' => '1',
			],
		],
		[
			'term' => [
				'comment_post_status' => 'publish',
			],
		],
		[
			'terms' => [
				'comment_post_type.raw' => get_searchable_post_types(),
			],
		],
	];
	if ( $query->author ) {
		$filter[] = [
			'term' => [
				'user_id' => $query->author,
			],
		];
	}
	$date_range = build_date_range( $query );
	if ( ! empty( $date_range ) ) {
		$filter[] = [
			'range' => [
				'comment_date_gmt' => $date_range,
			],
		];
	}

	return [
		'bool' => [
			'filter' => $filter,
			'must_not' => [
				[
					'terms' => [
						'comment_type.raw' => [ 'pingback', 'trackback' ],
					],
				],
			],
			'should' => build_match_clauses( $query, $fields ),
			'minimum_should_match' => 1,
		],
	];
}

/**
 * Build the clauses matching the search text against a set of fields.
 *
 * Matches are tiered so that exact phrase matches score highest, followed by
 * documents containing every term, then documents containing any term.
 *
 * @param Query    $query  Search parameters.
 * @param string[] $fields Indexed field names, optionally with boosts.
 * @return array Elasticsearch query clauses, at least one of which must match.
 */
function build_match_clauses( Query $query, array $fields ) : array {
	return [
		[
			'multi_match' => [
				'query' => $query->search,
				'type' => 'phrase',
				'fields' => $fields,
				'boost' => 4,
			],
		],
		[
			'multi_match' => [
				'query' => $query->search,
				'fields' => $fields,
				'operator' => 'and',
				'boost' => 2,
			],
		],
		[
			'multi_match' => [
				'query' => $query->search,
				'fields' => $fields,
				// Fuzzy matching only makes sense when ordering by relevance,
				// as otherwise weak matches would be interleaved with strong ones.
				'fuzziness' => $query->orderby === 'relevance' ? 1 : 0,
			],
		],
	];
}

/**
 * Build the date range filter for a search.
 *
 * @param Query $query Search parameters.
 * @return array Elasticsearch range parameters, empty if no dates are set.
 */
function build_date_range( Query $query ) : array {
	$range = [];
	if ( $query->after ) {
		$range['gt'] = format_date( $query->after );
	}
	if ( $query->before ) {
		$range['lt'] = format_date( $query->before );
	}

	return $range;
}

/**
 * Format a date for comparison with indexed dates.
 *
 * ElasticPress indexes dates in MySQL format; GMT fields are compared here.
 *
 * @param DateTimeInterface $date Date to format.
 * @return string
 */
function format_date( DateTimeInterface $date ) : string {
	return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
}

/**
 * Build a Painless snippet resolving a document's publication date.
 *
 * Each result type stores its date in a different field, so the fields are
 * checked in turn. The snippet leaves the date in a `date` variable, which is
 * null if the document has none.
 *
 * @param string[] $date_fields Indexed date fields, one per result type.
 * @return string
 */
function get_date_script( array $date_fields ) : string {
	$script = 'def date = null;';
	foreach ( array_unique( $date_fields ) as $field ) {
		$script .= sprintf(
			" if (date == null && doc.containsKey('%1\$s') && doc['%1\$s'].size() > 0) { date = doc['%1\$s'].value; }",
			$field
		);
	}

	return $script;
}

/**
 * Wrap a query so that recent results are boosted.
 *
 * @param array    $es_query    Elasticsearch query to wrap.
 * @param string[] $date_fields Indexed date fields, one per result type.
 * @return array Elasticsearch query.
 */
function apply_recency_boost( array $es_query, array $date_fields ) : array {
	/**
	 * Filter the parameters of the recency boost.
	 *
	 * Scores are multiplied by up to 2, decaying exponentially with the age of
	 * the result. Results newer than `offset` receive the full boost, decaying
	 * to `decay` at `scale` beyond that. Tuned to roughly match the legacy
	 * decay from HMES; see https://observablehq.com/@rmccue/visualising-decay.
	 *
	 * @param array|null $params Boost parameters (`scale`, `offset` and `decay`), or null to disable.
	 */
	$params = apply_filters( 'h2_network_search_recency_boost', [
		'scale' => '14d',
		'offset' => '7d',
		'decay' => 0.2,
	] );
	if ( empty( $params ) ) {
		return $es_query;
	}

	$source = get_date_script( $date_fields )
		. ' if (date == null) { return _score; }'
		. ' return _score * (1 + decayDateExp(params.origin, params.scale, params.offset, params.decay, date));';

	return [
		'function_score' => [
			'query' => $es_query,
			'script_score' => [
				'script' => [
					'lang' => 'painless',
					'source' => $source,
					'params' => $params + [
						'origin' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					],
				],
			],
			'boost_mode' => 'replace',
		],
	];
}

/**
 * Resolve a hit to a result.
 *
 * @param array     $hit       Hit from Elasticsearch.
 * @param array     $index_map Map of index name to `type` slug and `site` ID.
 * @param array     $types     Result types being searched, keyed by slug.
 * @param WP_Site[] $sites     Sites being searched, keyed by ID.
 * @return Result|null Result, or null if the hit can't be used.
 */
function resolve_hit( array $hit, array $index_map, array $types, array $sites ) : ?Result {
	$identity = identify_hit( $hit, $index_map, $types );
	if ( ! $identity ) {
		return null;
	}

	// Never expose anything from a site or type that wasn't asked for.
	if ( ! isset( $sites[ $identity['site'] ] ) || ! isset( $types[ $identity['type'] ] ) ) {
		return null;
	}

	$site = $sites[ $identity['site'] ];
	$type = $types[ $identity['type'] ];

	switch_to_blog( $site->id );
	$object = call_user_func( $type['resolve'], $hit['_source'] ?? [], $site );
	restore_current_blog();

	if ( ! $object ) {
		return null;
	}

	$result = new Result();
	$result->type = $identity['type'];
	$result->site = $site;
	$result->object = $object;
	$result->score = isset( $hit['_score'] ) ? (float) $hit['_score'] : null;
	$result->highlight = prepare_highlight( $hit['highlight'] ?? [], $type['highlight'] );

	return $result;
}

/**
 * Work out which type and site a hit belongs to.
 *
 * @param array $hit       Hit from Elasticsearch.
 * @param array $index_map Map of index name to `type` slug and `site` ID.
 * @param array $types     Result types being searched, keyed by slug.
 * @return array|null Array with `type` slug and `site` ID, or null if unknown.
 */
function identify_hit( array $hit, array $index_map, array $types ) : ?array {
	$index = $hit['_index'] ?? '';
	if ( isset( $index_map[ $index ] ) ) {
		return $index_map[ $index ];
	}

	// The hit's index may be a concrete index behind an alias, so fall back
	// to inspecting the document, and parsing the site from the index name
	// in the same way ElasticPress does.
	$source = $hit['_source'] ?? [];
	foreach ( $types as $slug => $type ) {
		if ( ! isset( $source[ $type['id_field'] ] ) ) {
			continue;
		}

		$site_id = Elasticsearch::factory()->parse_site_id( $index );
		if ( ! $site_id ) {
			return null;
		}

		return [
			'type' => $slug,
			'site' => $site_id,
		];
	}

	return null;
}

/**
 * Resolve a post hit to the post.
 *
 * @param array   $source Source document from Elasticsearch.
 * @param WP_Site $site   Site the document belongs to.
 * @return WP_Post|null Post, or null if it can no longer be shown.
 */
function resolve_post( array $source, WP_Site $site ) : ?WP_Post {
	$post = get_post( (int) ( $source['post_id'] ?? 0 ) );
	if ( ! $post || ! is_post_visible( $post ) ) {
		return null;
	}

	return $post;
}

/**
 * Resolve a comment hit to the comment.
 *
 * @param array   $source Source document from Elasticsearch.
 * @param WP_Site $site   Site the document belongs to.
 * @return WP_Comment|null Comment, or null if it can no longer be shown.
 */
function resolve_comment( array $source, WP_Site $site ) : ?WP_Comment {
	$comment = get_comment( (int) ( $source['comment_ID'] ?? 0 ) );
	if ( ! $comment || $comment->comment_approved !== '1' ) {
		return null;
	}

	$post = get_post( $comment->comment_post_ID );
	if ( ! $post || ! is_post_visible( $post ) ) {
		return null;
	}

	return $comment;
}

/**
 * Check whether a post can be shown in search results.
 *
 * This protects against a stale index, and ensures the current user can
 * actually access the post (either it's published, or they have the caps).
 *
 * @param WP_Post $post Post to check.
 * @return bool
 */
function is_post_visible( WP_Post $post ) : bool {
	return ( 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID ) )
		&& empty( $post->post_password )
		&& in_array( $post->post_type, get_searchable_post_types(), true );
}

/**
 * Prepare a hit's highlighted fragments for output.
 *
 * @param array $highlight Highlighted fragments from Elasticsearch, keyed by indexed field.
 * @param array $config    Highlight configuration for the type. See get_types().
 * @return array<string, string[]> Sanitized fragments, keyed by highlight key.
 */
function prepare_highlight( array $highlight, array $config ) : array {
	$prepared = [];
	foreach ( $config as $key => $options ) {
		$fragments = $highlight[ $options['field'] ] ?? [];
		if ( empty( $fragments ) ) {
			continue;
		}

		$prepared[ $key ] = array_map( __NAMESPACE__ . '\\sanitize_highlight_fragment', $fragments );
	}

	return $prepared;
}

/**
 * Sanitize a highlighted fragment for output.
 *
 * Fragments are cut from the raw indexed content, so may contain (partial)
 * markup. Everything is stripped and escaped, leaving only <mark> elements
 * around the matches.
 *
 * @param string $fragment Fragment from Elasticsearch, with matches wrapped in the highlight markers.
 * @return string HTML.
 */
function sanitize_highlight_fragment( string $fragment ) : string {
	// Drop the tail of any comment (e.g. a block delimiter) cut off at the start.
	$text = preg_replace( '/^[^<]*?-->/', '', $fragment );
	$text = wp_strip_all_tags( $text, true );
	$text = esc_html( trim( $text ) );

	return str_replace( [ HIGHLIGHT_START, HIGHLIGHT_END ], [ '<mark>', '</mark>' ], $text );
}
