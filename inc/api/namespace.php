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
	$sites = Network\get_active_sites();
	$data = array_map( function ( WP_Site $site ) {
		return [
			'id' => $site->id,
			'network' => $site->network_id,
			'name' => $site->blogname,
			'url' => $site->home,
			'siteurl' => $site->siteurl,
		];
	}, $sites );
	return rest_ensure_response( $data );
}
