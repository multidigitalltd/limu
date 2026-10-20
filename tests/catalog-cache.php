<?php
/** Public catalog caching with isolated fixtures; never run against a production database. */
$catalog_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $catalog_root ) { fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" ); exit( 1 ); }
require $catalog_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) { fwrite( STDERR, "Refusing a non-disposable database.\n" ); exit( 1 ); }
foreach ( array( 'institutions', 'study', 'courses', 'course', 'routes', 'route', 'leads' ) as $catalog_type ) { register_post_type( $catalog_type, array( 'public' => false ) ); }
add_filter( 'pre_wp_mail', '__return_true' );
$catalog_no_http = static function () { return new WP_Error( 'catalog_no_network', 'External requests disabled for catalog fixtures.' ); };
add_filter( 'pre_http_request', $catalog_no_http );
$catalog_created = array();
$catalog_passed = 0;
$catalog_revision = get_option( 'lcrm_catalog_revision', null );
$catalog_queue = $GLOBALS['lcrm_native_queue'] ?? null;
$catalog_new = $GLOBALS['lcrm_native_new_leads'] ?? null;
$catalog_settings = get_option( 'lcrm_settings', null );
$catalog_scope = LimuCRM\native_catalog_scope();
$catalog_tracker = static function ( $id, $post, $update ) use ( &$catalog_created ) { if ( ! $update ) { $catalog_created[] = $id; } };
add_action( 'wp_after_insert_post', $catalog_tracker, 99, 3 );
$GLOBALS['lcrm_native_queue'] = array();
$GLOBALS['lcrm_native_new_leads'] = array();

function catalog_check( $condition, $message ) {
    global $catalog_passed;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$catalog_passed;
    echo "PASS $message\n";
}
function catalog_post( $type, $title, $meta = array(), $slug = '', $status = 'publish' ) {
    $args = array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status );
    if ( $slug ) { $args['post_name'] = $slug; }
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Unable to create an isolated catalog fixture.' ); }
    foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
    return $id;
}
function catalog_resolve( $value ) { return LimuCRM\legacy_institutions( $value, LimuCRM\native_titles() ); }
function catalog_forget_request() { unset( $GLOBALS['lcrm_native_catalog_cache'][ LimuCRM\native_catalog_scope() ] ); }

try {
    $admin = get_user_by( 'login', 'crm-admin' );
    if ( ! $admin || ! user_can( $admin, 'manage_options' ) ) { throw new RuntimeException( 'Run integration setup in the disposable database first.' ); }
    wp_set_current_user( $admin->ID );
    $prefix = 'catalog-' . wp_generate_uuid4();
    $title_a = $prefix . ' institution A';
    $title_b = $prefix . ' institution B';
    $a = catalog_post( 'institutions', $title_a, array(), $prefix . '-public-alias' );
    $b = catalog_post( 'institutions', $title_b );
    $course_title = $prefix . ' exact course';
    $course = catalog_post( 'courses', $course_title, array( 'institution_acf' => $a, '_elementor_data' => str_repeat( 'unused', 10000 ) ) );
    $page = catalog_post( 'page', $prefix . ' unrelated page', array( '_elementor_data' => str_repeat( 'unused', 10000 ) ) );
    $lead = catalog_post( 'leads', 'Private catalog fixture contact', array( 'phone' => '0598877665', 'full-name' => 'Private catalog fixture contact' ), '', 'draft' );
    LimuCRM\native_catalog_invalidate();
    wp_cache_flush();
    $catalog_reads = 0;
    $relationship_reads = 0;
    $full_meta_reads = 0;
    $read_locked = array();
    $query_observer = static function ( $sql ) use ( &$catalog_reads, &$relationship_reads, &$full_meta_reads, &$read_locked, $wpdb ) {
        if ( false !== stripos( $sql, 'SELECT' ) && false !== strpos( $sql, $wpdb->posts ) && false !== strpos( $sql, "'institutions'" ) && false !== strpos( $sql, "'study'" ) ) {
            ++$catalog_reads;
            $read_locked[] = isset( $GLOBALS['lcrm_changed_records'] );
        }
        if ( false !== strpos( $sql, 'FROM ' . $wpdb->postmeta ) && false !== strpos( $sql, 'post_id IN' ) ) {
            if ( false !== strpos( $sql, 'meta_key IN' ) ) { ++$relationship_reads; }
            else { ++$full_meta_reads; }
        }
        return $sql;
    };
    add_filter( 'query', $query_observer, 99 );
    $titles = LimuCRM\native_titles();
    remove_filter( 'query', $query_observer, 99 );
    catalog_check( array( $a ) === LimuCRM\legacy_institutions( $course_title, $titles ) && $catalog_reads >= 1 && $relationship_reads >= 1, 'A cold catalog reads the exact public source and explicit relationship rows' );
    catalog_check( 0 === $full_meta_reads && false === wp_cache_get( $page, 'post_meta' ) && false === wp_cache_get( $course, 'post_meta' ), 'Cold catalog loading never hydrates unrelated Elementor or other complete post metadata' );
    $revision = get_option( 'lcrm_catalog_revision' );
    $key = LimuCRM\native_catalog_key( $revision );
    $stored = get_transient( $key );
    catalog_check( LimuCRM\native_catalog_valid( $stored, $revision ) && array( 'revision', 'titles' ) === array_keys( $stored ) && false === strpos( serialize( $stored ), 'Private catalog fixture contact' ) && false === strpos( serialize( $stored ), '0598877665' ), 'The persisted catalog contains only public mapping names and IDs, never lead identities or full metadata' );
    if ( ! wp_using_ext_object_cache() ) {
        $expires = get_option( '_transient_timeout_' . $key );
        catalog_check( $expires > time() && $expires <= time() + HOUR_IN_SECONDS, 'The persistent catalog has a bounded one-hour lifetime' );
    }
    $before = $wpdb->num_queries;
    catalog_check( $titles === LimuCRM\native_titles() && $before === $wpdb->num_queries, 'A second lookup in the same request issues no database queries' );
    catalog_forget_request();
    wp_cache_flush();
    $catalog_reads = 0;
    $relationship_reads = 0;
    add_filter( 'query', $query_observer, 99 );
    $persistent = LimuCRM\native_titles();
    remove_filter( 'query', $query_observer, 99 );
    catalog_check( $titles === $persistent && 0 === $catalog_reads && 0 === $relationship_reads, 'A subsequent request reuses the transient without selecting catalog posts or relationship metadata again' );
    $revision = get_option( 'lcrm_catalog_revision' );
    update_post_meta( $lead, 'phone', '0598877666' );
    wp_update_post( array( 'ID' => $lead, 'post_title' => 'Private catalog fixture changed' ) );
    update_post_meta( $course, '_elementor_data', 'still unrelated' );
    catalog_check( $revision === get_option( 'lcrm_catalog_revision' ) && $titles === LimuCRM\native_titles(), 'Lead saves, contact metadata and unrelated course metadata do not invalidate the recipient catalog' );

    update_post_meta( $course, 'institution_acf', $b );
    catalog_check( $revision !== get_option( 'lcrm_catalog_revision' ) && array( $b ) === catalog_resolve( $course_title ), 'A course parent saved after save_post invalidates its cached recipient immediately' );
    add_post_meta( $course, 'institution', $a );
    catalog_check( array() === catalog_resolve( $course_title ), 'A conflicting legacy course parent fails closed even after a cache was previously populated' );
    delete_post_meta( $course, 'institution' );
    catalog_check( array( $b ) === catalog_resolve( $course_title ), 'Deleting conflicting relationship metadata restores the unique cached mapping' );
    $study_title = $prefix . ' late study parent';
    $study = catalog_post( 'study', $study_title );
    catalog_check( array() === catalog_resolve( $study_title ), 'A study with no stored parent remains unresolved in the cache' );
    add_post_meta( $study, 'mosad', $a );
    catalog_check( array( $a ) === catalog_resolve( $study_title ), 'A study parent added after the post save invalidates an earlier unresolved result' );

    $renamed = $prefix . ' renamed institution';
    wp_update_post( array( 'ID' => $a, 'post_title' => $renamed ) );
    catalog_check( array( $a ) === catalog_resolve( $renamed ) && array() === catalog_resolve( $title_a ), 'Changing an institution title removes the old title instead of serving a stale recipient' );
    $old_alias = str_replace( '-', ' ', $prefix . '-public-alias' );
    $new_slug = $prefix . '-new-public-alias';
    wp_update_post( array( 'ID' => $a, 'post_name' => $new_slug ) );
    catalog_check( array() === catalog_resolve( $old_alias ) && array( $a ) === catalog_resolve( str_replace( '-', ' ', $new_slug ) ), 'A permalink change invalidates the saved alias without guessing similar names' );
    $blocker = catalog_post( 'page', $course_title );
    catalog_check( array() === catalog_resolve( $course_title ), 'A new generic page collision invalidates a cached course recipient and remains unresolved' );
    wp_trash_post( $blocker );
    catalog_check( array( $b ) === catalog_resolve( $course_title ), 'Trashing a generic blocker refreshes the published/draft catalog immediately' );
    wp_update_post( array( 'ID' => $blocker, 'post_status' => 'publish' ) );
    catalog_check( array() === catalog_resolve( $course_title ), 'Restoring a blocker invalidates the recipient mapping again' );
    wp_delete_post( $blocker, true );
    catalog_check( array( $b ) === catalog_resolve( $course_title ), 'Permanent deletion invalidates the cache using the deleted post object' );

    $revision = get_option( 'lcrm_catalog_revision' );
    $key = LimuCRM\native_catalog_key( $revision );
    set_transient( $key, array( 'revision' => $revision, 'titles' => array( $renamed => array( 'malformed-id' ) ) ), HOUR_IN_SECONDS );
    catalog_forget_request();
    catalog_check( array( $a ) === catalog_resolve( $renamed ) && LimuCRM\native_catalog_valid( get_transient( $key ), $revision ), 'Malformed cached recipient IDs are discarded and replaced from authoritative source data' );
    $oversized = array_fill_keys( array_map( static function ( $n ) { return 'cached-' . $n; }, range( 1, 20001 ) ), array( $b ) );
    set_transient( $key, array( 'revision' => $revision, 'titles' => $oversized ), HOUR_IN_SECONDS );
    catalog_forget_request();
    catalog_check( array( $a ) === catalog_resolve( $renamed ) && count( get_transient( $key )['titles'] ) < 20001, 'An oversized cached catalog is rejected rather than reused as a recipient source' );
    unset( $oversized );
    $large_names = array();
    for ( $n = 0; $n < 600; ++$n ) { $large_names[ $n . str_repeat( 'x', 3600 ) ] = array( $a ); }
    catalog_check( ! LimuCRM\native_catalog_valid( array( 'revision' => $revision, 'titles' => $large_names ), $revision ), 'Cache validation also bounds aggregate payload size when its entry count is otherwise valid' );
    unset( $large_names );

    LimuCRM\native_catalog_invalidate();
    $revision = get_option( 'lcrm_catalog_revision' );
    $key = LimuCRM\native_catalog_key( $revision );
    $in_transaction = LimuCRM\locked( static function () { return LimuCRM\native_titles(); } );
    catalog_check( ! is_wp_error( $in_transaction ) && false === get_transient( $key ) && ! isset( $GLOBALS['lcrm_native_catalog_cache'][ LimuCRM\native_catalog_scope() ] ), 'A cold read inside a transaction does not publish its potentially uncommitted catalog view' );
    $stable = LimuCRM\native_titles();
    $rolled_back = LimuCRM\locked( static function () use ( $a, $prefix ) {
        $GLOBALS['lcrm_changed_records'][] = $a;
        wp_update_post( array( 'ID' => $a, 'post_title' => $prefix . ' uncommitted title' ) );
        $inside = LimuCRM\native_titles();
        if ( ! isset( $inside[ $prefix . ' uncommitted title' ] ) ) { throw new RuntimeException( 'Transaction did not observe its own catalog update.' ); }
        return LimuCRM\error( 'Synthetic catalog rollback', 409 );
    } );
    catalog_check( is_wp_error( $rolled_back ) && $stable === LimuCRM\native_titles() && array() === catalog_resolve( $prefix . ' uncommitted title' ), 'A rolled-back catalog change never escapes through the transient or request memo' );
    $committed_title = $prefix . ' committed title';
    $committed = LimuCRM\locked( static function () use ( $a, $committed_title ) {
        $GLOBALS['lcrm_changed_records'][] = $a;
        wp_update_post( array( 'ID' => $a, 'post_title' => $committed_title ) );
        return true;
    } );
    catalog_check( true === $committed && array( $a ) === catalog_resolve( $committed_title ) && array() === catalog_resolve( $renamed ), 'The post-commit listener rotates a dirty catalog only after a successful transaction' );

    $source = catalog_post( 'leads', 'Catalog flush fixture', array( 'institution' => $committed_title, 'full-name' => 'Synthetic catalog-only contact', 'phone' => '0598877601', 'email' => '', 'form-name' => 'catalog-test' ), '', 'draft' );
    wp_update_post( array( 'ID' => $source, 'post_date' => '1990-01-01 10:00:00', 'post_status' => 'publish' ) );
    LimuCRM\native_catalog_invalidate();
    wp_cache_flush();
    $catalog_reads = 0;
    $read_locked = array();
    add_filter( 'query', $query_observer, 99 );
    $flush = LimuCRM\native_flush();
    remove_filter( 'query', $query_observer, 99 );
    catalog_check( isset( $flush[ $source ] ) && ! is_wp_error( $flush[ $source ] ) && $catalog_reads >= 1 && ! in_array( true, $read_locked, true ), 'Native flushing warms the catalog before acquiring any lead mutation lock' );
    catalog_check( $catalog_settings === get_option( 'lcrm_settings', null ), 'Catalog caching and fixture capture never change billing settings' );
    echo "OK $catalog_passed catalog cache assertions\n";
} finally {
    remove_action( 'wp_after_insert_post', $catalog_tracker, 99 );
    if ( isset( $query_observer ) ) { remove_filter( 'query', $query_observer, 99 ); }
    foreach ( array_reverse( array_unique( $catalog_created ) ) as $id ) { wp_delete_post( $id, true ); }
    $current_revision = get_option( 'lcrm_catalog_revision', '0' );
    if ( is_string( $current_revision ) ) { delete_transient( LimuCRM\native_catalog_key( $current_revision ) ); }
    if ( null === $catalog_revision ) { delete_option( 'lcrm_catalog_revision' ); }
    else { update_option( 'lcrm_catalog_revision', $catalog_revision, false ); }
    unset( $GLOBALS['lcrm_native_catalog_cache'][ $catalog_scope ], $GLOBALS['lcrm_native_catalog_dirty'][ $catalog_scope ] );
    if ( null === $catalog_queue ) { unset( $GLOBALS['lcrm_native_queue'] ); }
    else { $GLOBALS['lcrm_native_queue'] = $catalog_queue; }
    if ( null === $catalog_new ) { unset( $GLOBALS['lcrm_native_new_leads'] ); }
    else { $GLOBALS['lcrm_native_new_leads'] = $catalog_new; }
    remove_filter( 'pre_http_request', $catalog_no_http );
}
