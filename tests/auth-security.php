<?php
/** Authentication checks against a disposable database, without changing users/passwords. */
$root = getenv( 'LIMU_WP_ROOT' );
if ( ! $root ) {
	fwrite( STDERR, "Set LIMU_WP_ROOT to a disposable WordPress installation.\n" );
	exit( 1 );
}
require $root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
	exit( 1 );
}
$admin = get_user_by( 'login', 'crm-admin' );
if ( ! $admin || ! $admin->user_email ) {
	fwrite( STDERR, "Run integration.php first to prepare the test administrator.\n" );
	exit( 1 );
}
$passed = 0;
function auth_check( $result, $message ) {
	global $passed;
	if ( ! $result ) {
		throw new Exception( $message );
	}
	++$passed;
	echo "PASS $message\n";
}
$original_peer = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
$_SERVER['REMOTE_ADDR'] = '198.51.100.241';
$GLOBALS['lcrm_login_request'] = true;
$pair = LimuCRM\login_rate_key( $admin->user_login );
$peer = LimuCRM\login_peer_rate_key();
$unknown = LimuCRM\login_rate_key( 'auth-security-unknown-user' );
$rotated = LimuCRM\login_rate_key( 'auth-security-rotated-user' );
$hash_checks = 0;
$count_hashes = function ( $checked ) use ( &$hash_checks ) {
	++$hash_checks;
	return $checked;
};
add_filter( 'check_password', $count_hashes );
try {
	foreach ( array( $pair, $peer, $unknown, $rotated ) as $key ) {
		delete_transient( $key );
	}
	auth_check( $pair === LimuCRM\login_rate_key( $admin->user_email ), 'Login and email aliases share the failure budget' );
	auth_check( $pair === LimuCRM\login_rate_key( strtoupper( $admin->user_login ) ), 'Login case does not create a separate failure budget' );
	set_transient( $pair, 10, 240 );
	$timeout = get_option( '_transient_timeout_' . $pair );
	$result = wp_authenticate( $admin->user_login, 'auth-security-known-wrong-password' );
	auth_check( is_wp_error( $result ) && in_array( 'lcrm_login_limited', $result->get_error_codes(), true ), 'Pair lock rejects WordPress password authentication' );
	auth_check( 0 === $hash_checks, 'Exhausted pair is rejected before password hashing' );
	$result = wp_authenticate( $admin->user_email, 'auth-security-known-wrong-password' );
	auth_check( is_wp_error( $result ) && 0 === $hash_checks, 'Email authentication cannot bypass pre-hash pair protection' );
	auth_check( $timeout === get_option( '_transient_timeout_' . $pair ), 'Blocked retries do not extend an existing pair lockout' );
	delete_transient( $pair );
	set_transient( $peer, 50, 240 );
	$peer_timeout = get_option( '_transient_timeout_' . $peer );
	$result = wp_authenticate( $admin->user_login, 'auth-security-known-wrong-password' );
	auth_check( is_wp_error( $result ) && in_array( 'lcrm_login_limited', $result->get_error_codes(), true ) && 0 === $hash_checks, 'CRM peer spray budget rejects a fresh account before hashing' );
	LimuCRM\login_failed_attempt( 'auth-security-rotated-user' );
	auth_check( false === get_transient( $rotated ) && false === get_transient( $pair ), 'Exhausted CRM peer cannot create buckets for rotated usernames' );
	auth_check( $peer_timeout === get_option( '_transient_timeout_' . $peer ), 'Rotated usernames cannot extend an exhausted peer lockout' );
	delete_transient( $pair );
	unset( $GLOBALS['lcrm_login_request'] );
	$result = wp_authenticate( $admin->user_login, 'auth-security-known-wrong-password' );
	auth_check( is_wp_error( $result ) && ! in_array( 'lcrm_login_limited', $result->get_error_codes(), true ) && $hash_checks > 0, 'CRM peer budget does not lock unrelated WordPress authentication' );
	$GLOBALS['lcrm_login_request'] = true;
	delete_transient( $peer );
	$result = wp_authenticate( 'auth-security-unknown-user', 'auth-security-known-wrong-password' );
	auth_check( is_wp_error( $result ) && 1 === (int) get_transient( $peer ), 'Unknown usernames contribute to the CRM peer spray budget' );
	set_transient( $pair, 5, 240 );
	do_action( 'wp_login', $admin->user_login, $admin );
	auth_check( false === get_transient( $pair ), 'Successful login clears the canonical account pair budget' );
	auth_check( 1 === (int) get_transient( $peer ), 'Successful login preserves the shared peer spray budget' );
	echo "OK $passed authentication security assertions\n";
} finally {
	remove_filter( 'check_password', $count_hashes );
	foreach ( array( $pair, $peer, $unknown, $rotated ) as $key ) {
		delete_transient( $key );
	}
	unset( $GLOBALS['lcrm_login_request'] );
	if ( null === $original_peer ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $original_peer;
	}
}
