<?php
/**
 * Bounded repair of previously imported, unbilled historical mapping exceptions.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fixed private-record cursors and transactional option writes require the live database.
// phpcs:disable WordPress.DB.SlowDBQuery -- Exact source lookup reuses existing private metadata.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAPPING_REPAIR_VERSION = 1;

/**
 * Reject malformed cursor state rather than guessing a new migration boundary.
 *
 * @param mixed $state Saved private migration option.
 * @return bool Whether the state is complete and internally consistent.
 */
function mapping_repair_valid_state( $state ) {
	if ( ! is_array( $state ) || MAPPING_REPAIR_VERSION !== ( $state['version'] ?? 0 ) || ! isset( $state['done'] ) || ! is_bool( $state['done'] ) ) {
		return false;
	}
	foreach ( array( 'cursor', 'max_id', 'processed', 'repaired', 'deliveries', 'unresolved' ) as $key ) {
		if ( ! isset( $state[ $key ] ) || ! is_int( $state[ $key ] ) || $state[ $key ] < 0 ) {
			return false;
		}
	}
	return $state['cursor'] <= $state['max_id'];
}

/**
 * Validate a saved historical exception without hydrating or changing its snapshot.
 *
 * @param array $item Stored private payload.
 * @return bool Whether this exception is eligible for historical-only repair.
 */
function mapping_repair_eligible( $item ) {
	if ( ! is_array( $item ) || 'unmapped' !== ( $item['state'] ?? '' ) || ! empty( $item['institution'] ) || ! empty( $item['bill'] ) || ! empty( $item['origin_live'] ) || ! isset( $item['institution'], $item['bill'], $item['source'], $item['contact'], $item['date'], $item['month'], $item['phone_key'], $item['email_key'] ) || ( ! is_int( $item['institution'] ) && ! is_string( $item['institution'] ) ) || ( ! is_int( $item['bill'] ) && ! is_string( $item['bill'] ) ) ) {
		return false;
	}
	if ( ! is_string( $item['source'] ) || ! preg_match( '/^legacy:([1-9]\d{0,9})$/D', $item['source'], $match ) || ! is_scalar( $item['contact'] ) || is_bool( $item['contact'] ) || (string) $item['contact'] !== $match[1] || ( isset( $item['legacy_id'] ) && ( ! is_scalar( $item['legacy_id'] ) || is_bool( $item['legacy_id'] ) || (string) $item['legacy_id'] !== $match[1] ) ) ) {
		return false;
	}
	if ( ! is_string( $item['date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $item['date'] ) || ! valid_date( substr( $item['date'], 0, 10 ) ) || substr( $item['date'], 0, 7 ) !== $item['month'] || ! is_string( $item['phone_key'] ) || ! is_string( $item['email_key'] ) || normalize_phone( $item['phone_key'] ) !== $item['phone_key'] || normalize_email( $item['email_key'] ) !== $item['email_key'] ) {
		return false;
	}
	if ( isset( $item['duplicate_mode'] ) ) {
		try {
			duplicate_window_start( $item['date'], $item['duplicate_mode'] );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}
	return true;
}

/**
 * Check existing recipients against immutable source context before closing an exception.
 *
 * @param array $existing Existing historical recipient payload.
 * @param array $item Original historical exception payload.
 * @return bool Whether the existing recipient can safely satisfy a retry.
 */
function mapping_repair_matches( $existing, $item ) {
	if ( 'historical' !== ( $existing['state'] ?? '' ) || ! empty( $existing['bill'] ) || ! empty( $existing['origin_live'] ) ) {
		return false;
	}
	foreach ( array( 'source', 'contact', 'legacy_id', 'date', 'month', 'phone_key', 'email_key', 'duplicate_mode' ) as $key ) {
		if ( array_key_exists( $key, $existing ) !== array_key_exists( $key, $item ) || ( isset( $item[ $key ] ) && $existing[ $key ] !== $item[ $key ] ) ) {
			return false;
		}
	}
	foreach ( array( 'notes', 'treatment' ) as $key ) {
		if ( isset( $item[ $key ] ) && ( $existing[ $key ] ?? null ) !== $item[ $key ] ) {
			return false;
		}
	}
	return true;
}

/**
 * Repair one historical exception inside the enclosing database transaction.
 *
 * @param int   $id Existing exception post ID.
 * @param array $catalog Exact server-side source name and parent catalog.
 * @return array|\WP_Error Counts or a persistence failure requiring complete rollback.
 */
function mapping_repair_item( $id, $catalog ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$item    = get_post_meta( $id, '_lcrm_data', true );
	if ( $wpdb->num_queries > $queries && $wpdb->last_error ) {
		return error( 'קריאת החריג ההיסטורי נכשלה. יש לנסות שוב.', 500 );
	}
	if ( ! mapping_repair_eligible( $item ) ) {
		return array(
			'repaired'   => 0,
			'deliveries' => 0,
		);
	}
	$queries = $wpdb->num_queries;
	$source  = get_post( $item['contact'] );
	if ( $wpdb->num_queries > $queries && $wpdb->last_error ) {
		return error( 'קריאת מקור הפנייה נכשלה. יש לנסות שוב.', 500 );
	}
	$queries = $wpdb->num_queries;
	$value   = get_post_meta( $item['contact'], 'institution', true );
	if ( $wpdb->num_queries > $queries && $wpdb->last_error ) {
		return error( 'קריאת מוסד המקור נכשלה. יש לנסות שוב.', 500 );
	}
	if ( ! $source || 'leads' !== $source->post_type || 'publish' !== $source->post_status || ( ! is_string( $value ) && ! is_int( $value ) ) ) {
		return array(
			'repaired'   => 0,
			'deliveries' => 0,
		);
	}
	$destinations = legacy_institutions( $value, $catalog );
	if ( ! $destinations || count( $destinations ) > 20 ) {
		return array(
			'repaired'   => 0,
			'deliveries' => 0,
		);
	}
	foreach ( $destinations as $institution ) {
		if ( 'institutions' !== get_post_type( $institution ) || ! in_array( get_post_status( $institution ), array( 'publish', 'draft' ), true ) ) {
			return array(
				'repaired'   => 0,
				'deliveries' => 0,
			);
		}
	}
	$others = get_posts(
		array(
			'post_type'      => 'lcrm_delivery',
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_lcrm_source',
			'meta_value'     => $item['source'],
		)
	);
	if ( '' !== $wpdb->last_error ) {
		return error( 'קריאת שיוכים קיימים נכשלה. יש לנסות שוב.', 500 );
	}
	$queries = $wpdb->num_queries;
	update_meta_cache( 'post', $others );
	if ( $wpdb->num_queries > $queries && $wpdb->last_error ) {
		return error( 'קריאת פרטי שיוכים קיימים נכשלה. יש לנסות שוב.', 500 );
	}
	$existing = array();
	foreach ( $others as $other_id ) {
		if ( (int) $other_id === (int) $id ) {
			continue;
		}
		$other       = get_post_meta( $other_id, '_lcrm_data', true );
		$institution = is_array( $other ) ? ( $other['institution'] ?? 0 ) : 0;
		if ( ! in_array( $institution, $destinations, true ) || isset( $existing[ $institution ] ) || ! mapping_repair_matches( $other, $item ) ) {
			return array(
				'repaired'   => 0,
				'deliveries' => 0,
			);
		}
		$existing[ $institution ] = true;
	}
	$created = 0;
	foreach ( $destinations as $institution ) {
		if ( isset( $existing[ $institution ] ) ) {
			continue;
		}
		// Match against the captured keys, even if the original contact was edited later.
		$lead          = $item;
		$lead['name']  = sanitize_text_field( substr( get_post_field( 'post_title', $id, 'raw' ), 0, 600 ) );
		$lead['phone'] = $item['phone_key'];
		$lead['email'] = $item['email_key'];
		$result        = record_delivery( $lead, $institution, $item['source'], true, $item['duplicate_mode'] ?? 'calendar' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$stored = get_post_meta( $result['id'], '_lcrm_data', true );
		if ( ! is_array( $stored ) || 'lcrm_delivery' !== get_post_type( $result['id'] ) || ( $stored['id'] ?? 0 ) !== (int) $result['id'] || 'historical' !== ( $stored['state'] ?? '' ) || ! empty( $stored['bill'] ) || ! empty( $stored['origin_live'] ) || ( $stored['institution'] ?? 0 ) !== $institution ) {
			return error( 'מקבל קיים אינו מתאים לתיקון היסטורי.', 409 );
		}
		foreach ( array( 'source', 'contact', 'legacy_id', 'date', 'month', 'phone_key', 'email_key' ) as $key ) {
			if ( array_key_exists( $key, $stored ) !== array_key_exists( $key, $item ) || ( isset( $item[ $key ] ) && $stored[ $key ] !== $item[ $key ] ) ) {
				return error( 'מקבל קיים אינו תואם למקור ההיסטורי.', 409 );
			}
		}
		foreach ( array( 'duplicate_mode', 'notes', 'treatment' ) as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$stored[ $key ] = $item[ $key ];
			} else {
				unset( $stored[ $key ] );
			}
		}
		$result = save_record( 'lcrm_delivery', $stored, $result['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! mapping_repair_matches( get_post_meta( $result['id'], '_lcrm_data', true ), $item ) ) {
			return error( 'שמירת התמונה ההיסטורית נכשלה.', 500 );
		}
		++$created;
	}
	$GLOBALS['lcrm_changed_records'][] = $id;
	if ( ! wp_trash_post( $id ) ) {
		return error( 'תיקון השיוך לא נשמר. יש לנסות שוב.', 500 );
	}
	return array(
		'repaired'   => 1,
		'deliveries' => $created,
	);
}

/**
 * Create a frozen repair cursor without doing any historical repair in a page request.
 *
 * @return array|\WP_Error Saved migration state.
 */
function mapping_repair_state() {
	$state = get_option( 'lcrm_mapping_repair', null );
	if ( is_array( $state ) && MAPPING_REPAIR_VERSION === ( $state['version'] ?? 0 ) ) {
		return mapping_repair_valid_state( $state ) ? $state : error( 'מצב תיקון השיוך אינו תקין.', 409 );
	}
	return locked(
		static function () {
			global $wpdb;
			$state = get_option( 'lcrm_mapping_repair', null );
			if ( is_array( $state ) && MAPPING_REPAIR_VERSION === ( $state['version'] ?? 0 ) ) {
				return mapping_repair_valid_state( $state ) ? $state : error( 'מצב תיקון השיוך אינו תקין.', 409 );
			}
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", 'lcrm_delivery', 'private' ) );
			if ( '' !== $wpdb->last_error ) {
				return error( 'קריאת מצב תיקון השיוך נכשלה. יש לנסות שוב.', 500 );
			}
			$state = array(
				'version'    => MAPPING_REPAIR_VERSION,
				'cursor'     => 0,
				'max_id'     => $max,
				'done'       => 0 === $max,
				'processed'  => 0,
				'repaired'   => 0,
				'deliveries' => 0,
				'unresolved' => 0,
			);
			update_option( 'lcrm_mapping_repair', $state, false );
			return get_option( 'lcrm_mapping_repair' ) === $state ? $state : error( 'שמירת מצב תיקון השיוך נכשלה.', 500 );
		}
	);
}

/**
 * Queue one small background batch, leaving ambiguous sources unchanged.
 *
 * @param int $delay Delay in seconds; longer after a persistence failure.
 * @return void
 */
function mapping_repair_schedule( $delay = 60 ) {
	if ( ! wp_next_scheduled( 'lcrm_mapping_repair' ) ) {
		wp_schedule_single_event( time() + $delay, 'lcrm_mapping_repair' );
	}
}

/** Initialize the upgrade job; activation is not required for an ordinary plugin update. */
function mapping_repair_init() {
	$state = mapping_repair_state();
	if ( is_wp_error( $state ) || empty( $state['done'] ) ) {
		mapping_repair_schedule();
	}
}

/**
 * Execute no more than 50 existing exceptions, with writes and cursor committed together.
 *
 * @return array|\WP_Error Migration counts, or a safe error with the cursor unchanged.
 */
function mapping_repair_run() {
	if ( ! wp_doing_cron() ) {
		return error( 'תיקון השיוך זמין כתהליך רקע בלבד.', 403 );
	}
	$result = locked(
		static function () {
			global $wpdb;
			$state = get_option( 'lcrm_mapping_repair', null );
			if ( ! mapping_repair_valid_state( $state ) ) {
				return error( 'מצב תיקון השיוך אינו זמין.', 409 );
			}
			if ( ! empty( $state['done'] ) ) {
				return $state;
			}
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = %s AND p.post_status = %s AND p.ID > %d AND p.ID <= %d GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d", '_lcrm_state', 'unmapped', 'lcrm_delivery', 'private', $state['cursor'], $state['max_id'], 50 ) );
			if ( '' !== $wpdb->last_error ) {
				return error( 'קריאת תיקון השיוך נכשלה. יש לנסות שוב.', 500 );
			}
			$queries = $wpdb->num_queries;
			update_meta_cache( 'post', $ids );
			if ( $wpdb->num_queries > $queries && $wpdb->last_error ) {
				return error( 'קריאת פרטי תיקון השיוך נכשלה. יש לנסות שוב.', 500 );
			}
			$catalog = native_titles();
			$batch   = array(
				'processed'  => 0,
				'repaired'   => 0,
				'deliveries' => 0,
				'unresolved' => 0,
			);
			foreach ( $ids as $id ) {
				$repaired = mapping_repair_item( (int) $id, $catalog );
				if ( is_wp_error( $repaired ) ) {
					return $repaired;
				}
				++$batch['processed'];
				$batch['repaired']   += $repaired['repaired'];
				$batch['deliveries'] += $repaired['deliveries'];
				$batch['unresolved'] += ! $repaired['repaired'] ? 1 : 0;
			}
			$state['cursor'] = $ids ? (int) end( $ids ) : $state['max_id'];
			$state['done']   = count( $ids ) < 50;
			foreach ( $batch as $key => $count ) {
				$state[ $key ] += $count;
			}
			update_option( 'lcrm_mapping_repair', $state, false );
			if ( get_option( 'lcrm_mapping_repair' ) !== $state ) {
				return error( 'שמירת מצב תיקון השיוך נכשלה.', 500 );
			}
			if ( $ids ) {
				audit( 'historical_mapping_repaired', 0, $batch );
			}
			return $state;
		}
	);
	if ( is_wp_error( $result ) || empty( $result['done'] ) ) {
		mapping_repair_schedule( is_wp_error( $result ) ? 300 : 60 );
	} else {
		wp_clear_scheduled_hook( 'lcrm_mapping_repair' );
	}
	return $result;
}

add_action( 'lcrm_mapping_repair', __NAMESPACE__ . '\\mapping_repair_run' );
