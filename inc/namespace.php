<?php

namespace H2Selector;

/**
 * Bootstrap.
 */
function bootstrap() {
	API\bootstrap();
	UI\bootstrap();
}

/**
 * Get sites that can be activated.
 *
 * @return WP_Site[] List of sites on the network.
 */
function get_available_sites() {
	return get_sites( [
		'archived' => false,
		'deleted'  => false,
		'spam'     => false,
	] );
}

/**
 * Sanitize the `h2_sites` option value.
 *
 * @param mixed $value Unsanitized, raw value.
 * @return int[] List of site IDs.
 */
function sanitize_sites( $value ) {
	$available_ids = array_map( function ( $site ) {
		return $site->blog_id;
	}, get_available_sites() );

	// Ensure entries are integers first.
	$sites = array_map( 'absint', (array) $value );

	// Then, ensure the integers are valid site IDs.
	$sanitised = array_intersect( $sites, $available_ids );

	return $sanitised;
}

/**
 * Get the sites which are active in the selector.
 *
 * @param boolean $check_access True to only return sites the current user can access, false otherwise.
 * @return WP_Site[] List of site objects.
 */
function get_active_sites( $check_access = true ) {
	$current = get_site_option( 'h2_sites', [] );
	if ( empty( $current ) ) {
		return [];
	}

	$sites = array_map( 'get_site', $current );

	// Allow any public sites, or any sites that the user can access.
	$accessible = array_filter( $sites, function ( $site ) use ( $check_access ) {
		// Skip invalid IDs/sites.
		if ( empty( $site ) ) {
			return false;
		}

		// If we don't care about access, keep all sites.
		if ( ! $check_access ) {
			return true;
		}

		if ( $site->public ) {
			return true;
		}

		return current_user_can_for_blog( $site->id, 'read' );
	} );

	return array_values( $accessible );
}
