<?php
/**
 * Short-lived, permission-scoped dashboard cache, invalidated after committed writes.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invalidate summaries without storing contact data or an autoloaded option.
 *
 * @return void
 */
function invalidate_summary_cache() {
	update_option( 'lcrm_summary_revision', wp_generate_uuid4(), false );
}

/**
 * Invalidate once after private records commit, without hydrating every changed post.
 *
 * @param array $ids Changed private record IDs.
 * @return void
 */
function summary_records_committed( $ids ) {
	if ( $ids ) {
		invalidate_summary_cache();
	}
}
add_action( 'lcrm_committed', __NAMESPACE__ . '\\summary_records_committed' );

/**
 * Handle maintenance changes outside the CRM transaction API.
 *
 * @param int $id Changed object ID.
 * @return void
 */
function summary_record_changed( $id ) {
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) && in_array( get_post_type( $id ), array( 'lcrm_delivery', 'lcrm_bill', 'lcrm_payment' ), true ) ) {
		summary_records_committed( array( $id ) );
	}
}
foreach ( array( 'lcrm_delivery', 'lcrm_bill', 'lcrm_payment' ) as $summary_type ) {
	add_action( 'save_post_' . $summary_type, __NAMESPACE__ . '\\summary_record_changed' );
}
unset( $summary_type );
add_action( 'before_delete_post', __NAMESPACE__ . '\\summary_record_changed' );

/**
 * Invalidate when relevant private metadata is changed outside transactions.
 *
 * @param int    $meta_id Changed metadata row ID.
 * @param int    $object_id Private record ID.
 * @param string $key Changed metadata name.
 * @return void
 */
function summary_metadata_changed( $meta_id, $object_id, $key ) {
	if ( in_array( $key, array( '_lcrm_data', '_lcrm_state', '_lcrm_institution', '_lcrm_month', '_lcrm_date', '_lcrm_source' ), true ) ) {
		summary_record_changed( $object_id );
	}
}
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $summary_hook ) {
	add_action( $summary_hook, __NAMESPACE__ . '\\summary_metadata_changed', 10, 3 );
}
unset( $summary_hook );

/**
 * Read a scoped summary; errors and in-flight transaction results are never cached.
 *
 * @param \WP_REST_Request $request Validated reporting request.
 * @return array|\WP_Error Dashboard totals or a safe error.
 */
function cached_summary( $request ) {
	global $wpdb;
	if ( ! can_view() ) {
		return error( 'אין הרשאה.', 403 );
	}
	$filters = period_filters( $request );
	if ( is_wp_error( $filters ) ) {
		return $filters;
	}
	$raw_id = $request->get_param( 'institution' );
	if ( null !== $raw_id && '' !== $raw_id && ( ! is_scalar( $raw_id ) || is_bool( $raw_id ) || ! preg_match( '/^\d{1,10}$/D', (string) $raw_id ) ) ) {
		return error( 'מוסד לא תקין.' );
	}
	$id = absint( $raw_id );
	if ( $id && ! owns( $id ) ) {
		return error( 'אין הרשאה למוסד.', 403 );
	}
	$allowed = manager() ? array() : allowed_institutions();
	sort( $allowed, SORT_NUMERIC );
	ksort( $filters );
	$revision = get_option( 'lcrm_summary_revision', 'initial' );
	$scope    = array( LIMU_CRM_VERSION, get_current_blog_id(), get_current_user_id(), manager(), $allowed, $filters, $id, current_time( 'Y-m-d' ), $revision );
	$key      = 'lcrm_summary_' . hash( 'sha256', wp_json_encode( $scope ) );
	$in_tx    = isset( $GLOBALS['lcrm_changed_records'] );
	$cached   = $in_tx ? false : get_transient( $key );
	if ( is_array( $cached ) && isset( $cached['totals'] ) && is_array( $cached['totals'] ) && ( $cached['scope'] ?? '' ) === $key ) {
		return $cached['totals'];
	}

	// Observe errors across the whole calculation; a later successful SQL query must not hide one.
	$failed           = false;
	$wpdb->last_error = '';
	$observe          = static function ( $query ) use ( &$failed, $wpdb ) {
		if ( $wpdb->last_error || '' === trim( $query ) ) {
			$failed = true;
		}
		return $query;
	};
	add_filter( 'query', $observe, PHP_INT_MAX );
	try {
		$result = summary( $request );
		$failed = $failed || (bool) $wpdb->last_error;
	} catch ( \Throwable $exception ) {
		$result = error( 'לא ניתן לטעון את הסיכום. יש לנסות שוב.', 503 );
	} finally {
		remove_filter( 'query', $observe, PHP_INT_MAX );
	}
	if ( $failed ) {
		return error( 'לא ניתן לטעון את הסיכום. יש לנסות שוב.', 503 );
	}
	if ( ! $in_tx && ! is_wp_error( $result ) ) {
		set_transient(
			$key,
			array(
				'scope'  => $key,
				'totals' => $result,
			),
			30
		);
	}
	return $result;
}
