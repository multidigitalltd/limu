<?php
/**
 * Atomic institution tariff changes shared by individual and bulk endpoints.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Financial writes must verify authoritative state on the locked database connection.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Read an institution and its tariff history without trusting persistent object caches.
 *
 * @param int $id Institution ID.
 * @return array|\WP_Error Saved configuration or a safe validation error.
 */
function agreement_snapshot( $id ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.post_type, p.post_status, m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.ID = %d",
			'_lcrm_agreement',
			$id
		),
		ARRAY_A
	);
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) {
		return error( 'לא ניתן לקרוא את הסכם המוסד.', 500 );
	}
	if ( ! $rows || 'institutions' !== $rows[0]['post_type'] || ! in_array( $rows[0]['post_status'], array( 'publish', 'draft' ), true ) ) {
		return error( 'מוסד לא נמצא או אינו פעיל.' );
	}
	if ( null === $rows[0]['meta_value'] ) {
		return array(
			'rates'       => array(),
			'credit_days' => 0,
		);
	}
	$config = maybe_unserialize( $rows[0]['meta_value'] );
	if ( ! is_array( $config ) || ! isset( $config['rates'], $config['credit_days'] ) || ! is_array( $config['rates'] ) || ! agreement_credit_days_valid( $config['credit_days'] ) ) {
		return error( 'הסכם המוסד אינו תקין. לא בוצעו שינויים.', 500 );
	}
	$dates = array();
	foreach ( $config['rates'] as $rate ) {
		if ( ! is_array( $rate ) || ! isset( $rate['from'], $rate['price'], $rate['vat_bp'] ) || ! valid_date( $rate['from'] ) || isset( $dates[ $rate['from'] ] ) || ! is_int( $rate['price'] ) || $rate['price'] < 0 || ! is_int( $rate['vat_bp'] ) || $rate['vat_bp'] < 0 || $rate['vat_bp'] > 10000 ) {
			return error( 'היסטוריית התעריפים אינה תקינה. לא בוצעו שינויים.', 500 );
		}
		$dates[ $rate['from'] ] = true;
	}
	return $config;
}
/**
 * Validate credit terms identically for the individual and bulk agreement paths.
 *
 * @param mixed $days Credit days from issue date.
 * @return bool Whether the supplied value is a whole number in the supported range.
 */
function agreement_credit_days_valid( $days ) {
	return ( is_int( $days ) || is_string( $days ) ) && preg_match( '/^\d{1,3}$/D', (string) $days ) && (int) $days <= 365;
}
/**
 * Find the latest approved period directly on the current transaction connection.
 *
 * @param int $id Institution ID.
 * @return string|\WP_Error Latest approved month, an empty string, or a safe error.
 */
function agreement_approved_month( $id ) {
	global $wpdb;
	$queries = $wpdb->num_queries;
	$rows    = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = %s AND i.meta_value = %s INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_status = %s ORDER BY m.meta_value DESC, p.ID DESC LIMIT 1",
			'_lcrm_institution',
			(string) $id,
			'_lcrm_state',
			'approved',
			'_lcrm_month',
			'lcrm_bill',
			'private'
		)
	);
	if ( $wpdb->num_queries <= $queries || $wpdb->last_error || ! is_array( $rows ) ) {
		return error( 'לא ניתן לבדוק תקופות חיוב מאושרות.', 500 );
	}
	if ( ! $rows ) {
		return '';
	}
	$bill = raw_records( $rows )[0];
	if ( ! isset( $bill['institution'], $bill['state'], $bill['month'] ) || (int) $bill['institution'] !== $id || 'approved' !== $bill['state'] || ! valid_date( $bill['month'] . '-01' ) ) {
		return error( 'לא ניתן לבדוק תקופות חיוב מאושרות.', 500 );
	}
	return $bill['month'];
}
/**
 * Save one effective tariff, preserving every other rate and independent institution policy.
 *
 * The caller owns the single database transaction for the entire request.
 *
 * @param int   $id Institution ID.
 * @param mixed $from Effective calendar date.
 * @param mixed $decimal_price Exact price in shekels, before the fixed VAT.
 * @param mixed $days Explicit credit days, or null to preserve the institution's terms.
 * @return array|\WP_Error Configuration and change flag, or a safe error.
 */
function agreement_write( $id, $from, $decimal_price, $days = null ) {
	if ( ! isset( $GLOBALS['lcrm_changed_records'] ) ) {
		return error( 'שמירת הסכם מחייבת פעולת ניהול מאובטחת.', 500 );
	}
	$price = money( $decimal_price );
	if ( ! valid_date( $from ) || null === $price || ( null !== $days && ! agreement_credit_days_valid( $days ) ) ) {
		return error( 'תעריף, תאריך או ימי אשראי לא תקינים.' );
	}
	$old = agreement_snapshot( $id );
	if ( is_wp_error( $old ) ) {
		return $old;
	}
	$config            = $old;
	$config['rates']   = array_values(
		array_filter(
			$config['rates'],
			static function ( $rate ) use ( $from ) {
				return $rate['from'] !== $from;
			}
		)
	);
	$config['rates'][] = array(
		'from'   => $from,
		'price'  => $price,
		'vat_bp' => VAT_BP,
	);
	if ( null !== $days ) {
		$config['credit_days'] = (int) $days;
	}
	usort(
		$config['rates'],
		static function ( $a, $b ) {
			return strcmp( $a['from'], $b['from'] );
		}
	);
	if ( $old === $config ) {
		return array(
			'config'  => $config,
			'changed' => false,
		);
	}
	$month = agreement_approved_month( $id );
	if ( is_wp_error( $month ) ) {
		return $month;
	}
	if ( '' !== $month && substr( $from, 0, 7 ) <= $month ) {
		return error( 'לא ניתן לשנות תעריף בתקופה שכבר אושרה.', 409 );
	}
	$GLOBALS['lcrm_changed_records'][] = $id;
	// update_post_meta() may otherwise short-circuit against a stale cached target value.
	wp_cache_delete( $id, 'post_meta' );
	update_post_meta( $id, '_lcrm_agreement', $config );
	$saved = agreement_snapshot( $id );
	if ( is_wp_error( $saved ) || $saved !== $config ) {
		return error( 'שמירת ההסכם נכשלה.', 500 );
	}
	audit(
		'agreement_changed',
		$id,
		array(
			'from'        => $from,
			'price'       => $price,
			'vat_bp'      => VAT_BP,
			'credit_days' => (int) $config['credit_days'],
		)
	);
	return array(
		'config'  => $config,
		'changed' => true,
	);
}
/**
 * Apply a common tariff to a bounded list while retaining each institution's terms.
 *
 * @param array $input Validated JSON object parameters.
 * @return array|\WP_Error Changed and unchanged institution IDs, or a safe error.
 */
function agreements_bulk_write( $input ) {
	if ( array_diff( array_keys( $input ), array( 'institutions', 'from', 'price' ) ) || ! isset( $input['institutions'], $input['from'], $input['price'] ) || ! is_array( $input['institutions'] ) || ! $input['institutions'] || count( $input['institutions'] ) > 100 || array_keys( $input['institutions'] ) !== range( 0, count( $input['institutions'] ) - 1 ) ) {
		return error( 'יש לבחור בין מוסד אחד למאה מוסדות ולציין תעריף ותאריך בלבד.' );
	}
	$ids = array();
	foreach ( $input['institutions'] as $id ) {
		if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[1-9]\d{0,9}$/D', (string) $id ) || isset( $ids[ (int) $id ] ) ) {
			return error( 'רשימת המוסדות אינה תקינה או מכילה כפילויות.' );
		}
		$ids[ (int) $id ] = (int) $id;
	}
	$result = array(
		'updated'   => array(),
		'unchanged' => array(),
		'count'     => 0,
	);
	foreach ( $ids as $id ) {
		$saved = agreement_write( $id, $input['from'], $input['price'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$result[ $saved['changed'] ? 'updated' : 'unchanged' ][] = $id;
	}
	$result['count'] = count( $result['updated'] );
	return $result;
}
