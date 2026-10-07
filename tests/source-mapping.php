<?php
/** Exact public source mapping checks with disposable synthetic fixtures only. PHP 7.4 syntax. */
$mapping_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $mapping_root ) { fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable installation.\n" ); exit( 1 ); }
require $mapping_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) { fwrite( STDERR, "Refusing a non-disposable database.\n" ); exit( 1 ); }
foreach ( array( 'institutions', 'study', 'courses', 'course', 'routes', 'route', 'leads' ) as $mapping_type ) { register_post_type( $mapping_type, array( 'public' => false ) ); }
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', function () { return new WP_Error( 'mapping_no_network', 'Network disabled for synthetic mapping checks.' ); } );
$mapping_posts = array();
$mapping_passed = 0;
$mapping_failure = null;
$mapping_settings = get_option( 'lcrm_settings', null );
$mapping_tracker = function ( $id, $post, $update ) use ( &$mapping_posts ) { if ( ! $update ) { $mapping_posts[] = $id; } };
add_action( 'wp_after_insert_post', $mapping_tracker, 99, 3 );

function mapping_check( $condition, $message ) {
    global $mapping_passed;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$mapping_passed;
    echo "PASS $message\n";
}
function mapping_post( $type, $title, $meta = array(), $status = 'publish', $slug = '' ) {
    $args = array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status );
    if ( $slug ) { $args['post_name'] = $slug; }
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Unable to create isolated public mapping fixture.' ); }
    foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); }
    return $id;
}
function mapping_resolve( $value ) { return LimuCRM\legacy_institutions( $value, LimuCRM\native_titles() ); }
function mapping_source_rows( $id ) { return LimuCRM\query_records( 'lcrm_delivery', array( 'source' => 'legacy:' . $id ), 1, 20 )['items']; }
function mapping_fault( $relationship ) {
    global $wpdb;
    $triggered = 0;
    $old_suppression = $wpdb->suppress_errors( true );
    $uncached_meta = function ( $query ) {
        $types = (array) $query->get( 'post_type' );
        if ( in_array( 'institutions', $types, true ) && in_array( 'study', $types, true ) ) { $query->set( 'update_post_meta_cache', false ); }
    };
    $fault = function ( $sql ) use ( &$triggered, $relationship, $wpdb ) {
        $catalog = false !== strpos( $sql, "'institutions'" ) && false !== strpos( $sql, "'study'" ) && false !== strpos( $sql, $wpdb->posts );
        $metadata = false !== strpos( $sql, 'FROM ' . $wpdb->postmeta ) && false !== strpos( $sql, 'meta_key' );
        if ( ! $triggered && ( $relationship ? $metadata : $catalog ) ) {
            ++$triggered;
            return 'SELECT missing_mapping_fixture_column FROM ' . $wpdb->posts;
        }
        return $sql;
    };
    wp_cache_flush();
    if ( $relationship ) { add_action( 'pre_get_posts', $uncached_meta, 99 ); }
    add_filter( 'query', $fault, 99 );
    try {
        $result = LimuCRM\locked( function () { return LimuCRM\native_titles(); } );
        return array( $triggered, $result );
    } finally {
        remove_filter( 'query', $fault, 99 );
        remove_action( 'pre_get_posts', $uncached_meta, 99 );
        $wpdb->suppress_errors( $old_suppression );
        $wpdb->last_error = '';
        wp_cache_flush();
    }
}

try {
    $mapping_admin = get_user_by( 'login', 'crm-admin' );
    if ( ! $mapping_admin || ! user_can( $mapping_admin, 'manage_options' ) ) { throw new RuntimeException( 'Missing disposable administrator.' ); }
    wp_set_current_user( $mapping_admin->ID );
    $mapping_prefix = 'mapping-' . wp_generate_uuid4();
    $mapping_a_title = $mapping_prefix . ' מוסד "אלף"';
    $mapping_b_title = $mapping_prefix . ' מוסד בית';
    $mapping_b_alias = str_replace( '-', ' ', $mapping_prefix ) . ' המוסד הקודם';
    $mapping_a = mapping_post( 'institutions', $mapping_a_title );
    $mapping_b = mapping_post( 'institutions', $mapping_b_title, array(), 'draft', str_replace( ' ', '-', $mapping_b_alias ) );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_a_title ), 'Direct institution titles retain their exact institution' );
    mapping_check( array( $mapping_b ) === mapping_resolve( $mapping_b_title ), 'Draft institutions remain valid reporting destinations' );
    mapping_check( array( $mapping_a ) === mapping_resolve( (string) $mapping_a ), 'A numeric existing institution ID remains a valid destination' );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_prefix . ' מוסד &quot;אלף&quot;' ), 'HTML-encoded quotes are canonicalized to the same exact institution title' );
    mapping_check( array( $mapping_a ) === mapping_resolve( "\u{200F}" . $mapping_prefix . "\u{00A0}מוסד\u{2003}\"אלף\"\u{200E}" ), 'Unicode whitespace and direction markers preserve exact source identity' );
    mapping_check( array( $mapping_b ) === mapping_resolve( $mapping_b_alias ), 'A saved decoded institution permalink supplies its explicit former public spelling' );
    mapping_check( array() === mapping_resolve( $mapping_prefix . ' המוסד בית' ), 'Similar names and added articles do not create guessed aliases' );
    foreach ( array( '', ' , , ', array( $mapping_a ), true, "\xFF" ) as $mapping_bad ) { mapping_check( array() === mapping_resolve( $mapping_bad ), 'Empty or malformed source input remains unresolved' ); }

    $mapping_course_title = $mapping_prefix . ' מסלול מקצועי';
    $mapping_study_title = $mapping_prefix . ' מסלול אקדמי';
    $mapping_course = mapping_post( 'courses', $mapping_course_title, array( 'institution_acf' => $mapping_a ) );
    $mapping_study = mapping_post( 'study', $mapping_study_title, array( 'mosad' => $mapping_b ) );
    $mapping_legacy_course = mapping_post( 'courses', $mapping_prefix . ' קורס קודם', array( 'institution' => $mapping_a ) );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_course_title ), 'A unique course title resolves through its explicit institution_acf parent' );
    mapping_check( array( $mapping_b ) === mapping_resolve( $mapping_study_title ), 'A unique study title resolves through its explicit mosad parent' );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_prefix . ' קורס קודם' ), 'The existing numeric institution parent is accepted for a legacy course' );
    mapping_check( array( $mapping_a, $mapping_b ) === mapping_resolve( $mapping_course_title . ', ' . $mapping_study_title . ',' ), 'A completely mapped course list fans out to distinct recipients and ignores an empty trailing separator' );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_course_title . ', ' . $mapping_course_title ), 'Repeated recipient entries do not create duplicate destinations' );
    mapping_check( array() === mapping_resolve( $mapping_course_title . ', ' . $mapping_prefix . ' unknown' ), 'One unknown recipient leaves the entire source list unresolved' );
    $mapping_comma = mapping_post( 'study', $mapping_prefix . ' משחק, תיאטרון', array( 'mosad' => $mapping_a ) );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_prefix . ' משחק, תיאטרון' ), 'A complete exact study title containing a comma is checked before list splitting' );

    $mapping_dupe_title = $mapping_prefix . ' duplicate title';
    mapping_post( 'study', $mapping_dupe_title, array( 'mosad' => $mapping_a ) );
    mapping_post( 'courses', $mapping_dupe_title, array( 'institution_acf' => $mapping_a ) );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_dupe_title ), 'Several exact course records are safe only when every relationship agrees on one institution' );
    $mapping_conflict = mapping_post( 'courses', $mapping_dupe_title, array( 'institution_acf' => $mapping_b ) );
    mapping_check( array() === mapping_resolve( $mapping_dupe_title ), 'Same-title courses with different institutions fail closed' );
    wp_delete_post( $mapping_conflict, true );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_dupe_title ), 'Catalog results refresh after a conflicting course is removed in the same request' );
    update_post_meta( $mapping_study, 'mosad', $mapping_a );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_study_title ), 'A changed study relationship is read again without a stale request catalog' );
    update_post_meta( $mapping_study, 'mosad', $mapping_b );

    foreach ( array( 'missing' => array(), 'array' => array( 'mosad' => array( $mapping_a ) ), 'zero' => array( 'mosad' => 0 ), 'negative' => array( 'mosad' => -$mapping_a ), 'dangling' => array( 'mosad' => 999999999 ), 'nonnumeric' => array( 'mosad' => $mapping_a_title ) ) as $mapping_label => $mapping_meta ) {
        $mapping_title = $mapping_prefix . ' invalid ' . $mapping_label;
        mapping_post( 'study', $mapping_title, $mapping_meta );
        mapping_check( array() === mapping_resolve( $mapping_title ), 'A ' . $mapping_label . ' study parent remains unresolved rather than guessed' );
    }
    $mapping_multi_title = $mapping_prefix . ' multi parent';
    $mapping_multi = mapping_post( 'study', $mapping_multi_title, array( 'mosad' => $mapping_a ) );
    add_post_meta( $mapping_multi, 'mosad', $mapping_b );
    mapping_check( array() === mapping_resolve( $mapping_multi_title ), 'Conflicting duplicate parent metadata rows fail closed' );
    $mapping_acf_conflict = $mapping_prefix . ' conflicting old parent';
    mapping_post( 'courses', $mapping_acf_conflict, array( 'institution_acf' => $mapping_a, 'institution' => $mapping_b ) );
    mapping_check( array() === mapping_resolve( $mapping_acf_conflict ), 'Conflicting current and legacy course parents cannot select the first value' );

    foreach ( array( 'routes', 'page' ) as $mapping_generic_type ) {
        $mapping_title = $mapping_prefix . ' generic ' . $mapping_generic_type;
        mapping_post( 'courses', $mapping_title, array( 'institution_acf' => $mapping_a ) );
        mapping_post( $mapping_generic_type, $mapping_title );
        mapping_check( array() === mapping_resolve( $mapping_title ), 'A course title also used by a generic ' . $mapping_generic_type . ' cannot prove one recipient' );
    }
    $mapping_alias_route = mapping_post( 'routes', $mapping_b_alias );
    mapping_check( array() === mapping_resolve( $mapping_b_alias ), 'A generic route blocks an institution slug alias even when no course has that title' );
    wp_delete_post( $mapping_alias_route, true );
    mapping_check( array( $mapping_b ) === mapping_resolve( $mapping_b_alias ), 'Removing the ambiguous generic route restores the explicit permalink alias' );
    $mapping_alias_school = mapping_post( 'institutions', $mapping_b_alias );
    mapping_check( array( $mapping_alias_school ) === mapping_resolve( $mapping_b_alias ), 'An exact institution title retains authority over another institution inferred slug alias' );
    $mapping_direct_route = mapping_post( 'routes', $mapping_a_title );
    $mapping_direct_course = mapping_post( 'courses', $mapping_a_title );
    mapping_check( array( $mapping_a ) === mapping_resolve( $mapping_a_title ), 'An explicit institution title remains authoritative when an unrelated course or generic route shares it' );
    mapping_post( 'study', (string) $mapping_b, array( 'mosad' => $mapping_a ) );
    mapping_check( array( $mapping_b ) === mapping_resolve( (string) $mapping_b ), 'A numeric course title cannot replace the explicit numeric ID of another institution' );
    $mapping_numeric = mapping_post( 'institutions', (string) $mapping_a );
    mapping_check( array( $mapping_numeric ) === mapping_resolve( (string) $mapping_a ), 'A numeric exact institution title preserves its authority over an unrelated post ID' );
    $mapping_duplicate_school = mapping_post( 'institutions', $mapping_a_title );
    mapping_check( array() === mapping_resolve( $mapping_a_title ), 'Two institutions with the same exact title remain ambiguous' );
    wp_delete_post( $mapping_duplicate_school, true );

    $mapping_fault_conflict = mapping_post( 'courses', $mapping_dupe_title, array( 'institution_acf' => $mapping_b ) );
    mapping_check( array() === mapping_resolve( $mapping_dupe_title ), 'Fault fixtures begin with an ambiguous same-title relationship' );
    foreach ( array( false, true ) as $mapping_relationship_fault ) {
        list( $mapping_fault_triggered, $mapping_fault_result ) = mapping_fault( $mapping_relationship_fault );
        mapping_check( 1 === $mapping_fault_triggered && is_wp_error( $mapping_fault_result ), 'A suppressed ' . ( $mapping_relationship_fault ? 'relationship metadata' : 'catalog selection' ) . ' SQL failure returns a safe error instead of a recipient catalog' );
        mapping_check( false === strpos( $mapping_fault_result->get_error_message(), 'missing_mapping_fixture_column' ), 'Catalog SQL errors never expose database statements or column details' );
        mapping_check( array() === mapping_resolve( $mapping_dupe_title ), 'An ambiguous source remains unresolved after the failed read and rollback' );
    }
    wp_delete_post( $mapping_fault_conflict, true );
    $mapping_warm = LimuCRM\native_titles();
    $mapping_old_error = $wpdb->last_error;
    $mapping_old_queries = $wpdb->num_queries;
    $wpdb->last_error = 'Earlier unrelated SQL error';
    try {
        $mapping_cached = LimuCRM\native_titles();
        mapping_check( $mapping_old_queries === $wpdb->num_queries && $mapping_warm === $mapping_cached && array( $mapping_a ) === LimuCRM\legacy_institutions( $mapping_course_title, $mapping_cached ), 'A fully cached catalog ignores an unrelated stale SQL error without issuing a database query' );
    } finally {
        $wpdb->last_error = $mapping_old_error;
    }

    // Historical import and automatic observation share the same catalog without changing billing settings.
    $mapping_source = mapping_post( 'leads', 'Synthetic source mapping fixture', array( 'institution' => $mapping_course_title . ', ' . $mapping_study_title, 'full-name' => 'Synthetic mapping contact', 'phone' => '0599010201', 'email' => 'mapping-fixture@example.test' ) );
    wp_update_post( array( 'ID' => $mapping_source, 'post_date' => '1990-01-01 10:00:00' ) );
    $mapping_import = LimuCRM\locked( function () use ( $mapping_source ) { return LimuCRM\import_history( $mapping_source - 1 ); } );
    $mapping_rows = mapping_source_rows( $mapping_source );
    $mapping_recipients = array_column( $mapping_rows, 'institution' ); sort( $mapping_recipients ); $mapping_expected = array( $mapping_a, $mapping_b ); sort( $mapping_expected );
    mapping_check( ! is_wp_error( $mapping_import ) && 1 === $mapping_import['processed'] && $mapping_expected === $mapping_recipients && 2 === count( $mapping_rows ), 'Historical import uses exact course and study parents to create one record for each recipient' );
    mapping_check( ! array_filter( $mapping_rows, function ( $row ) { return 'historical' !== $row['state'] || $row['bill'] || '1990-01-01 10:00:00' !== $row['date']; } ), 'New historical source mappings preserve dates and remain outside billing' );
    LimuCRM\locked( function () use ( $mapping_source ) { return LimuCRM\import_history( $mapping_source - 1 ); } );
    mapping_check( $mapping_rows === mapping_source_rows( $mapping_source ), 'Repeating historical import preserves mapped source records without duplication' );
    LimuCRM\native_flush();
    mapping_check( $mapping_rows === mapping_source_rows( $mapping_source ), 'Native observation reuses the same stable source records and resolved recipients' );
    mapping_check( $mapping_settings === get_option( 'lcrm_settings', null ), 'Source mapping never changes billing or duplicate settings' );
    echo "\n$mapping_passed source mapping assertions passed.\n";
} catch ( Throwable $exception ) {
    $mapping_failure = $exception;
    fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" );
} finally {
    remove_action( 'wp_after_insert_post', $mapping_tracker, 99 );
    foreach ( array_reverse( array_unique( $mapping_posts ) ) as $mapping_id ) {
        unset( $GLOBALS['lcrm_native_queue'][ $mapping_id ], $GLOBALS['lcrm_native_new_leads'][ $mapping_id ] );
        wp_delete_post( $mapping_id, true );
    }
}
if ( $mapping_failure ) { exit( 1 ); }
