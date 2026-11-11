<?php

namespace H2\Network\API;

use H2\Network;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Site;

const API_NAMESPACE = 'h2/v1';

function bootstrap() {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );
}

/**
 * Register REST API routes for the site switcher and link previews.
 */
function register_rest_routes() {
	register_rest_route( API_NAMESPACE, 'site-switcher/sites', [
		'methods' => WP_REST_Server::READABLE,
		'callback' => __NAMESPACE__ . '\\get_sites_for_api',
		'permission_callback' => '__return_true',
	] );

	register_rest_route( API_NAMESPACE, 'lookup', [
		'methods' => WP_REST_Server::READABLE,
		'callback' => __NAMESPACE__ . '\\lookup_post',
		'permission_callback' => '__return_true',
		'args' => [
			'url' => [
				'description' => __( 'URL or path of the post on this site.', 'h2' ),
				'type' => 'string',
				'required' => true,
			],
		],
	] );
}

/**
 * Find the post at a URL on this site.
 *
 * Other sites on the network use this to preview links, so the URL is
 * resolved with this site's own rewrite rules rather than guessed at.
 *
 * The post is returned from its type's own endpoint (like `/wp/v2/posts/<id>`),
 * so it has the same format and permission checks. Other parameters, like
 * `_fields`, are passed on to that endpoint, and `_embed` works as usual.
 *
 * @param WP_REST_Request $request Request data.
 * @return WP_REST_Response|WP_Error Post data, or an error if no post was found.
 */
function lookup_post( WP_REST_Request $request ) {
	// Use the same error whether the post doesn't exist or can't be read, so
	// that the response doesn't reveal which.
	$not_found = new WP_Error(
		'rest_lookup_not_found',
		__( 'No post was found at that URL.', 'h2' ),
		[ 'status' => 404 ]
	);

	$post_id = url_to_postid( $request['url'] );
	if ( ! $post_id || ! is_post_type_viewable( get_post_type( $post_id ) ) ) {
		return $not_found;
	}

	$route = rest_get_route_for_post( $post_id );
	if ( ! $route ) {
		return $not_found;
	}

	$post_request = new WP_REST_Request( 'GET', $route );
	$post_request->set_query_params( array_diff_key( $request->get_query_params(), [ 'url' => true ] ) );
	$response = rest_do_request( $post_request );
	if ( $response->is_error() ) {
		return $not_found;
	}

	return $response;
}

/**
 * Get site details for the REST API.
 *
 * @param WP_REST_Request $request Request data.
 * @return WP_REST_Response Response data.
 */
function get_sites_for_api( WP_REST_Request $request ) {
	$data = array_map( __NAMESPACE__ . '\\prepare_site_for_api', Network\get_active_sites() );

	return rest_ensure_response( $data );
}

/**
 * Prepare one H2 site for API consumers.
 *
 * @param WP_Site $site Site to prepare.
 * @return array Site data.
 */
function prepare_site_for_api( WP_Site $site ) {
	$charset = get_blog_option( $site->id, 'blog_charset' ) ?: 'UTF-8';
	$archived = (bool) $site->archived;

	return [
		'id'              => $site->id,
		'network'         => $site->network_id,
		'name'            => $site->blogname,
		'url'             => $site->home,
		'siteurl'         => $site->siteurl,
		'network_id'      => (int) $site->network_id,
		'description'     => html_entity_decode( get_blog_option( $site->id, 'blogdescription' ), ENT_QUOTES, $charset ),
		'registered_at'   => $site->registered ?? null,
		'last_updated_at' => $site->last_updated ?? null,
		'status'          => [
			'active'   => ! $archived,
			'public'   => (bool) $site->public,
			'archived' => $archived,
		],
	];
}
