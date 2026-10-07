<?php
/**
 * Automatically observe existing site lead persistence and its established AJAX dispatch.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.SlowDBQuery -- Bounded exact source/CID lookups reuse existing metadata without new tables.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Track only source leads first created in this request; read their final metadata later.
 *
 * @param int      $id Native lead ID.
 * @param \WP_Post $post Source post.
 * @param bool     $update Whether this is an existing post update.
 * @return void
 */
function native_collect( $id, $post, $update ) {
	if ( 'leads' !== $post->post_type ) {
		return;
	}
	if ( ! $update ) {
		$GLOBALS['lcrm_native_new_leads'][ $id ] = true;
	}
	if ( 'publish' === $post->post_status && ! empty( $GLOBALS['lcrm_native_new_leads'][ $id ] ) ) {
		$GLOBALS['lcrm_native_queue'][ $id ]           = true;
		$GLOBALS['lcrm_native_published_leads'][ $id ] = true;
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\native_collect', 20, 3 );

add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'leads' === $post->post_type && ! empty( $GLOBALS['lcrm_native_new_leads'][ $post->ID ] ) ) {
			$GLOBALS['lcrm_native_queue'][ $post->ID ]           = true;
			$GLOBALS['lcrm_native_published_leads'][ $post->ID ] = true;
		}
	},
	20,
	3
);

/**
 * Keep source detection available after flushing to prevent a second form capture.
 *
 * @return bool Whether this request created native leads.
 */
function native_has_new_leads() {
	return ! empty( $GLOBALS['lcrm_native_published_leads'] );
}

/**
 * Build the exact institution title mapping used by historical/native source adapters.
 *
 * @return array Unique and ambiguous title mappings.
 */
function native_titles() {
	$titles = array();
	$ids    = get_posts(
		array(
			'post_type'      => 'institutions',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $ids as $id ) {
		$titles[ get_post_field( 'post_title', $id, 'raw' ) ][] = $id;
	}
	return $titles;
}

/**
 * Notify integration failures without logging contacts or source identifiers.
 *
 * @param \WP_Error $result Failed operation.
 * @param int       $institution Recipient ID, or zero for unresolved records.
 * @param string    $source Private idempotency source shared only with trusted PHP listeners.
 * @return void
 */
function native_report_error( $result, $institution, $source ) {
	$details = $result->get_error_data();
	audit( 'native_capture_error', $institution, array( 'status' => is_array( $details ) ? ( $details['status'] ?? 500 ) : 500 ) );
	do_action( 'limu_crm_delivery_error', $result, $institution, $source );
}

/**
 * Persist one newly observed source lead; old-dated records remain historical.
 *
 * @param int $id Existing native lead post ID.
 * @return array|\WP_Error Result without copied contact records.
 */
function native_capture( $id ) {
	$post = get_post( $id );
	if ( ! $post || 'leads' !== $post->post_type || 'publish' !== $post->post_status ) {
		return error( 'מקור פנייה לא תקין.' );
	}
	$lead = array(
		'date'      => $post->post_date,
		'contact'   => (int) $id,
		'legacy_id' => (int) $id,
	);
	foreach ( array(
		'name'  => 'full-name',
		'phone' => 'phone',
		'email' => 'email',
		'form'  => 'form-name',
	) as $field => $meta ) {
		$value          = get_post_meta( $id, $meta, true );
		$lead[ $field ] = is_scalar( $value ) ? sanitize_text_field( substr( (string) $value, 0, 600 ) ) : '';
	}
	$lead['email']       = sanitize_email( $lead['email'] );
	$boundary            = (string) get_option( 'lcrm_live_capture_from', '' );
	$live                = '' !== $boundary && $post->post_date >= $boundary && ! empty( $GLOBALS['lcrm_native_new_leads'][ $id ] );
	$lead['origin_live'] = $live;
	$lead['phone_key']   = normalize_phone( $lead['phone'] );
	$lead['email_key']   = normalize_email( $lead['email'] );
	$lead['capture_via'] = 'native';
	$source              = 'legacy:' . $id;
	$value               = get_post_meta( $id, 'institution', true );
	$destinations        = is_scalar( $value ) ? legacy_institutions( (string) $value, native_titles() ) : array();
	if ( ! $destinations || ( $live && ! $lead['phone_key'] && ! $lead['email_key'] ) ) {
		$exists = get_posts(
			array(
				'post_type'      => 'lcrm_delivery',
				'post_status'    => 'private',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_lcrm_source',
				'meta_value'     => $source,
			)
		);
		if ( $exists ) {
			return data( $exists[0] );
		}
		$lead['institution']    = 0;
		$lead['source']         = $source;
		$lead['state']          = 'unmapped';
		$lead['month']          = substr( $post->post_date, 0, 7 );
		$lead['duplicate_of']   = 0;
		$lead['duplicate_mode'] = settings()['duplicate_mode'];
		$lead['bill']           = 0;
		$lead['treatment']      = 'new';
		$lead['notes']          = array();
		$lead['capture_error']  = ! $destinations ? 'institution' : 'contact';
		$result                 = save_record( 'lcrm_delivery', $lead );
		if ( ! is_wp_error( $result ) ) {
			audit( 'native_capture_exception', $result['id'], array( 'reason' => $lead['capture_error'] ) );
		}
		return $result;
	}
	$results = array();
	foreach ( $destinations as $institution ) {
		$result = record_delivery( $lead, $institution, $source, ! $live );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// An overlapping import can store this newly observed source before its shutdown flush.
		if ( $live && 'historical' === $result['state'] ) {
			$bill = bill_for( $institution, $result['month'] );
			if ( $result['bill'] || ( $bill && 'approved' === $bill['state'] ) ) {
				return error( 'התקופה כבר אושרה. נדרשת התאמה כספית נפרדת.', 409 );
			}
			$result['state']          = 'sent';
			$result['origin_live']    = true;
			$result['capture_via']    = 'native';
			$result['duplicate_of']   = 0;
			$result['duplicate_mode'] = settings()['duplicate_mode'];
			$result                   = save_record( 'lcrm_delivery', $result, $result['id'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = reconcile_duplicates( $result );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$results[] = $result;
	}
	return $results;
}

/**
 * Flush final source metadata after the site's form actions have completed.
 *
 * @return array Per-source results for tests and internal integrations.
 */
function native_flush() {
	$ids                          = array_keys( $GLOBALS['lcrm_native_queue'] ?? array() );
	$GLOBALS['lcrm_native_queue'] = array();
	$results                      = array();
	foreach ( $ids as $id ) {
		$result         = locked(
			function () use ( $id ) {
				return native_capture( $id );
			}
		);
		$results[ $id ] = $result;
		if ( is_wp_error( $result ) ) {
			native_report_error( $result, 0, 'legacy:' . $id );
		}
	}
	return $results;
}
add_action( 'shutdown', __NAMESPACE__ . '\\native_flush', 5 );

/**
 * Observe successful transport in the existing institution_interested AJAX handler.
 *
 * @param array|\WP_Error $response WordPress HTTP response.
 * @param string          $context WordPress HTTP debug context.
 * @param string          $transport HTTP transport class.
 * @param array           $args Existing request arguments.
 * @param string          $url Actual outbound URL assembled by the existing handler.
 * @return array|\WP_Error|null Internal result, with no outbound request of its own.
 */
function native_http_capture( $response, $context, $transport, $args, $url ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Observe a completed existing server HTTP request; this adds no public submission endpoint.
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( ! wp_doing_ajax() || 'institution_interested' !== $action || 'response' !== $context || is_wp_error( $response ) || ! is_array( $args ) || ! isset( $args['method'] ) || ! is_string( $args['method'] ) || 'GET' !== strtoupper( $args['method'] ) || ! is_string( $url ) ) {
		return null;
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'ext.bhol.co.il' !== strtolower( $parts['host'] ?? '' ) || '/lead.php' !== ( $parts['path'] ?? '' ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
		return null;
	}
	$status = wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return null;
	}
	$query = array();
	parse_str( $parts['query'] ?? '', $query );
	foreach ( array( 'cId', 'fName', 'phone', 'email' ) as $key ) {
		if ( ! isset( $query[ $key ] ) || ! is_string( $query[ $key ] ) || strlen( $query[ $key ] ) > 600 ) {
			return null;
		}
	}
	$ids = get_posts(
		array(
			'post_type'      => 'institutions',
			'post_status'    => array( 'publish', 'draft' ),
			'fields'         => 'ids',
			'posts_per_page' => 2,
			'meta_key'       => 'cid',
			'meta_value'     => sanitize_text_field( $query['cId'] ),
		)
	);
	if ( 1 !== count( $ids ) || '' === trim( $query['cId'] ) ) {
		return null;
	}
	if ( empty( $GLOBALS['lcrm_native_ajax_source'] ) ) {
		$GLOBALS['lcrm_native_ajax_source'] = 'ajax:' . wp_generate_uuid4();
	}
	$source = $GLOBALS['lcrm_native_ajax_source'];
	$lead   = array(
		'name'        => sanitize_text_field( $query['fName'] ),
		'phone'       => sanitize_text_field( $query['phone'] ),
		'email'       => sanitize_email( $query['email'] ),
		'date'        => current_time( 'mysql' ),
		'form'        => 'institution_interested',
		'origin_live' => true,
		'capture_via' => 'site_ajax',
	);
	$result = locked(
		function () use ( $lead, $ids, $source ) {
			return record_delivery( $lead, $ids[0], $source );
		}
	);
	if ( is_wp_error( $result ) ) {
		native_report_error( $result, $ids[0], $source );
	}
	return $result;
}
add_action( 'http_api_debug', __NAMESPACE__ . '\\native_http_capture', 20, 5 );
