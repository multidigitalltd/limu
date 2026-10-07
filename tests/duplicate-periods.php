<?php
/** Duplicate-period boundaries and billing safety in the disposable database only. */
$period_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $period_root ) {
	fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
	exit( 1 );
}
require $period_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
	exit( 1 );
}
register_post_type( 'institutions', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$period_passed = 0;
$period_posts = array();
$period_settings = get_option( 'lcrm_settings', null );
$period_prefix = 'period-' . wp_generate_uuid4();
$period_sequence = 0;
$period_tracker = function ( $id, $post, $update ) use ( &$period_posts ) {
	if ( ! $update ) {
		$period_posts[] = $id;
	}
};
add_action( 'wp_after_insert_post', $period_tracker, 99, 3 );

function period_check( $condition, $message ) {
	global $period_passed;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$period_passed;
	echo "PASS $message\n";
}

function period_set( $mode, $days = 30 ) {
	$config = LimuCRM\settings();
	$config['duplicate_mode'] = $mode;
	$config['duplicate_days'] = $days;
	update_option( 'lcrm_settings', $config, false );
}

function period_settings_request( $mode, $days ) {
	$config = LimuCRM\settings();
	$request = new WP_REST_Request( 'POST', '/limu-crm/v1/settings' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'duplicate_mode' => $mode, 'duplicate_days' => $days, 'start_date' => $config['start_date'], 'automatic' => $config['automatic'] ) ) );
	return rest_do_request( $request );
}

function period_institution( $label, $from ) {
	global $period_prefix;
	$id = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $period_prefix . ' ' . $label ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Unable to create isolated duplicate-period institution.' );
	}
	update_post_meta( $id, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => $from, 'price' => 1000, 'vat_bp' => 1800 ) ) ) );
	return $id;
}

function period_delivery( $institution, $date, $phone = '0597654321', $email = '' ) {
	global $period_prefix, $period_sequence;
	$source = $period_prefix . ':' . ++$period_sequence;
	return LimuCRM\locked( function () use ( $institution, $date, $phone, $email, $source ) {
		return LimuCRM\record_delivery( array( 'name' => 'Synthetic duplicate-period fixture', 'date' => $date, 'phone' => $phone, 'email' => $email, 'form' => 'period-test' ), $institution, $source );
	} );
}

try {
	$admin = get_user_by( 'login', 'crm-admin' );
	if ( ! $admin || ! user_can( $admin, 'manage_options' ) ) {
		throw new RuntimeException( 'Run the disposable integration setup first.' );
	}
	wp_set_current_user( $admin->ID );
	$month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
	$current = $month . '-20 10:00:00';
	$from = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-11 years' )->format( 'Y-m-d' );
	$custom_response = period_settings_request( 'custom', 45 );
	period_check( 200 === $custom_response->get_status() && 45 === LimuCRM\settings()['duplicate_days'] && 'days:45' === LimuCRM\duplicate_policy( LimuCRM\settings() ), 'Manager can save a bounded custom period through the REST API' );
	$validated_settings = get_option( 'lcrm_settings' );
	foreach ( array( 0, 3651, '1.5', false ) as $invalid_days ) {
		$response = period_settings_request( 'custom', $invalid_days );
		period_check( 400 === $response->get_status() && $validated_settings === get_option( 'lcrm_settings' ), 'Invalid API period is rejected and preserves the previous settings' );
	}
	$unknown_response = period_settings_request( 'unknown', 45 );
	period_check( 400 === $unknown_response->get_status() && $validated_settings === get_option( 'lcrm_settings' ), 'Unknown period selector cannot change manager settings' );
	$boundaries = array(
		'rolling' => array( '2025-03-01 10:00:00', '2026-03-01 10:00:00' ),
		'days_30' => array( '2026-03-01 10:00:00', '2026-03-31 10:00:00' ),
		'days_90' => array( '2026-04-02 10:00:00', '2026-07-01 10:00:00' ),
		'days_180' => array( '2026-01-02 10:00:00', '2026-07-01 10:00:00' ),
		'months_24' => array( '2024-10-07 10:00:00', '2026-10-07 10:00:00' ),
		'days:45' => array( '2026-01-01 10:00:00', '2026-02-15 10:00:00' ),
	);
	foreach ( $boundaries as $mode => $dates ) {
		$before = ( new DateTimeImmutable( $dates[0], wp_timezone() ) )->modify( '-1 second' )->format( 'Y-m-d H:i:s' );
		$inside = ( new DateTimeImmutable( $dates[0], wp_timezone() ) )->modify( '+1 second' )->format( 'Y-m-d H:i:s' );
		period_check( ! LimuCRM\in_window( $before, $dates[1], $mode ), "$mode does not match an expired candidate" );
		period_check( ! LimuCRM\in_window( $dates[0], $dates[1], $mode ), "$mode expires exactly at its boundary" );
		period_check( LimuCRM\in_window( $inside, $dates[1], $mode ), "$mode matches one second inside its boundary" );
	}
	period_check( ! LimuCRM\in_window( '2025-12-31 23:59:59', '2026-01-01 00:00:00', 'calendar' ) && LimuCRM\in_window( '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'calendar' ), 'Calendar policy resets on January 1 and includes the start of the new year' );
	period_check( ! LimuCRM\in_window( '2023-03-01 10:00:00', '2024-02-29 10:00:00', 'rolling' ) && LimuCRM\in_window( '2023-03-01 10:00:01', '2024-02-29 10:00:00', 'rolling' ), 'Existing rolling anniversary semantics remain unchanged across leap day' );
	period_check( ! LimuCRM\in_window( '2026-04-01 00:00:00', '2026-03-01 00:00:00', 'days_30' ), 'A future candidate never identifies an earlier delivery as a duplicate' );
	period_check( ! LimuCRM\same_contact( array( 'phone_key' => '', 'email_key' => '' ), array( 'phone_key' => '', 'email_key' => '' ) ), 'Empty identities never match for any duplicate period' );
	period_check( LimuCRM\same_contact( array( 'phone_key' => '0597654321', 'email_key' => 'first@example.invalid' ), array( 'phone_key' => '0597654321', 'email_key' => 'second@example.invalid' ) ) && LimuCRM\same_contact( array( 'phone_key' => '0597654321', 'email_key' => 'first@example.invalid' ), array( 'phone_key' => '0597654322', 'email_key' => 'first@example.invalid' ) ), 'Additional periods preserve phone OR email matching' );
	period_check( 'days:1' === LimuCRM\duplicate_policy( array( 'duplicate_mode' => 'custom', 'duplicate_days' => 1 ) ) && 'days:3650' === LimuCRM\duplicate_policy( array( 'duplicate_mode' => 'custom', 'duplicate_days' => '3650' ) ), 'Custom policies freeze valid minimum and maximum day counts' );
	foreach ( array( 0, 3651, '1.5', array( 30 ) ) as $invalid_days ) {
		$rejected = false;
		try {
			LimuCRM\duplicate_policy( array( 'duplicate_mode' => 'custom', 'duplicate_days' => $invalid_days ) );
		} catch ( InvalidArgumentException $exception ) {
			$rejected = true;
		}
		period_check( $rejected, 'Invalid custom day count is rejected without broadening billability' );
	}
	foreach ( array( 'unknown', 'days:0', 'days:3651' ) as $invalid_mode ) {
		$rejected = false;
		try {
			LimuCRM\in_window( '2026-01-01 10:00:00', '2026-01-02 10:00:00', $invalid_mode );
		} catch ( InvalidArgumentException $exception ) {
			$rejected = true;
		}
		period_check( $rejected, 'Malformed saved policy fails closed' );
	}

	foreach ( array( 'days_30', 'days_90', 'days_180', 'months_24', 'custom' ) as $mode ) {
		period_set( $mode, 45 );
		$institution = period_institution( $mode, $from );
		$policy = LimuCRM\duplicate_policy( LimuCRM\settings() );
		$boundary = LimuCRM\duplicate_window_start( $current, $policy );
		$base = period_delivery( $institution, $boundary );
		$exact = period_delivery( $institution, $current );
		period_check( ! is_wp_error( $base ) && ! is_wp_error( $exact ) && 0 === $exact['duplicate_of'] && $policy === $exact['duplicate_mode'], "$mode capture persists its policy and excludes the exact expired boundary" );
		$within = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '+1 second' )->format( 'Y-m-d H:i:s' );
		$next = period_delivery( $institution, $within, '0597654322', 'period@example.invalid' );
		$email_match = period_delivery( $institution, ( new DateTimeImmutable( $within, wp_timezone() ) )->modify( '+1 second' )->format( 'Y-m-d H:i:s' ), '0597654323', 'period@example.invalid' );
		period_check( ! is_wp_error( $next ) && ! is_wp_error( $email_match ) && $next['id'] === $email_match['duplicate_of'], "$mode capture still treats email alone as a duplicate" );
	}

	period_set( 'custom', 3650 );
	$long = period_institution( 'long-lookback', $from );
	$long_anchor_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-3600 days' )->format( 'Y-m-d H:i:s' );
	$long_anchor = period_delivery( $long, $long_anchor_date );
	$long_later = period_delivery( $long, $current );
	period_check( ! is_wp_error( $long_later ) && $long_anchor['id'] === $long_later['duplicate_of'], 'Maximum custom period finds a qualifying anchor almost ten years old' );

	period_set( 'custom', 10 );
	$snapshot = period_institution( 'saved-policy', $from );
	$later = period_delivery( $snapshot, $current );
	period_set( 'custom', 2 );
	$earlier_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-9 days' )->format( 'Y-m-d H:i:s' );
	$earlier = period_delivery( $snapshot, $earlier_date );
	$later_now = LimuCRM\data( $later['id'] );
	period_check( ! is_wp_error( $earlier ) && 'days:2' === $earlier['duplicate_mode'] && 'days:10' === $later_now['duplicate_mode'] && $earlier['id'] === $later_now['duplicate_of'], 'Out-of-order capture uses each delivery original custom period after settings change' );

	period_set( 'months_24' );
	$chain = period_institution( 'two-year-chain', $from );
	$chain_a_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-24 months -1 day' )->format( 'Y-m-d H:i:s' );
	$chain_b_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-6 months' )->format( 'Y-m-d H:i:s' );
	$chain_b = period_delivery( $chain, $chain_b_date );
	$chain_c = period_delivery( $chain, $current );
	period_check( $chain_b['id'] === $chain_c['duplicate_of'], 'Two-year chain initially suppresses a matching later delivery' );
	$chain_a = period_delivery( $chain, $chain_a_date );
	period_check( ! is_wp_error( $chain_a ) && $chain_a['id'] === LimuCRM\data( $chain_b['id'] )['duplicate_of'] && 0 === LimuCRM\data( $chain_c['id'] )['duplicate_of'], 'Reconciliation reads anchors beyond one year and duplicates do not renew the two-year window' );

	$frozen = period_institution( 'frozen-two-year-chain', $from );
	$frozen_b = period_delivery( $frozen, $chain_b_date );
	$frozen_c = period_delivery( $frozen, $current );
	$unique = period_delivery( $frozen, $month . '-21 10:00:00', '0597654329' );
	$bill = LimuCRM\locked( function () use ( $frozen, $month ) { return LimuCRM\prepare_bill( $frozen, $month ); } );
	period_check( ! is_wp_error( $bill ) && 1180 === $bill['total'] && 1 === count( $bill['lines'] ), 'Approved-period fixture charges only its unrelated live delivery' );
	$approved = LimuCRM\locked( function () use ( $bill ) { return LimuCRM\approve_bill( $bill['id'] ); } );
	period_check( ! is_wp_error( $approved ), 'Extended-period bill can be approved normally' );
	$before_total = LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $frozen ) )['total'];
	$late_anchor = period_delivery( $frozen, $chain_a_date );
	period_check( is_wp_error( $late_anchor ) && 409 === $late_anchor->get_error_data()['status'], 'Earlier two-year anchor cannot change an excluded duplicate in an approved period' );
	period_check( $before_total === LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $frozen ) )['total'] && 0 === LimuCRM\data( $frozen_b['id'] )['duplicate_of'] && $frozen_b['id'] === LimuCRM\data( $frozen_c['id'] )['duplicate_of'] && $approved === LimuCRM\data( $bill['id'] ), 'Immutable-period conflict rolls back the new anchor and all preceding chain edits' );
	echo "OK $period_passed duplicate-period assertions\n";
} finally {
	remove_action( 'wp_after_insert_post', $period_tracker, 99 );
	foreach ( array_reverse( array_unique( $period_posts ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( null === $period_settings ) {
		delete_option( 'lcrm_settings' );
	} else {
		update_option( 'lcrm_settings', $period_settings, false );
	}
}
