<?php
/** Bill visibility, transaction capabilities and commit hooks in the disposable database only. */
$core_root = getenv( 'LIMU_WP_ROOT' );
if ( ! $core_root ) {
	fwrite( STDERR, "Set LIMU_WP_ROOT to the disposable WordPress installation.\n" );
	exit( 1 );
}
require $core_root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run outside the named limu_test database.\n" );
	exit( 1 );
}
register_post_type( 'institutions', array( 'public' => false ) );
add_filter( 'pre_wp_mail', '__return_true' );
$core_http = function () { return new WP_Error( 'synthetic_test', 'External requests are disabled in this test.' ); };
add_filter( 'pre_http_request', $core_http );
$core_posts = array();
$core_user = 0;
$core_passed = 0;
$core_prefix = 'core-access-' . wp_generate_uuid4();
$core_old_prefix = $wpdb->prefix;
$core_old_capability_cache = $GLOBALS['lcrm_transactional_tables'] ?? null;
$core_query_filter = null;
$core_commit_fault = null;
$core_commit_listener = null;
$core_throw_listener = null;
$core_tracker = function ( $id, $post, $update ) use ( &$core_posts ) {
	if ( ! $update ) {
		$core_posts[] = $id;
	}
};
add_action( 'wp_after_insert_post', $core_tracker, 99, 3 );

function core_check( $condition, $message ) {
	global $core_passed;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$core_passed;
	echo "PASS $message\n";
}

function core_read( $route, $query = array() ) {
	$request = new WP_REST_Request( 'GET', '/limu-crm/v1/' . $route );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	foreach ( $query as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

function core_institution( $label ) {
	global $core_prefix;
	$id = wp_insert_post( array( 'post_type' => 'institutions', 'post_status' => 'publish', 'post_title' => $core_prefix . ' ' . $label ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Unable to create an isolated access fixture.' );
	}
	return $id;
}

function core_bill( $institution, $month, $state, $subtotal ) {
	$vat = intdiv( $subtotal * 1800 + 5000, 10000 );
	return LimuCRM\locked( function () use ( $institution, $month, $state, $subtotal, $vat ) {
		return LimuCRM\save_record( 'lcrm_bill', array( 'institution' => $institution, 'month' => $month, 'state' => $state, 'subtotal' => $subtotal, 'vat' => $vat, 'total' => $subtotal + $vat, 'paid' => 0, 'lines' => array(), 'credit_days' => 30, 'issued' => 'approved' === $state ? $month . '-01' : '', 'due' => 'approved' === $state ? $month . '-28' : '' ) );
	} );
}

function core_contact( $suffix ) {
	global $core_prefix;
	return LimuCRM\save_record( 'lcrm_contact', array( 'source' => $core_prefix . ':' . $suffix, 'name' => 'Synthetic commit fixture', 'phone' => '0596543210', 'email' => '' ) );
}

function core_source_total( $suffix ) {
	global $core_prefix;
	return LimuCRM\query_records( 'lcrm_contact', array( 'source' => $core_prefix . ':' . $suffix ) )['total'];
}

function core_sheet_rows( $response ) {
	$payload = $response->get_data();
	if ( 200 !== $response->get_status() || empty( $payload['file'] ) ) {
		throw new RuntimeException( 'Unable to export the isolated bill access fixture.' );
	}
	$path = tempnam( sys_get_temp_dir(), 'lcrm-core-access-' );
	$zip = new ZipArchive();
	try {
		file_put_contents( $path, base64_decode( $payload['file'], true ) );
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( 'Unable to open the isolated bill export.' );
		}
		$sheet = simplexml_load_string( $zip->getFromName( 'xl/worksheets/sheet1.xml' ) );
		if ( false === $sheet ) {
			throw new RuntimeException( 'The isolated bill export is not valid XML.' );
		}
		return count( $sheet->sheetData->row );
	} finally {
		$zip->close();
		unlink( $path );
	}
}

try {
	$core_admin = get_user_by( 'login', 'crm-admin' );
	if ( ! $core_admin || ! user_can( $core_admin, 'manage_options' ) ) {
		throw new RuntimeException( 'Run the disposable integration setup first.' );
	}
	wp_set_current_user( $core_admin->ID );
	$a = core_institution( 'assigned' );
	$b = core_institution( 'foreign' );
	$first = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'first day of last month' );
	$approved_a = core_bill( $a, $first->format( 'Y-m' ), 'approved', 1000 );
	$approved_b = core_bill( $a, $first->modify( '-1 month' )->format( 'Y-m' ), 'approved', 2000 );
	$foreign_approved = core_bill( $b, $first->format( 'Y-m' ), 'approved', 7777 );
	$draft_ids = array();
	$draft_total = 0;
	for ( $index = 0; $index < 36; ++$index ) {
		$draft = core_bill( $a, $first->modify( '-' . ( $index + 2 ) . ' months' )->format( 'Y-m' ), 'draft', 10000 + $index );
		if ( is_wp_error( $draft ) ) {
			throw new RuntimeException( 'Unable to create the pagination access fixture.' );
		}
		$draft_ids[] = $draft['id'];
		$draft_total += $draft['total'];
	}
	$foreign_draft = core_bill( $b, $first->modify( '-1 month' )->format( 'Y-m' ), 'draft', 22222 );
	$manager_rows = core_read( 'bills', array( 'institution' => $a ) )->get_data();
	$manager_drafts = core_read( 'bills', array( 'institution' => $a, 'state' => 'draft' ) )->get_data();
	$manager_summary = core_read( 'summary', array( 'institution' => $a ) )->get_data();
	core_check( 38 === $manager_rows['total'] && 2 === $manager_rows['pages'] && 36 === $manager_drafts['total'], 'Manager retains all approved and draft bills across pagination' );
	core_check( $draft_total === $manager_summary['draft'] && 3540 === $manager_summary['approved'], 'Manager dashboard retains draft amounts and approved totals' );
	$core_user = wp_create_user( 'core-access-' . wp_generate_uuid4(), wp_generate_password( 40 ), 'core-access-' . wp_generate_uuid4() . '@example.test' );
	if ( is_wp_error( $core_user ) ) {
		$core_user = 0;
		throw new RuntimeException( 'Unable to create an isolated institution viewer.' );
	}
	get_user_by( 'id', $core_user )->set_role( 'limu_institution' );
	update_user_meta( $core_user, '_lcrm_institutions', array( $a ) );
	wp_set_current_user( $core_user );
	$member_response = core_read( 'bills' );
	$member_rows = $member_response->get_data();
	$member_ids = array_column( $member_rows['items'], 'id' );
	$expected_ids = array( $approved_a['id'], $approved_b['id'] );
	sort( $member_ids );
	sort( $expected_ids );
	core_check( 200 === $member_response->get_status() && 2 === $member_rows['total'] && 1 === $member_rows['pages'] && $expected_ids === $member_ids, 'Institution bill lists count and paginate approved rows before returning any result' );
	core_check( ! array_filter( $member_rows['items'], function ( $item ) use ( $a ) { return $a !== $item['institution'] || 'approved' !== $item['state']; } ) && ! array_intersect( $draft_ids, $member_ids ), 'Newer own-institution drafts and foreign approved bills never enter member results' );
	$direct_page = LimuCRM\query_records( 'lcrm_bill', array( 'institution' => $a ), 1, 1 );
	core_check( 2 === $direct_page['total'] && 2 === $direct_page['pages'] && 1 === count( $direct_page['items'] ) && 'approved' === $direct_page['items'][0]['state'], 'Shared query access rules precede a one-row pagination limit' );
	$direct_draft = LimuCRM\query_records( 'lcrm_bill', array( 'institution' => $a, 'state' => 'draft' ) );
	core_check( 0 === $direct_draft['total'] && array() === $direct_draft['items'], 'An explicit own-institution draft filter cannot bypass the shared query gate' );
	$member_drafts_response = core_read( 'bills', array( 'institution' => $a, 'state' => 'draft' ) );
	$member_drafts = $member_drafts_response->get_data();
	core_check( 403 === $member_drafts_response->get_status() || ( 200 === $member_drafts_response->get_status() && 0 === $member_drafts['total'] && array() === $member_drafts['items'] ), 'REST draft filters reveal neither draft rows nor their counts' );
	$member_summary = core_read( 'summary', array( 'institution' => $a ) )->get_data();
	core_check( 0 === $member_summary['draft'] && 3540 === $member_summary['approved'] && 0 === $member_summary['paid'], 'Institution dashboard hides draft totals and retains only its approved balances' );
	$member_report = core_read( 'report', array( 'target' => 'bills', 'institution' => $a ) )->get_data();
	core_check( 2 === $member_report['total'] && ! array_filter( $member_report['items'], function ( $item ) { return 'approved' !== $item['state']; } ), 'Institution print reports contain approved bills only' );
	$member_export = core_read( 'export', array( 'target' => 'bills', 'institution' => $a ) );
	core_check( 2 === $member_export->get_data()['total'] && 3 === core_sheet_rows( $member_export ), 'Institution Excel exports contain exactly two approved bills and a header' );
	foreach ( array( 'report', 'export' ) as $route ) {
		$response = core_read( $route, array( 'target' => 'bills', 'institution' => $a, 'state' => 'draft' ) );
		$payload = $response->get_data();
		core_check( 403 === $response->get_status() || ( 200 === $response->get_status() && 0 === $payload['total'] && ( 'report' === $route ? array() === $payload['items'] : 1 === core_sheet_rows( $response ) ) ), 'A member cannot expose drafts through a direct ' . $route . ' filter' );
	}
	core_check( 403 === core_read( 'bills', array( 'institution' => $b ) )->get_status() && 403 === core_read( 'report', array( 'target' => 'bills', 'institution' => $b ) )->get_status(), 'Approved-only visibility preserves foreign-institution access denial' );
	wp_set_current_user( $core_admin->ID );

	$GLOBALS['lcrm_transactional_tables'] = array();
	$engine_queries = 0;
	$start_queries = 0;
	$engine_fault_scope = null;
	$core_query_filter = function ( $query ) use ( &$engine_queries, &$start_queries, &$engine_fault_scope ) {
		global $wpdb;
		if ( false !== strpos( $query, 'information_schema.TABLES' ) ) {
			++$engine_queries;
			if ( $wpdb->prefix === $engine_fault_scope ) {
				return $wpdb->prepare( "SELECT %s AS TABLE_NAME, 'MyISAM' AS ENGINE UNION ALL SELECT %s, 'InnoDB' UNION ALL SELECT %s, 'InnoDB' UNION ALL SELECT %s, 'InnoDB'", $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->usermeta );
			}
		}
		if ( 'START TRANSACTION' === $query ) {
			++$start_queries;
		}
		return $query;
	};
	add_filter( 'query', $core_query_filter );
	$first_lock = LimuCRM\locked( function () { return true; } );
	$second_lock = LimuCRM\locked( function () { return true; } );
	core_check( true === $first_lock && true === $second_lock && 1 === $engine_queries && 2 === $start_queries, 'Successful table capability checks run once for repeated mutations in one request scope' );
	$cache_key = DB_NAME . ':' . $core_old_prefix;
	core_check( true === LimuCRM\ensure_transactional_tables() && 1 === $engine_queries && ! empty( $GLOBALS['lcrm_transactional_tables'][ $cache_key ] ), 'Capability cache is keyed by database and table prefix and shared by the public helper' );
	$engine_fault_scope = $core_old_prefix . 'synthetic_scope_';
	$wpdb->set_prefix( $engine_fault_scope );
	$callback_ran = false;
	$bad_scope_a = LimuCRM\locked( function () use ( &$callback_ran ) { $callback_ran = true; return true; } );
	$bad_scope_b = LimuCRM\locked( function () use ( &$callback_ran ) { $callback_ran = true; return true; } );
	core_check( is_wp_error( $bad_scope_a ) && 503 === $bad_scope_a->get_error_data()['status'] && is_wp_error( $bad_scope_b ) && 503 === $bad_scope_b->get_error_data()['status'] && ! $callback_ran && 2 === $start_queries, 'A different prefix must verify its own engines and fails before starting a mutation' );
	core_check( 3 === $engine_queries && ! isset( $GLOBALS['lcrm_transactional_tables'][ DB_NAME . ':' . $engine_fault_scope ] ), 'Failed engine checks are retried and never cached as capabilities' );
	$wpdb->set_prefix( $core_old_prefix );
	core_check( true === LimuCRM\ensure_transactional_tables() && 3 === $engine_queries, 'Returning to the verified database prefix reuses only its original successful capability' );
	remove_filter( 'query', $core_query_filter );
	$core_query_filter = null;

	$hook_calls = 0;
	$hook_context = array();
	$watched_id = 0;
	$core_commit_listener = function ( $changed_ids ) use ( &$hook_calls, &$hook_context, &$watched_id ) {
		global $wpdb;
		++$hook_calls;
		$name = 'lcrm_' . md5( DB_NAME . ':' . $wpdb->prefix );
		$hook_context[] = array( 'tracking_clear' => ! isset( $GLOBALS['lcrm_changed_records'] ) && ! isset( $GLOBALS['lcrm_changed_users'] ), 'lock_free' => '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) ), 'transaction_closed' => '0' === (string) $wpdb->get_var( 'SELECT @@in_transaction' ), 'record' => $watched_id ? LimuCRM\data( $watched_id ) : null, 'changed_ids' => $changed_ids );
	};
	add_action( 'lcrm_committed', $core_commit_listener );
	$committed = LimuCRM\locked( function () use ( &$watched_id ) { $record = core_contact( 'committed' ); $watched_id = $record['id']; return $record; } );
	core_check( ! is_wp_error( $committed ) && 1 === $hook_calls && $committed === $hook_context[0]['record'] && $hook_context[0]['tracking_clear'] && $hook_context[0]['lock_free'] && $hook_context[0]['transaction_closed'], 'Commit observers run after durable storage, cache tracking cleanup and advisory lock release' );
	core_check( is_array( $hook_context[0]['changed_ids'] ) && in_array( $committed['id'], $hook_context[0]['changed_ids'], true ), 'Commit observers receive the IDs changed by the committed mutation' );
	$failed = LimuCRM\locked( function () { core_contact( 'ordinary-rollback' ); return LimuCRM\error( 'Synthetic rollback', 409 ); } );
	core_check( is_wp_error( $failed ) && 409 === $failed->get_error_data()['status'] && 1 === $hook_calls && 0 === core_source_total( 'ordinary-rollback' ), 'Ordinary transaction rollback emits no commit event and leaves no saved record' );
	$thrown = LimuCRM\locked( function () { core_contact( 'exception-rollback' ); throw new RuntimeException( 'Synthetic callback exception.' ); } );
	core_check( is_wp_error( $thrown ) && 500 === $thrown->get_error_data()['status'] && 1 === $hook_calls && 0 === core_source_total( 'exception-rollback' ), 'A thrown callback rolls back without notifying commit observers' );
	$core_commit_fault = function ( $query ) { return 'COMMIT' === $query ? '' : $query; };
	add_filter( 'query', $core_commit_fault );
	$commit_failed = LimuCRM\locked( function () { return core_contact( 'failed-commit' ); } );
	remove_filter( 'query', $core_commit_fault );
	$core_commit_fault = null;
	core_check( is_wp_error( $commit_failed ) && 500 === $commit_failed->get_error_data()['status'] && 1 === $hook_calls && 0 === core_source_total( 'failed-commit' ), 'Database commit failure emits no successful commit event' );
	$partial = LimuCRM\locked( function () use ( &$watched_id ) {
		$good = core_contact( 'outer-committed' );
		$watched_id = $good['id'];
		$bad = LimuCRM\attempt( function () { core_contact( 'savepoint-rolled-back' ); return LimuCRM\error( 'Synthetic rejected batch item', 409 ); } );
		return array( 'good' => $good, 'bad' => $bad );
	} );
	core_check( ! is_wp_error( $partial ) && is_wp_error( $partial['bad'] ) && 2 === $hook_calls && 1 === core_source_total( 'outer-committed' ) && 0 === core_source_total( 'savepoint-rolled-back' ) && $partial['good'] === $hook_context[1]['record'], 'A rolled-back savepoint does not emit a separate event when its successful outer transaction commits once' );
	$nested = LimuCRM\locked( function () { return LimuCRM\locked( function () { return true; } ); } );
	core_check( is_wp_error( $nested ) && 409 === $nested->get_error_data()['status'] && 2 === $hook_calls, 'Rejected nested mutations do not emit a commit event' );
	$throw_observed_clean = false;
	$throw_observed_ids = null;
	$core_throw_listener = function ( $changed_ids ) use ( &$throw_observed_clean, &$throw_observed_ids ) {
		$throw_observed_clean = ! isset( $GLOBALS['lcrm_changed_records'] ) && ! isset( $GLOBALS['lcrm_changed_users'] );
		$throw_observed_ids = $changed_ids;
		throw new RuntimeException( 'Synthetic commit observer exception.' );
	};
	add_action( 'lcrm_committed', $core_throw_listener, 20 );
	$observer_exception = LimuCRM\locked( function () use ( &$watched_id ) { $record = core_contact( 'observer-throws' ); $watched_id = $record['id']; return $record; } );
	remove_action( 'lcrm_committed', $core_throw_listener, 20 );
	$core_throw_listener = null;
	$stored_after_observer_exception = is_array( $observer_exception ) ? maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $observer_exception['id'], '_lcrm_data' ) ) ) : null;
	core_check( ! is_wp_error( $observer_exception ) && 3 === $hook_calls && $observer_exception === $stored_after_observer_exception && 1 === core_source_total( 'observer-throws' ) && $throw_observed_clean && in_array( $observer_exception['id'], (array) $throw_observed_ids, true ), 'An observer exception preserves the successful result and database commit after cleanup' );
	core_check( ! isset( $GLOBALS['lcrm_changed_records'] ) && ! isset( $GLOBALS['lcrm_changed_users'] ), 'All success and failure paths clear mutation tracking before returning' );
	echo "OK $core_passed core access and transaction assertions\n";
} finally {
	if ( $core_query_filter ) {
		remove_filter( 'query', $core_query_filter );
	}
	if ( $core_commit_fault ) {
		remove_filter( 'query', $core_commit_fault );
	}
	if ( $core_commit_listener ) {
		remove_action( 'lcrm_committed', $core_commit_listener );
	}
	if ( $core_throw_listener ) {
		remove_action( 'lcrm_committed', $core_throw_listener, 20 );
	}
	$wpdb->set_prefix( $core_old_prefix );
	remove_filter( 'pre_http_request', $core_http );
	remove_action( 'wp_after_insert_post', $core_tracker, 99 );
	wp_set_current_user( isset( $core_admin ) && $core_admin ? $core_admin->ID : 0 );
	foreach ( array_reverse( array_unique( $core_posts ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $core_user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $core_user );
	}
	if ( null === $core_old_capability_cache ) {
		unset( $GLOBALS['lcrm_transactional_tables'] );
	} else {
		$GLOBALS['lcrm_transactional_tables'] = $core_old_capability_cache;
	}
}
