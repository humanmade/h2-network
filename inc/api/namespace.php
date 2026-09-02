<?php

namespace H2\Network\API;

use H2\Network;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Site;

function bootstrap() {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );
}

/**
 * Register REST API routes for the site switcher.
 */
function register_rest_routes() {
	register_rest_route( 'h2/v1', 'site-switcher/sites', [
		'methods' => WP_REST_Server::READABLE,
		'callback' => __NAMESPACE__ . '\\get_sites_for_api',
		'permission_callback' => '__return_true',
	] );
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
