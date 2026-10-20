<?php
/**
 * Content Security Policy for the isolated portal only.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate one unpredictable CSP nonce for this response.
 *
 * @return string CSP nonce, separate from WordPress authentication nonces.
 */
function portal_nonce() {
	static $nonce;
	if ( null === $nonce ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP encodes random bytes, not executable code.
		$nonce = base64_encode( random_bytes( 24 ) );
	}
	return $nonce;
}

/**
 * Build the complete policy, allowing Cloudflare only when configured.
 *
 * @return string Header value.
 */
function portal_csp() {
	$nonce     = portal_nonce();
	$challenge = turnstile_site_key() ? ' https://challenges.cloudflare.com' : '';
	return "default-src 'none'; script-src 'self' 'nonce-{$nonce}'{$challenge}; script-src-attr 'none'; style-src 'self' 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'{$challenge}; frame-src " . ( $challenge ? 'https://challenges.cloudflare.com' : "'none'" ) . "; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";
}

/**
 * Authorize trusted WordPress script tags rendered in the isolated portal.
 *
 * @param array $attributes Script attributes from WordPress.
 * @return array Script attributes with the current response nonce.
 */
function portal_script_nonce( $attributes ) {
	if ( get_query_var( 'limu_crm' ) ) {
		$attributes['nonce'] = portal_nonce();
	}
	return $attributes;
}
add_filter( 'wp_inline_script_attributes', __NAMESPACE__ . '\\portal_script_nonce' );
add_filter( 'wp_script_attributes', __NAMESPACE__ . '\\portal_script_nonce' );
