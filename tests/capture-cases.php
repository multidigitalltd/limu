<?php
/** Capture cases: visitor payloads, manual decisions, permissions and financial immutability. */
$case_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $case_root ) {
    fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
    exit( 1 );
}
require $case_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
    fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
    exit( 1 );
}
register_post_type( 'institutions', array( 'public' => false ) );
register_post_type( 'leads', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$case_passed = 0;
$case_failure = null;
$case_posts = array();
$case_member = 0;
$case_admin = null;
$case_sequence = 0;
$case_http_calls = 0;
$case_old_user = get_current_user_id();
$case_old_action = $_REQUEST['action'] ?? null;
$case_options = array();
foreach ( array( 'lcrm_settings', 'lcrm_live_capture_from', 'lcrm_capture_retry_cursor', 'lcrm_duplicate_index', 'cron' ) as $case_key ) {
    $case_options[ $case_key ] = get_option( $case_key, null );
}
$case_globals = array();
foreach ( $GLOBALS as $case_key => $case_value ) {
    if ( 0 === strpos( $case_key, 'lcrm_native_' ) || 0 === strpos( $case_key, 'lcrm_capture_' ) ) { $case_globals[ $case_key ] = $case_value; }
}
$case_tracker = static function ( $id, $post, $update ) use ( &$case_posts ) {
    if ( ! $update ) { $case_posts[] = (int) $id; }
};
add_action( 'wp_after_insert_post', $case_tracker, 99, 3 );
$case_http = static function () use ( &$case_http_calls ) {
    ++$case_http_calls;
    return new WP_Error( 'capture_case_no_http', 'External requests are disabled in capture case tests.' );
};
add_filter( 'pre_http_request', $case_http );

function case_check( $condition, $label ) {
    global $case_passed;
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$case_passed;
    echo "PASS $label\n";
}

function case_request( $method, $input = array(), $nonce = true, $route = null ) {
    $route = $route ?? ( 'GET' === $method ? 'capture-cases' : 'capture-case' );
    $request = new WP_REST_Request( $method, '/limu-crm/v1/' . $route );
    if ( $nonce ) { $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
    if ( 'GET' === $method ) {
        foreach ( $input as $key => $value ) { $request->set_param( $key, $value ); }
    } else {
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $input ) );
    }
    return rest_do_request( $request );
}

function case_lead( $date, $name = 'Synthetic capture case' ) {
    global $case_sequence;
    ++$case_sequence;
    return array( 'name' => $name, 'phone' => '059' . sprintf( '%07d', 8100000 + $case_sequence ), 'email' => 'capture-case-' . $case_sequence . '@example.test', 'form' => 'Contact & enquiry', 'date' => $date );
}

function case_native( $institution, $date ) {
    $lead = case_lead( $date );
    $id = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_title' => 'Synthetic capture case source', 'post_date' => $date ), true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Cannot create an isolated native case.' ); }
    foreach ( array( 'institution' => $institution, 'full-name' => $lead['name'], 'phone' => $lead['phone'], 'email' => $lead['email'], 'form-name' => $lead['form'] ) as $key => $value ) { update_post_meta( $id, $key, $value ); }
    return $id;
}

function case_deliveries( $source ) {
    $ids = get_posts( array( 'post_type' => 'lcrm_delivery', 'post_status' => 'private', 'posts_per_page' => -1, 'fields' => 'ids', 'cache_results' => false, 'meta_key' => '_lcrm_source', 'meta_value' => $source ) );
    return $ids ? LimuCRM\raw_records( $ids ) : array();
}

function case_row( $id, $state = 'active' ) {
    $response = case_request( 'GET', array( 'state' => $state ) );
    if ( 200 !== $response->get_status() ) { throw new RuntimeException( 'The manager case list could not be read.' ); }
    foreach ( $response->get_data()['items'] as $item ) { if ( $id === (int) $item['id'] ) { return $item; } }
    return null;
}

function case_cron() {
    add_filter( 'wp_doing_cron', '__return_true' );
    try { return LimuCRM\capture_retry_run(); } finally { remove_filter( 'wp_doing_cron', '__return_true' ); }
}

function case_fault( $filter, $callback ) {
    global $wpdb;
    $old_errors = $wpdb->suppress_errors( true );
    add_filter( 'query', $filter );
    try { return $callback(); } finally {
        remove_filter( 'query', $filter );
        $wpdb->last_error = '';
        $wpdb->suppress_errors( $old_errors );
    }
}

function case_clear_request() {
    foreach ( array_keys( $GLOBALS ) as $key ) { if ( 0 === strpos( $key, 'lcrm_native_' ) ) { unset( $GLOBALS[ $key ] ); } }
}

function case_audit_count() {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s", 'lcrm_audit' ) );
}

try {
    $case_admin = get_user_by( 'login', 'crm-admin' );
    case_check( $case_admin && user_can( $case_admin, 'manage_options' ), 'A disposable administrator is available for capture case decisions' );
    wp_set_current_user( $case_admin->ID );
    kses_init();
    $case_tag = 'capture-cases-' . wp_generate_uuid4();
    $case_previous = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
    $case_date = current_time( 'Y-m' ) . '-05 10:00:00';
    $case_settings = LimuCRM\settings();
    $case_settings['start_date'] = $case_previous . '-01';
    $case_settings['automatic'] = false;
    update_option( 'lcrm_settings', $case_settings, false );
    update_option( 'lcrm_live_capture_from', $case_previous . '-01 00:00:00', false );
    $case_a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $case_tag . ' A' ) );
    $case_b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $case_tag . ' B' ) );
    foreach ( array( $case_a, $case_b ) as $id ) {
        update_post_meta( $id, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => $case_previous . '-01', 'price' => 1000, 'vat_bp' => 1800 ) ) ) );
        update_post_meta( $id, '_lcrm_duplicate_policy', array( 'duplicate_mode' => 'all_time', 'duplicate_days' => 30 ) );
    }
    update_post_meta( $case_a, 'cid', $case_tag . '-a' );
    update_post_meta( $case_b, 'cid', $case_tag . '-b' );
    $case_lock_name = 'lcrm_' . md5( DB_NAME . ':' . $wpdb->prefix );
    $case_busy = static function ( $query ) use ( $case_lock_name ) {
        return false !== strpos( $query, 'GET_LOCK(' ) && false !== strpos( $query, $case_lock_name ) ? 'SELECT 0' : $query;
    };

    // WordPress enables both content and title KSES for the real visitor permission context.
    wp_set_current_user( 0 );
    kses_init();
    case_check( ! current_user_can( 'unfiltered_html' ) && false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Visitor regression runs anonymously with the real WordPress KSES save filters enabled' );
    foreach ( array( 'Tom & Jerry', 'Literal &amp; Jerry', 'עברית & "quoted" O\'Brien' ) as $case_name ) {
        $lead = case_lead( $case_date, $case_name );
        $source = 'ajax:' . wp_generate_uuid4();
        $queue = LimuCRM\capture_retry_ajax_enqueue( $lead, $case_tag . '-a', $source );
        $item = is_int( $queue ) ? LimuCRM\capture_retry_ajax_read( $queue ) : $queue;
        case_check( is_int( $queue ) && ! is_wp_error( $item ) && $case_name === $item['lead']['name'] && 'Contact & enquiry' === $item['lead']['form'], 'Anonymous AJAX storage preserves ampersands, literal entities, quotes and Hebrew exactly' );
        $result = LimuCRM\capture_retry_ajax_attempt( $queue );
        case_check( ! is_wp_error( $result ) && 'sent' === $result['state'] && $case_name === $result['name'] && $case_date === $result['date'] && ! get_post( $queue ), 'Anonymous AJAX capture completes immediately with the original contact name and date rather than waiting for cron' );
    }
    wp_set_current_user( $case_admin->ID );
    kses_init();

    // A visitor never waits for the foreground lock; accepted data remains durable and a quick retry is deduplicated.
    add_filter( 'wp_doing_ajax', '__return_true' );
    $_REQUEST['action'] = 'institution_interested';
    case_clear_request();
    $case_waits = array();
    $case_fast_busy = static function ( $query ) use ( &$case_waits, $case_lock_name ) {
        if ( false !== strpos( $query, 'GET_LOCK(' ) && false !== strpos( $query, $case_lock_name ) ) {
            preg_match( '/,\s*(\d+)\s*\)/', $query, $match );
            $case_waits[] = isset( $match[1] ) ? (int) $match[1] : -1;
            return 'SELECT 0';
        }
        return $query;
    };
    $case_response = array( 'response' => array( 'code' => 200 ), 'body' => 'synthetic accepted' );
    $case_args = array( 'method' => 'GET' );
    $case_url = 'https://ext.bhol.co.il/lead.php?' . http_build_query( array( 'cId' => $case_tag . '-a', 'fName' => 'Tom & Jerry', 'phone' => '0598100999', 'email' => 'fast-visitor@example.test' ) );
    $case_fast_started = time();
    $case_fast_result = case_fault( $case_fast_busy, static function () use ( $case_response, $case_args, $case_url ) { return LimuCRM\native_http_capture( $case_response, 'response', 'synthetic', $case_args, $case_url ); } );
    $case_soon = wp_next_scheduled( 'lcrm_capture_retry_soon' );
    case_check( is_wp_error( $case_fast_result ) && $case_waits && ! array_diff( $case_waits, array( 0, 1 ) ) && $case_soon >= $case_fast_started && $case_soon <= $case_fast_started + 180, 'Foreground accepted AJAX uses a zero-or-one-second lock and schedules a retry within a few minutes' );
    case_fault( $case_fast_busy, static function () use ( $case_response, $case_args, $case_url ) { return LimuCRM\native_http_capture( $case_response, 'response', 'synthetic', $case_args, $case_url ); } );
    $case_quick_events = 0;
    foreach ( _get_cron_array() as $events ) { if ( isset( $events['lcrm_capture_retry_soon'] ) ) { $case_quick_events += count( $events['lcrm_capture_retry_soon'] ); } }
    case_check( 1 === $case_quick_events && $case_soon === wp_next_scheduled( 'lcrm_capture_retry_soon' ), 'Repeated lock contention keeps one quick-retry event instead of multiplying scheduled jobs' );
    remove_filter( 'wp_doing_ajax', '__return_true' );
    case_clear_request();
    case_cron();

    // Missing or ambiguous CID becomes a normal live mapping exception that the existing manager flow can resolve.
    $case_unknown_source = 'ajax:' . wp_generate_uuid4();
    $case_unknown_lead = case_lead( $case_date, 'Unknown recipient & preserved name' );
    $case_unknown_queues = array();
    $case_unmapped = array();
    foreach ( array( 'missing-one', 'missing-two' ) as $suffix ) {
        $queue = LimuCRM\capture_retry_ajax_enqueue( $case_unknown_lead, $case_tag . '-' . $suffix, $case_unknown_source );
        $case_unknown_queues[] = $queue;
        $result = LimuCRM\capture_retry_ajax_attempt( $queue );
        case_check( ! is_wp_error( $result ) && 'unmapped' === $result['state'] && 0 === $result['institution'] && true === $result['origin_live'] && $case_unknown_source === $result['source'] && $case_date === $result['date'] && $queue === $result['capture_queue'] && ! get_post( $queue ), 'An unknown accepted CID becomes a durable live mapping exception and atomically leaves the technical retry queue' );
        $case_unmapped[] = $result;
    }
    case_check( $case_unmapped[0]['id'] !== $case_unmapped[1]['id'] && array_column( $case_unmapped, 'capture_queue' ) === $case_unknown_queues && 2 === count( case_deliveries( $case_unknown_source ) ) && $case_unmapped[0]['source'] === $case_unmapped[1]['source'], 'Two unknown recipients of one accepted event retain separate provenance without inventing a second source event' );
    $case_mapped_ids = array();
    foreach ( $case_unmapped as $unmapped ) {
        $mapped = case_request( 'POST', array( 'id' => $unmapped['id'], 'institutions' => array( $case_b ) ), true, 'remap' );
        case_check( 200 === $mapped->get_status() && 'sent' === $mapped->get_data()['results'][0]['state'], 'The existing lead-center mapping action resolves a live AJAX exception without a separate form-mapping workflow' );
        $case_mapped_ids[] = $mapped->get_data()['results'][0]['id'];
    }
    case_check( $case_mapped_ids[0] === $case_mapped_ids[1] && 1 === count( case_deliveries( $case_unknown_source ) ), 'Mapping two unknown recipient exceptions to the same institution reuses one delivery and cannot create a second charge' );
    update_post_meta( $case_b, 'cid', $case_tag . '-a' );
    $case_ambiguous_queue = LimuCRM\capture_retry_ajax_enqueue( case_lead( $case_date ), $case_tag . '-a', 'ajax:' . wp_generate_uuid4() );
    $case_ambiguous = LimuCRM\capture_retry_ajax_attempt( $case_ambiguous_queue );
    case_check( ! is_wp_error( $case_ambiguous ) && 'unmapped' === $case_ambiguous['state'] && 0 === $case_ambiguous['institution'] && ! get_post( $case_ambiguous_queue ), 'An ambiguous CID is exposed for manual assignment without guessing either matching institution' );
    update_post_meta( $case_b, 'cid', $case_tag . '-b' );

    // A genuine approved period remains immutable while its missing live source becomes actionable review work.
    case_native( get_post_field( 'post_title', $case_a, 'raw' ), $case_previous . '-04 10:00:00' );
    LimuCRM\native_flush();
    $case_bill = LimuCRM\locked( static function () use ( $case_a, $case_previous ) {
        $draft = LimuCRM\prepare_bill( $case_a, $case_previous );
        return is_wp_error( $draft ) ? $draft : LimuCRM\approve_bill( $draft['id'] );
    } );
    case_check( ! is_wp_error( $case_bill ) && 'approved' === $case_bill['state'], 'The manual-review fixture contains a genuinely approved immutable monthly bill' );
    $case_late = case_native( get_post_field( 'post_title', $case_a, 'raw' ), $case_previous . '-06 10:00:00' );
    $case_late_attempt = LimuCRM\native_flush()[ $case_late ];
    $case_late_marker = LimuCRM\capture_retry_native_read( $case_late );
    $case_late_row = case_row( $case_late );
    case_check( is_wp_error( $case_late_attempt ) && $case_late_row && 'review' === $case_late_row['state'] && 'approved_month' === $case_late_row['reason'] && $case_previous . '-06 10:00:00' === $case_late_row['date'] && is_string( $case_late_row['reason_label'] ) && '' !== $case_late_row['reason_label'], 'An approved-month failure appears in the manager list with its original date and a specific manual-review reason' );
    $case_late_attempts = $case_late_row['attempts'];
    case_cron();
    case_check( $case_late_attempts === case_row( $case_late )['attempts'] && $case_late_marker === LimuCRM\capture_retry_native_read( $case_late ) && $case_bill === LimuCRM\raw_records( array( $case_bill['id'] ) )[0], 'Automatic cron skips a permanent approved-period case and preserves both its original payload and the approved bill' );

    $case_member = wp_create_user( 'capture-case-' . wp_generate_password( 12, false ), wp_generate_password( 48 ), 'capture-case-' . wp_generate_uuid4() . '@example.test' );
    if ( is_wp_error( $case_member ) ) { throw new RuntimeException( 'Cannot create a read-only capture case fixture.' ); }
    get_user_by( 'id', $case_member )->add_cap( 'lcrm_view' );
    update_user_meta( $case_member, '_lcrm_institutions', array( $case_a, $case_b ) );
    wp_set_current_user( $case_member );
    case_check( 403 === case_request( 'GET' )->get_status() && 403 === case_request( 'POST', array( 'id' => $case_late, 'action' => 'close', 'note' => 'Synthetic manual handling' ) )->get_status(), 'Institution accounts cannot inspect or resolve global capture cases even for an assigned institution' );
    $case_member_leads = case_request( 'GET', array( 'institution' => $case_b ), true, 'deliveries' );
    $case_member_mapped = null;
    foreach ( $case_member_leads->get_data()['items'] ?? array() as $row ) { if ( $case_mapped_ids[0] === $row['id'] ) { $case_member_mapped = $row; } }
    case_check( 200 === $case_member_leads->get_status() && is_array( $case_member_mapped ) && ! array_intersect_key( $case_member_mapped, array_fill_keys( array( 'capture_name', 'capture_cid', 'capture_queue', 'capture_issue', 'source', 'contact' ), true ) ), 'An institution can read its mapped lead without receiving private CID, queue provenance or other capture bookkeeping' );
    wp_set_current_user( 0 );
    case_check( 403 === case_request( 'GET' )->get_status() && 403 === case_request( 'POST', array( 'id' => $case_late, 'action' => 'retry' ) )->get_status(), 'Anonymous requests cannot read or mutate pending capture case details' );
    wp_set_current_user( $case_admin->ID );
    kses_init();
    case_check( 403 === case_request( 'GET', array(), false )->get_status(), 'Even manager case-list reads require a current nonce before exposing private contact details' );
    case_check( 403 === case_request( 'POST', array( 'id' => $case_late, 'action' => 'close', 'note' => 'Synthetic handling' ), false )->get_status(), 'Manager capture case mutations require a current REST nonce' );
    case_check( case_request( 'POST', array( 'id' => $case_a, 'action' => 'close', 'note' => 'A non-source must not be changed.' ) )->get_status() >= 400, 'A case decision cannot target an institution or another unrelated WordPress record' );
    foreach ( array( array( 'id' => $case_late, 'action' => 'close' ), array( 'id' => $case_late, 'action' => 'close', 'note' => '' ), array( 'id' => $case_late, 'action' => 'close', 'note' => array( 'bad' ) ), array( 'id' => $case_late, 'action' => 'force_bill' ), array( 'id' => array( $case_late ), 'action' => 'retry' ), array( 'id' => $case_late, 'action' => 'retry', 'institution' => $case_b ) ) as $invalid ) {
        case_check( case_request( 'POST', $invalid )->get_status() >= 400, 'Malformed or unsupported case decisions cannot overwrite financial or source fields' );
    }
    $case_manual_retry = case_request( 'POST', array( 'id' => $case_late, 'action' => 'retry' ) );
    case_check( 200 === $case_manual_retry->get_status() && false === $case_manual_retry->get_data()['completed'] && 'review' === $case_manual_retry->get_data()['item']['state'] && $case_bill === LimuCRM\raw_records( array( $case_bill['id'] ) )[0] && ! case_deliveries( 'legacy:' . $case_late ), 'An explicit manager retry reports the remaining approved-period conflict and cannot bypass its frozen bill' );

    $case_before_close = LimuCRM\capture_case_read( $case_late );
    $case_before_audits = case_audit_count();
    $case_audit_failure = static function ( $empty, $post ) { return 'lcrm_audit' === ( $post['post_type'] ?? '' ) ? true : $empty; };
    add_filter( 'wp_insert_post_empty_content', $case_audit_failure, 10, 2 );
    try { $failed = case_request( 'POST', array( 'id' => $case_late, 'action' => 'close', 'note' => 'Synthetic failed audit handling' ) ); }
    finally { remove_filter( 'wp_insert_post_empty_content', $case_audit_failure, 10 ); }
    case_check( $failed->get_status() >= 400 && $case_before_close === LimuCRM\capture_case_read( $case_late ) && $case_before_audits === case_audit_count(), 'A close-decision audit failure rolls back the case state and cannot silently hide pending work' );
    $case_commit_hits = 0;
    $case_commit_fault = static function ( $query ) use ( &$case_commit_hits ) { return 'COMMIT' === $query && 1 === ++$case_commit_hits ? 'SELECT capture_case_missing_column FROM capture_case_missing_table' : $query; };
    $failed = case_fault( $case_commit_fault, static function () use ( $case_late ) { return case_request( 'POST', array( 'id' => $case_late, 'action' => 'close', 'note' => 'Synthetic failed commit handling' ) ); } );
    case_check( $failed->get_status() >= 400 && $case_before_close === LimuCRM\capture_case_read( $case_late ) && $case_before_audits === case_audit_count(), 'A failed close COMMIT preserves the active case, private payload and audit count' );
    $case_before_pending = LimuCRM\capture_pending_count();
    $closed = case_request( 'POST', array( 'id' => $case_late, 'action' => 'close', 'note' => 'Synthetic manual accounting handled separately' ) );
    $closed_row = case_row( $case_late, 'closed' );
    case_check( 200 === $closed->get_status() && $closed_row && 'closed' === $closed_row['state'] && '' !== $closed_row['closed_at'] && null === case_row( $case_late ) && $case_before_pending - 1 === LimuCRM\capture_pending_count(), 'A confirmed manager closure removes a handled case from the active queue and keeps it visible in the closed archive' );
    case_check( $case_late_marker === LimuCRM\capture_retry_native_read( $case_late ) && ! case_deliveries( 'legacy:' . $case_late ) && $case_bill === LimuCRM\raw_records( array( $case_bill['id'] ) )[0] && $case_before_audits + 1 === case_audit_count(), 'Manual closure retains the source marker, records one audit decision and makes no delivery or approved-bill change' );
    $case_audit_ids = get_posts( array( 'post_type' => 'lcrm_audit', 'post_status' => 'private', 'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'cache_results' => false ) );
    $case_audit = LimuCRM\raw_records( $case_audit_ids )[0];
    case_check( $case_late === $case_audit['target'] && $case_admin->ID === $case_audit['actor'] && ! empty( $case_audit['at'] ) && false === strpos( wp_json_encode( $case_audit ), 'Synthetic manual accounting handled separately' ) && false === strpos( wp_json_encode( $case_audit ), $case_late_row['phone'] ), 'The manual decision journal identifies actor, source and time without copying the private handling note or contact details' );
    case_cron();
    case_check( 'closed' === case_row( $case_late, 'closed' )['state'] && $case_late_marker === LimuCRM\capture_retry_native_read( $case_late ), 'Hourly retry cannot reopen a manager-closed case or erase its retained source' );
    $reopened = case_request( 'POST', array( 'id' => $case_late, 'action' => 'reopen' ) );
    case_check( 200 === $reopened->get_status() && false === $reopened->get_data()['completed'] && 'review' === $reopened->get_data()['item']['state'] && $case_before_pending === LimuCRM\capture_pending_count(), 'Reopening a retained case performs a safe retry and returns to review when the approved-period condition still exists' );

    // A conflicting source identity is reviewable, not an endless invisible hourly retry.
    $case_identity_source = 'ajax:' . wp_generate_uuid4();
    $case_identity_lead = case_lead( $case_date );
    $case_identity_first = LimuCRM\locked( static function () use ( $case_identity_lead, $case_a, $case_identity_source ) { return LimuCRM\record_delivery( $case_identity_lead, $case_a, $case_identity_source ); } );
    $case_identity_changed = $case_identity_lead;
    $case_identity_changed['phone'] = '0598999999';
    $case_identity_queue = LimuCRM\capture_retry_ajax_enqueue( $case_identity_changed, $case_tag . '-a', $case_identity_source );
    $case_identity_result = LimuCRM\capture_retry_ajax_attempt( $case_identity_queue );
    $case_identity_row = case_row( $case_identity_queue );
    $case_identity_payload = LimuCRM\capture_retry_ajax_read( $case_identity_queue );
    case_check( ! is_wp_error( $case_identity_first ) && is_wp_error( $case_identity_result ) && $case_identity_row && 'review' === $case_identity_row['state'] && 'identity_conflict' === $case_identity_row['reason'] && $case_identity_changed['phone'] === $case_identity_row['phone'], 'A source with contradictory contact details appears as a specific manager review case without overwriting the original delivery' );
    $case_identity_attempts = $case_identity_row['attempts'];
    case_cron();
    case_check( $case_identity_attempts === case_row( $case_identity_queue )['attempts'] && $case_identity_payload === LimuCRM\capture_retry_ajax_read( $case_identity_queue ) && 1 === count( case_deliveries( $case_identity_source ) ), 'Automatic retry skips an identity conflict and preserves both competing evidence and the original single delivery' );
    $case_identity_closed = case_request( 'POST', array( 'id' => $case_identity_queue, 'action' => 'close', 'note' => 'Synthetic conflicting source handled manually' ) );
    case_check( 200 === $case_identity_closed->get_status() && 'closed' === case_row( $case_identity_queue, 'closed' )['state'] && $case_identity_payload === LimuCRM\capture_retry_ajax_read( $case_identity_queue ) && 1 === count( case_deliveries( $case_identity_source ) ), 'Closing an AJAX identity conflict retains its private envelope and does not create or modify a charged delivery' );

    $case_empty_contact_source = 'ajax:' . wp_generate_uuid4();
    $case_empty_contact = case_lead( $case_date );
    $case_empty_contact['phone'] = '';
    $case_empty_contact['email'] = '';
    $case_empty_contact_queue = LimuCRM\capture_retry_ajax_enqueue( $case_empty_contact, $case_tag . '-a', $case_empty_contact_source );
    $case_empty_contact_result = LimuCRM\capture_retry_ajax_attempt( $case_empty_contact_queue );
    $case_empty_contact_row = case_row( $case_empty_contact_queue );
    $case_empty_contact_payload = LimuCRM\capture_retry_ajax_read( $case_empty_contact_queue );
    case_check( is_wp_error( $case_empty_contact_result ) && $case_empty_contact_row && 'review' === $case_empty_contact_row['state'] && 'invalid_payload' === $case_empty_contact_row['reason'] && '' === $case_empty_contact_payload['lead']['phone'] && '' === $case_empty_contact_payload['lead']['email'] && ! case_deliveries( $case_empty_contact_source ), 'An accepted known-CID request without usable contact details retains its evidence as invalid-payload review instead of an endless temporary retry' );
    case_cron();
    case_check( $case_empty_contact_row['attempts'] === case_row( $case_empty_contact_queue )['attempts'] && $case_empty_contact_payload === LimuCRM\capture_retry_ajax_read( $case_empty_contact_queue ) && ! case_deliveries( $case_empty_contact_source ), 'Automatic cron skips a permanently invalid contact without discarding its original envelope or creating a financial delivery' );
    $case_empty_contact_closed = case_request( 'POST', array( 'id' => $case_empty_contact_queue, 'action' => 'close', 'note' => 'Synthetic invalid contact reviewed and handled manually' ) );
    case_check( 200 === $case_empty_contact_closed->get_status() && 'closed' === case_row( $case_empty_contact_queue, 'closed' )['state'] && $case_empty_contact_payload === LimuCRM\capture_retry_ajax_read( $case_empty_contact_queue ) && ! case_deliveries( $case_empty_contact_source ), 'A manager can close an invalid-contact case with retained evidence and no accounting change' );

    $case_sql_fault = static function ( $query ) { return false !== stripos( $query, 'SELECT' ) && false !== strpos( $query, "'_lcrm_capture_case'" ) ? 'SELECT capture_case_missing_column FROM capture_case_missing_table' : $query; };
    $case_failed_read = case_fault( $case_sql_fault, static function () { return case_request( 'GET' ); } );
    case_check( $case_failed_read->get_status() >= 500, 'A case-metadata SQL failure returns an error instead of presenting an empty or healthy manager queue' );
    wp_cache_set( $case_identity_queue, array( '_lcrm_capture_case' => array( maybe_serialize( array( 'state' => 'retry' ) ) ), '_lcrm_capture_state' => array( 'retry' ) ), 'post_meta' );
    try { $case_closed_cache = LimuCRM\capture_case_read( $case_identity_queue ); }
    finally { wp_cache_delete( $case_identity_queue, 'post_meta' ); }
    case_check( is_array( $case_closed_cache ) && 'closed' === $case_closed_cache['state'], 'A stale metadata cache cannot reopen a committed manual closure' );
    $case_page_queues = array();
    for ( $case_page_index = 0; $case_page_index < 31; ++$case_page_index ) {
        $case_page_queues[] = LimuCRM\capture_retry_ajax_enqueue( case_lead( $case_date ), $case_tag . '-b', 'ajax:' . wp_generate_uuid4() );
    }
    $case_page_first = case_request( 'GET', array( 'state' => 'retry', 'page' => 1 ) );
    $case_page_second = case_request( 'GET', array( 'state' => 'retry', 'page' => 2 ) );
    $case_first = $case_page_first->get_data();
    $case_second = $case_page_second->get_data();
    case_check( 31 === count( array_filter( $case_page_queues, 'is_int' ) ) && 200 === $case_page_first->get_status() && 200 === $case_page_second->get_status() && 30 === count( $case_first['items'] ) && count( $case_second['items'] ) >= 1 && count( $case_second['items'] ) <= 30 && $case_first['total'] >= 31 && $case_first['pages'] >= 2 && ! array_intersect( array_column( $case_first['items'], 'id' ), array_column( $case_second['items'], 'id' ) ), 'Manager case pages are bounded, non-overlapping and expose older waiting sources after the first thirty rows' );
    case_check( ! array_filter( $case_first['items'], static function ( $row ) { return ! isset( $row['date'], $row['name'], $row['phone'], $row['email'], $row['reason'], $row['reason_label'], $row['state'] ) || isset( $row['post_content'], $row['lead'], $row['snapshot'] ); } ), 'Case rows provide bounded operational fields without returning raw storage envelopes or snapshots' );
    case_check( case_request( 'GET', array( 'state' => 'invalid-state' ) )->get_status() >= 400, 'Invalid case-state filters are rejected rather than broadening a private-data query' );
    case_check( 0 === $case_http_calls, 'Case listing, manual handling and retry never resend leads or issue fiscal HTTP requests' );
    echo "OK $case_passed capture case assertions\n";
} catch ( Throwable $exception ) {
    $case_failure = $exception;
    fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" );
} finally {
    remove_filter( 'wp_doing_ajax', '__return_true' );
    remove_filter( 'wp_doing_cron', '__return_true' );
    remove_filter( 'pre_http_request', $case_http );
    remove_action( 'wp_after_insert_post', $case_tracker, 99 );
    case_clear_request();
    wp_set_current_user( $case_admin->ID ?? $case_old_user );
    kses_init();
    foreach ( array_reverse( array_unique( $case_posts ) ) as $id ) { wp_delete_post( $id, true ); }
    if ( $case_member && ! is_wp_error( $case_member ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $case_member ); }
    foreach ( $case_options as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); } }
    foreach ( array_keys( $GLOBALS ) as $key ) { if ( 0 === strpos( $key, 'lcrm_native_' ) || 0 === strpos( $key, 'lcrm_capture_' ) ) { unset( $GLOBALS[ $key ] ); } }
    foreach ( $case_globals as $key => $value ) { $GLOBALS[ $key ] = $value; }
    if ( null === $case_old_action ) { unset( $_REQUEST['action'] ); } else { $_REQUEST['action'] = $case_old_action; }
    wp_set_current_user( $case_old_user );
    kses_init();
}
if ( $case_failure ) { exit( 1 ); }
