<?php
/** Automatic site adapters tested only with synthetic data in the disposable database. */
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
require_once __DIR__ . '/../limu-crm/includes/native.php';
register_post_type( 'institutions', array( 'public' => false ) );
register_post_type( 'leads', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$passed = 0;
$created = array();
function native_check( $value, $message ) {
	global $passed;
	if ( ! $value ) {
		throw new Exception( $message );
	}
	++$passed;
	echo "PASS $message\n";
}
function native_test_lead( $institution, $date, $phone, $status = 'publish' ) {
	$id = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => $status, 'post_date' => $date, 'post_title' => 'בדיקת קליטה בלבד' ) );
	foreach ( array( 'institution' => $institution, 'full-name' => 'פונה לדוגמה בלבד', 'phone' => $phone, 'email' => '', 'form-name' => 'native-test' ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}
$track = function ( $id, $post, $update ) use ( &$created ) {
	if ( ! $update && in_array( $post->post_type, array( 'institutions', 'leads', 'lcrm_contact', 'lcrm_delivery', 'lcrm_bill', 'lcrm_audit' ), true ) ) {
		$created[] = $id;
	}
};
add_action( 'wp_after_insert_post', $track, 99, 3 );
$old_settings = get_option( 'lcrm_settings', null );
$old_boundary = get_option( 'lcrm_live_capture_from', null );
$old_action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : null;
$ajax = function () { return true; };
try {
	$admin = get_user_by( 'login', 'crm-admin' );
	wp_set_current_user( $admin->ID );
	$month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
	$start = $month . '-01';
	update_option( 'lcrm_live_capture_from', $start . ' 00:00:00', false );
	update_option( 'lcrm_settings', array( 'duplicate_mode' => 'calendar', 'automatic' => false, 'start_date' => $start ), false );
	$prefix = 'native-' . wp_generate_uuid4();
	$a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $prefix . ' א' ) );
	$b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $prefix . ' ב' ) );
	foreach ( array( $a => 5000, $b => 7500 ) as $id => $price ) {
		update_post_meta( $id, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => $start, 'price' => $price, 'vat_bp' => 1800 ) ) ) );
	}
	$title_a = get_post_field( 'post_title', $a, 'raw' );
	$title_b = get_post_field( 'post_title', $b, 'raw' );
	$shared = native_test_lead( $title_a . ', ' . $title_b, $month . '-05 10:00:00', '0529876501' );
	$results = LimuCRM\native_flush();
	native_check( isset( $results[ $shared ] ) && 2 === count( $results[ $shared ] ), 'New stored shared lead is captured automatically for both exact institutions' );
	$deliveries = $results[ $shared ];
	native_check( 'sent' === $deliveries[0]['state'] && 'sent' === $deliveries[1]['state'], 'Live native lead requires no manual confirmation' );
	native_check( $shared === $deliveries[0]['contact'] && $shared === $deliveries[1]['contact'], 'Shared deliveries reference the existing contact without copying its data' );
	native_check( ! isset( get_post_meta( $deliveries[0]['id'], '_lcrm_data', true )['phone'] ), 'Private delivery payload does not copy the native contact fields' );
	$retry = LimuCRM\locked( function () use ( $shared ) { return LimuCRM\native_capture( $shared ); } );
	native_check( $retry[0]['id'] === $deliveries[0]['id'], 'Retry reuses the stable native source delivery' );
	$import_retry = LimuCRM\locked( function () use ( $shared, $a, $month ) { return LimuCRM\record_delivery( array( 'name' => 'פונה לדוגמה בלבד', 'phone' => '0529876501', 'email' => '', 'form' => 'native-test', 'date' => $month . '-05 10:00:00', 'contact' => $shared, 'legacy_id' => $shared ), $a, 'legacy:' . $shared, true ); } );
	native_check( $import_retry['id'] === $deliveries[0]['id'] && 'sent' === $import_retry['state'], 'Historical import source cannot duplicate or downgrade an already captured live lead' );
	$draft = native_test_lead( $title_a, $month . '-06 10:00:00', '0529876501', 'draft' );
	native_check( ! LimuCRM\native_flush(), 'Draft source is not exposed as a live delivery' );
	wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ) );
	$results = LimuCRM\native_flush();
	native_check( $results[ $draft ][0]['duplicate_of'] === $deliveries[0]['id'], 'New draft published later in the same request is captured and its duplicate excluded' );
	$old_date = ( new DateTimeImmutable( $start, wp_timezone() ) )->modify( '-1 month' )->format( 'Y-m' ) . '-05 10:00:00';
	$historical = native_test_lead( $title_a, $old_date, '0529876502' );
	$history = LimuCRM\native_flush()[ $historical ][0];
	native_check( 'historical' === $history['state'] && ! $history['origin_live'], 'Newly observed backdated source stays historical and nonbillable' );
	unset( $GLOBALS['lcrm_native_new_leads'], $GLOBALS['lcrm_native_queue'] );
	wp_update_post( array( 'ID' => $historical, 'post_title' => 'עדכון נתון היסטורי בלבד' ) );
	update_post_meta( $historical, 'phone', '0529876503' );
	native_check( ! LimuCRM\native_flush() && 'historical' === LimuCRM\data( $history['id'] )['state'], 'Editing an existing historical lead never turns it into a live billed lead' );
	$unknown = native_test_lead( $prefix . ' missing', $month . '-07 10:00:00', '0529876504' );
	$unmapped = LimuCRM\native_flush()[ $unknown ];
	native_check( 'unmapped' === $unmapped['state'] && 0 === $unmapped['institution'] && $unmapped['origin_live'], 'Unresolved new recipient remains a private mapping exception with its live origin' );
	$bad = native_test_lead( $title_a, $month . '-08 10:00:00', 'invalid' );
	$invalid = LimuCRM\native_flush()[ $bad ];
	native_check( 'unmapped' === $invalid['state'] && 'contact' === $invalid['capture_error'], 'Invalid live contact remains a nonbillable private exception' );
	$racing = native_test_lead( $title_a, $month . '-08 11:00:00', '0529876501' );
	$import_first = LimuCRM\locked( function () use ( $racing, $a, $month ) { return LimuCRM\record_delivery( array( 'name' => 'פונה לדוגמה בלבד', 'phone' => '0529876501', 'email' => '', 'form' => 'native-test', 'date' => $month . '-08 11:00:00', 'contact' => $racing, 'legacy_id' => $racing ), $a, 'legacy:' . $racing, true ); } );
	native_check( 'historical' === $import_first['state'], 'Overlapping import can arrive before the queued live source is flushed' );
	$race_result = LimuCRM\native_flush()[ $racing ][0];
	native_check( $import_first['id'] === $race_result['id'] && 'sent' === $race_result['state'] && $race_result['origin_live'] && $race_result['duplicate_of'] === $deliveries[0]['id'], 'Only a source newly observed in this request is promoted after an import race, with duplicate re-evaluation' );
	$bill_a = LimuCRM\locked( function () use ( $a, $month ) { return LimuCRM\prepare_bill( $a, $month ); } );
	$bill_b = LimuCRM\locked( function () use ( $b, $month ) { return LimuCRM\prepare_bill( $b, $month ); } );
	native_check( 5900 === $bill_a['total'] && 8850 === $bill_b['total'] && 1 === count( $bill_a['lines'] ), 'Automatic shared leads use independent tariffs and VAT while duplicates remain excluded' );
	$approved = LimuCRM\locked( function () use ( $bill_a ) { return LimuCRM\approve_bill( $bill_a['id'] ); } );
	native_check( ! is_wp_error( $approved ), 'Monthly approval still operates separately from automatic lead capture' );
	$late = native_test_lead( $title_a, $month . '-09 10:00:00', '0529876505' );
	$late_result = LimuCRM\native_flush()[ $late ];
	native_check( is_wp_error( $late_result ) && 5900 === LimuCRM\data( $bill_a['id'] )['total'], 'Late capture cannot change an already approved bill snapshot' );
	$frozen_race = native_test_lead( $title_a, $month . '-09 11:00:00', '0529876507' );
	$frozen_history = LimuCRM\locked( function () use ( $frozen_race, $a, $month ) { return LimuCRM\record_delivery( array( 'name' => 'פונה לדוגמה בלבד', 'phone' => '0529876507', 'email' => '', 'form' => 'native-test', 'date' => $month . '-09 11:00:00', 'contact' => $frozen_race, 'legacy_id' => $frozen_race ), $a, 'legacy:' . $frozen_race, true ); } );
	$frozen_result = LimuCRM\native_flush()[ $frozen_race ];
	native_check( is_wp_error( $frozen_result ) && 'historical' === LimuCRM\data( $frozen_history['id'] )['state'] && 5900 === LimuCRM\data( $bill_a['id'] )['total'], 'Import-race promotion cannot change an already approved billing period' );
	update_post_meta( $a, 'cid', $prefix . '-cid-a' );
	update_post_meta( $b, 'cid', $prefix . '-cid-b' );
	add_filter( 'wp_doing_ajax', $ajax );
	$_REQUEST['action'] = 'institution_interested';
	$response = array( 'response' => array( 'code' => 200 ), 'body' => 'accepted' );
	$args = array( 'method' => 'GET' );
	$query = array( 'cId' => $prefix . '-cid-a', 'fName' => 'פונה AJAX לדוגמה בלבד', 'phone' => '0529876506', 'email' => '' );
	$url = 'https://ext.bhol.co.il/lead.php?' . http_build_query( $query );
	native_check( null === LimuCRM\native_http_capture( $response, 'response', 'test', $args, str_replace( 'ext.bhol.co.il', 'invalid.example', $url ) ), 'Existing AJAX observer ignores an untrusted outbound hostname' );
	native_check( null === LimuCRM\native_http_capture( array( 'response' => array( 'code' => 500 ) ), 'response', 'test', $args, $url ), 'Failed AJAX HTTP transport is not counted as a lead' );
	$ajax_a = LimuCRM\native_http_capture( $response, 'response', 'test', $args, $url );
	$query['cId'] = $prefix . '-cid-b';
	$ajax_b = LimuCRM\native_http_capture( $response, 'response', 'test', $args, 'https://ext.bhol.co.il/lead.php?' . http_build_query( $query ) );
	native_check( ! is_wp_error( $ajax_a ) && ! is_wp_error( $ajax_b ) && $ajax_a['contact'] === $ajax_b['contact'] && $a === $ajax_a['institution'] && $b === $ajax_b['institution'], 'Accepted existing AJAX request captures the same contact separately for server-resolved CID recipients' );
	native_check( 'פונה AJAX לדוגמה בלבד' === $ajax_a['name'], 'AJAX observer reads the existing fName parameter correctly' );
	$again = LimuCRM\native_http_capture( $response, 'response', 'test', $args, $url );
	native_check( $again['id'] === $ajax_a['id'], 'Repeated HTTP observer callback is idempotent within the same request' );
	update_post_meta( $b, 'cid', $prefix . '-cid-a' );
	native_check( null === LimuCRM\native_http_capture( $response, 'response', 'test', $args, $url ), 'Ambiguous CID mapping cannot disclose a contact to guessed institutions' );
	$_REQUEST['action'] = array( 'institution_interested' );
	native_check( null === LimuCRM\native_http_capture( $response, 'response', 'test', $args, $url ), 'Malformed public action selector is rejected safely' );
	echo "OK $passed automatic native capture assertions\n";
} finally {
	remove_filter( 'wp_doing_ajax', $ajax );
	remove_action( 'wp_after_insert_post', $track, 99 );
	unset( $GLOBALS['lcrm_native_new_leads'], $GLOBALS['lcrm_native_queue'], $GLOBALS['lcrm_native_ajax_source'] );
	foreach ( array_reverse( array_unique( $created ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( null === $old_settings ) { delete_option( 'lcrm_settings' ); } else { update_option( 'lcrm_settings', $old_settings, false ); }
	if ( null === $old_boundary ) { delete_option( 'lcrm_live_capture_from' ); } else { update_option( 'lcrm_live_capture_from', $old_boundary, false ); }
	if ( null === $old_action ) { unset( $_REQUEST['action'] ); } else { $_REQUEST['action'] = $old_action; }
}
