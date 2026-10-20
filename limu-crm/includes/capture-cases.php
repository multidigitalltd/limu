<?php
/**
 * Private capture exceptions and explicit manager decisions.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- Capture decisions use authoritative prepared queries on the shared transaction connection.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CAPTURE_CASE_VERSION = 1;

/**
 * Cases expose private contact data to managers with a fresh REST nonce only.
 *
 * @param \WP_REST_Request $request Request.
 * @return true|\WP_Error
 */
function capture_case_permission( $request ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה.', 403 );
	}
	return wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ? true : error( 'יש להתחבר מחדש.', 403 );
}

/**
 * Return safe, fixed explanations rather than internal exception messages.
 *
 * @return array
 */
function capture_case_reasons() {
	return array(
		'unattempted'       => 'הפנייה נשמרה וממתינה לניסיון קליטה.',
		'temporary'         => 'הקליטה לא הושלמה עקב תקלה זמנית או נעילה תפוסה.',
		'approved_month'    => 'הקליטה משפיעה על חודש שכבר אושר. נדרשת החלטת מנהל.',
		'identity_conflict' => 'מזהה הפנייה קיים עם פרטי קשר סותרים. נדרש בירור ידני.',
		'invalid_payload'   => 'פרטי הפנייה שנשמרו אינם תקינים או אינם חד־משמעיים.',
		'manual_retry'      => 'המנהל ביקש לנסות את הקליטה מחדש.',
	);
}

/**
 * New cases remain eligible for a technical retry.
 *
 * @return array
 */
function capture_case_default() {
	return array(
		'version'      => CAPTURE_CASE_VERSION,
		'state'        => 'retry',
		'reason'       => 'unattempted',
		'last_attempt' => '',
		'attempts'     => 0,
		'actor'        => 0,
		'decision_at'  => '',
		'closed_at'    => '',
		'note'         => '',
	);
}

/**
 * Validate persisted case details and their scalar query mirror.
 *
 * @param array $rows Raw metadata rows.
 * @return array|null|\WP_Error
 */
function capture_case_parse( $rows ) {
	$values = array();
	foreach ( $rows as $row ) {
		if ( in_array( strtolower( $row['meta_key'] ), array( '_lcrm_capture_case', '_lcrm_capture_state' ), true ) && strtolower( $row['meta_key'] ) !== $row['meta_key'] ) {
			return error( 'מפתח בירור הפנייה אינו תקין.', 409 );
		}
		if ( ! in_array( $row['meta_key'], array( '_lcrm_capture_case', '_lcrm_capture_state' ), true ) ) {
			continue;
		}
		if ( array_key_exists( $row['meta_key'], $values ) ) {
			return error( 'רישום הבירור אינו חד־משמעי.', 409 );
		}
		$values[ $row['meta_key'] ] = $row['meta_value'];
	}
	if ( ! $values ) {
		return null;
	}
	if ( 2 !== count( $values ) ) {
		return error( 'רישום הבירור אינו שלם.', 409 );
	}
	$item = maybe_unserialize( $values['_lcrm_capture_case'] );
	$base = capture_case_default();
	if (
		! is_array( $item ) || array_diff( array_keys( $item ), array_keys( $base ) ) || array_diff( array_keys( $base ), array_keys( $item ) ) ||
		CAPTURE_CASE_VERSION !== $item['version'] || ! in_array( $item['state'], array( 'retry', 'review', 'closed' ), true ) ||
		$values['_lcrm_capture_state'] !== $item['state'] || ! is_string( $item['reason'] ) || ! isset( capture_case_reasons()[ $item['reason'] ] ) ||
		! is_int( $item['attempts'] ) || $item['attempts'] < 0 || ! is_int( $item['actor'] ) || $item['actor'] < 0 ||
		! is_string( $item['note'] ) || strlen( $item['note'] ) > 1000
	) {
		return error( 'פרטי הבירור אינם תקינים.', 409 );
	}
	foreach ( array( 'last_attempt', 'decision_at', 'closed_at' ) as $key ) {
		if ( '' !== $item[ $key ] && ! capture_retry_date( $item[ $key ] ) ) {
			return error( 'מועד הבירור אינו תקין.', 409 );
		}
	}
	if ( 'closed' === $item['state'] && ( ! $item['actor'] || '' === $item['note'] || '' === $item['closed_at'] ) ) {
		return error( 'החלטת הסגירה אינה שלמה.', 409 );
	}
	return $item;
}

/**
 * Read one case without any object-cache authority.
 *
 * @param int $id Source or inbox ID.
 * @return array|null|\WP_Error
 */
function capture_case_read( $id ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s,%s)", $id, '_lcrm_capture_case', '_lcrm_capture_state' ), ARRAY_A );
	return $wpdb->num_queries > $queries && ! $wpdb->last_error && is_array( $rows ) ? capture_case_parse( $rows ) : error( 'לא ניתן לקרוא את מצב בירור הפנייה.', 503 );
}

/**
 * Check case state again under the financial lock.
 *
 * @param int $id Source or inbox ID.
 * @return bool|\WP_Error
 */
function capture_case_auto( $id ) {
	$item = capture_case_read( $id );
	return is_wp_error( $item ) ? $item : ( null === $item || 'retry' === $item['state'] );
}

/**
 * Fixed SQL for automatic candidates; never parse serialized text with LIKE.
 *
 * @param string $alias Trusted posts alias.
 * @return string
 * @throws \InvalidArgumentException If an untrusted SQL alias is supplied.
 */
function capture_case_auto_sql( $alias = 'p' ) {
	global $wpdb;
	if ( ! preg_match( '/^[a-z][a-z0-9_]*$/D', $alias ) ) {
		throw new \InvalidArgumentException( 'Invalid capture SQL alias.' );
	}
	return "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} capture_state WHERE capture_state.post_id = {$alias}.ID AND capture_state.meta_key = '_lcrm_capture_state' AND capture_state.meta_value IN ('review','closed'))";
}

/**
 * Active totals include manual review, while an explicit closure is archived.
 *
 * @param string $alias Trusted posts alias.
 * @return string
 * @throws \InvalidArgumentException If an untrusted SQL alias is supplied.
 */
function capture_case_pending_sql( $alias = 'p' ) {
	global $wpdb;
	if ( ! preg_match( '/^[a-z][a-z0-9_]*$/D', $alias ) ) {
		throw new \InvalidArgumentException( 'Invalid capture SQL alias.' );
	}
	return "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} capture_state WHERE capture_state.post_id = {$alias}.ID AND capture_state.meta_key = '_lcrm_capture_state' AND capture_state.meta_value = 'closed')";
}

/**
 * Refuse structurally damaged state indexes before they can hide candidates.
 * Case details are still parsed authoritatively before every financial attempt.
 *
 * @return true|\WP_Error
 */
function capture_case_state_guard() {
	global $wpdb;
	$predicate = capture_case_sources_sql();
	$queries   = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The predicate and metadata keys are fixed internal SQL without request values.
	$invalid = $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} case_meta ON case_meta.post_id = p.ID AND case_meta.meta_key IN ('_lcrm_capture_case','_lcrm_capture_state') WHERE {$predicate} GROUP BY p.ID HAVING COUNT(*) <> 2 OR SUM(BINARY case_meta.meta_key = '_lcrm_capture_case') <> 1 OR SUM(BINARY case_meta.meta_key = '_lcrm_capture_state') <> 1 OR SUM(BINARY case_meta.meta_key = '_lcrm_capture_state' AND BINARY case_meta.meta_value NOT IN ('retry','review','closed')) > 0 LIMIT 1" );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error ) {
		return error( 'לא ניתן לבדוק את מצבי בירור הפניות.', 503 );
	}
	return null === $invalid ? true : error( 'מצב בירור של פנייה אינו תקין. הספירה והסריקה נעצרו לבדיקה.', 409 );
}

/**
 * Save the state and details atomically inside the existing mutation transaction.
 *
 * @param int   $id Source ID.
 * @param array $item Case.
 * @return array|\WP_Error
 */
function capture_case_write( $id, $item ) {
	global $wpdb;
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) ) {
		return error( 'שמירת בירור מחייבת פעולה משותפת.', 503 );
	}
	$check = capture_case_parse(
		array(
			array(
				'meta_key'   => '_lcrm_capture_case',
				'meta_value' => maybe_serialize( $item ),
			),
			array(
				'meta_key'   => '_lcrm_capture_state',
				'meta_value' => $item['state'] ?? '',
			),
		)
	);
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$old = capture_case_read( $id );
	if ( is_wp_error( $old ) ) {
		return $old;
	}
	if ( $old === $item ) {
		return $item;
	}
	$GLOBALS['lcrm_changed_records'][] = $id;
	foreach ( array(
		'_lcrm_capture_case'  => maybe_serialize( $item ),
		'_lcrm_capture_state' => $item['state'],
	) as $key => $value ) {
		$deleted = $wpdb->delete(
			$wpdb->postmeta,
			array(
				'post_id'  => $id,
				'meta_key' => $key,
			),
			array( '%d', '%s' )
		);
		$saved   = false !== $deleted ? $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => $key,
				'meta_value' => $value,
			),
			array( '%d', '%s', '%s' )
		) : false;
		if ( false === $saved ) {
			return error( 'שמירת בירור הפנייה נכשלה.', 503 );
		}
	}
	wp_cache_delete( $id, 'post_meta' );
	return capture_case_read( $id ) === $item ? $item : error( 'לא ניתן לאמת את שמירת הבירור.', 503 );
}

/**
 * Record a failed attempt after financial rollback, with no extra foreground wait.
 *
 * @param int   $id Source ID.
 * @param mixed $result Attempt result.
 * @return array|null|\WP_Error
 */
function capture_case_note( $id, $result ) {
	if ( ! is_wp_error( $result ) ) {
		return null;
	}
	return locked(
		static function () use ( $id, $result ) {
			$record = capture_case_source_rows( array( $id ) );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			if ( ! $record ) {
				return null;
			}
			$old = capture_case_read( $id );
			if ( is_wp_error( $old ) || ( is_array( $old ) && in_array( $old['state'], array( 'closed', 'review' ), true ) ) ) {
				return $old;
			}
			$details = $result->get_error_data();
			$reason  = is_array( $details ) && isset( $details['capture_reason'] ) ? $details['capture_reason'] : 'temporary';
			if ( is_array( $details ) && ! empty( $details['lock_busy'] ) ) {
				$reason = 'temporary';
			} elseif ( ! is_string( $reason ) || ! isset( capture_case_reasons()[ $reason ] ) || ( 'temporary' === $reason && ! isset( $details['capture_reason'] ) ) ) {
				$status = is_array( $details ) ? ( $details['status'] ?? 500 ) : 500;
				$reason = is_int( $status ) && $status >= 400 && $status < 500 ? 'invalid_payload' : 'temporary';
			}
			$item                 = $old ?? capture_case_default();
			$item['state']        = in_array( $reason, array( 'approved_month', 'identity_conflict', 'invalid_payload' ), true ) ? 'review' : 'retry';
			$item['reason']       = $reason;
			$item['last_attempt'] = current_time( 'mysql' );
			$item['attempts']     = min( PHP_INT_MAX - 1, $item['attempts'] ) + 1;
			return capture_case_write( $id, $item );
		},
		0
	);
}

/**
 * Base predicate for original sources and private accepted-dispatch inbox rows.
 *
 * @param string $alias Trusted alias.
 * @return string
 * @throws \InvalidArgumentException If an untrusted SQL alias is supplied.
 */
function capture_case_sources_sql( $alias = 'p' ) {
	global $wpdb;
	if ( ! preg_match( '/^[a-z][a-z0-9_]*$/D', $alias ) ) {
		throw new \InvalidArgumentException( 'Invalid capture SQL alias.' );
	}
	return "(({$alias}.post_type = 'leads' AND {$alias}.post_status = 'publish' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} marker WHERE marker.post_id = {$alias}.ID AND marker.meta_key = '_lcrm_capture_pending')) OR ({$alias}.post_type = 'lcrm_capture' AND {$alias}.post_status = 'private'))";
}

/**
 * Fetch a bounded source page, never full native post content.
 *
 * @param array $ids Source IDs.
 * @return array|\WP_Error
 */
function capture_case_source_rows( $ids ) {
	global $wpdb;
	if ( ! $ids || count( $ids ) > 100 ) {
		return array();
	}
	foreach ( $ids as $id ) {
		if ( ! is_int( $id ) || $id < 1 ) {
			return error( 'מזהה בירור לא תקין.' );
		}
	}
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$predicate    = capture_case_sources_sql();
	$queries      = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed predicates and validated numeric placeholders are prepared below.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_type, p.post_date, p.post_excerpt, CASE WHEN p.post_type = 'lcrm_capture' THEN LEFT(p.post_content,32769) ELSE '' END AS capture_content FROM {$wpdb->posts} p WHERE {$predicate} AND p.ID IN ({$placeholders}) ORDER BY p.ID DESC", $ids ), ARRAY_A );
	return $wpdb->num_queries > $queries && ! $wpdb->last_error && is_array( $rows ) ? $rows : error( 'לא ניתן לקרוא את הפניות לבירור.', 503 );
}

/**
 * Load all private case and source markers for one page in a single query.
 *
 * @param array $ids IDs.
 * @return array|\WP_Error
 */
function capture_case_metadata( $ids ) {
	global $wpdb;
	if ( ! $ids ) {
		return array();
	}
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$queries      = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Numeric placeholders are prepared; metadata keys are fixed internal strings.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders}) AND meta_key IN ('_lcrm_capture_case','_lcrm_capture_state','_lcrm_capture_pending')", $ids ), ARRAY_A );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) ) {
		return error( 'לא ניתן לקרוא את פרטי בירור הפניות.', 503 );
	}
	$grouped = array();
	foreach ( $rows as $row ) {
		$grouped[ (int) $row['post_id'] ][] = $row;
	}
	return $grouped;
}

/**
 * Shape one manager-only row from already batched snapshots.
 *
 * @param array $source Source row.
 * @param array $meta Metadata rows.
 * @return array|\WP_Error
 */
function capture_case_public_row( $source, $meta ) {
	$case = capture_case_parse( $meta );
	if ( is_wp_error( $case ) ) {
		return $case;
	}
	$case    = $case ?? capture_case_default();
	$native  = 'leads' === $source['post_type'];
	$payload = null;
	if ( $native ) {
		$markers = array_values(
			array_filter(
				$meta,
				static function ( $row ) {
					return '_lcrm_capture_pending' === $row['meta_key'];
				}
			)
		);
		$marker  = 1 === count( $markers ) ? maybe_unserialize( $markers[0]['meta_value'] ) : null;
		if ( capture_retry_native_valid( $marker ) ) {
			$payload = array(
				'date'         => $marker['date'],
				'lead'         => $marker['snapshot'] ?? array(),
				'institution'  => isset( $marker['destinations'] ) && 1 === count( $marker['destinations'] ) ? $marker['destinations'][0] : 0,
				'institutions' => $marker['destinations'] ?? array(),
			);
		}
	} else {
		$decoded = strlen( $source['capture_content'] ) <= 32768 ? json_decode( $source['capture_content'], true, 10 ) : null;
		if ( capture_retry_ajax_valid( $decoded ) && $decoded['source'] === $source['post_excerpt'] ) {
			$payload = $decoded;
		}
	}
	if ( null === $payload ) {
		$case['reason'] = 'invalid_payload';
	}
	return array(
		'id'           => (int) $source['ID'],
		'date'         => $payload['date'] ?? $source['post_date'],
		'name'         => $payload['lead']['name'] ?? '',
		'phone'        => $payload['lead']['phone'] ?? '',
		'email'        => $payload['lead']['email'] ?? '',
		'source_kind'  => $native ? 'native' : 'ajax',
		'institution'  => $payload['institution'] ?? 0,
		'institutions' => $payload['institutions'] ?? ( ! empty( $payload['institution'] ) ? array( $payload['institution'] ) : array() ),
		'reason'       => $case['reason'],
		'reason_label' => capture_case_reasons()[ $case['reason'] ],
		'state'        => $case['state'],
		'last_attempt' => $case['last_attempt'],
		'attempts'     => $case['attempts'],
		'closed_at'    => $case['closed_at'],
		'note'         => $case['note'],
	);
}

/**
 * Manager-only single-row view after a decision.
 *
 * @param int $id Source ID.
 * @return array|null|\WP_Error
 */
function capture_case_item( $id ) {
	$rows = capture_case_source_rows( array( $id ) );
	if ( is_wp_error( $rows ) || ! $rows ) {
		return is_wp_error( $rows ) ? $rows : null;
	}
	$meta = capture_case_metadata( array( $id ) );
	return is_wp_error( $meta ) ? $meta : capture_case_public_row( $rows[0], $meta[ $id ] ?? array() );
}

/**
 * Read a bounded manager-only case list with batched source metadata.
 *
 * @param \WP_REST_Request $request Request.
 * @return array|\WP_Error
 */
function capture_case_list( $request ) {
	global $wpdb;
	if ( ! manager() ) {
		return error( 'אין הרשאה.', 403 );
	}
	$integrity = capture_case_state_guard();
	if ( is_wp_error( $integrity ) ) {
		return $integrity;
	}
	$state = $request->get_param( 'state' ) ?? 'active';
	$page  = $request->get_param( 'page' ) ?? '1';
	if ( ! is_string( $state ) || ! in_array( $state, array( 'active', 'retry', 'review', 'closed' ), true ) || ( ! is_string( $page ) && ! is_int( $page ) ) || ! preg_match( '/^[1-9]\d{0,6}$/D', (string) $page ) ) {
		return error( 'סינון בירורים לא תקין.' );
	}
	foreach ( array( 'institution', 'month', 'year', 'date_from', 'date_to', 'search', 'billing_status' ) as $key ) {
		if ( null !== $request->get_param( $key ) && '' !== $request->get_param( $key ) ) {
			return error( 'רשימת הבירורים מציגה את כל המערכת. יש לסנן לפי מצב בירור בלבד.' );
		}
	}
	$where = capture_case_sources_sql();
	if ( 'active' === $state ) {
		$where .= ' AND ' . capture_case_pending_sql();
	} elseif ( 'retry' === $state ) {
		$where .= ' AND ' . capture_case_auto_sql();
	} else {
		$where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} capture_state WHERE capture_state.post_id = p.ID AND capture_state.meta_key = %s AND capture_state.meta_value = %s)", '_lcrm_capture_state', $state );
	}
	$queries = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The predicate contains only fixed SQL and the prepared exact state above.
	$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_scalar( $total ) || ! ctype_digit( (string) $total ) ) {
		return error( 'לא ניתן לקרוא את מספר הבירורים.', 503 );
	}
	$queries = $wpdb->num_queries;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The fixed/prepared predicate is combined with a prepared bounded numeric offset.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.ID DESC LIMIT 30 OFFSET %d", ( (int) $page - 1 ) * 30 ) );
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $ids ) ) {
		return error( 'לא ניתן לקרוא את רשימת הבירורים.', 503 );
	}
	$ids  = array_map( 'intval', $ids );
	$rows = capture_case_source_rows( $ids );
	$meta = capture_case_metadata( $ids );
	if ( is_wp_error( $rows ) || is_wp_error( $meta ) ) {
		return is_wp_error( $rows ) ? $rows : $meta;
	}
	$items = array();
	foreach ( $rows as $row ) {
		$item = capture_case_public_row( $row, $meta[ (int) $row['ID'] ] ?? array() );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$items[] = $item;
	}
	return array(
		'items' => $items,
		'total' => (int) $total,
		'pages' => (int) ceil( (int) $total / 30 ),
		'page'  => (int) $page,
	);
}

/**
 * Apply a manager decision without calling capture from inside its transaction.
 *
 * @param int    $id Source ID.
 * @param string $action Decision.
 * @param string $note Private note.
 * @return array|\WP_Error
 */
function capture_case_decide( $id, $action, $note ) {
	$source = capture_case_source_rows( array( $id ) );
	if ( is_wp_error( $source ) || ! $source ) {
		return is_wp_error( $source ) ? $source : error( 'הפנייה לבירור לא נמצאה.', 404 );
	}
	$old = capture_case_read( $id );
	if ( is_wp_error( $old ) ) {
		return $old;
	}
	$item = $old ?? capture_case_default();
	if ( 'close' === $action && 'closed' === $item['state'] ) {
		return $item;
	}
	if ( ( 'retry' === $action && 'closed' === $item['state'] ) || ( 'reopen' === $action && 'closed' !== $item['state'] ) ) {
		return error( 'מצב הבירור השתנה. יש לרענן ולבחור את הפעולה המתאימה.', 409 );
	}
	$item['state']       = 'close' === $action ? 'closed' : 'retry';
	$item['actor']       = get_current_user_id();
	$item['decision_at'] = current_time( 'mysql' );
	if ( 'close' === $action ) {
		$item['closed_at'] = $item['decision_at'];
		$item['note']      = $note;
	} else {
		$item['reason'] = 'manual_retry';
		if ( '' !== $note ) {
			$item['note'] = $note;
		}
	}
	$saved = capture_case_write( $id, $item );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$events = array(
		'close'  => 'capture_case_closed',
		'retry'  => 'capture_case_retried',
		'reopen' => 'capture_case_reopened',
	);
	audit(
		$events[ $action ],
		$id,
		array(
			'reason' => $item['reason'],
			'state'  => $item['state'],
		)
	);
	return $item;
}

/**
 * Manager request wrapper: capture itself runs only after the decision lock is released.
 *
 * @param \WP_REST_Request $request Request.
 * @return array|\WP_Error
 */
function capture_case_action( $request ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה.', 403 );
	}
	if ( strlen( $request->get_body() ) > 8192 ) {
		return error( 'הבקשה גדולה מדי.', 413 );
	}
	$input = $request->get_json_params();
	if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'id', 'action', 'note' ) ) || ! isset( $input['id'], $input['action'] ) || ( ! is_int( $input['id'] ) && ! is_string( $input['id'] ) ) || ! preg_match( '/^[1-9]\d{0,9}$/D', (string) $input['id'] ) || ! in_array( $input['action'], array( 'retry', 'close', 'reopen' ), true ) || ( array_key_exists( 'note', $input ) && ( ! is_string( $input['note'] ) || strlen( $input['note'] ) > 1000 ) ) ) {
		return error( 'פעולת בירור לא תקינה.' );
	}
	$id     = (int) $input['id'];
	$action = $input['action'];
	$note   = isset( $input['note'] ) ? trim( sanitize_textarea_field( $input['note'] ) ) : '';
	if ( 'close' === $action && '' === $note ) {
		return error( 'יש לתעד את הטיפול הידני לפני סגירת הבירור.' );
	}
	$decision = locked(
		static function () use ( $id, $action, $note ) {
			return capture_case_decide( $id, $action, $note );
		}
	);
	if ( is_wp_error( $decision ) ) {
		return $decision;
	}
	$result = null;
	if ( 'close' !== $action ) {
		$source = capture_case_source_rows( array( $id ) );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( $source && 'leads' === $source[0]['post_type'] ) {
			$marker = capture_retry_native_read( $id );
			if ( is_wp_error( $marker ) ) {
				return $marker;
			}
			if ( is_array( $marker ) && ( isset( $marker['snapshot'] ) || $marker['created_at'] <= time() - 60 ) ) {
				$result = capture_retry_native_attempt( $id, 0 );
			}
		} elseif ( $source ) {
			$result = capture_retry_ajax_attempt( $id, 0 );
		}
	}
	$item = capture_case_item( $id );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	if ( null === $item && ( 'close' === $action || ! is_array( $result ) || is_wp_error( $result ) ) ) {
		return error( 'מצב הפנייה השתנה בזמן הפעולה. יש לרענן את רשימת הבירורים.', 409 );
	}
	$unmapped = ! is_wp_error( $result ) && is_array( $result ) && isset( $result['id'], $result['state'] ) && 'unmapped' === $result['state'] ? (int) $result['id'] : 0;
	return array(
		'item'        => $item,
		'completed'   => null === $item && 'close' !== $action,
		'unmapped_id' => $unmapped,
	);
}
