<?php
/**
 * Elementor submission staging and historical import adapters.
 *
 * @package LimuCRM
 */

// phpcs:disable WordPress.DB.SlowDBQuery -- Bounded legacy cursor and idempotency lookups use existing metadata.
namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Re-evaluate against confirmed deliveries only, on manager-confirmed delivery.
 *
 * @param array $item Private record payload.
 * @return mixed Operation result or validation error.
 */
function recalculate_duplicate( $item ) {
	$s                      = settings();
	$item['duplicate_of']   = 0;
	$item['duplicate_mode'] = $s['duplicate_mode'];
	$start                  = 'calendar' === $s['duplicate_mode'] ? substr( $item['date'], 0, 4 ) . '-01-01 00:00:00' : ( new \DateTimeImmutable( $item['date'], wp_timezone() ) )->modify( '-12 months' )->format( 'Y-m-d H:i:s' );
	foreach ( institution_deliveries( $item['institution'], $item['date'], $start ) as $previous ) {
		if ( ( $item['id'] ?? 0 ) === $previous['id'] || 'sent' !== $previous['state'] || $previous['duplicate_of'] ) {
			continue;
		}
		if ( same_contact( $item, $previous ) && in_window( $previous['date'], $item['date'], $s['duplicate_mode'] ) ) {
			$item['duplicate_of'] = $previous['id'];
			break;
		}
	}
	return $item;
}
/** Capture Elementor records with administrator-defined server-side recipients, never hidden-field recipients. */
add_action(
	'elementor_pro/forms/new_record',
	function ( $record, $handler ) {
		$form_id = (string) $record->get_form_settings( 'id' );
		$config  = null;
		foreach ( settings()['forms'] as $form ) {
			if ( $form['id'] === $form_id ) {
				$config = $form;
				break;
			}
		}
		if ( ! $config ) {
			return;
		}
		$fields = $record->get( 'fields' );
		$lead   = array(
			'date'  => current_time( 'mysql' ),
			'form'  => sanitize_text_field( $record->get_form_settings( 'form_name' ) ),
			'name'  => '',
			'phone' => '',
			'email' => '',
		);
		foreach ( array( 'name', 'phone', 'email' ) as $key ) {
			$value        = $fields[ $config[ $key ] ]['value'] ?? '';
			$lead[ $key ] = is_scalar( $value ) ? sanitize_text_field( substr( (string) $value, 0, 200 ) ) : '';
		}
		// The hook records submission, not proof of receipt. Keep captured leads pending until confirmed.
		$hash           = hash_hmac( 'sha256', $form_id . '|' . wp_json_encode( $fields ), wp_salt( 'nonce' ) );
		$source         = 'elementor:' . $hash . ':' . (int) floor( time() / 60 );
		$capture_result = locked(
			function () use ( $lead, $config, $source ) {
				foreach ( $config['institutions'] as $id ) {
					$item = record_delivery( $lead, $id, $source );
					if ( is_wp_error( $item ) ) {
						return $item;
					}
					if ( ! is_wp_error( $item ) && 'sent' === $item['state'] && empty( $item['confirmed_at'] ) ) {
							$item['state']        = 'pending';
							$item['duplicate_of'] = 0;
							$saved                = save_record( 'lcrm_delivery', $item, $item['id'] );
						if ( is_wp_error( $saved ) ) {
							return $saved;
						}
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $capture_result ) ) {
			audit( 'capture_error', 0, array( 'form_id' => $form_id ) );
			if ( is_object( $handler ) && method_exists( $handler, 'add_error_message' ) ) {
				$handler->add_error_message( 'הפנייה לא נקלטה במערכת הניהול. יש לנסות שוב או לפנות לאתר.' );
			}
		}
	},
	100,
	2
);
/** Trusted PHP adapter hook for existing dispatch code, only after verified success. */
add_action(
	'limu_crm_delivery_confirmed',
	function ( $lead, $institution, $source ) {
		if ( ! is_array( $lead ) || ! is_string( $source ) || '' === $source || strlen( $source ) > 180 ) {
			return;
		}
		$clean = array(
			'name'  => sanitize_text_field( $lead['name'] ?? '' ),
			'phone' => sanitize_text_field( $lead['phone'] ?? '' ),
			'email' => sanitize_email( $lead['email'] ?? '' ),
			'form'  => sanitize_text_field( $lead['form'] ?? '' ),
			'date'  => current_time( 'mysql' ),
		);
		locked(
			function () use ( $clean, $institution, $source ) {
				return record_delivery( $clean, absint( $institution ), sanitize_text_field( $source ) );
			}
		);
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
					save_record(
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
				}
			} else {
				$result = record_delivery( $lead, $iid, 'legacy:' . $id, true );
				if ( is_wp_error( $result ) ) {
						++$exceptions;
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
