<?php
/** Mocked iCount integration on the named disposable database; never sends financial documents. */
$root = getenv( 'LIMU_WP_ROOT' );
if ( ! $root ) { fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable test WordPress installation.\n" ); exit( 1 ); }
require $root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) { fwrite( STDERR, "Refusing to run outside limu_test.\n" ); exit( 1 ); }
if ( defined( 'LIMU_CRM_ICOUNT_TOKEN' ) ) { fwrite( STDERR, "Refusing to run with a configured constant token.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_true' );
register_post_type( 'institutions', array( 'public' => false ) );
$admin = get_user_by( 'login', 'crm-admin' );
$original_user = get_current_user_id();
wp_set_current_user( $admin->ID );
$original_token = getenv( 'LIMU_CRM_ICOUNT_TOKEN' );
$original_options = array();
foreach ( array( 'lcrm_icount_config', 'lcrm_icount_namespace', 'lcrm_icount_automatic', 'lcrm_settings' ) as $name ) { $original_options[ $name ] = get_option( $name, '__icount_missing__' ); if ( 'lcrm_settings' !== $name ) { delete_option( $name ); } }
$types = array( 'institutions', 'lcrm_bill', 'lcrm_payment', 'lcrm_audit', 'lcrm_contact', 'lcrm_delivery' );
$original_posts = get_posts( array( 'post_type' => $types, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$passed = 0;
$provider = array();
$calls = array();
$failure = '';
$time_override = null;
$nest = null;
$next_docnum = 91000;
function icount_check( $value, $message ) { global $passed; if ( ! $value ) { throw new Exception( $message ); } ++$passed; echo "PASS $message\n"; }
function icount_rest( $action, $body = array(), $nonce = true ) {
 $request = new WP_REST_Request( 'POST', '/limu-crm/v1/icount/' . $action );
 $request->set_header( 'Content-Type', 'application/json' );
 if ( $nonce ) { $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
 $request->set_body( wp_json_encode( (object) $body ) );
 return rest_do_request( $request );
}
function icount_fixture_bill( $institution, $lines = null ) {
 $lines = null === $lines ? array( array( 'delivery' => 0, 'price' => 5000, 'vat_bp' => 1800, 'vat' => 900 ) ) : $lines;
 $subtotal = array_sum( array_column( $lines, 'price' ) ); $vat = array_sum( array_column( $lines, 'vat' ) );
 return LimuCRM\locked( function () use ( $institution, $lines, $subtotal, $vat ) {
  return LimuCRM\save_record( 'lcrm_bill', array( 'institution' => $institution, 'month' => '2026-09', 'state' => 'approved', 'lines' => $lines, 'subtotal' => $subtotal, 'vat' => $vat, 'total' => $subtotal + $vat, 'paid' => 0, 'issued' => '2026-09-01', 'due' => '2026-10-01', 'credit_days' => 30, 'document_state' => 'not_connected' ) );
 } );
}
function icount_fixture_payment( $bill, $amount, $method = 'cash' ) {
 return LimuCRM\locked( function () use ( $bill, $amount, $method ) {
  return LimuCRM\record_payment( $bill, array( 'amount' => $amount, 'date' => current_time( 'Y-m-d' ), 'method' => $method, 'request_key' => 'icount-test-' . wp_generate_uuid4() ) );
 } );
}
function icount_provider_response( $value ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $value ) ); }
$mock = function ( $pre, $args, $url ) use ( &$provider, &$calls, &$failure, &$nest, &$next_docnum, &$time_override ) {
 if ( 0 !== strpos( $url, 'https://api.icount.co.il/api/v3.php/' ) ) { throw new Exception( 'Unexpected external destination in accounting test' ); }
 if ( isset( $GLOBALS['lcrm_changed_records'] ) ) { throw new Exception( 'External API called during local transaction' ); }
 if ( true !== $args['sslverify'] || 0 !== $args['redirection'] || 1048576 !== $args['limit_response_size'] || 25 !== $args['timeout'] || 'Bearer icount-fixture-not-a-real-token' !== $args['headers']['Authorization'] ) { throw new Exception( 'Unsafe HTTP accounting configuration' ); }
 $method = substr( $url, strlen( 'https://api.icount.co.il/api/v3.php/' ) ); $body = json_decode( $args['body'], true );
 $calls[] = array( 'method' => $method, 'body' => $body );
 if ( 'malformed' === $failure ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{not-json' ); }
 if ( 'redirect' === $failure ) { return array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => 'https://example.test/' ) ); }
 if ( 'company/info' === $method ) { return icount_provider_response( array( 'status' => true, 'company_info' => array( 'vat_id' => '123456789', 'businessName' => 'חברת בדיקה', 'is_vat_exempt' => false ), 'bank_accounts' => array( '7' => array( 'account_id' => '7', 'title' => 'חשבון בדיקה' ) ) ) ); }
 if ( 'doc/types' === $method ) { return icount_provider_response( array( 'status' => true, 'doctypes' => array( 'deal' => array(), 'invoice' => array(), 'receipt' => array(), 'invrec' => array() ) ) ); }
 if ( 'client/info' === $method ) { return icount_provider_response( array( 'status' => true, 'client_info' => array( 'client_id' => $body['client_id'], 'client_name' => 'לקוח בדיקה ' . $body['client_id'], 'vat_id' => '987654321' ) ) ); }
 if ( 'doc/create' === $method ) {
  if ( true !== $body['send_email'] && false !== $body['send_email'] ) { throw new Exception( 'Malformed email permission' ); }
  if ( false !== $body['send_email'] || false !== $body['send_sms'] || 18 !== $body['vat_percent'] || 'ILS' !== $body['currency_code'] || strlen( $body['sanity_string'] ) > 30 ) { throw new Exception( 'Incorrect financial document safety fields' ); }
  if ( $nest ) { $callback = $nest; $nest = null; $callback(); }
  $sanity = $body['sanity_string'];
  $duplicate = isset( $provider[ $sanity ] );
  if ( $duplicate && $provider[ $sanity ]['payload'] !== $body ) { throw new Exception( 'A retry changed the frozen provider payload' ); }
  if ( ! $duplicate ) { $provider[ $sanity ] = array( 'docnum' => ++$next_docnum, 'payload' => $body ); }
  $doc = $provider[ $sanity ];
  if ( 'lost-response' === $failure ) { $failure = ''; return new WP_Error( 'timeout', 'Fixture lost response' ); }
  return icount_provider_response( array( 'status' => ! $duplicate, 'reason' => $duplicate ? 'doc_exists_based_on_sanity_string' : 'OK', 'doctype' => $body['doctype'], 'client_id' => $body['client_id'], 'docnum' => (string) $doc['docnum'], 'doc_url' => 'https://app.icount.co.il/document/' . $doc['docnum'], 'doc_copy_url' => 'https://app.icount.co.il/copy/' . $doc['docnum'] ) );
 }
 if ( 'doc/info' === $method ) {
  if ( 'readback-failure' === $failure ) { return new WP_Error( 'timeout', 'Fixture lost readback' ); }
  foreach ( $provider as $doc ) { if ( $body['doctype'] === $doc['payload']['doctype'] && (int) $body['docnum'] === $doc['docnum'] ) {
   $p = $doc['payload']; $info = $p;
   $info['docnum'] = $doc['docnum']; $info['dateissued'] = $p['doc_date']; $info['timeissued'] = null === $time_override ? time() : $time_override; $info['is_cancelled'] = false; $info['is_cancellation'] = false;
   if ( 'wrong-total' === $failure && isset( $info['totalwithvat'] ) ) { $info['totalwithvat'] = '0.01'; }
   if ( 'wrong-bank' === $failure && isset( $info['banktransfer'] ) ) { $info['banktransfer']['account'] = 99; }
   return icount_provider_response( array( 'status' => true, 'doctype' => $p['doctype'], 'docnum' => $doc['docnum'], 'doc_info' => $info ) );
  } }
  return icount_provider_response( array( 'status' => false, 'reason' => 'doc_not_found' ) );
 }
 throw new Exception( 'Unexpected accounting method' );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
 putenv( 'LIMU_CRM_ICOUNT_TOKEN' );
 icount_check( false === LimuCRM\icount_status()['configured'] && false === LimuCRM\icount_status()['verified'], 'Absent server token produces a safe unconfigured state' );
 icount_check( is_wp_error( LimuCRM\icount_request( 'company/info', array() ) ) && 0 === count( $calls ), 'Missing token refuses network requests' );
 putenv( 'LIMU_CRM_ICOUNT_TOKEN=unsafe token' );
 icount_check( '' === LimuCRM\icount_token(), 'Header whitespace in server token is rejected' );
 putenv( 'LIMU_CRM_ICOUNT_TOKEN=icount-fixture-not-a-real-token' );
 icount_check( 200 === icount_rest( 'check' )->get_status(), 'Manager explicitly verifies company, bank accounts and document types with mocked provider' );
 $status = LimuCRM\icount_status();
 icount_check( $status['verified'] && array( 'deal', 'invoice', 'receipt', 'invrec' ) === $status['doctypes'] && 7 === $status['bank_accounts'][0]['id'] && false === $status['automatic'], 'Cached status exposes supported fiscal options and defaults automation off' );
 icount_check( false === strpos( wp_json_encode( $status ), 'fixture-not-a-real-token' ) && false === strpos( wp_json_encode( $status ), LimuCRM\icount_account() ), 'Connection state never exposes secret or account fingerprint' );
 $before = count( $calls ); LimuCRM\icount_status();
 icount_check( $before === count( $calls ), 'Reading connection status does not contact provider' );
 icount_check( is_wp_error( LimuCRM\icount_request( 'https://example.test/', array() ) ) && $before === count( $calls ), 'Caller cannot choose a provider destination' );
 icount_check( is_wp_error( LimuCRM\locked( function () { return LimuCRM\icount_request( 'company/info', array() ); } ) ) && $before === count( $calls ), 'HTTP is rejected inside the CRM transaction' );
 $institution = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => 'מוסד בדיקת iCount' ) );
 icount_check( 200 === icount_rest( 'client', array( 'institution' => $institution, 'client_id' => 42 ) )->get_status(), 'Institution links an explicit verified fiscal client ID' );
 icount_check( array( 'client_id' => 42 ) === end( $calls )['body'], 'Fiscal client lookup sends only explicit ID, with no lead CID, name or contacts' );
 $bootstrap = new WP_REST_Request( 'GET', '/limu-crm/v1/bootstrap' );
 $boot = rest_do_request( $bootstrap )->get_data();
 icount_check( isset( $boot['icount'] ) && false === strpos( wp_json_encode( $boot ), 'fixture-not-a-real-token' ) && false === strpos( wp_json_encode( $boot ), LimuCRM\icount_account() ), 'Manager bootstrap exposes safe connection and institution mapping only' );
 $preview = icount_rest( 'client-preview', array( 'client_id' => 42 ) );
 icount_check( 200 === $preview->get_status() && 42 === $preview->get_data()['id'] && ! isset( $preview->get_data()['account'] ), 'Manager previews verified fiscal identity before confirming the institution mapping' );
 $bill = icount_fixture_bill( $institution );
 $before = count( $calls );
 $nest = function () use ( $bill ) { icount_check( is_wp_error( LimuCRM\icount_issue_bill( $bill['id'], 'deal' ) ), 'Concurrent second demand reservation is blocked before any HTTP' ); };
 $demand = icount_rest( 'issue', array( 'bill' => $bill['id'], 'doctype' => 'deal' ) );
 icount_check( 200 === $demand->get_status() && 'issued' === $demand->get_data()['state'], 'Approved monthly demand is created and read back before marking issued' );
 $snapshot = LimuCRM\data( $bill['id'] );
 icount_check( current_time( 'Y-m-d' ) === $snapshot['issued'] && LimuCRM\due_date( current_time( 'Y-m-d' ), 30 ) === $snapshot['due'] && $bill['lines'] === $snapshot['lines'] && $bill['total'] === $snapshot['total'], 'Demand updates actual issue date and credit terms without changing approved financial snapshot' );
 $create = $calls[ $before ]['body'];
 icount_check( '50.00' === $create['items'][0]['unitprice'] && 1 === $create['items'][0]['quantity'] && '59.00' === $create['totalwithvat'] && 18 === $create['vat_percent'], 'Provider payload preserves exact per-lead pricing and fixed eighteen percent VAT' );
 $before = count( $calls ); $again = icount_rest( 'issue', array( 'bill' => $bill['id'], 'doctype' => 'deal' ) );
 icount_check( $again->get_data()['docnum'] === $demand->get_data()['docnum'] && $before === count( $calls ), 'Repeated issued demand returns existing document without external request' );
 $invoice = icount_rest( 'issue', array( 'bill' => $bill['id'], 'doctype' => 'invoice' ) );
 icount_check( 200 === $invoice->get_status() && 'invoice' === $invoice->get_data()['doctype'], 'Manager issues one tax invoice after the demand' );
 $invoice_payload = $calls[ count( $calls ) - 2 ]['body'];
 icount_check( array( array( 'doctype' => 'deal', 'docnum' => $demand->get_data()['docnum'] ) ) === $invoice_payload['based_on'], 'Tax invoice preserves verified demand origin' );
 $partial = icount_fixture_payment( $bill['id'], '20.00', 'transfer' );
 $receipt = icount_rest( 'payment', array( 'payment' => $partial['id'], 'account' => 7 ) );
 icount_check( 200 === $receipt->get_status() && 'receipt' === $receipt->get_data()['doctype'] && 2000 === $receipt->get_data()['total'], 'Partial bank-transfer payment creates only a receipt after existing invoice' );
 $receipt_payload = $calls[ count( $calls ) - 2 ]['body'];
 icount_check( ! isset( $receipt_payload['items'] ) && '20.00' === $receipt_payload['banktransfer']['sum'] && 7 === $receipt_payload['banktransfer']['account'] && $invoice->get_data()['docnum'] === $receipt_payload['based_on'][0]['docnum'], 'Receipt carries actual payment, receiving bank ID and invoice link without taxing again' );
 $rest = icount_fixture_payment( $bill['id'], '39.00' );
 icount_check( 'receipt' === LimuCRM\icount_issue_payment( $rest['id'] )['doctype'], 'Final payment after partial receipt creates another receipt without another tax invoice' );
 $anchor_bill = icount_fixture_bill( $institution ); $anchor_demand = LimuCRM\icount_issue_bill( $anchor_bill['id'], 'deal' );
 $anchor_date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-10 days' )->format( 'Y-m-d' );
 $anchor_outbox = LimuCRM\icount_outbox( $anchor_bill['id'] ); $anchor_outbox['deal']['issued'] = $anchor_date; $anchor_outbox['deal']['paydate'] = LimuCRM\due_date( $anchor_date, 30 );
 LimuCRM\locked( function () use ( $anchor_bill, $anchor_outbox ) { return LimuCRM\icount_save_outbox( $anchor_bill['id'], $anchor_outbox ); } );
 $anchor_invoice = LimuCRM\icount_issue_bill( $anchor_bill['id'], 'invoice' );
 icount_check( ! is_wp_error( $anchor_invoice ) && LimuCRM\due_date( $anchor_date, 30 ) === $anchor_invoice['paydate'], 'Tax invoice issued later inherits the demand deadline instead of restarting credit days' );
 $full_bill = icount_fixture_bill( $institution ); $full = icount_fixture_payment( $full_bill['id'], '59.00' );
 $invrec = LimuCRM\icount_issue_payment( $full['id'] );
 icount_check( ! is_wp_error( $invrec ) && 'invrec' === $invrec['doctype'], 'A single full payment without prior invoice creates invoice-receipt' );
 $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $full_bill['id'], 'invoice' ) ) && $before === count( $calls ), 'Invoice-receipt prevents a second tax invoice for the bill' );
 $partial_bill = icount_fixture_bill( $institution ); $p = icount_fixture_payment( $partial_bill['id'], '10.00' );
 $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_issue_payment( $p['id'] ) ) && $before === count( $calls ), 'Partial payment without invoice requests explicit invoice first and creates no hidden document' );
 $unlinked = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => 'מוסד ללא קישור' ) );
 $unlinked_bill = icount_fixture_bill( $unlinked );
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $unlinked_bill['id'], 'deal' ) ) && $before === count( $calls ), 'Unlinked institution cannot issue a demand' );
 $draft = icount_fixture_bill( $institution ); $d = LimuCRM\data( $draft['id'] ); $d['state'] = 'draft'; LimuCRM\locked( function () use ( $d ) { return LimuCRM\save_record( 'lcrm_bill', $d, $d['id'] ); } );
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $draft['id'], 'deal' ) ) && $before === count( $calls ), 'Draft bill cannot create financial documents' );
 $rounding = icount_fixture_bill( $institution, array_fill( 0, 3, array( 'delivery' => 0, 'price' => 1, 'vat_bp' => 1800, 'vat' => 0 ) ) );
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $rounding['id'], 'deal' ) ) && $before === count( $calls ), 'Per-lead versus global VAT rounding ambiguity is rejected before HTTP' );
 $lost_bill = icount_fixture_bill( $institution ); $failure = 'lost-response';
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $lost_bill['id'], 'deal' ) ) && 'unknown' === LimuCRM\icount_documents( $lost_bill['id'] )[0]['state'], 'Document created with lost response persists an uncertain state' );
 $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $lost_bill['id'], 'deal' ) ) && $before === count( $calls ), 'Ordinary issue click never retries an uncertain operation' );
 $provider_before = count( $provider );
 $recovered = icount_rest( 'reconcile', array( 'target' => $lost_bill['id'], 'doctype' => 'deal' ) );
 icount_check( 200 === $recovered->get_status() && 'issued' === $recovered->get_data()['state'] && $provider_before === count( $provider ), 'Explicit same-day recovery uses official duplicate-sanity proof and creates no second document' );
 $readback_bill = icount_fixture_bill( $institution ); $failure = 'readback-failure';
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $readback_bill['id'], 'deal' ) ) && LimuCRM\icount_documents( $readback_bill['id'] )[0]['docnum'] > 0, 'Lost readback retains a proven provider document number in uncertain state' );
 $failure = ''; $before = count( $calls );
 icount_check( ! is_wp_error( LimuCRM\icount_reconcile( $readback_bill['id'], 'deal' ) ) && 1 === count( $calls ) - $before && 'doc/info' === end( $calls )['method'], 'Known candidate recovery performs read-only document verification without create replay' );
 $bad_total_bill = icount_fixture_bill( $institution ); $failure = 'wrong-total';
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $bad_total_bill['id'], 'invoice' ) ) && 'unknown' === LimuCRM\icount_documents( $bad_total_bill['id'] )[0]['state'], 'Provider amount mismatch remains uncertain and never changes approved totals' );
 $failure = ''; $bad_payment = icount_fixture_payment( $bad_total_bill['id'], '59.00' ); $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_issue_payment( $bad_payment['id'] ) ) && $before === count( $calls ), 'Uncertain tax invoice blocks invoice-receipt and duplicate taxation' );
 icount_check( is_wp_error( LimuCRM\icount_reconcile( $bad_total_bill['id'], 'invoice', 123456 ) ) && $before === count( $calls ), 'Reconciliation cannot attach an arbitrary same-amount document number' );
 $cross_day = icount_fixture_bill( $institution ); $failure = 'lost-response'; LimuCRM\icount_issue_bill( $cross_day['id'], 'deal' );
 $outbox = LimuCRM\icount_outbox( $cross_day['id'] ); $outbox['deal']['payload']['doc_date'] = '2026-01-01';
 LimuCRM\locked( function () use ( $cross_day, $outbox ) { return LimuCRM\icount_save_outbox( $cross_day['id'], $outbox ); } );
 $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_reconcile( $cross_day['id'], 'deal' ) ) && $before === count( $calls ), 'Uncertain demand from an earlier day without candidate cannot create a backdated demand' );
 $pending_bill = icount_fixture_bill( $institution ); $reserved = LimuCRM\icount_reserve( $pending_bill['id'], 'deal' ); $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_reconcile( $pending_bill['id'], 'deal' ) ) && $before === count( $calls ), 'Fresh sending intent cannot be concurrently reconciled' );
 $outbox = LimuCRM\icount_outbox( $pending_bill['id'] ); $outbox['deal']['started'] = time() - 121;
 LimuCRM\locked( function () use ( $pending_bill, $outbox ) { return LimuCRM\icount_save_outbox( $pending_bill['id'], $outbox ); } );
 icount_check( ! is_wp_error( LimuCRM\icount_reconcile( $pending_bill['id'], 'deal' ) ), 'Stale committed sending intent can be recovered explicitly with the same idempotency key' );
 $remap_bill = icount_fixture_bill( $institution ); LimuCRM\icount_issue_bill( $remap_bill['id'], 'invoice' );
 LimuCRM\icount_link_client( $institution, 43 ); $remap_payment = icount_fixture_payment( $remap_bill['id'], '59.00' ); $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_issue_payment( $remap_payment['id'] ) ) && $before === count( $calls ), 'Changed institution client cannot receive a receipt based on another client invoice' );
 LimuCRM\icount_link_client( $institution, 42 );
 $before = count( $calls ); putenv( 'LIMU_CRM_ICOUNT_TOKEN=another-account-token' );
 icount_check( false === LimuCRM\icount_status()['verified'] && null === LimuCRM\icount_client( $institution ) && is_wp_error( LimuCRM\icount_issue_bill( $bill['id'], 'invoice' ) ) && $before === count( $calls ), 'Changed account token invalidates mappings and prevents cross-account issuance' );
 putenv( 'LIMU_CRM_ICOUNT_TOKEN=icount-fixture-not-a-real-token' );
 icount_check( '' === LimuCRM\icount_document_url( 'https://app.icount.co.il.evil.test/x' ) && '' === LimuCRM\icount_document_url( 'http://app.icount.co.il/x' ) && '' === LimuCRM\icount_document_url( 'https://user@app.icount.co.il/x' ) && '' === LimuCRM\icount_document_url( 'https://app.icount.co.il:8443/x' ) && '' !== LimuCRM\icount_document_url( 'https://dev.icount.co.il/x' ), 'Document links reject impersonation domains, plain HTTP, userinfo and nonstandard ports' );
 icount_check( is_wp_error( LimuCRM\icount_payment_payload( $partial, array( 'account' => true ) ) ) && is_wp_error( LimuCRM\icount_payment_payload( $partial, array( 'account' => 99 ) ) ), 'Boolean and unknown transfer accounts are rejected' );
 $cheque = array( 'amount' => 5900, 'method' => 'check', 'date' => current_time( 'Y-m-d' ) );
 $cheque_body = LimuCRM\icount_payment_payload( $cheque, array( 'bank' => 10, 'branch' => 20, 'account' => 30, 'number' => 40, 'check_date' => current_time( 'Y-m-d' ) ) );
 icount_check( ! is_wp_error( $cheque_body ) && '59.00' === $cheque_body['cheques'][0]['sum'], 'Cheque payload includes verified official fields with exact payment amount' );
 icount_check( is_wp_error( LimuCRM\icount_payment_payload( $cheque, array( 'bank' => true, 'branch' => 20, 'account' => 30, 'number' => 40 ) ) ), 'Boolean cheque identifiers are rejected' );
 icount_check( is_wp_error( LimuCRM\icount_payment_payload( array( 'amount' => 5900, 'method' => 'card' ), array() ) ) && is_wp_error( LimuCRM\icount_payment_payload( array( 'amount' => 5900, 'method' => 'other' ), array() ) ), 'Unsupported card and generic methods do not collect payment-card secrets or guess supplier codes' );
 $bank_bill = icount_fixture_bill( $institution ); $bank_payment = icount_fixture_payment( $bank_bill['id'], '59.00', 'transfer' ); $failure = 'wrong-bank';
 icount_check( is_wp_error( LimuCRM\icount_issue_payment( $bank_payment['id'], array( 'account' => 7 ) ) ) && 'unknown' === LimuCRM\icount_documents( $bank_bill['id'] )[0]['state'], 'Different receiving bank in provider readback is rejected without pretending the document is verified' );
 $failure = '';
 $cheque_bill = icount_fixture_bill( $institution ); $cheque_payment = icount_fixture_payment( $cheque_bill['id'], '59.00', 'check' );
 $cheque_document = LimuCRM\icount_issue_payment( $cheque_payment['id'], array( 'bank' => 10, 'branch' => 20, 'account' => 30, 'number' => 40, 'check_date' => current_time( 'Y-m-d' ) ) );
 icount_check( ! is_wp_error( $cheque_document ) && 'invrec' === $cheque_document['doctype'], 'Full cheque payment issues and verifies all official cheque details' );
 $finalize_bill = icount_fixture_bill( $institution );
 $fault = function ( $pre, $id, $key, $value ) use ( $finalize_bill ) { return $id === $finalize_bill['id'] && '_lcrm_icount' === $key && 'issued' === ( $value['deal']['state'] ?? '' ) ? false : $pre; };
 add_filter( 'update_post_metadata', $fault, 10, 4 ); $finalize_error = LimuCRM\icount_issue_bill( $finalize_bill['id'], 'deal' ); remove_filter( 'update_post_metadata', $fault, 10 );
 icount_check( is_wp_error( $finalize_error ) && 'sending' === LimuCRM\icount_documents( $finalize_bill['id'] )[0]['state'], 'Failed local finalization preserves the committed sending intent and never reports success' );
 $finalize_outbox = LimuCRM\icount_outbox( $finalize_bill['id'] ); $finalize_outbox['deal']['started'] = time() - 121;
 LimuCRM\locked( function () use ( $finalize_bill, $finalize_outbox ) { return LimuCRM\icount_save_outbox( $finalize_bill['id'], $finalize_outbox ); } ); $provider_before = count( $provider );
 icount_check( ! is_wp_error( LimuCRM\icount_reconcile( $finalize_bill['id'], 'deal' ) ) && $provider_before === count( $provider ), 'Recovery after local write failure proves the existing fiscal document with the original sanity key' );
 $midnight_bill = icount_fixture_bill( $institution ); $time_override = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 day' )->getTimestamp();
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $midnight_bill['id'], 'deal' ) ), 'Actual issue date inconsistent with supplier due date remains uncertain instead of silently changing credit terms' );
 $time_override = null;
 $malformed_bill = icount_fixture_bill( $institution ); $failure = 'malformed';
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $malformed_bill['id'], 'deal' ) ), 'Malformed provider JSON produces an uncertain operation' );
 $failure = 'redirect'; $redirect_bill = icount_fixture_bill( $institution );
 icount_check( is_wp_error( LimuCRM\icount_issue_bill( $redirect_bill['id'], 'deal' ) ), 'HTTP redirect is never followed or treated as an issued document' );
 $failure = '';
 icount_check( 403 === icount_rest( 'check', array(), false )->get_status(), 'Accounting REST mutation requires a valid nonce' );
 icount_check( 400 === icount_rest( 'issue', array( 'bill' => $bill['id'], 'doctype' => 'deal', 'url' => 'https://example.test/' ) )->get_status(), 'Accounting REST rejects unknown provider-routing fields' );
 icount_check( 400 === icount_rest( 'client', array( 'institution' => true, 'client_id' => 42 ) )->get_status(), 'Accounting REST rejects boolean object identifiers' );
 $large = new WP_REST_Request( 'POST', '/limu-crm/v1/icount/check' ); $large->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $large->set_header( 'Content-Type', 'application/json' ); $large->set_body( '{"padding":"' . str_repeat( 'x', 4096 ) . '"}' );
 icount_check( 413 === rest_do_request( $large )->get_status(), 'Accounting REST enforces its narrow body limit' );
 icount_check( 200 === icount_rest( 'bill', array( 'bill' => $bill['id'] ) )->get_status(), 'Manager bill detail retrieves safe documents and recorded payments' );
 $audit_fault = function ( $pre, $id, $key ) { return 'lcrm_audit' === get_post_type( $id ) && '_lcrm_data' === $key ? false : $pre; };
 add_filter( 'update_post_metadata', $audit_fault, 10, 3 ); $failed_enable = icount_rest( 'automatic', array( 'enabled' => true ) ); remove_filter( 'update_post_metadata', $audit_fault, 10 );
 icount_check( 500 === $failed_enable->get_status() && false === get_option( 'lcrm_icount_automatic', false ), 'Failed automation audit rolls back durable opt-in and invalidates the option cache' );
 icount_check( 200 === icount_rest( 'automatic', array( 'enabled' => true ) )->get_status() && true === LimuCRM\icount_status()['automatic'], 'Demand automation requires an explicit boolean opt-in' );
 icount_check( 400 === icount_rest( 'automatic', array( 'enabled' => 'true' ) )->get_status(), 'Automation rejects truthy strings' );
 $approve_request = function ( $id ) { $r = new WP_REST_Request( 'POST', '/limu-crm/v1/approve' ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $r->set_body( wp_json_encode( array( 'ids' => array( $id ) ) ) ); return rest_do_request( $r ); };
 icount_check( 200 === $approve_request( $full_bill['id'] )->get_status() && false === wp_next_scheduled( 'lcrm_icount_demand', array( $full_bill['id'] ) ), 'Opting into automation does not queue an already-approved bill when approval is repeated' );
 $service_month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
 $temporary_settings = LimuCRM\settings(); $temporary_settings['start_date'] = $service_month . '-01'; update_option( 'lcrm_settings', $temporary_settings, false );
 $approval_institution = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => 'מוסד אישור חדש' ) ); LimuCRM\icount_link_client( $approval_institution, 42 );
 update_post_meta( $approval_institution, '_lcrm_agreement', array( 'rates' => array( array( 'from' => $service_month . '-01', 'price' => 5000, 'vat_bp' => 1800 ) ), 'credit_days' => 30 ) );
 $live_delivery = LimuCRM\locked( function () use ( $approval_institution, $service_month ) { return LimuCRM\record_delivery( array( 'name' => 'בדיקת אישור אוטומציה', 'phone' => '0509871234', 'email' => '', 'date' => $service_month . '-05 10:00:00', 'form' => 'test' ), $approval_institution, 'icount-approval-' . wp_generate_uuid4() ); } );
 $live_draft = LimuCRM\locked( function () use ( $approval_institution, $service_month ) { return LimuCRM\prepare_bill( $approval_institution, $service_month ); } );
 icount_check( ! is_wp_error( $live_delivery ) && ! is_wp_error( $live_draft ) && 200 === $approve_request( $live_draft['id'] )->get_status() && false !== wp_next_scheduled( 'lcrm_icount_demand', array( $live_draft['id'] ) ), 'Real approval of a newly prepared bill queues its demand only after the approval transaction commits' );
 $first_event = wp_next_scheduled( 'lcrm_icount_demand', array( $live_draft['id'] ) );
 icount_check( 200 === $approve_request( $live_draft['id'] )->get_status() && $first_event === wp_next_scheduled( 'lcrm_icount_demand', array( $live_draft['id'] ) ), 'Repeated real approval preserves one queued demand event' );
 $auto = icount_fixture_bill( $institution ); LimuCRM\icount_queue_demands( array( $auto['id'], $auto['id'] ) );
 icount_check( false !== wp_next_scheduled( 'lcrm_icount_demand', array( $auto['id'] ) ), 'Explicit automation queues only the provided newly approved bill' );
 $cron = _get_cron_array(); $matches = 0; foreach ( $cron as $events ) { foreach ( $events['lcrm_icount_demand'] ?? array() as $event ) { if ( array( $auto['id'] ) === $event['args'] ) { ++$matches; } } }
 icount_check( 1 === $matches, 'Duplicate approval queue input schedules one demand event' );
 $uid = wp_create_user( 'icount-scope-' . wp_generate_password( 8, false ), wp_generate_password( 32 ), 'icount-scope-' . wp_generate_password( 8, false ) . '@example.test' );
 $user = get_user_by( 'id', $uid ); $user->add_cap( 'lcrm_view' ); update_user_meta( $uid, '_lcrm_institutions', array( $institution ) ); wp_set_current_user( $uid );
 icount_check( 403 === icount_rest( 'check' )->get_status() && 403 === icount_rest( 'issue', array( 'bill' => $bill['id'], 'doctype' => 'deal' ) )->get_status(), 'Institution user cannot connect or issue accounting documents' );
 icount_check( 4 === count( LimuCRM\icount_documents( $bill['id'] ) ) && array() === LimuCRM\icount_documents( $unlinked_bill['id'] ) && array() === LimuCRM\icount_documents( $bad_total_bill['id'] ), 'Institution sees only its issued documents and never another institution or uncertain operations' );
 icount_check( is_wp_error( LimuCRM\icount_issue_payment( $partial['id'] ) ), 'Direct accounting wrapper also enforces manager-only mutation' );
 wp_set_current_user( $admin->ID );
 add_filter( 'wp_doing_cron', '__return_true' );
 wp_set_current_user( 0 );
 icount_check( ! is_wp_error( LimuCRM\icount_issue_automatic_demand( $auto['id'] ) ), 'Trusted opted-in cron worker can issue a verified demand without impersonating an administrator' );
 update_option( 'lcrm_icount_automatic', false, false ); $before = count( $calls );
 icount_check( is_wp_error( LimuCRM\icount_issue_automatic_demand( $auto['id'] ) ) && $before === count( $calls ), 'Disabling automation prevents pending cron work from sending' );
 wp_set_current_user( $admin->ID );
 require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $uid );
 echo "\n$passed iCount assertions passed; all provider requests mocked.\n";
} catch ( Throwable $e ) { fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" ); $exit_code = 1; }
finally {
 remove_filter( 'pre_http_request', $mock, 10 );
 remove_filter( 'wp_doing_cron', '__return_true' );
 if ( isset( $uid ) && get_user_by( 'id', $uid ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $uid ); }
 wp_set_current_user( $admin->ID );
 $after = get_posts( array( 'post_type' => $types, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
 foreach ( array_diff( $after, $original_posts ) as $id ) { wp_clear_scheduled_hook( 'lcrm_icount_demand', array( $id ) ); wp_delete_post( $id, true ); }
 foreach ( $original_options as $name => $value ) { if ( '__icount_missing__' === $value ) { delete_option( $name ); } else { update_option( $name, $value, false ); } }
 if ( false === $original_token ) { putenv( 'LIMU_CRM_ICOUNT_TOKEN' ); } else { putenv( 'LIMU_CRM_ICOUNT_TOKEN=' . $original_token ); }
 wp_set_current_user( $original_user );
}
exit( $exit_code ?? 0 );
