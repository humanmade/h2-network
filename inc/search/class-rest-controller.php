<?php
/**
 * REST API controller for network search.
 */

namespace H2\Network\Search;

use DateTimeImmutable;
use DateTimeInterface;
use H2\Network\API;
use WP_Comment;
use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Site;
use WP_User;

/**
 * Exposes network search at `GET /h2/v1/search`.
 */
class REST_Controller extends WP_REST_Controller {
	/**
	 * Sites prepared for the response, keyed by ID.
	 *
	 * @var array<int, array>
	 */
	protected array $sites = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = API\API_NAMESPACE;
		$this->rest_base = 'search';
	}

	/**
	 * Register the search route.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
				'args' => $this->get_collection_params(),
			],
			'schema' => [ $this, 'get_public_item_schema' ],
		] );
	}

	/**
	 * Check whether the current user can search.
	 *
	 * Anyone can search; access is enforced per site, so users only ever see
	 * results from sites they can access.
	 *
	 * @param WP_REST_Request $request Request data.
	 * @return true
	 */
	public function get_items_permissions_check( $request ) {
		return true;
	}

	/**
	 * Run a search.
	 *
	 * @param WP_REST_Request $request Request data.
	 * @return WP_REST_Response|WP_Error Response data, or an error.
	 */
	public function get_items( $request ) {
		$query = $this->prepare_query( $request );
		if ( is_wp_error( $query ) ) {
			return $query;
		}

		$results = search( $query );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$total = $results['total'];
		$max_pages = (int) ceil( min( $total, MAX_RESULTS ) / $query->per_page );
		if ( $total > 0 && $query->page > $max_pages ) {
			return new WP_Error(
				'rest_search_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.', 'h2' ),
				[ 'status' => 400 ]
			);
		}

		$items = [];
		foreach ( $results['results'] as $result ) {
			$item = $this->prepare_item_for_response( $result, $request );
			$items[] = $this->prepare_response_for_collection( $item );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $max_pages );

		return $response;
	}

	/**
	 * Build the search query from the request.
	 *
	 * @param WP_REST_Request $request Request data.
	 * @return Query|WP_Error Query, or an error if the request is invalid.
	 */
	protected function prepare_query( WP_REST_Request $request ) {
		$query = new Query();
		$query->search = trim( $request['search'] );
		$query->sites = array_map( 'intval', $request['sites'] );
		$query->types = $request['type'];
		$query->author = $request['author'] ? (int) $request['author'] : null;
		$query->after = $this->parse_date( $request['after'] );
		$query->before = $this->parse_date( $request['before'] );
		$query->orderby = $request['orderby'];
		$query->order = $request['order'];
		$query->page = (int) $request['page'];
		$query->per_page = (int) $request['per_page'];

		if ( $query->get_offset() + $query->per_page > MAX_RESULTS ) {
			return new WP_Error(
				'rest_search_invalid_page_number',
				sprintf(
					/* translators: %d: maximum number of results */
					__( 'Only the first %d results can be paged through.', 'h2' ),
					MAX_RESULTS
				),
				[ 'status' => 400 ]
			);
		}

		$searchable = get_searchable_sites();
		foreach ( $query->sites as $site_id ) {
			if ( ! isset( $searchable[ $site_id ] ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: site ID */
						__( 'Site %d cannot be searched.', 'h2' ),
						$site_id
					),
					[ 'status' => 400 ]
				);
			}
		}

		return $query;
	}

	/**
	 * Parse a date parameter.
	 *
	 * @param string|null $value RFC3339 date, already validated by the schema.
	 * @return DateTimeInterface|null
	 */
	protected function parse_date( ?string $value ) : ?DateTimeInterface {
		if ( empty( $value ) ) {
			return null;
		}

		$timestamp = rest_parse_date( $value );
		if ( $timestamp === false ) {
			return null;
		}

		return new DateTimeImmutable( '@' . $timestamp );
	}

	/**
	 * Validate the search text.
	 *
	 * @param mixed           $value   Value to validate.
	 * @param WP_REST_Request $request Request data.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_search( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( trim( $value ) === '' ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s cannot be empty.', 'h2' ),
					$param
				),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Prepare a result for the response.
	 *
	 * @param Result          $item    Search result.
	 * @param WP_REST_Request $request Request data.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		switch_to_blog( $item->site->id );

		$data = [
			'id' => 0,
			'type' => $item->type,
			'site' => $this->prepare_site( $item->site ),
		];
		if ( $item->object instanceof WP_Post ) {
			$data = array_merge( $data, $this->prepare_post( $item->object ) );
		} elseif ( $item->object instanceof WP_Comment ) {
			$data = array_merge( $data, $this->prepare_comment( $item->object ) );
		}
		$data['score'] = $item->score;
		$data['highlight'] = (object) $item->highlight;

		$links = $this->prepare_links( $item );

		restore_current_blog();

		$response = rest_ensure_response( $data );
		$response->add_links( $links );

		/**
		 * Filter a search result prepared for the REST API.
		 *
		 * @param WP_REST_Response $response Response data.
		 * @param Result           $result   Search result.
		 * @param WP_REST_Request  $request  Request data.
		 */
		return apply_filters( 'h2_network_search_rest_prepare_result', $response, $item, $request );
	}

	/**
	 * Prepare a post result.
	 *
	 * Must be called with the post's site switched to.
	 *
	 * @param WP_Post $post Post to prepare.
	 * @return array
	 */
	protected function prepare_post( WP_Post $post ) : array {
		/** This filter is documented in wp-includes/post-template.php */
		$excerpt = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );

		return [
			'id' => (int) $post->ID,
			'title' => get_the_title( $post ),
			/** This filter is documented in wp-includes/post-template.php */
			'excerpt' => apply_filters( 'the_excerpt', $excerpt ),
			'link' => get_permalink( $post ),
			'date' => mysql_to_rfc3339( $post->post_date ),
			'date_gmt' => mysql_to_rfc3339( $post->post_date_gmt ),
			'author' => $this->prepare_user( get_userdata( (int) $post->post_author ) ),
		];
	}

	/**
	 * Prepare a comment result.
	 *
	 * Must be called with the comment's site switched to.
	 *
	 * @param WP_Comment $comment Comment to prepare.
	 * @return array
	 */
	protected function prepare_comment( WP_Comment $comment ) : array {
		$post = get_post( $comment->comment_post_ID );

		/** This filter is documented in wp-includes/comment-template.php */
		$content = apply_filters( 'comment_text', $comment->comment_content, $comment, [] );

		if ( $comment->user_id ) {
			$author = $this->prepare_user( get_userdata( (int) $comment->user_id ) );
		} else {
			$author = [
				'id' => 0,
				'name' => $comment->comment_author,
				'slug' => null,
				'link' => null,
				'avatar_urls' => rest_get_avatar_urls( $comment ),
			];
		}

		return [
			'id' => (int) $comment->comment_ID,
			'title' => $post ? get_the_title( $post ) : '',
			'excerpt' => wpautop( wp_trim_words( $content, 55 ) ),
			'link' => get_comment_link( $comment ),
			'date' => mysql_to_rfc3339( $comment->comment_date ),
			'date_gmt' => mysql_to_rfc3339( $comment->comment_date_gmt ),
			'author' => $author,
			'post' => $post ? [
				'id' => (int) $post->ID,
				'title' => get_the_title( $post ),
				'link' => get_permalink( $post ),
			] : null,
		];
	}

	/**
	 * Prepare a user for the response.
	 *
	 * @param WP_User|false $user User to prepare.
	 * @return array|null User data, or null if the user no longer exists.
	 */
	protected function prepare_user( $user ) : ?array {
		if ( ! $user instanceof WP_User ) {
			return null;
		}

		return [
			'id' => (int) $user->ID,
			'name' => $user->display_name,
			'slug' => $user->user_nicename,
			'link' => get_author_posts_url( $user->ID, $user->user_nicename ),
			'avatar_urls' => rest_get_avatar_urls( $user ),
		];
	}

	/**
	 * Prepare a site for the response.
	 *
	 * Uses the same format as the site switcher.
	 *
	 * @param WP_Site $site Site to prepare.
	 * @return array
	 */
	protected function prepare_site( WP_Site $site ) : array {
		$id = (int) $site->id;
		if ( ! isset( $this->sites[ $id ] ) ) {
			$this->sites[ $id ] = API\prepare_site_for_api( $site );
		}

		return $this->sites[ $id ];
	}

	/**
	 * Prepare links for a result.
	 *
	 * Links point to the canonical REST API resources on the result's site.
	 * Must be called with the result's site switched to.
	 *
	 * @param Result $result Search result.
	 * @return array Links, keyed by relation.
	 */
	protected function prepare_links( Result $result ) : array {
		$site_id = (int) $result->site->id;
		$links = [];

		if ( $result->object instanceof WP_Post ) {
			$route = rest_get_route_for_post( $result->object );
			if ( $route ) {
				$links['self'] = [
					'href' => get_rest_url( $site_id, $route ),
				];
			}
		} elseif ( $result->object instanceof WP_Comment ) {
			$links['self'] = [
				'href' => get_rest_url( $site_id, sprintf( 'wp/v2/comments/%d', $result->object->comment_ID ) ),
			];

			$post = get_post( $result->object->comment_post_ID );
			$route = $post ? rest_get_route_for_post( $post ) : '';
			if ( $route ) {
				$links['up'] = [
					'href' => get_rest_url( $site_id, $route ),
				];
			}
		}

		return $links;
	}

	/**
	 * Get the query parameters for the search route.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$types = array_keys( get_types() );

		return [
			'search' => [
				'description' => __( 'Text to search for.', 'h2' ),
				'type' => 'string',
				'required' => true,
				'validate_callback' => [ $this, 'validate_search' ],
			],
			'sites' => [
				'description' => __( 'Limit results to these sites. Defaults to every site the current user can access.', 'h2' ),
				'type' => 'array',
				'items' => [
					'type' => 'integer',
				],
				'default' => [],
			],
			'type' => [
				'description' => __( 'Limit results to these types.', 'h2' ),
				'type' => 'array',
				'items' => [
					'type' => 'string',
					'enum' => $types,
				],
				'default' => $types,
			],
			'author' => [
				'description' => __( 'Limit results to those written by this user.', 'h2' ),
				'type' => 'integer',
				'minimum' => 1,
			],
			'after' => [
				'description' => __( 'Limit results to those published after this ISO8601 date.', 'h2' ),
				'type' => 'string',
				'format' => 'date-time',
			],
			'before' => [
				'description' => __( 'Limit results to those published before this ISO8601 date.', 'h2' ),
				'type' => 'string',
				'format' => 'date-time',
			],
			'orderby' => [
				'description' => __( 'Sort results by relevance to the search, or by publication date.', 'h2' ),
				'type' => 'string',
				'enum' => [ 'relevance', 'date' ],
				'default' => 'relevance',
			],
			'order' => [
				'description' => __( 'Order results ascending or descending.', 'h2' ),
				'type' => 'string',
				'enum' => [ 'asc', 'desc' ],
				'default' => 'desc',
			],
			'page' => [
				'description' => __( 'Current page of results.', 'h2' ),
				'type' => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'description' => __( 'Maximum number of results per page.', 'h2' ),
				'type' => 'integer',
				'default' => 10,
				'minimum' => 1,
				'maximum' => 100,
			],
		];
	}

	/**
	 * Get the schema for a search result.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$user_schema = [
			'type' => [ 'object', 'null' ],
			'properties' => [
				'id' => [
					'description' => __( 'User ID, or 0 for a guest.', 'h2' ),
					'type' => 'integer',
				],
				'name' => [
					'description' => __( 'Display name.', 'h2' ),
					'type' => 'string',
				],
				'slug' => [
					'description' => __( 'User slug.', 'h2' ),
					'type' => [ 'string', 'null' ],
				],
				'link' => [
					'description' => __( 'URL of the author archive on the result\'s site.', 'h2' ),
					'type' => [ 'string', 'null' ],
					'format' => 'uri',
				],
				'avatar_urls' => [
					'description' => __( 'Avatar URLs, keyed by size.', 'h2' ),
					'type' => 'object',
				],
			],
		];

		$this->schema = [
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'h2-search-result',
			'type' => 'object',
			'properties' => [
				'id' => [
					'description' => __( 'ID of the result on its site. Only unique in combination with the type and site.', 'h2' ),
					'type' => 'integer',
					'readonly' => true,
				],
				'type' => [
					'description' => __( 'Type of result.', 'h2' ),
					'type' => 'string',
					'enum' => array_keys( get_types() ),
					'readonly' => true,
				],
				'site' => [
					'description' => __( 'Site the result belongs to, in the same format as the site switcher.', 'h2' ),
					'type' => 'object',
					'readonly' => true,
					'properties' => [
						'id' => [
							'type' => 'integer',
						],
						'name' => [
							'type' => 'string',
						],
						'url' => [
							'type' => 'string',
							'format' => 'uri',
						],
					],
				],
				'title' => [
					'description' => __( 'Title of the result. For comments, the title of the post commented on.', 'h2' ),
					'type' => 'string',
					'readonly' => true,
				],
				'excerpt' => [
					'description' => __( 'HTML excerpt of the result.', 'h2' ),
					'type' => 'string',
					'readonly' => true,
				],
				'link' => [
					'description' => __( 'URL of the result.', 'h2' ),
					'type' => 'string',
					'format' => 'uri',
					'readonly' => true,
				],
				'date' => [
					'description' => __( 'Publication date, in the timezone of the site.', 'h2' ),
					'type' => 'string',
					'format' => 'date-time',
					'readonly' => true,
				],
				'date_gmt' => [
					'description' => __( 'Publication date, as GMT.', 'h2' ),
					'type' => 'string',
					'format' => 'date-time',
					'readonly' => true,
				],
				'author' => array_merge( $user_schema, [
					'description' => __( 'Author of the result.', 'h2' ),
					'readonly' => true,
				] ),
				'post' => [
					'description' => __( 'Post commented on. Only present for comment results.', 'h2' ),
					'type' => [ 'object', 'null' ],
					'readonly' => true,
					'properties' => [
						'id' => [
							'type' => 'integer',
						],
						'title' => [
							'type' => 'string',
						],
						'link' => [
							'type' => 'string',
							'format' => 'uri',
						],
					],
				],
				'score' => [
					'description' => __( 'Relevance score. Null when not ordered by relevance.', 'h2' ),
					'type' => [ 'number', 'null' ],
					'readonly' => true,
				],
				'highlight' => [
					'description' => __( 'Fragments showing where the search matched, keyed by field. HTML containing only <mark> elements.', 'h2' ),
					'type' => 'object',
					'readonly' => true,
					'additionalProperties' => [
						'type' => 'array',
						'items' => [
							'type' => 'string',
						],
					],
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
