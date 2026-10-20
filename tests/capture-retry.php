<?php
/** Durable capture retries against synthetic records in the disposable WordPress database only. */
$retry_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $retry_root ) {
    fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
    exit( 1 );
}
require $retry_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
    fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
    exit( 1 );
}
register_post_type( 'institutions', array( 'public' => false ) );
register_post_type( 'leads', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$retry_passed = 0;
$retry_created = array();
$retry_user = 0;
$retry_http_calls = 0;
$retry_sequence = 0;
$retry_failure = null;
$retry_original_user = get_current_user_id();
$retry_original_action = $_REQUEST['action'] ?? null;
$retry_options = array();
foreach ( array( 'lcrm_settings', 'lcrm_live_capture_from', 'lcrm_capture_retry_cursor', 'lcrm_duplicate_index', 'cron' ) as $retry_option ) {
    $retry_options[ $retry_option ] = get_option( $retry_option, null );
}
$retry_globals = array();
foreach ( $GLOBALS as $retry_key => $retry_value ) {
    if ( 0 === strpos( $retry_key, 'lcrm_native_' ) || 0 === strpos( $retry_key, 'lcrm_capture_' ) ) {
        $retry_globals[ $retry_key ] = $retry_value;
    }
}
$retry_track = static function ( $id, $post, $update ) use ( &$retry_created ) {
    if ( ! $update ) { $retry_created[] = (int) $id; }
};
add_action( 'wp_after_insert_post', $retry_track, 99, 3 );
$retry_no_http = static function () use ( &$retry_http_calls ) {
    ++$retry_http_calls;
    return new WP_Error( 'capture_retry_no_http', 'External requests are disabled by the synthetic retry test.' );
};
add_filter( 'pre_http_request', $retry_no_http );

function retry_check( $condition, $label ) {
    global $retry_passed;
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$retry_passed;
    echo "PASS $label\n";
}

function retry_fresh_marker( $id ) {
    return LimuCRM\capture_retry_native_read( $id );
}

function retry_source( $institution, $date, $status = 'publish' ) {
    global $retry_sequence;
    ++$retry_sequence;
    $id = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => $status, 'post_title' => 'Synthetic retry source', 'post_date' => $date ), true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Cannot create an isolated source lead.' ); }
    foreach ( array( 'institution' => $institution, 'full-name' => 'Synthetic retry contact', 'phone' => '059' . sprintf( '%07d', $retry_sequence ), 'email' => 'capture-retry-' . $retry_sequence . '@example.test', 'form-name' => 'Capture retry fixture' ) as $key => $value ) {
        update_post_meta( $id, $key, $value );
    }
    return $id;
}

function retry_deliveries( $source ) {
    $ids = get_posts( array( 'post_type' => 'lcrm_delivery', 'post_status' => 'private', 'posts_per_page' => -1, 'fields' => 'ids', 'cache_results' => false, 'meta_key' => '_lcrm_source', 'meta_value' => $source ) );
    return $ids ? LimuCRM\raw_records( $ids ) : array();
}

function retry_queue_ids( $source = null ) {
    global $wpdb;
    if ( null === $source ) {
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY ID", 'lcrm_capture', 'private' ) ) );
    }
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_excerpt = %s ORDER BY ID", 'lcrm_capture', 'private', $source ) ) );
}

function retry_queue_envelope( $id ) {
    global $wpdb;
    return json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ), true );
}

function retry_clear_request() {
    foreach ( array_keys( $GLOBALS ) as $key ) {
        if ( 0 === strpos( $key, 'lcrm_native_' ) ) { unset( $GLOBALS[ $key ] ); }
    }
}

function retry_fault( $filter, $callback ) {
    global $wpdb;
    $errors = $wpdb->suppress_errors( true );
    add_filter( 'query', $filter );
    try {
        return $callback();
    } finally {
        remove_filter( 'query', $filter );
        $wpdb->last_error = '';
        $wpdb->suppress_errors( $errors );
    }
}

function retry_cron( $continuation = false ) {
    add_filter( 'wp_doing_cron', '__return_true' );
    try {
        return LimuCRM\capture_retry_run( $continuation );
    } finally {
        remove_filter( 'wp_doing_cron', '__return_true' );
    }
}

function retry_summary( $user_id, $period = array() ) {
    wp_set_current_user( $user_id );
    $request = new WP_REST_Request( 'GET', '/limu-crm/v1/summary' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    foreach ( $period as $key => $value ) { $request->set_param( $key, $value ); }
    return rest_do_request( $request );
}

try {
    $retry_admin = get_user_by( 'login', 'crm-admin' );
    retry_check( $retry_admin && user_can( $retry_admin, 'manage_options' ), 'A disposable administrator is available' );
    wp_set_current_user( $retry_admin->ID );
    $retry_month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
    $retry_date = $retry_month . '-05 10:00:00';
    $retry_settings = LimuCRM\settings();
    $retry_settings['start_date'] = $retry_month . '-01';
    $retry_settings['automatic'] = false;
    update_option( 'lcrm_settings', $retry_settings, false );
    update_option( 'lcrm_live_capture_from', $retry_month . '-01 00:00:00', false );
    $retry_tag = 'capture-retry-' . wp_generate_uuid4();
    $retry_a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $retry_tag . ' A' ) );
    $retry_b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $retry_tag . ' B' ) );
    foreach ( array( $retry_a, $retry_b ) as $retry_id ) {
        update_post_meta( $retry_id, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => $retry_month . '-01', 'price' => 1000, 'vat_bp' => 1800 ) ) ) );
        update_post_meta( $retry_id, '_lcrm_duplicate_policy', array( 'duplicate_mode' => 'all_time', 'duplicate_days' => 30 ) );
    }
    $retry_title_a = get_post_field( 'post_title', $retry_a, 'raw' );
    $retry_title_b = get_post_field( 'post_title', $retry_b, 'raw' );
    $retry_financial_lock = 'lcrm_' . md5( DB_NAME . ':' . $wpdb->prefix );
    $retry_busy = static function ( $query ) use ( $retry_financial_lock ) {
        return false !== strpos( $query, 'GET_LOCK(' ) && false !== strpos( $query, $retry_financial_lock ) ? 'SELECT 0' : $query;
    };
    retry_check( 0 === LimuCRM\capture_pending_count(), 'The isolated test begins without unrelated queued sources' );
    $retry_guard = LimuCRM\capture_retry_run();
    retry_check( is_wp_error( $retry_guard ) && 403 === $retry_guard->get_error_data()['status'], 'An ordinary request cannot execute the private retry worker' );
    retry_check( 404 === rest_do_request( new WP_REST_Request( 'POST', '/limu-crm/v1/capture-retry' ) )->get_status(), 'No public capture-retry mutation route is exposed' );

    // The creation hook must establish live origin before any later form fields are written.
    $retry_early = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'draft', 'post_title' => 'Unfinished synthetic form', 'post_date' => $retry_date ) );
    $retry_early_marker = retry_fresh_marker( $retry_early );
    retry_check( is_array( $retry_early_marker ) && true === $retry_early_marker['live'] && $retry_date === $retry_early_marker['date'] && is_int( $retry_early_marker['created_at'] ), 'Creation durably stores the original date and live flag before final form metadata or business locking' );
    retry_check( 0 === LimuCRM\capture_pending_count(), 'Unpublished drafts are not reported as pending received leads' );
    foreach ( array( 'institution' => $retry_title_a, 'full-name' => 'Synthetic later publication', 'phone' => '0597000001', 'email' => 'later-publication@example.test', 'form-name' => 'Capture retry fixture' ) as $key => $value ) { update_post_meta( $retry_early, $key, $value ); }
    retry_clear_request();
    wp_update_post( array( 'ID' => $retry_early, 'post_status' => 'publish' ) );
    LimuCRM\native_flush();
    $retry_early_rows = retry_deliveries( 'legacy:' . $retry_early );
    retry_check( 1 === count( $retry_early_rows ) && 'sent' === $retry_early_rows[0]['state'] && $retry_date === $retry_early_rows[0]['date'] && null === retry_fresh_marker( $retry_early ), 'Publishing a marked draft in a later request preserves live origin and clears pending only after capture' );

    // A failed creation-marker insert on a draft must still be persisted at shutdown.
    $retry_marker_insert_hits = 0;
    $retry_marker_insert_fault = static function ( $query ) use ( &$retry_marker_insert_hits ) {
        global $wpdb;
        if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, $wpdb->postmeta ) && false !== strpos( $query, "'_lcrm_capture_pending'" ) ) {
            ++$retry_marker_insert_hits;
            return 'SELECT retry_missing_column FROM retry_missing_table';
        }
        return $query;
    };
    $retry_fallback_draft = retry_fault( $retry_marker_insert_fault, static function () use ( $retry_title_a, $retry_date ) { return retry_source( $retry_title_a, $retry_date, 'draft' ); } );
    $retry_fallback_origin = $GLOBALS['lcrm_native_markers'][ $retry_fallback_draft ] ?? null;
    retry_check( 1 === $retry_marker_insert_hits && null === retry_fresh_marker( $retry_fallback_draft ) && is_array( $retry_fallback_origin ) && true === $retry_fallback_origin['live'] && empty( $GLOBALS['lcrm_native_queue'][ $retry_fallback_draft ] ), 'A draft whose initial marker INSERT failed retains only its original live marker fallback, without entering the published capture queue' );
    $retry_fallback_shutdown = LimuCRM\native_flush();
    $retry_fallback_persisted = retry_fresh_marker( $retry_fallback_draft );
    retry_check( ! $retry_fallback_shutdown && $retry_fallback_origin === $retry_fallback_persisted && ! isset( $retry_fallback_persisted['snapshot'] ) && empty( $GLOBALS['lcrm_native_markers'][ $retry_fallback_draft ] ) && ! retry_deliveries( 'legacy:' . $retry_fallback_draft ) && 0 === LimuCRM\capture_pending_count(), 'Shutdown persists a retained draft marker without freezing unfinished fields or manufacturing a received delivery' );
    retry_clear_request();
    wp_update_post( array( 'ID' => $retry_fallback_draft, 'post_status' => 'publish' ) );
    $retry_fallback_original_queue = $GLOBALS['lcrm_native_queue'] ?? array();
    LimuCRM\native_flush();
    $retry_fallback_rows = retry_deliveries( 'legacy:' . $retry_fallback_draft );
    retry_check( 1 === count( $retry_fallback_rows ) && 'sent' === $retry_fallback_rows[0]['state'] && true === $retry_fallback_rows[0]['origin_live'] && $retry_date === $retry_fallback_rows[0]['date'] && null === retry_fresh_marker( $retry_fallback_draft ), 'Publishing the recovered draft in a later request preserves the original live event and clears its durable marker' );
    update_post_meta( $retry_fallback_draft, 'institution', $retry_title_b );
    wp_update_post( array( 'ID' => $retry_fallback_draft, 'post_title' => 'Edited successfully captured source' ) );
    $GLOBALS['lcrm_native_queue'] = $retry_fallback_original_queue;
    LimuCRM\native_flush();
    $retry_fallback_replay_rows = retry_deliveries( 'legacy:' . $retry_fallback_draft );
    retry_check( null === retry_fresh_marker( $retry_fallback_draft ) && empty( $GLOBALS['lcrm_native_markers'][ $retry_fallback_draft ] ) && 1 === count( $retry_fallback_replay_rows ) && $retry_a === $retry_fallback_replay_rows[0]['institution'], 'Replaying the original queue after successful capture cannot resurrect its marker or bill a newly edited recipient' );

    $retry_old = retry_source( $retry_title_a, $retry_date );
    delete_post_meta( $retry_old, '_lcrm_capture_pending' );
    retry_clear_request();
    wp_update_post( array( 'ID' => $retry_old, 'post_title' => 'Edited unmarked pre-upgrade source' ) );
    retry_check( ! LimuCRM\native_flush() && ! retry_deliveries( 'legacy:' . $retry_old ) && null === retry_fresh_marker( $retry_old ), 'Editing an unmarked older source does not manufacture a new live event' );
    wp_delete_post( $retry_old, true );

    wp_cache_set( 'lcrm_live_capture_from', '2999-01-01 00:00:00', 'options' );
    try {
        $retry_cached_boundary_source = retry_source( $retry_title_a, $retry_date );
        $retry_cached_boundary_marker = retry_fresh_marker( $retry_cached_boundary_source );
    } finally { wp_cache_delete( 'lcrm_live_capture_from', 'options' ); }
    retry_check( is_array( $retry_cached_boundary_marker ) && true === $retry_cached_boundary_marker['live'] && empty( $retry_cached_boundary_marker['boundary_pending'] ), 'A stale options-cache activation boundary cannot misclassify a newly created live source as historical' );
    LimuCRM\native_flush();
    $retry_cached_boundary_rows = retry_deliveries( 'legacy:' . $retry_cached_boundary_source );
    retry_check( 1 === count( $retry_cached_boundary_rows ) && 'sent' === $retry_cached_boundary_rows[0]['state'], 'Fresh boundary classification remains live through the committed delivery' );
    $retry_boundary_hits = 0;
    $retry_boundary_fault = static function ( $query ) use ( &$retry_boundary_hits ) {
        if ( 0 === strpos( $query, 'SELECT option_value FROM ' ) && false !== strpos( $query, "'lcrm_live_capture_from'" ) ) {
            ++$retry_boundary_hits;
            return 'SELECT retry_missing_column FROM retry_missing_table';
        }
        return $query;
    };
    $retry_boundary_failed = retry_fault( $retry_boundary_fault, static function () use ( $retry_title_a, $retry_date ) {
        $id = retry_source( $retry_title_a, $retry_date );
        return array( 'id' => $id, 'results' => LimuCRM\native_flush(), 'marker' => retry_fresh_marker( $id ) );
    } );
    $retry_boundary_source = $retry_boundary_failed['id'];
    $retry_boundary_marker = $retry_boundary_failed['marker'];
    retry_check( $retry_boundary_hits > 0 && is_wp_error( $retry_boundary_failed['results'][ $retry_boundary_source ] ) && true === $retry_boundary_marker['boundary_pending'] && isset( $retry_boundary_marker['snapshot'] ) && $retry_date === $retry_boundary_marker['date'] && ! retry_deliveries( 'legacy:' . $retry_boundary_source ), 'A failed activation-boundary read persists an unresolved origin and frozen payload without emitting a historical delivery' );
    retry_clear_request();
    retry_cron();
    $retry_boundary_rows = retry_deliveries( 'legacy:' . $retry_boundary_source );
    retry_check( 1 === count( $retry_boundary_rows ) && 'sent' === $retry_boundary_rows[0]['state'] && true === $retry_boundary_rows[0]['origin_live'] && $retry_date === $retry_boundary_rows[0]['date'] && null === retry_fresh_marker( $retry_boundary_source ), 'A later worker resolves the fresh activation boundary and preserves the source as a billable live event' );

    // Lock contention must leave every source durably queued across a fresh request.
    $retry_one = retry_source( $retry_title_a, $retry_date );
    $retry_two = retry_source( $retry_title_b, $retry_month . '-06 10:00:00' );
    $retry_original_phone = get_post_meta( $retry_one, 'phone', true );
    $retry_busy_results = retry_fault( $retry_busy, static function () { return LimuCRM\native_flush(); } );
    retry_check( is_wp_error( $retry_busy_results[ $retry_one ] ) && is_wp_error( $retry_busy_results[ $retry_two ] ) && retry_fresh_marker( $retry_one )['live'] && retry_fresh_marker( $retry_two )['live'], 'Busy business locks retain both native live markers rather than relying on request globals' );
    retry_check( 2 === LimuCRM\capture_pending_count() && ! retry_deliveries( 'legacy:' . $retry_one ) && ! retry_deliveries( 'legacy:' . $retry_two ), 'Failed native captures do not fabricate deliveries or remove the backlog' );
    $retry_frozen = retry_fresh_marker( $retry_one );
    retry_check( isset( $retry_frozen['snapshot'] ) && $retry_original_phone === $retry_frozen['snapshot']['phone'], 'Final native form values are frozen before a failing business lock' );
    update_post_meta( $retry_one, 'phone', '0599999999' );
    update_post_meta( $retry_one, 'email', 'edited-after-failure@example.test' );
    update_post_meta( $retry_one, 'institution', $retry_title_b );
    wp_update_post( array( 'ID' => $retry_one, 'post_date' => current_time( 'mysql' ) ) );
    retry_clear_request();
    $retry_recovered = retry_cron();
    $retry_one_rows = retry_deliveries( 'legacy:' . $retry_one );
    $retry_two_rows = retry_deliveries( 'legacy:' . $retry_two );
    retry_check( ! is_wp_error( $retry_recovered ) && 1 === count( $retry_one_rows ) && 1 === count( $retry_two_rows ) && 'sent' === $retry_one_rows[0]['state'], 'Hourly retry captures both sources as live after all original request globals have gone' );
    retry_check( $retry_a === $retry_one_rows[0]['institution'] && $retry_date === $retry_one_rows[0]['date'] && $retry_frozen['snapshot']['phone'] === $retry_one_rows[0]['phone_key'], 'Editing the source after failure cannot change the frozen date, identity or recipient used for billing' );
    retry_check( null === retry_fresh_marker( $retry_one ) && null === retry_fresh_marker( $retry_two ) && 0 === LimuCRM\capture_pending_count(), 'Committed retry capture clears both native markers and the global backlog' );
    retry_cron();
    retry_check( 1 === count( retry_deliveries( 'legacy:' . $retry_one ) ) && 1 === count( retry_deliveries( 'legacy:' . $retry_two ) ), 'Repeated cron execution cannot create a second delivery for the same native source' );

    // A warm-up failure affects this request, but never discards its persisted work.
    $retry_catalog_one = retry_source( $retry_title_a, $retry_month . '-07 10:00:00' );
    $retry_catalog_two = retry_source( $retry_title_b, $retry_month . '-08 10:00:00' );
    LimuCRM\native_catalog_invalidate();
    $retry_catalog_failure = static function ( $query ) {
        return false !== stripos( $query, 'SELECT' ) && false !== strpos( $query, "'institutions'" ) && false !== strpos( $query, "'page'" ) ? 'SELECT retry_missing_column FROM retry_missing_table' : $query;
    };
    $retry_catalog_results = retry_fault( $retry_catalog_failure, static function () { return LimuCRM\native_flush(); } );
    retry_check( is_wp_error( $retry_catalog_results[ $retry_catalog_one ] ) && is_wp_error( $retry_catalog_results[ $retry_catalog_two ] ) && 2 === LimuCRM\capture_pending_count(), 'A failed catalog warm-up leaves all source leads marked for retry' );
    retry_clear_request();
    retry_cron();
    retry_check( 1 === count( retry_deliveries( 'legacy:' . $retry_catalog_one ) ) && 1 === count( retry_deliveries( 'legacy:' . $retry_catalog_two ) ) && 0 === LimuCRM\capture_pending_count(), 'A later worker recovers every source from a catalog failure without a new form submission' );

    $retry_shared = retry_source( $retry_title_a . ', ' . $retry_title_b, $retry_month . '-09 10:00:00' );
    $retry_second_writes = 0;
    $retry_second_fault = static function ( $check, $id, $key, $value ) use ( &$retry_second_writes, $retry_b ) {
        if ( '_lcrm_data' === $key && is_array( $value ) && $retry_b === ( $value['institution'] ?? null ) && 'sent' === ( $value['state'] ?? null ) ) {
            ++$retry_second_writes;
            return false;
        }
        return $check;
    };
    add_filter( 'update_post_metadata', $retry_second_fault, 10, 4 );
    try { $retry_shared_result = LimuCRM\native_flush(); } finally { remove_filter( 'update_post_metadata', $retry_second_fault, 10 ); }
    retry_check( $retry_second_writes > 0 && is_wp_error( $retry_shared_result[ $retry_shared ] ) && ! retry_deliveries( 'legacy:' . $retry_shared ) && is_array( retry_fresh_marker( $retry_shared ) ), 'Second-recipient persistence failure rolls back the first recipient and retains the shared source marker' );
    retry_clear_request();
    retry_cron();
    $retry_shared_rows = retry_deliveries( 'legacy:' . $retry_shared );
    retry_check( 2 === count( $retry_shared_rows ) && 2 === count( array_unique( array_column( $retry_shared_rows, 'institution' ) ) ) && null === retry_fresh_marker( $retry_shared ), 'A shared source retries all institutions once without partial or duplicate deliveries' );

    $retry_commit = retry_source( $retry_title_a, $retry_month . '-10 10:00:00' );
    $retry_commit_count = 0;
    $retry_commit_failure = static function ( $query ) use ( &$retry_commit_count ) {
        return 'COMMIT' === $query && 1 === ++$retry_commit_count ? 'SELECT retry_missing_column FROM retry_missing_table' : $query;
    };
    $retry_commit_result = retry_fault( $retry_commit_failure, static function () { return LimuCRM\native_flush(); } );
    retry_check( is_wp_error( $retry_commit_result[ $retry_commit ] ) && is_array( retry_fresh_marker( $retry_commit ) ) && ! retry_deliveries( 'legacy:' . $retry_commit ), 'A failed COMMIT cannot clear a native marker or leave a delivery that appears complete' );
    retry_clear_request();
    retry_cron();
    retry_check( 1 === count( retry_deliveries( 'legacy:' . $retry_commit ) ) && null === retry_fresh_marker( $retry_commit ), 'Native capture can retry safely after COMMIT failure' );

    $retry_unknown = retry_source( $retry_tag . ' unresolved institution', $retry_month . '-11 10:00:00' );
    $retry_unknown_result = LimuCRM\native_flush();
    retry_check( 'unmapped' === $retry_unknown_result[ $retry_unknown ]['state'] && null === retry_fresh_marker( $retry_unknown ), 'A committed mapping exception is a completed capture, not an endless technical retry' );

    // Accepted AJAX transport has no source lead post, so it needs its own write-ahead record.
    update_post_meta( $retry_a, 'cid', $retry_tag . '-cid-a' );
    update_post_meta( $retry_b, 'cid', $retry_tag . '-cid-b' );
    add_filter( 'wp_doing_ajax', '__return_true' );
    $_REQUEST['action'] = 'institution_interested';
    retry_clear_request();
    $retry_response = array( 'response' => array( 'code' => 200 ), 'body' => 'synthetic accepted' );
    $retry_args = array( 'method' => 'GET' );
    $retry_query = array( 'cId' => $retry_tag . '-cid-a', 'fName' => 'Synthetic AJAX retry', 'phone' => '0597000021', 'email' => 'ajax-retry@example.test' );
    $retry_url = 'https://ext.bhol.co.il/lead.php?' . http_build_query( $retry_query );
    $retry_ajax_a = retry_fault( $retry_busy, static function () use ( $retry_response, $retry_args, $retry_url ) { return LimuCRM\native_http_capture( $retry_response, 'response', 'synthetic', $retry_args, $retry_url ); } );
    $retry_ajax_source = $GLOBALS['lcrm_native_ajax_source'];
    $retry_query['cId'] = $retry_tag . '-cid-b';
    $retry_url_b = 'https://ext.bhol.co.il/lead.php?' . http_build_query( $retry_query );
    $retry_ajax_b = retry_fault( $retry_busy, static function () use ( $retry_response, $retry_args, $retry_url_b ) { return LimuCRM\native_http_capture( $retry_response, 'response', 'synthetic', $retry_args, $retry_url_b ); } );
    $retry_ajax_queues = retry_queue_ids( $retry_ajax_source );
    retry_check( is_wp_error( $retry_ajax_a ) && is_wp_error( $retry_ajax_b ) && 2 === count( $retry_ajax_queues ) && ! retry_deliveries( $retry_ajax_source ), 'Accepted AJAX calls persist private recipient queues before a busy CRM lock can reject capture' );
    retry_check( 1 === LimuCRM\capture_pending_count(), 'Two failed recipient captures of one AJAX submission count as one pending source' );
    $retry_ajax_original = retry_queue_envelope( $retry_ajax_queues[0] );
    retry_check( $retry_ajax_source === $retry_ajax_original['source'] && '0597000021' === $retry_ajax_original['lead']['phone'] && ! empty( $retry_ajax_original['date'] ), 'AJAX queues retain the original contact, timestamp and idempotency source' );
    retry_clear_request();
    retry_cron();
    $retry_ajax_rows = retry_deliveries( $retry_ajax_source );
    retry_check( 2 === count( $retry_ajax_rows ) && 1 === count( array_unique( array_column( $retry_ajax_rows, 'contact' ) ) ) && ! retry_queue_ids( $retry_ajax_source ), 'A worker recovers both AJAX recipients with one shared contact and removes committed queue records' );
    retry_check( $retry_ajax_original['date'] === $retry_ajax_rows[0]['date'] && 'sent' === $retry_ajax_rows[0]['state'], 'AJAX retry preserves its original live timestamp rather than substituting cron time' );
    retry_cron();
    retry_check( 2 === count( retry_deliveries( $retry_ajax_source ) ), 'A later AJAX retry sweep does not duplicate successful recipients' );

    $retry_bad_before = LimuCRM\capture_pending_count();
    $retry_bad_host = LimuCRM\native_http_capture( $retry_response, 'response', 'synthetic', $retry_args, str_replace( 'ext.bhol.co.il', 'untrusted.example', $retry_url ) );
    $retry_bad_status = LimuCRM\native_http_capture( array( 'response' => array( 'code' => 500 ) ), 'response', 'synthetic', $retry_args, $retry_url );
    retry_check( null === $retry_bad_host && null === $retry_bad_status && $retry_bad_before === LimuCRM\capture_pending_count(), 'Untrusted URLs and failed external transport do not become billable or queued accepted dispatches' );
    remove_filter( 'wp_doing_ajax', '__return_true' );

    // A frozen CID resolution survives a later delivery failure and a subsequent CID edit.
    $retry_ajax_frozen_source = 'ajax:' . wp_generate_uuid4();
    $retry_ajax_lead = array( 'name' => 'Frozen AJAX recipient', 'phone' => '0597000022', 'email' => 'frozen-ajax@example.test', 'form' => 'institution_interested', 'date' => $retry_date, 'origin_live' => true, 'capture_via' => 'site_ajax' );
    $retry_ajax_frozen_queue = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-cid-a', $retry_ajax_frozen_source );
    $retry_ajax_commits = 0;
    $retry_ajax_commit_failure = static function ( $query ) use ( &$retry_ajax_commits ) { return 'COMMIT' === $query && 2 === ++$retry_ajax_commits ? 'SELECT retry_missing_column FROM retry_missing_table' : $query; };
    $retry_ajax_frozen_result = retry_fault( $retry_ajax_commit_failure, static function () use ( $retry_ajax_frozen_queue ) { return LimuCRM\capture_retry_ajax_attempt( $retry_ajax_frozen_queue ); } );
    $retry_ajax_envelope = retry_queue_envelope( $retry_ajax_frozen_queue );
    retry_check( is_wp_error( $retry_ajax_frozen_result ) && $retry_a === ( $retry_ajax_envelope['institution'] ?? null ) && ! retry_deliveries( $retry_ajax_frozen_source ), 'AJAX recipient resolution commits independently while a failed delivery COMMIT retains the pending record' );
    update_post_meta( $retry_a, 'cid', $retry_tag . '-moved-a' );
    update_post_meta( $retry_b, 'cid', $retry_tag . '-cid-a' );
    $retry_ajax_frozen_success = LimuCRM\capture_retry_ajax_attempt( $retry_ajax_frozen_queue );
    retry_check( ! is_wp_error( $retry_ajax_frozen_success ) && $retry_a === $retry_ajax_frozen_success['institution'] && ! retry_queue_ids( $retry_ajax_frozen_source ), 'Changing CID mapping after failure cannot retarget the frozen accepted AJAX recipient' );

    // Source idempotency and the shared contact must consult committed data, never cached absence.
    foreach ( array( '', 'SELECT retry_missing_column FROM retry_missing_table' ) as $retry_broken_lookup ) {
        $retry_lookup_source = 'ajax:' . wp_generate_uuid4();
        $retry_lookup_queue = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-moved-a', $retry_lookup_source );
        $retry_lookup_hits = 0;
        $retry_lookup_fault = static function ( $query ) use ( $retry_lookup_source, $retry_broken_lookup, &$retry_lookup_hits ) {
            if ( 0 === strpos( $query, 'SELECT DISTINCT p.ID' ) && false !== strpos( $query, "'_lcrm_source'" ) && false !== strpos( $query, $retry_lookup_source ) ) {
                ++$retry_lookup_hits;
                return $retry_broken_lookup;
            }
            return $query;
        };
        $retry_lookup_result = retry_fault( $retry_lookup_fault, static function () use ( $retry_lookup_queue ) { return LimuCRM\capture_retry_ajax_attempt( $retry_lookup_queue ); } );
        retry_check( $retry_lookup_hits > 0 && is_wp_error( $retry_lookup_result ) && array( $retry_lookup_queue ) === retry_queue_ids( $retry_lookup_source ) && ! retry_deliveries( $retry_lookup_source ), 'A blocked or failed source-idempotency lookup retains its AJAX queue and cannot manufacture another delivery' );
        $retry_lookup_recovered = LimuCRM\capture_retry_ajax_attempt( $retry_lookup_queue );
        retry_check( ! is_wp_error( $retry_lookup_recovered ) && 1 === count( retry_deliveries( $retry_lookup_source ) ) && ! retry_queue_ids( $retry_lookup_source ), 'A source lookup failure can recover once after the database becomes available' );
    }
    $retry_ambiguous_row = LimuCRM\raw_records( array( $retry_ajax_frozen_success['id'] ) )[0];
    $retry_ambiguous_copy = LimuCRM\locked( static function () use ( $retry_ambiguous_row ) { return LimuCRM\save_record( 'lcrm_delivery', $retry_ambiguous_row ); } );
    $retry_ambiguous_queue = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-moved-a', $retry_ajax_frozen_source );
    $retry_ambiguous_result = LimuCRM\capture_retry_ajax_attempt( $retry_ambiguous_queue );
    retry_check( ! is_wp_error( $retry_ambiguous_copy ) && is_wp_error( $retry_ambiguous_result ) && 2 === count( retry_deliveries( $retry_ajax_frozen_source ) ) && array( $retry_ambiguous_queue ) === retry_queue_ids( $retry_ajax_frozen_source ), 'Two committed rows for the same source and recipient fail closed without adding a third or acknowledging pending work' );
    wp_delete_post( $retry_ambiguous_copy['id'], true );
    $retry_ambiguous_recovered = LimuCRM\capture_retry_ajax_attempt( $retry_ambiguous_queue );
    retry_check( ! is_wp_error( $retry_ambiguous_recovered ) && $retry_ajax_frozen_success['id'] === $retry_ambiguous_recovered['id'] && ! retry_queue_ids( $retry_ajax_frozen_source ), 'Repairing an ambiguous source resumes using the original recipient delivery' );
    $retry_stale_contact = LimuCRM\raw_records( array( $retry_ajax_frozen_success['contact'] ) )[0];
    $retry_poisoned_contact = $retry_stale_contact;
    $retry_poisoned_contact['phone'] = '0599999988';
    $retry_poisoned_contact['email'] = 'stale-contact@example.test';
    wp_cache_set( $retry_stale_contact['id'], array( '_lcrm_data' => array( maybe_serialize( $retry_poisoned_contact ) ) ), 'post_meta' );
    $retry_stale_contact_queue = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-cid-a', $retry_ajax_frozen_source );
    try { $retry_stale_contact_result = LimuCRM\capture_retry_ajax_attempt( $retry_stale_contact_queue ); } finally { wp_cache_delete( $retry_stale_contact['id'], 'post_meta' ); }
    retry_check( ! is_wp_error( $retry_stale_contact_result ) && $retry_stale_contact['id'] === $retry_stale_contact_result['contact'] && 2 === count( retry_deliveries( $retry_ajax_frozen_source ) ) && ! retry_queue_ids( $retry_ajax_frozen_source ), 'A stale cached shared contact cannot reject a valid second recipient or create a second contact' );

    // Counts are a fresh manager-only health indicator, independent of report filters.
    $retry_count_native = retry_source( $retry_title_a, $retry_month . '-12 10:00:00' );
    retry_fault( $retry_busy, static function () { return LimuCRM\native_flush(); } );
    $retry_count_ajax_source = 'ajax:' . wp_generate_uuid4();
    $retry_count_ajax_a = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-moved-a', $retry_count_ajax_source );
    $retry_count_ajax_b = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-cid-a', $retry_count_ajax_source );
    $retry_count_expected = LimuCRM\capture_pending_count();
    $retry_count_manager = retry_summary( $retry_admin->ID )->get_data();
    $retry_count_filtered = retry_summary( $retry_admin->ID, array( 'year' => '1900', 'institution' => $retry_b ) )->get_data();
    retry_check( 2 === $retry_count_expected && $retry_count_expected === $retry_count_manager['capture_pending'] && $retry_count_expected === $retry_count_filtered['capture_pending'], 'Manager backlog counts distinct sources across native and AJAX independently of period and institution filters' );
    wp_delete_post( $retry_count_ajax_a, true );
    wp_delete_post( $retry_count_ajax_b, true );
    retry_check( 1 === retry_summary( $retry_admin->ID )->get_data()['capture_pending'], 'Removing queued records changes the health count even when financial summaries are cached' );
    $retry_user = wp_create_user( 'retry-' . wp_generate_password( 12, false ), wp_generate_password( 48 ), 'retry-' . wp_generate_uuid4() . '@example.test' );
    if ( is_wp_error( $retry_user ) ) { throw new RuntimeException( 'Cannot create the read-only account.' ); }
    get_user_by( 'id', $retry_user )->add_cap( 'lcrm_view' );
    update_user_meta( $retry_user, '_lcrm_institutions', array( $retry_a ) );
    $retry_member = retry_summary( $retry_user );
    retry_check( 200 === $retry_member->get_status() && ! array_key_exists( 'capture_pending', $retry_member->get_data() ), 'Institution accounts never receive the global queued-source count' );
    retry_check( 403 === retry_summary( 0 )->get_status(), 'Anonymous callers cannot read the queued-source count or dashboard totals' );
    wp_set_current_user( $retry_admin->ID );
    retry_clear_request();
    retry_cron();

    // A persistent failure at the head must not starve later work or create an endless continuation.
    $retry_blocked_heads = array();
    for ( $retry_index = 0; $retry_index < LimuCRM\CAPTURE_RETRY_BATCH; ++$retry_index ) {
        $retry_blocked_heads[] = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-missing-' . $retry_index, 'ajax:' . wp_generate_uuid4() );
    }
    $retry_fair_source = 'ajax:' . wp_generate_uuid4();
    $retry_fair_queue = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-moved-a', $retry_fair_source );
    $retry_first_batch = retry_cron();
    $retry_first_cursor = LimuCRM\capture_retry_cursor();
    retry_check( ! is_wp_error( $retry_first_batch ) && $retry_first_batch['processed'] > 0 && $retry_first_batch['processed'] <= LimuCRM\CAPTURE_RETRY_BATCH && $retry_first_batch['active'] && $retry_fair_queue === $retry_first_cursor['max_id'] && ! retry_deliveries( $retry_fair_source ), 'A bounded batch advances beyond unresolved heads while freezing the upper source boundary' );
    $retry_new_source = 'ajax:' . wp_generate_uuid4();
    $retry_new_queue = LimuCRM\capture_retry_ajax_enqueue( $retry_ajax_lead, $retry_tag . '-moved-a', $retry_new_source );
    wp_cache_set( 'lcrm_capture_retry_cursor', array( 'version' => 1, 'cursor' => 0, 'max_id' => 0, 'active' => false ), 'options' );
    $retry_continue = array( 'active' => true );
    for ( $retry_round = 0; $retry_round < LimuCRM\CAPTURE_RETRY_BATCH + 3 && $retry_continue['active']; ++$retry_round ) {
        $retry_continue = retry_cron( true );
        if ( is_wp_error( $retry_continue ) ) { throw new RuntimeException( 'The fair continuation failed.' ); }
    }
    retry_check( ! $retry_continue['active'] && 1 === count( retry_deliveries( $retry_fair_source ) ) && ! retry_queue_ids( $retry_fair_source ) && array( $retry_new_queue ) === retry_queue_ids( $retry_new_source ), 'Continuation uses fresh cursor state, reaches an eligible tail and defers sources beyond the frozen maximum' );
    $retry_no_sweep = retry_cron( true );
    retry_check( ! is_wp_error( $retry_no_sweep ) && 0 === $retry_no_sweep['processed'] && false === $retry_no_sweep['active'] && ! retry_deliveries( $retry_new_source ), 'A stale continuation cannot open a new sweep around permanently unresolved sources' );
    $retry_next_sweep = retry_cron();
    for ( $retry_round = 0; $retry_round < LimuCRM\CAPTURE_RETRY_BATCH + 3 && ! is_wp_error( $retry_next_sweep ) && $retry_next_sweep['active']; ++$retry_round ) { $retry_next_sweep = retry_cron( true ); }
    retry_check( ! is_wp_error( $retry_next_sweep ) && ! $retry_next_sweep['active'] && 1 === count( retry_deliveries( $retry_new_source ) ) && LimuCRM\CAPTURE_RETRY_BATCH === LimuCRM\capture_pending_count(), 'The next hourly sweep reaches newly queued work despite a full batch of permanent earlier failures' );
    foreach ( $retry_blocked_heads as $retry_head ) { wp_delete_post( $retry_head, true ); }

    // Approval is immutable even when a previously missing live capture is retried later.
    $retry_exclusion = new WP_REST_Request( 'POST', '/limu-crm/v1/billing-exclusion' );
    $retry_exclusion->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $retry_exclusion->set_header( 'Content-Type', 'application/json' );
    $retry_exclusion->set_body( wp_json_encode( array( 'id' => $retry_one_rows[0]['id'], 'excluded' => true ) ) );
    retry_check( 200 === rest_do_request( $retry_exclusion )->get_status(), 'A manager can exclude a committed lead before approval for stale-cache replay coverage' );
    $retry_bill = LimuCRM\locked( static function () use ( $retry_a, $retry_month ) {
        $draft = LimuCRM\prepare_bill( $retry_a, $retry_month );
        return is_wp_error( $draft ) ? $draft : LimuCRM\approve_bill( $draft['id'] );
    } );
    retry_check( ! is_wp_error( $retry_bill ) && 'approved' === $retry_bill['state'], 'A genuine approved bill protects the retry test period' );
    $retry_cache_absence = static function ( $posts, $query ) {
        return false !== strpos( wp_json_encode( $query->query_vars ), '_lcrm_source' ) ? array() : $posts;
    };
    foreach ( array( $retry_early_rows[0]['id'], $retry_one_rows[0]['id'] ) as $retry_protected_id ) {
        $retry_committed = LimuCRM\raw_records( array( $retry_protected_id ) )[0];
        $retry_stale_financial = $retry_committed;
        $retry_stale_financial['bill'] = 0;
        $retry_stale_financial['billing_excluded'] = false;
        wp_cache_set( $retry_protected_id, array( '_lcrm_data' => array( maybe_serialize( $retry_stale_financial ) ) ), 'post_meta' );
        add_filter( 'posts_pre_query', $retry_cache_absence, 10, 2 );
        try {
            $retry_replayed = LimuCRM\locked( static function () use ( $retry_committed ) {
                return LimuCRM\record_delivery( array( 'name' => 'Synthetic immutable replay', 'phone' => $retry_committed['phone_key'], 'email' => $retry_committed['email_key'], 'form' => 'Capture retry fixture', 'date' => $retry_committed['date'] ), $retry_committed['institution'], $retry_committed['source'] );
            } );
        } finally {
            remove_filter( 'posts_pre_query', $retry_cache_absence, 10 );
            wp_cache_delete( $retry_protected_id, 'post_meta' );
        }
        retry_check( ! is_wp_error( $retry_replayed ) && $retry_committed['id'] === $retry_replayed['id'] && $retry_committed['bill'] === $retry_replayed['bill'] && ( $retry_committed['billing_excluded'] ?? false ) === ( $retry_replayed['billing_excluded'] ?? false ) && $retry_committed === LimuCRM\raw_records( array( $retry_protected_id ) )[0] && 1 === count( retry_deliveries( $retry_committed['source'] ) ), 'Stale cached source absence and metadata cannot duplicate a replay or erase its approved bill or exclusion' );
    }
    $retry_late = retry_source( $retry_title_a, $retry_month . '-13 10:00:00' );
    $retry_late_result = LimuCRM\native_flush();
    retry_clear_request();
    retry_cron();
    retry_check( is_wp_error( $retry_late_result[ $retry_late ] ) && is_array( retry_fresh_marker( $retry_late ) ) && ! retry_deliveries( 'legacy:' . $retry_late ) && $retry_bill === LimuCRM\raw_records( array( $retry_bill['id'] ) )[0], 'A retry in an approved month stays pending and cannot silently change the bill or create a late delivery' );

    $retry_lock_calls = 0;
    $retry_waits = array();
    $retry_cron_busy = static function ( $query ) use ( &$retry_lock_calls, &$retry_waits, $retry_financial_lock ) {
        if ( false !== strpos( $query, 'GET_LOCK(' ) && false !== strpos( $query, $retry_financial_lock ) ) {
            ++$retry_lock_calls;
            preg_match( '/,\s*(\d+)\s*\)/', $query, $match );
            $retry_waits[] = isset( $match[1] ) ? (int) $match[1] : -1;
            return 'SELECT 0';
        }
        return $query;
    };
    retry_fault( $retry_cron_busy, static function () { return retry_cron(); } );
    retry_check( 1 === $retry_lock_calls && array( 0 ) === $retry_waits && is_array( retry_fresh_marker( $retry_late ) ), 'A busy worker tries the business lock without waiting and aborts its batch after the first contention' );
    wp_delete_post( $retry_late, true );
    retry_check( 0 === LimuCRM\capture_pending_count(), 'Deleting a pending source removes it from the manager backlog without inventing a delivery' );
    retry_check( 0 === $retry_http_calls, 'Retry processing makes no external delivery, email or fiscal HTTP calls' );
    echo "OK $retry_passed durable capture retry assertions\n";
} catch ( Throwable $exception ) {
    $retry_failure = $exception;
    fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" );
} finally {
    remove_filter( 'wp_doing_ajax', '__return_true' );
    remove_filter( 'wp_doing_cron', '__return_true' );
    remove_filter( 'pre_http_request', $retry_no_http );
    remove_action( 'wp_after_insert_post', $retry_track, 99 );
    retry_clear_request();
    wp_set_current_user( $retry_admin->ID ?? $retry_original_user );
    foreach ( array_reverse( array_unique( $retry_created ) ) as $retry_id ) { wp_delete_post( $retry_id, true ); }
    if ( $retry_user && ! is_wp_error( $retry_user ) ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $retry_user );
    }
    foreach ( $retry_options as $retry_option => $retry_value ) {
        if ( null === $retry_value ) { delete_option( $retry_option ); } else { update_option( $retry_option, $retry_value, false ); }
    }
    foreach ( array_keys( $GLOBALS ) as $retry_key ) {
        if ( 0 === strpos( $retry_key, 'lcrm_native_' ) || 0 === strpos( $retry_key, 'lcrm_capture_' ) ) { unset( $GLOBALS[ $retry_key ] ); }
    }
    foreach ( $retry_globals as $retry_key => $retry_value ) { $GLOBALS[ $retry_key ] = $retry_value; }
    if ( null === $retry_original_action ) { unset( $_REQUEST['action'] ); } else { $_REQUEST['action'] = $retry_original_action; }
    wp_set_current_user( $retry_original_user );
}
if ( $retry_failure ) { exit( 1 ); }
