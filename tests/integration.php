<?php
/** Integration tests run against a disposable WordPress database, never the production database. */
$root = getenv( 'LIMU_WP_ROOT' ); if ( ! $root ) { fwrite( STDERR, "Set LIMU_WP_ROOT to a disposable WordPress installation.\n" ); exit( 1 ); }
require $root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) { fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_true' );
register_post_type( 'institutions', array( 'public' => false ) ); register_post_type( 'leads', array( 'public' => false ) );
$passed = 0;
function check( $value, $message ) { global $passed; if ( ! $value ) { throw new Exception( $message ); } ++$passed; echo "PASS $message\n"; }
function request( $route, $body = null, $nonce = true ) {
 $r = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/limu-crm/v1/' . $route );
 if ( $nonce ) { $r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
 if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
 return rest_do_request( $r );
}
function filtered_read( $route, $query ) {
 $r = new WP_REST_Request( 'GET', '/limu-crm/v1/' . $route );
 foreach ( $query as $key => $value ) { $r->set_param( $key, $value ); }
 return rest_do_request( $r );
}
try {
 $admin = get_user_by( 'login', 'crm-admin' ); wp_set_current_user( $admin->ID );
 foreach ( array( 'lcrm_contact', 'lcrm_delivery', 'lcrm_bill', 'lcrm_payment', 'lcrm_audit', 'institutions', 'leads' ) as $type ) {
  $ids = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ); foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
 }
 delete_option( 'lcrm_settings' );
 $a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => 'מוסד א' ) );
 $b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => 'מוסד ב' ) );
 $month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
 $start = $month . '-01';
 check( 200 === request( 'settings', array( 'duplicate_mode' => 'calendar', 'start_date' => $start, 'automatic' => false ) )->get_status(), 'Settings saved' );
 foreach ( array( $a => '50.00', $b => '75.00' ) as $id => $price ) {
  check( 200 === request( 'agreement', array( 'institution' => $id, 'price' => $price, 'from' => $start, 'credit_days' => '30' ) )->get_status(), 'Institution tariff saved without a per-institution VAT field' );
 }
 check( '0501234567' === LimuCRM\normalize_phone( '+972-50-123-4567' ), 'Israeli phone normalization' );
 check( 12345 === LimuCRM\money( '123.45' ) && null === LimuCRM\money( '1.005' ), 'Money uses exact agorot' );
 check( ! LimuCRM\same_contact( array( 'phone_key' => '', 'email_key' => '' ), array( 'phone_key' => '', 'email_key' => '' ) ), 'Empty contacts do not duplicate' );
 check( ! LimuCRM\in_window( '2025-12-31', '2026-01-01', 'calendar' ) && LimuCRM\in_window( '2025-12-31', '2026-01-01', 'rolling' ), 'Calendar and rolling windows differ' );
 check( ! LimuCRM\in_window( '2025-01-01', '2026-01-01', 'rolling' ), 'Rolling window expires at anniversary' );
 $lead = array( 'name' => '<script>alert(1)</script> פונה', 'phone' => '0501234567', 'email' => 'one@example.test', 'form' => 'טופס כללי', 'date' => $month . '-05 10:00:00' );
 $first = LimuCRM\locked( fn() => LimuCRM\record_delivery( $lead, $a, 'test:first' ) );
 $again = LimuCRM\locked( fn() => LimuCRM\record_delivery( $lead, $a, 'test:first' ) ); check( $first['id'] === $again['id'], 'Source replay is idempotent' );
 $other = LimuCRM\locked( fn() => LimuCRM\record_delivery( $lead, $b, 'test:first' ) ); check( ! $other['duplicate_of'], 'Same general lead bills separately per institution' );
 check( $first['contact'] === $other['contact'] && ! isset( get_post_meta( $first['id'], '_lcrm_data', true )['phone'] ), 'General lead contact details stored once across institutions' );
 $lead['date'] = $month . '-06 10:00:00'; $lead['email'] = 'changed@example.test';
 $dup_phone = LimuCRM\locked( fn() => LimuCRM\record_delivery( $lead, $a, 'test:phone' ) ); check( $dup_phone['duplicate_of'] === $first['id'], 'Phone alone matches duplicate' );
 $lead['phone'] = '0529876543'; $lead['email'] = 'one@example.test'; $lead['date'] = $month . '-07 10:00:00';
 $dup_email = LimuCRM\locked( fn() => LimuCRM\record_delivery( $lead, $a, 'test:email' ) ); check( $dup_email['duplicate_of'] === $first['id'], 'Email alone matches duplicate' );
 $legacy = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_title' => 'רשומת עבר', 'post_date' => $month . '-01 09:00:00' ) );
 foreach ( array( 'institution' => 'מוסד א, מוסד ב', 'phone' => '0541111111', 'email' => 'legacy@example.test', 'full-name' => 'פונה מהעבר', 'form-name' => 'טופס היסטורי' ) as $key => $value ) { update_post_meta( $legacy, $key, $value ); }
 $r = request( 'import', array( 'after' => 0 ) )->get_data(); check( 1 === $r['processed'] && 2 === $r['deliveries'], 'Historical general lead resolves two institutions' );
 $before = LimuCRM\query_records( 'lcrm_delivery' )['total']; request( 'import', array( 'after' => 0 ) ); check( $before === LimuCRM\query_records( 'lcrm_delivery' )['total'], 'Historical import repeatable without duplication' );
 $bill = LimuCRM\locked( fn() => LimuCRM\prepare_bill( $a, $month ) );
 check( 1 === count( $bill['lines'] ) && 5900 === $bill['total'] && 2 === $bill['duplicates'] && 1 === $bill['historical'], 'Billing excludes duplicates and unverified history' );
 $bill2 = LimuCRM\locked( fn() => LimuCRM\prepare_bill( $b, $month ) ); check( 8850 === $bill2['total'], 'Each institution has its own tariff plus VAT' );
 $batch = request( 'approve', array( 'ids' => array( $bill['id'], $bill2['id'] ) ) ); check( 200 === $batch->get_status() && 'approved' === $batch->get_data()['results'][0]['state'], 'Bulk approval succeeds' );
 $frozen = LimuCRM\data( $bill['id'] ); check( $frozen['due'] === LimuCRM\due_date( current_time( 'Y-m-d' ), 30 ), 'Credit days count from actual issue date' );
 $r = request( 'agreement', array( 'institution' => $a, 'price' => '90', 'from' => $start, 'credit_days' => 30 ) ); check( 409 === $r->get_status(), 'Retrospective tariff edits blocked for approved month' );
 check( LimuCRM\locked( fn() => LimuCRM\prepare_bill( $a, $month ) )['total'] === 5900, 'Approved bill remains immutable' );
 $payment = array( 'bill' => $bill['id'], 'amount' => '20', 'date' => current_time( 'Y-m-d' ), 'method' => 'transfer', 'reference' => 'test', 'request_key' => 'test-payment-key-000001' );
 $r1 = request( 'payment', $payment ); $r2 = request( 'payment', $payment ); check( $r1->get_data()['id'] === $r2->get_data()['id'] && 2000 === LimuCRM\data( $bill['id'] )['paid'], 'Payment replay does not double count' );
 $payment['request_key'] = 'test-payment-key-000002'; $payment['amount'] = '40'; check( 409 === request( 'payment', $payment )->get_status(), 'Overpayment is rejected' );
 $payment['amount'] = '39'; check( 200 === request( 'payment', $payment )->get_status() && 5900 === LimuCRM\data( $bill['id'] )['paid'], 'Partial and full manual payment totals correct' );
 $uid = username_exists( 'institution-test' ) ?: wp_create_user( 'institution-test', wp_generate_password( 40 ), 'institution@example.test' );
 request( 'member', array( 'email' => 'institution@example.test', 'institutions' => array( $a ) ) ); wp_set_current_user( $uid );
 $r = request( 'deliveries' )->get_data(); check( count( $r['items'] ) > 0 && ! array_filter( $r['items'], fn( $x ) => $x['institution'] !== $a ), 'Institution sees only assigned institution' );
 $req = new WP_REST_Request( 'GET', '/limu-crm/v1/bills' ); $req->set_param( 'institution', $b ); check( 403 === rest_do_request( $req )->get_status(), 'Direct institution ID tampering is forbidden' );
 check( 403 === request( 'payment', $payment )->get_status(), 'Institution cannot mutate via REST' );
 check( 403 === request( 'audit' )->get_status(), 'Institution cannot access audit log' );
 check( ! isset( $r['items'][0]['phone_key'] ) && ! isset( $r['items'][0]['notes'] ), 'Institution response omits internal fields' );
 wp_set_current_user( $admin->ID ); check( 403 === request( 'settings', array( 'duplicate_mode' => 'calendar' ), false )->get_status(), 'Missing mutation nonce rejected' );
 $export = request( 'export' )->get_data(); check( isset( $export['file'] ) && 0 === strpos( base64_decode( $export['file'] ), 'PK' ), 'Real XLSX archive exported' );
 $path = tempnam( sys_get_temp_dir(), 'lcrm' ); file_put_contents( $path, base64_decode( $export['file'] ) ); $zip = new ZipArchive(); $zip->open( $path );
 $sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); check( false !== strpos( $sheet, 'inlineStr' ) && false === strpos( $sheet, '<f>' ), 'Spreadsheet cells do not execute formulas' ); $zip->close(); unlink( $path );

 $count_before = LimuCRM\query_records( 'lcrm_delivery' )['total'];
 LimuCRM\locked( function () use ( $a, $month ) { LimuCRM\record_delivery( array( 'name' => 'rollback', 'phone' => '0535555555', 'email' => '', 'date' => $month . '-10 12:00:00', 'form' => 'test' ), $a, 'test:rollback' ); return LimuCRM\error( 'test rollback' ); } );
 check( $count_before === LimuCRM\query_records( 'lcrm_delivery' )['total'], 'Failed mutation rolls back database and object cache' );
 LimuCRM\native_flush(); unset( $GLOBALS['lcrm_native_new_leads'], $GLOBALS['lcrm_native_queue'] );
 $new_lead = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_title' => 'פונה חדש', 'post_date' => current_time( 'mysql' ) ) );
 foreach ( array( 'institution' => 'מוסד א', 'full-name' => 'פונה חדש', 'phone' => '0532222222', 'email' => 'capture@example.test', 'form-name' => 'טופס בדיקה' ) as $key => $value ) { update_post_meta( $new_lead, $key, $value ); }
 LimuCRM\native_flush();
 $captured = LimuCRM\query_records( 'lcrm_delivery', array( 'state' => 'sent', 'month' => current_time( 'Y-m' ) ) )['items'];
 check( 1 === count( $captured ) && $a === $captured[0]['institution'] && $new_lead === $captured[0]['contact'], 'Existing site lead is captured automatically with its stored institution and no form mapping' );
 $summary_all = request( 'summary' )->get_data();
 $summary_previous_request = new WP_REST_Request( 'GET', '/limu-crm/v1/summary' ); $summary_previous_request->set_param( 'month', $month );
 $summary_previous = rest_do_request( $summary_previous_request )->get_data();
 $summary_current_request = new WP_REST_Request( 'GET', '/limu-crm/v1/summary' ); $summary_current_request->set_param( 'month', current_time( 'Y-m' ) );
 $summary_current = rest_do_request( $summary_current_request )->get_data();
 check( 7 === $summary_all['leads'] && 2 === $summary_all['historical'], 'Default dashboard includes imported historical deliveries across all months' );
 check( 6 === $summary_previous['leads'] && 1 === $summary_current['leads'] && $summary_all['leads'] === $summary_previous['leads'] + $summary_current['leads'], 'Selecting a month narrows the all-period dashboard without losing historical rows' );
 check( 14750 === $summary_all['approved'] && 5900 === $summary_all['paid'] && 5900 === LimuCRM\data( $bill['id'] )['total'] && 8850 === LimuCRM\data( $bill2['id'] )['total'], 'Reporting historical leads does not create or change any bill' );
 $year = substr( $month, 0, 4 );
 $outside_year = LimuCRM\locked( fn() => LimuCRM\record_delivery( array( 'name' => 'עבר משנה אחרת', 'phone' => '0597876501', 'email' => '', 'form' => 'history', 'date' => ( (int) $year - 1 ) . '-05-10 09:00:00' ), $a, 'test:outside-year', true ) );
 $summary_year = filtered_read( 'summary', array( 'year' => $year ) )->get_data();
 $expected_year_count = $summary_previous['leads'] + ( substr( current_time( 'Y-m' ), 0, 4 ) === $year ? $summary_current['leads'] : 0 );
 check( $expected_year_count === $summary_year['leads'] && 2 === $summary_year['historical'] && 14750 === $summary_year['approved'], 'Full-year summary includes every matching month and excludes other years' );
 $year_deliveries = filtered_read( 'deliveries', array( 'year' => $year, 'institution' => $a ) )->get_data();
 check( ! array_filter( $year_deliveries['items'], fn( $d ) => substr( $d['date'], 0, 4 ) !== $year || $a !== $d['institution'] ), 'Full-year lead list combines year range and institution scope' );
 $year_report = filtered_read( 'report', array( 'target' => 'bills', 'year' => $year, 'institution' => $a ) )->get_data();
 $year_export = filtered_read( 'export', array( 'target' => 'deliveries', 'year' => $year, 'institution' => $a ) )->get_data();
 check( 1 === $year_report['total'] && $bill['id'] === $year_report['items'][0]['id'] && $year_deliveries['total'] === $year_export['total'] && 0 === strpos( base64_decode( $year_export['file'] ), 'PK' ), 'Print reports and XLSX exports apply the same full-year institution filter' );
 wp_delete_post( $outside_year['id'], true ); wp_delete_post( $outside_year['contact'], true );
 wp_set_current_user( $uid ); $visible = request( 'deliveries' )->get_data()['items'];
 check( count( array_filter( $visible, fn( $d ) => $captured[0]['id'] === $d['id'] ) ) === 1, 'Automatically captured lead is visible to its institution without manual approval' );
 $summary_member = request( 'summary' )->get_data();
 check( 5 === $summary_member['leads'] && 1 === $summary_member['historical'] && array( $a ) === array_keys( $summary_member['by_institution'] ) && 5900 === $summary_member['approved'], 'All-period institution dashboard includes its historical rows and excludes every other institution' );
 $member_year = filtered_read( 'summary', array( 'year' => $year ) )->get_data();
 check( 1 === $member_year['historical'] && array( $a ) === array_keys( $member_year['by_institution'] ) && 5900 === $member_year['approved'] && 403 === filtered_read( 'report', array( 'target' => 'deliveries', 'year' => $year, 'institution' => $b ) )->get_status(), 'Full-year institution dashboard and reports preserve institution isolation' );
 wp_set_current_user( $admin->ID );
 check( 404 === request( 'confirm', array( 'id' => $captured[0]['id'] ) )->get_status(), 'Manual lead-confirmation endpoint is absent' );
 check( 200 === request( 'treatment', array( 'id' => $first['id'], 'treatment' => 'working', 'note' => 'Internal note <script>bad</script>' ) )->get_status(), 'Manager can update treatment and internal notes' );
 wp_set_current_user( $uid ); $visible = request( 'deliveries' )->get_data()['items'];
 check( ! array_filter( $visible, fn( $d ) => isset( $d['notes'] ) ), 'Internal notes never appear in institution payloads' );
 wp_set_current_user( $admin->ID );
 $preview_bill = LimuCRM\locked( fn() => LimuCRM\prepare_bill( $a, current_time( 'Y-m' ) ) ); check( is_wp_error( $preview_bill ), 'Open calendar month cannot be billed early' );
 $titles = array( 'Same name' => array( $a, $b ) ); check( ! LimuCRM\legacy_institutions( 'Same name', $titles ), 'Ambiguous historical name remains an exception' );
 $s = LimuCRM\settings(); $s['automatic'] = true; update_option( 'lcrm_settings', $s, false );
 do_action( 'lcrm_daily' ); check( 'approved' === LimuCRM\bill_for( $a, $month )['state'], 'Scheduled monthly job is repeatable for approved bills' );
 $s['automatic'] = false; update_option( 'lcrm_settings', $s, false );


 $count_before = LimuCRM\query_records( 'lcrm_payment' )['total'];
 $fault = function ( $check, $object_id, $meta_key ) use ( $bill2 ) { return $object_id === $bill2['id'] && '_lcrm_data' === $meta_key ? false : $check; };
 add_filter( 'update_post_metadata', $fault, 10, 3 );
 $failed_payment = request( 'payment', array( 'bill' => $bill2['id'], 'amount' => '5', 'date' => current_time( 'Y-m-d' ), 'method' => 'transfer', 'request_key' => 'test-write-failure-000001' ) );
 remove_filter( 'update_post_metadata', $fault, 10 );
 check( 500 === $failed_payment->get_status() && $count_before === LimuCRM\query_records( 'lcrm_payment' )['total'] && 0 === LimuCRM\data( $bill2['id'] )['paid'], 'Payment and bill roll back together when snapshot update fails' );
 $orphan = LimuCRM\locked( fn() => LimuCRM\save_record( 'lcrm_delivery', array( 'name' => 'unmapped', 'phone' => '0541111111', 'email' => 'legacy@example.test', 'phone_key' => '0541111111', 'email_key' => 'legacy@example.test', 'contact' => $legacy, 'institution' => 0, 'state' => 'unmapped', 'date' => $month . '-01 09:00:00', 'month' => $month, 'source' => 'test:unmapped', 'duplicate_of' => 0, 'bill' => 0 ) ) );
 $mapped = request( 'remap', array( 'id' => $orphan['id'], 'institutions' => array( $a, $b ) ) );
 check( 200 === $mapped->get_status() && 2 === count( $mapped->get_data()['results'] ) && 'trash' === get_post_status( $orphan['id'] ), 'Historical exception can be resolved to multiple institutions without billing' );
 $invalid = request( 'payment', array( 'bill' => array( 1 ), 'amount' => '5' ) ); check( 400 === $invalid->get_status(), 'Malformed array input rejected without PHP warnings' );
 putenv( 'LIMU_CRM_TURNSTILE_SECRET=test-fixture-only' );
 check( ! LimuCRM\verify_turnstile( '', 'crm_login' ), 'Turnstile rejects missing token' );
 $mock = function ( $pre, $args, $url ) { if ( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' !== $url ) { throw new Exception( 'Unexpected CAPTCHA destination' ); } check( true === $args['sslverify'], 'Turnstile retains TLS verification' ); return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'hostname' => wp_parse_url( home_url(), PHP_URL_HOST ), 'action' => 'crm_login' ) ) ); };
 add_filter( 'pre_http_request', $mock, 10, 3 ); check( LimuCRM\verify_turnstile( 'test-token', 'crm_login' ), 'Turnstile accepts verified hostname and action (mocked provider)' ); check( ! LimuCRM\verify_turnstile( 'test-token', 'other_action' ), 'Turnstile rejects token for a different action' ); remove_filter( 'pre_http_request', $mock, 10 ); putenv( 'LIMU_CRM_TURNSTILE_SECRET' );

 $invalid_history = LimuCRM\locked( fn() => LimuCRM\record_delivery( array( 'name' => 'historical invalid contact', 'phone' => 'abc', 'email' => 'invalid', 'date' => $month . '-02 09:00:00', 'form' => 'history' ), $a, 'test:historical-invalid', true ) );
 check( ! is_wp_error( $invalid_history ) && 'historical' === $invalid_history['state'] && ! $invalid_history['duplicate_of'], 'Invalid historical contact remains reportable and never becomes billable' );
 $invalid_native = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_title' => 'פנייה ללא פרטי קשר', 'post_date' => current_time( 'mysql' ) ) );
 update_post_meta( $invalid_native, 'institution', 'מוסד א' );
 $failed_capture = LimuCRM\native_flush()[ $invalid_native ];
 check( 'unmapped' === $failed_capture['state'] && 'contact' === $failed_capture['capture_error'] && 0 === $failed_capture['institution'], 'Invalid existing lead remains a private nonbillable exception' );
 wp_set_current_user( 0 ); check( 403 === request( 'bootstrap' )->get_status(), 'Anonymous API access rejected' );
 echo "\n$passed integration assertions passed.\n";
} catch ( Throwable $e ) { fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" ); exit( 1 ); }
