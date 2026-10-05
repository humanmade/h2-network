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
			'type' => $item->type,
			'site' => $this->prepare_site( $item->site ),
			'score' => $item->score,
			'highlight' => (object) $item->highlight,
			'result' => null,
		];
		if ( $item->object instanceof WP_Post ) {
			$data['result'] = $this->prepare_post( $item->object );
		} elseif ( $item->object instanceof WP_Comment ) {
			$data['result'] = $this->prepare_comment( $item->object );
		}

		restore_current_blog();

		$response = rest_ensure_response( $data );

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
	 * Uses the shape of the posts endpoint (`wp/v2/posts`), with the author
	 * embedded. Must be called with the post's site switched to.
	 *
	 * @param WP_Post $post Post to prepare.
	 * @return array
	 */
	protected function prepare_post( WP_Post $post ) : array {
		/** This filter is documented in wp-includes/post-template.php */
		$excerpt = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );

		$data = [
			'id' => (int) $post->ID,
			'date' => mysql_to_rfc3339( $post->post_date ),
			'date_gmt' => mysql_to_rfc3339( $post->post_date_gmt ),
			'type' => $post->post_type,
			'link' => get_permalink( $post ),
			'title' => [
				'rendered' => get_the_title( $post ),
			],
			'excerpt' => [
				/** This filter is documented in wp-includes/post-template.php */
				'rendered' => apply_filters( 'the_excerpt', $excerpt ),
			],
			'author' => (int) $post->post_author,
		];

		$embedded = [];
		$author = $this->prepare_user( get_userdata( (int) $post->post_author ) );
		if ( $author ) {
			$embedded['author'] = [ $author ];
		}

		return $this->add_links_and_embeds( $data, $this->prepare_links( $post ), $embedded );
	}

	/**
	 * Prepare a comment result.
	 *
	 * Uses the shape of the comments endpoint (`wp/v2/comments`), with the
	 * author and the post commented on embedded. Must be called with the
	 * comment's site switched to.
	 *
	 * @param WP_Comment $comment Comment to prepare.
	 * @return array
	 */
	protected function prepare_comment( WP_Comment $comment ) : array {
		$data = [
			'id' => (int) $comment->comment_ID,
			'post' => (int) $comment->comment_post_ID,
			'author' => (int) $comment->user_id,
			'author_name' => $comment->comment_author,
			'date' => mysql_to_rfc3339( $comment->comment_date ),
			'date_gmt' => mysql_to_rfc3339( $comment->comment_date_gmt ),
			'content' => [
				/** This filter is documented in wp-includes/comment-template.php */
				'rendered' => apply_filters( 'comment_text', $comment->comment_content, $comment, [] ),
			],
			'link' => get_comment_link( $comment ),
			'author_avatar_urls' => rest_get_avatar_urls( $comment ),
		];

		$embedded = [];
		$author = $comment->user_id ? $this->prepare_user( get_userdata( (int) $comment->user_id ) ) : null;
		if ( $author ) {
			$embedded['author'] = [ $author ];
		}

		$post = get_post( $comment->comment_post_ID );
		if ( $post ) {
			$embedded['up'] = [
				[
					'id' => (int) $post->ID,
					'link' => get_permalink( $post ),
					'title' => [
						'rendered' => get_the_title( $post ),
					],
				],
			];
		}

		return $this->add_links_and_embeds( $data, $this->prepare_links( $comment ), $embedded );
	}

	/**
	 * Add links and embedded objects to a prepared post or comment.
	 *
	 * These are added in the same format the REST API server uses for
	 * top-level responses, as the server only handles those itself.
	 *
	 * @param array $data     Prepared post or comment.
	 * @param array $links    Links, keyed by relation.
	 * @param array $embedded Lists of embedded objects, keyed by relation.
	 * @return array
	 */
	protected function add_links_and_embeds( array $data, array $links, array $embedded ) : array {
		if ( $links ) {
			$data['_links'] = array_map( fn ( array $link ) => [ $link ], $links );
		}
		if ( $embedded ) {
			$data['_embedded'] = $embedded;
		}

		return $data;
	}

	/**
	 * Prepare a user for the response.
	 *
	 * Uses the shape of the users endpoint (`wp/v2/users`).
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
	 * Prepare links for a post or comment.
	 *
	 * Links point to the canonical REST API resources on the object's site.
	 * Must be called with the object's site switched to.
	 *
	 * @param WP_Post|WP_Comment $object Post or comment.
	 * @return array Links, keyed by relation.
	 */
	protected function prepare_links( $object ) : array {
		$site_id = get_current_blog_id();
		$links = [];

		if ( $object instanceof WP_Post ) {
			$route = rest_get_route_for_post( $object );
			if ( $route ) {
				$links['self'] = [
					'href' => get_rest_url( $site_id, $route ),
				];
			}
		} elseif ( $object instanceof WP_Comment ) {
			$links['self'] = [
				'href' => get_rest_url( $site_id, sprintf( 'wp/v2/comments/%d', $object->comment_ID ) ),
			];

			$post = get_post( $object->comment_post_ID );
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

		$rendered_schema = [
			'type' => 'object',
			'properties' => [
				'rendered' => [
					'type' => 'string',
				],
			],
		];

		$date_schema = [
			'type' => 'string',
			'format' => 'date-time',
		];

		$user_schema = [
			'type' => 'object',
			'properties' => [
				'id' => [
					'description' => __( 'User ID.', 'h2' ),
					'type' => 'integer',
				],
				'name' => [
					'description' => __( 'Display name.', 'h2' ),
					'type' => 'string',
				],
				'slug' => [
					'description' => __( 'User slug.', 'h2' ),
					'type' => 'string',
				],
				'link' => [
					'description' => __( 'URL of the author archive on the result\'s site.', 'h2' ),
					'type' => 'string',
					'format' => 'uri',
				],
				'avatar_urls' => [
					'description' => __( 'Avatar URLs, keyed by size.', 'h2' ),
					'type' => 'object',
				],
			],
		];

		$post_schema = [
			'title' => 'post',
			'description' => __( 'Post, in the shape of the posts endpoint.', 'h2' ),
			'type' => 'object',
			'properties' => [
				'id' => [
					'description' => __( 'ID of the post on its site.', 'h2' ),
					'type' => 'integer',
				],
				'date' => array_merge( $date_schema, [
					'description' => __( 'Publication date, in the timezone of the site.', 'h2' ),
				] ),
				'date_gmt' => array_merge( $date_schema, [
					'description' => __( 'Publication date, as GMT.', 'h2' ),
				] ),
				'type' => [
					'description' => __( 'Post type.', 'h2' ),
					'type' => 'string',
				],
				'link' => [
					'description' => __( 'URL of the post.', 'h2' ),
					'type' => 'string',
					'format' => 'uri',
				],
				'title' => array_merge( $rendered_schema, [
					'description' => __( 'Title of the post.', 'h2' ),
				] ),
				'excerpt' => array_merge( $rendered_schema, [
					'description' => __( 'HTML excerpt of the post.', 'h2' ),
				] ),
				'author' => [
					'description' => __( 'ID of the author.', 'h2' ),
					'type' => 'integer',
				],
				'_embedded' => [
					'description' => __( 'Embedded objects, keyed by relation.', 'h2' ),
					'type' => 'object',
					'properties' => [
						'author' => [
							'description' => __( 'Author of the post. Omitted if the user no longer exists.', 'h2' ),
							'type' => 'array',
							'items' => $user_schema,
						],
					],
				],
			],
		];

		$comment_schema = [
			'title' => 'comment',
			'description' => __( 'Comment, in the shape of the comments endpoint.', 'h2' ),
			'type' => 'object',
			'properties' => [
				'id' => [
					'description' => __( 'ID of the comment on its site.', 'h2' ),
					'type' => 'integer',
				],
				'post' => [
					'description' => __( 'ID of the post commented on.', 'h2' ),
					'type' => 'integer',
				],
				'author' => [
					'description' => __( 'ID of the author, or 0 for a guest.', 'h2' ),
					'type' => 'integer',
				],
				'author_name' => [
					'description' => __( 'Display name of the author.', 'h2' ),
					'type' => 'string',
				],
				'date' => array_merge( $date_schema, [
					'description' => __( 'Publication date, in the timezone of the site.', 'h2' ),
				] ),
				'date_gmt' => array_merge( $date_schema, [
					'description' => __( 'Publication date, as GMT.', 'h2' ),
				] ),
				'content' => array_merge( $rendered_schema, [
					'description' => __( 'HTML content of the comment.', 'h2' ),
				] ),
				'link' => [
					'description' => __( 'URL of the comment.', 'h2' ),
					'type' => 'string',
					'format' => 'uri',
				],
				'author_avatar_urls' => [
					'description' => __( 'Avatar URLs for the author, keyed by size.', 'h2' ),
					'type' => 'object',
				],
				'_embedded' => [
					'description' => __( 'Embedded objects, keyed by relation.', 'h2' ),
					'type' => 'object',
					'properties' => [
						'author' => [
							'description' => __( 'Author of the comment. Omitted for guests.', 'h2' ),
							'type' => 'array',
							'items' => $user_schema,
						],
						'up' => [
							'description' => __( 'Post commented on. Omitted if the post no longer exists.', 'h2' ),
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'id' => [
										'type' => 'integer',
									],
									'link' => [
										'type' => 'string',
										'format' => 'uri',
									],
									'title' => $rendered_schema,
								],
							],
						],
					],
				],
			],
		];

		$this->schema = [
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'h2-search-result',
			'type' => 'object',
			'properties' => [
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
				'result' => [
					'description' => __( 'The post or comment, in the shape of its regular REST API endpoint. Its ID is only unique in combination with the type and site.', 'h2' ),
					'type' => 'object',
					'readonly' => true,
					'oneOf' => [
						$post_schema,
						$comment_schema,
					],
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
