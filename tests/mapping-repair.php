<?php
/** Historical-only mapping upgrade checks using synthetic data in the disposable database. */
$root = getenv( 'LIMU_WP_ROOT' );
if ( ! $root ) { fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" ); exit( 1 ); }
require $root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) { fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" ); exit( 1 ); }
register_post_type( 'institutions', array( 'public' => false ) );
register_post_type( 'leads', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$passed = 0;
$created = array();
$sequence = 0;
$failure = null;
$fault = null;
$query_fault = null;
$trash_fault = null;
$posts_fault = null;
$original_state = get_option( 'lcrm_mapping_repair', null );
$original_settings = get_option( 'lcrm_settings', null );
$original_cron = get_option( 'cron', array() );
$original_user = get_current_user_id();
$original_native_new = $GLOBALS['lcrm_native_new_leads'] ?? null;
$original_native_queue = $GLOBALS['lcrm_native_queue'] ?? null;
$tracker = function ( $id, $post, $update ) use ( &$created ) { if ( ! $update ) { $created[] = $id; } };
add_action( 'wp_insert_post', $tracker, 99, 3 );
$external_calls = 0;
$deny_http = function () use ( &$external_calls ) { ++$external_calls; return new WP_Error( 'repair_no_external_http', 'Unexpected external HTTP in historical repair test.' ); };
add_filter( 'pre_http_request', $deny_http );

function repair_check( $condition, $label ) {
    global $passed;
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$passed;
    echo "PASS $label\n";
}
function repair_exception( $value, $overrides = array() ) {
    global $sequence;
    ++$sequence;
    $lead = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_title' => 'Historical repair source fixture', 'post_date' => '2019-04-15 10:00:00' ) );
    foreach ( array( 'institution' => $value, 'full-name' => 'Historical repair contact', 'phone' => '058' . sprintf( '%07d', $sequence ), 'email' => 'repair-' . $sequence . '@example.test' ) as $key => $field ) { update_post_meta( $lead, $key, $field ); }
    $payload = array_merge( array(
        'source' => 'legacy:' . $lead, 'contact' => $lead, 'legacy_id' => $lead,
        'institution' => 0, 'bill' => 0, 'state' => 'unmapped', 'origin_live' => false,
        'date' => '2019-04-15 10:00:00', 'month' => '2019-04',
        'phone_key' => '058' . sprintf( '%07d', $sequence ), 'email_key' => 'repair-' . $sequence . '@example.test',
        'duplicate_of' => 0, 'duplicate_mode' => 'days:47', 'form' => 'Historical fixture',
        'treatment' => 'working', 'notes' => array( array( 'text' => 'Preserve C:\\fixture\\note', 'actor' => 0, 'at' => '2019-04-16 12:00:00' ) ),
    ), $overrides );
    $item = LimuCRM\locked( function () use ( $payload ) { return LimuCRM\save_record( 'lcrm_delivery', $payload ); } );
    if ( is_wp_error( $item ) ) { throw new RuntimeException( 'Unable to save historical exception fixture.' ); }
    return array( 'id' => $item['id'], 'lead' => $lead, 'payload' => get_post_meta( $item['id'], '_lcrm_data', true ) );
}
function repair_cursor( $minimum, $maximum ) {
    $state = array( 'version' => LimuCRM\MAPPING_REPAIR_VERSION, 'cursor' => $minimum, 'max_id' => $maximum, 'done' => false, 'processed' => 0, 'repaired' => 0, 'deliveries' => 0, 'unresolved' => 0 );
    update_option( 'lcrm_mapping_repair', $state, false );
    return $state;
}
function repair_recipients( $source ) {
    $ids = get_posts( array( 'post_type' => 'lcrm_delivery', 'post_status' => 'private', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lcrm_source', 'meta_value' => $source ) );
    return array_map( static fn( $id ) => get_post_meta( $id, '_lcrm_data', true ), $ids );
}

try {
    $admin = get_user_by( 'login', 'crm-admin' );
    if ( ! $admin ) { throw new RuntimeException( 'Run integration.php first to create the disposable administrator.' ); }
    wp_set_current_user( $admin->ID );
    $before_guard = get_option( 'lcrm_mapping_repair' );
    $guarded = LimuCRM\mapping_repair_run();
    repair_check( is_wp_error( $guarded ) && 403 === $guarded->get_error_data()['status'] && $before_guard === get_option( 'lcrm_mapping_repair' ), 'Ordinary requests cannot execute the cron-only repair or move its cursor' );
    repair_check( 404 === rest_do_request( new WP_REST_Request( 'POST', '/limu-crm/v1/mapping-repair' ) )->get_status(), 'Historical repair has no public REST endpoint' );

    delete_option( 'lcrm_mapping_repair' );
    $query_fault = static function ( $query ) { return false !== strpos( $query, 'SELECT COALESCE(MAX(ID), 0)' ) ? 'SELECT * FROM lcrm_repair_missing_table' : $query; };
    add_filter( 'query', $query_fault );
    $snapshot_failure = LimuCRM\mapping_repair_state();
    remove_filter( 'query', $query_fault );
    $query_fault = null;
    repair_check( is_wp_error( $snapshot_failure ) && 500 === $snapshot_failure->get_error_data()['status'] && null === get_option( 'lcrm_mapping_repair', null ), 'A failed upper-bound query cannot permanently mark historical repair complete' );

    $tag = 'repair-' . wp_generate_uuid4();
    $a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $tag . ' A' ) );
    $b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'draft', 'post_title' => $tag . ' B' ) );
    update_option( 'lcrm_settings', array( 'duplicate_mode' => 'calendar', 'duplicate_days' => 30, 'automatic' => false, 'start_date' => '2019-04-01' ), false );
    update_post_meta( $a, '_lcrm_agreement', array( 'credit_days' => 30, 'rates' => array( array( 'from' => '2019-04-01', 'price' => 1000, 'vat_bp' => 1800 ) ) ) );
    $anchor = LimuCRM\locked( function () use ( $a, $tag ) { return LimuCRM\record_delivery( array( 'name' => 'Approved bill fixture', 'phone' => '0598876100', 'email' => '', 'date' => '2019-04-05 10:00:00', 'form' => 'Approved bill fixture' ), $a, $tag . ':approved-anchor' ); } );
    $draft = LimuCRM\locked( function () use ( $a ) { return LimuCRM\prepare_bill( $a, '2019-04' ); } );
    $approved = LimuCRM\locked( function () use ( $draft ) { return LimuCRM\approve_bill( $draft['id'] ); } );
    repair_check( ! is_wp_error( $approved ) && 'approved' === $approved['state'], 'An actual approved billing snapshot protects the historical repair test period' );

    $shared = repair_exception( get_post_field( 'post_title', $a, 'raw' ) . ', ' . get_post_field( 'post_title', $b, 'raw' ) );
    // Source corrections must not rewrite the captured event date or duplicate keys.
    update_post_meta( $shared['lead'], 'phone', '0529999922' );
    update_post_meta( $shared['lead'], 'email', 'edited-contact@example.test' );
    wp_update_post( array( 'ID' => $shared['lead'], 'post_date' => '2020-09-01 11:30:00' ) );
    $initial = repair_cursor( $shared['id'] - 1, $shared['id'] );
    add_filter( 'wp_doing_cron', '__return_true' );
    wp_set_current_user( 0 );
    foreach ( array( 'batch_metadata', 'source_post', 'source_metadata' ) as $read_phase ) {
        clean_post_cache( $shared['lead'] );
        wp_cache_delete( $shared['id'], 'post_meta' );
        $read_failures = 0;
        $query_fault = static function ( $query ) use ( $shared, $read_phase, &$read_failures ) {
            $metadata_id = 'batch_metadata' === $read_phase ? $shared['id'] : $shared['lead'];
            $matches = 'source_post' === $read_phase ? preg_match( '/\\bID\\s*=\\s*' . $shared['lead'] . '\\b/', $query ) : false !== strpos( $query, 'post_id IN' ) && false !== strpos( $query, 'postmeta' ) && preg_match( '/\\b' . $metadata_id . '\\b/', $query );
            if ( ! $read_failures && $matches && false !== stripos( $query, 'SELECT' ) ) {
                ++$read_failures;
                return 'SELECT * FROM lcrm_repair_missing_table';
            }
            return $query;
        };
        add_filter( 'query', $query_fault );
        $read_failure = LimuCRM\mapping_repair_run();
        remove_filter( 'query', $query_fault );
        $query_fault = null;
        repair_check( 1 === $read_failures && is_wp_error( $read_failure ) && 500 === $read_failure->get_error_data()['status'] && $initial === get_option( 'lcrm_mapping_repair' ) && 'private' === get_post_status( $shared['id'] ), 'A transient ' . $read_phase . ' read failure leaves the exception and cursor retryable instead of treating it as unresolved' );
    }
    clean_post_cache( $shared['lead'] );
    wp_cache_delete( $shared['id'], 'post_meta' );
    $fault = static function ( $check, $id, $key, $value ) use ( $b, $shared ) { return '_lcrm_data' === $key && is_array( $value ) && $b === ( $value['institution'] ?? 0 ) && $shared['payload']['source'] === ( $value['source'] ?? '' ) ? false : $check; };
    add_filter( 'update_post_metadata', $fault, 10, 4 );
    $failed = LimuCRM\mapping_repair_run();
    remove_filter( 'update_post_metadata', $fault, 10 );
    $fault = null;
    repair_check( is_wp_error( $failed ) && $initial === get_option( 'lcrm_mapping_repair' ) && 'private' === get_post_status( $shared['id'] ) && 1 === count( repair_recipients( $shared['payload']['source'] ) ), 'A second-recipient persistence failure rolls back the first recipient, exception status and repair cursor' );
    $result = LimuCRM\mapping_repair_run();
    $recipients = repair_recipients( $shared['payload']['source'] );
    repair_check( ! is_wp_error( $result ) && true === $result['done'] && 1 === $result['repaired'] && 2 === $result['deliveries'] && 'trash' === get_post_status( $shared['id'] ) && 2 === count( $recipients ), 'A safe retry creates both exact recipients and closes the exception once' );
    foreach ( $recipients as $recipient ) {
        foreach ( array( 'source', 'contact', 'legacy_id', 'date', 'month', 'phone_key', 'email_key', 'duplicate_mode', 'notes', 'treatment' ) as $key ) {
            repair_check( $shared['payload'][ $key ] === $recipient[ $key ], 'Historical recipient preserves the captured ' . $key );
        }
        repair_check( 'historical' === $recipient['state'] && 0 === $recipient['bill'] && false === $recipient['origin_live'], 'Repair preserves historical-only classification without a bill or live promotion' );
    }
    repair_check( $approved === LimuCRM\data( $approved['id'] ) && $anchor['id'] === LimuCRM\data( $anchor['id'] )['id'], 'Repair in an approved service month leaves the approved bill and its original line unchanged' );
    repair_check( $result === LimuCRM\mapping_repair_run() && 2 === count( repair_recipients( $shared['payload']['source'] ) ), 'A completed cron retry is idempotent' );

    $closing = repair_exception( get_post_field( 'post_title', $a, 'raw' ) );
    $closing_initial = repair_cursor( $closing['id'] - 1, $closing['id'] );
    $trash_fault = static function ( $trash, $post ) use ( $closing ) { return $closing['id'] === $post->ID ? false : $trash; };
    add_filter( 'pre_trash_post', $trash_fault, 10, 2 );
    $closing_failure = LimuCRM\mapping_repair_run();
    remove_filter( 'pre_trash_post', $trash_fault, 10 );
    $trash_fault = null;
    repair_check( is_wp_error( $closing_failure ) && $closing_initial === get_option( 'lcrm_mapping_repair' ) && 'private' === get_post_status( $closing['id'] ) && 1 === count( repair_recipients( $closing['payload']['source'] ) ), 'Failure to close the exception rolls back all new recipients and leaves a retryable cursor' );

    $query_fault = static function ( $query ) { return 'COMMIT' === $query ? 'SELECT * FROM lcrm_repair_missing_table' : $query; };
    add_filter( 'query', $query_fault );
    $commit_failure = LimuCRM\mapping_repair_run();
    remove_filter( 'query', $query_fault );
    $query_fault = null;
    repair_check( is_wp_error( $commit_failure ) && $closing_initial === get_option( 'lcrm_mapping_repair' ) && 'private' === get_post_status( $closing['id'] ) && 1 === count( repair_recipients( $closing['payload']['source'] ) ), 'Commit failure invalidates the advanced option/post caches as well as rolling back their database writes' );
    repair_check( 1 === LimuCRM\mapping_repair_run()['repaired'] && 'trash' === get_post_status( $closing['id'] ), 'A failed commit can be retried without losing or duplicating the historical mapping' );

    $blocked = array();
    foreach ( array( array( 'origin_live' => true ), array( 'bill' => $approved['id'] ), array( 'contact' => 0 ), array( 'source' => 'not-a-legacy-source' ), array( 'month' => '2019-05' ), array( 'phone_key' => '052-999-9922' ), array( 'duplicate_mode' => 'invalid-policy' ) ) as $override ) {
        $blocked[] = repair_exception( get_post_field( 'post_title', $a, 'raw' ), $override );
    }
    $blocked[] = repair_exception( $tag . ' unresolved source' );
    $trash_school = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'trash', 'post_title' => $tag . ' trash school' ) );
    $blocked[] = repair_exception( (string) $trash_school );
    $active_conflict = repair_exception( get_post_field( 'post_title', $a, 'raw' ) );
    $other = $active_conflict['payload'];
    $other['institution'] = $a;
    $other['state'] = 'sent';
    $other['origin_live'] = true;
    $other_saved = LimuCRM\locked( function () use ( $other ) { return LimuCRM\save_record( 'lcrm_delivery', $other ); } );
    $blocked[] = $active_conflict;
    $outside_relation = repair_exception( get_post_field( 'post_title', $a, 'raw' ) );
    $wrong = $outside_relation['payload'];
    $wrong['institution'] = $b;
    $wrong['state'] = 'historical';
    LimuCRM\locked( function () use ( $wrong ) { return LimuCRM\save_record( 'lcrm_delivery', $wrong ); } );
    $blocked[] = $outside_relation;
    repair_cursor( $blocked[0]['id'] - 1, $outside_relation['id'] );
    $blocked_result = LimuCRM\mapping_repair_run();
    foreach ( $blocked as $blocked_item ) {
        repair_check( 'private' === get_post_status( $blocked_item['id'] ) && $blocked_item['payload'] === get_post_meta( $blocked_item['id'], '_lcrm_data', true ), 'Unsafe, malformed, ambiguous or conflicting historical sources stay unchanged: ' . $blocked_item['id'] );
    }
    repair_check( ! is_wp_error( $blocked_result ) && 0 === $blocked_result['repaired'] && $other_saved === LimuCRM\data( $other_saved['id'] ), 'Repair cannot change a source already represented by an active recipient' );

    $lookup_initial = repair_cursor( $active_conflict['id'] - 1, $active_conflict['id'] );
    $lookup_failures = 0;
    clean_post_cache( $active_conflict['id'] );
    $query_fault = static function ( $query ) use ( $active_conflict, &$lookup_failures ) {
        if ( ! $lookup_failures && false !== strpos( $query, '_lcrm_source' ) && false !== strpos( $query, $active_conflict['payload']['source'] ) && false !== stripos( $query, 'SELECT' ) ) {
            ++$lookup_failures;
            return 'SELECT * FROM lcrm_repair_missing_table';
        }
        return $query;
    };
    add_filter( 'query', $query_fault );
    $lookup_failure = LimuCRM\mapping_repair_run();
    remove_filter( 'query', $query_fault );
    $query_fault = null;
    repair_check( 1 === $lookup_failures && is_wp_error( $lookup_failure ) && $lookup_initial === get_option( 'lcrm_mapping_repair' ) && $other_saved === LimuCRM\data( $other_saved['id'] ) && 'private' === get_post_status( $active_conflict['id'] ), 'A failed existing-recipient query cannot treat the source as new or modify a live recipient' );
    clean_post_cache( $active_conflict['id'] );
    $posts_fault = static function ( $posts, $query ) use ( $active_conflict ) { return '_lcrm_source' === $query->get( 'meta_key' ) && $active_conflict['payload']['source'] === $query->get( 'meta_value' ) ? array() : $posts; };
    add_filter( 'posts_pre_query', $posts_fault, 10, 2 );
    $postflight_failure = LimuCRM\mapping_repair_run();
    remove_filter( 'posts_pre_query', $posts_fault, 10 );
    $posts_fault = null;
    repair_check( is_wp_error( $postflight_failure ) && 409 === $postflight_failure->get_error_data()['status'] && $lookup_initial === get_option( 'lcrm_mapping_repair' ) && $other_saved === LimuCRM\data( $other_saved['id'] ), 'Postflight validation refuses a live recipient returned by the storage adapter even if the initial conflict lookup missed it' );

    $partial = repair_exception( get_post_field( 'post_title', $a, 'raw' ) . ', ' . get_post_field( 'post_title', $b, 'raw' ) );
    $partial_payload = $partial['payload'];
    $partial_payload['state'] = 'historical';
    $partial_payload['institution'] = $a;
    $partial_existing = LimuCRM\locked( function () use ( $partial_payload ) { return LimuCRM\save_record( 'lcrm_delivery', $partial_payload ); } );
    repair_cursor( $partial['id'] - 1, $partial['id'] );
    $partial_result = LimuCRM\mapping_repair_run();
    repair_check( 1 === $partial_result['repaired'] && 1 === $partial_result['deliveries'] && 2 === count( repair_recipients( $partial['payload']['source'] ) ) && $partial_existing === LimuCRM\data( $partial_existing['id'] ), 'An exact existing historical recipient is reused unchanged while only the missing recipient is created' );

    $missing_policy = repair_exception( get_post_field( 'post_title', $a, 'raw' ) );
    $without = $missing_policy['payload'];
    unset( $without['duplicate_mode'], $without['notes'], $without['treatment'] );
    LimuCRM\locked( function () use ( $without, $missing_policy ) { return LimuCRM\save_record( 'lcrm_delivery', $without, $missing_policy['id'] ); } );
    repair_cursor( $missing_policy['id'] - 1, $missing_policy['id'] );
    $absent_result = LimuCRM\mapping_repair_run();
    $absent_recipient = repair_recipients( $missing_policy['payload']['source'] )[0];
    repair_check( 1 === $absent_result['repaired'] && ! array_key_exists( 'duplicate_mode', $absent_recipient ) && ! array_key_exists( 'notes', $absent_recipient ) && ! array_key_exists( 'treatment', $absent_recipient ), 'Older historical exceptions do not gain a fabricated prior duplicate policy or lose their original field absence' );

    for ( $index = 0; $index < 52; ++$index ) { repair_exception( $tag . ' unknown batch ' . $index ); }
    delete_option( 'lcrm_mapping_repair' );
    $frozen = LimuCRM\mapping_repair_state();
    $later = repair_exception( get_post_field( 'post_title', $a, 'raw' ) );
    $first_batch = LimuCRM\mapping_repair_run();
    repair_check( 50 === $first_batch['processed'] && false === $first_batch['done'] && $frozen['max_id'] === $first_batch['max_id'], 'Each background batch scans at most 50 existing exceptions using a frozen upper ID' );
    for ( $attempt = 0; $attempt < 10 && empty( $first_batch['done'] ); ++$attempt ) { $first_batch = LimuCRM\mapping_repair_run(); }
    repair_check( true === $first_batch['done'] && $first_batch['cursor'] <= $frozen['max_id'] && 'private' === get_post_status( $later['id'] ), 'Later exceptions beyond the frozen boundary are not silently included in the upgrade repair' );
    repair_check( ! wp_next_scheduled( 'lcrm_mapping_repair' ), 'A completed migration removes its pending background event' );
    $audit_ids = get_posts( array( 'post_type' => 'lcrm_audit', 'post_status' => 'private', 'post__in' => $created, 'posts_per_page' => -1, 'fields' => 'ids' ) );
    $repair_audits = array_filter( array_map( 'LimuCRM\\data', $audit_ids ), static fn( $item ) => 'historical_mapping_repaired' === ( $item['event'] ?? '' ) );
    repair_check( $repair_audits && ! array_filter( $repair_audits, static fn( $item ) => array_diff( array_keys( $item['details'] ), array( 'processed', 'repaired', 'deliveries', 'unresolved' ) ) || 0 !== $item['actor'] ), 'Cron repair audit stores only anonymous batch counts without source IDs or contact details' );
    repair_check( 0 === $external_calls, 'Historical repair performs no external HTTP, messages or fiscal document requests' );
    $corrupt = $first_batch;
    $corrupt['cursor'] = 'invalid';
    update_option( 'lcrm_mapping_repair', $corrupt, false );
    repair_check( is_wp_error( LimuCRM\mapping_repair_run() ) && $corrupt === get_option( 'lcrm_mapping_repair' ), 'Corrupt saved cursor state fails closed without inventing a new boundary' );
    echo "OK $passed historical mapping repair assertions\n";
} catch ( Throwable $exception ) {
    $failure = $exception;
    fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" );
} finally {
    if ( $fault ) { remove_filter( 'update_post_metadata', $fault, 10 ); }
    if ( $query_fault ) { remove_filter( 'query', $query_fault ); }
    if ( $trash_fault ) { remove_filter( 'pre_trash_post', $trash_fault, 10 ); }
    if ( $posts_fault ) { remove_filter( 'posts_pre_query', $posts_fault, 10 ); }
    remove_filter( 'wp_doing_cron', '__return_true' );
    remove_filter( 'pre_http_request', $deny_http );
    remove_action( 'wp_insert_post', $tracker, 99 );
    wp_set_current_user( $admin->ID ?? $original_user );
    foreach ( array_reverse( array_unique( $created ) ) as $id ) { wp_delete_post( $id, true ); }
    if ( null === $original_state ) { delete_option( 'lcrm_mapping_repair' ); } else { update_option( 'lcrm_mapping_repair', $original_state, false ); }
    if ( null === $original_settings ) { delete_option( 'lcrm_settings' ); } else { update_option( 'lcrm_settings', $original_settings, false ); }
    update_option( 'cron', $original_cron );
    if ( null === $original_native_new ) { unset( $GLOBALS['lcrm_native_new_leads'] ); } else { $GLOBALS['lcrm_native_new_leads'] = $original_native_new; }
    if ( null === $original_native_queue ) { unset( $GLOBALS['lcrm_native_queue'] ); } else { $GLOBALS['lcrm_native_queue'] = $original_native_queue; }
    wp_set_current_user( $original_user );
}
if ( $failure ) { exit( 1 ); }
