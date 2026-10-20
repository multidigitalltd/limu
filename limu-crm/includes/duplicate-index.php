<?php
/**
 * Private contact indexes, bounded upgrade backfill and exact connected-component reads.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Indexed private metadata cursor reads must use the current transaction.
// phpcs:disable WordPress.DB.SlowDBQuery -- Exact contact indexes reuse WordPress metadata without new tables.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DUPLICATE_INDEX_VERSION = 1;
const DUPLICATE_INDEX_BATCH   = 100;

/**
 * Reject corrupt migration cursors instead of declaring old contacts indexed.
 *
 * @param mixed $state Persisted private cursor.
 * @return bool Whether all state invariants hold.
 */
function duplicate_index_valid_state( $state ) {
	if ( ! is_array( $state ) || DUPLICATE_INDEX_VERSION !== ( $state['version'] ?? null ) || ! isset( $state['done'] ) || ! is_bool( $state['done'] ) ) {
		return false;
	}
	foreach ( array( 'cursor', 'max_id', 'processed' ) as $field ) {
		if ( ! isset( $state[ $field ] ) || ! is_int( $state[ $field ] ) || $state[ $field ] < 0 ) {
			return false;
		}
	}
	return $state['cursor'] <= $state['max_id'] && ( ! $state['done'] || $state['cursor'] === $state['max_id'] );
}

/**
 * Read or initialize the upper bound without doing backfill in a visitor request.
 *
 * @return array|\WP_Error Cursor or a safe persistence error.
 */
function duplicate_index_state() {
	$state = get_option( 'lcrm_duplicate_index', null );
	if ( null !== $state ) {
		return duplicate_index_valid_state( $state ) ? $state : error( 'מצב אינדקס הכפילויות אינו תקין.', 409 );
	}
	return locked(
		static function () {
			global $wpdb;
			$state = get_option( 'lcrm_duplicate_index', null );
			if ( null !== $state ) {
				return duplicate_index_valid_state( $state ) ? $state : error( 'מצב אינדקס הכפילויות אינו תקין.', 409 );
			}
			$queries = $wpdb->num_queries;
			$max     = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", 'lcrm_delivery', 'private' ) );
			if ( $wpdb->num_queries <= $queries || '' !== $wpdb->last_error || ! is_scalar( $max ) || ! ctype_digit( (string) $max ) ) {
				return error( 'קריאת גבול אינדקס הכפילויות נכשלה.', 500 );
			}
			$max   = (int) $max;
			$state = array(
				'version'   => DUPLICATE_INDEX_VERSION,
				'cursor'    => 0,
				'max_id'    => $max,
				'processed' => 0,
				'done'      => 0 === $max,
			);
			update_option( 'lcrm_duplicate_index', $state, false );
			return get_option( 'lcrm_duplicate_index' ) === $state ? $state : error( 'שמירת אינדקס הכפילויות נכשלה.', 500 );
		}
	);
}

/**
 * Queue one short batch without restarting a completed migration.
 *
 * @param int $delay Delay in seconds.
 * @return void
 */
function duplicate_index_schedule( $delay = 60 ) {
	if ( ! wp_next_scheduled( 'lcrm_duplicate_index_batch' ) ) {
		wp_schedule_single_event( time() + $delay, 'lcrm_duplicate_index_batch' );
	}
}

/** Initialize upgrades after post types have been registered. */
function duplicate_index_init() {
	$state = duplicate_index_state();
	if ( is_wp_error( $state ) ) {
		if ( 500 === ( $state->get_error_data()['status'] ?? 0 ) ) {
			duplicate_index_schedule( 300 );
		}
	} elseif ( ! $state['done'] ) {
		duplicate_index_schedule();
	}
}

/**
 * Validate immutable matching data without hydrating the source contact.
 *
 * @param mixed $item Private delivery snapshot.
 * @param int   $id Expected post ID.
 * @return bool Whether keys are safe to index and compare.
 */
function duplicate_index_valid_item( $item, $id ) {
	if (
		! is_array( $item ) ||
		! isset( $item['id'], $item['institution'], $item['state'], $item['date'], $item['month'], $item['phone_key'], $item['email_key'], $item['duplicate_of'], $item['bill'] ) ||
		! is_int( $item['id'] ) || 0 >= $item['id'] || $item['id'] !== $id ||
		! is_int( $item['institution'] ) || 0 > $item['institution'] ||
		! is_string( $item['state'] ) || ! is_string( $item['date'] ) ||
		! is_string( $item['phone_key'] ) || ! is_string( $item['email_key'] ) || strlen( $item['email_key'] ) > 600 ||
		! is_int( $item['duplicate_of'] ) || 0 > $item['duplicate_of'] ||
		! is_int( $item['bill'] ) || 0 > $item['bill'] ||
		! is_string( $item['month'] ) || substr( $item['date'], 0, 7 ) !== $item['month']
	) {
		return false;
	}
	return normalize_phone( $item['phone_key'] ) === $item['phone_key'] && normalize_email( $item['email_key'] ) === $item['email_key'] && preg_match( '/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $item['date'] ) && valid_date( substr( $item['date'], 0, 10 ) );
}

/**
 * Read scalar mirrors from the current transaction without trusting an object cache.
 *
 * @param array $ids At most one component or migration page of delivery IDs.
 * @return array|\WP_Error Fresh indexed values or an explicit read error.
 */
function duplicate_index_values( $ids ) {
	global $wpdb;
	if ( ! $ids ) {
		return array();
	}
	if ( count( $ids ) > 200 ) {
		return error( 'אצוות אינדקס פרטי הקשר גדולה מדי.', 500 );
	}
	$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	$values       = array_merge( array( '_lcrm_phone_key', '_lcrm_email_key', '_lcrm_institution', '_lcrm_state' ), array_map( 'intval', $ids ) );
	$queries      = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The generated list contains only placeholders; the complete values array is prepared.
	$sql  = $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s) AND post_id IN ($placeholders)", $values );
	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The complete query is prepared immediately above.
	if ( $wpdb->num_queries <= $queries || '' !== $wpdb->last_error || ! is_array( $rows ) ) {
		return error( 'קריאת אינדקס פרטי הקשר נכשלה.', 500 );
	}
	$result = array();
	foreach ( $rows as $row ) {
		$id    = (int) $row['post_id'];
		$field = substr( $row['meta_key'], 6 );
		if ( isset( $result[ $id ][ $field ] ) ) {
			return error( 'אינדקס פרטי הקשר מכיל ערכים סותרים.', 409 );
		}
		$result[ $id ][ $field ] = $row['meta_value'];
	}
	return $result;
}

/**
 * Build at most one bounded batch; snapshot keys and cursor commit atomically.
 *
 * @return array|\WP_Error Updated private cursor or a rollback-safe error.
 */
function duplicate_index_run() {
	if ( ! wp_doing_cron() ) {
		return error( 'עדכון האינדקס זמין כתהליך רקע בלבד.', 403 );
	}
	$result = locked(
		static function () {
			global $wpdb;
			$state = get_option( 'lcrm_duplicate_index', null );
			if ( ! duplicate_index_valid_state( $state ) ) {
				return error( 'מצב אינדקס הכפילויות אינו תקין.', 409 );
			}
			if ( $state['done'] ) {
				return $state;
			}
			$queries = $wpdb->num_queries;
			$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d", 'lcrm_delivery', 'private', $state['cursor'], $state['max_id'], DUPLICATE_INDEX_BATCH ) );
			if ( $wpdb->num_queries <= $queries || '' !== $wpdb->last_error || ! is_array( $ids ) ) {
				return error( 'קריאת אצוות האינדקס נכשלה.', 500 );
			}
			try {
				$items = raw_records( $ids );
			} catch ( \Throwable $exception ) {
				return error( 'קריאת פרטי אצוות האינדקס נכשלה.', 500 );
			}
			foreach ( $items as $item ) {
				$id = $item['id'] ?? 0;
				if ( ! duplicate_index_valid_item( $item, $id ) ) {
					return error( 'רשומת פנייה אינה תקינה. האינדקס לא הושלם.', 409 );
				}
				$GLOBALS['lcrm_changed_records'][] = (int) $id;
				foreach ( array( 'phone_key', 'email_key', 'institution', 'state' ) as $field ) {
					update_post_meta( $id, '_lcrm_' . $field, wp_slash( $item[ $field ] ) );
				}
			}
			$mirrors = duplicate_index_values( $ids );
			if ( is_wp_error( $mirrors ) ) {
				return $mirrors;
			}
			foreach ( $items as $item ) {
				foreach ( array( 'phone_key', 'email_key', 'institution', 'state' ) as $field ) {
					if ( ! isset( $mirrors[ $item['id'] ][ $field ] ) || $mirrors[ $item['id'] ][ $field ] !== (string) $item[ $field ] ) {
						return error( 'שמירת אינדקס הפנייה נכשלה.', 500 );
					}
				}
			}
			$state['processed'] += count( $ids );
			$state['cursor']     = $ids ? (int) end( $ids ) : $state['max_id'];
			$state['done']       = count( $ids ) < DUPLICATE_INDEX_BATCH || $state['cursor'] === $state['max_id'];
			if ( $state['done'] ) {
				$state['cursor'] = $state['max_id'];
			}
			update_option( 'lcrm_duplicate_index', $state, false );
			return get_option( 'lcrm_duplicate_index' ) === $state ? $state : error( 'שמירת התקדמות האינדקס נכשלה.', 500 );
		}
	);
	if ( is_wp_error( $result ) ) {
		if ( 500 === ( $result->get_error_data()['status'] ?? 0 ) ) {
			duplicate_index_schedule( 300 );
		}
	} elseif ( ! $result['done'] ) {
		duplicate_index_schedule();
	} else {
		wp_clear_scheduled_hook( 'lcrm_duplicate_index_batch' );
	}
	return $result;
}

/**
 * Invalidate a finished index if another integration bypasses transactional record saves.
 *
 * @param int    $meta_id Metadata entry ID.
 * @param int    $id Post ID.
 * @param string $key Metadata key.
 * @return void
 */
function duplicate_index_external_meta( $meta_id, $id, $key ) {
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) && 'lcrm_delivery' === get_post_type( $id ) && in_array( $key, array( '_lcrm_data', '_lcrm_phone_key', '_lcrm_email_key', '_lcrm_institution', '_lcrm_state' ), true ) ) {
		delete_option( 'lcrm_duplicate_index' );
	}
}

/**
 * Restoring an old record may reintroduce a row that the private-only backfill skipped.
 *
 * @param string   $next New post status.
 * @param string   $previous Previous status.
 * @param \WP_Post $post Changed record.
 * @return void
 */
function duplicate_index_restore( $next, $previous, $post ) {
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) && 'private' === $next && $previous !== $next && 'lcrm_delivery' === $post->post_type ) {
		delete_option( 'lcrm_duplicate_index' );
	}
}

/**
 * Return the established complete scan while old indexes are not ready.
 *
 * @param int $institution Institution ID.
 * @return array|\WP_Error Raw sorted snapshots or an explicit read failure.
 */
function duplicate_index_fallback( $institution ) {
	global $wpdb;
	try {
		$items = institution_deliveries( $institution, null, null, false );
	} catch ( \Throwable $exception ) {
		return error( 'קריאת היסטוריית הכפילויות נכשלה.', 500 );
	}
	if ( $wpdb->last_error ) {
		return error( 'קריאת היסטוריית הכפילויות נכשלה.', 500 );
	}
	foreach ( $items as $item ) {
		if ( ! duplicate_index_valid_item( $item, $item['id'] ?? 0 ) ) {
			return error( 'רשומת היסטוריית כפילויות אינה תקינה.', 409 );
		}
	}
	return $items;
}

/**
 * Fetch one cursor page of only exact matching private recipients.
 *
 * @param int   $institution Institution ID.
 * @param array $keys Nonempty phone/email lookup values, bounded to 100 per field.
 * @param int   $cursor Previous post ID.
 * @return array|\WP_Error Post IDs or a safe read error.
 */
function duplicate_index_matches( $institution, $keys, $cursor ) {
	global $wpdb;
	$clauses = array();
	$values  = array( '_lcrm_institution', (string) $institution, '_lcrm_state', 'sent', 'historical', 'lcrm_delivery', 'private', $cursor );
	foreach ( array( 'phone_key', 'email_key' ) as $field ) {
		if ( ! empty( $keys[ $field ] ) ) {
			$clauses[] = '(c.meta_key = %s AND c.meta_value IN (' . implode( ', ', array_fill( 0, count( $keys[ $field ] ), '%s' ) ) . '))';
			$values[]  = '_lcrm_' . $field;
			$values    = array_merge( $values, $keys[ $field ] );
		}
	}
	if ( ! $clauses ) {
		return array();
	}
	$values[] = 200;
	$sql      = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = %s AND i.meta_value = %s INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value IN (%s, %s) INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID WHERE p.post_type = %s AND p.post_status = %s AND p.ID > %d AND (" . implode( ' OR ', $clauses ) . ') ORDER BY p.ID ASC LIMIT %d';
	$queries  = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only trusted table names and generated placeholders are interpolated; every value is prepared.
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );
	return $wpdb->num_queries > $queries && '' === $wpdb->last_error && is_array( $ids ) ? $ids : error( 'קריאת מועמדי כפילות נכשלה.', 500 );
}

/**
 * Read a complete contact component, including aliases from duplicate historical rows.
 *
 * @param array $changed Newly saved delivery snapshot.
 * @return array|\WP_Error Raw snapshots sorted by event date and ID.
 */
function duplicate_component( $changed ) {
	global $wpdb;
	if ( ! duplicate_index_valid_item( $changed, $changed['id'] ?? 0 ) ) {
		return error( 'פרטי פנייה אינם תקינים להשוואת כפילות.', 409 );
	}
	$state = get_option( 'lcrm_duplicate_index', null );
	if ( ! duplicate_index_valid_state( $state ) || ! $state['done'] ) {
		return duplicate_index_fallback( $changed['institution'] );
	}
	$items   = array();
	$pending = array(
		'phone_key' => array(),
		'email_key' => array(),
	);
	$seen    = array(
		'phone_key' => array(),
		'email_key' => array(),
	);
	foreach ( array( 'phone_key', 'email_key' ) as $field ) {
		if ( '' !== $changed[ $field ] ) {
			$pending[ $field ][]                  = $changed[ $field ];
			$seen[ $field ][ $changed[ $field ] ] = true;
		}
	}
	if ( ! $pending['phone_key'] && ! $pending['email_key'] ) {
		return array( $changed );
	}
	$pages = 0;
	while ( $pending['phone_key'] || $pending['email_key'] ) {
		$keys   = array(
			'phone_key' => array_splice( $pending['phone_key'], 0, 100 ),
			'email_key' => array_splice( $pending['email_key'], 0, 100 ),
		);
		$cursor = 0;
		do {
			if ( ++$pages > 20 || count( $items ) > 500 ) {
				// A very large connected group is cheaper to read once than through many SQL rounds.
				return duplicate_index_fallback( $changed['institution'] );
			}
			$ids = duplicate_index_matches( $changed['institution'], $keys, $cursor );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			try {
				$candidates = raw_records( $ids );
			} catch ( \Throwable $exception ) {
				return error( 'קריאת פרטי מועמדי כפילות נכשלה.', 500 );
			}
			$mirrors = duplicate_index_values( $ids );
			if ( is_wp_error( $mirrors ) ) {
				return $mirrors;
			}
			foreach ( $candidates as $item ) {
				$id = $item['id'] ?? 0;
				if ( isset( $items[ $id ] ) ) {
					continue;
				}
				if ( ! duplicate_index_valid_item( $item, $id ) || $item['institution'] !== $changed['institution'] || ! in_array( $item['state'], array( 'sent', 'historical' ), true ) ) {
					return error( 'אינדקס פניות אינו תואם לנתונים השמורים.', 409 );
				}
				if ( ! in_array( $item['phone_key'], $keys['phone_key'], true ) && ! in_array( $item['email_key'], $keys['email_key'], true ) ) {
					return error( 'מועמד כפילות אינו תואם למפתח החיפוש.', 409 );
				}
				foreach ( array( 'institution', 'state' ) as $field ) {
					if ( ! isset( $mirrors[ $id ][ $field ] ) || $mirrors[ $id ][ $field ] !== (string) $item[ $field ] ) {
						return error( 'אינדקס שיוך הפנייה אינו תואם לנתונים.', 409 );
					}
				}
				foreach ( array( 'phone_key', 'email_key' ) as $field ) {
					if ( ! isset( $mirrors[ $id ][ $field ] ) || $mirrors[ $id ][ $field ] !== $item[ $field ] ) {
						return error( 'אינדקס פרטי הקשר אינו תואם לפנייה.', 409 );
					}
					if ( '' !== $item[ $field ] && ! isset( $seen[ $field ][ $item[ $field ] ] ) ) {
						$pending[ $field ][]               = $item[ $field ];
						$seen[ $field ][ $item[ $field ] ] = true;
					}
				}
				$items[ $id ] = $item;
			}
			$cursor = $ids ? (int) end( $ids ) : $cursor;
			$count  = count( $ids );
		} while ( 200 === $count );
	}
	if ( ! isset( $items[ $changed['id'] ] ) ) {
		return error( 'הפנייה החדשה חסרה באינדקס הכפילויות.', 409 );
	}
	$items = array_values( $items );
	usort(
		$items,
		static function ( $a, $b ) {
			return array( $a['date'], $a['id'] ) <=> array( $b['date'], $b['id'] );
		}
	);
	return $items;
}

add_action( 'init', __NAMESPACE__ . '\\duplicate_index_init', 20 );
add_action( 'lcrm_duplicate_index_batch', __NAMESPACE__ . '\\duplicate_index_run' );
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
	add_action( $hook, __NAMESPACE__ . '\\duplicate_index_external_meta', 10, 3 );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\duplicate_index_restore', 10, 3 );
