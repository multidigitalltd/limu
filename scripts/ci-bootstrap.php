<?php
/** Install synthetic WordPress fixtures or prepare browser credentials in CI only. */
$ci_directory = getenv( 'LIMU_CI_DIR' );
$ci_root      = getenv( 'LIMU_WP_ROOT' );
$ci_mode      = $argv[1] ?? '';
if ( 'cli' !== PHP_SAPI || ! $ci_directory || ! $ci_root || ! in_array( $ci_mode, array( 'install', 'member' ), true ) || ! is_file( $ci_directory . '/.limu-ci' ) || realpath( $ci_root ) !== realpath( $ci_directory . '/wordpress' ) ) {
	fwrite( STDERR, "A prepared disposable CI installation is required.\n" );
	exit( 1 );
}
if ( 'install' === $ci_mode ) {
	define( 'WP_INSTALLING', true );
}
require $ci_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to modify a non-test database.\n" );
	exit( 1 );
}
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

/** Write generated test credentials outside both Git and the web document root. */
function ci_credentials( $path, $username, $password ) {
	$result = file_put_contents( $path, wp_json_encode( array( 'user' => $username, 'password' => $password ) ) );
	if ( false === $result || ! chmod( $path, 0600 ) ) {
		throw new RuntimeException( 'Unable to store private CI credentials.' );
	}
}

if ( 'install' === $ci_mode ) {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( is_blog_installed() ) {
		throw new RuntimeException( 'CI installation requires an empty disposable database.' );
	}
	$ci_password = wp_generate_password( 48, true, true );
	$installed   = wp_install( 'CRM CI fixtures', 'crm-admin', 'admin@example.test', false, '', $ci_password );
	if ( is_wp_error( $installed ) ) {
		throw new RuntimeException( 'WordPress test installation failed.' );
	}
	update_option( 'timezone_string', 'Asia/Jerusalem' );
	update_option( 'permalink_structure', '/%postname%/' );
	$activated = activate_plugin( 'limu-crm/limu-crm.php' );
	if ( is_wp_error( $activated ) ) {
		throw new RuntimeException( 'CRM activation failed.' );
	}
	ci_credentials( $ci_directory . '/test-login.json', 'crm-admin', $ci_password );
	echo "Disposable WordPress installed; CRM activated; mail and real external HTTP blocked.\n";
} else {
	$ci_member = get_user_by( 'login', 'institution-test' );
	if ( ! $ci_member || ! user_can( $ci_member, 'lcrm_view' ) ) {
		throw new RuntimeException( 'Run the integration fixture setup before browser tests.' );
	}
	$ci_password = wp_generate_password( 48, true, true );
	wp_set_password( $ci_password, $ci_member->ID );
	ci_credentials( $ci_directory . '/member-login.json', 'institution-test', $ci_password );
	echo "Private institution browser fixture prepared.\n";
}
