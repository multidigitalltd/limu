<?php
/** Security and billing regression checks against the disposable database only. PHP 7.4 syntax. */
$sec_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $sec_root ) {
    fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
    exit( 1 );
}
require $sec_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
    fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
    exit( 1 );
}
add_filter( 'pre_wp_mail', '__return_true' );
register_post_type( 'institutions', array( 'public' => false ) );
register_post_type( 'leads', array( 'public' => false ) );
$sec_passed = 0;
$sec_posts = array();
$sec_user_id = 0;
$sec_original_settings = get_option( 'lcrm_settings', null );
$sec_failure = null;
$sec_fault = null;
$sec_query_fault = null;
$sec_large_posts = null;
$sec_post_tracker = function ( $id, $post, $update ) {
    global $sec_posts;
    if ( ! $update ) {
        $sec_posts[] = $id;
    }
};
add_action( 'wp_insert_post', $sec_post_tracker, 10, 3 );

function sec_check( $condition, $message ) {
    global $sec_passed;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    ++$sec_passed;
    echo "PASS $message\n";
}

function sec_request( $route, $body = null, $query = array(), $nonce = true ) {
    $request = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/limu-crm/v1/' . $route );
    if ( $nonce ) {
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    }
    foreach ( $query as $key => $value ) {
        $request->set_param( $key, $value );
    }
    if ( null !== $body ) {
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
}

function sec_institution( $title, $from ) {
    $id = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $title ), true );
    if ( is_wp_error( $id ) ) {
        throw new RuntimeException( 'Unable to create isolated security institution.' );
    }
    $response = sec_request( 'agreement', array( 'institution' => $id, 'price' => '17.50', 'from' => $from, 'credit_days' => '30' ) );
    if ( 200 !== $response->get_status() ) {
        throw new RuntimeException( 'Unable to configure isolated security tariff.' );
    }
    return $id;
}

function sec_delivery( $institution, $date, $source, $phone ) {
    return LimuCRM\locked( function () use ( $institution, $date, $source, $phone ) {
        return LimuCRM\record_delivery( array( 'name' => 'Security fixture', 'phone' => $phone, 'email' => '', 'date' => $date, 'form' => 'Security fixture' ), $institution, $source );
    } );
}

function sec_legacy( $institution_value, $date ) {
    $id = wp_insert_post( array( 'post_type' => 'leads', 'post_status' => 'publish', 'post_title' => 'Security import fixture', 'post_date' => $date ) );
    foreach ( array( 'institution' => $institution_value, 'full-name' => 'Security history', 'phone' => '0598883322', 'email' => '', 'form-name' => 'Security fixture' ) as $key => $value ) {
        update_post_meta( $id, $key, $value );
    }
    return $id;
}

try {
    $sec_admin = get_user_by( 'login', 'crm-admin' );
    if ( ! $sec_admin || ! user_can( $sec_admin, 'manage_options' ) ) {
        throw new RuntimeException( 'Run the normal disposable integration setup first.' );
    }
    wp_set_current_user( $sec_admin->ID );
    $sec_month = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' )->format( 'Y-m' );
    $sec_previous_month = ( new DateTimeImmutable( $sec_month . '-01', wp_timezone() ) )->modify( '-1 month' )->format( 'Y-m' );
    $sec_settings = LimuCRM\settings();
    $sec_settings['duplicate_mode'] = 'calendar';
    $sec_settings['duplicate_days'] = 30;
    $sec_settings['start_date'] = $sec_month . '-01';
    $sec_settings['automatic'] = false;
    update_option( 'lcrm_settings', $sec_settings, false );

    // Upgrade compatibility is response-only: old clients never receive stored retired mappings.
    $sec_retired_settings = $sec_settings;
    $sec_retired_settings['forms'] = array( 'retired-form' => array( 'institutions' => array( 999999 ), 'email' => 'private-field' ) );
    update_option( 'lcrm_settings', $sec_retired_settings, false );
    $sec_compat_bootstrap = sec_request( 'bootstrap' )->get_data();
    sec_check( array() === $sec_compat_bootstrap['settings']['forms'] && $sec_retired_settings === get_option( 'lcrm_settings' ) && ! array_key_exists( 'forms', LimuCRM\settings() ), 'Bootstrap provides an empty legacy forms array without exposing or changing stored retired mappings' );
    $sec_compat_settings = array( 'duplicate_mode' => 'rolling', 'duplicate_days' => 30, 'automatic' => true, 'start_date' => $sec_settings['start_date'] );
    $sec_compat_input = $sec_compat_settings;
    unset( $sec_compat_input['duplicate_days'] );
    $sec_compat_save = sec_request( 'settings', $sec_compat_input + array( 'forms' => array() ) );
    sec_check( 200 === $sec_compat_save->get_status() && $sec_compat_settings === $sec_compat_save->get_data() && $sec_compat_settings === get_option( 'lcrm_settings' ), 'Older settings clients can save their exact flags while the empty forms compatibility field is never persisted' );
    foreach ( array( array( 'retired-form' => array() ), null, '', false, 0, (object) array(), array( array( 'malformed' ) ) ) as $sec_invalid_forms ) {
        $sec_forms_rejected = sec_request( 'settings', $sec_compat_settings + array( 'forms' => $sec_invalid_forms ) );
        sec_check( 400 === $sec_forms_rejected->get_status() && $sec_compat_settings === get_option( 'lcrm_settings' ), 'Settings reject nonempty, null, scalar, object or malformed legacy forms without modifying configuration: ' . wp_json_encode( $sec_invalid_forms ) );
    }
    foreach ( array( 'agreement', 'member', 'prepare', 'approve', 'payment', 'import', 'treatment', 'remap' ) as $sec_forms_route ) {
        $sec_forms_rejected = sec_request( $sec_forms_route, array( 'forms' => array() ) );
        sec_check( 400 === $sec_forms_rejected->get_status() && 'שדה לא תקין.' === $sec_forms_rejected->get_data()['message'], 'The empty legacy forms field is rejected before handling the ' . $sec_forms_route . ' mutation' );
    }
    sec_check( false === has_action( 'elementor_pro/forms/new_record', 'LimuCRM\\new_record' ) && false === has_action( 'elementor_pro/forms/validation', 'LimuCRM\\validate_turnstile' ), 'Legacy settings compatibility does not restore retired Elementor capture or validation hooks' );

    foreach ( array( 'calendar', 'rolling', 'days_30', 'days_90', 'days_180', 'months_24', 'custom' ) as $sec_mode ) {
        $sec_mode_settings = $sec_compat_settings;
        $sec_mode_settings['duplicate_mode'] = $sec_mode;
        $sec_mode_settings['duplicate_days'] = 47;
        $sec_mode_response = sec_request( 'settings', $sec_mode_settings );
        sec_check( 200 === $sec_mode_response->get_status() && $sec_mode_settings === get_option( 'lcrm_settings' ) && ( 'custom' === $sec_mode ? 'days:47' : $sec_mode ) === LimuCRM\duplicate_policy( LimuCRM\settings() ), 'The duplicate period is saved and resolved to its immutable delivery policy: ' . $sec_mode );
    }
    $sec_custom_missing = $sec_compat_input;
    $sec_custom_missing['duplicate_mode'] = 'custom';
    $sec_before_invalid_mode = get_option( 'lcrm_settings' );
    sec_check( 400 === sec_request( 'settings', $sec_custom_missing )->get_status() && $sec_before_invalid_mode === get_option( 'lcrm_settings' ), 'A custom duplicate period requires an explicitly supplied day count, even when a previous count exists' );
    foreach ( array( 0, 3651, -1, true, false, null, '1.5', 30.5, 'abc', '', array() ) as $sec_invalid_days ) {
        $sec_invalid_custom = $sec_compat_settings;
        $sec_invalid_custom['duplicate_mode'] = 'custom';
        $sec_invalid_custom['duplicate_days'] = $sec_invalid_days;
        sec_check( 400 === sec_request( 'settings', $sec_invalid_custom )->get_status() && $sec_before_invalid_mode === get_option( 'lcrm_settings' ), 'Invalid custom duplicate day counts cannot modify configuration: ' . wp_json_encode( $sec_invalid_days ) );
    }
    $sec_invalid_mode = $sec_compat_settings;
    $sec_invalid_mode['duplicate_mode'] = 'unknown';
    sec_check( 400 === sec_request( 'settings', $sec_invalid_mode )->get_status() && $sec_before_invalid_mode === get_option( 'lcrm_settings' ), 'Unknown duplicate period identifiers are rejected' );
    foreach ( array( 1, 3650, '180' ) as $sec_valid_days ) {
        $sec_valid_custom = $sec_compat_settings;
        $sec_valid_custom['duplicate_mode'] = 'custom';
        $sec_valid_custom['duplicate_days'] = $sec_valid_days;
        $sec_valid_response = sec_request( 'settings', $sec_valid_custom );
        sec_check( 200 === $sec_valid_response->get_status() && (int) $sec_valid_days === $sec_valid_response->get_data()['duplicate_days'], 'Custom duplicate windows accept valid integer bounds and normalize decimal digit input: ' . $sec_valid_days );
    }
    $sec_preserve_days = $sec_compat_input;
    $sec_preserve_days['duplicate_mode'] = 'days_90';
    $sec_preserve_response = sec_request( 'settings', $sec_preserve_days );
    sec_check( 200 === $sec_preserve_response->get_status() && 180 === $sec_preserve_response->get_data()['duplicate_days'], 'Selecting a preset without a day field preserves the manager\'s previous custom day count' );
    $sec_advanced_before_legacy = get_option( 'lcrm_settings' );
    $sec_legacy_reset = $sec_compat_input;
    $sec_legacy_reset['duplicate_mode'] = 'calendar';
    sec_check( 409 === sec_request( 'settings', $sec_legacy_reset + array( 'forms' => array() ) )->get_status() && $sec_advanced_before_legacy === get_option( 'lcrm_settings' ), 'A cached legacy settings form cannot silently reset an advanced duplicate period to its calendar default' );
    update_option( 'lcrm_settings', $sec_settings, false );

    sec_check( null === LimuCRM\money( true ) && null === LimuCRM\money( false ), 'Boolean values cannot become monetary amounts' );
    sec_check( null === LimuCRM\money( 1.25 ) && 125 === LimuCRM\money( '1.25' ) && 100 === LimuCRM\money( 1 ), 'Decimal money accepts exact decimal strings and integer units only' );
    $sec_tag = 'Security ' . wp_generate_uuid4();
    $sec_a = sec_institution( $sec_tag . ' chronology', $sec_month . '-01' );
    $sec_b = sec_institution( $sec_tag . ' frozen', $sec_month . '-01' );
    $sec_literal_institution = sec_institution( $sec_tag . ' literal values', $sec_month . '-01' );

    $sec_vat_institution = sec_institution( $sec_tag . ' fixed VAT', $sec_month . '-01' );
    $sec_vat_response = sec_request( 'agreement', array( 'institution' => $sec_vat_institution, 'price' => '17.50', 'vat_percent' => '99', 'from' => $sec_month . '-01', 'credit_days' => '30' ) );
    sec_check( 200 === $sec_vat_response->get_status() && 1800 === $sec_vat_response->get_data()['rates'][0]['vat_bp'], 'A forged per-institution VAT value cannot change the server-wide 18 percent rate' );
    $sec_vat_config = LimuCRM\agreement( $sec_vat_institution );
    $sec_vat_config['rates'][0]['vat_bp'] = 1700;
    update_post_meta( $sec_vat_institution, '_lcrm_agreement', $sec_vat_config );
    $sec_vat_delivery = sec_delivery( $sec_vat_institution, $sec_month . '-03 10:00:00', $sec_tag . ':fixed-vat', '0598876001' );
    $sec_vat_bill = LimuCRM\locked( function () use ( $sec_vat_institution, $sec_month ) { return LimuCRM\prepare_bill( $sec_vat_institution, $sec_month ); } );
    sec_check( 1800 === $sec_vat_bill['lines'][0]['vat_bp'] && 315 === $sec_vat_bill['vat'] && 2065 === $sec_vat_bill['total'], 'Draft billing uses fixed 18 percent VAT even when old agreement metadata contains another rate' );
    $sec_vat_approved = LimuCRM\locked( function () use ( $sec_vat_bill ) { return LimuCRM\approve_bill( $sec_vat_bill['id'] ); } );
    $sec_vat_config['rates'][0]['vat_bp'] = 0;
    update_post_meta( $sec_vat_institution, '_lcrm_agreement', $sec_vat_config );
    $sec_vat_frozen = LimuCRM\locked( function () use ( $sec_vat_institution, $sec_month ) { return LimuCRM\prepare_bill( $sec_vat_institution, $sec_month ); } );
    sec_check( ! is_wp_error( $sec_vat_approved ) && $sec_vat_approved === $sec_vat_frozen, 'Fixed VAT enforcement leaves approved historical bill snapshots immutable' );

    // A homogeneous virtual history exercises pagination without writing thousands of disposable rows.
    $sec_large_query = function ( $query ) use ( $sec_vat_institution ) {
        if ( 'lcrm_delivery' !== $query->get( 'post_type' ) ) {
            return false;
        }
        foreach ( (array) $query->get( 'meta_query' ) as $clause ) {
            if ( is_array( $clause ) && '_lcrm_institution' === ( $clause['key'] ?? '' ) && $sec_vat_institution === ( $clause['value'] ?? null ) ) {
                return true;
            }
        }
        return false;
    };
    $sec_large_posts = function ( $posts, $query ) use ( $sec_large_query, $sec_vat_delivery ) {
        if ( ! $sec_large_query( $query ) ) {
            return $posts;
        }
        $size = max( 1, (int) $query->get( 'posts_per_page' ) );
        $offset = ( max( 1, (int) $query->get( 'paged' ) ) - 1 ) * $size;
        $query->found_posts = 5011;
        $query->max_num_pages = (int) ceil( 5011 / $size );
        return array_fill( 0, max( 0, min( $size, 5011 - $offset ) ), get_post( $sec_vat_delivery['id'] ) );
    };
    add_filter( 'posts_pre_query', $sec_large_posts, 10, 2 );
    $sec_large_summary = sec_request( 'summary', null, array( 'institution' => $sec_vat_institution ) );
    remove_filter( 'posts_pre_query', $sec_large_posts, 10 );
    $sec_large_posts = null;
    $sec_large_data = $sec_large_summary->get_data();
    sec_check( 200 === $sec_large_summary->get_status() && 5011 === $sec_large_data['leads'] && 5011 === $sec_large_data['by_institution'][ $sec_vat_institution ] && 2065 === $sec_large_data['approved'], 'Dashboard totals include every row beyond the former 5000-row ceiling and retain scoped bill totals' );

    $sec_slash_name = 'Security C:\\samples\\literal';
    $sec_slash_note = 'Notes C:\\samples\\literal and \\another\\path';
    $sec_slash_lead = array( 'name' => $sec_slash_name, 'phone' => '0598891234', 'email' => '', 'date' => $sec_month . '-11 12:00:00', 'form' => 'Security fixture' );
    $sec_slash_source = $sec_tag . ':literal-source';
    $sec_slash_delivery = LimuCRM\locked( function () use ( $sec_literal_institution, $sec_slash_lead, $sec_slash_source ) { return LimuCRM\record_delivery( $sec_slash_lead, $sec_literal_institution, $sec_slash_source ); } );
    sec_check( ! is_wp_error( $sec_slash_delivery ) && $sec_slash_name === $sec_slash_delivery['name'] && $sec_slash_name === get_post_field( 'post_title', $sec_slash_delivery['id'], 'raw' ), 'Contact values and searchable titles preserve literal backslashes' );
    sec_check( 200 === sec_request( 'treatment', array( 'id' => $sec_slash_delivery['id'], 'treatment' => 'new', 'note' => $sec_slash_note ) )->get_status() && $sec_slash_note === LimuCRM\data( $sec_slash_delivery['id'] )['notes'][0]['text'], 'Internal notes preserve literal backslashes without failed verification' );
    $sec_changed_lead = $sec_slash_lead;
    $sec_changed_lead['phone'] = '0598891235';
    $sec_same_source = LimuCRM\locked( function () use ( $sec_literal_institution, $sec_changed_lead, $sec_slash_source ) { return LimuCRM\record_delivery( $sec_changed_lead, $sec_literal_institution, $sec_slash_source ); } );
    sec_check( is_wp_error( $sec_same_source ) && 409 === $sec_same_source->get_error_data()['status'], 'Source key cannot silently change contact data for the same institution' );
    $sec_shared_source = LimuCRM\locked( function () use ( $sec_b, $sec_changed_lead, $sec_slash_source ) { return LimuCRM\record_delivery( $sec_changed_lead, $sec_b, $sec_slash_source ); } );
    sec_check( is_wp_error( $sec_shared_source ) && 409 === $sec_shared_source->get_error_data()['status'], 'Shared source key cannot mix different contacts across institutions' );

    $sec_newer = sec_delivery( $sec_a, $sec_month . '-06 10:00:00', $sec_tag . ':newer', '0591113300' );
    sec_check( ! is_wp_error( $sec_newer ) && 'sent' === $sec_newer['state'], 'Newer automatic delivery can arrive first' );
    $sec_older = sec_delivery( $sec_a, $sec_month . '-05 10:00:00', $sec_tag . ':older', '0591113300' );
    sec_check( ! is_wp_error( $sec_older ) && 'sent' === $sec_older['state'], 'Earlier automatic delivery can arrive later without manager intervention' );
    $sec_older = LimuCRM\data( $sec_older['id'] );
    $sec_newer = LimuCRM\data( $sec_newer['id'] );
    sec_check( 0 === $sec_older['duplicate_of'] && $sec_older['id'] === $sec_newer['duplicate_of'], 'Automatic arrival order cannot make both matching deliveries billable' );
    $sec_draft = LimuCRM\locked( function () use ( $sec_a, $sec_month ) { return LimuCRM\prepare_bill( $sec_a, $sec_month ); } );
    sec_check( ! is_wp_error( $sec_draft ) && 1 === count( $sec_draft['lines'] ) && 2065 === $sec_draft['total'], 'Reconciled chronology bills the matching contact once' );

    $sec_frozen_newer = sec_delivery( $sec_b, $sec_month . '-06 11:00:00', $sec_tag . ':frozen-newer', '0591114400' );
    sec_check( ! is_wp_error( $sec_frozen_newer ) && 'sent' === $sec_frozen_newer['state'], 'Separate frozen-billing fixture arrives automatically' );
    $sec_bill = LimuCRM\locked( function () use ( $sec_b, $sec_month ) { return LimuCRM\prepare_bill( $sec_b, $sec_month ); } );
    $sec_approved = LimuCRM\locked( function () use ( $sec_bill ) { return LimuCRM\approve_bill( $sec_bill['id'] ); } );
    sec_check( ! is_wp_error( $sec_approved ) && 'approved' === $sec_approved['state'], 'Separate security bill approved' );
    $sec_frozen_older = sec_delivery( $sec_b, $sec_previous_month . '-05 11:00:00', $sec_tag . ':frozen-older', '0591114400' );
    sec_check( is_wp_error( $sec_frozen_older ) && 409 === $sec_frozen_older->get_error_data()['status'], 'Earlier-month automatic delivery cannot change a later approved billing period' );
    sec_check( 0 === LimuCRM\query_records( 'lcrm_delivery', array( 'source' => $sec_tag . ':frozen-older' ) )['total'] && $sec_approved === LimuCRM\data( $sec_bill['id'] ), 'Rejected automatic arrival leaves no partial record and preserves the approved snapshot' );
    $sec_late = LimuCRM\locked( function () use ( $sec_b, $sec_month, $sec_tag ) {
        return LimuCRM\record_delivery( array( 'name' => 'Late synthetic delivery', 'phone' => '0591115500', 'email' => '', 'date' => $sec_month . '-10 12:00:00', 'form' => 'Security fixture' ), $sec_b, $sec_tag . ':late-confirmed' );
    } );
    sec_check( is_wp_error( $sec_late ) && 409 === $sec_late->get_error_data()['status'], 'Trusted delivery cannot silently disappear from an already approved month' );

    $sec_payment_input = array( 'bill' => $sec_bill['id'], 'amount' => '1.00', 'date' => current_time( 'Y-m-d' ), 'method' => 'transfer', 'reference' => 'Synthetic-only', 'request_key' => wp_generate_uuid4() );
    $sec_payment = sec_request( 'payment', $sec_payment_input );
    sec_check( 200 === $sec_payment->get_status(), 'Isolated idempotent payment recorded' );
    $sec_replay = sec_request( 'payment', $sec_payment_input );
    sec_check( 200 === $sec_replay->get_status() && $sec_payment->get_data()['id'] === $sec_replay->get_data()['id'], 'Exact payment replay returns the existing payment' );
    $sec_payment_input['amount'] = '2.00';
    sec_check( 409 === sec_request( 'payment', $sec_payment_input )->get_status() && 100 === LimuCRM\data( $sec_bill['id'] )['paid'], 'Changed payment payload cannot reuse an already committed request key' );

    $sec_rolling_settings = $sec_settings;
    $sec_rolling_settings['duplicate_mode'] = 'rolling';
    update_option( 'lcrm_settings', $sec_rolling_settings, false );
    $sec_c = sec_institution( $sec_tag . ' rolling chain', $sec_month . '-01' );
    $sec_current_month = current_time( 'Y-m' );
    $sec_chain_start = ( new DateTimeImmutable( $sec_current_month . '-01', wp_timezone() ) )->modify( '-12 months' )->format( 'Y-m-d' );
    $sec_chain_b = sec_delivery( $sec_c, $sec_month . '-20 10:00:00', $sec_tag . ':chain-b', '0591116600' );
    $sec_chain_c = sec_delivery( $sec_c, $sec_current_month . '-03 10:00:00', $sec_tag . ':chain-c', '0591116600' );
    sec_check( ! is_wp_error( $sec_chain_b ) && ! is_wp_error( $sec_chain_c ) && 'sent' === $sec_chain_b['state'] && 'sent' === $sec_chain_c['state'], 'Rolling chain can arrive automatically before its older anchor' );
    sec_check( $sec_chain_b['id'] === LimuCRM\data( $sec_chain_c['id'] )['duplicate_of'], 'Rolling tail initially duplicates the first automatic anchor' );
    $sec_chain_a = sec_delivery( $sec_c, $sec_chain_start . ' 10:00:00', $sec_tag . ':chain-a', '0591116600' );
    sec_check( ! is_wp_error( $sec_chain_a ) && 'sent' === $sec_chain_a['state'], 'Earlier rolling anchor can arrive automatically when future periods remain editable' );
    sec_check( $sec_chain_a['id'] === LimuCRM\data( $sec_chain_b['id'] )['duplicate_of'] && 0 === LimuCRM\data( $sec_chain_c['id'] )['duplicate_of'], 'Rolling reconciliation updates the full chain and expires at the older anniversary' );

    $sec_d = sec_institution( $sec_tag . ' frozen duplicate chain', $sec_month . '-01' );
    $sec_frozen_chain_start = ( new DateTimeImmutable( $sec_month . '-01', wp_timezone() ) )->modify( '-12 months' )->format( 'Y-m-d' );
    $sec_frozen_chain_b = sec_delivery( $sec_d, $sec_previous_month . '-20 10:00:00', $sec_tag . ':frozen-chain-b', '0591117700' );
    $sec_frozen_chain_c = sec_delivery( $sec_d, $sec_month . '-03 10:00:00', $sec_tag . ':frozen-chain-c', '0591117700' );
    $sec_unique = sec_delivery( $sec_d, $sec_month . '-04 10:00:00', $sec_tag . ':frozen-chain-unique', '0591118800' );
    $sec_chain_bill = LimuCRM\locked( function () use ( $sec_d, $sec_month ) { return LimuCRM\prepare_bill( $sec_d, $sec_month ); } );
    $sec_chain_approved = LimuCRM\locked( function () use ( $sec_chain_bill ) { return LimuCRM\approve_bill( $sec_chain_bill['id'] ); } );
    sec_check( ! is_wp_error( $sec_chain_approved ) && 1 === count( $sec_chain_approved['lines'] ) && 0 === LimuCRM\data( $sec_frozen_chain_c['id'] )['bill'], 'Approved month can contain an excluded duplicate with no bill attachment' );
    $sec_frozen_chain_a = sec_delivery( $sec_d, $sec_frozen_chain_start . ' 10:00:00', $sec_tag . ':frozen-chain-a', '0591117700' );
    sec_check( is_wp_error( $sec_frozen_chain_a ) && 409 === $sec_frozen_chain_a->get_error_data()['status'], 'Late automatic anchor cannot make an excluded duplicate billable in an approved later month' );
    sec_check( 0 === LimuCRM\query_records( 'lcrm_delivery', array( 'source' => $sec_tag . ':frozen-chain-a' ) )['total'] && 0 === LimuCRM\data( $sec_frozen_chain_b['id'] )['duplicate_of'] && $sec_frozen_chain_b['id'] === LimuCRM\data( $sec_frozen_chain_c['id'] )['duplicate_of'] && $sec_chain_approved === LimuCRM\data( $sec_chain_bill['id'] ), 'Failed rolling reconciliation rolls back all earlier chain edits and preserves the snapshot' );
    update_option( 'lcrm_settings', $sec_settings, false );

    foreach ( array( false, true ) as $sec_unmapped ) {
        $sec_legacy = sec_legacy( $sec_unmapped ? $sec_tag . ' missing recipient' : get_the_title( $sec_a ), $sec_month . '-03 09:00:00' );
        $sec_fault = function ( $check, $id, $key, $value ) use ( $sec_legacy ) {
            if ( '_lcrm_data' === $key && is_array( $value ) && isset( $value['source'] ) && 'legacy:' . $sec_legacy === $value['source'] ) {
                return false;
            }
            return $check;
        };
        add_filter( 'update_post_metadata', $sec_fault, 10, 4 );
        $sec_failed_import = sec_request( 'import', array( 'after' => $sec_legacy - 1 ) );
        remove_filter( 'update_post_metadata', $sec_fault, 10 );
        $sec_fault = null;
        sec_check( 500 === $sec_failed_import->get_status(), ( $sec_unmapped ? 'Unmapped' : 'Mapped' ) . ' import persistence failure stops the batch' );
        $sec_rows = LimuCRM\query_records( 'lcrm_delivery', array( 'source' => 'legacy:' . $sec_legacy ) );
        sec_check( 0 === $sec_rows['total'], 'Failed historical batch leaves no partial delivery record' );
        $sec_retry = sec_request( 'import', array( 'after' => $sec_legacy - 1 ) );
        $sec_retry_data = $sec_retry->get_data();
        sec_check( 200 === $sec_retry->get_status() && $sec_legacy === $sec_retry_data['after'] && 1 === $sec_retry_data['processed'], 'Historical batch retries safely from its unchanged cursor' );
        $sec_rows = LimuCRM\query_records( 'lcrm_delivery', array( 'source' => 'legacy:' . $sec_legacy ) );
        sec_check( 1 === $sec_rows['total'] && ( $sec_unmapped ? 'unmapped' : 'historical' ) === $sec_rows['items'][0]['state'], 'Retried historical record remains nonbillable' );
    }

    foreach ( array( 'institution', 'month', 'year', 'state', 'page', 'search' ) as $sec_param ) {
        sec_check( 400 === sec_request( 'deliveries', null, array( $sec_param => array( 'malformed' ) ) )->get_status(), 'Array query input rejected for ' . $sec_param );
    }
    foreach ( array( '99', '1899', '10000', '2026-01', 'abcd', true ) as $sec_year ) {
        sec_check( 400 === sec_request( 'summary', null, array( 'year' => $sec_year ) )->get_status(), 'Malformed or out-of-range full-year input is rejected: ' . var_export( $sec_year, true ) );
    }
    foreach ( array( 'summary', 'deliveries', 'report', 'export' ) as $sec_route ) {
        sec_check( 400 === sec_request( $sec_route, null, array( 'month' => $sec_month, 'year' => substr( $sec_month, 0, 4 ), 'target' => 'deliveries' ) )->get_status(), 'Ambiguous simultaneous month and year filters are rejected for ' . $sec_route );
    }
    sec_check( 400 === sec_request( 'deliveries', null, array( 'search' => str_repeat( 'x', 201 ) ) )->get_status(), 'Oversized search is rejected before database work' );
    sec_check( 400 === sec_request( 'treatment', array( 'id' => $sec_older['id'], 'treatment' => 'new', 'note' => str_repeat( 'x', 100000 ) ) )->get_status(), 'Oversized mutation input is rejected before persistence' );
    sec_check( 403 === sec_request( 'treatment', array( 'id' => $sec_older['id'], 'treatment' => 'new' ), array(), false )->get_status(), 'Manager mutation without a REST nonce is rejected' );
    sec_check( 404 === sec_request( 'confirm', array( 'id' => $sec_older['id'], 'evidence' => 'This endpoint must not exist' ) )->get_status(), 'Manual per-lead confirmation route is unavailable even to administrators' );
    sec_check( 400 === sec_request( 'prepare', array( 'month' => $sec_month, 'institutions' => array_fill( 0, 21, $sec_a ) ) )->get_status(), 'Oversized prepare batch is rejected before deduplication or truncation' );
    sec_check( 400 === sec_request( 'approve', array( 'ids' => array_fill( 0, 21, $sec_bill['id'] ) ) )->get_status(), 'Oversized approve batch is rejected before deduplication or truncation' );

    $sec_callback_ran = false;
    $sec_query_fault = function ( $query ) {
        if ( 0 === strpos( $query, 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES' ) ) {
            return str_replace( 'TABLE_NAME, ENGINE', "TABLE_NAME, 'MyISAM' AS ENGINE", $query );
        }
        return $query;
    };
    add_filter( 'query', $sec_query_fault );
    $sec_engine_fault = LimuCRM\locked( function () use ( &$sec_callback_ran ) { $sec_callback_ran = true; return true; } );
    remove_filter( 'query', $sec_query_fault );
    $sec_query_fault = null;
    sec_check( is_wp_error( $sec_engine_fault ) && 503 === $sec_engine_fault->get_error_data()['status'] && ! $sec_callback_ran, 'Nontransactional engine report fails closed before running the mutation' );

    $sec_callback_ran = false;
    $sec_query_fault = function ( $query ) { return 'START TRANSACTION' === $query ? '' : $query; };
    add_filter( 'query', $sec_query_fault );
    $sec_start_fault = LimuCRM\locked( function () use ( &$sec_callback_ran ) { $sec_callback_ran = true; return true; } );
    remove_filter( 'query', $sec_query_fault );
    $sec_query_fault = null;
    sec_check( is_wp_error( $sec_start_fault ) && 503 === $sec_start_fault->get_error_data()['status'] && ! $sec_callback_ran, 'Transaction-start failure fails closed before running the mutation' );

    $sec_query_fault = function ( $query ) { return 'COMMIT' === $query ? '' : $query; };
    add_filter( 'query', $sec_query_fault );
    $sec_commit_fault = LimuCRM\locked( function () use ( $sec_tag ) {
        return LimuCRM\save_record( 'lcrm_contact', array( 'source' => $sec_tag . ':commit-fault', 'name' => 'Synthetic commit failure', 'phone' => '0591119900', 'email' => '' ) );
    } );
    remove_filter( 'query', $sec_query_fault );
    $sec_query_fault = null;
    sec_check( is_wp_error( $sec_commit_fault ) && 500 === $sec_commit_fault->get_error_data()['status'] && 0 === LimuCRM\query_records( 'lcrm_contact', array( 'source' => $sec_tag . ':commit-fault' ) )['total'], 'Commit failure returns an error and rolls back the newly written record' );

    $sec_before_cache = get_option( 'lcrm_settings' );
    $sec_cache_result = LimuCRM\locked( function () use ( $sec_before_cache ) {
        $temporary = $sec_before_cache;
        $temporary['automatic'] = ! $temporary['automatic'];
        update_option( 'lcrm_settings', $temporary, false );
        return LimuCRM\error( 'Synthetic rollback probe', 409 );
    } );
    sec_check( is_wp_error( $sec_cache_result ) && $sec_before_cache === get_option( 'lcrm_settings' ), 'Ordinary error rollback invalidates cached option changes' );
    $sec_nested = LimuCRM\locked( function () { return LimuCRM\locked( function () { return true; } ); } );
    sec_check( is_wp_error( $sec_nested ) && 409 === $sec_nested->get_error_data()['status'], 'Nested transactions cannot bypass the mutation lock' );

    $sec_user_id = wp_create_user( 'sec-' . wp_generate_uuid4(), wp_generate_password( 40 ), 'sec-' . wp_generate_uuid4() . '@example.test' );
    if ( is_wp_error( $sec_user_id ) ) {
        $sec_user_id = 0;
        throw new RuntimeException( 'Unable to create isolated institution-role user.' );
    }
    $sec_user = get_user_by( 'id', $sec_user_id );
    $sec_user->set_role( 'limu_institution' );
    $sec_user_rollback = LimuCRM\locked( function () use ( $sec_user_id, $sec_a ) {
        $GLOBALS['lcrm_changed_users'][] = $sec_user_id;
        update_user_meta( $sec_user_id, '_lcrm_institutions', array( $sec_a ) );
        return LimuCRM\error( 'Synthetic assignment rollback probe', 409 );
    } );
    sec_check( is_wp_error( $sec_user_rollback ) && ! get_user_meta( $sec_user_id, '_lcrm_institutions', true ), 'Failed assignment transaction invalidates cached institution access' );
    wp_set_current_user( $sec_user_id );
    $sec_empty_bootstrap = sec_request( 'bootstrap' )->get_data();
    $sec_empty_deliveries = sec_request( 'deliveries' )->get_data();
    sec_check( null === $sec_empty_bootstrap['settings'], 'Institution bootstrap cannot read manager settings or the legacy compatibility payload' );
    sec_check( array() === $sec_empty_bootstrap['institutions'] && 0 === $sec_empty_deliveries['total'] && array() === $sec_empty_deliveries['items'], 'Institution role without assignments exposes no institution or contact data' );
    $sec_empty_summary = sec_request( 'summary' )->get_data();
    sec_check( 0 === $sec_empty_summary['leads'] && 0 === $sec_empty_summary['approved'] && array() === $sec_empty_summary['by_institution'], 'All-period dashboard exposes no totals to an unassigned institution account' );
    sec_check( 403 === sec_request( 'summary', null, array( 'institution' => $sec_a ) )->get_status(), 'All-period dashboard rejects a forged institution filter' );
    sec_check( 403 === sec_request( 'deliveries', null, array( 'institution' => $sec_a ) )->get_status(), 'Blank institution assignment cannot request another institution directly' );
    sec_check( 403 === sec_request( 'audit' )->get_status() && 403 === sec_request( 'treatment', array( 'id' => $sec_older['id'], 'treatment' => 'new' ) )->get_status(), 'Institution role cannot access audit records or mutate deliveries' );
    sec_check( ! current_user_can( 'read_post', $sec_older['id'] ) && ! current_user_can( 'edit_post', $sec_bill['id'] ), 'Native WordPress private-record capabilities also deny institution users' );

    // The institution picker is complete even when there are more than 100 permitted recipients.
    wp_set_current_user( $sec_admin->ID );
    $sec_many_ids = array();
    for ( $sec_index = 1; $sec_index <= 101; ++$sec_index ) {
        $sec_many_id = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 0 === $sec_index % 2 ? 'draft' : 'publish', 'post_title' => 'zzzz ' . $sec_tag . ' institution ' . sprintf( '%03d', $sec_index ) ), true );
        if ( is_wp_error( $sec_many_id ) ) {
            throw new RuntimeException( 'Unable to create institution-list fixture.' );
        }
        $sec_many_ids[] = $sec_many_id;
    }
    $sec_last_institution = end( $sec_many_ids );
    $sec_last_title = get_post_field( 'post_title', $sec_last_institution, 'raw' );
    $sec_last_agreement = array( 'credit_days' => 30, 'rates' => array( array( 'from' => $sec_month . '-01', 'price' => 1750, 'vat_bp' => 1800 ) ) );
    update_post_meta( $sec_last_institution, '_lcrm_agreement', $sec_last_agreement );
    $sec_trashed_institution = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'trash', 'post_title' => $sec_tag . ' trashed institution' ) );
    $sec_many_bootstrap = sec_request( 'bootstrap' )->get_data();
    $sec_many_entries = array_column( $sec_many_bootstrap['institutions'], null, 'id' );
    sec_check( ! array_diff( $sec_many_ids, array_keys( $sec_many_entries ) ) && ! isset( $sec_many_entries[ $sec_trashed_institution ] ), 'Manager bootstrap includes every one of 101 published/draft institutions and excludes trash' );
    sec_check( $sec_last_title === $sec_many_entries[ $sec_last_institution ]['name'] && $sec_last_agreement === $sec_many_entries[ $sec_last_institution ]['agreement'], 'An institution beyond the old 100-row limit retains its name and tariff for filtering and billing selection' );

    // WordPress text sanitization retains interior XML-forbidden controls: export must replace them.
    $sec_xml_name = sanitize_text_field( "XML fixture\x01text\x0B" );
    $sec_xml_form = sanitize_text_field( "=SUM(A1:A2)\x02" );
    $sec_xml_delivery = LimuCRM\locked( function () use ( $sec_last_institution, $sec_month, $sec_tag, $sec_xml_name, $sec_xml_form ) {
        return LimuCRM\record_delivery( array( 'name' => $sec_xml_name, 'phone' => '0598876501', 'email' => '', 'date' => $sec_month . '-12 10:00:00', 'form' => $sec_xml_form ), $sec_last_institution, $sec_tag . ':xml-controls', true );
    } );
    sec_check( ! is_wp_error( $sec_xml_delivery ) && false !== strpos( $sec_xml_delivery['name'], "\x01" ), 'The XML regression uses a real stored contact containing a control that survives WordPress sanitization' );
    $sec_xml_export = sec_request( 'export', null, array( 'target' => 'deliveries', 'institution' => $sec_last_institution ) );
    $sec_xml_payload = $sec_xml_export->get_data();
    if ( 200 !== $sec_xml_export->get_status() || empty( $sec_xml_payload['file'] ) ) {
        throw new RuntimeException( 'Unable to export the isolated XML fixture.' );
    }
    $sec_export_path = tempnam( sys_get_temp_dir(), 'lcrm-security-' );
    $sec_export_zip = new ZipArchive();
    try {
        file_put_contents( $sec_export_path, base64_decode( $sec_xml_payload['file'], true ) );
        if ( true !== $sec_export_zip->open( $sec_export_path ) ) {
            throw new RuntimeException( 'Unable to open the isolated XLSX archive.' );
        }
        $sec_sheet = $sec_export_zip->getFromName( 'xl/worksheets/sheet1.xml' );
        sec_check( false !== strpos( $sec_sheet, $sec_last_title ), 'XLSX includes the institution name beyond the old 100-row institution limit' );
        sec_check( false !== simplexml_load_string( $sec_sheet ) && false === strpos( $sec_sheet, "\x01" ) && false === strpos( $sec_sheet, "\x02" ) && false === strpos( $sec_sheet, "\x0B" ) && false !== strpos( $sec_sheet, '=SUM(A1:A2)' ) && false === strpos( $sec_sheet, '<f>' ), 'XML-forbidden controls are replaced while formula-like contact text remains a non-executable inline string in a valid worksheet' );
    } finally {
        $sec_export_zip->close();
        unlink( $sec_export_path );
    }
    update_user_meta( $sec_user_id, '_lcrm_institutions', $sec_many_ids );
    wp_set_current_user( $sec_user_id );
    $sec_many_member = sec_request( 'bootstrap' )->get_data();
    $sec_many_member_ids = array_column( $sec_many_member['institutions'], 'id' );
    sec_check( 101 === count( $sec_many_member_ids ) && ! array_diff( $sec_many_ids, $sec_many_member_ids ) && ! in_array( $sec_a, $sec_many_member_ids, true ) && ! array_filter( $sec_many_member['institutions'], static fn( $item ) => isset( $item['agreement'] ) || isset( $item['icount_client'] ) ), 'A representative assigned to 101 institutions receives all its assignments without other institutions or manager-only tariff/client fields' );
    sec_check( 403 === sec_request( 'export', null, array( 'target' => 'deliveries', 'institution' => $sec_a ) )->get_status() && 403 === sec_request( 'report', null, array( 'target' => 'bills', 'institution' => $sec_a ) )->get_status(), 'Complete institution lists preserve foreign-institution access denial for Excel and print reports' );
    wp_set_current_user( 0 );
    sec_check( 403 === sec_request( 'bootstrap' )->get_status(), 'Anonymous requests remain denied' );
    echo "\n$sec_passed security regression assertions passed.\n";
} catch ( Throwable $exception ) {
    $sec_failure = $exception;
    fwrite( STDERR, 'FAIL: ' . $exception->getMessage() . "\n" );
} finally {
    if ( $sec_large_posts ) { remove_filter( 'posts_pre_query', $sec_large_posts, 10 ); }
    if ( $sec_fault ) {
        remove_filter( 'update_post_metadata', $sec_fault, 10 );
    }
    if ( $sec_query_fault ) {
        remove_filter( 'query', $sec_query_fault );
    }
    remove_action( 'wp_insert_post', $sec_post_tracker, 10 );
    wp_set_current_user( isset( $sec_admin ) && $sec_admin ? $sec_admin->ID : 0 );
    foreach ( array_reverse( array_unique( $sec_posts ) ) as $sec_post_id ) {
        wp_delete_post( $sec_post_id, true );
    }
    if ( $sec_user_id ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $sec_user_id );
    }
    if ( null === $sec_original_settings ) {
        delete_option( 'lcrm_settings' );
    } else {
        update_option( 'lcrm_settings', $sec_original_settings, false );
    }
}
if ( $sec_failure ) {
    exit( 1 );
}
