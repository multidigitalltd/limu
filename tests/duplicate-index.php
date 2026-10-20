<?php
/** Exact indexed duplicate lookup and bounded migration in the disposable test database. */
$root = getenv( 'LIMU_WP_ROOT' );
if ( ! $root ) { fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" ); exit( 1 ); }
require $root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) { fwrite( STDERR, "Refusing to run outside limu_test.\n" ); exit( 1 ); }
register_post_type( 'institutions', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$passed = 0;
$created = array();
$sequence = 0;
$tag = 'duplicate-index-' . wp_generate_uuid4();
$original_state = get_option( 'lcrm_duplicate_index', null );
$original_cron = get_option( 'cron', array() );
$original_user = get_current_user_id();
$original_settings = get_option( 'lcrm_settings', null );
$failure = null;
$write_fault = null;
$query_fault = null;
$cursor_fault = null;
$http_calls = 0;
$track = static function ( $id, $post, $update ) use ( &$created ) { if ( ! $update ) { $created[] = $id; } };
add_action( 'wp_insert_post', $track, 99, 3 );
$no_http = static function () use ( &$http_calls ) { ++$http_calls; return new WP_Error( 'duplicate_index_no_http', 'Unexpected external request.' ); };
add_filter( 'pre_http_request', $no_http );

function index_check( $condition, $label ) {
    global $passed;
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$passed;
    echo "PASS $label\n";
}
function index_capture( $institution, $date, $phone, $email = '', $historical = true ) {
    global $tag, $sequence;
    $source = $tag . ':' . ++$sequence;
    $result = LimuCRM\locked( static function () use ( $institution, $date, $phone, $email, $historical, $source ) {
        return LimuCRM\record_delivery( array( 'name' => 'Synthetic indexed contact', 'phone' => $phone, 'email' => $email, 'date' => $date, 'form' => 'Indexed fixture' ), $institution, $source, $historical );
    } );
    if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Unable to create indexed fixture: ' . $result->get_error_message() ); }
    return $result;
}
function index_cursor( $first, $last, $done = false ) {
    $state = array( 'version' => LimuCRM\DUPLICATE_INDEX_VERSION, 'cursor' => $done ? $last : $first - 1, 'max_id' => $last, 'processed' => 0, 'done' => $done );
    update_option( 'lcrm_duplicate_index', $state, false );
    return $state;
}
function index_ready( $last ) { return index_cursor( 1, $last, true ); }

try {
    $admin = get_user_by( 'login', 'crm-admin' );
    if ( ! $admin || ! user_can( $admin, 'manage_options' ) ) { throw new RuntimeException( 'Run integration.php first.' ); }
    wp_set_current_user( $admin->ID );
    $before_guard = get_option( 'lcrm_duplicate_index', null );
    $guard = LimuCRM\duplicate_index_run();
    index_check( is_wp_error( $guard ) && 403 === $guard->get_error_data()['status'] && $before_guard === get_option( 'lcrm_duplicate_index', null ), 'Backfill cannot run in an ordinary visitor request' );
    index_check( 404 === rest_do_request( new WP_REST_Request( 'POST', '/limu-crm/v1/duplicate-index' ) )->get_status(), 'The private index job has no public mutation endpoint' );
    index_check( ! LimuCRM\duplicate_index_valid_state( array( 'version' => 1, 'cursor' => 20, 'max_id' => 10, 'processed' => 0, 'done' => true ) ) && ! LimuCRM\duplicate_index_valid_state( array( 'version' => 1, 'cursor' => 1, 'max_id' => 2, 'processed' => 0, 'done' => true ) ), 'Invalid and prematurely completed cursor boundaries are rejected' );
    delete_option( 'lcrm_duplicate_index' );
    global $wpdb;
    $query_fault = static function ( $query ) { return false !== strpos( $query, 'SELECT COALESCE(MAX(ID), 0)' ) ? 'SELECT missing_index_fixture_column FROM invalid_index_fixture_table' : $query; };
    $old_suppression = $wpdb->suppress_errors( true );
    add_filter( 'query', $query_fault );
    $failed_boundary = LimuCRM\duplicate_index_state();
    remove_filter( 'query', $query_fault ); $query_fault = null;
    $wpdb->suppress_errors( $old_suppression ); $wpdb->last_error = '';
    index_check( is_wp_error( $failed_boundary ) && 500 === $failed_boundary->get_error_data()['status'] && null === get_option( 'lcrm_duplicate_index', null ), 'A failed upper-bound query cannot permanently mark the index complete' );
    $query_fault = static function ( $query ) { return false !== strpos( $query, 'SELECT COALESCE(MAX(ID), 0)' ) ? '' : $query; };
    add_filter( 'query', $query_fault );
    $blocked_boundary = LimuCRM\duplicate_index_state();
    remove_filter( 'query', $query_fault ); $query_fault = null;
    index_check( is_wp_error( $blocked_boundary ) && 500 === $blocked_boundary->get_error_data()['status'] && null === get_option( 'lcrm_duplicate_index', null ), 'A silently blocked upper-bound query cannot declare the index empty and complete' );
    $a = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $tag . ' institution A' ) );
    $b = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $tag . ' institution B' ) );
    delete_option( 'lcrm_duplicate_index' );
    $anchor = index_capture( $a, '2010-01-01 10:00:00', '0591122334', 'index-a@example.invalid' );
    $alias = index_capture( $a, '2024-01-01 10:00:00', '0591122334', 'index-b@example.invalid' );
    $chain = index_capture( $a, '2024-02-01 10:00:00', '0591122335', 'index-b@example.invalid' );
    $foreign = index_capture( $b, '2010-01-01 10:00:00', '0591122334', 'index-a@example.invalid' );
    index_check( $anchor['id'] === $alias['duplicate_of'] && $anchor['id'] === $chain['duplicate_of'] && 0 === $foreign['duplicate_of'], 'All-history phone and email aliases retain a common root without crossing institutions' );
    $unrelated = array();
    for ( $i = 1; $i <= LimuCRM\DUPLICATE_INDEX_BATCH + 5; ++$i ) {
        $unrelated[] = index_capture( $a, '2024-03-01 10:00:00', '057' . sprintf( '%07d', $i ), 'unrelated-' . $i . '@example.invalid' );
    }
    index_check( get_post_meta( $chain['id'], '_lcrm_phone_key', true ) === $chain['phone_key'] && get_post_meta( $chain['id'], '_lcrm_email_key', true ) === $chain['email_key'], 'Normal record writes mirror both frozen contact keys into separate scalar metadata' );
    $empty = index_capture( $a, '2024-04-01 10:00:00', '', '' );
    index_check( metadata_exists( 'post', $empty['id'], '_lcrm_phone_key' ) && metadata_exists( 'post', $empty['id'], '_lcrm_email_key' ) && '' === get_post_meta( $empty['id'], '_lcrm_email_key', true ), 'Known-empty historical identities are indexed explicitly without treating them as matches' );

    delete_post_meta( $anchor['id'], '_lcrm_phone_key' );
    delete_post_meta( $anchor['id'], '_lcrm_email_key' );
    index_check( null === get_option( 'lcrm_duplicate_index', null ), 'External deletion of a contact index invalidates fast-path readiness' );
    $oldest_snapshot = get_post_meta( $anchor['id'], '_lcrm_data', true );
    $edited_contact = get_post_meta( $anchor['contact'], '_lcrm_data', true );
    $edited_contact['phone'] = '0520000000';
    $edited_contact['email'] = 'edited-after-capture@example.invalid';
    update_post_meta( $anchor['contact'], '_lcrm_data', $edited_contact );
    $last = $empty['id'];
    $cursor = index_cursor( $anchor['id'], $last );
    $fallback = LimuCRM\duplicate_component( LimuCRM\data( $chain['id'] ) );
    index_check( ! is_wp_error( $fallback ) && count( $fallback ) > 100 && in_array( $anchor['id'], array_column( $fallback, 'id' ), true ), 'Incomplete indexes use the full historical fallback instead of losing an old matching lead' );

    $legacy = $unrelated[0];
    delete_post_meta( $legacy['id'], '_lcrm_phone_key' );
    delete_post_meta( $legacy['id'], '_lcrm_email_key' );
    $cursor = index_cursor( $anchor['id'], $last );
    $ghost_complete = $cursor;
    $ghost_complete['cursor'] = $last;
    $ghost_complete['done'] = true;
    wp_cache_set( 'lcrm_duplicate_index', $ghost_complete, 'options' );
    $cached_repeat = index_capture( $a, '2024-04-15 10:00:00', $legacy['phone_key'], $legacy['email_key'] );
    index_check( $legacy['id'] === $cached_repeat['duplicate_of'], 'An uncommitted complete options-cache value cannot make an unindexed historical repeat billable' );
    $authoritative_state = LimuCRM\duplicate_index_state();
    index_check( $cursor === $authoritative_state, 'Initialization reads the committed migration cursor instead of a valid-looking cached completion marker' );
    wp_cache_delete( 'lcrm_duplicate_index', 'options' );

    $invalid = $oldest_snapshot; $invalid['duplicate_of'] = -1;
    $wrong_month = $oldest_snapshot; $wrong_month['month'] = '2010-02';
    index_check( ! LimuCRM\duplicate_index_valid_item( $invalid, $anchor['id'] ) && ! LimuCRM\duplicate_index_valid_item( $wrong_month, $anchor['id'] ), 'Corrupt financial references and mismatched billing months cannot enter matching' );
    remove_action( 'updated_post_meta', 'LimuCRM\\duplicate_index_external_meta', 10 );
    update_post_meta( $anchor['id'], '_lcrm_institution', $b );
    update_post_meta( $anchor['id'], '_lcrm_state', 'pending' );
    add_action( 'updated_post_meta', 'LimuCRM\\duplicate_index_external_meta', 10, 3 );
    $poison = $oldest_snapshot;
    $poison['phone_key'] = '0580000000';
    $poison['email_key'] = 'stale-cache@example.invalid';
    wp_cache_set( $anchor['id'], array( '_lcrm_data' => array( maybe_serialize( $poison ) ), '_lcrm_phone_key' => array( $poison['phone_key'] ), '_lcrm_email_key' => array( $poison['email_key'] ) ), 'post_meta' );
    add_filter( 'wp_doing_cron', '__return_true' );
    $query_fault = static function ( $query ) { return false !== strpos( $query, 'SELECT ID FROM' ) && false !== strpos( $query, 'ID <=' ) ? '' : $query; };
    add_filter( 'query', $query_fault );
    $blocked_batch = LimuCRM\duplicate_index_run();
    remove_filter( 'query', $query_fault ); $query_fault = null;
    index_check( is_wp_error( $blocked_batch ) && 500 === $blocked_batch->get_error_data()['status'] && $cursor === get_option( 'lcrm_duplicate_index' ), 'A silently blocked batch query leaves the frozen cursor incomplete and unchanged' );
    wp_cache_set( 'lcrm_duplicate_index', $ghost_complete, 'options' );
    $first_batch = LimuCRM\duplicate_index_run();
    index_check( ! is_wp_error( $first_batch ) && LimuCRM\DUPLICATE_INDEX_BATCH === $first_batch['processed'] && ! $first_batch['done'] && $first_batch['cursor'] <= $last, 'One cron batch indexes at most one hundred existing private recipients' );
    index_check( $first_batch === LimuCRM\duplicate_index_read_state(), 'A cached complete cursor cannot skip pending database rows in the background migration' );
    index_check( $oldest_snapshot === get_post_meta( $anchor['id'], '_lcrm_data', true ) && '0591122334' === get_post_meta( $anchor['id'], '_lcrm_phone_key', true ), 'Backfill reads frozen database identities despite a poisoned object cache and preserves the delivery snapshot' );
    index_check( (string) $a === get_post_meta( $anchor['id'], '_lcrm_institution', true ) && 'historical' === get_post_meta( $anchor['id'], '_lcrm_state', true ), 'Backfill repairs and verifies legacy institution and state mirrors from the frozen snapshot' );
    $extra = index_capture( $a, '2024-04-02 10:00:00', '0589988776', 'after-boundary@example.invalid' );
    index_check( $extra['id'] > $first_batch['max_id'] && '0589988776' === get_post_meta( $extra['id'], '_lcrm_phone_key', true ), 'New live-format writes remain indexed after the migration upper bound has been frozen' );
    $final_batch = LimuCRM\duplicate_index_run();
    index_check( ! is_wp_error( $final_batch ) && $final_batch['done'] && $last === $final_batch['cursor'] && $last === $final_batch['max_id'], 'Backfill completes only after the frozen range has been indexed' );
    index_check( false === wp_next_scheduled( 'lcrm_duplicate_index_batch' ), 'Completed migrations leave no recurring background job' );
    global $wpdb;
    index_check( 'no' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'lcrm_duplicate_index' ) ) || 'off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'lcrm_duplicate_index' ) ), 'The index cursor does not add data to autoloaded options' );

    foreach ( $unrelated as $row ) { wp_cache_delete( $row['id'], 'post_meta' ); wp_cache_delete( $row['contact'], 'post_meta' ); }
    wp_cache_delete( $anchor['contact'], 'post_meta' );
    $changed = LimuCRM\raw_records( array( $chain['id'] ) )[0];
    wp_cache_set( $anchor['id'], array( '_lcrm_data' => array( maybe_serialize( $poison ) ), '_lcrm_phone_key' => array( $poison['phone_key'] ), '_lcrm_email_key' => array( $poison['email_key'] ) ), 'post_meta' );
    $queries = $wpdb->num_queries;
    $component = LimuCRM\duplicate_component( $changed );
    $query_count = $wpdb->num_queries - $queries;
    index_check( ! is_wp_error( $component ) && array( $anchor['id'], $alias['id'], $chain['id'] ) === array_column( $component, 'id' ), 'The ready fast path loads the complete three-row alias component and excludes unrelated recipients' );
    index_check( $component[0]['phone_key'] === $oldest_snapshot['phone_key'] && $component[0]['email_key'] === $oldest_snapshot['email_key'], 'Matching reads snapshots and scalar mirrors from the database instead of trusting stale cached identities' );
    index_check( $query_count <= 12 && false === wp_cache_get( $unrelated[0]['id'], 'post_meta' ) && false === wp_cache_get( $unrelated[0]['contact'], 'post_meta' ) && false === wp_cache_get( $anchor['contact'], 'post_meta' ), 'Indexed matching does not hydrate unrelated delivery rows or referenced source contacts' );
    wp_cache_delete( $anchor['id'], 'post_meta' );
    $candidate_rounds = 0;
    $query_fault = static function ( $query ) use ( &$candidate_rounds ) { return false !== strpos( $query, 'SELECT DISTINCT p.ID' ) && 2 === ++$candidate_rounds ? '' : $query; };
    add_filter( 'query', $query_fault );
    $blocked_component = LimuCRM\duplicate_component( $changed );
    remove_filter( 'query', $query_fault ); $query_fault = null;
    index_check( is_wp_error( $blocked_component ) && 500 === $blocked_component->get_error_data()['status'], 'A silently blocked later alias query fails closed instead of accepting a partial contact component' );
    $empty_component = LimuCRM\duplicate_component( LimuCRM\data( $empty['id'] ) );
    index_check( array( $empty['id'] ) === array_column( $empty_component, 'id' ), 'Two empty matching keys never connect historical recipients' );
    $late = index_capture( $a, '2024-05-01 10:00:00', '0591122336', 'index-b@example.invalid', false );
    index_check( $anchor['id'] === $late['duplicate_of'], 'A new live lead remains duplicate of an ancient historical identity reached through a duplicate alias' );

    $out = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $tag . ' reverse order' ) );
    update_post_meta( $out, '_lcrm_duplicate_policy', array( 'duplicate_mode' => 'days_30', 'duplicate_days' => 30 ) );
    $later = index_capture( $out, '2024-06-20 10:00:00', '0583344556' );
    $earlier = index_capture( $out, '2024-06-10 10:00:00', '0583344556' );
    index_check( $earlier['id'] === LimuCRM\data( $later['id'] )['duplicate_of'], 'An indexed out-of-order source still updates a later bounded-window recipient' );
    $later_limit = index_capture( $out, '2024-07-15 10:00:00', '0583344556' );
    index_check( 0 === $later_limit['duplicate_of'], 'An excluded duplicate does not renew a bounded window during indexed reconciliation' );

    $state_before_failure = index_cursor( $anchor['id'], $anchor['id'] );
    $original_phone = get_post_meta( $anchor['id'], '_lcrm_phone_key', true );
    $write_fault = static function ( $check, $id, $key ) use ( $anchor ) { return (int) $id === $anchor['id'] && '_lcrm_email_key' === $key ? false : $check; };
    // Give the backfill a distinguishable index write that must be rolled back together with its cursor.
    remove_action( 'updated_post_meta', 'LimuCRM\\duplicate_index_external_meta', 10 );
    update_post_meta( $anchor['id'], '_lcrm_phone_key', '0580000000' );
    update_post_meta( $anchor['id'], '_lcrm_email_key', 'blocked-write@example.invalid' );
    add_action( 'updated_post_meta', 'LimuCRM\\duplicate_index_external_meta', 10, 3 );
    add_filter( 'update_post_metadata', $write_fault, 10, 3 );
    $failed = LimuCRM\duplicate_index_run();
    remove_filter( 'update_post_metadata', $write_fault, 10 ); $write_fault = null;
    index_check( is_wp_error( $failed ) && 500 === $failed->get_error_data()['status'] && $state_before_failure === get_option( 'lcrm_duplicate_index' ) && '0580000000' === get_post_meta( $anchor['id'], '_lcrm_phone_key', true ), 'A failed index write rolls back earlier metadata writes and leaves the migration cursor unchanged' );
    $recovered = LimuCRM\duplicate_index_run();
    index_check( ! is_wp_error( $recovered ) && $recovered['done'] && $original_phone === get_post_meta( $anchor['id'], '_lcrm_phone_key', true ), 'Retry repairs the original failed batch instead of skipping its contacts' );

    $cursor_state = index_cursor( $anchor['id'], $anchor['id'] );
    remove_action( 'updated_post_meta', 'LimuCRM\\duplicate_index_external_meta', 10 );
    update_post_meta( $anchor['id'], '_lcrm_phone_key', '0580000000' );
    add_action( 'updated_post_meta', 'LimuCRM\\duplicate_index_external_meta', 10, 3 );
    $cursor_fault = static function ( $new, $old ) { return $old; };
    add_filter( 'pre_update_option_lcrm_duplicate_index', $cursor_fault, 10, 2 );
    $failed_cursor = LimuCRM\duplicate_index_run();
    remove_filter( 'pre_update_option_lcrm_duplicate_index', $cursor_fault, 10 ); $cursor_fault = null;
    index_check( is_wp_error( $failed_cursor ) && 500 === $failed_cursor->get_error_data()['status'] && $cursor_state === get_option( 'lcrm_duplicate_index' ) && '0580000000' === get_post_meta( $anchor['id'], '_lcrm_phone_key', true ), 'A failed cursor save rolls back the whole metadata batch and preserves the previous progress' );
    $cursor_retry = LimuCRM\duplicate_index_run();
    index_check( ! is_wp_error( $cursor_retry ) && $cursor_retry['done'] && $original_phone === get_post_meta( $anchor['id'], '_lcrm_phone_key', true ), 'A cursor persistence failure can retry the exact original batch successfully' );

    $database_cursor = index_cursor( $anchor['id'], $anchor['id'] );
    $uncommitted_target = $database_cursor;
    $uncommitted_target['cursor'] = $anchor['id'];
    $uncommitted_target['processed'] = 1;
    $uncommitted_target['done'] = true;
    wp_cache_set( 'lcrm_duplicate_index', $uncommitted_target, 'options' );
    $skipped_write = LimuCRM\duplicate_index_run();
    index_check( is_wp_error( $skipped_write ) && 500 === $skipped_write->get_error_data()['status'] && $database_cursor === LimuCRM\duplicate_index_read_state(), 'A cached target cannot masquerade as a cursor write that update_option skipped' );
    $real_write = LimuCRM\duplicate_index_run();
    index_check( ! is_wp_error( $real_write ) && $uncommitted_target === LimuCRM\duplicate_index_read_state(), 'Retry persists a previously cached-only cursor target to the database' );

    $query_fault = static function ( $query ) { return false !== strpos( $query, 'SELECT option_value FROM' ) && false !== strpos( $query, 'lcrm_duplicate_index' ) ? '' : $query; };
    add_filter( 'query', $query_fault );
    $blocked_readiness = LimuCRM\duplicate_component( LimuCRM\raw_records( array( $chain['id'] ) )[0] );
    $blocked_cursor = LimuCRM\duplicate_index_run();
    remove_filter( 'query', $query_fault ); $query_fault = null;
    index_check( is_wp_error( $blocked_readiness ) && 500 === $blocked_readiness->get_error_data()['status'] && is_wp_error( $blocked_cursor ) && 500 === $blocked_cursor->get_error_data()['status'], 'A silently blocked authoritative cursor read cannot enable matching or advance the migration' );

    $before_queries = get_option( 'lcrm_duplicate_index' );
    $query_fault = static function ( $query ) { return false !== strpos( $query, 'SELECT DISTINCT p.ID' ) && false !== strpos( $query, 'c.meta_value IN' ) ? 'SELECT missing_index_fixture_column FROM invalid_index_fixture_table' : $query; };
    $old_suppression = $wpdb->suppress_errors( true );
    add_filter( 'query', $query_fault );
    $failed_read = LimuCRM\duplicate_component( LimuCRM\data( $chain['id'] ) );
    remove_filter( 'query', $query_fault ); $query_fault = null;
    $wpdb->suppress_errors( $old_suppression ); $wpdb->last_error = '';
    index_check( is_wp_error( $failed_read ) && 500 === $failed_read->get_error_data()['status'] && $before_queries === get_option( 'lcrm_duplicate_index' ), 'A failed indexed query fails closed rather than reporting a false unique lead' );

    $corrupt = $before_queries; $corrupt['cursor'] = $corrupt['max_id'] + 1;
    update_option( 'lcrm_duplicate_index', $corrupt, false );
    $corrupt_result = LimuCRM\duplicate_index_run();
    index_check( is_wp_error( $corrupt_result ) && 409 === $corrupt_result->get_error_data()['status'] && $corrupt === get_option( 'lcrm_duplicate_index' ), 'Corrupt migration state cannot silently choose a different upper bound or mark completion' );
    $corrupt_fallback = LimuCRM\duplicate_component( LimuCRM\data( $chain['id'] ) );
    index_check( ! is_wp_error( $corrupt_fallback ) && in_array( $anchor['id'], array_column( $corrupt_fallback, 'id' ), true ), 'A corrupt readiness marker retains the safe historical fallback' );

    index_ready( $extra['id'] );
    wp_trash_post( $extra['id'] );
    index_ready( $extra['id'] );
    wp_untrash_post( $extra['id'] );
    // WordPress may restore private posts as draft; explicitly restoring private still invalidates readiness.
    wp_update_post( array( 'ID' => $extra['id'], 'post_status' => 'private' ) );
    index_check( null === get_option( 'lcrm_duplicate_index', null ), 'Restoring an old private recipient requires readiness to be checked again' );
    index_check( 0 === $http_calls, 'Index migration and matching perform no external HTTP or fiscal requests' );
    index_check( $original_settings === get_option( 'lcrm_settings', null ), 'Index work does not change billing or duplicate policy settings' );
    echo "OK $passed indexed duplicate assertions\n";
} catch ( Throwable $error ) {
    $failure = $error;
} finally {
    if ( $write_fault ) { remove_filter( 'update_post_metadata', $write_fault, 10 ); }
    if ( $query_fault ) { remove_filter( 'query', $query_fault ); }
    if ( $cursor_fault ) { remove_filter( 'pre_update_option_lcrm_duplicate_index', $cursor_fault, 10 ); }
    remove_filter( 'wp_doing_cron', '__return_true' );
    remove_filter( 'pre_http_request', $no_http );
    remove_action( 'wp_insert_post', $track, 99 );
    foreach ( array_reverse( array_unique( $created ) ) as $id ) { wp_delete_post( $id, true ); }
    if ( null === $original_state ) { delete_option( 'lcrm_duplicate_index' ); } else { update_option( 'lcrm_duplicate_index', $original_state, false ); }
    update_option( 'cron', $original_cron );
    wp_set_current_user( $original_user );
}
if ( $failure ) { fwrite( STDERR, $failure->getMessage() . "\n" . $failure->getTraceAsString() . "\n" ); exit( 1 ); }
