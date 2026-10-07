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
	$peer = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'local';
	return 'lcrm_login_' . hash_hmac( 'sha256', strtolower( trim( (string) $username ) ) . '|' . $peer, wp_salt( 'auth' ) );
}
add_filter(
	'authenticate',
	function ( $user, $username, $password ) {
		if ( '' === (string) $username || '' === (string) $password ) {
			return $user;
		}
		if ( (int) get_transient( login_rate_key( $username ) ) >= 10 ) {
			return new \WP_Error( 'lcrm_login_limited', 'בוצעו ניסיונות התחברות רבים. נסו שוב בעוד 15 דקות.' );
		}
		return $user;
	},
	100,
	3
);
add_action(
	'wp_login_failed',
	function ( $username ) {
		$key = login_rate_key( $username );
		set_transient( $key, min( 10, (int) get_transient( $key ) + 1 ), 15 * MINUTE_IN_SECONDS );
	}
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
		if ( str_starts_with( $request->get_route(), '/limu-crm/v1/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	},
	10,
	3
);
