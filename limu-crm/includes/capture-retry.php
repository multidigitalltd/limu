<?php
/**
 * Durable capture markers and a private retry inbox; retries never resend leads.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- Queue acknowledgements and financial decisions must use bounded, authoritative database reads.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CAPTURE_RETRY_VERSION = 1;
const CAPTURE_RETRY_BATCH   = 25;

/**
 * Read the activation boundary without an options-cache fallback.
 *
 * @return string|\WP_Error
 */
function capture_retry_boundary() {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'lcrm_live_capture_from' ), ARRAY_A );
	return $wpdb->num_queries > $queries && ! $wpdb->last_error && is_array( $rows ) && 1 === count( $rows ) && capture_retry_date( $rows[0]['option_value'] ) ? $rows[0]['option_value'] : error( 'לא ניתן לקבוע את מקור הפנייה.', 503 );
}

/**
 * Read the source row without trusting a stale publication status.
 *
 * @param int $id Source ID.
 * @return array|null|\WP_Error
 */
function capture_retry_source( $id ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, post_status, post_date FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) ) {
		return error( 'לא ניתן לקרוא את מקור הפנייה.', 503 );
	}
	return $rows ? $rows[0] : null;
}

/**
 * Verify the delivery before acknowledging its durable source.
 *
 * @param int    $id Delivery ID.
 * @param string $source Original event.
 * @param int    $institution Frozen recipient, or zero for an exception.
 * @param array  $lead Frozen contact and date.
 * @return array|\WP_Error
 */
function capture_retry_delivery( $id, $source, $institution, $lead ) {
	$item = raw_records( array( $id ) )[0];
	if ( ! isset( $item['source'], $item['institution'], $item['date'], $item['phone_key'], $item['email_key'], $item['state'] ) || $source !== $item['source'] || (string) $institution !== (string) $item['institution'] || $lead['date'] !== $item['date'] || normalize_phone( $lead['phone'] ) !== $item['phone_key'] || normalize_email( $lead['email'] ) !== $item['email_key'] || ! in_array( $item['state'], $institution ? array( 'sent', 'historical' ) : array( 'unmapped' ), true ) ) {
		return error( 'לא ניתן לאמת את קליטת הפנייה.', 409 );
	}
	return $item;
}

/**
 * Validate a frozen local event date.
 *
 * @param mixed $date Event date.
 * @return bool
 */
function capture_retry_date( $date ) {
	return is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $date ) && valid_date( substr( $date, 0, 10 ) );
}

/**
 * Validate the small frozen contact payload.
 *
 * @param mixed $lead Payload.
 * @return bool
 */
function capture_retry_lead_valid( $lead ) {
	if ( ! is_array( $lead ) || array_diff( array_keys( $lead ), array( 'name', 'phone', 'email', 'form', 'institution' ) ) ) {
		return false;
	}
	foreach ( array( 'name', 'phone', 'email', 'form' ) as $field ) {
		if ( ! isset( $lead[ $field ] ) || ! is_string( $lead[ $field ] ) || strlen( $lead[ $field ] ) > 600 ) {
			return false;
		}
	}
	return ! isset( $lead['institution'] ) || ( is_string( $lead['institution'] ) && strlen( $lead['institution'] ) <= 8192 );
}

/**
 * Validate the server-created source marker.
 *
 * @param mixed $marker Marker.
 * @return bool
 */
function capture_retry_native_valid( $marker ) {
	return is_array( $marker ) && ! array_diff( array_keys( $marker ), array( 'version', 'date', 'live', 'created_at', 'snapshot', 'destinations', 'boundary_pending' ) ) && CAPTURE_RETRY_VERSION === ( $marker['version'] ?? null ) && capture_retry_date( $marker['date'] ?? null ) && isset( $marker['live'], $marker['created_at'] ) && is_bool( $marker['live'] ) && is_int( $marker['created_at'] ) && $marker['created_at'] > 0 && ( ! isset( $marker['boundary_pending'] ) || true === $marker['boundary_pending'] ) && ( ! isset( $marker['snapshot'] ) || ( capture_retry_lead_valid( $marker['snapshot'] ) && isset( $marker['snapshot']['institution'] ) ) ) && ( ! isset( $marker['destinations'] ) || ( is_array( $marker['destinations'] ) && array_values( $marker['destinations'] ) === $marker['destinations'] && count( $marker['destinations'] ) <= 100 && ! array_filter(
		$marker['destinations'],
		static function ( $value ) {
			return ! is_int( $value ) || $value < 1;
		}
	) ) );
}

/**
 * Read the durable source marker directly.
 *
 * @param int $id Source ID.
 * @return array|null|\WP_Error
 */
function capture_retry_native_read( $id ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, '_lcrm_capture_pending' ), ARRAY_A );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) {
		return error( 'לא ניתן לקרוא את הפנייה הממתינה.', 503 );
	}
	if ( ! $rows ) {
		return null;
	}
	$marker = maybe_unserialize( $rows[0]['meta_value'] );
	return capture_retry_native_valid( $marker ) ? $marker : error( 'סימון הפנייה הממתינה אינו תקין.', 409 );
}

/**
 * Write and verify one durable marker.
 *
 * @param int        $id Source ID.
 * @param array      $marker Marker.
 * @param array|null $expected Previous marker for compare-and-swap.
 * @return array|\WP_Error
 */
function capture_retry_native_write( $id, $marker, $expected = null ) {
	global $wpdb;
	if ( ! capture_retry_native_valid( $marker ) ) {
		return error( 'סימון הפנייה הממתינה אינו תקין.', 409 );
	}
	$queries = $wpdb->num_queries;
	$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, '_lcrm_capture_pending' ) );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $ids ) || count( $ids ) > 1 ) {
		$failure = error( 'לא ניתן לשמור את הפנייה לניסיון חוזר.', 503 );
		$failure->add_data(
			array(
				'status'             => 503,
				'marker_not_written' => true,
			)
		);
		return $failure;
	}
	$value = maybe_serialize( $marker );
	if ( null !== $expected ) {
		if ( ! $ids ) {
			return error( 'הפנייה כבר נקלטה או השתנתה.', 409 );
		}
		$saved = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $value ),
			array(
				'meta_id'    => (int) $ids[0],
				'meta_value' => maybe_serialize( $expected ),
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	} else {
		$saved = $ids ? $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $value ), array( 'meta_id' => (int) $ids[0] ), array( '%s' ), array( '%d' ) ) : $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => '_lcrm_capture_pending',
				'meta_value' => $value,
			),
			array( '%d', '%s', '%s' )
		);
	}
	wp_cache_delete( $id, 'post_meta' );
	if ( false === $saved ) {
		$failure = error( 'לא ניתן לשמור את הפנייה לניסיון חוזר.', 503 );
		$failure->add_data(
			array(
				'status'             => 503,
				'marker_not_written' => true,
			)
		);
		return $failure;
	}
	return capture_retry_native_read( $id ) === $marker ? $marker : error( 'לא ניתן לשמור את הפנייה לניסיון חוזר.', 503 );
}

/**
 * Mark only newly created site leads, before catalog loading or the CRM lock.
 *
 * @param int      $id Source ID.
 * @param \WP_Post $post Source.
 * @return array|\WP_Error
 */
function capture_retry_native_mark( $id, $post ) {
	$boundary = capture_retry_boundary();
	$marker   = array(
		'version'    => CAPTURE_RETRY_VERSION,
		'date'       => $post->post_date,
		'live'       => ! is_wp_error( $boundary ) && $post->post_date >= $boundary,
		'created_at' => time(),
	);
	if ( is_wp_error( $boundary ) ) {
		$marker['boundary_pending'] = true;
	}
	$saved = capture_retry_native_write( $id, $marker );
	if ( is_wp_error( $saved ) && ! empty( $saved->get_error_data()['marker_not_written'] ) ) {
		$GLOBALS['lcrm_native_markers'][ $id ] = $marker;
	} else {
		unset( $GLOBALS['lcrm_native_markers'][ $id ] );
	}
	return $saved;
}

/**
 * Retry an initial marker write without recreating an acknowledged marker.
 *
 * @param int $id Source ID.
 * @return array|null|\WP_Error
 */
function capture_retry_native_restore( $id ) {
	$marker = capture_retry_native_read( $id );
	if ( is_wp_error( $marker ) ) {
		return $marker;
	}
	if ( null === $marker && isset( $GLOBALS['lcrm_native_markers'][ $id ] ) ) {
		$post = capture_retry_source( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! $post || 'leads' !== $post['post_type'] ) {
			unset( $GLOBALS['lcrm_native_markers'][ $id ] );
			return null;
		}
		$marker = capture_retry_native_write( $id, $GLOBALS['lcrm_native_markers'][ $id ] );
		if ( ! is_wp_error( $marker ) || empty( $marker->get_error_data()['marker_not_written'] ) ) {
			unset( $GLOBALS['lcrm_native_markers'][ $id ] );
		}
	} elseif ( is_array( $marker ) ) {
		unset( $GLOBALS['lcrm_native_markers'][ $id ] );
	}
	return $marker;
}

/**
 * Freeze final form fields before catalog loading can fail.
 *
 * @param int $id Source ID.
 * @return array|\WP_Error
 */
function capture_retry_native_freeze( $id ) {
	global $wpdb;
	$marker = capture_retry_native_restore( $id );
	if ( is_wp_error( $marker ) ) {
		return $marker;
	}
	if ( ! is_array( $marker ) ) {
		return error( 'מקור הפנייה אינו מסומן לקליטה חוזרת.', 409 );
	}
	if ( isset( $marker['snapshot'] ) ) {
		return $marker;
	}
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s,%s,%s,%s,%s)", $id, 'full-name', 'phone', 'email', 'form-name', 'institution' ), ARRAY_A );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) ) {
		return error( 'לא ניתן לקרוא את פרטי הפנייה.', 503 );
	}
	$values = array();
	foreach ( $rows as $row ) {
		if ( isset( $values[ $row['meta_key'] ] ) ) {
			return error( 'פרטי הפנייה אינם חד משמעיים.', 409 );
		}
		$value                      = maybe_unserialize( $row['meta_value'] );
		$values[ $row['meta_key'] ] = is_scalar( $value ) ? (string) $value : '';
	}
	$snapshot = array();
	foreach ( array(
		'name'  => 'full-name',
		'phone' => 'phone',
		'email' => 'email',
		'form'  => 'form-name',
	) as $field => $key ) {
		$snapshot[ $field ] = sanitize_text_field( substr( $values[ $key ] ?? '', 0, 600 ) );
	}
	$snapshot['email']       = sanitize_email( $snapshot['email'] );
	$snapshot['institution'] = $values['institution'] ?? '';
	if ( strlen( $snapshot['institution'] ) > 8192 ) {
		return error( 'מקבלי הפנייה אינם תקינים.', 409 );
	}
	$expected           = $marker;
	$marker['snapshot'] = $snapshot;
	$saved              = capture_retry_native_write( $id, $marker, $expected );
	$current            = capture_retry_native_read( $id );
	return is_wp_error( $saved ) && is_array( $current ) && isset( $current['snapshot'] ) ? $current : $saved;
}

/**
 * Freeze resolved native recipients before financial writes can roll back.
 *
 * @param int $id Source ID.
 * @return array|\WP_Error
 */
function capture_retry_native_prepare( $id ) {
	$marker = capture_retry_native_freeze( $id );
	if ( is_wp_error( $marker ) ) {
		return $marker;
	}
	if ( ! empty( $marker['boundary_pending'] ) ) {
		$boundary = capture_retry_boundary();
		if ( is_wp_error( $boundary ) ) {
			return $boundary;
		}
		$expected       = $marker;
		$marker['live'] = $marker['date'] >= $boundary;
		unset( $marker['boundary_pending'] );
		$saved = capture_retry_native_write( $id, $marker, $expected );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}
	if ( isset( $marker['destinations'] ) ) {
		return $marker;
	}
	try {
		$titles = native_titles();
	} catch ( \Throwable $exception ) {
		return error( 'לא ניתן לקרוא את קטלוג המוסדות.', 503 );
	}
	$expected               = $marker;
	$marker['destinations'] = legacy_institutions( $marker['snapshot']['institution'], $titles );
	$saved                  = capture_retry_native_write( $id, $marker, $expected );
	$current                = capture_retry_native_read( $id );
	return is_wp_error( $saved ) && is_array( $current ) && isset( $current['destinations'] ) ? $current : $saved;
}

/**
 * Acknowledge within the same transaction as the delivery.
 *
 * @param int $id Source ID.
 * @return true|\WP_Error
 */
function capture_retry_native_clear( $id ) {
	global $wpdb;
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) ) {
		return error( 'קליטת הפנייה מחייבת שמירה משותפת.', 503 );
	}
	$GLOBALS['lcrm_changed_records'][] = $id;
	$deleted                           = $wpdb->delete(
		$wpdb->postmeta,
		array(
			'post_id'  => $id,
			'meta_key' => '_lcrm_capture_pending',
		),
		array( '%d', '%s' )
	);
	wp_cache_delete( $id, 'post_meta' );
	return false !== $deleted && null === capture_retry_native_read( $id ) ? true : error( 'אישור קליטת הפנייה נכשל.', 503 );
}

/**
 * Validate a private accepted-dispatch envelope.
 *
 * @param mixed $item Envelope.
 * @return bool
 */
function capture_retry_ajax_valid( $item ) {
	return is_array( $item ) && ! array_diff( array_keys( $item ), array( 'version', 'source', 'date', 'created_at', 'cid', 'lead', 'institution' ) ) && CAPTURE_RETRY_VERSION === ( $item['version'] ?? null ) && isset( $item['source'], $item['created_at'], $item['cid'], $item['lead'] ) && is_string( $item['source'] ) && preg_match( '/^ajax:[a-f0-9-]{36}$/D', $item['source'] ) && capture_retry_date( $item['date'] ?? null ) && is_int( $item['created_at'] ) && $item['created_at'] > 0 && is_string( $item['cid'] ) && '' !== trim( $item['cid'] ) && strlen( $item['cid'] ) <= 600 && capture_retry_lead_valid( $item['lead'] ) && ! isset( $item['lead']['institution'] ) && ( ! isset( $item['institution'] ) || ( is_int( $item['institution'] ) && $item['institution'] > 0 ) );
}

/**
 * Store a dispatch before CID resolution or a financial lock can fail.
 *
 * @param array  $lead Accepted contact.
 * @param string $cid Recipient CID.
 * @param string $source Opaque shared event.
 * @return int|\WP_Error
 */
function capture_retry_ajax_enqueue( $lead, $cid, $source ) {
	$item = array(
		'version'    => CAPTURE_RETRY_VERSION,
		'source'     => $source,
		'date'       => $lead['date'] ?? '',
		'created_at' => time(),
		'cid'        => $cid,
		'lead'       => array_intersect_key( $lead, array_flip( array( 'name', 'phone', 'email', 'form' ) ) ),
	);
	if ( ! capture_retry_ajax_valid( $item ) ) {
		return error( 'פרטי הפנייה הממתינה אינם תקינים.', 409 );
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'lcrm_capture',
				'post_status'  => 'private',
				'post_title'   => 'פנייה ממתינה',
				'post_excerpt' => $source,
				'post_content' => wp_json_encode( $item ),
			)
		),
		true
	);
	if ( is_wp_error( $id ) || ! $id ) {
		return error( 'לא ניתן לשמור את הפנייה לניסיון חוזר.', 503 );
	}
	return capture_retry_ajax_read( $id ) === $item ? (int) $id : error( 'לא ניתן לאמת את שמירת הפנייה.', 503 );
}

/**
 * Read an inbox item without an object-cache snapshot.
 *
 * @param int $id Queue ID.
 * @return array|\WP_Error
 */
function capture_retry_ajax_read( $id ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, post_status, post_excerpt, post_content FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) ) {
		return error( 'לא ניתן לקרוא את הפנייה הממתינה.', 503 );
	}
	if ( 1 !== count( $rows ) || 'lcrm_capture' !== $rows[0]['post_type'] || 'private' !== $rows[0]['post_status'] || strlen( $rows[0]['post_content'] ) > 32768 ) {
		return error( 'פנייה ממתינה לא נמצאה.', 404 );
	}
	$item = json_decode( $rows[0]['post_content'], true, 10 );
	return capture_retry_ajax_valid( $item ) && $item['source'] === $rows[0]['post_excerpt'] ? $item : error( 'פרטי הפנייה הממתינה אינם תקינים.', 409 );
}

/**
 * Resolve and freeze a recipient separately from retryable financial work.
 *
 * @param int $id Queue ID.
 * @param int $wait Lock wait.
 * @return array|null|\WP_Error
 */
function capture_retry_ajax_resolve( $id, $wait ) {
	return locked(
		static function () use ( $id ) {
			global $wpdb;
			$item = capture_retry_ajax_read( $id );
			if ( is_wp_error( $item ) || isset( $item['institution'] ) ) {
				return $item;
			}
			$queries = $wpdb->num_queries;
			$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = %s AND p.post_status IN (%s,%s) ORDER BY p.ID ASC LIMIT 2", 'cid', $item['cid'], 'institutions', 'publish', 'draft' ) );
			if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $ids ) ) {
				return error( 'לא ניתן לקרוא את מוסד הפנייה.', 503 );
			}
			if ( 1 !== count( $ids ) ) {
				return null;
			}
			$item['institution']               = (int) $ids[0];
			$GLOBALS['lcrm_changed_records'][] = $id;
			$saved                             = $wpdb->update( $wpdb->posts, array( 'post_content' => wp_json_encode( $item ) ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );
			clean_post_cache( $id );
			return false !== $saved && capture_retry_ajax_read( $id ) === $item ? $item : error( 'לא ניתן לשמור את מוסד הפנייה.', 503 );
		},
		$wait
	);
}

/**
 * Capture an accepted dispatch once and acknowledge in its transaction.
 *
 * @param int $id Queue ID.
 * @param int $wait Lock wait.
 * @return array|null|\WP_Error
 */
function capture_retry_ajax_attempt( $id, $wait = 5 ) {
	$item = capture_retry_ajax_read( $id );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	if ( ! isset( $item['institution'] ) ) {
		$item = capture_retry_ajax_resolve( $id, $wait );
		if ( null === $item || is_wp_error( $item ) ) {
			return $item;
		}
	}
	return locked(
		static function () use ( $id ) {
			global $wpdb;
			$item = capture_retry_ajax_read( $id );
			if ( is_wp_error( $item ) || ! isset( $item['institution'] ) ) {
				return is_wp_error( $item ) ? $item : error( 'מוסד הפנייה טרם נשמר.', 409 );
			}
			$lead   = array_merge(
				$item['lead'],
				array(
					'date'        => $item['date'],
					'origin_live' => true,
					'capture_via' => 'site_ajax',
				)
			);
			$result = record_delivery( $lead, $item['institution'], $item['source'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$verified = capture_retry_delivery( $result['id'], $item['source'], $item['institution'], $lead );
			if ( is_wp_error( $verified ) || 'sent' !== $verified['state'] || empty( $verified['origin_live'] ) ) {
				return is_wp_error( $verified ) ? $verified : error( 'קליטת הפנייה החיה טרם אושרה.', 409 );
			}
			$GLOBALS['lcrm_changed_records'][] = $id;
			$deleted                           = $wpdb->delete(
				$wpdb->posts,
				array(
					'ID'        => $id,
					'post_type' => 'lcrm_capture',
				),
				array( '%d', '%s' )
			);
			clean_post_cache( $id );
			return 1 === $deleted ? $result : error( 'אישור קליטת הפנייה נכשל.', 503 );
		},
		$wait
	);
}

/**
 * Count original pending events globally, never contacts or recipient totals.
 *
 * @return int|\WP_Error
 */
function capture_pending_count() {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$count   = $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status = %s AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s)) + (SELECT COUNT(DISTINCT post_excerpt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s)", 'leads', 'publish', '_lcrm_capture_pending', 'lcrm_capture', 'private' ) );
	return $wpdb->num_queries > $queries && ! $wpdb->last_error && is_scalar( $count ) && ctype_digit( (string) $count ) ? (int) $count : error( 'לא ניתן לקרוא את מספר הפניות הממתינות.', 503 );
}

/**
 * Maintain the normal hourly schedule after upgrades.
 *
 * @return void
 */
function capture_retry_init() {
	if ( ! wp_next_scheduled( 'lcrm_capture_retry_hourly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'lcrm_capture_retry_hourly' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\capture_retry_init', 30 );

/**
 * Read or write the private fair-sweep cursor directly.
 *
 * @param array|null $value Optional replacement.
 * @return array|null|\WP_Error
 */
function capture_retry_cursor( $value = null ) {
	global $wpdb;
	if ( null !== $value ) {
		$sql = $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)", 'lcrm_capture_retry_cursor', maybe_serialize( $value ), 'no' );
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			return error( 'שמירת התקדמות הקליטה נכשלה.', 503 );
		}
		wp_cache_delete( 'lcrm_capture_retry_cursor', 'options' );
	}
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'lcrm_capture_retry_cursor' ), ARRAY_A );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) {
		return error( 'קריאת התקדמות הקליטה נכשלה.', 503 );
	}
	if ( ! $rows ) {
		return null;
	}
	$state = maybe_unserialize( $rows[0]['option_value'] );
	if ( ! is_array( $state ) || CAPTURE_RETRY_VERSION !== ( $state['version'] ?? null ) || ! isset( $state['cursor'], $state['max_id'], $state['active'] ) || ! is_int( $state['cursor'] ) || ! is_int( $state['max_id'] ) || ! is_bool( $state['active'] ) || $state['cursor'] < 0 || $state['cursor'] > $state['max_id'] || ( null !== $value && $state !== $value ) ) {
		return error( 'התקדמות הקליטה אינה תקינה.', 409 );
	}
	return $state;
}

/**
 * Select pending IDs only; no contact payload is needed to sweep the inbox.
 *
 * @param int $cursor Last ID.
 * @param int $maximum Frozen boundary, or zero for upper-bound discovery.
 * @return array|int|\WP_Error
 */
function capture_retry_ids( $cursor, $maximum = 0 ) {
	global $wpdb;
	$select = $maximum ? 'p.ID' : 'COALESCE(MAX(p.ID),0)';
	$sql    = "SELECT {$select} FROM {$wpdb->posts} p WHERE ((p.post_type = %s AND p.post_status = %s AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s)) OR (p.post_type = %s AND p.post_status = %s))";
	$args   = array( 'leads', 'publish', '_lcrm_capture_pending', 'lcrm_capture', 'private' );
	if ( $maximum ) {
		$sql .= ' AND p.ID > %d AND p.ID <= %d ORDER BY p.ID ASC LIMIT %d';
		$args = array_merge( $args, array( $cursor, $maximum, CAPTURE_RETRY_BATCH ) );
	}
	$queries = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query contains only fixed SQL and every changing value is prepared.
	$result = $maximum ? $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ( $maximum ? ! is_array( $result ) : ! is_scalar( $result ) || ! ctype_digit( (string) $result ) ) ) {
		return error( 'קריאת הפניות הממתינות נכשלה.', 503 );
	}
	return $maximum ? array_map( 'intval', $result ) : (int) $result;
}

/**
 * Run one fair bounded cron batch; a continuation never creates an endless new sweep.
 *
 * @param bool $continuation Existing sweep only.
 * @return array|\WP_Error
 */
function capture_retry_run( $continuation = false ) {
	global $wpdb;
	if ( ! wp_doing_cron() ) {
		return error( 'אין הרשאה להריץ את עבודת הקליטה.', 403 );
	}
	$name = 'lcrm_retry_' . md5( DB_NAME . ':' . $wpdb->prefix );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) ) {
		return array(
			'processed' => 0,
			'completed' => 0,
			'active'    => true,
		);
	}
	$started = microtime( true );
	$out     = array(
		'processed' => 0,
		'completed' => 0,
		'active'    => false,
	);
	try {
		$state = capture_retry_cursor();
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( ! $state || ! $state['active'] ) {
			if ( $continuation ) {
				return $out;
			}
			$max = capture_retry_ids( 0 );
			if ( is_wp_error( $max ) ) {
				return $max;
			}
			$state = capture_retry_cursor(
				array(
					'version' => CAPTURE_RETRY_VERSION,
					'cursor'  => 0,
					'max_id'  => $max,
					'active'  => $max > 0,
				)
			);
		}
		if ( is_wp_error( $state ) || ! $state['active'] ) {
			return is_wp_error( $state ) ? $state : $out;
		}
		$ids = capture_retry_ids( $state['cursor'], $state['max_id'] );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		foreach ( $ids as $id ) {
			$result = null;
			$post   = capture_retry_source( $id );
			if ( is_wp_error( $post ) ) {
				$result = $post;
			} elseif ( $post && 'leads' === $post['post_type'] ) {
				$marker = capture_retry_native_read( $id );
				if ( is_array( $marker ) && ( isset( $marker['snapshot'] ) || $marker['created_at'] <= time() - 60 ) ) {
					$marker = capture_retry_native_prepare( $id );
					if ( ! is_wp_error( $marker ) ) {
						try {
							$result = locked(
								static function () use ( $id ) {
									return native_capture( $id );
								},
								0
							);
						} catch ( \Throwable $exception ) {
							$result = error( 'לא ניתן לקרוא את קטלוג המוסדות.', 503 );
						}
					} else {
						$result = $marker;
					}
				} elseif ( is_wp_error( $marker ) ) {
					$result = $marker;
				}
			} else {
				$result = capture_retry_ajax_attempt( $id, 0 );
			}
			if ( is_wp_error( $result ) && ! empty( $result->get_error_data()['lock_busy'] ) ) {
				break;
			}
			++$out['processed'];
			if ( null !== $result && ! is_wp_error( $result ) ) {
				++$out['completed'];
			}
			$state['cursor'] = $id;
			$state           = capture_retry_cursor( $state );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			if ( microtime( true ) - $started >= 10 ) {
				break;
			}
		}
		$remaining = capture_retry_ids( $state['cursor'], $state['max_id'] );
		if ( is_wp_error( $remaining ) ) {
			return $remaining;
		}
		$state['active'] = (bool) $remaining;
		$state           = capture_retry_cursor( $state );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$out['active'] = $state['active'];
		if ( $state['active'] && ! wp_next_scheduled( 'lcrm_capture_retry_batch' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'lcrm_capture_retry_batch' );
		} elseif ( ! $state['active'] ) {
			wp_clear_scheduled_hook( 'lcrm_capture_retry_batch' );
		}
		return $out;
	} catch ( \Throwable $exception ) {
		if ( ! wp_next_scheduled( 'lcrm_capture_retry_batch' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'lcrm_capture_retry_batch' );
		}
		return error( 'לא ניתן להשלים את ניסיון הקליטה.', 503 );
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
add_action(
	'lcrm_capture_retry_hourly',
	static function () {
		capture_retry_run();
	}
);
add_action(
	'lcrm_capture_retry_batch',
	static function () {
		capture_retry_run( true );
	}
);
