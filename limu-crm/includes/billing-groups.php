<?php
/**
 * Shared monthly billing; institution identity and approved snapshots remain independent.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Financial configuration must bypass stale option and metadata caches.
// phpcs:disable WordPress.DB.SlowDBQuery -- Existing WordPress metadata stores the bounded group membership index.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the authoritative non-autoloaded group registry.
 *
 * @return array|\WP_Error Registry or safe error.
 */
function billing_groups() {
	global $wpdb;
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'lcrm_billing_groups' ) );
	if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) {
		return error( 'לא ניתן לקרוא את קבוצות הגבייה.', 500 );
	}
	$groups = $rows ? maybe_unserialize( $rows[0] ) : array();
	if ( ! is_array( $groups ) ) {
		return error( 'הגדרות קבוצות הגבייה אינן תקינות.', 500 );
	}
	foreach ( $groups as $id => $group ) {
		if ( ! is_array( $group ) || ! wp_is_uuid( $id ) || ( $group['id'] ?? null ) !== $id || ! is_string( $group['name'] ?? null ) || ! is_array( $group['members'] ?? null ) || ! array_is_list( $group['members'] ) || count( $group['members'] ) < 2 || count( $group['members'] ) > 20 || count( array_unique( $group['members'] ) ) !== count( $group['members'] ) || ! in_array( $group['payer'] ?? null, $group['members'], true ) || ! is_int( $group['credit_days'] ?? null ) || $group['credit_days'] < 0 || $group['credit_days'] > 365 || ! billing_month_valid( $group['from'] ?? null ) || ! array_key_exists( 'until', $group ) || ( '' !== $group['until'] && ( ! billing_month_valid( $group['until'] ) || $group['until'] < $group['from'] ) ) || ! is_bool( $group['cancelled'] ?? null ) ) {
			return error( 'הגדרות קבוצות הגבייה אינן תקינות.', 500 );
		}
		foreach ( $group['members'] as $member ) {
			if ( ! is_int( $member ) || $member < 1 ) {
				return error( 'מוסד בקבוצת הגבייה אינו תקין.', 500 );
			}
		}
	}
	return $groups;
}

/**
 * Validate a complete service month.
 *
 * @param mixed $month Month.
 * @return bool Valid.
 */
function billing_month_valid( $month ) {
	return is_string( $month ) && (bool) preg_match( '/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $month );
}

/**
 * Resolve the group effective for one institution and month.
 *
 * @param int        $id Institution.
 * @param string     $month Month.
 * @param array|null $groups Optional fresh registry.
 * @return array|null|\WP_Error Group, none or error.
 */
function billing_group_for( $id, $month, $groups = null ) {
	$groups = null === $groups ? billing_groups() : $groups;
	if ( is_wp_error( $groups ) ) {
		return $groups;
	}
	$found = null;
	foreach ( $groups as $group ) {
		if ( ! $group['cancelled'] && in_array( (int) $id, $group['members'], true ) && $group['from'] <= $month && ( '' === $group['until'] || $group['until'] >= $month ) ) {
			if ( $found ) {
				return error( 'מוסד משויך לקבוצות גבייה חופפות.', 409 );
			}
			$found = $group;
		}
	}
	return $found;
}

/**
 * Manager-only group reads and nonce-protected writes.
 *
 * @param \WP_REST_Request $request Request.
 * @return true|\WP_Error Permission.
 */
function billing_group_permission( $request ) {
	$permission = permission( $request );
	return true === $permission && ! manager() ? error( 'אין הרשאה.', 403 ) : $permission;
}
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'limu-crm/v1',
			'/billing-groups',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => __NAMESPACE__ . '\\billing_group_permission',
				'callback'            => static function ( $request ) {
					if ( 'GET' === $request->get_method() ) {
						$groups = billing_groups();
						return is_wp_error( $groups ) ? $groups : array( 'items' => array_values( $groups ) );
					}
					return locked( static fn() => billing_group_write( $request ) );
				},
			)
		);
	}
);

/**
 * Check cancellation eligibility using a fresh committed index read.
 *
 * @param string $id Group UUID.
 * @return bool|\WP_Error Whether a draft or approved snapshot exists.
 */
function billing_group_has_bills( $id ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$found   = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND p.post_status = %s AND m.meta_key = %s AND m.meta_value = %s LIMIT 1", 'lcrm_bill', 'private', '_lcrm_billing_group', $id ) );
	if ( $wpdb->last_error || $wpdb->num_queries <= $queries ) {
		return error( 'לא ניתן לבדוק חיובים קיימים לקבוצה.', 500 );
	}
	return null !== $found;
}

/**
 * Create an immutable group, schedule its end or cancel an unused group.
 *
 * @param \WP_REST_Request $request Request.
 * @return array|\WP_Error Saved group.
 */
function billing_group_write( $request ) {
	$input = $request->get_json_params();
	if ( strlen( $request->get_body() ) > 8192 || ! is_array( $input ) || array_diff( array_keys( $input ), array( 'id', 'name', 'members', 'payer', 'from', 'credit_days', 'until', 'cancelled' ) ) || ! is_string( $input['id'] ?? null ) || ! wp_is_uuid( $input['id'] ) ) {
		return error( 'פרטי קבוצה לא תקינים.' );
	}
	$groups = billing_groups();
	if ( is_wp_error( $groups ) ) {
		return $groups;
	}
	$id = strtolower( $input['id'] );
	if ( isset( $input['until'] ) || isset( $input['cancelled'] ) ) {
		if ( ! isset( $groups[ $id ] ) || array_diff( array_keys( $input ), array( 'id', 'until', 'cancelled' ) ) ) {
			return error( 'קבוצה לא נמצאה או פעולה לא תקינה.' );
		}
		$group = $groups[ $id ];
		if ( array_key_exists( 'cancelled', $input ) ) {
			if ( true !== $input['cancelled'] || isset( $input['until'] ) ) {
				return error( 'ביטול קבוצה לא תקין.' );
			}
			$existing = billing_group_has_bills( $id );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( $existing ) {
				return error( 'קבוצה שכבר הוכנו לה חיובים אינה ניתנת לביטול. ניתן לקבוע חודש סיום.', 409 );
			}
			$group['cancelled'] = true;
		} else {
			if ( ! billing_month_valid( $input['until'] ) || $input['until'] < current_time( 'Y-m' ) || $input['until'] < $group['from'] || ( '' !== $group['until'] && $input['until'] !== $group['until'] ) ) {
				return error( 'חודש הסיום חייב להיות החודש הנוכחי או עתידי; לא ניתן לשנות סיום שכבר נקבע.' );
			}
			$group['until'] = $input['until'];
		}
	} else {
		if ( ! is_string( $input['name'] ?? null ) || '' === trim( $input['name'] ) || strlen( $input['name'] ) > 300 || ! is_array( $input['members'] ?? null ) || ! array_is_list( $input['members'] ) || count( $input['members'] ) < 2 || count( $input['members'] ) > 20 || ! is_int( $input['payer'] ?? null ) || ! billing_month_valid( $input['from'] ?? null ) || $input['from'] < current_time( 'Y-m' ) || ! is_int( $input['credit_days'] ?? null ) || $input['credit_days'] < 0 || $input['credit_days'] > 365 ) {
			return error( 'יש לבחור 2–20 מוסדות, מוסד משלם, חודש התחלה נוכחי או עתידי וימי אשראי בין 0 ל־365.' );
		}
		foreach ( $input['members'] as $member ) {
			if ( ! is_int( $member ) || $member < 1 || 'institutions' !== get_post_type( $member ) || ! in_array( get_post_status( $member ), array( 'publish', 'draft' ), true ) ) {
				return error( 'מוסד בקבוצה אינו תקין.' );
			}
		}
		$members = array_values( array_unique( $input['members'] ) );
		sort( $members, SORT_NUMERIC );
		if ( count( $members ) !== count( $input['members'] ) || ! in_array( $input['payer'], $members, true ) ) {
			return error( 'המוסדות חייבים להיות שונים והמוסד המשלם חייב להשתייך לקבוצה.' );
		}
		$group = array(
			'id'          => $id,
			'name'        => sanitize_text_field( $input['name'] ),
			'members'     => $members,
			'payer'       => $input['payer'],
			'from'        => $input['from'],
			'until'       => '',
			'credit_days' => $input['credit_days'],
			'cancelled'   => false,
		);
		if ( '' === $group['name'] ) {
			return error( 'יש להזין שם קבוצה.' );
		}
		foreach ( $groups as $other_id => $other ) {
			if ( $other_id !== $id && ! $other['cancelled'] && array_intersect( $members, $other['members'] ) && ( '' === $other['until'] || $other['until'] >= $group['from'] ) ) {
				return error( 'אחד המוסדות כבר משויך לקבוצה בתקופה זו.', 409 );
			}
		}
		if ( isset( $groups[ $id ] ) && $groups[ $id ] !== $group ) {
			return error( 'מזהה הבקשה כבר נשמר עם הגדרה אחרת.', 409 );
		}
	}
	if ( ( $groups[ $id ] ?? null ) === $group ) {
		return $group;
	}
	$groups[ $id ] = $group;
	update_option( 'lcrm_billing_groups', $groups, false );
	if ( billing_groups() !== $groups ) {
		return error( 'שמירת הקבוצה נכשלה.', 500 );
	}
	audit(
		'billing_group_changed',
		0,
		array(
			'group'     => $id,
			'members'   => $group['members'],
			'from'      => $group['from'],
			'until'     => $group['until'],
			'cancelled' => $group['cancelled'],
		)
	);
	return $group;
}

/**
 * Require an explicit group-aware UI before approving, paying or issuing a combined document.
 *
 * @param int   $id Bill ID.
 * @param array $input Request fields.
 * @return true|\WP_Error Whether group intent was acknowledged.
 */
function billing_acknowledgement( $id, $input ) {
	if ( 'lcrm_bill' !== get_post_type( $id ) ) {
		return error( 'חיוב לא נמצא.', 404 );
	}
	$bill = raw_records( array( $id ) )[0];
	if ( ! empty( $bill['billing_group'] ) && true !== ( $input['shared_billing'] ?? null ) ) {
		return error( 'זהו חיוב משותף. יש לרענן את המערכת ולבדוק את החיוב המלא לפני ביצוע הפעולה.', 409 );
	}
	return true;
}

/**
 * Resolve snapshot membership without replacing the payer institution.
 *
 * @param array $bill Bill/payment snapshot.
 * @return array Institution IDs.
 */
function billing_members( $bill ) {
	return $bill['billing_members'] ?? array( (int) $bill['institution'] );
}

/**
 * Validate a frozen grouped bill and its shares before a financial operation.
 *
 * @param array $bill Snapshot.
 * @return void
 * @throws \RuntimeException Corrupt snapshot.
 */
function billing_validate_bill( $bill ) {
	if ( empty( $bill['billing_group'] ) ) {
		if ( isset( $bill['billing_members'] ) || isset( $bill['shares'] ) ) {
			throw new \RuntimeException( 'Grouped snapshot has no group identity.' );
		}
		return;
	}
	$members = $bill['billing_members'] ?? array();
	if ( ! is_string( $bill['billing_group'] ) || ! wp_is_uuid( $bill['billing_group'] ) || ! is_string( $bill['group_name'] ?? null ) || ! is_array( $bill['lines'] ?? null ) || ! is_int( $bill['institution'] ?? null ) || ! is_int( $bill['credit_days'] ?? null ) || $bill['credit_days'] < 0 || $bill['credit_days'] > 365 || ! billing_month_valid( $bill['month'] ?? null ) || ! in_array( $bill['state'] ?? null, array( 'draft', 'approved' ), true ) || ! is_array( $members ) || ! array_is_list( $members ) || count( $members ) < 2 || count( $members ) > 20 || count( $members ) !== count( array_unique( $members ) ) || ! in_array( $bill['institution'], $members, true ) || ! is_array( $bill['shares'] ?? null ) || array_keys( $bill['shares'] ) !== $members ) {
		throw new \RuntimeException( 'Grouped bill membership is invalid.' );
	}
	foreach ( $members as $member ) {
		if ( ! is_int( $member ) || $member < 1 ) {
			throw new \RuntimeException( 'Grouped institution identity is invalid.' );
		}
	}
	$line_ids = array();
	foreach ( $bill['lines'] as $line ) {
		if ( ! is_int( $line['delivery'] ?? null ) || $line['delivery'] < 1 || isset( $line_ids[ $line['delivery'] ] ) || ! in_array( $line['institution'] ?? null, $members, true ) || ! is_int( $line['price'] ?? null ) || $line['price'] < 0 || $line['price'] > 9999999999 || ! is_int( $line['vat'] ?? null ) || ( $line['vat_bp'] ?? null ) !== VAT_BP || intdiv( $line['price'] * VAT_BP + 5000, 10000 ) !== $line['vat'] || ! is_string( $line['institution_name'] ?? null ) ) {
			throw new \RuntimeException( 'Grouped billing line is invalid.' );
		}
	}
	$sums = array(
		'subtotal' => 0,
		'vat'      => 0,
		'total'    => 0,
		'paid'     => 0,
		'count'    => 0,
	);
	foreach ( $members as $member ) {
		$share = $bill['shares'][ $member ] ?? array();
		foreach ( $sums as $key => $unused ) {
			if ( ! is_int( $share[ $key ] ?? null ) || $share[ $key ] < 0 || $share[ $key ] > PHP_INT_MAX - $sums[ $key ] ) {
				throw new \RuntimeException( 'Grouped bill share is invalid.' );
			}
			$sums[ $key ] += $share[ $key ];
		}
		if ( $share['total'] !== $share['subtotal'] + $share['vat'] || $share['paid'] > $share['total'] ) {
			throw new \RuntimeException( 'Grouped bill share totals are invalid.' );
		}
		$lines = array_values( array_filter( $bill['lines'], static fn( $line ) => ( $line['institution'] ?? null ) === $member ) );
		if ( count( $lines ) !== $share['count'] || array_sum( array_column( $lines, 'price' ) ) !== $share['subtotal'] || array_sum( array_column( $lines, 'vat' ) ) !== $share['vat'] ) {
			throw new \RuntimeException( 'Grouped bill lines do not match shares.' );
		}
	}
	foreach ( array( 'subtotal', 'vat', 'total', 'paid' ) as $key ) {
		if ( $sums[ $key ] !== $bill[ $key ] ) {
			throw new \RuntimeException( 'Grouped bill totals are invalid.' );
		}
	}
	if ( count( $bill['lines'] ) !== $sums['count'] ) {
		throw new \RuntimeException( 'Grouped bill contains unassigned lines.' );
	}
}

/**
 * Build one monthly draft using each member's own tariffs and eligibility.
 *
 * @param array  $group Effective group.
 * @param string $month Completed month.
 * @return array|\WP_Error Shared draft.
 * @throws \RuntimeException When the authoritative billing period cannot be read.
 */
function prepare_group_bill( $group, $month ) {
	global $wpdb;
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) || ! billing_month_valid( $month ) || $month >= current_time( 'Y-m' ) ) {
		return error( 'חיוב משותף מחייב שמירה מאובטחת וחודש שהסתיים.' );
	}
	$queries = $wpdb->num_queries;
	$ids     = get_posts(
		array(
			'post_type'              => 'lcrm_bill',
			'post_status'            => 'private',
			'fields'                 => 'ids',
			'posts_per_page'         => 2,
			'cache_results'          => false,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => '_lcrm_institution',
					'value'   => $group['members'],
					'compare' => 'IN',
					'type'    => 'NUMERIC',
				),
				array(
					'key'   => '_lcrm_month',
					'value' => $month,
				),
			),
		)
	);
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error ) {
		throw new \RuntimeException( 'Shared billing period lookup failed.' );
	}
	if ( count( $ids ) > 1 ) {
		return error( 'קיימים חיובים נפרדים או חופפים בתקופה. החיובים הקיימים לא ישונו.', 409 );
	}
	$existing = $ids ? raw_records( $ids )[0] : null;
	if ( $existing ) {
		billing_validate_bill( $existing );
		if ( ( $existing['billing_group'] ?? '' ) !== $group['id'] || ( $existing['billing_members'] ?? array() ) !== $group['members'] ) {
			return error( 'קיים חיוב נפרד או חיוב מקבוצה אחרת בתקופה. החיוב הקיים לא ישונה.', 409 );
		}
	}
	if ( $existing && 'approved' === $existing['state'] ) {
		return $existing;
	}
	$start = settings()['start_date'];
	if ( ! valid_date( $start ) ) {
		return error( 'יש להגדיר תאריך התחלת חיוב.' );
	}
	$first = $month . '-01 00:00:00';
	$end   = ( new \DateTimeImmutable( $first, wp_timezone() ) )->format( 'Y-m-t' ) . ' 23:59:59';
	if ( substr( $end, 0, 10 ) < $start ) {
		return error( 'התקופה קודמת לתחילת החיוב.' );
	}
	$bill = array(
		'institution'     => $group['payer'],
		'billing_group'   => $group['id'],
		'group_name'      => $group['name'],
		'billing_members' => $group['members'],
		'month'           => $month,
		'state'           => 'draft',
		'lines'           => array(),
		'shares'          => array(),
		'subtotal'        => 0,
		'vat'             => 0,
		'total'           => 0,
		'paid'            => 0,
		'duplicates'      => 0,
		'historical'      => 0,
		'issued'          => '',
		'due'             => '',
		'credit_days'     => $group['credit_days'],
		'document_state'  => 'not_connected',
	);
	foreach ( $group['members'] as $member ) {
		$config = agreement( $member );
		if ( ! $config ) {
			return error( 'יש להגדיר הסכם לכל מוסד בקבוצה.' );
		}
		$share = array(
			'subtotal'   => 0,
			'vat'        => 0,
			'total'      => 0,
			'paid'       => 0,
			'count'      => 0,
			'name'       => get_the_title( $member ),
			'duplicates' => 0,
			'historical' => 0,
		);
		foreach ( institution_deliveries( $member, $end, $first, false ) as $item ) {
			if ( $item['duplicate_of'] ) {
				++$share['duplicates'];
				continue;
			}
			if ( 'sent' !== $item['state'] ) {
				++$share['historical'];
				continue;
			}
			if ( ! empty( $item['billing_excluded'] ) || substr( $item['date'], 0, 10 ) < $start ) {
				continue;
			}
			if ( $item['bill'] && ( ! $existing || $item['bill'] !== $existing['id'] ) ) {
				return error( 'פנייה כבר משויכת לחיוב אחר.', 409 );
			}
			$rate = rate_on( $config, substr( $item['date'], 0, 10 ) );
			if ( ! $rate ) {
				return error( 'חסר תעריף תקף במועד הפנייה לאחד המוסדות בקבוצה.' );
			}
			$vat                = intdiv( $rate['price'] * VAT_BP + 5000, 10000 );
			$bill['lines'][]    = array(
				'delivery'         => $item['id'],
				'institution'      => $member,
				'institution_name' => $share['name'],
				'price'            => $rate['price'],
				'vat_bp'           => VAT_BP,
				'vat'              => $vat,
			);
			$share['subtotal'] += $rate['price'];
			$share['vat']      += $vat;
			++$share['count'];
		}
		$share['total']            = $share['subtotal'] + $share['vat'];
		$bill['shares'][ $member ] = $share;
		foreach ( array( 'subtotal', 'vat', 'total', 'duplicates', 'historical' ) as $key ) {
			if ( ! is_int( $share[ $key ] ) || $share[ $key ] > PHP_INT_MAX - $bill[ $key ] ) {
				return error( 'סכום החיוב חורג מהטווח הנתמך.' );
			}
			$bill[ $key ] += $share[ $key ];
		}
	}
	billing_validate_bill( $bill );
	return save_record( 'lcrm_bill', $bill, $existing['id'] ?? 0 );
}

/**
 * Exact quotient and remainder of a*b/divisor without overflowing integer multiplication.
 *
 * @param int $a Amount.
 * @param int $b Weight.
 * @param int $divisor Sum.
 * @return array Quotient and remainder.
 */
function billing_ratio( $a, $b, $divisor ) {
	$q = 0;
	$r = 0;
	foreach ( str_split( decbin( $a ) ) as $bit ) {
		$q += $q;
		if ( $r >= $divisor - $r ) {
			$r -= $divisor - $r;
			++$q;
		} else {
			$r += $r;
		}
		if ( '1' === $bit ) {
			if ( $r >= $divisor - $b ) {
				$r -= $divisor - $b;
				++$q;
			} else {
				$r += $b;
			}
		}
	}
	return array( $q, $r );
}

/**
 * Allocate one partial payment proportionally to each outstanding balance; deterministic largest remainders.
 *
 * @param array $bill Fresh bill.
 * @param array $paid Ledger allocations per institution.
 * @param int   $amount Payment cents.
 * @return array Allocations.
 * @throws \RuntimeException Corrupt allocation.
 */
function billing_allocate( $bill, $paid, $amount ) {
	$balances = array();
	foreach ( $bill['shares'] as $id => $share ) {
		$balances[ $id ] = $share['total'] - ( $paid[ $id ] ?? 0 );
		if ( $balances[ $id ] < 0 ) {
			throw new \RuntimeException( 'Institution payment exceeds share.' );
		}
	}
	$total = array_sum( $balances );
	if ( ! is_int( $total ) || $amount < 1 || $amount > $total ) {
		throw new \RuntimeException( 'Invalid proportional payment.' );
	}
	$allocations = array();
	$remainders  = array();
	foreach ( $balances as $id => $balance ) {
		list( $allocations[ $id ], $remainders[ $id ] ) = billing_ratio( $amount, $balance, $total );
	}
	$left = $amount - array_sum( $allocations );
	$ids  = array_keys( $balances );
	usort(
		$ids,
		static function ( $a, $b ) use ( $remainders ) {
			$order = $remainders[ $b ] <=> $remainders[ $a ];
			return 0 !== $order ? $order : ( $a <=> $b );
		}
	);
	foreach ( $ids as $id ) {
		if ( $left && $allocations[ $id ] < $balances[ $id ] ) {
			++$allocations[ $id ];
			--$left;
		}
	}
	if ( $left ) {
		throw new \RuntimeException( 'Payment allocation did not reconcile.' );
	}
	return $allocations;
}

/**
 * Project shared financial totals to the allowed/selected institution scope before reports or serialization.
 *
 * @param array $item Financial snapshot.
 * @param array $filters Scoped filters.
 * @return array Safe projected snapshot.
 * @throws \RuntimeException Invalid financial snapshot or unauthorized index.
 */
function billing_project( $item, $filters = array() ) {
	if ( empty( $item['billing_group'] ) ) {
		if ( isset( $item['billing_members'] ) || isset( $item['allocations'] ) || isset( $item['shares'] ) ) {
			throw new \RuntimeException( 'Financial group identity is missing.' );
		}
		return $item;
	}
	if ( isset( $item['shares'] ) ) {
		billing_validate_bill( $item );
	}
	if ( ! isset( $item['shares'] ) ) {
		if ( ! is_array( $item['billing_members'] ?? null ) || ! is_array( $item['allocations'] ?? null ) || array_keys( $item['allocations'] ) !== $item['billing_members'] || ! is_int( $item['amount'] ?? null ) || array_sum( $item['allocations'] ) !== $item['amount'] ) {
			throw new \RuntimeException( 'Grouped payment snapshot is invalid.' );
		}
		foreach ( $item['allocations'] as $allocation ) {
			if ( ! is_int( $allocation ) || $allocation < 0 ) {
				throw new \RuntimeException( 'Grouped payment amount is invalid.' );
			}
		}
	}
	$members = $item['billing_members'];
	$scope   = manager() ? $members : array_values( array_intersect( $members, allowed_institutions() ) );
	if ( ! empty( $filters['institution'] ) ) {
		$scope = array_values( array_intersect( $scope, array( (int) $filters['institution'] ) ) );
	}
	if ( count( $scope ) === count( $members ) ) {
		return $item;
	}
	$item['scoped_group']    = true;
	$item['institution']     = $scope[0] ?? 0;
	$item['group_name']      = implode( ' · ', array_map( 'get_the_title', $scope ) ) . ' — חלק בחיוב משותף';
	$item['billing_members'] = $scope;
	if ( isset( $item['shares'] ) ) {
		$item['shares'] = array_intersect_key( $item['shares'], array_flip( $scope ) );
		foreach ( array( 'subtotal', 'vat', 'total', 'paid', 'duplicates', 'historical' ) as $key ) {
			$item[ $key ] = array_sum( array_column( $item['shares'], $key ) );
		}
		$item['lines'] = array_values( array_filter( $item['lines'], static fn( $line ) => in_array( $line['institution'], $scope, true ) ) );
	} else {
		$item['allocations'] = array_intersect_key( $item['allocations'], array_flip( $scope ) );
		$item['amount']      = array_sum( $item['allocations'] );
	}
	return $item;
}
