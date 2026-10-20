<?php
/**
 * Private WordPress records, transactions and billing invariants.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Lock and transaction statements must use the live connection, never cached results.
// phpcs:disable WordPress.DB.SlowDBQuery -- Private CPT metadata queries are bounded, with batch priming and no new tables per project rules.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Fixed VAT for every newly calculated bill, expressed in basis points. */
const VAT_BP = 1800;
/**
 * Determine whether the current user may manage the CRM.
 *
 * @return mixed Operation result.
 */
function manager() {
	return current_user_can( 'manage_options' );
}
/**
 * Determine whether the current authenticated user has portal access.
 *
 * @return mixed Operation result.
 */
function can_view() {
	return is_user_logged_in() && ( manager() || current_user_can( 'lcrm_view' ) );
}
/**
 * Return the explicit institution assignments of the current user.
 *
 * @return mixed Operation result.
 */
function allowed_institutions() {
	return array_values( array_filter( array_map( 'absint', (array) get_user_meta( get_current_user_id(), '_lcrm_institutions', true ) ) ) );
}
/**
 * Check object-level access to an institution.
 *
 * @param int $id WordPress object ID.
 * @return mixed Operation result or validation error.
 */
function owns( $id ) {
	return manager() || in_array( absint( $id ), allowed_institutions(), true );
}
/**
 * Build a safe REST error without database or exception details.
 *
 * @param string $message Safe user-facing explanation.
 * @param int    $status HTTP status code.
 * @return mixed Operation result or validation error.
 */
function error( $message, $status = 400 ) {
	return new \WP_Error( 'lcrm_error', $message, array( 'status' => $status ) );
}
/**
 * Read non-autoloaded CRM configuration with conservative defaults.
 *
 * @return mixed Operation result.
 */
function settings() {
	$defaults = array(
		'duplicate_mode' => 'all_time',
		'duplicate_days' => 30,
		'automatic'      => false,
		'start_date'     => '',
	);
	return wp_parse_args(
		array_intersect_key( (array) get_option( 'lcrm_settings', array() ), $defaults ),
		$defaults
	);
}
/**
 * Read the institution tariff history and credit terms.
 *
 * @param int $id WordPress object ID.
 * @return mixed Operation result or validation error.
 */
function agreement( $id ) {
	$data = get_post_meta( $id, '_lcrm_agreement', true );
	return is_array( $data ) ? $data : null;
}
/**
 * Read the institution's independent duplicate window without inheriting retired global rules.
 *
 * @param int $id Institution ID.
 * @return array Institution duplicate configuration.
 * @throws \InvalidArgumentException When saved configuration is malformed.
 */
function institution_policy( $id ) {
	$defaults = array(
		'duplicate_mode' => 'all_time',
		'duplicate_days' => 30,
	);
	if ( ! metadata_exists( 'post', $id, '_lcrm_duplicate_policy' ) ) {
		return $defaults;
	}
	$config = get_post_meta( $id, '_lcrm_duplicate_policy', true );
	if ( ! is_array( $config ) ) {
		throw new \InvalidArgumentException( 'Invalid institution duplicate configuration.' );
	}
	$config = wp_parse_args( array_intersect_key( $config, $defaults ), $defaults );
	$days   = $config['duplicate_days'];
	if ( ( ! is_int( $days ) && ! is_string( $days ) ) || ! preg_match( '/^\d{1,4}$/D', (string) $days ) || (int) $days < 1 || (int) $days > 3650 ) {
		throw new \InvalidArgumentException( 'Invalid institution duplicate day count.' );
	}
	duplicate_policy( $config );
	$config['duplicate_days'] = (int) $days;
	return $config;
}
/**
 * Read a private structured CRM record.
 *
 * @param int $id WordPress object ID.
 * @return mixed Operation result or validation error.
 */
function data( $id ) {
	$value = get_post_meta( $id, '_lcrm_data', true );
	if ( ! is_array( $value ) ) {
		return array();
	}
	if ( ! empty( $value['contact'] ) ) {
		$contact = absint( $value['contact'] );
		if ( 'leads' === get_post_type( $contact ) ) {
			$value['name']  = sanitize_text_field( get_post_meta( $contact, 'full-name', true ) );
			$value['phone'] = sanitize_text_field( get_post_meta( $contact, 'phone', true ) );
			$value['email'] = sanitize_email( get_post_meta( $contact, 'email', true ) );
		} else {
			$source = get_post_meta( $contact, '_lcrm_data', true );
			foreach ( array( 'name', 'phone', 'email' ) as $key ) {
				$value[ $key ] = is_array( $source ) ? ( $source[ $key ] ?? '' ) : '';
			}
		}
	}
	return $value;
}

/**
 * Verify transactional storage once per database/prefix for the current PHP request.
 * Failed checks are never cached and never permit the mutation callback to run.
 *
 * @return true|\WP_Error Whether all four mutable WordPress tables use InnoDB.
 */
function ensure_transactional_tables() {
	global $wpdb;
	$key = DB_NAME . ':' . $wpdb->prefix;
	if ( ! empty( $GLOBALS['lcrm_transactional_tables'][ $key ] ) ) {
		return true;
	}
	$required = array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->usermeta );
	$queries  = $wpdb->num_queries;
	$tables   = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s, %s, %s)', DB_NAME, $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->usermeta ), ARRAY_A );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $tables ) || 4 !== count( $tables ) ) {
		return error( 'שמירה מאובטחת דורשת טבלאות InnoDB. יש לפנות למנהל האתר.', 503 );
	}
	$verified = array();
	foreach ( $tables as $table ) {
		if ( ! isset( $table['TABLE_NAME'], $table['ENGINE'] ) || ! is_string( $table['ENGINE'] ) || ! in_array( $table['TABLE_NAME'], $required, true ) || 'INNODB' !== strtoupper( $table['ENGINE'] ) ) {
			return error( 'שמירה מאובטחת דורשת טבלאות InnoDB. יש לפנות למנהל האתר.', 503 );
		}
		$verified[ $table['TABLE_NAME'] ] = true;
	}
	if ( 4 !== count( $verified ) ) {
		return error( 'שמירה מאובטחת דורשת טבלאות InnoDB. יש לפנות למנהל האתר.', 503 );
	}
	$GLOBALS['lcrm_transactional_tables'][ $key ] = true;
	return true;
}
/**
 * All mutations share a database advisory lock, including parallel REST and cron workers.
 *
 * @param callable $callback Mutation executed under lock or savepoint.
 * @param int      $wait_seconds Maximum lock wait; background retries use zero.
 * @return mixed Operation result or validation error.
 */
function locked( $callback, $wait_seconds = 5 ) {
	global $wpdb;
	if ( isset( $GLOBALS['lcrm_changed_records'] ) ) {
		return error( 'לא ניתן להתחיל פעולה מקבילה בתוך פעולה קיימת.', 409 );
	}
	$name         = 'lcrm_' . md5( DB_NAME . ':' . $wpdb->prefix );
	$wait_seconds = max( 0, min( 5, (int) $wait_seconds ) );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait_seconds ) ) ) {
		$busy = error( 'המערכת עסוקה. נסו שוב בעוד רגע.', 409 );
		$busy->add_data(
			array(
				'status'    => 409,
				'lock_busy' => true,
			)
		);
		return $busy;
	}
	$committed   = false;
	$changed_ids = array();
	try {
		$capability = ensure_transactional_tables();
		if ( is_wp_error( $capability ) ) {
			return $capability;
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return error( 'לא ניתן להתחיל שמירה. יש לנסות שוב.', 503 );
		}
		$GLOBALS['lcrm_changed_records'] = array();
		$GLOBALS['lcrm_changed_users']   = array();
		$result                          = $callback();
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			rollback_caches();
		} elseif ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			rollback_caches();
			$result = error( 'הפעולה לא נשמרה. יש לנסות שוב.', 500 );
		} else {
			$committed   = true;
			$changed_ids = array_values( array_unique( $GLOBALS['lcrm_changed_records'] ?? array() ) );
		}
	} catch ( \Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		rollback_caches();
		$result = error( 'הפעולה לא נשמרה. יש לנסות שוב.', 500 );
	} finally {
		unset( $GLOBALS['lcrm_changed_records'], $GLOBALS['lcrm_changed_users'] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
	if ( $committed ) {
		try {
			/** Invalidate derived state only after commit; IDs remain available after transaction cleanup. */
			do_action( 'lcrm_committed', $changed_ids );
		} catch ( \Throwable $exception ) {
			// The mutation has committed and must never be reported as rolled back.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Static operational diagnostic contains no user data or exception details.
			error_log( 'CRM: post-commit notification failed.' );
		}
	}
	return $result;
}
/**
 * Read authorized private-record snapshots directly from committed database metadata.
 * No contact hydration or object-cache values are used for financial guards or totals.
 *
 * @param array $ids IDs already selected by a scoped query.
 * @return array Snapshots in the caller's original ID order.
 * @throws \RuntimeException When a snapshot is missing, malformed or cannot be read.
 */
function raw_records( $ids ) {
	global $wpdb;
	if ( ! $ids ) {
		return array();
	}
	if ( count( $ids ) > 5000 ) {
		throw new \RuntimeException( 'Private record batch exceeds its bound.' );
	}
	foreach ( $ids as $id ) {
		if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[1-9]\d*$/D', (string) $id ) ) {
			throw new \RuntimeException( 'Invalid private record ID.' );
		}
	}
	$records = array();
	foreach ( array_chunk( array_unique( array_map( 'intval', $ids ) ), 500 ) as $batch ) {
		$placeholders = implode( ', ', array_fill( 0, count( $batch ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated internally and every ID is passed through prepare().
		$sql     = $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)", array_merge( array( '_lcrm_data' ), $batch ) );
		$queries = $wpdb->num_queries;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with bounded numeric IDs.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Private record snapshot lookup failed.' );
		}
		foreach ( $rows as $row ) {
			$id   = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			$item = isset( $row['meta_value'] ) ? maybe_unserialize( $row['meta_value'] ) : null;
			if ( ! in_array( $id, $batch, true ) || isset( $records[ $id ] ) || ! is_array( $item ) || ! isset( $item['id'] ) || ( ! is_int( $item['id'] ) && ! is_string( $item['id'] ) ) || (string) $id !== (string) $item['id'] ) {
				throw new \RuntimeException( 'Invalid private record snapshot.' );
			}
			$records[ $id ] = $item;
		}
		foreach ( $batch as $id ) {
			if ( ! isset( $records[ $id ] ) ) {
				throw new \RuntimeException( 'Private record snapshot is missing.' );
			}
		}
	}
	return array_map(
		static function ( $id ) use ( $records ) {
			return $records[ (int) $id ];
		},
		$ids
	);
}
/**
 * Store structured payloads in private WordPress records with separate indexed query metadata.
 *
 * @param string $type Private record post type.
 * @param array  $payload Validated business payload.
 * @param int    $id WordPress object ID.
 * @return mixed Operation result or validation error.
 */
function save_record( $type, $payload, $id = 0 ) {
	if ( 'lcrm_delivery' === $type && array_key_exists( 'billing_excluded', $payload ) && ! is_bool( $payload['billing_excluded'] ) ) {
		return error( 'מצב החרגה מחיוב לא תקין.', 500 );
	}
	if ( 'lcrm_delivery' === $type ) {
		foreach ( array(
			'phone_key' => 'normalize_phone',
			'email_key' => 'normalize_email',
		) as $key => $normalizer ) {
			$value = array_key_exists( $key, $payload ) ? $payload[ $key ] : '';
			if ( ! is_string( $value ) || ( '' !== $value && call_user_func( __NAMESPACE__ . '\\' . $normalizer, $value ) !== $value ) ) {
				return error( 'מפתח זיהוי פנייה לא תקין.', 500 );
			}
		}
	}
	$post = array(
		'post_type'   => $type,
		'post_status' => 'private',
		'post_title'  => 'lcrm_delivery' === $type ? ( $payload['name'] ?? ( $id ? get_post_field( 'post_title', $id, 'raw' ) : 'פנייה' ) ) : $type,
		'post_author' => get_current_user_id(),
	);
	if ( $id ) {
		$post['ID'] = $id;
	}
	if ( 'lcrm_delivery' === $type && ! empty( $payload['contact'] ) ) {
		unset( $payload['name'], $payload['phone'], $payload['email'] );
	}
	$saved = wp_insert_post( wp_slash( $post ), true );
	if ( is_wp_error( $saved ) ) {
		return error( 'שמירת הנתונים נכשלה.', 500 );
	}
	if ( isset( $GLOBALS['lcrm_changed_records'] ) ) {
		$GLOBALS['lcrm_changed_records'][] = $saved;
	}
	$payload['id'] = $saved;
	update_post_meta( $saved, '_lcrm_data', wp_slash( $payload ) );
	foreach ( array( 'institution', 'month', 'state', 'source', 'date', 'bill', 'billing_excluded', 'phone_key', 'email_key' ) as $key ) {
		if ( 'billing_excluded' === $key && 'lcrm_delivery' === $type ) {
			$value = (int) ( $payload['billing_excluded'] ?? false );
		} elseif ( in_array( $key, array( 'phone_key', 'email_key' ), true ) && 'lcrm_delivery' === $type ) {
			$value = $payload[ $key ] ?? '';
		} elseif ( isset( $payload[ $key ] ) ) {
			$value = $payload[ $key ];
		} else {
			continue;
		}
		update_post_meta( $saved, '_lcrm_' . $key, wp_slash( $value ) );
		if ( (string) get_post_meta( $saved, '_lcrm_' . $key, true ) !== (string) $value ) {
			return error( 'שמירת אינדקס הרשומה נכשלה.', 500 );
		}
	}
	if ( get_post_meta( $saved, '_lcrm_data', true ) !== $payload ) {
		return error( 'שמירת הנתונים נכשלה.', 500 );
	}
	return 'lcrm_delivery' === $type ? data( $saved ) : $payload;
}
/**
 * Audit business actions without contact details, secrets, or payment references.
 *
 * @param string $event Audit event key.
 * @param int    $target Object ID.
 * @param array  $extra Non-sensitive details.
 * @return void
 * @throws \RuntimeException When an audit write fails inside a business transaction.
 */
function audit( $event, $target, $extra = array() ) {
	$result = save_record(
		'lcrm_audit',
		array(
			'event'   => $event,
			'target'  => absint( $target ),
			'actor'   => get_current_user_id(),
			'at'      => current_time( 'mysql' ),
			'details' => $extra,
		)
	);
	if ( is_wp_error( $result ) && isset( $GLOBALS['lcrm_changed_records'] ) ) {
		throw new \RuntimeException( 'Audit write failed.' );
	}
}
/**
 * Fetch bounded private records with institution scope applied before pagination.
 *
 * @param string $type Private post type.
 * @param array  $filters Validated metadata filters.
 * @param int    $page One-based page.
 * @param int    $limit Maximum row count.
 * @param string $search Sanitized name search.
 * @param bool   $hydrate Include contact details; false is reserved for summary batches with totals on page one only.
 * @return array Items and totals; raw summary batches after page one return zero totals and pages.
 */
function query_records( $type, $filters = array(), $page = 1, $limit = 30, $search = '', $hydrate = true ) {
	$meta = array( 'relation' => 'AND' );
	if ( ! manager() ) {
		$ids = allowed_institutions();
		if ( ! $ids ) {
			return array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}
		$meta[] = array(
			'key'     => '_lcrm_institution',
			'value'   => $ids,
			'compare' => 'IN',
			'type'    => 'NUMERIC',
		);
		if ( 'lcrm_delivery' === $type ) {
			$meta[] = array(
				'key'     => '_lcrm_state',
				'value'   => array( 'sent', 'historical' ),
				'compare' => 'IN',
			);
		} elseif ( 'lcrm_bill' === $type ) {
			$meta[] = array(
				'key'   => '_lcrm_state',
				'value' => 'approved',
			);
		}
	}
	if ( isset( $filters['date_from'], $filters['date_to'] ) ) {
		if ( 'lcrm_bill' === $type ) {
			$meta[] = array(
				'key'     => '_lcrm_month',
				'value'   => array( substr( $filters['date_from'], 0, 7 ), substr( $filters['date_to'], 0, 7 ) ),
				'compare' => 'BETWEEN',
			);
		} else {
			$meta[] = array(
				'key'     => '_lcrm_date',
				'value'   => array( 'lcrm_payment' === $type ? $filters['date_from'] : $filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59' ),
				'compare' => 'BETWEEN',
			);
		}
	}
	foreach ( $filters as $key => $value ) {
		if ( in_array( $key, array( 'date_from', 'date_to' ), true ) ) {
			continue;
		}
		if ( '' !== $value && null !== $value ) {
			if ( 'billing_status' === $key ) {
				$meta[] = array(
					'key'   => '_lcrm_billing_excluded',
					'value' => 'excluded' === $value && 'lcrm_delivery' === $type ? '1' : 'invalid',
				);
				continue;
			}
			if ( 'lcrm_payment' === $type && in_array( $key, array( 'month', 'year' ), true ) ) {
				$first  = 'year' === $key ? $value . '-01-01' : $value . '-01';
				$last   = 'year' === $key ? $value . '-12-31' : ( new \DateTimeImmutable( $first, wp_timezone() ) )->format( 'Y-m-t' );
				$meta[] = array(
					'key'     => '_lcrm_date',
					'value'   => array( $first, $last . ' 23:59:59' ),
					'compare' => 'BETWEEN',
				);
				continue;
			}
			if ( 'year' === $key ) {
				$meta[] = array(
					'key'     => '_lcrm_month',
					'value'   => array( $value . '-01', $value . '-12' ),
					'compare' => 'BETWEEN',
				);
				continue;
			}
			$meta[] = array(
				'key'     => '_lcrm_' . $key,
				'value'   => $value,
				'compare' => '=',
			);
		}
	}
	$q = new \WP_Query(
		array(
			'post_type'              => $type,
			'post_status'            => 'private',
			'posts_per_page'         => min( 5000, max( 1, $limit ) ),
			'paged'                  => max( 1, $page ),
			's'                      => $search,
			'meta_query'             => $meta,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'cache_results'          => $hydrate,
			'update_post_meta_cache' => $hydrate,
			'update_post_term_cache' => $hydrate,
			// Summary callers retain the first page's total; later batches do not need another count query.
			'no_found_rows'          => ! $hydrate && $page > 1,
		)
	);
	if ( $hydrate ) {
		prime_contacts( wp_list_pluck( $q->posts, 'ID' ) );
		$items = array_map(
			static function ( $post ) {
				return data( $post->ID );
			},
			$q->posts
		);
	} else {
		$items = raw_records( wp_list_pluck( $q->posts, 'ID' ) );
	}
	return array(
		'items' => $items,
		'total' => (int) $q->found_posts,
		'pages' => (int) $q->max_num_pages,
	);
}
/**
 * Load deliveries for one institution in chronological order; batches remain bounded by month in billing.
 *
 * @param int             $id WordPress object ID.
 * @param string|null     $before Inclusive upper timestamp bound.
 * @param int|string|null $after Cursor or lower timestamp bound.
 * @param bool            $hydrate Whether contact details are needed by the caller.
 * @return mixed Operation result or validation error.
 * @throws \RuntimeException When an authoritative delivery lookup fails.
 */
function institution_deliveries( $id, $before = null, $after = null, $hydrate = true ) {
	global $wpdb;
	$meta = array(
		array(
			'key'   => '_lcrm_institution',
			'value' => $id,
			'type'  => 'NUMERIC',
		),
	);
	if ( $before ) {
		$meta[] = array(
			'key'     => '_lcrm_date',
			'value'   => $before,
			'compare' => '<=',
		);
	}
	if ( $after ) {
		$meta[] = array(
			'key'     => '_lcrm_date',
			'value'   => $after,
			'compare' => '>=',
		);
	}
	$queries = $wpdb->num_queries;
	$ids     = get_posts(
		array(
			'post_type'              => 'lcrm_delivery',
			'post_status'            => 'private',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'meta_query'             => $meta,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'cache_results'          => $hydrate,
			'update_post_meta_cache' => $hydrate,
			'update_post_term_cache' => $hydrate,
		)
	);
	if ( ! $hydrate && ( $wpdb->num_queries <= $queries || $wpdb->last_error ) ) {
		throw new \RuntimeException( 'Institution delivery lookup failed.' );
	}
	if ( $hydrate ) {
		update_meta_cache( 'post', $ids );
		prime_contacts( $ids );
		$items = array_map( __NAMESPACE__ . '\\data', $ids );
	} else {
		$items = array();
		foreach ( array_chunk( $ids, 5000 ) as $batch ) {
			foreach ( raw_records( $batch ) as $item ) {
				$items[] = $item;
			}
		}
	}
	usort(
		$items,
		function ( $a, $b ) {
			return array( $a['date'], $a['id'] ) <=> array( $b['date'], $b['id'] );
		}
	);
	return $items;
}
/**
 * Find a unique private source record without WordPress query or object caches.
 *
 * @param string $type Private source record type.
 * @param string $source Stable source idempotency key.
 * @param int    $institution Recipient for delivery records; zero for contacts.
 * @return array|\WP_Error At most one matching record ID or a source conflict.
 * @throws \RuntimeException When a lookup fails or the source is ambiguous.
 */
function source_record_ids( $type, $source, $institution = 0 ) {
	global $wpdb;
	if ( 'lcrm_delivery' === $type ) {
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = %s AND CAST(i.meta_value AS SIGNED) = %d WHERE p.post_type = %s AND p.post_status = %s ORDER BY p.ID ASC LIMIT 2",
			'_lcrm_source',
			$source,
			'_lcrm_institution',
			$institution,
			$type,
			'private'
		);
	} elseif ( 'lcrm_contact' === $type ) {
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s WHERE p.post_type = %s AND p.post_status = %s ORDER BY p.ID ASC LIMIT 2",
			'_lcrm_source',
			$source,
			$type,
			'private'
		);
	} else {
		throw new \RuntimeException( 'Invalid source record type.' );
	}
	$queries = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both fixed query shapes are prepared immediately above.
	$ids = $wpdb->get_col( $sql );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $ids ) ) {
		throw new \RuntimeException( 'Source record lookup failed or is ambiguous.' );
	}
	if ( count( $ids ) > 1 ) {
		return capture_retry_failure( 'identity_conflict', 'נמצאו רישומי מקור סותרים. נדרשת בדיקת מנהל.' );
	}
	return array_map( 'intval', $ids );
}
/**
 * Record automatic source events against validated institutions.
 *
 * @param array       $lead Validated contact and delivery context.
 * @param int         $institution Server-validated recipient institution.
 * @param string      $source Stable source idempotency key.
 * @param bool        $historical Whether delivery evidence is unverified historical data.
 * @param string|null $saved_policy Immutable server-side policy for delayed remapping.
 * @return mixed Operation result or validation error.
 * @throws \RuntimeException When existing source records cannot be verified.
 */
function record_delivery( $lead, $institution, $source, $historical = false, $saved_policy = null ) {
	if ( ! is_array( $lead ) || ! is_string( $source ) || '' === $source || strlen( $source ) > 180 || ! isset( $lead['date'] ) || ! is_string( $lead['date'] ) || ! valid_date( substr( $lead['date'], 0, 10 ) ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $lead['date'] ) ) {
		return error( 'פרטי מקור או מועד פנייה לא תקינים.' );
	}
	foreach ( array( 'name', 'phone', 'email', 'form' ) as $field ) {
		if ( isset( $lead[ $field ] ) && ( ! is_string( $lead[ $field ] ) || strlen( $lead[ $field ] ) > 600 ) ) {
			return error( 'פרטי פנייה לא תקינים.' );
		}
	}
	if ( 'institutions' !== get_post_type( $institution ) ) {
		return error( 'מוסד לא תקין.' );
	}
	$lead['phone_key'] = normalize_phone( $lead['phone'] ?? '' );
	$lead['email_key'] = normalize_email( $lead['email'] ?? '' );
	$existing          = source_record_ids( 'lcrm_delivery', $source, $institution );
	if ( is_wp_error( $existing ) ) {
		return $existing;
	}
	if ( $existing ) {
		$item = raw_records( $existing )[0];
		if ( ! isset( $item['source'], $item['institution'], $item['phone_key'], $item['email_key'] ) || $source !== $item['source'] || ( ! is_int( $item['institution'] ) && ! is_string( $item['institution'] ) ) || (string) $institution !== (string) $item['institution'] || ! is_string( $item['phone_key'] ) || ! is_string( $item['email_key'] ) || normalize_phone( $item['phone_key'] ) !== $item['phone_key'] || normalize_email( $item['email_key'] ) !== $item['email_key'] ) {
			throw new \RuntimeException( 'Invalid delivery source snapshot.' );
		}
		if ( ! $historical && ( $item['phone_key'] !== $lead['phone_key'] || $item['email_key'] !== $lead['email_key'] ) ) {
			return capture_retry_failure( 'identity_conflict', 'מזהה המסירה כבר קיים עם פרטי קשר אחרים.' );
		}
		// Hydrate display details only; committed financial fields always take precedence.
		return array_merge( data( $existing[0] ), $item );
	}
	if ( ! $historical ) {
		$existing_bill = bill_for( $institution, substr( $lead['date'], 0, 7 ) );
		if ( $existing_bill && 'approved' === $existing_bill['state'] ) {
			return capture_retry_failure( 'approved_month', 'התקופה כבר אושרה. נדרשת התאמה כספית נפרדת.' );
		}
	}
	if ( ! $lead['phone_key'] && ! $lead['email_key'] && ! $historical ) {
		return capture_retry_failure( 'invalid_payload', 'נדרש טלפון או אימייל תקין.', 400 );
	}
	$contact_id = ! empty( $lead['contact'] ) ? absint( $lead['contact'] ) : 0;
	if ( ! $contact_id && ! empty( $lead['legacy_id'] ) && 'leads' === get_post_type( $lead['legacy_id'] ) ) {
		$contact_id = absint( $lead['legacy_id'] );
	}
	if ( ! $contact_id ) {
		$contacts = source_record_ids( 'lcrm_contact', $source );
		if ( is_wp_error( $contacts ) ) {
			return $contacts;
		}
		if ( $contacts ) {
			$contact_id = $contacts[0];
			$contact    = raw_records( $contacts )[0];
			if ( ! isset( $contact['source'], $contact['name'], $contact['phone'], $contact['email'] ) || $source !== $contact['source'] ) {
				throw new \RuntimeException( 'Invalid contact source snapshot.' );
			}
			foreach ( array( 'name', 'phone', 'email' ) as $field ) {
				if ( ! is_string( $contact[ $field ] ) || strlen( $contact[ $field ] ) > 600 ) {
					throw new \RuntimeException( 'Invalid contact source identity.' );
				}
			}
			$contact['phone_key'] = normalize_phone( $contact['phone'] );
			$contact['email_key'] = normalize_email( $contact['email'] );
			if ( ! $historical && ( $lead['phone_key'] !== $contact['phone_key'] || $lead['email_key'] !== $contact['email_key'] ) ) {
				return capture_retry_failure( 'identity_conflict', 'מזהה הפנייה כבר קיים עם פרטי קשר אחרים.' );
			}
		} else {
				$contact = save_record(
					'lcrm_contact',
					array(
						'source' => $source,
						'name'   => $lead['name'] ?? '',
						'phone'  => $lead['phone'] ?? '',
						'email'  => $lead['email'] ?? '',
					)
				);
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}
			$contact_id = $contact['id'];
		}
	}
	$lead['contact'] = $contact_id;
	$policy          = null === $saved_policy ? duplicate_policy( institution_policy( $institution ) ) : $saved_policy;
	// Validate a preserved remapping policy before saving. Reconciliation matches once below.
	duplicate_window_start( $lead['date'], $policy );
	$item   = array_merge(
		$lead,
		array(
			'treatment'      => 'new',
			'notes'          => array(),
			'institution'    => $institution,
			'source'         => $source,
			'month'          => substr( $lead['date'], 0, 7 ),
			'state'          => $historical ? 'historical' : 'sent',
			'duplicate_of'   => 0,
			'duplicate_mode' => $policy,
			'bill'           => 0,
		)
	);
	$result = save_record( 'lcrm_delivery', $item );
	return is_wp_error( $result ) ? $result : reconcile_duplicates( $result );
}
/**
 * Find the unique institution and service-month bill.
 *
 * @param int    $id WordPress object ID.
 * @param string $month Completed service month in YYYY-MM format.
 * @return mixed Operation result or validation error.
 * @throws \RuntimeException When the billing lookup or frozen snapshot cannot be verified.
 */
function bill_for( $id, $month ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$ids     = get_posts(
		array(
			// Financial guards must not reuse a cached empty result from a failed database lookup.
			'cache_results'  => false,
			'post_type'      => 'lcrm_bill',
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_lcrm_institution',
					'value' => $id,
					'type'  => 'NUMERIC',
				),
				array(
					'key'   => '_lcrm_month',
					'value' => $month,
				),
			),
		)
	);
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error ) {
		throw new \RuntimeException( 'Billing period lookup failed.' );
	}
	if ( ! $ids ) {
		return null;
	}
	$items = raw_records( $ids );
	$bill  = $items[0];
	if ( ! isset( $bill['state'], $bill['institution'], $bill['month'] ) || ! in_array( $bill['state'], array( 'draft', 'approved' ), true ) || ( ! is_int( $bill['institution'] ) && ! is_string( $bill['institution'] ) ) || (string) $id !== (string) $bill['institution'] || $month !== $bill['month'] ) {
		throw new \RuntimeException( 'Billing period snapshot is invalid.' );
	}
	return $bill;
}
/**
 * Draft totals are calculated again at approval. Approved snapshots are immutable.
 *
 * @param int    $id WordPress object ID.
 * @param string $month Completed service month in YYYY-MM format.
 * @return mixed Operation result or validation error.
 */
function prepare_bill( $id, $month ) {
	if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/D', $month ) || $month >= current_time( 'Y-m' ) ) {
		return error( 'ניתן לסכם רק חודש שהסתיים.' );
	}
	$s      = settings();
	$config = agreement( $id );
	if ( ! $config || ! valid_date( $s['start_date'] ) ) {
		return error( 'יש להגדיר הסכם ותאריך תחילת חיוב.' );
	}
	$existing = bill_for( $id, $month );
	if ( $existing && 'approved' === $existing['state'] ) {
		return $existing;
	}
	$first = $month . '-01 00:00:00';
	$end   = ( new \DateTimeImmutable( $first, wp_timezone() ) )->modify( 'last day of this month' )->format( 'Y-m-d' ) . ' 23:59:59';
	if ( substr( $end, 0, 10 ) < $s['start_date'] ) {
		return error( 'התקופה קודמת לתחילת החיוב.' );
	}
	$items      = institution_deliveries( $id, $end, $first, false );
	$lines      = array();
	$subtotal   = 0;
	$vat        = 0;
	$duplicates = 0;
	$historical = 0;
	foreach ( $items as $item ) {
		if ( $item['duplicate_of'] ) {
			++$duplicates;
			continue;
		}
		if ( 'sent' !== $item['state'] ) {
			++$historical;
			continue;
		}
		if ( ! empty( $item['billing_excluded'] ) ) {
			continue;
		}
		if ( substr( $item['date'], 0, 10 ) < $s['start_date'] ) {
			continue;
		}
		if ( $item['bill'] && ( ! $existing || $existing['id'] !== $item['bill'] ) ) {
			return error( 'רשומה כבר משויכת לחיוב אחר.', 409 );
		}
		$rate = rate_on( $config, substr( $item['date'], 0, 10 ) );
		if ( ! $rate ) {
			return error( 'חסר תעריף תקף לחלק מהלידים.' );
		}
		$line_vat  = intdiv( $rate['price'] * VAT_BP + 5000, 10000 );
		$lines[]   = array(
			'delivery' => $item['id'],
			'price'    => $rate['price'],
			'vat_bp'   => VAT_BP,
			'vat'      => $line_vat,
		);
		$subtotal += $rate['price'];
		$vat      += $line_vat;
	}
	return save_record(
		'lcrm_bill',
		array(
			'institution'    => $id,
			'month'          => $month,
			'state'          => 'draft',
			'lines'          => $lines,
			'subtotal'       => $subtotal,
			'vat'            => $vat,
			'total'          => $subtotal + $vat,
			'duplicates'     => $duplicates,
			'historical'     => $historical,
			'paid'           => 0,
			'issued'         => '',
			'due'            => '',
			'credit_days'    => $config['credit_days'],
			'document_state' => 'not_connected',
		),
		$existing['id'] ?? 0
	);
}
/**
 * Recalculate, validate and freeze a monthly billing snapshot.
 *
 * @param int $id WordPress object ID.
 * @return mixed Operation result or validation error.
 */
function approve_bill( $id ) {
	if ( 'lcrm_bill' !== get_post_type( $id ) ) {
		return error( 'חיוב לא נמצא.', 404 );
	}
	$bill = raw_records( array( $id ) )[0];
	if ( 'approved' === $bill['state'] ) {
		return $bill;
	}
	$preview = $bill;
	$bill    = prepare_bill( $bill['institution'], $bill['month'] );
	if ( is_wp_error( $bill ) ) {
		return $bill;
	}
	if ( $preview['lines'] !== $bill['lines'] || $preview['total'] !== $bill['total'] || $preview['credit_days'] !== $bill['credit_days'] ) {
		return error( 'החיוב השתנה מאז הכנת הטיוטה. יש להכין מחדש את הטיוטה ולבדוק לפני אישור.', 409 );
	}
	if ( ! $bill['lines'] ) {
		return error( 'אין לידים לחיוב בתקופה זו.' );
	}
	$bill['state']       = 'approved';
	$bill['issued']      = current_time( 'Y-m-d' );
	$bill['due']         = due_date( $bill['issued'], $bill['credit_days'] );
	$bill['approved_by'] = get_current_user_id();
	$bill['approved_at'] = current_time( 'mysql' );
	$result              = save_record( 'lcrm_bill', $bill, $id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	foreach ( array_chunk( array_column( $bill['lines'], 'delivery' ), 5000 ) as $batch ) {
		foreach ( raw_records( $batch ) as $item ) {
			$item['bill'] = $id;
			$saved        = save_record( 'lcrm_delivery', $item, $item['id'] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
	}
	audit( 'bill_approved', $id, array( 'total' => $bill['total'] ) );
	return $result;
}
/**
 * Retry-safe payments: persistent request key, integer amounts, and derived payment totals.
 *
 * @param int   $bill_id Approved billing snapshot ID.
 * @param array $input Validated request payload.
 * @return mixed Operation result or validation error.
 * @throws \RuntimeException When an authoritative bill or payment snapshot cannot be verified.
 */
function record_payment( $bill_id, $input ) {
	global $wpdb;
	if ( 'lcrm_bill' !== get_post_type( $bill_id ) ) {
		return error( 'חיוב לא נמצא.', 404 );
	}
	$bill = raw_records( array( $bill_id ) )[0];
	if ( 'approved' !== ( $bill['state'] ?? '' ) ) {
		return error( 'יש לאשר את החיוב לפני תשלום.' );
	}
	if ( ! isset( $bill['total'], $bill['institution'] ) || ! is_int( $bill['total'] ) || $bill['total'] < 0 || empty( $bill['institution'] ) ) {
		throw new \RuntimeException( 'Approved payment snapshot is invalid.' );
	}
	$amount = money( $input['amount'] ?? '' );
	$date   = $input['date'] ?? '';
	$key    = $input['request_key'] ?? '';
	if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9-]{16,64}$/D', $key ) || ! valid_date( $date ) || $date > current_time( 'Y-m-d' ) || null === $amount || $amount <= 0 ) {
		return error( 'פרטי תשלום לא תקינים.' );
	}
	$method    = sanitize_key( $input['method'] ?? '' );
	$reference = sanitize_text_field( substr( (string) ( $input['reference'] ?? '' ), 0, 200 ) );
	if ( ! in_array( $method, array( 'transfer', 'card', 'check', 'cash', 'other' ), true ) ) {
		return error( 'יש לבחור אמצעי תשלום.' );
	}
	$queries  = $wpdb->num_queries;
	$payments = get_posts(
		array(
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'post_type'              => 'lcrm_payment',
			'post_status'            => 'private',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'meta_key'               => '_lcrm_bill',
			'meta_value'             => $bill_id,
		)
	);
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error ) {
		throw new \RuntimeException( 'Payment ledger lookup failed.' );
	}
	$paid = 0;
	foreach ( array_chunk( $payments, 5000 ) as $batch ) {
		foreach ( raw_records( $batch ) as $p ) {
			if ( ! isset( $p['amount'], $p['bill'], $p['request_key'] ) || ! is_int( $p['amount'] ) || $p['amount'] <= 0 || ! is_string( $p['request_key'] ) || ( ! is_int( $p['bill'] ) && ! is_string( $p['bill'] ) ) || (string) $bill_id !== (string) $p['bill'] ) {
				throw new \RuntimeException( 'Payment ledger snapshot is invalid.' );
			}
			if ( $p['request_key'] === $key ) {
				if ( $p['amount'] !== $amount || ( $p['date'] ?? null ) !== $date || ( $p['method'] ?? null ) !== $method || ( $p['reference'] ?? null ) !== $reference ) {
					return error( 'הניסיון הקודם כבר נשמר עם פרטי תשלום אחרים. יש לרענן ולבדוק את היתרה.', 409 );
				}
				return $p;
			}
			$paid += $p['amount'];
		}
	}
	if ( $paid + $amount > $bill['total'] ) {
		return error( 'התשלום גבוה מהיתרה.', 409 );
	}
	$payment = save_record(
		'lcrm_payment',
		array(
			'institution'    => $bill['institution'],
			'bill'           => $bill_id,
			'amount'         => $amount,
			'date'           => $date,
			'method'         => $method,
			'reference'      => $reference,
			'request_key'    => $key,
			'actor'          => get_current_user_id(),
			'state'          => 'recorded',
			'document_state' => 'not_connected',
		)
	);
	if ( is_wp_error( $payment ) ) {
		return $payment;
	}
	$bill['paid'] = $paid + $amount;
	$saved_bill   = save_record( 'lcrm_bill', $bill, $bill_id );
	if ( is_wp_error( $saved_bill ) ) {
		return $saved_bill;
	} audit( 'payment_recorded', $bill_id, array( 'amount' => $amount ) );
	return $payment;
}

/**
 * A failed batch item rolls back independently while other selected items can succeed.
 *
 * @param callable $callback Mutation executed under lock or savepoint.
 * @return mixed Operation result or validation error.
 * @throws \RuntimeException When a savepoint operation fails; the enclosing transaction rolls back.
 */
function attempt( $callback ) {
	global $wpdb;
	if ( false === $wpdb->query( 'SAVEPOINT lcrm_item' ) ) {
		throw new \RuntimeException( 'Savepoint failed.' );
	}
	$result = $callback();
	if ( is_wp_error( $result ) ) {
		if ( false === $wpdb->query( 'ROLLBACK TO SAVEPOINT lcrm_item' ) ) {
			throw new \RuntimeException( 'Savepoint rollback failed.' );
		}
		rollback_caches();
	}
	if ( false === $wpdb->query( 'RELEASE SAVEPOINT lcrm_item' ) ) {
		throw new \RuntimeException( 'Savepoint release failed.' );
	}
	return $result;
}

/**
 * Batch-prime shared contact records referenced by delivery rows.
 *
 * @param array $ids Delivery post IDs.
 * @return void
 */
function prime_contacts( $ids ) {
	$contacts = array();
	foreach ( $ids as $id ) {
		$row = get_post_meta( $id, '_lcrm_data', true );
		if ( is_array( $row ) && ! empty( $row['contact'] ) ) {
			$contacts[] = absint( $row['contact'] );
		}
	}
	$contacts = array_values( array_unique( $contacts ) );
	if ( $contacts ) {
		get_posts(
			array(
				'post_type'      => array( 'lcrm_contact', 'leads' ),
				'post_status'    => array( 'private', 'publish', 'draft' ),
				'post__in'       => $contacts,
				'posts_per_page' => count( $contacts ),
				'no_found_rows'  => true,
			)
		);
	}
}

/**
 * Invalidate only objects and options touched by a failed CRM transaction.
 *
 * @return void
 */
function rollback_caches() {
	foreach ( $GLOBALS['lcrm_changed_records'] ?? array() as $id ) {
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
	}
	foreach ( $GLOBALS['lcrm_changed_users'] ?? array() as $id ) {
		clean_user_cache( $id );
		wp_cache_delete( $id, 'user_meta' );
	}
	foreach ( array( 'lcrm_settings', 'lcrm_mapping_repair', 'lcrm_duplicate_index', 'lcrm_icount_config', 'lcrm_icount_automatic', 'lcrm_icount_namespace' ) as $option ) {
		wp_cache_delete( $option, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}
