<?php
/**
 * Optional Cloudflare Turnstile verification, with secrets supplied outside the plugin.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the public widget site key from runtime configuration.
 *
 * @return string Public widget key, or empty when disabled.
 */
function turnstile_site_key() {
	return defined( 'LIMU_CRM_TURNSTILE_SITE_KEY' ) ? (string) LIMU_CRM_TURNSTILE_SITE_KEY : (string) getenv( 'LIMU_CRM_TURNSTILE_SITE_KEY' );
}

/**
 * Verify a token against a fixed HTTPS endpoint and its expected hostname/action.
 *
 * @param string $token Browser challenge token.
 * @param string $action Expected action binding.
 * @return bool Verified token result.
 */
function verify_turnstile( $token, $action ) {
	$secret = defined( 'LIMU_CRM_TURNSTILE_SECRET' ) ? (string) LIMU_CRM_TURNSTILE_SECRET : (string) getenv( 'LIMU_CRM_TURNSTILE_SECRET' );
	if ( ! $secret || ! is_string( $token ) || ! $token || strlen( $token ) > 2048 ) {
		return false;
	}
	$result = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'body'        => array(
				'secret'   => $secret,
				'response' => $token,
			),
		)
	);
	if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
		return false;
	}
	$body = json_decode( wp_remote_retrieve_body( $result ), true );
	return is_array( $body ) && true === ( $body['success'] ?? false ) && ( $body['action'] ?? '' ) === $action && strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ) === strtolower( (string) ( $body['hostname'] ?? '' ) );
}

/**
 * Generate the optional widget and load its script only where requested.
 *
 * @param string $action Verified challenge action.
 * @param string $field Token field name.
 * @return string Escaped widget markup.
 */
function turnstile_widget( $action, $field ) {
	$key = turnstile_site_key();
	if ( ! $key ) {
		return '';
	}
	wp_enqueue_script(
		'lcrm-turnstile',
		'https://challenges.cloudflare.com/turnstile/v0/api.js',
		array(),
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare manages its versioned v0 API cache.
		null,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	return '<div class="cf-turnstile" data-sitekey="' . esc_attr( $key ) . '" data-action="' . esc_attr( $action ) . '" data-response-field-name="' . esc_attr( $field ) . '"></div>';
}

add_shortcode(
	'limu_crm_turnstile',
	function () {
		return turnstile_widget( 'crm_form', 'form_fields[lcrm_turnstile]' );
	}
);
add_action(
	'elementor_pro/forms/validation',
	function ( $record, $handler ) {
		if ( ! turnstile_site_key() ) {
			return;
		}
		$id     = (string) $record->get_form_settings( 'id' );
		$mapped = false;
		foreach ( settings()['forms'] as $form ) {
			if ( $form['id'] === $id ) {
					$mapped = true;
					break;
			}
		}
		if ( ! $mapped ) {
			return;
		}
	 // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Elementor owns form validation; this verifies its challenge token independently.
		$token = isset( $_POST['form_fields']['lcrm_turnstile'] ) && is_string( $_POST['form_fields']['lcrm_turnstile'] ) ? sanitize_text_field( wp_unslash( $_POST['form_fields']['lcrm_turnstile'] ) ) : '';
		if ( ! verify_turnstile( $token, 'crm_form' ) ) {
			$handler->add_error_message( 'לא ניתן לאמת את הפנייה. יש לרענן ולנסות שוב.' );
		}
	},
	10,
	2
);
