<?php

namespace H2\Network\Comments;

use WP_Error;
use WP_REST_Request;

const SINGLE_COMMENT_ROUTE = '/wp/v2/comments/(?P<id>[\d]+)';

function bootstrap() {
	add_filter( 'rest_endpoints', __NAMESPACE__ . '\\replace_permission_checks' );
}

/**
 * Replace core's comment permission checks with our own.
 */
function replace_permission_checks( $endpoints ) {
	if ( ! get_site_option( 'h2_allow_editing_own_comments', false ) ) {
		return $endpoints;
	}

	$single_comment = $endpoints[ SINGLE_COMMENT_ROUTE ] ?? null;
	if ( empty( $single_comment ) ) {
		// Comment endpoint has been removed, return.
		return $endpoints;
	}

	// Override the GET permission callback.
	$original_get_callback = $single_comment[0]['permission_callback'];
	$single_comment[0]['permission_callback'] = function ( WP_REST_Request $request ) use ( $original_get_callback ) {
		return get_comment_permissions_check( $original_get_callback, $request );
	};

	// Override the PUT/PATCH/POST permission callback.
	$original_update_callback = $single_comment[1]['permission_callback'];
	$single_comment[1]['permission_callback'] = function ( WP_REST_Request $request ) use ( $original_update_callback ) {
		return update_comment_permissions_check( $original_update_callback, $request );
	};

	// Override the DELETE permission callback.
	$original_delete_callback = $single_comment[2]['permission_callback'];
	$single_comment[2]['permission_callback'] = function ( WP_REST_Request $request ) use ( $original_delete_callback ) {
		return delete_comment_permissions_check( $original_delete_callback, $request );
	};

	// Override with our new endpoints.
	$endpoints[ SINGLE_COMMENT_ROUTE ] = $single_comment;
	return $endpoints;
}

/**
 * Check if the current user is the author of the requested comment.
 *
 * @param WP_REST_Request $request
 * @return boolean True if the comment is authored by the current user, false otherwise.
 */
function current_user_is_comment_author( WP_REST_Request $request ) : bool {
	$id = (int) $request['id'];
	$comment = get_comment( $id );

	if ( ! $comment ) {
		return false;
	}

	// Check if the comment's author matches the current user.
	if ( get_current_user_id() !== (int) $comment->user_id ) {
		return false;
	}

	// User is editing their own comment, allow it!
	return true;
}

/**
 * Check whether a user can get a comment.
 *
 * Overrides the editing capability check.
 *
 * @param callback $original_callback Original permissions callback
 * @return bool|WP_Error True if the user can perform the action, error otherwise.
 */
function get_comment_permissions_check( $original_callback, WP_REST_Request $request ) {
	$result = call_user_func( $original_callback, $request );
	if ( ! is_wp_error( $result ) ) {
		return $result;
	}

	$code = $result->get_error_code();
	if ( $code !== 'rest_forbidden_context' ) {
		return $result;
	}

	// Allow if user is fetching their own comment.
	return current_user_is_comment_author( $request ) ? true : $result;
}

/**
 * Check whether a user can update a comment.
 *
 * Overrides the editing capability check.
 *
 * @param callback $original_callback Original permissions callback
 * @return bool|WP_Error True if the user can perform the action, error otherwise.
 */
function update_comment_permissions_check( $original_callback, WP_REST_Request $request ) {
	$result = call_user_func( $original_callback, $request );
	if ( ! is_wp_error( $result ) ) {
		return $result;
	}

	$code = $result->get_error_code();
	if ( $code !== 'rest_cannot_edit' ) {
		return $result;
	}

	// Allow if user is updating their own comment.
	return current_user_is_comment_author( $request ) ? true : $result;
}

/**
 * Check whether a user can delete a comment.
 *
 * Overrides the editing capability check.
 *
 * @param callback $original_callback Original permissions callback
 * @return bool|WP_Error True if the user can perform the action, error otherwise.
 */
function delete_comment_permissions_check( $original_callback, WP_REST_Request $request ) {
	$result = call_user_func( $original_callback, $request );
	if ( ! is_wp_error( $result ) ) {
		return $result;
	}

	$code = $result->get_error_code();
	if ( $code !== 'rest_cannot_delete' ) {
		return $result;
	}

	// Allow if user is deleting their own comment.
	return current_user_is_comment_author( $request ) ? true : $result;
}
