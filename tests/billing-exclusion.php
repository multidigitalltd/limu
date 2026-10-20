<?php
/** Manual billing exclusions tested only with synthetic data in the disposable database. */
$exclude_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $exclude_root ) {
	fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
	exit( 1 );
}
require $exclude_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
	exit( 1 );
}
register_post_type( 'institutions', array( 'public' => false ) );
register_post_type( 'leads', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$exclude_http = function () { return new WP_Error( 'synthetic_test', 'External requests are disabled in this test.' ); };
add_filter( 'pre_http_request', $exclude_http );
$exclude_posts = array();
$exclude_user = 0;
$exclude_passed = 0;
$exclude_sequence = 0;
$exclude_prefix = 'billing-exclusion-' . wp_generate_uuid4();
$exclude_old_settings = get_option( 'lcrm_settings', null );
$exclude_old_boundary = get_option( 'lcrm_live_capture_from', null );
$exclude_fault = null;
$exclude_query_fault = null;
$exclude_previous_suppress = null;
$exclude_poisoned_bill = 0;
$exclude_poisoned_ids = array();
$exclude_tracker = function ( $id, $post, $update ) use ( &$exclude_posts ) {
	if ( ! $update ) {
		$exclude_posts[] = $id;
	}
};
add_action( 'wp_after_insert_post', $exclude_tracker, 99, 3 );

function exclude_check( $condition, $message ) {
	global $exclude_passed;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$exclude_passed;
	echo "PASS $message\n";
}

function exclude_request( $route, $body = null, $query = array(), $nonce = true ) {
	$request = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/limu-crm/v1/' . $route );
	if ( $nonce ) {
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	}
	foreach ( $query as $key => $value ) {
		$request->set_param( $key, $value );
	}
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
}

function exclude_toggle( $id, $excluded, $nonce = true ) {
	return exclude_request( 'billing-exclusion', array( 'id' => $id, 'excluded' => $excluded ), array(), $nonce );
}

function exclude_institution( $label, $from, $price = 1000 ) {
	global $exclude_prefix;
	$id = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $exclude_prefix . ' ' . $label ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Unable to create an isolated billing exclusion institution.' );
	}
	update_post_meta( $id, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => $from, 'price' => $price, 'vat_bp' => 1800 ) ) ) );
	return $id;
}

function exclude_delivery( $institution, $date, $phone, $historical = false, $source = null ) {
	global $exclude_prefix, $exclude_sequence;
	$source = null === $source ? $exclude_prefix . ':' . ++$exclude_sequence : $source;
	return LimuCRM\locked( function () use ( $institution, $date, $phone, $historical, $source ) {
		return LimuCRM\record_delivery( array( 'name' => 'Synthetic billing exclusion fixture', 'date' => $date, 'phone' => $phone, 'email' => '', 'form' => 'exclusion-test' ), $institution, $source, $historical );
	} );
}

function exclude_draft( $institution, $month ) {
	return LimuCRM\locked( function () use ( $institution, $month ) { return LimuCRM\prepare_bill( $institution, $month ); } );
}

function exclude_approve( $id ) {
	return LimuCRM\locked( function () use ( $id ) { return LimuCRM\approve_bill( $id ); } );
}

function exclude_events( $target ) {
	return array_values( array_filter( LimuCRM\query_records( 'lcrm_audit', array(), 1, 200 )['items'], function ( $item ) use ( $target ) {
		return $target === $item['target'] && in_array( $item['event'], array( 'delivery_billing_excluded', 'delivery_billing_restored' ), true );
	} ) );
}

function exclude_sheet( $response ) {
	$payload = $response->get_data();
	if ( 200 !== $response->get_status() || empty( $payload['file'] ) ) {
		throw new RuntimeException( 'Unable to export the isolated exclusion fixture.' );
	}
	$path = tempnam( sys_get_temp_dir(), 'lcrm-exclusion-' );
	$zip = new ZipArchive();
	try {
		file_put_contents( $path, base64_decode( $payload['file'], true ) );
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( 'Unable to open the isolated exclusion export.' );
		}
		return $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	} finally {
		$zip->close();
		unlink( $path );
	}
}

try {
	$exclude_admin = get_user_by( 'login', 'crm-admin' );
	if ( ! $exclude_admin || ! user_can( $exclude_admin, 'manage_options' ) ) {
		throw new RuntimeException( 'Run the disposable integration setup first.' );
	}
	wp_set_current_user( $exclude_admin->ID );
	$month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
	$from = $month . '-01';
	$settings = LimuCRM\settings();
	$settings['start_date'] = $from;
	$settings['automatic'] = false;
	update_option( 'lcrm_settings', $settings, false );
	$a = exclude_institution( 'A', $from );
	$b = exclude_institution( 'B', $from );
	$c = exclude_institution( 'C', $from );
	$shared_source = $exclude_prefix . ':general';
	$base = exclude_delivery( $a, $month . '-05 10:00:00', '0598765401', false, $shared_source );
	$other = exclude_delivery( $b, $month . '-05 10:00:00', '0598765401', false, $shared_source );
	$duplicate = exclude_delivery( $a, $month . '-06 10:00:00', '0598765401' );
	$unique = exclude_delivery( $a, $month . '-07 10:00:00', '0598765402' );
	$history = exclude_delivery( $a, $month . '-08 10:00:00', '0598765403', true );
	$draft = exclude_draft( $a, $month );
	exclude_check( ! is_wp_error( $draft ) && 2360 === $draft['total'] && 2 === count( $draft['lines'] ) && 1 === $draft['duplicates'] && 1 === $draft['historical'], 'Baseline bills two unique live leads and retains separate duplicate and historical counts' );
	$before_delivery_count = LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $a ) )['total'];
	$toggle = exclude_toggle( $base['id'], true );
	$payload = $toggle->get_data();
	$excluded_base = LimuCRM\data( $base['id'] );
	exclude_check( 200 === $toggle->get_status() && $base['id'] === $payload['id'] && true === $payload['billing_excluded'] && true === $payload['draft_requires_refresh'], 'Manager can exclude a delivery and receives an explicit draft refresh requirement' );
	exclude_check( true === $excluded_base['billing_excluded'] && $exclude_admin->ID === $excluded_base['billing_excluded_by'] && ! empty( $excluded_base['billing_excluded_at'] ) && '1' === (string) get_post_meta( $base['id'], '_lcrm_billing_excluded', true ), 'Exclusion stores its actor, time and indexed boolean' );
	$exclude_poisoned_ids[] = $base['id'];
	wp_cache_set( $base['id'], array( '_lcrm_data' => array( maybe_serialize( $base ) ) ), 'post_meta' );
	$stale_treatment = exclude_request( 'treatment', array( 'id' => $base['id'], 'treatment' => 'working', 'note' => 'Synthetic protected exclusion note' ) );
	wp_cache_delete( $base['id'], 'post_meta' );
	$excluded_base = LimuCRM\raw_records( array( $base['id'] ) )[0];
	exclude_check( 200 === $stale_treatment->get_status() && true === $excluded_base['billing_excluded'] && $exclude_admin->ID === $excluded_base['billing_excluded_by'] && $base['duplicate_of'] === $excluded_base['duplicate_of'], 'Treatment updates preserve the committed exclusion and duplicate snapshot despite a stale cached lead' );
	exclude_check( $draft === LimuCRM\data( $draft['id'] ) && $before_delivery_count === LimuCRM\query_records( 'lcrm_delivery', array( 'institution' => $a ) )['total'] && $base['state'] === $excluded_base['state'] && $base['duplicate_of'] === $excluded_base['duplicate_of'], 'Exclusion keeps the current draft, delivery count, state and duplicate classification unchanged' );
	$events = exclude_events( $base['id'] );
	exclude_check( 1 === count( $events ) && 'delivery_billing_excluded' === $events[0]['event'] && $exclude_admin->ID === $events[0]['actor'] && ! empty( $events[0]['at'] ) && array( 'institution' => $a ) === $events[0]['details'], 'Exclusion is audited with institution context and no contact data' );
	$repeat = exclude_toggle( $base['id'], true );
	exclude_check( 200 === $repeat->get_status() && 1 === count( exclude_events( $base['id'] ) ) && $excluded_base === LimuCRM\raw_records( array( $base['id'] ) )[0], 'Repeating the same exclusion is idempotent and creates no extra audit event' );
	exclude_check( $base['id'] === LimuCRM\data( $duplicate['id'] )['duplicate_of'], 'An excluded delivery remains the original duplicate anchor' );
	$other_draft = exclude_draft( $b, $month );
	exclude_check( empty( LimuCRM\data( $other['id'] )['billing_excluded'] ) && 1180 === $other_draft['total'] && $other['contact'] === $base['contact'], 'Excluding one recipient of a general lead leaves the other institution billable' );
	$historical_toggle = exclude_toggle( $history['id'], true );
	exclude_check( 200 === $historical_toggle->get_status() && 'historical' === LimuCRM\data( $history['id'] )['state'] && $draft === LimuCRM\data( $draft['id'] ), 'Historical leads can be marked without becoming live or recalculating a draft' );
	exclude_check( 200 === exclude_toggle( $history['id'], false )->get_status() && false === LimuCRM\data( $history['id'] )['billing_excluded'], 'Manager can restore a historical exclusion before approval' );
	$stale_delivery_cache = $excluded_base;
	$stale_delivery_cache['billing_excluded'] = false;
	$exclude_poisoned_ids[] = $base['id'];
	wp_cache_set( $base['id'], array( '_lcrm_data' => array( maybe_serialize( $stale_delivery_cache ) ) ), 'post_meta' );
	$stale = exclude_approve( $draft['id'] );
	wp_cache_delete( $base['id'], 'post_meta' );
	exclude_check( is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'] && $draft === LimuCRM\data( $draft['id'] ), 'Approving a stale draft fails and rolls back the implicit recalculation' );
	$recalculated = exclude_draft( $a, $month );
	exclude_check( 1180 === $recalculated['total'] && 1 === count( $recalculated['lines'] ) && $unique['id'] === $recalculated['lines'][0]['delivery'] && 1 === $recalculated['duplicates'] && 1 === $recalculated['historical'], 'Explicit preparation omits the manual exclusion while preserving report classifications' );
	$restore = exclude_toggle( $base['id'], false );
	$restored_base = LimuCRM\data( $base['id'] );
	$events = exclude_events( $base['id'] );
	exclude_check( 200 === $restore->get_status() && false === $restored_base['billing_excluded'] && 2 === count( $events ) && in_array( 'delivery_billing_restored', array_column( $events, 'event' ), true ), 'Restoring a delivery is reversible and receives its own audit event' );
	$stale_restore = exclude_approve( $draft['id'] );
	exclude_check( is_wp_error( $stale_restore ) && 409 === $stale_restore->get_error_data()['status'], 'Undoing an exclusion also invalidates an already prepared draft' );
	exclude_check( 2360 === exclude_draft( $a, $month )['total'], 'Explicit refresh restores the original charge after undo' );
	exclude_toggle( $base['id'], true );
	$ready = exclude_draft( $a, $month );
	$approved = exclude_approve( $ready['id'] );
	exclude_check( ! is_wp_error( $approved ) && 'approved' === $approved['state'] && 1180 === $approved['total'] && 0 === LimuCRM\data( $base['id'] )['bill'], 'Approval freezes the refreshed bill while the excluded delivery has no bill attachment' );
	$exclude_poisoned_ids[] = $unique['id'];
	wp_cache_set( $unique['id'], array( '_lcrm_data' => array( maybe_serialize( $unique ) ) ), 'post_meta' );
	$approved_treatment = exclude_request( 'treatment', array( 'id' => $unique['id'], 'treatment' => 'closed', 'note' => 'Synthetic approved delivery note' ) );
	wp_cache_delete( $unique['id'], 'post_meta' );
	exclude_check( 200 === $approved_treatment->get_status() && $approved['id'] === LimuCRM\data( $unique['id'] )['bill'] && $approved === LimuCRM\data( $ready['id'] ), 'Treatment updates cannot erase an approved bill attachment through stale metadata' );
	exclude_check( 409 === exclude_toggle( $unique['id'], true )->get_status() && $approved === LimuCRM\data( $ready['id'] ), 'An attached delivery cannot be excluded after its bill is approved' );
	exclude_check( 409 === exclude_toggle( $base['id'], false )->get_status() && true === LimuCRM\data( $base['id'] )['billing_excluded'] && $approved === LimuCRM\data( $ready['id'] ), 'An excluded delivery cannot be restored inside an approved month even without a bill attachment' );
	// A failed bill lookup must not turn an approved period into an apparently editable one.
	global $wpdb;
	clean_post_cache( $ready['id'] );
	$exclude_lookup_failed = false;
	$exclude_query_fault = function ( $query ) use ( &$exclude_lookup_failed ) {
		if ( ! $exclude_lookup_failed && 0 === strpos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, "'lcrm_bill'" ) ) {
			$exclude_lookup_failed = true;
			return 'SELECT * FROM limu_exclusion_lookup_failure_fixture';
		}
		return $query;
	};
	$exclude_previous_suppress = $wpdb->suppress_errors( true );
	add_filter( 'query', $exclude_query_fault );
	$failed_lookup = exclude_toggle( $base['id'], false );
	remove_filter( 'query', $exclude_query_fault );
	$exclude_query_fault = null;
	$wpdb->suppress_errors( $exclude_previous_suppress );
	$exclude_previous_suppress = null;
	exclude_check( $exclude_lookup_failed && 500 === $failed_lookup->get_status() && true === LimuCRM\data( $base['id'] )['billing_excluded'] && $approved === LimuCRM\data( $ready['id'] ), 'A failed approved-bill lookup fails closed and never restores an excluded delivery' );
	exclude_check( 409 === exclude_toggle( $base['id'], false )->get_status() && true === LimuCRM\data( $base['id'] )['billing_excluded'], 'Retry after a failed bill lookup still observes the approved period instead of a cached empty result' );
	$forged_draft = $approved;
	$forged_draft['state'] = 'draft';
	$exclude_poisoned_bill = $ready['id'];
	wp_cache_set( $ready['id'], array( '_lcrm_data' => array( maybe_serialize( $forged_draft ) ) ), 'post_meta' );
	$stale_metadata_attempt = exclude_toggle( $base['id'], false );
	wp_cache_delete( $ready['id'], 'post_meta' );
	$exclude_poisoned_bill = 0;
	exclude_check( 409 === $stale_metadata_attempt->get_status() && true === LimuCRM\data( $base['id'] )['billing_excluded'] && $approved === LimuCRM\data( $ready['id'] ), 'Stale draft data in the metadata cache cannot bypass an approved-period exclusion guard' );
	$approval_count_before = count( array_filter( LimuCRM\query_records( 'lcrm_audit', array(), 1, 200 )['items'], function ( $event ) use ( $ready ) { return $ready['id'] === $event['target'] && 'bill_approved' === $event['event']; } ) );
	$exclude_poisoned_bill = $ready['id'];
	wp_cache_set( $ready['id'], array( '_lcrm_data' => array( maybe_serialize( $forged_draft ) ) ), 'post_meta' );
	$approval_retry = exclude_request( 'approve', array( 'ids' => array( $ready['id'] ) ) );
	wp_cache_delete( $ready['id'], 'post_meta' );
	$exclude_poisoned_bill = 0;
	$approval_count_after = count( array_filter( LimuCRM\query_records( 'lcrm_audit', array(), 1, 200 )['items'], function ( $event ) use ( $ready ) { return $ready['id'] === $event['target'] && 'bill_approved' === $event['event']; } ) );
	exclude_check( 200 === $approval_retry->get_status() && $approved === $approval_retry->get_data()['results'][0] && $approval_count_before === $approval_count_after && $approved === LimuCRM\data( $ready['id'] ), 'A stale cached draft cannot reapprove, retimestamp or reissue an already approved bill' );
	exclude_check( 409 === exclude_toggle( $history['id'], true )->get_status() && false === LimuCRM\data( $history['id'] )['billing_excluded'], 'Historical records also respect the approved institution-month boundary' );

	$free_institution = exclude_institution( 'zero price', $from, 0 );
	$free = exclude_delivery( $free_institution, $month . '-09 10:00:00', '0598765404' );
	$free_draft = exclude_draft( $free_institution, $month );
	exclude_check( 0 === $free_draft['total'] && 1 === count( $free_draft['lines'] ) && 200 === exclude_toggle( $free['id'], true )->get_status(), 'A zero-priced lead can be excluded even though the monetary total is already zero' );
	$free_stale = exclude_approve( $free_draft['id'] );
	exclude_check( is_wp_error( $free_stale ) && 409 === $free_stale->get_error_data()['status'], 'Stale approval compares delivery lines even when both monetary totals are zero' );
	$empty_free = exclude_draft( $free_institution, $month );
	exclude_check( 0 === $empty_free['total'] && array() === $empty_free['lines'] && is_wp_error( exclude_approve( $empty_free['id'] ) ), 'An explicit zero-price refresh removes the line and cannot approve an empty bill' );
	exclude_check( 200 === exclude_toggle( $free['id'], false )->get_status() && 1 === count( exclude_draft( $free_institution, $month )['lines'] ), 'Undo restores a zero-priced delivery line before approval' );

	$payment_institution = exclude_institution( 'authoritative-payments', $from );
	exclude_delivery( $payment_institution, $month . '-05 10:00:00', '0598765490' );
	exclude_delivery( $payment_institution, $month . '-06 10:00:00', '0598765491' );
	$payment_draft = exclude_draft( $payment_institution, $month );
	$payment_bill = exclude_approve( $payment_draft['id'] );
	$payment_input = array( 'bill' => $payment_bill['id'], 'amount' => '10', 'date' => current_time( 'Y-m-d' ), 'method' => 'transfer', 'reference' => 'Synthetic cache regression', 'request_key' => wp_generate_uuid4() );
	$first_payment_response = exclude_request( 'payment', $payment_input );
	$first_payment = $first_payment_response->get_data();
	exclude_check( 200 === $first_payment_response->get_status() && 1000 === LimuCRM\raw_records( array( $payment_bill['id'] ) )[0]['paid'], 'The cache-regression fixture records one partial payment against its approved bill' );
	$forged_balance = LimuCRM\raw_records( array( $payment_bill['id'] ) )[0];
	$forged_balance['paid'] = 0;
	$forged_balance['total'] = 99999999;
	$forged_payment = $first_payment;
	$forged_payment['amount'] = 1;
	$forged_payment['request_key'] = wp_generate_uuid4();
	$exclude_poisoned_ids[] = $payment_bill['id'];
	$exclude_poisoned_ids[] = $first_payment['id'];
	wp_cache_set( $payment_bill['id'], array( '_lcrm_data' => array( maybe_serialize( $forged_balance ) ) ), 'post_meta' );
	wp_cache_set( $first_payment['id'], array( '_lcrm_data' => array( maybe_serialize( $forged_payment ) ) ), 'post_meta' );
	$payment_count_before = LimuCRM\query_records( 'lcrm_payment', array( 'bill' => $payment_bill['id'] ) )['total'];
	$overpayment_input = $payment_input;
	$overpayment_input['amount'] = '20';
	$overpayment_input['request_key'] = wp_generate_uuid4();
	$poisoned_overpayment = exclude_request( 'payment', $overpayment_input );
	exclude_check( 409 === $poisoned_overpayment->get_status() && $payment_count_before === LimuCRM\query_records( 'lcrm_payment', array( 'bill' => $payment_bill['id'] ) )['total'] && 1000 === LimuCRM\raw_records( array( $payment_bill['id'] ) )[0]['paid'], 'Forged cached bill totals and payment amounts cannot allow an overpayment' );
	$poisoned_replay = exclude_request( 'payment', $payment_input );
	wp_cache_delete( $payment_bill['id'], 'post_meta' );
	wp_cache_delete( $first_payment['id'], 'post_meta' );
	exclude_check( 200 === $poisoned_replay->get_status() && $first_payment['id'] === $poisoned_replay->get_data()['id'] && $payment_count_before === LimuCRM\query_records( 'lcrm_payment', array( 'bill' => $payment_bill['id'] ) )['total'] && 1000 === LimuCRM\data( $payment_bill['id'] )['paid'], 'Idempotent payment replay uses the committed ledger despite a stale cached request key' );

	$rollback_lead = exclude_delivery( $c, $month . '-10 10:00:00', '0598765405' );
	$rollback_before = LimuCRM\data( $rollback_lead['id'] );
	$rollback_event_count = count( exclude_events( $rollback_lead['id'] ) );
	$exclude_fault = function ( $check, $id, $key, $value ) use ( $rollback_lead ) {
		if ( '_lcrm_data' === $key && is_array( $value ) && 'delivery_billing_excluded' === ( $value['event'] ?? '' ) && $rollback_lead['id'] === ( $value['target'] ?? 0 ) ) {
			return false;
		}
		return $check;
	};
	add_filter( 'update_post_metadata', $exclude_fault, 10, 4 );
	$rollback_response = exclude_toggle( $rollback_lead['id'], true );
	remove_filter( 'update_post_metadata', $exclude_fault, 10 );
	$exclude_fault = null;
	exclude_check( 500 === $rollback_response->get_status() && $rollback_before === LimuCRM\data( $rollback_lead['id'] ) && $rollback_event_count === count( exclude_events( $rollback_lead['id'] ) ) && ! get_post_meta( $rollback_lead['id'], '_lcrm_billing_excluded', true ), 'Audit storage failure rolls back both the flag and its indexed metadata' );
	$no_draft_response = exclude_toggle( $rollback_lead['id'], true );
	exclude_check( 200 === $no_draft_response->get_status() && false === $no_draft_response->get_data()['draft_requires_refresh'], 'A delivery without an existing draft does not claim that a refresh is needed' );
	exclude_toggle( $rollback_lead['id'], false );
	$validation_before = LimuCRM\data( $rollback_lead['id'] );

	foreach ( array( 0, 1, 'true', 'false', '', null, array(), (object) array() ) as $bad_boolean ) {
		exclude_check( 400 === exclude_toggle( $rollback_lead['id'], $bad_boolean )->get_status() && $validation_before === LimuCRM\data( $rollback_lead['id'] ), 'Exclusion rejects non-boolean input without changing a delivery: ' . wp_json_encode( $bad_boolean ) );
	}
	foreach ( array( true, false, -1, '1.5', array(), null ) as $bad_id ) {
		exclude_check( in_array( exclude_toggle( $bad_id, true )->get_status(), array( 400, 404 ), true ), 'Malformed delivery identifiers are rejected safely' );
	}
	exclude_check( in_array( exclude_toggle( 2147483647, true )->get_status(), array( 400, 404 ), true ) && in_array( exclude_toggle( $c, true )->get_status(), array( 400, 404 ), true ), 'Missing records and institution IDs cannot be used as delivery IDs' );
	exclude_check( 400 === exclude_request( 'billing-exclusion', array( 'id' => $rollback_lead['id'] ) )->get_status() && 400 === exclude_request( 'billing-exclusion', array( 'id' => $rollback_lead['id'], 'excluded' => true, 'amount' => '0' ) )->get_status(), 'The endpoint requires an explicit boolean and rejects unrelated mutation fields' );
	$unmapped = LimuCRM\locked( function () use ( $month, $exclude_prefix ) {
		return LimuCRM\save_record( 'lcrm_delivery', array( 'institution' => 0, 'source' => $exclude_prefix . ':unmapped', 'state' => 'unmapped', 'date' => $month . '-11 10:00:00', 'month' => $month, 'name' => 'Synthetic unmapped fixture', 'phone' => '0598765406', 'email' => '', 'phone_key' => '0598765406', 'email_key' => '', 'duplicate_of' => 0, 'bill' => 0 ) );
	} );
	exclude_check( 400 === exclude_toggle( $unmapped['id'], true )->get_status(), 'An unresolved recipient cannot be excluded as if it were a mapped delivery' );
	$unsupported_state = $rollback_before;
	unset( $unsupported_state['id'] );
	$unsupported_state['source'] = $exclude_prefix . ':unsupported-state';
	$unsupported_state['state'] = 'pending';
	$unsupported_state = LimuCRM\locked( function () use ( $unsupported_state ) { return LimuCRM\save_record( 'lcrm_delivery', $unsupported_state ); } );
	exclude_check( 400 === exclude_toggle( $unsupported_state['id'], true )->get_status() && empty( LimuCRM\data( $unsupported_state['id'] )['billing_excluded'] ), 'A mapped record with an unsupported delivery state cannot acquire a billing exclusion' );
	exclude_check( 403 === exclude_toggle( $rollback_lead['id'], true, false )->get_status() && false === LimuCRM\data( $rollback_lead['id'] )['billing_excluded'], 'Manager mutations without a REST nonce are rejected' );

	$filter = array( 'institution' => $a, 'billing_status' => 'excluded' );
	$filtered = exclude_request( 'deliveries', null, $filter );
	$filtered_data = $filtered->get_data();
	exclude_check( 200 === $filtered->get_status() && 1 === $filtered_data['total'] && $base['id'] === $filtered_data['items'][0]['id'] && true === $filtered_data['items'][0]['billing_excluded'], 'Delivery filter returns only manually excluded leads for the selected institution' );
	exclude_check( 1 === LimuCRM\query_records( 'lcrm_delivery', $filter )['total'] && 0 === exclude_request( 'deliveries', null, array( 'institution' => $b, 'billing_status' => 'excluded' ) )->get_data()['total'], 'Indexed filtering preserves independent institution scope' );
	$report = exclude_request( 'report', null, $filter + array( 'target' => 'deliveries' ) );
	exclude_check( 200 === $report->get_status() && 1 === $report->get_data()['total'] && $base['id'] === $report->get_data()['items'][0]['id'], 'Printable reports use the same manual exclusion filter' );
	$excluded_export = exclude_request( 'export', null, $filter + array( 'target' => 'deliveries' ) );
	$excluded_sheet = exclude_sheet( $excluded_export );
	$all_sheet = exclude_sheet( exclude_request( 'export', null, array( 'institution' => $a, 'target' => 'deliveries' ) ) );
	exclude_check( 1 === $excluded_export->get_data()['total'] && false !== simplexml_load_string( $excluded_sheet ) && false !== strpos( $excluded_sheet, 'מצב חיוב' ) && false !== strpos( $excluded_sheet, 'החרגה מחיוב' ) && false !== strpos( $all_sheet, 'ללא החרגה ידנית' ), 'XLSX exports preserve the exclusion filter and describe manual billing status separately' );
	foreach ( array( 'bills', 'summary', 'payments', 'audit', 'bootstrap' ) as $unsupported_route ) {
		exclude_check( 400 === exclude_request( $unsupported_route, null, $filter )->get_status(), 'Billing exclusion filters are rejected outside delivery reports: ' . $unsupported_route );
	}
	foreach ( array( 'report', 'export' ) as $unsupported_target ) {
		exclude_check( 400 === exclude_request( $unsupported_target, null, $filter + array( 'target' => 'bills' ) )->get_status(), 'Billing reports cannot silently ignore a lead-only exclusion filter' );
	}
	foreach ( array( 'other', true, array( 'excluded' ) ) as $bad_status ) {
		exclude_check( 400 === exclude_request( 'deliveries', null, array( 'billing_status' => $bad_status ) )->get_status(), 'Unsupported or malformed exclusion filter values are rejected' );
	}

	$exclude_user = wp_create_user( 'exclusion-' . wp_generate_uuid4(), wp_generate_password( 40 ), 'exclusion-' . wp_generate_uuid4() . '@example.test' );
	if ( is_wp_error( $exclude_user ) ) {
		$exclude_user = 0;
		throw new RuntimeException( 'Unable to create an isolated institution viewer.' );
	}
	get_user_by( 'id', $exclude_user )->set_role( 'limu_institution' );
	update_user_meta( $exclude_user, '_lcrm_institutions', array( $a ) );
	wp_set_current_user( $exclude_user );
	$member_rows = exclude_request( 'deliveries', null, $filter )->get_data();
	exclude_check( 1 === $member_rows['total'] && true === $member_rows['items'][0]['billing_excluded'] && ! isset( $member_rows['items'][0]['billing_excluded_by'] ) && ! isset( $member_rows['items'][0]['billing_excluded_at'] ), 'Institution viewers see the billing flag while actor and time remain private' );
	exclude_check( 403 === exclude_toggle( $base['id'], false )->get_status() && 403 === exclude_toggle( $other['id'], true )->get_status(), 'Institution viewers cannot change an exclusion for any recipient' );
	exclude_check( 403 === exclude_request( 'deliveries', null, array( 'institution' => $b, 'billing_status' => 'excluded' ) )->get_status(), 'The exclusion filter cannot disclose another institution data' );
	wp_set_current_user( 0 );
	exclude_check( 403 === exclude_toggle( $rollback_lead['id'], true )->get_status() && 403 === exclude_request( 'deliveries', null, $filter )->get_status(), 'Anonymous users cannot read or change billing exclusions' );
	wp_set_current_user( $exclude_admin->ID );

	$unmapped['billing_excluded'] = true;
	$unmapped['billing_excluded_by'] = $exclude_admin->ID;
	$unmapped['billing_excluded_at'] = current_time( 'mysql' );
	$legacy_exception = LimuCRM\locked( function () use ( $unmapped ) { return LimuCRM\save_record( 'lcrm_delivery', $unmapped, $unmapped['id'] ); } );
	$remapped = exclude_request( 'remap', array( 'id' => $legacy_exception['id'], 'institutions' => array( $c ) ) );
	$remapped_data = $remapped->get_data();
	exclude_check( 200 === $remapped->get_status() && true === $remapped_data['results'][0]['billing_excluded'] && $exclude_admin->ID === $remapped_data['results'][0]['billing_excluded_by'] && $legacy_exception['billing_excluded_at'] === $remapped_data['results'][0]['billing_excluded_at'], 'Resolving a legacy unmapped exception preserves its existing exclusion and provenance' );
	update_option( 'lcrm_live_capture_from', current_time( 'Y-m-d' ) . ' 00:00:00', false );
	$native = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_date' => current_time( 'mysql' ), 'post_title' => 'Synthetic excluded import-race fixture' ) );
	foreach ( array( 'institution' => get_post_field( 'post_title', $c, 'raw' ), 'full-name' => 'Synthetic excluded import-race fixture', 'phone' => '0598765407', 'email' => '', 'form-name' => 'exclusion-test' ) as $key => $value ) {
		update_post_meta( $native, $key, $value );
	}
	$import_race = LimuCRM\locked( function () use ( $native, $c ) {
		return LimuCRM\record_delivery( array( 'name' => 'Synthetic excluded import-race fixture', 'phone' => '0598765407', 'email' => '', 'date' => get_post_field( 'post_date', $native ), 'form' => 'exclusion-test', 'contact' => $native, 'legacy_id' => $native ), $c, 'legacy:' . $native, true );
	} );
	exclude_toggle( $import_race['id'], true );
	$race_before = LimuCRM\data( $import_race['id'] );
	$promoted = LimuCRM\locked( function () use ( $native ) { return LimuCRM\native_capture( $native ); } );
	exclude_check( ! is_wp_error( $promoted ) && 'sent' === $promoted[0]['state'] && true === $promoted[0]['billing_excluded'] && $race_before['billing_excluded_by'] === $promoted[0]['billing_excluded_by'] && $race_before['billing_excluded_at'] === $promoted[0]['billing_excluded_at'], 'An overlapping import promoted to live capture preserves the manual exclusion' );
	echo "OK $exclude_passed billing exclusion assertions\n";
} finally {
	foreach ( array_unique( $exclude_poisoned_ids ) as $id ) {
		wp_cache_delete( $id, 'post_meta' );
	}
	if ( $exclude_poisoned_bill ) {
		wp_cache_delete( $exclude_poisoned_bill, 'post_meta' );
	}
	if ( $exclude_query_fault ) {
		remove_filter( 'query', $exclude_query_fault );
	}
	if ( null !== $exclude_previous_suppress ) {
		$wpdb->suppress_errors( $exclude_previous_suppress );
	}
	if ( $exclude_fault ) {
		remove_filter( 'update_post_metadata', $exclude_fault, 10 );
	}
	remove_filter( 'pre_http_request', $exclude_http );
	remove_action( 'wp_after_insert_post', $exclude_tracker, 99 );
	wp_set_current_user( isset( $exclude_admin ) && $exclude_admin ? $exclude_admin->ID : 0 );
	unset( $GLOBALS['lcrm_native_new_leads'], $GLOBALS['lcrm_native_queue'] );
	foreach ( array_reverse( array_unique( $exclude_posts ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $exclude_user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $exclude_user );
	}
	if ( null === $exclude_old_settings ) {
		delete_option( 'lcrm_settings' );
	} else {
		update_option( 'lcrm_settings', $exclude_old_settings, false );
	}
	if ( null === $exclude_old_boundary ) {
		delete_option( 'lcrm_live_capture_from' );
	} else {
		update_option( 'lcrm_live_capture_from', $exclude_old_boundary, false );
	}
}
