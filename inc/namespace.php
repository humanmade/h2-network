<?php

namespace H2\Network;

/**
 * Bootstrap.
 */
function bootstrap() {
	API\bootstrap();
	UI\bootstrap();

	add_action( 'plugins_loaded', __NAMESPACE__ . '\\override_settings' );
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

/**
 * Override site settings.
 *
 * Relaxes WordPress' validation of comments and users to better handle a
 * communication forum.
 */
function override_settings() {
	// Only apply on H2 sites.
	$theme = get_stylesheet();
	if ( $theme !== 'h2' ) {
		return;
	}

	// Disable moderation and whitelisting.
	if ( get_site_option( 'h2_override_moderation', false ) ) {
		add_filter( 'pre_option_comment_moderation', '__return_zero' );
		add_filter( 'pre_option_comment_whitelist',  '__return_zero' );

		// Override maximum allowed number of links.
		add_filter( 'pre_option_comment_max_links', function () {
			return 100;
		} );
	}

	// Allow short usernames.
	if ( get_site_option( 'h2_allow_short_usernames', false ) ) {
		add_filter( 'wpmu_validate_user_signup', __NAMESPACE__ . '\\allow_short_usernames' );
	}
}

/**
 * Allow very short user names.
 *
 * @param array $result Result of the user validation
 * @return array
 */
function allow_short_usernames( $result ) {
    $error_name = $result['errors']->get_error_message( 'user_name' );
    if ( empty( $error_name ) || $error_name !== __( 'Username must be at least 4 characters.' ) ) {
        return $result;
	}

    $result['errors']->remove( 'user_name' );
    return $result;
}
