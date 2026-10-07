<?php
/** Date range reporting checks against isolated fixtures in the disposable database only. PHP 7.4 syntax. */
$range_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $range_root ) {
    fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
    exit( 1 );
}
require $range_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
    fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
    exit( 1 );
}
add_filter( 'pre_wp_mail', '__return_true' );
register_post_type( 'institutions', array( 'public' => false ) );
register_post_type( 'leads', array( 'public' => false ) );
$range_passed = 0;
$range_posts = array();
$range_user_id = 0;
$range_original_settings = get_option( 'lcrm_settings', null );
$range_failure = null;
$range_tracker = function ( $id, $post, $update ) {
    global $range_posts;
    if ( ! $update ) {
        $range_posts[] = $id;
    }
};
add_action( 'wp_insert_post', $range_tracker, 10, 3 );

function range_check( $condition, $message ) {
    global $range_passed;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    ++$range_passed;
    echo "PASS $message\n";
}

function range_request( $route, $query = array() ) {
    $request = new WP_REST_Request( 'GET', '/limu-crm/v1/' . $route );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    foreach ( $query as $key => $value ) {
        $request->set_param( $key, $value );
    }
    return rest_do_request( $request );
}

function range_delivery( $institution, $date, $name, $phone, $tag, $email = '' ) {
    $record = LimuCRM\locked( function () use ( $institution, $date, $name, $phone, $tag, $email ) {
        return LimuCRM\record_delivery( array( 'name' => $name, 'phone' => $phone, 'email' => $email, 'date' => $date, 'form' => 'Range fixture' ), $institution, $tag . ':' . $name, true );
    } );
    if ( is_wp_error( $record ) ) {
        throw new RuntimeException( 'Unable to create isolated historical date fixture.' );
    }
    return $record;
}

function range_count_record( $institution, $date, $source, $state = 'historical', $contact = 0 ) {
    $record = LimuCRM\save_record( 'lcrm_delivery', array( 'institution' => $institution, 'date' => $date, 'month' => substr( $date, 0, 7 ), 'source' => $source, 'state' => $state, 'contact' => $contact, 'duplicate_of' => 0, 'bill' => 0 ) );
    if ( is_wp_error( $record ) ) {
        throw new RuntimeException( 'Unable to create isolated count fixture.' );
    }
    return $record;
}

function range_sheet( $response ) {
    $payload = $response->get_data();
    if ( 200 !== $response->get_status() || empty( $payload['file'] ) ) {
        throw new RuntimeException( 'Unable to export date range fixture.' );
    }
    $path = tempnam( sys_get_temp_dir(), 'lcrm-range-' );
    try {
        file_put_contents( $path, base64_decode( $payload['file'], true ) );
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            throw new RuntimeException( 'Unable to open generated XLSX fixture.' );
        }
        $sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        $zip->close();
        return $sheet;
    } finally {
        unlink( $path );
    }
}

try {
    $range_admin = get_user_by( 'login', 'crm-admin' );
    if ( ! $range_admin || ! user_can( $range_admin, 'manage_options' ) ) {
        throw new RuntimeException( 'Run the normal disposable integration setup first.' );
    }
    wp_set_current_user( $range_admin->ID );
    $range_tag = 'Date range ' . wp_generate_uuid4();
    $range_a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $range_tag . ' A' ), true );
    $range_b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $range_tag . ' B' ), true );
    if ( is_wp_error( $range_a ) || is_wp_error( $range_b ) ) {
        throw new RuntimeException( 'Unable to create isolated institutions.' );
    }
    $range_before = range_delivery( $range_a, '2024-02-28 23:59:59', 'Range before', '0599010001', $range_tag );
    $range_start = range_delivery( $range_a, '2024-02-29 00:00:00', 'Range at start', '0599010002', $range_tag );
    $range_leap = range_delivery( $range_a, '2024-02-29 23:59:59', 'Range leap final second', '0599010003', $range_tag );
    $range_middle = range_delivery( $range_a, '2024-03-01 00:00:00', 'Range middle', '0599010004', $range_tag );
    $range_end = range_delivery( $range_a, '2024-03-31 23:59:59', 'Range at end', '0599010005', $range_tag );
    $range_after = range_delivery( $range_a, '2024-04-01 00:00:00', 'Range after', '0599010006', $range_tag );
    $range_other = range_delivery( $range_b, '2024-02-29 12:00:00', 'Range other institution', '0599010007', $range_tag );
    $range_filter = array( 'institution' => $range_a, 'date_from' => '2024-02-29', 'date_to' => '2024-03-31' );
    $range_list = range_request( 'deliveries', $range_filter );
    $range_list_data = $range_list->get_data();
    $range_expected_ids = array( $range_end['id'], $range_middle['id'], $range_leap['id'], $range_start['id'] );
    range_check( 200 === $range_list->get_status() && 4 === $range_list_data['total'] && $range_expected_ids === array_column( $range_list_data['items'], 'id' ), 'Lead ranges include midnight at the start and the final second at the end, excluding neighboring dates and other institutions' );
    $range_leap_query = array( 'institution' => $range_a, 'date_from' => '2024-02-29', 'date_to' => '2024-02-29' );
    range_check( 2 === range_request( 'deliveries', $range_leap_query )->get_data()['total'], 'A single valid leap day includes its entire local day' );

    $range_bills = array();
    foreach ( array( '2024-01' => 5000, '2024-02' => 10000, '2024-03' => 20000, '2024-04' => 30000 ) as $range_month => $range_subtotal ) {
        $range_bill = LimuCRM\save_record( 'lcrm_bill', array( 'institution' => $range_a, 'month' => $range_month, 'state' => 'approved', 'subtotal' => $range_subtotal, 'vat' => (int) ( $range_subtotal * 18 / 100 ), 'total' => (int) ( $range_subtotal * 118 / 100 ), 'paid' => (int) ( $range_subtotal / 10 ), 'due' => $range_month . '-28', 'lines' => array() ) );
        if ( is_wp_error( $range_bill ) ) {
            throw new RuntimeException( 'Unable to create isolated monthly billing fixture.' );
        }
        $range_bills[ $range_month ] = $range_bill;
    }
    $range_bill_list = range_request( 'bills', $range_filter )->get_data();
    range_check( 2 === $range_bill_list['total'] && array( '2024-03', '2024-02' ) === array_column( $range_bill_list['items'], 'month' ), 'A custom range includes whole monthly bills for every service month touching its dates' );
    $range_partial_months = range_request( 'bills', array( 'institution' => $range_a, 'date_from' => '2024-02-29', 'date_to' => '2024-03-01' ) )->get_data();
    range_check( $range_bill_list === $range_partial_months, 'Choosing portions of two months retains the same whole monthly bills and amounts' );
    $range_summary = range_request( 'summary', $range_filter )->get_data();
    range_check( 4 === $range_summary['leads'] && 4 === $range_summary['historical'] && 35400 === $range_summary['approved'] && 3000 === $range_summary['paid'] && array( $range_a => 4 ) === $range_summary['by_institution'], 'Dashboard range totals share the lead boundaries and monthly billing scope' );
    range_check( 4 === $range_summary['received_leads'] && 4 === $range_summary['institution_leads'] && 4 === $range_summary['institution_historical'], 'Both lead counters apply the inclusive selected date boundaries and institution filter' );

    foreach ( array( '2024-02-28', '2024-02-29', '2024-03-31', '2024-04-01' ) as $range_payment_date ) {
        $range_payment = LimuCRM\save_record( 'lcrm_payment', array( 'institution' => $range_a, 'bill' => $range_bills['2024-02']['id'], 'date' => $range_payment_date, 'amount' => 100, 'request_key' => $range_tag . ':payment:' . $range_payment_date ) );
        if ( is_wp_error( $range_payment ) ) {
            throw new RuntimeException( 'Unable to create isolated payment fixture.' );
        }
    }
    $range_payments = range_request( 'payments', $range_filter )->get_data();
    range_check( 2 === $range_payments['total'] && array( '2024-03-31', '2024-02-29' ) === array_column( $range_payments['items'], 'date' ), 'Payments stored as dates include both boundary days without requiring a timestamp' );

    foreach ( array( '2024-01-01', '2024-12-31', '2025-01-01' ) as $range_real_date ) {
        $range_real_input = array( 'amount' => '1.00', 'date' => $range_real_date, 'method' => 'transfer', 'reference' => 'Isolated date range fixture', 'request_key' => wp_generate_uuid4() );
        $range_real_payment = LimuCRM\locked( function () use ( $range_bills, $range_real_input ) { return LimuCRM\record_payment( $range_bills['2024-01']['id'], $range_real_input ); } );
        range_check( ! is_wp_error( $range_real_payment ) && ! array_key_exists( 'month', $range_real_payment ) && '' === get_post_meta( $range_real_payment['id'], '_lcrm_month', true ), 'Production payment recording stores the actual payment date without a synthetic month index: ' . $range_real_date );
    }
    // Snapshot after intentional payment recording; every subsequent reporting read must leave it intact.
    $range_bills['2024-01'] = LimuCRM\data( $range_bills['2024-01']['id'] );
    $range_payment_february = range_request( 'payments', array( 'institution' => $range_a, 'month' => '2024-02' ) )->get_data();
    range_check( 2 === $range_payment_february['total'] && array( '2024-02-29', '2024-02-28' ) === array_column( $range_payment_february['items'], 'date' ), 'Calendar-month payment filters use actual dates and include the last day of a leap-year February without month metadata' );
    $range_payment_year = range_request( 'payments', array( 'institution' => $range_a, 'year' => '2024' ) )->get_data();
    $range_payment_year_dates = array_column( $range_payment_year['items'], 'date' );
    sort( $range_payment_year_dates );
    range_check( 6 === $range_payment_year['total'] && array( '2024-01-01', '2024-02-28', '2024-02-29', '2024-03-31', '2024-04-01', '2024-12-31' ) === $range_payment_year_dates, 'Calendar-year payment filters include January first through December last and exclude the following year' );
    $range_payment_december = range_request( 'payments', array( 'institution' => $range_a, 'month' => '2024-12' ) )->get_data();
    $range_payment_next_year = range_request( 'payments', array( 'institution' => $range_a, 'year' => '2025' ) )->get_data();
    range_check( 1 === $range_payment_december['total'] && '2024-12-31' === $range_payment_december['items'][0]['date'] && 1 === $range_payment_next_year['total'] && '2025-01-01' === $range_payment_next_year['items'][0]['date'], 'Payments are selected by their actual calendar period even when their bill service month belongs to January of a different year' );

    $range_print = range_request( 'report', $range_filter + array( 'target' => 'deliveries' ) )->get_data();
    range_check( 4 === $range_print['total'] && $range_expected_ids === array_column( $range_print['items'], 'id' ), 'The PDF print snapshot uses the same filtered lead rows' );
    $range_bill_print = range_request( 'report', $range_filter + array( 'target' => 'bills' ) )->get_data();
    range_check( $range_bill_list === $range_bill_print, 'The PDF billing snapshot preserves whole monthly amounts within the selected date range' );
    $range_xlsx = range_sheet( range_request( 'export', $range_filter + array( 'target' => 'deliveries' ) ) );
    range_check( 5 === substr_count( $range_xlsx, '<row ' ) && false !== strpos( $range_xlsx, 'Range at start' ) && false !== strpos( $range_xlsx, 'Range at end' ) && false === strpos( $range_xlsx, 'Range before' ) && false === strpos( $range_xlsx, 'Range after' ) && false === strpos( $range_xlsx, 'Range other institution' ), 'Excel exports contain precisely the selected lead range and institution' );
    $range_bill_xlsx = range_sheet( range_request( 'export', $range_filter + array( 'target' => 'bills' ) ) );
    range_check( 3 === substr_count( $range_bill_xlsx, '<row ' ) && false !== strpos( $range_bill_xlsx, '2024-02' ) && false !== strpos( $range_bill_xlsx, '2024-03' ) && false === strpos( $range_bill_xlsx, '2024-01' ) && false === strpos( $range_bill_xlsx, '2024-04' ), 'Excel billing exports use the same service months as the list and PDF' );

    $range_invalid_queries = array(
        array( 'date_from' => '2024-03-02', 'date_to' => '2024-03-01' ),
        array( 'date_from' => '2024-02-29' ),
        array( 'date_to' => '2024-03-31' ),
        array( 'date_from' => '2023-02-29', 'date_to' => '2024-03-31' ),
        array( 'date_from' => '2024-02-30', 'date_to' => '2024-03-31' ),
        array( 'date_from' => '2024-02-29 00:00:00', 'date_to' => '2024-03-31' ),
        array( 'date_from' => "2024-02-2\0", 'date_to' => '2024-03-31' ),
        array( 'date_from' => array( '2024-02-29' ), 'date_to' => '2024-03-31' ),
        array( 'date_from' => '2024-02-29', 'date_to' => false ),
        array( 'date_from' => '2024-02-29', 'date_to' => '2024-03-31', 'year' => '2024' ),
        array( 'date_from' => '2024-02-29', 'date_to' => '2024-03-31', 'month' => '2024-03' ),
    );
    foreach ( array( 'summary', 'deliveries', 'bills', 'payments', 'report', 'export' ) as $range_route ) {
        foreach ( $range_invalid_queries as $range_invalid ) {
            range_check( 400 === range_request( $range_route, $range_invalid + array( 'institution' => $range_a, 'target' => 'deliveries' ) )->get_status(), 'Invalid or ambiguous date ranges are rejected by ' . $range_route . ': ' . wp_json_encode( $range_invalid ) );
        }
    }

    $range_user_id = wp_create_user( 'range-' . wp_generate_uuid4(), wp_generate_password( 40 ), 'range-' . wp_generate_uuid4() . '@example.test' );
    if ( is_wp_error( $range_user_id ) ) {
        $range_user_id = 0;
        throw new RuntimeException( 'Unable to create isolated institution account.' );
    }
    $range_user = get_user_by( 'id', $range_user_id );
    $range_user->set_role( 'limu_institution' );
    update_user_meta( $range_user_id, '_lcrm_institutions', array( $range_a ) );
    wp_set_current_user( $range_user_id );
    $range_member_filter = array( 'date_from' => '2024-02-29', 'date_to' => '2024-03-31' );
    $range_member_list = range_request( 'deliveries', $range_member_filter )->get_data();
    range_check( 4 === $range_member_list['total'] && $range_expected_ids === array_column( $range_member_list['items'], 'id' ), 'Institution range scope is applied before pagination and totals even without an explicit institution filter' );
    range_check( 2 === range_request( 'payments', array( 'month' => '2024-02' ) )->get_data()['total'] && 6 === range_request( 'payments', array( 'year' => '2024' ) )->get_data()['total'], 'Payment calendar month and year filters preserve institution record isolation' );
    range_check( 4 === range_request( 'summary', $range_member_filter )->get_data()['leads'] && 35400 === range_request( 'summary', $range_member_filter )->get_data()['approved'], 'Institution dashboard ranges disclose only assigned leads and billing totals' );
    foreach ( array( 'summary', 'deliveries', 'bills', 'payments', 'report', 'export' ) as $range_route ) {
        range_check( 403 === range_request( $range_route, $range_member_filter + array( 'institution' => $range_b, 'target' => 'deliveries' ) )->get_status(), 'Institution range cannot request another institution through ' . $range_route );
    }
    $range_member_export = range_sheet( range_request( 'export', $range_member_filter + array( 'target' => 'deliveries' ) ) );
    range_check( 5 === substr_count( $range_member_export, '<row ' ) && false === strpos( $range_member_export, 'Range other institution' ), 'Institution Excel range exports retain record isolation' );
    update_user_meta( $range_user_id, '_lcrm_institutions', array() );
    range_check( 0 === range_request( 'summary', $range_member_filter )->get_data()['leads'] && 0 === range_request( 'deliveries', $range_member_filter )->get_data()['total'], 'Unassigned institution accounts see no range totals or records' );
    wp_set_current_user( $range_admin->ID );
    foreach ( $range_bills as $range_bill ) {
        range_check( $range_bill === LimuCRM\data( $range_bill['id'] ), 'Reading date ranges leaves approved monthly billing snapshots unchanged: ' . $range_bill['month'] );
    }

    // A submission is one source event, even when sent to several institutions or repeated by the same person.
    $count_shared = range_delivery( $range_a, '2060-06-01 10:00:00', 'Count shared submission', '0599010100', $range_tag, 'count-fixture@example.test' );
    $count_shared_b = range_delivery( $range_b, '2060-06-01 10:00:00', 'Count shared submission', '0599010100', $range_tag, 'count-fixture@example.test' );
    $count_repeated = range_delivery( $range_a, '2060-06-02 10:00:00', 'Count separate submission', '0599010100', $range_tag, 'count-fixture@example.test' );
    $count_filter = array( 'year' => '2060' );
    $count_summary = range_request( 'summary', $count_filter )->get_data();
    range_check( 2 === $count_summary['received_leads'] && 3 === $count_summary['institution_leads'] && 3 === $count_summary['leads'], 'A general submission counts once for the site and once per recipient institution' );
    range_check( $count_shared['contact'] === $count_shared_b['contact'] && $count_repeated['duplicate_of'] === $count_shared['id'] && 1 === $count_summary['duplicates'] && 2 === $count_summary['received_leads'], 'Separate submissions with the same phone and email remain two site leads while billing duplication stays unchanged' );
    $count_replayed = range_delivery( $range_a, '2060-06-01 10:00:00', 'Count shared submission', '0599010100', $range_tag, 'count-fixture@example.test' );
    range_check( $count_replayed['id'] === $count_shared['id'] && $count_summary === range_request( 'summary', $count_filter )->get_data(), 'Replaying a delivery source changes neither dashboard count' );

    range_count_record( 0, '2060-06-03 10:00:00', $range_tag . ':count-unmapped', 'unmapped' );
    range_count_record( $range_a, '2060-06-04 10:00:00', $range_tag . ':count-pending', 'pending' );
    range_count_record( $range_a, '2060-06-05 10:00:00', $range_tag . ':count-sent', 'sent' );
    $count_summary = range_request( 'summary', $count_filter )->get_data();
    range_check( 5 === $count_summary['received_leads'] && 4 === $count_summary['institution_leads'] && 3 === $count_summary['institution_historical'] && 1 === $count_summary['institution_live'] && 6 === $count_summary['leads'] && 1 === $count_summary['unmapped'] && 1 === $count_summary['pending'], 'Unmapped and incomplete leads count for the site only; mapped live and historical deliveries count for recipients' );
    range_check( array( $range_a => 3, $range_b => 1 ) === $count_summary['by_institution'] && 4 === array_sum( $count_summary['by_institution'] ) && ! isset( $count_summary['by_institution'][0] ), 'Institution chart totals match recipient counts and exclude unmapped and incomplete records' );
    $count_day = range_request( 'summary', array( 'date_from' => '2060-06-01', 'date_to' => '2060-06-01' ) )->get_data();
    range_check( 1 === $count_day['received_leads'] && 2 === $count_day['institution_leads'], 'A one-day range preserves source deduplication across recipients' );
    $count_institution = range_request( 'summary', array( 'institution' => $range_a, 'month' => '2060-06' ) )->get_data();
    range_check( 4 === $count_institution['received_leads'] && 3 === $count_institution['institution_leads'] && 2 === $count_institution['institution_historical'] && 1 === $count_institution['institution_live'], 'Institution and month filters scope both counters before source deduplication' );
    $count_empty = range_request( 'summary', array( 'year' => '2062' ) )->get_data();
    range_check( 0 === $count_empty['received_leads'] && 0 === $count_empty['institution_leads'] && 0 === $count_empty['institution_historical'] && 0 === $count_empty['institution_live'] && array() === $count_empty['by_institution'], 'An empty calendar year clears both counters and the institution chart' );
    range_check( ! isset( $count_summary['source'], $count_summary['contact'] ) && ! in_array( $count_shared['source'], $count_summary, true ), 'Summary responses contain numeric counts without original source or contact identities' );

    // The shared source spans both 500-row summary query pages.
    range_count_record( $range_a, '2061-01-01 10:00:00', $range_tag . ':page-shared' );
    for ( $count_index = 0; $count_index < 499; ++$count_index ) {
        range_count_record( $range_a, '2061-01-01 10:00:00', $range_tag . ':page-' . $count_index );
    }
    range_count_record( $range_b, '2061-01-01 10:00:00', $range_tag . ':page-shared' );
    $count_pages = range_request( 'summary', array( 'year' => '2061' ) )->get_data();
    range_check( 500 === $count_pages['received_leads'] && 501 === $count_pages['institution_leads'] && 501 === $count_pages['institution_historical'] && 501 === $count_pages['leads'], 'Source identity is deduplicated across summary query pages without dropping recipient deliveries' );

    range_count_record( $range_a, '2063-01-01 10:00:00', '', 'historical', $count_shared['contact'] );
    range_count_record( $range_b, '2063-01-01 10:00:00', '', 'historical', $count_shared['contact'] );
    range_count_record( 0, '2063-01-01 10:00:00', '', 'unmapped' );
    $count_legacy = range_request( 'summary', array( 'year' => '2063' ) )->get_data();
    range_check( 2 === $count_legacy['received_leads'] && 2 === $count_legacy['institution_leads'] && 3 === $count_legacy['leads'], 'Older records use a positive shared contact ID when the source is absent and keep an unidentified row separate' );

    $count_import_id = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_title' => 'Isolated imported count fixture', 'post_date' => '1990-01-01 10:00:00' ), true );
    $count_draft_id = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'draft', 'post_title' => 'Isolated unsubmitted draft fixture', 'post_date' => '1990-01-01 10:00:00' ), true );
    if ( is_wp_error( $count_import_id ) || is_wp_error( $count_draft_id ) ) {
        throw new RuntimeException( 'Unable to create isolated import fixtures.' );
    }
    unset( $GLOBALS['lcrm_native_queue'][ $count_import_id ], $GLOBALS['lcrm_native_new_leads'][ $count_import_id ], $GLOBALS['lcrm_native_queue'][ $count_draft_id ], $GLOBALS['lcrm_native_new_leads'][ $count_draft_id ] );
    foreach ( array( 'institution' => get_post_field( 'post_title', $range_a, 'raw' ) . ', ' . get_post_field( 'post_title', $range_b, 'raw' ), 'full-name' => 'Isolated imported count fixture', 'phone' => '0599010101', 'email' => 'import-count@example.test' ) as $count_meta => $count_value ) {
        update_post_meta( $count_import_id, $count_meta, $count_value );
        update_post_meta( $count_draft_id, $count_meta, $count_value );
    }
    $count_import = LimuCRM\locked( function () use ( $count_import_id ) { return LimuCRM\import_history( $count_import_id - 1 ); } );
    $count_import_summary = range_request( 'summary', array( 'year' => '1990' ) )->get_data();
    range_check( ! is_wp_error( $count_import ) && 1 === $count_import['processed'] && 1 === $count_import_summary['received_leads'] && 2 === $count_import_summary['institution_leads'], 'Historical import counts a published general lead once for the site, twice for recipients and excludes a draft' );
    $count_import_again = LimuCRM\locked( function () use ( $count_import_id ) { return LimuCRM\import_history( $count_import_id - 1 ); } );
    range_check( ! is_wp_error( $count_import_again ) && $count_import_summary === range_request( 'summary', array( 'year' => '1990' ) )->get_data(), 'Repeating historical import preserves both dashboard counts' );

    update_user_meta( $range_user_id, '_lcrm_institutions', array( $range_a ) );
    wp_set_current_user( $range_user_id );
    $count_member = range_request( 'summary', $count_filter )->get_data();
    range_check( 3 === $count_member['received_leads'] && 3 === $count_member['institution_leads'] && 2 === $count_member['institution_historical'] && 1 === $count_member['institution_live'] && array( $range_a => 3 ) === $count_member['by_institution'], 'Institution users count only their own completed deliveries and cannot infer unmapped or pending site submissions' );
    $count_member_day = range_request( 'summary', array( 'date_from' => '2060-06-01', 'date_to' => '2060-06-01' ) )->get_data();
    range_check( 1 === $count_member_day['received_leads'] && 1 === $count_member_day['institution_leads'] && 403 === range_request( 'summary', $count_filter + array( 'institution' => $range_b ) )->get_status(), 'Institution date ranges and explicit institution access preserve isolation for both counters' );
    update_user_meta( $range_user_id, '_lcrm_institutions', array( $range_a, $range_b ) );
    $count_multi_member = range_request( 'summary', $count_filter )->get_data();
    range_check( 3 === $count_multi_member['received_leads'] && 4 === $count_multi_member['institution_leads'] && 3 === $count_multi_member['institution_historical'], 'A user assigned two institutions sees one source submission and both authorized recipient deliveries' );
    update_user_meta( $range_user_id, '_lcrm_institutions', array() );
    $count_unassigned = range_request( 'summary', $count_filter )->get_data();
    range_check( 0 === $count_unassigned['received_leads'] && 0 === $count_unassigned['institution_leads'] && 0 === $count_unassigned['institution_historical'] && 0 === $count_unassigned['institution_live'], 'An unassigned user receives zero for every new lead count' );
    wp_set_current_user( $range_admin->ID );
    foreach ( $range_bills as $range_bill ) {
        range_check( $range_bill === LimuCRM\data( $range_bill['id'] ), 'Split lead count reporting leaves approved billing snapshots unchanged: ' . $range_bill['month'] );
    }
    range_check( $range_original_settings === get_option( 'lcrm_settings', null ), 'Date range reports and isolated fixtures do not modify global CRM settings' );
    echo "\n$range_passed date range regression assertions passed.\n";
} catch ( Throwable $exception ) {
    $range_failure = $exception;
    fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" );
} finally {
    remove_action( 'wp_insert_post', $range_tracker, 10 );
    wp_set_current_user( isset( $range_admin ) && $range_admin ? $range_admin->ID : 0 );
    foreach ( array_reverse( array_unique( $range_posts ) ) as $range_post_id ) {
        wp_delete_post( $range_post_id, true );
    }
    if ( $range_user_id ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $range_user_id );
    }
}
if ( $range_failure ) {
    exit( 1 );
}
