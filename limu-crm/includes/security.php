<?php
/**
 * Authentication abuse protection without storing passwords.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Rate-limit password login by normalized login and actual peer IP; never trust forwarded headers.
 *
 * @param string $username Login name without password data.
 * @return mixed Operation result or validation error.
 */
function login_rate_key( $username ) {
	$peer  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'local';
	$login = strtolower( trim( (string) $username ) );
	$user  = get_user_by( 'login', $login );
	if ( ! $user && is_email( $login ) ) {
		$user = get_user_by( 'email', $login );
	}
	if ( $user ) {
		$login = strtolower( $user->user_login );
	}
	return 'lcrm_login_' . hash_hmac( 'sha256', $login . '|' . $peer, wp_salt( 'auth' ) );
}

/**
 * Build a peer-only key for CRM password-spray protection without storing its IP.
 *
 * @return string Private rate bucket key.
 */
function login_peer_rate_key() {
	$peer = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'local';
	return 'lcrm_peer_' . hash_hmac( 'sha256', $peer, wp_salt( 'auth' ) );
}

/**
 * Check existing pair protection and the CRM-only peer failure budget.
 *
 * @param string $username Login or email alias.
 * @return bool Whether this request is rate limited.
 */
function login_is_limited( $username ) {
	return (int) get_transient( login_rate_key( $username ) ) >= 10 || ( ! empty( $GLOBALS['lcrm_login_request'] ) && (int) get_transient( login_peer_rate_key() ) >= 50 );
}

/**
 * Count failed password/challenge attempts without extending already-blocked buckets.
 *
 * @param string $username Login or email alias.
 * @return void
 */
function login_failed_attempt( $username ) {
	if ( ! empty( $GLOBALS['lcrm_login_request'] ) && (int) get_transient( login_peer_rate_key() ) >= 50 ) {
		return;
	}
	$buckets = array( login_rate_key( $username ) => 10 );
	if ( ! empty( $GLOBALS['lcrm_login_request'] ) ) {
		$buckets[ login_peer_rate_key() ] = 50;
	}
	foreach ( $buckets as $key => $limit ) {
		$count = (int) get_transient( $key );
		if ( $count < $limit ) {
			set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );
		}
	}
}

// Core invokes this filter before wp_check_password, for username and email sign-on.
add_filter(
	'wp_authenticate_user',
	function ( $user, $password ) {
		if ( $user instanceof \WP_User && '' !== (string) $password && login_is_limited( $user->user_login ) ) {
			return new \WP_Error( 'lcrm_login_limited', 'לא ניתן להתחבר. נסו שוב מאוחר יותר.' );
		}
		return $user;
	},
	1,
	2
);
add_filter(
	'authenticate',
	function ( $user, $username, $password ) {
		if ( '' === (string) $username || '' === (string) $password ) {
			return $user;
		}
		if ( login_is_limited( $username ) ) {
			return new \WP_Error( 'lcrm_login_limited', 'לא ניתן להתחבר. נסו שוב מאוחר יותר.' );
		}
		return $user;
	},
	100,
	3
);
add_action(
	'wp_login_failed',
	__NAMESPACE__ . '\\login_failed_attempt'
);
add_action(
	'wp_login',
	function ( $username ) {
		delete_transient( login_rate_key( $username ) );
	}
);

add_filter(
	'rest_post_dispatch',
	function ( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/limu-crm/v1/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	},
	10,
	3
);
