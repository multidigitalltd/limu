<?php
/**
 * Automatic form capture and historical import adapters.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.SlowDBQuery -- Bounded legacy cursor and idempotency lookups use existing metadata.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Reconcile subsequent deliveries when source events arrive out of chronological order.
 * Existing per-delivery policies remain intact and approved periods are never changed.
 *
 * @param array $changed Newly recorded automatic delivery.
 * @return array|\WP_Error Updated delivery or an immutable-period conflict.
 */
function reconcile_duplicates( $changed ) {
	$start = ( new \DateTimeImmutable( $changed['date'], wp_timezone() ) )->modify( '-12 months' )->format( 'Y-m-d H:i:s' );
	$items = institution_deliveries( $changed['institution'], null, $start );
	$bases = array();
	$bills = array();
	foreach ( $items as $item ) {
		if ( 'sent' !== $item['state'] ) {
			continue;
		}
		if ( $item['date'] >= $changed['date'] ) {
			$duplicate = 0;
			foreach ( $bases as $previous ) {
				if ( same_contact( $item, $previous ) && in_window( $previous['date'], $item['date'], $item['duplicate_mode'] ?? 'calendar' ) ) {
					$duplicate = $previous['id'];
					break;
				}
			}
			if ( $duplicate !== $item['duplicate_of'] ) {
				if ( ! array_key_exists( $item['month'], $bills ) ) {
					$bills[ $item['month'] ] = bill_for( $item['institution'], $item['month'] );
				}
				if ( $item['bill'] || ( $bills[ $item['month'] ] && 'approved' === $bills[ $item['month'] ]['state'] ) ) {
					return error( 'הקליטה תשנה כפילות בתקופה שכבר אושרה. נדרשת התאמה כספית נפרדת.', 409 );
				}
				$item['duplicate_of'] = $duplicate;
				$result               = save_record( 'lcrm_delivery', $item, $item['id'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		if ( ! $item['duplicate_of'] ) {
			$bases[] = $item;
		}
	}
	return data( $changed['id'] );
}
/** Trusted PHP adapter hook for existing dispatch code, only after verified success. */
add_action(
	'limu_crm_delivery_confirmed',
	function ( $lead, $institution, $source ) {
		if ( ! is_array( $lead ) || ! is_string( $source ) || '' === $source || strlen( $source ) > 180 ) {
			return;
		}
		$source = sanitize_text_field( $source );
		if ( '' === $source ) {
			return;
		}
		$clean  = array(
			'name'  => sanitize_text_field( $lead['name'] ?? '' ),
			'phone' => sanitize_text_field( $lead['phone'] ?? '' ),
			'email' => sanitize_email( $lead['email'] ?? '' ),
			'form'  => sanitize_text_field( $lead['form'] ?? '' ),
			'date'  => current_time( 'mysql' ),
		);
		$result = locked(
			function () use ( $clean, $institution, $source ) {
				return record_delivery( $clean, absint( $institution ), $source );
			}
		);
		if ( is_wp_error( $result ) ) {
			audit( 'dispatch_error', absint( $institution ), array( 'status' => $result->get_error_data()['status'] ?? 500 ) );
			/** Notify the trusted dispatch integration without exposing a public endpoint. */
			do_action( 'limu_crm_delivery_error', $result, absint( $institution ), $source );
		}
	},
	10,
	3
);
/**
 * Resolve legacy names exactly; ambiguous or partially mapped lists remain exceptions.
 *
 * @param mixed $value Input value to validate or normalize.
 * @param array $titles Exact institution title to ID mapping.
 * @return mixed Operation result or validation error.
 */
function legacy_institutions( $value, $titles ) {
	if ( isset( $titles[ $value ] ) && 1 === count( $titles[ $value ] ) ) {
		return $titles[ $value ];
	}
	if ( ctype_digit( (string) $value ) && 'institutions' === get_post_type( (int) $value ) ) {
		return array( (int) $value );
	}
	$parts = preg_split( '/,\s*/u', (string) $value );
	$ids   = array();
	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( ! isset( $titles[ $part ] ) || 1 !== count( $titles[ $part ] ) ) {
			return array();
		} $ids[] = $titles[ $part ][0];
	}
	return array_values( array_unique( $ids ) );
}
/**
 * Cursor-based, retry-safe historical import; never converts imported records to confirmed delivery.
 *
 * @param int|string|null $after Cursor or lower timestamp bound.
 * @return mixed Operation result or validation error.
 */
function import_history( $after ) {
	global $wpdb;
	$institutions = get_posts(
		array(
			'post_type'      => 'institutions',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	$titles       = array();
	foreach ( $institutions as $id ) {
		$title              = get_post_field( 'post_title', $id, 'raw' );
		$titles[ $title ][] = $id;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live, admin-only import cursor; caching would skip changed rows.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d", 'leads', 'publish', $after, 50 ) );
	update_meta_cache( 'post', $ids );
	if ( $ids ) {
		get_posts(
			array(
				'post_type'      => 'leads',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => count( $ids ),
				'no_found_rows'  => true,
			)
		);
	}
	$exceptions = 0;
	$created    = 0;
	foreach ( $ids as $id ) {
		$post         = get_post( $id );
		$value        = (string) get_post_meta( $id, 'institution', true );
		$destinations = legacy_institutions( $value, $titles );
		$lead         = array(
			'name'      => sanitize_text_field( get_post_meta( $id, 'full-name', true ) ),
			'phone'     => sanitize_text_field( get_post_meta( $id, 'phone', true ) ),
			'email'     => sanitize_email( get_post_meta( $id, 'email', true ) ),
			'form'      => sanitize_text_field( get_post_meta( $id, 'form-name', true ) ),
			'date'      => $post->post_date,
			'legacy_id' => (int) $id,
			'contact'   => (int) $id,
		);
		if ( ! $destinations ) {
			++$exceptions;
			$destinations = array( 0 );
		}
		foreach ( $destinations as $iid ) {
			if ( ! $iid ) {
				$exists = get_posts(
					array(
						'post_type'      => 'lcrm_delivery',
						'post_status'    => 'private',
						'fields'         => 'ids',
						'posts_per_page' => 1,
						'meta_key'       => '_lcrm_source',
						'meta_value'     => 'legacy:' . $id,
					)
				);
				if ( ! $exists ) {
					$result = save_record(
						'lcrm_delivery',
						array_merge(
							$lead,
							array(
								'institution'  => 0,
								'source'       => 'legacy:' . $id,
								'state'        => 'unmapped',
								'month'        => substr( $post->post_date, 0, 7 ),
								'duplicate_of' => 0,
								'bill'         => 0,
								'phone_key'    => normalize_phone( $lead['phone'] ),
								'email_key'    => normalize_email( $lead['email'] ),
							)
						)
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
			} else {
				$result = record_delivery( $lead, $iid, 'legacy:' . $id, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				} else {
								++$created;
				}
			}
		}
	}
	$cursor = $ids ? (int) end( $ids ) : $after;
	audit(
		'history_batch',
		0,
		array(
			'after'      => $after,
			'cursor'     => $cursor,
			'exceptions' => $exceptions,
		)
	);
	return array(
		'after'      => $cursor,
		'processed'  => count( $ids ),
		'deliveries' => $created,
		'exceptions' => $exceptions,
		'done'       => count( $ids ) < 50,
	);
}
