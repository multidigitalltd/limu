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
$period_user = 0;
$period_fault = null;
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

function period_set( $institution, $mode, $days = 30 ) {
	$response = period_policy_request( $institution, $mode, $days );
	if ( 200 !== $response->get_status() ) {
		throw new RuntimeException( 'Unable to save isolated institution duplicate policy.' );
	}
}

function period_request( $route, $body = null, $nonce = true ) {
	$request = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/limu-crm/v1/' . $route );
	if ( $nonce ) {
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	}
	$request->set_header( 'Content-Type', 'application/json' );
	if ( null !== $body ) {
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
}

function period_policy_request( $institution, $mode, $days ) {
	return period_request( 'institution-policy', array( 'institution' => $institution, 'duplicate_mode' => $mode, 'duplicate_days' => $days ) );
}

function period_institution( $label, $from = null ) {
	global $period_prefix;
	$id = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $period_prefix . ' ' . $label ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Unable to create isolated duplicate-period institution.' );
	}
	if ( null !== $from ) {
		update_post_meta( $id, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => $from, 'price' => 1000, 'vat_bp' => 1800 ) ) ) );
	}
	return $id;
}

function period_delivery( $institution, $date, $phone = '0597654321', $email = '', $historical = false ) {
	global $period_prefix, $period_sequence;
	$source = $period_prefix . ':' . ++$period_sequence;
	return LimuCRM\locked( function () use ( $institution, $date, $phone, $email, $source, $historical ) {
		return LimuCRM\record_delivery( array( 'name' => 'Synthetic duplicate-period fixture', 'date' => $date, 'phone' => $phone, 'email' => $email, 'form' => 'period-test' ), $institution, $source, $historical );
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
	$unpriced = period_institution( 'unpriced-policy' );
	period_check( 'all_time' === LimuCRM\institution_policy( $unpriced )['duplicate_mode'] && null === LimuCRM\agreement( $unpriced ), 'An unconfigured institution defaults to all history without requiring a tariff' );
	$custom_response = period_policy_request( $unpriced, 'custom', 45 );
	period_check( 200 === $custom_response->get_status() && 45 === LimuCRM\institution_policy( $unpriced )['duplicate_days'] && 'days:45' === LimuCRM\duplicate_policy( LimuCRM\institution_policy( $unpriced ) ) && null === LimuCRM\agreement( $unpriced ), 'Manager saves an independent custom period without a price or effective date' );
	$validated_settings = get_post_meta( $unpriced, '_lcrm_duplicate_policy', true );
	foreach ( array( 0, 3651, '1.5', false, null, array() ) as $invalid_days ) {
		$response = period_policy_request( $unpriced, 'custom', $invalid_days );
		period_check( 400 === $response->get_status() && $validated_settings === get_post_meta( $unpriced, '_lcrm_duplicate_policy', true ), 'Invalid API period is rejected and preserves the previous institution policy' );
	}
	$unknown_response = period_policy_request( $unpriced, 'unknown', 45 );
	period_check( 400 === $unknown_response->get_status() && $validated_settings === get_post_meta( $unpriced, '_lcrm_duplicate_policy', true ), 'Unknown period selector cannot change institution settings' );
	period_check( 400 === period_request( 'institution-policy', array( 'institution' => $unpriced, 'duplicate_mode' => 'custom' ) )->get_status(), 'A custom institution period requires an explicit day count' );
	period_check( 400 === period_policy_request( 0, 'all_time', 30 )->get_status() && 400 === period_policy_request( true, 'all_time', 30 )->get_status(), 'Missing and boolean institution IDs cannot change a duplicate policy' );
	period_check( 400 === period_request( 'institution-policy', array( 'institution' => $unpriced, 'duplicate_mode' => 'all_time', 'price' => '0' ) )->get_status(), 'Policy endpoint rejects tariff fields instead of silently accepting a price edit' );
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
	period_check( null === LimuCRM\duplicate_window_start( $current, 'all_time' ) && LimuCRM\in_window( '1900-01-01 00:00:00', $current, 'all_time' ), 'All-history policy has no lower bound or ten-year cutoff' );
	$identity_anchor = array( 'id' => 1, 'date' => $current, 'phone_key' => '0597654321', 'email_key' => 'first@example.invalid' );
	$identity_index = array( 'phone_key' => array( '0597654321' => array( $identity_anchor ), '' => array( $identity_anchor ) ), 'email_key' => array( 'first@example.invalid' => array( $identity_anchor ), '' => array( $identity_anchor ) ) );
	period_check( array() === LimuCRM\duplicate_candidates( array( 'phone_key' => '', 'email_key' => '' ), $identity_index ), 'Empty identities never match even when empty index buckets exist' );
	$large_index = array();
	for ( $i = 1; $i <= 10000; ++$i ) {
		$anchor = array( 'id' => $i, 'date' => $current, 'phone_key' => '059' . str_pad( (string) $i, 7, '0', STR_PAD_LEFT ), 'email_key' => 'person-' . $i . '@example.invalid' );
		$large_index['phone_key'][ $anchor['phone_key'] ] = array( $anchor );
		$large_index['email_key'][ $anchor['email_key'] ] = array( $anchor );
	}
	$candidates = LimuCRM\duplicate_candidates( array( 'phone_key' => '0590009999', 'email_key' => 'person-10000@example.invalid' ), $large_index );
	period_check( array( 9999, 10000 ) === array_column( $candidates, 'id' ), 'Matching examines only two relevant anchors among ten thousand unrelated contacts in stable date-ID order' );
	$same_candidate = LimuCRM\duplicate_candidates( array( 'phone_key' => '0590009999', 'email_key' => 'person-9999@example.invalid' ), $large_index );
	period_check( 1 === count( $same_candidate ) && 9999 === $same_candidate[0]['id'], 'One prior delivery matching both identities is compared only once' );
	unset( $large_index, $candidates, $same_candidate );
	$phone_candidate = LimuCRM\duplicate_candidates( array( 'phone_key' => '0597654321', 'email_key' => 'second@example.invalid' ), $identity_index );
	$email_candidate = LimuCRM\duplicate_candidates( array( 'phone_key' => '0597654322', 'email_key' => 'first@example.invalid' ), $identity_index );
	period_check( array( $identity_anchor ) === $phone_candidate && array( $identity_anchor ) === $email_candidate, 'Candidate selection preserves phone OR email matching when the other contact field differs' );
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
		$institution = period_institution( $mode, $from );
		period_set( $institution, $mode, 45 );
		$policy = LimuCRM\duplicate_policy( LimuCRM\institution_policy( $institution ) );
		$boundary = LimuCRM\duplicate_window_start( $current, $policy );
		$base = period_delivery( $institution, $boundary );
		$exact = period_delivery( $institution, $current );
		period_check( ! is_wp_error( $base ) && ! is_wp_error( $exact ) && 0 === $exact['duplicate_of'] && $policy === $exact['duplicate_mode'], "$mode capture persists its policy and excludes the exact expired boundary" );
		$within = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '+1 second' )->format( 'Y-m-d H:i:s' );
		$next = period_delivery( $institution, $within, '0597654322', 'period@example.invalid' );
		$email_match = period_delivery( $institution, ( new DateTimeImmutable( $within, wp_timezone() ) )->modify( '+1 second' )->format( 'Y-m-d H:i:s' ), '0597654323', 'period@example.invalid' );
		period_check( ! is_wp_error( $next ) && ! is_wp_error( $email_match ) && $next['id'] === $email_match['duplicate_of'], "$mode capture still treats email alone as a duplicate" );
	}

	$long = period_institution( 'long-lookback', $from );
	period_set( $long, 'custom', 3650 );
	$long_anchor_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-3600 days' )->format( 'Y-m-d H:i:s' );
	$long_anchor = period_delivery( $long, $long_anchor_date );
	$long_later = period_delivery( $long, $current );
	period_check( ! is_wp_error( $long_later ) && $long_anchor['id'] === $long_later['duplicate_of'], 'Maximum custom period finds a qualifying anchor almost ten years old' );

	$snapshot = period_institution( 'saved-policy', $from );
	period_set( $snapshot, 'custom', 10 );
	$later = period_delivery( $snapshot, $current );
	period_set( $snapshot, 'custom', 2 );
	$earlier_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-9 days' )->format( 'Y-m-d H:i:s' );
	$earlier = period_delivery( $snapshot, $earlier_date );
	$later_now = LimuCRM\data( $later['id'] );
	period_check( ! is_wp_error( $earlier ) && 'days:2' === $earlier['duplicate_mode'] && 'days:10' === $later_now['duplicate_mode'] && $earlier['id'] === $later_now['duplicate_of'], 'Out-of-order capture uses each delivery original custom period after settings change' );

	$chain = period_institution( 'two-year-chain', $from );
	period_set( $chain, 'months_24' );
	$chain_a_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-24 months -1 day' )->format( 'Y-m-d H:i:s' );
	$chain_b_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-6 months' )->format( 'Y-m-d H:i:s' );
	$chain_b = period_delivery( $chain, $chain_b_date );
	$chain_c = period_delivery( $chain, $current );
	period_check( $chain_b['id'] === $chain_c['duplicate_of'], 'Two-year chain initially suppresses a matching later delivery' );
	$chain_a = period_delivery( $chain, $chain_a_date );
	period_check( ! is_wp_error( $chain_a ) && $chain_a['id'] === LimuCRM\data( $chain_b['id'] )['duplicate_of'] && 0 === LimuCRM\data( $chain_c['id'] )['duplicate_of'], 'Reconciliation reads anchors beyond one year and duplicates do not renew the two-year window' );

	$frozen = period_institution( 'frozen-two-year-chain', $from );
	period_set( $frozen, 'months_24' );
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
	$tariff_before = LimuCRM\agreement( $frozen );
	$policy_after_approval = period_policy_request( $frozen, 'days_30', 30 );
	period_check( 200 === $policy_after_approval->get_status() && $tariff_before === LimuCRM\agreement( $frozen ) && $approved === LimuCRM\data( $bill['id'] ) && 'months_24' === LimuCRM\data( $frozen_c['id'] )['duplicate_mode'], 'Institution period remains editable after billing approval without changing tariffs or saved delivery policies' );

	// Historical data participates in the same recipient-specific rule, even beyond ten years.
	$legacy_globals = LimuCRM\settings();
	$legacy_globals['duplicate_mode'] = 'custom';
	$legacy_globals['duplicate_days'] = 1;
	update_option( 'lcrm_settings', $legacy_globals, false );
	$all_history = period_institution( 'all-history-default', $from );
	$short_history = period_institution( 'different-institution-window', $from );
	period_set( $short_history, 'days_30' );
	$ancient_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-16 years' )->format( 'Y-m-d H:i:s' );
	$history_a = period_delivery( $all_history, $ancient_date, '0597654310', 'history@example.invalid', true );
	$history_b = period_delivery( $short_history, $ancient_date, '0597654310', 'history@example.invalid', true );
	$matching_rows = LimuCRM\institution_deliveries( $all_history, null, null, false );
	period_check( 1 === count( $matching_rows ) && '0597654310' === $matching_rows[0]['phone_key'] && ! isset( $matching_rows[0]['phone'] ) && ! isset( $matching_rows[0]['name'] ) && 'Synthetic duplicate-period fixture' === LimuCRM\data( $history_a['id'] )['name'], 'Duplicate scans read immutable identity metadata while leaving contact hydration to ordinary reads' );
	$phone_a = period_delivery( $all_history, $current, '0597654310', 'different@example.invalid' );
	$phone_b = period_delivery( $short_history, $current, '0597654310', 'different@example.invalid' );
	period_check( ! is_wp_error( $history_a ) && ! is_wp_error( $phone_a ) && $history_a['id'] === $phone_a['duplicate_of'] && 'all_time' === $phone_a['duplicate_mode'], 'An institution without a rule matches imported phone history sixteen years old despite retired global one-day settings' );
	period_check( ! is_wp_error( $phone_b ) && 0 === $phone_b['duplicate_of'] && 'days_30' === $phone_b['duplicate_mode'], 'The same contact is independently billable at another institution with a shorter window' );
	$email_a = period_delivery( $all_history, $month . '-21 10:00:00', '0597654311', 'history@example.invalid' );
	period_check( $history_a['id'] === $email_a['duplicate_of'], 'All-history institution matches old imported email even when the phone changes' );
	$before_history = LimuCRM\data( $history_a['id'] );
	$zero_bill = LimuCRM\locked( function () use ( $all_history, $month ) { return LimuCRM\prepare_bill( $all_history, $month ); } );
	period_check( ! is_wp_error( $zero_bill ) && 0 === $zero_bill['total'] && array() === $zero_bill['lines'] && $before_history === LimuCRM\data( $history_a['id'] ), 'Old imported matches exclude live duplicates without retroactively billing or promoting historical data' );
	$nearby_date = ( new DateTimeImmutable( $current, wp_timezone() ) )->modify( '-10 days' )->format( 'Y-m-d H:i:s' );
	$nearby_history = period_delivery( $short_history, $nearby_date, '0597654310', '', true );
	period_check( ! is_wp_error( $nearby_history ) && $nearby_history['id'] === LimuCRM\data( $phone_b['id'] )['duplicate_of'], 'A historical record imported out of order also counts inside a thirty-day institution window' );

	$reverse_history = period_institution( 'reverse-history', $from );
	$live_first = period_delivery( $reverse_history, $current, '0597654320' );
	$import_later = period_delivery( $reverse_history, $ancient_date, '0597654320', '', true );
	$live_after = period_delivery( $reverse_history, $month . '-21 10:00:00', '0597654320' );
	period_check( ! is_wp_error( $import_later ) && $import_later['id'] === LimuCRM\data( $live_first['id'] )['duplicate_of'] && $import_later['id'] === $live_after['duplicate_of'], 'Earlier history arriving after a live lead reconciles the full history without duplicate chains' );
	$aliases = period_institution( 'all-history-aliases', $from );
	period_set( $aliases, 'days_30' );
	$alias_a = period_delivery( $aliases, $ancient_date, '0597654330', 'old-alias@example.invalid', true );
	$alias_b_date = ( new DateTimeImmutable( $ancient_date, wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
	$alias_b = period_delivery( $aliases, $alias_b_date, '0597654330', 'new-alias@example.invalid', true );
	period_check( $alias_a['id'] === $alias_b['duplicate_of'] && 'days_30' === $alias_b['duplicate_mode'], 'A bounded historical duplicate can introduce a second email without becoming a new anchor' );
	period_set( $aliases, 'all_time' );
	$alias_c = period_delivery( $aliases, $current, '0597654331', 'new-alias@example.invalid' );
	$alias_other = period_institution( 'independent-alias-recipient', $from );
	$alias_independent = period_delivery( $alias_other, $current, '0597654331', 'new-alias@example.invalid' );
	period_check( ! is_wp_error( $alias_c ) && $alias_a['id'] === $alias_c['duplicate_of'] && 0 === $alias_independent['duplicate_of'], 'All-history matching retains email aliases from bounded duplicates, points to the original anchor, and never crosses institutions' );
	$alias_bill = LimuCRM\locked( function () use ( $aliases, $month ) { return LimuCRM\prepare_bill( $aliases, $month ); } );
	period_check( ! is_wp_error( $alias_bill ) && 0 === $alias_bill['total'] && array() === $alias_bill['lines'], 'Historical duplicate aliases cannot produce a new charge after switching to all history' );

	$frozen_history = period_institution( 'frozen-all-history', $from );
	$frozen_live = period_delivery( $frozen_history, $current, '0597654327' );
	$history_bill = LimuCRM\locked( function () use ( $frozen_history, $month ) { return LimuCRM\prepare_bill( $frozen_history, $month ); } );
	$history_approved = LimuCRM\locked( function () use ( $history_bill ) { return LimuCRM\approve_bill( $history_bill['id'] ); } );
	period_check( ! is_wp_error( $history_approved ) && 1180 === $history_approved['total'], 'All-history institution bill can be approved before a later historical import' );
	$safe_history = period_delivery( $frozen_history, $ancient_date, '0597654328', '', true );
	$safe_duplicate = period_delivery( $frozen_history, $month . '-21 10:00:00', '0597654328', '', true );
	period_check( ! is_wp_error( $safe_history ) && ! is_wp_error( $safe_duplicate ) && 'historical' === $safe_duplicate['state'] && $safe_history['id'] === $safe_duplicate['duplicate_of'] && 0 === $safe_duplicate['bill'] && $history_approved === LimuCRM\data( $history_bill['id'] ), 'A harmless historical duplicate may be added to an approved month without changing its approved live bill' );
	$history_count = LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $frozen_history ) )['total'];
	$blocked_history = period_delivery( $frozen_history, $ancient_date, '0597654327', '', true );
	period_check( is_wp_error( $blocked_history ) && 409 === $blocked_history->get_error_data()['status'] && $history_count === LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $frozen_history ) )['total'] && 0 === LimuCRM\data( $frozen_live['id'] )['duplicate_of'] && $history_approved === LimuCRM\data( $history_bill['id'] ), 'A late all-history import cannot rewrite an approved charge and rolls back safely' );

	$bootstrap = period_request( 'bootstrap' )->get_data();
	$by_id = array_column( $bootstrap['institutions'], null, 'id' );
	period_check( array( 'duplicate_mode' => 'all_time', 'duplicate_days' => 30 ) === $by_id[ $all_history ]['duplicate_policy'] && 'days_30' === $by_id[ $short_history ]['duplicate_policy']['duplicate_mode'], 'Manager bootstrap exposes independent default and explicit institution rules' );
	$global_before = get_option( 'lcrm_settings' );
	$obsolete = period_request( 'settings', array( 'duplicate_mode' => 'calendar', 'duplicate_days' => 30, 'start_date' => $global_before['start_date'], 'automatic' => false ) );
	period_check( 409 === $obsolete->get_status() && $global_before === get_option( 'lcrm_settings' ), 'A cached global-policy client receives an explicit conflict without losing old settings' );
	$global_current = period_request( 'settings', array( 'start_date' => $global_before['start_date'], 'automatic' => $global_before['automatic'] ) );
	period_check( 200 === $global_current->get_status() && $global_before === get_option( 'lcrm_settings' ) && 'all_time' === LimuCRM\institution_policy( $all_history )['duplicate_mode'], 'Current global settings preserve retired data and never change institution rules' );

	$protected_policy = LimuCRM\institution_policy( $unpriced );
	$no_nonce = period_request( 'institution-policy', array( 'institution' => $unpriced, 'duplicate_mode' => 'all_time' ), false );
	period_check( 403 === $no_nonce->get_status() && $protected_policy === LimuCRM\institution_policy( $unpriced ), 'Even a manager cannot mutate an institution policy without a REST nonce' );
	$period_user = wp_insert_user( array( 'user_login' => $period_prefix, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $period_prefix . '@example.invalid', 'role' => 'limu_institution' ) );
	if ( is_wp_error( $period_user ) ) {
		throw new RuntimeException( 'Unable to create isolated policy viewer.' );
	}
	update_user_meta( $period_user, '_lcrm_institutions', array( $unpriced ) );
	wp_set_current_user( $period_user );
	$viewer_bootstrap = period_request( 'bootstrap' )->get_data();
	period_check( 1 === count( $viewer_bootstrap['institutions'] ) && ! array_key_exists( 'duplicate_policy', $viewer_bootstrap['institutions'][0] ) && null === $viewer_bootstrap['settings'], 'Institution viewers cannot read manager-only duplicate configuration' );
	period_check( 403 === period_policy_request( $unpriced, 'all_time', 30 )->get_status() && $protected_policy === LimuCRM\institution_policy( $unpriced ), 'Assigned institution viewers cannot change their own duplicate rule' );
	wp_set_current_user( 0 );
	period_check( 403 === period_policy_request( $unpriced, 'all_time', 30 )->get_status() && $protected_policy === LimuCRM\institution_policy( $unpriced ), 'Anonymous callers cannot change an institution duplicate rule' );
	wp_set_current_user( $admin->ID );

	$period_fault = function ( $check, $id, $key ) use ( $unpriced ) {
		return $id === $unpriced && '_lcrm_duplicate_policy' === $key ? false : $check;
	};
	add_filter( 'update_post_metadata', $period_fault, 10, 3 );
	$failed_save = period_policy_request( $unpriced, 'all_time', 30 );
	remove_filter( 'update_post_metadata', $period_fault, 10 );
	$period_fault = null;
	period_check( 500 === $failed_save->get_status() && $protected_policy === LimuCRM\institution_policy( $unpriced ), 'Metadata write failure does not report success or replace the prior institution rule' );
	$period_fault = function ( $check, $id, $key ) {
		return 'lcrm_audit' === get_post_type( $id ) && '_lcrm_data' === $key ? false : $check;
	};
	add_filter( 'update_post_metadata', $period_fault, 10, 3 );
	$failed_audit = period_policy_request( $unpriced, 'all_time', 30 );
	remove_filter( 'update_post_metadata', $period_fault, 10 );
	$period_fault = null;
	period_check( 500 === $failed_audit->get_status() && $protected_policy === LimuCRM\institution_policy( $unpriced ), 'Audit failure rolls back institution metadata and invalidates the mutated cache' );
	$corrupt_count = LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $unpriced ) )['total'];
	update_post_meta( $unpriced, '_lcrm_duplicate_policy', array( 'duplicate_mode' => 'unknown', 'duplicate_days' => 30 ) );
	$corrupt_capture = period_delivery( $unpriced, $current );
	update_post_meta( $unpriced, '_lcrm_duplicate_policy', $protected_policy );
	period_check( is_wp_error( $corrupt_capture ) && 500 === $corrupt_capture->get_error_data()['status'] && $corrupt_count === LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $unpriced ) )['total'], 'Corrupt institution policy fails closed instead of silently producing a billable delivery' );
	echo "OK $period_passed duplicate-period assertions\n";
} finally {
	if ( null !== $period_fault ) {
		remove_filter( 'update_post_metadata', $period_fault, 10 );
	}
	if ( is_int( $period_user ) && $period_user > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $period_user );
	}
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
