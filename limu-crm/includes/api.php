<?php
/**
 * Authenticated REST endpoints and scoped report exports.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.SlowDBQuery -- Bounded institution and month metadata joins preserve existing WordPress storage.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Cookie-authenticated REST requests require a current nonce; institutions have no write endpoints.
 *
 * @param \WP_REST_Request $request Authenticated REST request.
 * @return mixed Operation result or validation error.
 */
function permission( $request ) {
	if ( ! can_view() ) {
		return error( 'אין הרשאה.', 403 );
	}
	if ( 'GET' !== $request->get_method() ) {
		if ( ! manager() ) {
			return error( 'צפייה בלבד.', 403 );
		}
		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return error( 'יש להתחבר מחדש.', 403 );
		}
	}
	return true;
}
add_action(
	'rest_api_init',
	function () {
		foreach ( array( 'bootstrap', 'summary', 'deliveries', 'bills', 'payments', 'audit', 'export', 'report' ) as $route ) {
			register_rest_route(
				'limu-crm/v1',
				'/' . $route,
				array(
					'methods'             => 'GET',
					'permission_callback' => __NAMESPACE__ . '\\permission',
					'callback'            => __NAMESPACE__ . '\\read_api',
				)
			);
		}
		foreach ( array( 'settings', 'agreement', 'member', 'prepare', 'approve', 'payment', 'import', 'treatment', 'remap' ) as $route ) {
			register_rest_route(
				'limu-crm/v1',
				'/' . $route,
				array(
					'methods'             => 'POST',
					'permission_callback' => __NAMESPACE__ . '\\permission',
					'callback'            => function ( $request ) {
						$result = locked(
							function () use ( $request ) {
								return write_api( $request );
							}
						);
						if ( ! is_wp_error( $result ) && isset( $result['newly_approved'] ) ) {
							icount_queue_demands( $result['newly_approved'] );
							unset( $result['newly_approved'] );
						}
						return $result;
					},
				)
			);
		}
	}
);
/**
 * Return institutions permitted for the current user; agreements are manager-only.
 *
 * @return mixed Operation result.
 */
function institution_list() {
	$args = array(
		'post_type'      => 'institutions',
		'post_status'    => array( 'publish', 'draft' ),
		'posts_per_page' => 100,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	);
	if ( ! manager() ) {
		$ids = allowed_institutions();
		if ( ! $ids ) {
			return array();
		} $args['post__in'] = $ids;
	}
	return array_map(
		function ( $post ) {
			$item = array(
				'id'   => $post->ID,
				'name' => get_the_title( $post->ID ),
			);
			if ( manager() ) {
				$item['agreement']     = agreement( $post->ID );
				$item['icount_client'] = icount_client( $post->ID );
			}
			return $item;
		},
		get_posts( $args )
	);
}
/**
 * Remove internal and identifying lookup fields before returning a record.
 *
 * @param array $item Private record payload.
 * @return mixed Operation result or validation error.
 */
function public_item( $item ) {
	if ( isset( $item['id'] ) && in_array( get_post_type( $item['id'] ), array( 'lcrm_bill', 'lcrm_payment' ), true ) ) {
		$item['documents'] = icount_documents( $item['id'] );
		if ( ! manager() ) {
			$item['documents'] = array_values( array_filter( $item['documents'], static fn( $document ) => 'issued' === $document['state'] ) );
			foreach ( $item['documents'] as &$document ) {
				unset( $document['key'], $document['target'] );
			}
			unset( $document );
		}
	}
	unset( $item['phone_key'], $item['email_key'], $item['request_key'] );
	if ( ! manager() ) {
		$item['count'] = isset( $item['lines'] ) ? count( $item['lines'] ) : 0;
		unset( $item['source'], $item['actor'], $item['approved_by'], $item['lines'], $item['notes'], $item['evidence'], $item['confirmed_by'], $item['contact'], $item['legacy_id'], $item['origin_live'], $item['capture_via'], $item['capture_error'] );
		if ( isset( $item['duplicate_of'] ) ) {
			$item['duplicate_of'] = (bool) $item['duplicate_of'];
		}
	}
	return $item;
}
/**
 * Validate one reporting period shared by lists, summaries and exports.
 *
 * @param \WP_REST_Request $request Authenticated REST request.
 * @return array|\WP_Error Validated period filters or a safe validation error.
 */
function period_filters( $request ) {
	$filters = array();
	foreach ( array( 'month', 'year', 'date_from', 'date_to' ) as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value && '' !== $value ) {
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				return error( 'תקופה לא תקינה.' );
			}
			$filters[ $key ] = (string) $value;
		}
	}
	if ( isset( $filters['month'] ) && ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/D', $filters['month'] ) ) {
		return error( 'חודש לא תקין.' );
	}
	if ( isset( $filters['year'] ) && ( ! preg_match( '/^\d{4}$/D', $filters['year'] ) || (int) $filters['year'] < 1900 ) ) {
		return error( 'שנה לא תקינה.' );
	}
	if ( isset( $filters['month'], $filters['year'] ) ) {
		return error( 'יש לבחור חודש או שנה, ולא את שניהם יחד.' );
	}
	if ( isset( $filters['date_from'] ) || isset( $filters['date_to'] ) ) {
		if ( ! isset( $filters['date_from'], $filters['date_to'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $filters['date_from'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $filters['date_to'] ) || ! valid_date( $filters['date_from'] ) || ! valid_date( $filters['date_to'] ) || $filters['date_from'] > $filters['date_to'] ) {
			return error( 'יש לבחור תאריך התחלה וסיום תקינים, לפי הסדר.' );
		}
		if ( isset( $filters['month'] ) || isset( $filters['year'] ) ) {
			return error( 'יש לבחור טווח תאריכים, חודש או שנה.' );
		}
	}
	return $filters;
}
/**
 * Serve scoped reads and authorized exports.
 *
 * @param \WP_REST_Request $request Authenticated REST request.
 * @return mixed Operation result or validation error.
 */
function read_api( $request ) {
	foreach ( array( 'target', 'institution', 'month', 'year', 'date_from', 'date_to', 'state', 'page', 'search' ) as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value && ! is_scalar( $value ) ) {
			return error( 'פרמטר לא תקין.' );
		}
		$limits = array(
			'target'      => 20,
			'institution' => 10,
			'month'       => 7,
			'year'        => 4,
			'date_from'   => 10,
			'date_to'     => 10,
			'state'       => 30,
			'page'        => 7,
			'search'      => 200,
		);
		if ( null !== $value && ( is_bool( $value ) || strlen( (string) $value ) > $limits[ $key ] ) ) {
			return error( 'פרמטר לא תקין.' );
		}
		if ( in_array( $key, array( 'institution', 'page' ), true ) && null !== $value && '' !== $value && ! preg_match( '/^\d+$/D', (string) $value ) ) {
			return error( 'מזהה או עמוד לא תקין.' );
		}
	}
	$period = period_filters( $request );
	if ( is_wp_error( $period ) ) {
		return $period;
	}
	$route = basename( $request->get_route() );
	if ( 'bootstrap' === $route ) {
		// Older cached clients expect this field; never expose or restore retired form mappings.
		$manager_settings = manager() ? array_merge( settings(), array( 'forms' => array() ) ) : null;
		return array(
			'manager'      => manager(),
			'name'         => wp_get_current_user()->display_name,
			'institutions' => institution_list(),
			'settings'     => $manager_settings,
			'icount'       => manager() ? icount_status() : null,
			'today'        => current_time( 'Y-m-d' ),
			'month'        => current_time( 'Y-m' ),
		);
	}
	if ( 'summary' === $route ) {
		return summary( $request );
	}
	if ( 'audit' === $route && ! manager() ) {
		return error( 'אין הרשאה.', 403 );
	}
	$types  = array(
		'deliveries' => 'lcrm_delivery',
		'bills'      => 'lcrm_bill',
		'payments'   => 'lcrm_payment',
		'audit'      => 'lcrm_audit',
	);
	$target = in_array( $route, array( 'export', 'report' ), true ) ? sanitize_key( $request->get_param( 'target' ) ?? 'deliveries' ) : $route;
	if ( ! isset( $types[ $target ] ) || ( 'audit' === $target && ! manager() ) ) {
		return error( 'דוח לא תקין.' );
	}
	$filters = $period;
	$id      = absint( $request->get_param( 'institution' ) );
	if ( $id ) {
		if ( ! owns( $id ) ) {
			return error( 'אין הרשאה למוסד.', 403 );
		} $filters['institution'] = $id;
	}
	$state = $request->get_param( 'state' );
	if ( null !== $state && '' !== $state ) {
		$filters['state'] = sanitize_text_field( $state );
	}
	$result = query_records( $types[ $target ], $filters, absint( $request->get_param( 'page' ) ), in_array( $route, array( 'export', 'report' ), true ) ? 5000 : 30, sanitize_text_field( $request->get_param( 'search' ) ?? '' ) );
	if ( 'report' === $route ) {
		if ( ! in_array( $target, array( 'deliveries', 'bills' ), true ) || $result['total'] > 5000 ) {
			return error( 'יש לצמצם את הדוח לפי מוסד או תקופה.' );
		} audit(
			'export',
			0,
			array(
				'target' => $target,
				'format' => 'print',
				'count'  => $result['total'],
			)
		);
	}
	if ( 'export' === $route ) {
		if ( $result['total'] > 5000 ) {
			return error( 'הדוח גדול מדי. יש לסנן לפי מוסד או תקופה.' );
		} return export_xlsx( $target, $result );
	}
	$result['items'] = array_map(
		function ( $item ) {
			return public_item( $item );
		},
		$result['items']
	);
	return $result;
}
/**
 * Validate and execute manager mutations under the database lock.
 *
 * @param \WP_REST_Request $request Authenticated REST request.
 * @return mixed Operation result or validation error.
 */
function write_api( $request ) {
	if ( strlen( $request->get_body() ) > 131072 ) {
		return error( 'הבקשה גדולה מדי.', 413 );
	}
	$input                  = $request->get_json_params();
	$legacy_settings_client = is_array( $input ) && array_key_exists( 'forms', $input );
	if ( ! is_array( $input ) ) {
		return error( 'בקשה לא תקינה.' );
	}
	$route = basename( $request->get_route() );
	foreach ( $input as $key => $value ) {
		if ( 'forms' === $key ) {
			// Accept only the empty JSON array sent by older settings clients; do not persist it.
			$legacy_input = json_decode( $request->get_body() );
			if ( 'settings' !== $route || array() !== $value || ! is_object( $legacy_input ) || ! isset( $legacy_input->forms ) || ! is_array( $legacy_input->forms ) ) {
				return error( 'שדה לא תקין.' );
			}
			continue;
		}
		if ( is_string( $value ) && strlen( $value ) > 4096 ) {
			return error( 'שדה ארוך מדי.' );
		}
		if ( 'automatic' === $key && ! is_bool( $value ) ) {
			return error( 'הגדרת אוטומציה לא תקינה.' );
		}
		if ( in_array( $key, array( 'institution', 'bill', 'id', 'after' ), true ) && ( ! is_scalar( $value ) || is_bool( $value ) || ! preg_match( '/^\d{1,10}$/D', (string) $value ) ) ) {
			return error( 'מזהה לא תקין.' );
		}
		if ( in_array( $key, array( 'institutions', 'ids' ), true ) ) {
			if ( ! is_array( $value ) || count( $value ) > 100 ) {
				return error( 'רשימת נתונים לא תקינה.' );
			}
			foreach ( $value as $member ) {
				if ( ! is_scalar( $member ) || is_bool( $member ) || ! preg_match( '/^\d{1,10}$/D', (string) $member ) ) {
					return error( 'מזהה לא תקין.' );
				}
			}
		} elseif ( ! is_scalar( $value ) && null !== $value ) {
			return error( 'שדה לא תקין.' );
		}
	}
	if ( 'settings' === $route ) {
		$mode  = $input['duplicate_mode'] ?? '';
		$start = $input['start_date'] ?? '';
		if ( ! in_array( $mode, array( 'calendar', 'rolling', 'days_30', 'days_90', 'days_180', 'months_24', 'custom' ), true ) || ( '' !== $start && ! valid_date( $start ) ) ) {
			return error( 'הגדרות לא תקינות.' );
		}
		$old = settings();
		if ( ! empty( $legacy_settings_client ) && ! in_array( $old['duplicate_mode'], array( 'calendar', 'rolling' ), true ) && $mode !== $old['duplicate_mode'] ) {
			return error( 'יש לרענן את העמוד לפני שמירת הגדרות הכפילות.', 409 );
		}
		if ( 'custom' === $mode && ! array_key_exists( 'duplicate_days', $input ) ) {
			return error( 'יש לציין את מספר הימים למניעת כפילות.' );
		}
		$days = array_key_exists( 'duplicate_days', $input ) ? $input['duplicate_days'] : ( $old['duplicate_days'] ?? 30 );
		if ( ( ! is_int( $days ) && ! is_string( $days ) ) || ! preg_match( '/^\d{1,4}$/D', (string) $days ) || (int) $days < 1 || (int) $days > 3650 ) {
			return error( 'מספר הימים חייב להיות בין 1 ל־3650.' );
		}
		if ( '' !== $old['start_date'] && $old['start_date'] !== $start ) {
			$q = query_records( 'lcrm_bill', array( 'state' => 'approved' ), 1, 1 );
			if ( $q['total'] ) {
				return error( 'תאריך תחילת החיוב נעול לאחר אישור חיובים.', 409 );
			}
		}
		$new = array(
			'duplicate_mode' => $mode,
			'duplicate_days' => (int) $days,
			'automatic'      => ! empty( $input['automatic'] ),
			'start_date'     => $start,
		);
		update_option( 'lcrm_settings', $new, false );
		if ( get_option( 'lcrm_settings' ) !== $new ) {
			return error( 'שמירת ההגדרות נכשלה.', 500 );
		}
		audit(
			'settings_changed',
			0,
			array(
				'duplicate_mode' => $mode,
				'duplicate_days' => (int) $days,
				'automatic'      => $new['automatic'],
			)
		);
		return $new;
	}
	if ( 'agreement' === $route ) {
		$id = absint( $input['institution'] ?? 0 );
		if ( 'institutions' !== get_post_type( $id ) ) {
			return error( 'מוסד לא נמצא.' );
		}
		$from  = $input['from'] ?? '';
		$price = money( $input['price'] ?? '' );
		$days  = $input['credit_days'] ?? null;
		if ( ! valid_date( $from ) || null === $price || ! is_numeric( $days ) || ! preg_match( '/^\d{1,3}$/D', (string) $days ) || $days < 0 || $days > 365 ) {
			return error( 'תעריף, תאריך או ימי אשראי לא תקינים.' );
		}
		$config = agreement( $id ) ?? array(
			'rates'       => array(),
			'credit_days' => 0,
		);
		// Prevent retrospective tariff edits after any bill for the institution was approved.
		$latest = get_posts(
			array(
				'post_type'      => 'lcrm_bill',
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_lcrm_institution',
						'value' => $id,
					),
					array(
						'key'   => '_lcrm_state',
						'value' => 'approved',
					),
				),
				'meta_key'       => '_lcrm_month',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		if ( $latest && substr( $from, 0, 7 ) <= data( $latest[0] )['month'] ) {
			return error( 'לא ניתן לשנות תעריף בתקופה שכבר אושרה.', 409 );
		}
		$config['rates']       = array_values(
			array_filter(
				$config['rates'],
				function ( $rate ) use ( $from ) {
					return $rate['from'] !== $from;
				}
			)
		);
		$config['rates'][]     = array(
			'from'   => $from,
			'price'  => $price,
			'vat_bp' => VAT_BP,
		);
		$config['credit_days'] = (int) $days;
		usort(
			$config['rates'],
			function ( $a, $b ) {
				return strcmp( $a['from'], $b['from'] );
			}
		);
		$GLOBALS['lcrm_changed_records'][] = $id;
		update_post_meta( $id, '_lcrm_agreement', $config );
		if ( get_post_meta( $id, '_lcrm_agreement', true ) !== $config ) {
			return error( 'שמירת ההסכם נכשלה.', 500 );
		}
		audit(
			'agreement_changed',
			$id,
			array(
				'from'        => $from,
				'price'       => $price,
				'vat_bp'      => VAT_BP,
				'credit_days' => (int) $days,
			)
		);
		return $config;
	}
	if ( 'member' === $route ) {
		$user = get_user_by( 'email', sanitize_email( $input['email'] ?? '' ) );
		$ids  = array_values( array_unique( array_map( 'absint', (array) ( $input['institutions'] ?? array() ) ) ) );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return error( 'נדרש משתמש קיים שאינו מנהל.' );
		}
		foreach ( $ids as $id ) {
			if ( 'institutions' !== get_post_type( $id ) ) {
					return error( 'מוסד לא תקין.' );
			}
		}
		$GLOBALS['lcrm_changed_users'][] = $user->ID;
		update_user_meta( $user->ID, '_lcrm_institutions', $ids );
		if ( get_user_meta( $user->ID, '_lcrm_institutions', true ) !== $ids ) {
			return error( 'שמירת הגישה נכשלה.', 500 );
		}
		if ( $ids ) {
			$user->add_cap( 'lcrm_view' );
		} else {
			$user->remove_cap( 'lcrm_view' );
		}
		audit( 'member_access_changed', $user->ID, array( 'institutions' => $ids ) );
		return array( 'saved' => true );
	}
	if ( 'prepare' === $route ) {
		if ( count( $input['institutions'] ?? array() ) > 20 ) {
			return error( 'ניתן להכין עד 20 מוסדות בבקשה.' );
		}
		$month = sanitize_text_field( $input['month'] ?? '' );
		$ids   = array_slice( array_unique( array_map( 'absint', (array) ( $input['institutions'] ?? array() ) ) ), 0, 20 );
		if ( ! $ids ) {
			return error( 'יש לבחור מוסדות.' );
		}
		$results = array();
		foreach ( $ids as $id ) {
			if ( 'institutions' !== get_post_type( $id ) ) {
				$results[] = array(
					'id'    => $id,
					'error' => 'מוסד לא תקין',
				);
				continue;
			} $r       = attempt(
				function () use ( $id, $month ) {
					return prepare_bill( $id, $month );
				}
			);
			$results[] = is_wp_error( $r ) ? array(
				'id'    => $id,
				'error' => $r->get_error_message(),
			) : $r;
		}
		return array( 'results' => $results );
	}
	if ( 'approve' === $route ) {
		if ( count( $input['ids'] ?? array() ) > 20 ) {
			return error( 'ניתן לאשר עד 20 חיובים בבקשה.' );
		}
		$ids = array_slice( array_unique( array_map( 'absint', (array) ( $input['ids'] ?? array() ) ) ), 0, 20 );
		if ( ! $ids ) {
			return error( 'יש לבחור חיובים.' );
		}
		$result         = array();
		$newly_approved = array();
		foreach ( $ids as $id ) {
			$was_approved = 'lcrm_bill' === get_post_type( $id ) && 'approved' === ( data( $id )['state'] ?? '' );
			$r            = attempt(
				function () use ( $id ) {
					return approve_bill( $id );
				}
			);
			if ( ! is_wp_error( $r ) && ! $was_approved ) {
				$newly_approved[] = $id;
			}
			$result[] = is_wp_error( $r ) ? array(
				'id'    => $id,
				'error' => $r->get_error_message(),
			) : $r;
		}
		return array(
			'results'        => $result,
			'newly_approved' => $newly_approved,
		);
	}
	if ( 'payment' === $route ) {
		return record_payment( absint( $input['bill'] ?? 0 ), $input );
	}
	if ( 'treatment' === $route ) {
		$id    = absint( $input['id'] ?? 0 );
		$state = sanitize_key( $input['treatment'] ?? '' );
		if ( 'lcrm_delivery' !== get_post_type( $id ) || ! in_array( $state, array( 'new', 'working', 'closed', 'irrelevant' ), true ) ) {
			return error( 'פנייה או מצב טיפול לא תקינים.' );
		}
		$item              = data( $id );
		$item['treatment'] = $state;
		$note              = sanitize_textarea_field( substr( (string) ( $input['note'] ?? '' ), 0, 2000 ) );
		if ( $note ) {
			$item['notes'][] = array(
				'text'  => $note,
				'actor' => get_current_user_id(),
				'at'    => current_time( 'mysql' ),
			);
		}
		$result = save_record( 'lcrm_delivery', $item, $id );
		audit( 'treatment_updated', $id, array( 'state' => $state ) );
		return $result;
	}
	if ( 'remap' === $route ) {
		$id = absint( $input['id'] ?? 0 );
		if ( 'lcrm_delivery' !== get_post_type( $id ) ) {
			return error( 'פנייה לא נמצאה.', 404 );
		}
		$item = data( $id );
		if ( 'unmapped' !== $item['state'] || $item['bill'] ) {
			return error( 'ניתן לשייך רק חריג היסטורי ללא חיוב.' );
		}
		$destinations = array_values( array_unique( array_map( 'absint', $input['institutions'] ?? array() ) ) );
		if ( ! $destinations || count( $destinations ) > 20 ) {
			return error( 'יש לבחור עד 20 מוסדות.' );
		}
		$results = array();
		foreach ( $destinations as $institution ) {
			$result = record_delivery( $item, $institution, $item['source'], empty( $item['origin_live'] ), $item['duplicate_mode'] ?? null );
			if ( is_wp_error( $result ) ) {
				return $result;

			} $results[] = $result;
		}
		if ( ! wp_trash_post( $id ) ) {
			return error( 'לא ניתן לסגור את החריג.', 500 );
		}
		audit( 'historical_remapped', $id, array( 'institutions' => $destinations ) );
		return array( 'results' => $results );
	}
	if ( 'import' === $route ) {
		return import_history( absint( $input['after'] ?? 0 ) );
	}
	return error( 'פעולה לא מוכרת.', 404 );
}
/**
 * XLSX uses native ZipArchive, inline strings, and bounded exports; no spreadsheet formulas.
 *
 * @param mixed $target Audit target ID or export kind.
 * @param array $result Bounded query result for the export.
 * @return mixed Operation result or validation error.
 */
function export_xlsx( $target, $result ) {
	if ( ! class_exists( '\\ZipArchive' ) ) {
		return error( 'נדרשת הרחבת PHP Zip לייצוא Excel.', 503 );
	}
	if ( ! in_array( $target, array( 'deliveries', 'bills' ), true ) ) {
		return error( 'הייצוא זמין ללידים ולחיובים.' );
	}
	$headers      = 'deliveries' === $target ? array( 'מוסד', 'תאריך', 'שם', 'טלפון', 'אימייל', 'מקור', 'כפילות', 'מצב מסירה' ) : array( 'מוסד', 'חודש שירות מלא', 'מצב', 'לפני מעמ', 'מעמ', 'סך הכל', 'שולם', 'יתרה', 'מועד פירעון' );
	$institutions = array_column( institution_list(), 'name', 'id' );
	$rows         = array( $headers );
	foreach ( $result['items'] as $item ) {
		$name   = $institutions[ $item['institution'] ] ?? '';
		$rows[] = 'deliveries' === $target ? array( $name, $item['date'], $item['name'], $item['phone'], $item['email'], $item['form'] ?? '', $item['duplicate_of'] ? 'כפול — לא לחיוב' : 'ללא כפילות', $item['state'] ) : array( $name, $item['month'], $item['state'], $item['subtotal'] / 100, $item['vat'] / 100, $item['total'] / 100, $item['paid'] / 100, ( $item['total'] - $item['paid'] ) / 100, $item['due'] );
	}
	$xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetData>';
	foreach ( $rows as $i => $row ) {
		$xml .= '<row r="' . ( $i + 1 ) . '">';
		foreach ( $row as $j => $cell ) {
			$xml .= '<c r="' . chr( 65 + $j ) . ( $i + 1 ) . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars( (string) $cell, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>';
		} $xml .= '</row>';
	}
	$xml .= '</sheetData></worksheet>';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$path = wp_tempnam( 'lcrm-export' );
	$zip  = new \ZipArchive();
	if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) {
		wp_delete_file( $path );
		return error( 'יצירת הקובץ נכשלה.', 500 );
	}
	$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' );
	$zip->addFromString( '_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
	$zip->addFromString( 'xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="דוח CRM" sheetId="1" r:id="rId1"/></sheets></workbook>' );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
	$zip->addFromString( 'xl/worksheets/sheet1.xml', $xml );
	$zip->close();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local temporary XLSX, never a URL.
	$bytes = file_get_contents( $path );
	wp_delete_file( $path );
	audit(
		'export',
		0,
		array(
			'target' => $target,
			'count'  => count( $result['items'] ),
		)
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary XLSX transport in JSON; no code obfuscation.
	return array(
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary XLSX transport; no code obfuscation.
		'file'  => base64_encode( $bytes ),
		'name'  => 'limu-' . $target . '.xlsx',
		'count' => count( $result['items'] ),
		'total' => $result['total'],
	);
}

/**
 * Aggregate on server to avoid transferring thousands of personal records for dashboard cards.
 *
 * @param \WP_REST_Request $request Authenticated REST request.
 * @return mixed Operation result or validation error.
 */
function summary( $request ) {
	$filters = period_filters( $request );
	if ( is_wp_error( $filters ) ) {
		return $filters;
	}
	$iid = absint( $request->get_param( 'institution' ) );
	if ( $iid ) {
		if ( ! owns( $iid ) ) {
			return error( 'אין הרשאה למוסד.', 403 );
		} $filters['institution'] = $iid;
	}
	$out = array(
		'leads'          => 0,
		'duplicates'     => 0,
		'historical'     => 0,
		'pending'        => 0,
		'unmapped'       => 0,
		'confirmed'      => 0,
		'approved'       => 0,
		'draft'          => 0,
		'paid'           => 0,
		'overdue'        => 0,
		'by_institution' => array(),
	);
	for ( $page = 1, $pages = 1; $page <= $pages; ++$page ) {
		$batch = query_records( 'lcrm_delivery', $filters, $page, 500 );
		if ( 1 === $page ) {
			$pages = $batch['pages'];
		}
		foreach ( $batch['items'] as $d ) {
			++$out['leads'];
			if ( $d['duplicate_of'] ) {
				++$out['duplicates'];
			}
			if ( 'historical' === $d['state'] ) {
				++$out['historical'];
			}
			if ( 'pending' === $d['state'] ) {
				++$out['pending'];
			}
			if ( 'unmapped' === $d['state'] ) {
				++$out['unmapped'];
			}
			if ( 'sent' === $d['state'] ) {
				++$out['confirmed'];
			}
			$iid                           = $d['institution'];
			$out['by_institution'][ $iid ] = ( $out['by_institution'][ $iid ] ?? 0 ) + 1;
		}
	}
	$today = current_time( 'Y-m-d' );
	for ( $page = 1, $pages = 1; $page <= $pages; ++$page ) {
		$batch = query_records( 'lcrm_bill', $filters, $page, 500 );
		if ( 1 === $page ) {
			$pages = $batch['pages'];
		}
		foreach ( $batch['items'] as $b ) {
			if ( 'approved' === $b['state'] ) {
				$out['approved'] += $b['total'];
				$out['paid']     += $b['paid'];
				if ( $b['due'] < $today ) {
					$out['overdue'] += $b['total'] - $b['paid'];
				}
			} else {
				$out['draft'] += $b['total'];
			}
		}
	}
	return $out;
}
