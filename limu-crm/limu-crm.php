<?php
/**
 * Plugin Name: Limu CRM — Multi Digital
 * Description: פורטל מוסדות, לידים וחיובים חודשי עם בקרת הרשאות. חיבור iCount בשלב נפרד.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: limu-crm
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'LIMU_CRM_FILE', __FILE__ );
require_once __DIR__ . '/includes/domain.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/capture.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/turnstile.php';

/** Register private records and the portal route without loading frontend assets globally. */
function register() {
	foreach ( array( 'lcrm_contact', 'lcrm_delivery', 'lcrm_bill', 'lcrm_payment', 'lcrm_audit' ) as $type ) {
		register_post_type(
			$type,
			array(
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'supports'            => array( 'title' ),
				'can_export'          => false,
				'exclude_from_search' => true,
				'map_meta_cap'        => false,
				'capabilities'        => array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'manage_options' ),
			)
		);
	}
	add_rewrite_rule( '^crm/?$', 'index.php?limu_crm=1', 'top' );
}
add_action( 'init', __NAMESPACE__ . '\\register' );
add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'limu_crm';
		return $vars;
	}
);
register_activation_hook(
	__FILE__,
	function () {
		register();
		add_role(
			'limu_institution',
			'נציג מוסד — CRM',
			array(
				'read'      => true,
				'lcrm_view' => true,
			)
		);
		if ( ! wp_next_scheduled( 'lcrm_daily' ) ) {
			wp_schedule_event( time() + 60, 'daily', 'lcrm_daily' );
		}
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'lcrm_daily' );
		flush_rewrite_rules();
	}
);
add_action(
	'template_redirect',
	function () {
		if ( ! get_query_var( 'limu_crm' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( 'post' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) && ! is_user_logged_in() ) {
			$nonce = isset( $_POST['_lcrm_login_nonce'] ) && is_string( $_POST['_lcrm_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_lcrm_login_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'lcrm_login' ) ) {
				$GLOBALS['lcrm_login_error'] = 'הבקשה פגה. יש לרענן ולנסות שוב.';
			} else {
					$login = isset( $_POST['log'] ) && is_string( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must be passed unchanged to WordPress authentication.
				$password      = isset( $_POST['pwd'] ) && is_string( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '';
					$challenge = isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
				if ( (int) get_transient( login_rate_key( $login ) ) >= 10 || ( turnstile_site_key() && ! verify_turnstile( $challenge, 'crm_login' ) ) ) {
					$key = login_rate_key( $login );
					set_transient( $key, min( 10, (int) get_transient( $key ) + 1 ), 15 * MINUTE_IN_SECONDS );
					$user = new \WP_Error( 'lcrm_challenge', 'לא ניתן לאמת את הכניסה.' );
				} else {
					$user = wp_signon(
						array(
							'user_login'    => $login,
							'user_password' => $password,
							'remember'      => false,
						),
						is_ssl()
					);
				}
				if ( is_wp_error( $user ) ) {
					$GLOBALS['lcrm_login_error'] = 'לא ניתן להתחבר. בדקו את הפרטים או נסו שוב מאוחר יותר.';
				} else {
					wp_safe_redirect( home_url( '/crm/' ) );
					exit;
				}
			}
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff' );
		wp_enqueue_style( 'limu-crm', plugins_url( 'assets/crm.min.css', LIMU_CRM_FILE ), array(), '0.1.0' );
		wp_enqueue_script(
			'limu-crm-access',
			plugins_url( 'assets/access.min.js', LIMU_CRM_FILE ),
			array(),
			'0.1.0',
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		if ( can_view() ) {
			wp_enqueue_script(
				'limu-crm',
				plugins_url( 'assets/crm.min.js', LIMU_CRM_FILE ),
				array(),
				'0.1.0',
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_add_inline_script(
				'limu-crm',
				'window.LimuCRM=' . wp_json_encode(
					array(
						'api'   => esc_url_raw( rest_url( 'limu-crm/v1/' ) ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
					)
				) . ';',
				'before'
			);
		}
		include __DIR__ . '/templates/portal.php';
		exit;
	}
);
add_action(
	'lcrm_daily',
	function () {
		$today = new \DateTimeImmutable( 'now', wp_timezone() );
		$month = $today->modify( 'first day of last month' )->format( 'Y-m' );
		locked(
			function () use ( $month ) {
				$institutions = get_posts(
					array(
						'post_type'      => 'institutions',
						'post_status'    => array( 'publish', 'draft' ),
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				foreach ( $institutions as $id ) {
					if ( ! agreement( $id ) ) {
						continue;
					}
					$result = attempt(
						function () use ( $id, $month ) {
							return prepare_bill( $id, $month );
						}
					);
					if ( ! is_wp_error( $result ) && settings()['automatic'] && 'draft' === $result['state'] ) {
						$approved = attempt(
							function () use ( $result ) {
											return approve_bill( $result['id'] );
							}
						);
						if ( is_wp_error( $approved ) ) {
												audit( 'automatic_error', $id, array( 'operation' => 'approve' ) );
						}
					}
				}
				return true;
			}
		);
	}
);
