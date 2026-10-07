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
		'duplicate_mode' => 'calendar',
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
 * All mutations share a database advisory lock, including parallel REST and cron workers.
 *
 * @param callable $callback Mutation executed under lock or savepoint.
 * @return mixed Operation result or validation error.
 */
function locked( $callback ) {
	global $wpdb;
	if ( isset( $GLOBALS['lcrm_changed_records'] ) ) {
		return error( 'לא ניתן להתחיל פעולה מקבילה בתוך פעולה קיימת.', 409 );
	}
	$name = 'lcrm_' . md5( DB_NAME . ':' . $wpdb->prefix );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ) ) {
		return error( 'המערכת עסוקה. נסו שוב בעוד רגע.', 409 );
	}
	try {
		$tables = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s, %s, %s)', DB_NAME, $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->usermeta ), ARRAY_A );
		if ( 4 !== count( $tables ) || array_filter( $tables, static fn( $table ) => 'INNODB' !== strtoupper( $table['ENGINE'] ) ) ) {
			return error( 'שמירה מאובטחת דורשת טבלאות InnoDB. יש לפנות למנהל האתר.', 503 );
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
		}
		return $result;
	} catch ( \Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		rollback_caches();
		return error( 'הפעולה לא נשמרה. יש לנסות שוב.', 500 );
	} finally {
		unset( $GLOBALS['lcrm_changed_records'], $GLOBALS['lcrm_changed_users'] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
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
	$post = array(
		'post_type'   => $type,
		'post_status' => 'private',
		'post_title'  => 'lcrm_delivery' === $type ? ( $payload['name'] ?? 'פנייה' ) : $type,
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
	foreach ( array( 'institution', 'month', 'state', 'source', 'date', 'bill' ) as $key ) {
		if ( isset( $payload[ $key ] ) ) {
			update_post_meta( $saved, '_lcrm_' . $key, wp_slash( $payload[ $key ] ) );
			if ( (string) get_post_meta( $saved, '_lcrm_' . $key, true ) !== (string) $payload[ $key ] ) {
				return error( 'שמירת אינדקס הרשומה נכשלה.', 500 );
			}
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
			'post_type'      => $type,
			'post_status'    => 'private',
			'posts_per_page' => min( 5000, max( 1, $limit ) ),
			'paged'          => max( 1, $page ),
			's'              => $search,
			'meta_query'     => $meta,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			// Summary callers retain the first page's total; later batches do not need another count query.
			'no_found_rows'  => ! $hydrate && $page > 1,
		)
	);
	if ( $hydrate ) {
		prime_contacts( wp_list_pluck( $q->posts, 'ID' ) );
	}
	return array(
		'items' => array_map(
			function ( $post ) use ( $hydrate ) {
				if ( $hydrate ) {
					return data( $post->ID );
				}
				$item = get_post_meta( $post->ID, '_lcrm_data', true );
				return is_array( $item ) ? $item : array();
			},
			$q->posts
		),
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
 * @return mixed Operation result or validation error.
 */
function institution_deliveries( $id, $before = null, $after = null ) {
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
	$ids = get_posts(
		array(
			'post_type'      => 'lcrm_delivery',
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	update_meta_cache( 'post', $ids );
	prime_contacts( $ids );
	$items = array_map( __NAMESPACE__ . '\\data', $ids );
	usort(
		$items,
		function ( $a, $b ) {
			return array( $a['date'], $a['id'] ) <=> array( $b['date'], $b['id'] );
		}
	);
	return $items;
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
	$existing          = get_posts(
		array(
			'post_type'      => 'lcrm_delivery',
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_lcrm_source',
					'value' => $source,
				),
				array(
					'key'   => '_lcrm_institution',
					'value' => $institution,
					'type'  => 'NUMERIC',
				),
			),
		)
	);
	if ( $existing ) {
		$item = data( $existing[0] );
		if ( ! $historical && ( $item['phone_key'] !== $lead['phone_key'] || $item['email_key'] !== $lead['email_key'] ) ) {
			return error( 'מזהה המסירה כבר קיים עם פרטי קשר אחרים.', 409 );
		}
		return $item;
	}
	if ( ! $historical ) {
		$existing_bill = bill_for( $institution, substr( $lead['date'], 0, 7 ) );
		if ( $existing_bill && 'approved' === $existing_bill['state'] ) {
			return error( 'התקופה כבר אושרה. נדרשת התאמה כספית נפרדת.', 409 );
		}
	}
	if ( ! $lead['phone_key'] && ! $lead['email_key'] && ! $historical ) {
		return error( 'נדרש טלפון או אימייל תקין.' );
	}
	$contact_id = ! empty( $lead['contact'] ) ? absint( $lead['contact'] ) : 0;
	if ( ! $contact_id && ! empty( $lead['legacy_id'] ) && 'leads' === get_post_type( $lead['legacy_id'] ) ) {
		$contact_id = absint( $lead['legacy_id'] );
	}
	if ( ! $contact_id ) {
		$contacts = get_posts(
			array(
				'post_type'      => 'lcrm_contact',
				'post_status'    => 'private',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_lcrm_source',
				'meta_value'     => $source,
			)
		);
		if ( $contacts ) {
			$contact_id           = $contacts[0];
			$contact              = data( $contact_id );
			$contact['phone_key'] = normalize_phone( $contact['phone'] );
			$contact['email_key'] = normalize_email( $contact['email'] );
			if ( ! $historical && ( $lead['phone_key'] !== $contact['phone_key'] || $lead['email_key'] !== $contact['email_key'] ) ) {
				return error( 'מזהה הפנייה כבר קיים עם פרטי קשר אחרים.', 409 );
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
	$s               = settings();
	$duplicate       = 0;
	$policy          = null === $saved_policy ? duplicate_policy( $s ) : $saved_policy;
	$start           = duplicate_window_start( $lead['date'], $policy );
	foreach ( institution_deliveries( $institution, $lead['date'], $start ) as $previous ) {
		if ( ! empty( $previous['duplicate_of'] ) || ( ! $historical && 'sent' !== $previous['state'] ) ) {
			continue;
		}
		if ( same_contact( $lead, $previous ) && in_window( $previous['date'], $lead['date'], $policy ) ) {
			$duplicate = $previous['id'];
			break;
		}
	}
	$item   = array_merge(
		$lead,
		array(
			'treatment'      => 'new',
			'notes'          => array(),
			'institution'    => $institution,
			'source'         => $source,
			'month'          => substr( $lead['date'], 0, 7 ),
			'state'          => $historical ? 'historical' : 'sent',
			'duplicate_of'   => $duplicate,
			'duplicate_mode' => $policy,
			'bill'           => 0,
		)
	);
	$result = save_record( 'lcrm_delivery', $item );
	return is_wp_error( $result ) || $historical ? $result : reconcile_duplicates( $result );
}
/**
 * Find the unique institution and service-month bill.
 *
 * @param int    $id WordPress object ID.
 * @param string $month Completed service month in YYYY-MM format.
 * @return mixed Operation result or validation error.
 */
function bill_for( $id, $month ) {
	$ids = get_posts(
		array(
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
	return $ids ? data( $ids[0] ) : null;
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
	$items      = institution_deliveries( $id, $end, $first );
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
	$bill = data( $id );
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
	foreach ( $bill['lines'] as $line ) {
		$item         = data( $line['delivery'] );
		$item['bill'] = $id;
		$saved        = save_record( 'lcrm_delivery', $item, $item['id'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
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
 */
function record_payment( $bill_id, $input ) {
	if ( 'lcrm_bill' !== get_post_type( $bill_id ) ) {
		return error( 'חיוב לא נמצא.', 404 );
	}
	$bill = data( $bill_id );
	if ( 'approved' !== $bill['state'] ) {
		return error( 'יש לאשר את החיוב לפני תשלום.' );
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
	$payments = get_posts(
		array(
			'post_type'      => 'lcrm_payment',
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_lcrm_bill',
			'meta_value'     => $bill_id,
		)
	);
	$paid     = 0;
	foreach ( $payments as $pid ) {
		$p = data( $pid );
		if ( $p['request_key'] === $key ) {
			if ( $p['amount'] !== $amount || $p['date'] !== $date || $p['method'] !== $method || $p['reference'] !== $reference ) {
				return error( 'הניסיון הקודם כבר נשמר עם פרטי תשלום אחרים. יש לרענן ולבדוק את היתרה.', 409 );
			}
			return $p;
		} $paid += $p['amount'];
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
	foreach ( array( 'lcrm_settings', 'lcrm_icount_config', 'lcrm_icount_automatic', 'lcrm_icount_namespace' ) as $option ) {
		wp_cache_delete( $option, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}
