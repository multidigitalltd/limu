<?php
/** Bulk tariffs: REST authorization, preserved terms, closed periods and atomic rollback. */
$bulk_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $bulk_root ) {
	fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
	exit( 1 );
}
require $bulk_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
	exit( 1 );
}
register_post_type( 'institutions', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$bulk_http = static function () { return new WP_Error( 'synthetic_test', 'External requests are disabled in this test.' ); };
add_filter( 'pre_http_request', $bulk_http );
$bulk_posts = array();
$bulk_passed = 0;
$bulk_failed = false;
$bulk_member = 0;
$bulk_query_fault = null;
$bulk_meta_fault = null;
$bulk_audit_fault = null;
$bulk_old_user = get_current_user_id();
$bulk_prefix = 'bulk-rates-' . wp_generate_uuid4();
$bulk_tracker = static function ( $id, $post, $update ) use ( &$bulk_posts ) {
	if ( ! $update ) {
		$bulk_posts[] = $id;
	}
};
add_action( 'wp_after_insert_post', $bulk_tracker, 99, 3 );

function bulk_check( $condition, $message ) {
	global $bulk_passed;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$bulk_passed;
	echo "PASS $message\n";
}

function bulk_request( $body, $nonce = true, $route = 'agreements-bulk', $raw = false ) {
	$request = new WP_REST_Request( 'POST', '/limu-crm/v1/' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( $nonce ) {
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	}
	$request->set_body( $raw ? $body : wp_json_encode( $body ) );
	return rest_do_request( $request );
}

function bulk_institution( $label, $days = null, $status = 'publish' ) {
	global $bulk_prefix;
	$id = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => $status, 'post_title' => $bulk_prefix . ' ' . $label ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Unable to create an isolated tariff fixture.' );
	}
	if ( null !== $days ) {
		update_post_meta( $id, '_lcrm_agreement', array( 'credit_days' => $days, 'rates' => array( array( 'from' => '2025-01-01', 'price' => 1000, 'vat_bp' => 1700 ), array( 'from' => '2027-01-01', 'price' => 2000, 'vat_bp' => 1800 ) ), 'preserved' => 'institution-specific' ) );
	}
	return $id;
}

function bulk_config( $id ) {
	global $wpdb;
	return maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, '_lcrm_agreement' ) ) );
}

function bulk_audits() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'lcrm_audit' ) );
}

try {
	$bulk_admin = get_user_by( 'login', 'crm-admin' );
	if ( ! $bulk_admin || ! user_can( $bulk_admin, 'manage_options' ) ) {
		throw new RuntimeException( 'Run the disposable integration setup first.' );
	}
	wp_set_current_user( $bulk_admin->ID );
	$a = bulk_institution( 'published', 17 );
	$b = bulk_institution( 'draft', 90, 'draft' );
	$c = bulk_institution( 'no-agreement' );
	$policy_a = array( 'duplicate_mode' => 'custom', 'duplicate_days' => 47 );
	$policy_b = array( 'duplicate_mode' => 'all_time', 'duplicate_days' => 30 );
	update_post_meta( $a, '_lcrm_duplicate_policy', $policy_a );
	update_post_meta( $b, '_lcrm_duplicate_policy', $policy_b );
	$old_a = bulk_config( $a );
	$old_b = bulk_config( $b );
	$payload = array( 'institutions' => array( $a, $b ), 'from' => '2026-11-01', 'price' => '12.34' );
	$before_audits = bulk_audits();
	bulk_check( 403 === bulk_request( $payload, false )->get_status() && $old_a === bulk_config( $a ) && $before_audits === bulk_audits(), 'A manager mutation requires a REST nonce and cannot partially save without one' );
	wp_set_current_user( 0 );
	bulk_check( 403 === bulk_request( $payload )->get_status(), 'Anonymous visitors cannot change institution tariffs' );
	$bulk_member = wp_create_user( $bulk_prefix, wp_generate_password( 40 ), $bulk_prefix . '@example.test' );
	if ( is_wp_error( $bulk_member ) ) {
		throw new RuntimeException( 'Unable to create an isolated institution user.' );
	}
	$member = get_user_by( 'id', $bulk_member );
	$member->add_cap( 'lcrm_view' );
	update_user_meta( $bulk_member, '_lcrm_institutions', array( $a ) );
	wp_set_current_user( $bulk_member );
	bulk_check( 403 === bulk_request( $payload )->get_status() && $before_audits === bulk_audits(), 'Read-only institution users cannot change tariffs, including their own institution' );
	wp_set_current_user( $bulk_admin->ID );
	$response = bulk_request( $payload );
	bulk_check( 200 === $response->get_status() && array( 'updated' => array( $a, $b ), 'unchanged' => array(), 'count' => 2 ) === $response->get_data(), 'One bulk request applies a common exact decimal rate to published and draft institutions' );
	foreach ( array( $a => $old_a, $b => $old_b ) as $id => $old ) {
		$config = bulk_config( $id );
		bulk_check( $config['credit_days'] === $old['credit_days'] && $config['preserved'] === $old['preserved'] && $config['rates'][0] === $old['rates'][0] && $config['rates'][2] === $old['rates'][1] && array( 'from' => '2026-11-01', 'price' => 1234, 'vat_bp' => 1800 ) === $config['rates'][1], 'Bulk tariffs preserve credit days, historical VAT, earlier and later rates, and independent agreement fields for ' . $id );
	}
	bulk_check( $policy_a === get_post_meta( $a, '_lcrm_duplicate_policy', true ) && $policy_b === get_post_meta( $b, '_lcrm_duplicate_policy', true ) && $before_audits + 2 === bulk_audits(), 'Each changed institution receives an audit while its duplicate policy remains unchanged' );
	$response = bulk_request( $payload );
	bulk_check( 200 === $response->get_status() && array( 'updated' => array(), 'unchanged' => array( $a, $b ), 'count' => 0 ) === $response->get_data() && $before_audits + 2 === bulk_audits(), 'Exact request replay reports unchanged institutions and writes no additional audit events' );
	$mixed = $payload;
	$mixed['institutions'] = array( $a, $c );
	$response = bulk_request( $mixed );
	bulk_check( 200 === $response->get_status() && array( 'updated' => array( $c ), 'unchanged' => array( $a ), 'count' => 1 ) === $response->get_data() && 0 === bulk_config( $c )['credit_days'], 'A mixed replay counts actual updates and initializes only an absent agreement with zero credit days' );

	$invalid = array(
		array(),
		array( 'institutions' => array() ),
		array( 'institutions' => array( $a, $a ) ),
		array( 'institutions' => array( $a, (string) $a ) ),
		array( 'institutions' => array( 0 ) ),
		array( 'institutions' => array( -1 ) ),
		array( 'institutions' => array( true ) ),
		array( 'institutions' => array( false ) ),
		array( 'institutions' => array( null ) ),
		array( 'institutions' => array( 1.25 ) ),
		array( 'institutions' => array( array( $a ) ) ),
		array( 'institutions' => array( (object) array( 'id' => $a ) ) ),
		array( 'institutions' => array( '0' ) ),
		array( 'institutions' => array( '01' ) ),
		array( 'institutions' => array( '1e2' ) ),
		array( 'institutions' => array( '99999999999' ) ),
		array( 'institutions' => range( 1, 101 ) ),
		array( 'institutions' => '1,2' ),
		array( 'institutions' => null ),
		array( 'institutions' => true ),
		array( 'from' => '2026-02-30' ),
		array( 'from' => '2026-1-01' ),
		array( 'from' => '2026-11-01 00:00:00' ),
		array( 'from' => null ),
		array( 'from' => true ),
		array( 'from' => 20261101 ),
		array( 'from' => array( '2026-11-01' ) ),
		array( 'from' => (object) array( 'value' => '2026-11-01' ) ),
		array( 'price' => '-1' ),
		array( 'price' => '1.005' ),
		array( 'price' => '1e2' ),
		array( 'price' => '100000000' ),
		array( 'price' => '12,34' ),
		array( 'price' => ' 12.34' ),
		array( 'price' => null ),
		array( 'price' => true ),
		array( 'price' => false ),
		array( 'price' => 12.34 ),
		array( 'price' => array( '12.34' ) ),
		array( 'price' => (object) array( 'value' => '12.34' ) ),
		array( 'credit_days' => 30 ),
		array( 'duplicate_mode' => 'calendar' ),
		array( 'vat_percent' => '99' ),
		array( 'unexpected' => 'value' ),
	);
	$before_a = bulk_config( $a );
	$before_b = bulk_config( $b );
	$before_audits = bulk_audits();
	foreach ( $invalid as $index => $patch ) {
		$input = 0 === $index ? $patch : array_replace( $payload, $patch );
		bulk_check( 400 === bulk_request( $input )->get_status() && $before_a === bulk_config( $a ) && $before_b === bulk_config( $b ) && $before_audits === bulk_audits(), 'Malformed bulk input is rejected atomically: case ' . $index );
	}
	foreach ( array( 'from', 'price', 'institutions' ) as $missing ) {
		$input = $payload;
		unset( $input[ $missing ] );
		bulk_check( 400 === bulk_request( $input )->get_status(), 'The bulk request requires the field ' . $missing );
	}
	$object_list = '{"institutions":{"0":' . $a . '},"from":"2026-11-01","price":"12.34"}';
	$float_id = '{"institutions":[' . $a . '.0],"from":"2026-11-01","price":"12.34"}';
	bulk_check( 400 === bulk_request( $object_list, true, 'agreements-bulk', true )->get_status() && 400 === bulk_request( $float_id, true, 'agreements-bulk', true )->get_status(), 'An object with numeric keys and a floating-point ID cannot masquerade as an institution list' );
	$trash = bulk_institution( 'trashed', null, 'trash' );
	$wrong_type = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $bulk_prefix . ' wrong type' ) );
	foreach ( array( 9999999999, $trash, $wrong_type ) as $bad_id ) {
		$input = array( 'institutions' => array( $a, $bad_id ), 'from' => '2026-12-01', 'price' => '50' );
		bulk_check( 400 === bulk_request( $input )->get_status() && $before_a === bulk_config( $a ) && $before_audits === bulk_audits(), 'A later missing, trashed or wrong-type recipient rolls back the earlier institution and its audit: ' . $bad_id );
	}

	$bill = LimuCRM\locked( static function () use ( $b ) {
		return LimuCRM\save_record( 'lcrm_bill', array( 'institution' => $b, 'month' => '2026-11', 'state' => 'approved', 'subtotal' => 1234, 'vat' => 222, 'total' => 1456, 'paid' => 0, 'lines' => array(), 'credit_days' => 90, 'issued' => '2026-12-01', 'due' => '2027-03-01' ) );
	} );
	bulk_check( ! is_wp_error( $bill ), 'An isolated approved bill exists for the closed-period boundary' );
	$before_bill = $bill;
	$poisoned_bill = $bill;
	$poisoned_bill['month'] = '2025-01';
	wp_cache_set( $bill['id'], array( '_lcrm_data' => array( maybe_serialize( $poisoned_bill ) ) ), 'post_meta' );
	$closed = array( 'institutions' => array( $a, $b ), 'from' => '2026-11-30', 'price' => '88' );
	bulk_check( 409 === bulk_request( $closed )->get_status() && $before_a === bulk_config( $a ) && $before_b === bulk_config( $b ) && $before_audits === bulk_audits(), 'A closed month on the second institution rolls back the whole batch despite stale cached bill dates' );
	$replay = bulk_request( $payload );
	bulk_check( 200 === $replay->get_status() && 0 === $replay->get_data()['count'] && $before_audits === bulk_audits(), 'An exact unchanged replay stays harmless after a period has been approved' );
	$future = array( 'institutions' => array( $a, $b ), 'from' => '2026-12-01', 'price' => '24.56' );
	bulk_check( 200 === bulk_request( $future )->get_status() && $before_bill === LimuCRM\raw_records( array( $bill['id'] ) )[0], 'A rate beginning in the next month succeeds without changing the approved financial snapshot' );
	$single = bulk_request( array( 'institution' => $a, 'from' => '2026-12-15', 'price' => '33.00', 'credit_days' => '25', 'vat_percent' => '99' ), true, 'agreement' );
	bulk_check( 200 === $single->get_status() && 25 === $single->get_data()['credit_days'] && array( 'from' => '2026-12-15', 'price' => 3300, 'vat_bp' => 1800 ) === LimuCRM\rate_on( $single->get_data(), '2026-12-15' ), 'The individual agreement route retains its response shape, editable credit terms and ignored legacy VAT input' );
	bulk_check( 409 === bulk_request( array( 'institution' => $b, 'from' => '2026-11-01', 'price' => '99', 'credit_days' => 90 ), true, 'agreement' )->get_status(), 'Individual and bulk edits share the same closed-period guard' );
	foreach ( array( true, null, 1.5, '366', '-1' ) as $bad_days ) {
		bulk_check( 400 === bulk_request( array( 'institution' => $a, 'from' => '2026-12-15', 'price' => '33', 'credit_days' => $bad_days ), true, 'agreement' )->get_status(), 'The shared individual agreement validator rejects unsafe credit terms: ' . wp_json_encode( $bad_days ) );
	}

	$before_a = bulk_config( $a );
	$before_b = bulk_config( $b );
	$before_audits = bulk_audits();
	$fault_payload = array( 'institutions' => array( $a, $b ), 'from' => '2026-12-20', 'price' => '70.01' );
	$bulk_meta_fault = static function ( $check, $id, $key ) use ( $b ) {
		return $id === $b && '_lcrm_agreement' === $key ? false : $check;
	};
	add_filter( 'update_post_metadata', $bulk_meta_fault, 10, 3 );
	$failed = bulk_request( $fault_payload );
	remove_filter( 'update_post_metadata', $bulk_meta_fault, 10 );
	$bulk_meta_fault = null;
	bulk_check( 500 === $failed->get_status() && $before_a === bulk_config( $a ) && $before_b === bulk_config( $b ) && $before_audits === bulk_audits() && $before_a === LimuCRM\agreement( $a ), 'A second-institution write failure rolls back all rates, all audits and cached agreement state' );
	$audit_attempts = 0;
	$bulk_audit_fault = static function ( $empty, $post ) use ( &$audit_attempts ) {
		if ( 'lcrm_audit' === ( $post['post_type'] ?? '' ) && ++$audit_attempts === 2 ) {
			return true;
		}
		return $empty;
	};
	add_filter( 'wp_insert_post_empty_content', $bulk_audit_fault, 10, 2 );
	$failed = bulk_request( $fault_payload );
	remove_filter( 'wp_insert_post_empty_content', $bulk_audit_fault, 10 );
	$bulk_audit_fault = null;
	bulk_check( 500 === $failed->get_status() && $before_a === bulk_config( $a ) && $before_b === bulk_config( $b ) && $before_audits === bulk_audits(), 'An audit failure after both rate writes rolls back the entire financial operation' );
	$bulk_query_fault = static function ( $sql ) {
		return false !== strpos( $sql, 'SELECT p.ID FROM' ) && false !== strpos( $sql, "'_lcrm_state'" ) ? '' : $sql;
	};
	add_filter( 'query', $bulk_query_fault );
	$failed = bulk_request( $fault_payload );
	remove_filter( 'query', $bulk_query_fault );
	$bulk_query_fault = null;
	bulk_check( 500 === $failed->get_status() && $before_a === bulk_config( $a ) && $before_b === bulk_config( $b ) && $before_audits === bulk_audits(), 'A suppressed approved-period query fails closed instead of treating an unreadable period as empty' );
	$bulk_query_fault = static function ( $sql ) use ( $b ) {
		return false !== strpos( $sql, 'SELECT p.post_type, p.post_status, m.meta_value' ) && false !== strpos( $sql, 'WHERE p.ID = ' . $b ) ? '' : $sql;
	};
	add_filter( 'query', $bulk_query_fault );
	$failed = bulk_request( $fault_payload );
	remove_filter( 'query', $bulk_query_fault );
	$bulk_query_fault = null;
	bulk_check( 500 === $failed->get_status() && $before_a === bulk_config( $a ) && $before_b === bulk_config( $b ) && $before_audits === bulk_audits(), 'A second-institution authoritative snapshot read failure rolls back the earlier rate and audit' );
	$malformed = $before_b;
	$malformed['rates'] = 'invalid';
	update_post_meta( $b, '_lcrm_agreement', $malformed );
	$failed = bulk_request( $fault_payload );
	bulk_check( 500 === $failed->get_status() && $before_a === bulk_config( $a ) && $malformed === bulk_config( $b ) && $before_audits === bulk_audits(), 'Malformed existing history fails closed and cannot erase an institution agreement or partially update another' );
	update_post_meta( $b, '_lcrm_agreement', $before_b );
	$cache_poison = array( 'credit_days' => 1, 'rates' => array( array( 'from' => '2026-12-20', 'price' => 7001, 'vat_bp' => 1800 ) ) );
	wp_cache_set( $a, array( '_lcrm_agreement' => array( maybe_serialize( $cache_poison ) ) ), 'post_meta' );
	$target_cache = $before_b;
	$target_cache['rates'][] = array( 'from' => '2026-12-20', 'price' => 7001, 'vat_bp' => 1800 );
	usort( $target_cache['rates'], static function ( $first, $second ) { return strcmp( $first['from'], $second['from'] ); } );
	wp_cache_set( $b, array( '_lcrm_agreement' => array( maybe_serialize( $target_cache ) ) ), 'post_meta' );
	$repaired = bulk_request( $fault_payload );
	bulk_check( 200 === $repaired->get_status() && array( $a, $b ) === $repaired->get_data()['updated'] && 25 === bulk_config( $a )['credit_days'] && 90 === bulk_config( $b )['credit_days'] && 6 === count( bulk_config( $a )['rates'] ) && $target_cache === bulk_config( $b ), 'Fresh locked snapshots preserve true credit terms and all history while a stale cached target cannot skip persistence' );
	$strings = array( 'institutions' => array( (string) $a ), 'from' => '2026-12-20', 'price' => '70.01' );
	bulk_check( 200 === bulk_request( $strings )->get_status() && array( $a ) === bulk_request( $strings )->get_data()['unchanged'], 'Decimal digit IDs normalize to numeric response IDs without duplicating a rate or audit' );
	$zero = array( 'institutions' => array( $c ), 'from' => '2026-12-20', 'price' => 0 );
	bulk_check( 200 === bulk_request( $zero )->get_status() && 0 === LimuCRM\rate_on( bulk_config( $c ), '2026-12-20' )['price'], 'A valid zero-cost institution tariff uses the same exact-money semantics as the individual editor' );
	$hundred = array();
	for ( $index = 0; $index < 100; ++$index ) {
		$hundred[] = bulk_institution( 'bounded-' . $index );
	}
	$bounded = bulk_request( array( 'institutions' => $hundred, 'from' => '2026-12-20', 'price' => '1' ) );
	bulk_check( 200 === $bounded->get_status() && $hundred === $bounded->get_data()['updated'] && 100 === $bounded->get_data()['count'] && 100 === bulk_config( $hundred[99] )['rates'][0]['price'], 'A full bounded selection of one hundred unique institutions commits atomically' );

	echo "\n$bulk_passed bulk tariff assertions passed.\n";
} catch ( Throwable $exception ) {
	fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n" );
	$bulk_failed = true;
} finally {
	if ( $bulk_query_fault ) { remove_filter( 'query', $bulk_query_fault ); }
	if ( $bulk_meta_fault ) { remove_filter( 'update_post_metadata', $bulk_meta_fault, 10 ); }
	if ( $bulk_audit_fault ) { remove_filter( 'wp_insert_post_empty_content', $bulk_audit_fault, 10 ); }
	wp_set_current_user( $bulk_old_user );
	remove_action( 'wp_after_insert_post', $bulk_tracker, 99 );
	foreach ( array_reverse( array_unique( $bulk_posts ) ) as $id ) { wp_delete_post( $id, true ); }
	if ( $bulk_member && ! is_wp_error( $bulk_member ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $bulk_member );
	}
	remove_filter( 'pre_http_request', $bulk_http );
}
if ( $bulk_failed ) {
	exit( 1 );
}
