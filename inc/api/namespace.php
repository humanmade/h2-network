<?php

namespace H2\Network\API;

use H2\Network;
use WP_Error;
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
 * @return WP_REST_Response|WP_Error Response data, or a site projection error.
 */
function get_sites_for_api( WP_REST_Request $request ) {
	$data = [];
	foreach ( Network\get_active_sites() as $site ) {
		$prepared = prepare_site_for_api( $site );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$data[] = [
			'id'              => $site->id,
			'network'         => $site->network_id,
			'name'            => $site->blogname,
			'url'             => $site->home,
			'siteurl'         => $site->siteurl,
			'network_id'      => $prepared['network_id'],
			'description'     => $prepared['description'],
			'host'            => $prepared['host'],
			'registered_at'   => $prepared['registered_at'],
			'last_updated_at' => $prepared['last_updated_at'],
			'status'          => $prepared['status'],
		];
	}

	return rest_ensure_response( $data );
}

/**
 * Get all H2 sites eligible for catalogue consumers.
 *
 * @return WP_Site[] List of sites on the network.
 */
function get_catalogue_sites() {
	$sites = get_sites( [
		'number'  => 0,
		'mature'  => false,
		'spam'    => false,
		'deleted' => false,
	] );

	$sites = array_filter( $sites, function ( WP_Site $site ) {
		switch_to_blog( $site->blog_id );
		try {
			return 'h2' === get_stylesheet();
		} finally {
			restore_current_blog();
		}
	} );

	return array_values( $sites );
}

/**
 * Get the complete H2 site catalogue.
 *
 * This can include non-public site metadata. Callers must enforce access.
 *
 * @return array[]|WP_Error Site catalogue data ordered by hostname, or an error.
 */
function get_site_catalogue() {
	$data = prepare_sites_for_api( get_catalogue_sites() );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	usort( $data, function ( $a, $b ) {
		return strcasecmp( $a['host'], $b['host'] );
	} );

	return $data;
}

/**
 * Prepare sites for API consumers.
 *
 * @param WP_Site[] $sites Sites to prepare.
 * @return array[]|WP_Error Prepared site data, or the first projection error.
 */
function prepare_sites_for_api( array $sites ) {
	$data = [];
	foreach ( $sites as $site ) {
		$prepared = prepare_site_for_api( $site );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$data[] = $prepared;
	}

	return $data;
}

/**
 * Prepare one H2 site for API consumers.
 *
 * @param WP_Site $site Site to prepare.
 * @return array|WP_Error Site data, or an error if the site cannot be represented.
 */
function prepare_site_for_api( WP_Site $site ) {
	switch_to_blog( $site->blog_id );
	try {
		$url = home_url();
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $url || ! $host ) {
			return new WP_Error(
				'h2_network_site_catalogue_invalid_url',
				'The H2 site catalogue contains a site without a valid home URL.',
				[
					'status'  => 500,
					'site_id' => (int) $site->blog_id,
				]
			);
		}

		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		$archived = (bool) (int) $site->archived;

		return [
			'id'              => (int) $site->blog_id,
			'network_id'      => (int) $site->site_id,
			'name'            => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, $charset ),
			'description'     => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, $charset ),
			'url'             => $url,
			'host'            => $host,
			'registered_at'   => $site->registered ?: null,
			'last_updated_at' => $site->last_updated ?: null,
			'status'          => [
				'active'   => ! $archived,
				'public'   => (bool) (int) $site->public,
				'archived' => $archived,
			],
		];
	} finally {
		restore_current_blog();
	}
}
