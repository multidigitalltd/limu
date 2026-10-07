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
 * Check the configured duplicate period with an exclusive rolling anniversary.
 *
 * @param string $previous Previous delivery timestamp.
 * @param string $current Current delivery timestamp.
 * @param string $mode Calendar or rolling duplicate policy.
 * @return mixed Operation result or validation error.
 */
function in_window( $previous, $current, $mode ) {
	$a = new \DateTimeImmutable( $previous, wp_timezone() );
	$b = new \DateTimeImmutable( $current, wp_timezone() );
	if ( $a > $b ) {
		return false;
	}
	return 'calendar' === $mode ? $a->format( 'Y' ) === $b->format( 'Y' ) : $a > $b->modify( '-12 months' );
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
