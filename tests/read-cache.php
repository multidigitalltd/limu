<?php
/** Dashboard cache isolation, invalidation and SQL failure regressions; disposable DB only. */
$cache_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $cache_root ) {
	fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
	exit( 1 );
}
require $cache_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run outside limu_test.\n" );
	exit( 1 );
}
register_post_type( 'institutions', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$cache_posts = array();
$cache_passed = 0;
$cache_settings = get_option( 'lcrm_settings', null );
$cache_admin = get_user_by( 'login', 'crm-admin' );
$cache_user = 0;
$cache_queries = 0;
$cache_tracker = function ( $id, $post, $update ) use ( &$cache_posts ) {
	if ( ! $update ) {
		$cache_posts[] = $id;
	}
};
add_action( 'wp_after_insert_post', $cache_tracker, 99, 3 );
$cache_count = function ( $sql ) use ( &$cache_queries ) {
	if ( false !== strpos( $sql, 'lcrm_delivery' ) || false !== strpos( $sql, 'lcrm_bill' ) ) {
		++$cache_queries;
	}
	return $sql;
};
add_filter( 'posts_request', $cache_count );
function cache_check( $condition, $message ) {
	global $cache_passed;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$cache_passed;
	echo "PASS $message\n";
}
function cache_request( $user, $institution = null, $period = array() ) {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( 'GET', '/limu-crm/v1/summary' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	if ( null !== $institution ) {
		$request->set_param( 'institution', $institution );
	}
	foreach ( $period as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}
function cache_delivery( $institution, $date, $number ) {
	return LimuCRM\locked( static function () use ( $institution, $date, $number ) {
		return LimuCRM\record_delivery( array( 'name' => 'Synthetic cache contact', 'phone' => $number, 'email' => $number . '@example.invalid', 'date' => $date ), $institution, 'cache:' . wp_generate_uuid4() );
	} );
}
try {
	cache_check( $cache_admin && user_can( $cache_admin, 'manage_options' ), 'Disposable administrator is available' );
	wp_set_current_user( $cache_admin->ID );
	$cache_a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => 'Cache institution A' ) );
	$cache_b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => 'Cache institution B' ) );
	$cache_month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
	$cache_config = LimuCRM\settings();
	$cache_config['start_date'] = $cache_month . '-01';
	update_option( 'lcrm_settings', $cache_config, false );
	foreach ( array( $cache_a, $cache_b ) as $cache_id ) {
		update_post_meta( $cache_id, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => $cache_month . '-01', 'price' => 1000, 'vat_bp' => 1800 ) ) ) );
	}
	$cache_first = cache_delivery( $cache_a, $cache_month . '-05 10:00:00', '0591000001' );
	$cache_other = cache_delivery( $cache_b, $cache_month . '-06 10:00:00', '0591000002' );
	cache_check( ! is_wp_error( $cache_first ) && ! is_wp_error( $cache_other ), 'Isolated institution fixtures are recorded' );
	$cache_bill = LimuCRM\locked( static function () use ( $cache_a, $cache_month ) {
		$draft = LimuCRM\prepare_bill( $cache_a, $cache_month );
		return is_wp_error( $draft ) ? $draft : LimuCRM\approve_bill( $draft['id'] );
	} );
	cache_check( ! is_wp_error( $cache_bill ) && 1180 === $cache_bill['total'], 'Approved cache fixture has an exact known amount' );
	LimuCRM\invalidate_summary_cache();
	$cache_before = $cache_queries;
	$cache_one = cache_request( $cache_admin->ID, $cache_a );
	$cache_after = $cache_queries;
	$cache_two = cache_request( $cache_admin->ID, $cache_a );
	cache_check( 200 === $cache_one->get_status() && 1 === $cache_one->get_data()['received_leads'] && 1180 === $cache_one->get_data()['approved'], 'First summary computes the authorized source and billing totals' );
	cache_check( $cache_after > $cache_before && $cache_after === $cache_queries && $cache_one->get_data() === $cache_two->get_data(), 'Repeated summary reuses totals without scanning private records again' );
	$cache_outside = cache_request( $cache_admin->ID, $cache_a, array( 'year' => '1900' ) );
	cache_check( 0 === $cache_outside->get_data()['received_leads'] && 0 === $cache_outside->get_data()['approved'], 'Period filters have separate cache entries' );
	$cache_b_data = cache_request( $cache_admin->ID, $cache_b )->get_data();
	cache_check( 1 === $cache_b_data['received_leads'] && 0 === $cache_b_data['approved'], 'Institution filters never reuse another institution financial totals' );
	$cache_user = wp_create_user( 'cache-' . wp_generate_password( 12, false ), wp_generate_password( 48 ), 'cache-' . wp_generate_uuid4() . '@example.invalid' );
	cache_check( ! is_wp_error( $cache_user ), 'Read-only account is isolated to this test' );
	get_user_by( 'id', $cache_user )->add_cap( 'lcrm_view' );
	update_user_meta( $cache_user, '_lcrm_institutions', array( $cache_a ) );
	$cache_member = cache_request( $cache_user )->get_data();
	cache_check( 1 === $cache_member['received_leads'] && 1180 === $cache_member['approved'] && 0 === $cache_member['draft'], 'Institution account receives only its authorized approved totals' );
	update_user_meta( $cache_user, '_lcrm_institutions', array( $cache_b ) );
	$cache_reassigned = cache_request( $cache_user )->get_data();
	cache_check( 1 === $cache_reassigned['received_leads'] && 0 === $cache_reassigned['approved'] && 403 === cache_request( $cache_user, $cache_a )->get_status(), 'Changing assignments cannot expose a previously cached institution' );
	cache_check( 403 === cache_request( 0, $cache_a )->get_status(), 'Anonymous requests cannot read an existing manager cache entry' );
	wp_set_current_user( $cache_admin->ID );
	$cache_new = cache_delivery( $cache_a, current_time( 'Y-m-d' ) . ' 10:00:00', '0591000003' );
	$cache_new_data = cache_request( $cache_admin->ID, $cache_a )->get_data();
	cache_check( ! is_wp_error( $cache_new ) && 2 === $cache_new_data['received_leads'], 'Committed delivery invalidates previous dashboard totals immediately' );
	$cache_payment = LimuCRM\locked( static function () use ( $cache_bill ) {
		return LimuCRM\record_payment( $cache_bill['id'], array( 'amount' => '5', 'date' => current_time( 'Y-m-d' ), 'method' => 'transfer', 'request_key' => wp_generate_uuid4() ) );
	} );
	cache_check( ! is_wp_error( $cache_payment ) && 500 === cache_request( $cache_admin->ID, $cache_a )->get_data()['paid'], 'Committed payment invalidates cached balances before the next read' );
	$cache_inside = 0;
	$cache_rollback = LimuCRM\locked( static function () use ( $cache_a, &$cache_inside ) {
		$created = LimuCRM\record_delivery( array( 'name' => 'Rolled back contact', 'phone' => '0591000004', 'date' => current_time( 'Y-m-d' ) . ' 11:00:00' ), $cache_a, 'cache:rollback:' . wp_generate_uuid4() );
		if ( is_wp_error( $created ) ) { return $created; }
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'institution', $cache_a );
		$cache_inside = LimuCRM\cached_summary( $request )['received_leads'];
		return LimuCRM\error( 'Intentional rollback.' );
	} );
	cache_check( is_wp_error( $cache_rollback ) && 3 === $cache_inside && 2 === cache_request( $cache_admin->ID, $cache_a )->get_data()['received_leads'], 'In-flight summaries are never cached and rollback preserves previously committed totals' );
	wp_delete_post( $cache_new['id'], true );
	cache_check( 1 === cache_request( $cache_admin->ID, $cache_a )->get_data()['received_leads'], 'Maintenance deletion outside the mutation API invalidates totals' );
	LimuCRM\invalidate_summary_cache();
	$cache_fault = static function ( $sql ) {
		return false !== strpos( $sql, "'lcrm_delivery'" ) && false !== strpos( $sql, 'SELECT' ) ? 'SELECT * FROM lcrm_intentional_missing_table' : $sql;
	};
	$cache_old_errors = $wpdb->suppress_errors( true );
	add_filter( 'query', $cache_fault );
	$cache_failed = cache_request( $cache_admin->ID, $cache_a );
	remove_filter( 'query', $cache_fault );
	$wpdb->suppress_errors( $cache_old_errors );
	cache_check( 503 === $cache_failed->get_status(), 'SQL failure returns a safe error rather than cached partial or empty totals' );
	$cache_recovered = cache_request( $cache_admin->ID, $cache_a );
	cache_check( 200 === $cache_recovered->get_status() && 1 === $cache_recovered->get_data()['received_leads'], 'Retry after a read failure recomputes correct totals instead of reusing an error' );
	cache_check( 400 === cache_request( $cache_admin->ID, $cache_a, array( 'year' => '1900', 'month' => '2026-01' ) )->get_status(), 'Malformed filter combinations are validated before cache access' );
	echo "OK $cache_passed dashboard cache assertions\n";
} finally {
	if ( isset( $cache_fault ) ) { remove_filter( 'query', $cache_fault ); }
	remove_filter( 'posts_request', $cache_count );
	remove_action( 'wp_after_insert_post', $cache_tracker, 99 );
	foreach ( array_reverse( array_unique( $cache_posts ) ) as $cache_id ) { wp_delete_post( $cache_id, true ); }
	if ( $cache_user && ! is_wp_error( $cache_user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $cache_user );
	}
	if ( null === $cache_settings ) { delete_option( 'lcrm_settings' ); } else { update_option( 'lcrm_settings', $cache_settings, false ); }
	LimuCRM\invalidate_summary_cache();
}
