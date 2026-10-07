<?php
/**
 * Pure validation, contact matching and integer money rules.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Normalize Israeli/local and international phone notation without fuzzy matching.
 *
 * @param mixed $value Input value to validate or normalize.
 * @return mixed Operation result or validation error.
 */
function normalize_phone( $value ) {
	$digits = preg_replace( '/\D+/', '', (string) $value );
	if ( 0 === strpos( $digits, '00972' ) ) {
		$digits = '0' . substr( $digits, 5 );
	} elseif ( 0 === strpos( $digits, '972' ) ) {
		$digits = '0' . substr( $digits, 3 );
	}
		return strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ? $digits : '';
}
/**
 * Normalize an email without provider-specific fuzzy rewriting.
 *
 * @param mixed $value Input value to validate or normalize.
 * @return mixed Operation result or validation error.
 */
function normalize_email( $value ) {
	$email = strtolower( trim( (string) $value ) );
	return is_email( $email ) ? $email : '';
}
/**
 * Convert decimal money to integer agorot; reject floating point input and rounding ambiguity.
 *
 * @param mixed $value Input value to validate or normalize.
 * @return mixed Operation result or validation error.
 */
function money( $value ) {
	if ( ( ! is_string( $value ) && ! is_int( $value ) ) || ! preg_match( '/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $value ) ) {
		return null;
	}
	$parts = explode( '.', (string) $value );
	return (int) $parts[0] * 100 + (int) str_pad( $parts[1] ?? '', 2, '0' );
}
/**
 * Validate a complete calendar date without allowing rollover.
 *
 * @param mixed $value Input value to validate or normalize.
 * @return mixed Operation result or validation error.
 */
function valid_date( $value ) {
	if ( ! is_string( $value ) ) {
		return false;
	}
	$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
	return $date && $date->format( 'Y-m-d' ) === $value;
}
/**
 * Matching is OR, scoped per institution, and excludes empty contact values.
 *
 * @param array $a First normalized contact.
 * @param array $b Second normalized contact.
 * @return mixed Operation result or validation error.
 */
function same_contact( $a, $b ) {
	return ( ! empty( $a['phone_key'] ) && $a['phone_key'] === $b['phone_key'] ) || ( ! empty( $a['email_key'] ) && $a['email_key'] === $b['email_key'] );
}
/**
 * Resolve mutable settings into a self-contained policy for each new delivery.
 *
 * @param array $config Duplicate detection settings.
 * @return string Immutable policy key.
 * @throws \InvalidArgumentException When the configured period is invalid.
 */
function duplicate_policy( $config ) {
	$mode = $config['duplicate_mode'] ?? 'calendar';
	if ( in_array( $mode, array( 'calendar', 'rolling', 'days_30', 'days_90', 'days_180', 'months_24' ), true ) ) {
		return $mode;
	}
	$days = $config['duplicate_days'] ?? 30;
	if ( 'custom' === $mode && ( is_int( $days ) || is_string( $days ) ) && preg_match( '/^\d{1,4}$/D', (string) $days ) && (int) $days >= 1 && (int) $days <= 3650 ) {
		return 'days:' . (int) $days;
	}
	throw new \InvalidArgumentException( 'Invalid duplicate policy.' );
}
/**
 * Find the earliest timestamp that can match a delivery's saved policy.
 *
 * @param string $current Current delivery timestamp.
 * @param string $mode Immutable calendar, month or day policy key.
 * @return string Earliest candidate timestamp in the site's timezone.
 * @throws \InvalidArgumentException When a saved period is invalid.
 */
function duplicate_window_start( $current, $mode ) {
	$date = new \DateTimeImmutable( $current, wp_timezone() );
	if ( 'calendar' === $mode ) {
		return $date->format( 'Y' ) . '-01-01 00:00:00';
	}
	if ( 'rolling' === $mode || 'months_24' === $mode ) {
		return $date->modify( 'rolling' === $mode ? '-12 months' : '-24 months' )->format( 'Y-m-d H:i:s' );
	}
	$periods = array(
		'days_30'  => 30,
		'days_90'  => 90,
		'days_180' => 180,
	);
	$days    = is_string( $mode ) && isset( $periods[ $mode ] ) ? $periods[ $mode ] : null;
	if ( null === $days && is_string( $mode ) && preg_match( '/^days:([1-9]\d{0,3})$/D', $mode, $matches ) && (int) $matches[1] <= 3650 ) {
		$days = (int) $matches[1];
	}
	if ( null === $days ) {
		throw new \InvalidArgumentException( 'Invalid saved duplicate policy.' );
	}
	return $date->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
}
/**
 * Check a saved duplicate period with an exclusive rolling boundary.
 *
 * @param string $previous Previous delivery timestamp.
 * @param string $current Current delivery timestamp.
 * @param string $mode Immutable calendar, month or day duplicate policy.
 * @return mixed Operation result or validation error.
 * @throws \InvalidArgumentException When a saved period is invalid.
 */
function in_window( $previous, $current, $mode ) {
	$a = new \DateTimeImmutable( $previous, wp_timezone() );
	$b = new \DateTimeImmutable( $current, wp_timezone() );
	if ( $a > $b ) {
		return false;
	}
	$start = new \DateTimeImmutable( duplicate_window_start( $current, $mode ), wp_timezone() );
	return 'calendar' === $mode ? $a->format( 'Y' ) === $b->format( 'Y' ) : $a > $start;
}
/**
 * Positive credit days are counted from actual issue/approval date, not month end.
 *
 * @param string $issued Actual approval or issue date.
 * @param int    $days Credit days from issue date.
 * @return mixed Operation result or validation error.
 */
function due_date( $issued, $days ) {
	return ( new \DateTimeImmutable( $issued, wp_timezone() ) )->modify( '+' . absint( $days ) . ' days' )->format( 'Y-m-d' );
}
/**
 * Select the most recent tariff effective on the delivery date.
 *
 * @param array  $config Institution agreement.
 * @param string $date Delivery calendar date.
 * @return mixed Operation result or validation error.
 */
function rate_on( $config, $date ) {
	$chosen = null;
	foreach ( $config['rates'] as $rate ) {
		if ( $rate['from'] <= $date && ( null === $chosen || $chosen['from'] < $rate['from'] ) ) {
			$chosen = $rate;
		}
	}
	return $chosen;
}
