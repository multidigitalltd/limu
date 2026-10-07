<?php
/**
 * Manager-only iCount actions. Network operations run outside CRM transactions.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		foreach ( array( 'check', 'client', 'client-preview', 'bill', 'issue', 'payment', 'reconcile', 'automatic' ) as $action ) {
			register_rest_route(
				'limu-crm/v1',
				'/icount/' . $action,
				array(
					'methods'             => 'POST',
					'permission_callback' => __NAMESPACE__ . '\\permission',
					'callback'            => __NAMESPACE__ . '\\icount_api',
				)
			);
		}
	}
);

/**
 * Validate a narrow JSON action and delegate its financial rules to the adapter.
 *
 * @param \WP_REST_Request $request Authenticated manager request.
 * @return mixed Action result or safe validation error.
 */
function icount_api( $request ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה.', 403 );
	}
	if ( strlen( $request->get_body() ) > 4096 ) {
		return error( 'הבקשה גדולה מדי.', 413 );
	}
	$decoded = json_decode( $request->get_body() );
	$input   = $request->get_json_params();
	if ( ! is_object( $decoded ) || ! is_array( $input ) ) {
		return error( 'בקשה לא תקינה.' );
	}
	$action = basename( $request->get_route() );
	$fields = array(
		'check'          => array(),
		'client'         => array( 'institution', 'client_id' ),
		'client-preview' => array( 'client_id' ),
		'bill'           => array( 'bill' ),
		'issue'          => array( 'bill', 'doctype' ),
		'payment'        => array( 'payment', 'account', 'bank', 'branch', 'number', 'check_date' ),
		'reconcile'      => array( 'target', 'doctype', 'docnum' ),
		'automatic'      => array( 'enabled' ),
	);
	if ( ! isset( $fields[ $action ] ) ) {
		return error( 'פעולה לא מוכרת.', 404 );
	}
	foreach ( $input as $key => $value ) {
		if ( ! in_array( $key, $fields[ $action ], true ) || ! is_scalar( $value ) || ( is_string( $value ) && ( strlen( $value ) > 200 || false !== strpos( $value, "\0" ) ) ) ) {
			return error( 'שדה לא תקין.' );
		}
		if ( in_array( $key, array( 'institution', 'client_id', 'bill', 'payment', 'target', 'docnum' ), true ) && ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[1-9]\d{0,9}$/D', (string) $value ) ) ) {
			return error( 'מזהה לא תקין.' );
		}
	}
	if ( 'check' === $action ) {
		return icount_check_connection();
	}
	if ( 'client-preview' === $action ) {
		return icount_preview_client( absint( $input['client_id'] ?? 0 ) );
	}
	if ( 'client' === $action ) {
		return icount_link_client( absint( $input['institution'] ?? 0 ), absint( $input['client_id'] ?? 0 ) );
	}
	if ( 'bill' === $action ) {
		$id = absint( $input['bill'] ?? 0 );
		if ( 'lcrm_bill' !== get_post_type( $id ) ) {
			return error( 'חיוב לא נמצא.', 404 );
		}
		$payments          = query_records( 'lcrm_payment', array( 'bill' => $id ), 1, 5000 );
		$payments['items'] = array_map( __NAMESPACE__ . '\\public_item', $payments['items'] );
		return array(
			'bill'     => public_item( data( $id ) ),
			'payments' => $payments['items'],
		);
	}
	if ( 'issue' === $action ) {
		return icount_issue_bill( absint( $input['bill'] ?? 0 ), $input['doctype'] ?? '' );
	}
	if ( 'payment' === $action ) {
		$id = absint( $input['payment'] ?? 0 );
		unset( $input['payment'] );
		return icount_issue_payment( $id, $input );
	}
	if ( 'reconcile' === $action ) {
		return icount_reconcile( absint( $input['target'] ?? 0 ), $input['doctype'] ?? '', absint( $input['docnum'] ?? 0 ) );
	}
	if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) {
		return error( 'הגדרת אוטומציה לא תקינה.' );
	}
	return locked(
		function () use ( $input ) {
			update_option( 'lcrm_icount_automatic', $input['enabled'], false );
			if ( get_option( 'lcrm_icount_automatic' ) !== $input['enabled'] ) {
				return error( 'שמירת ההגדרה נכשלה.', 500 );
			}
			audit( 'icount_automatic_changed', 0, array( 'enabled' => $input['enabled'] ) );
			return icount_status();
		}
	);
}

/**
 * Queue future demand issuance after the approval transaction committed.
 *
 * @param array $ids Newly approved bill IDs.
 * @return void
 */
function icount_queue_demands( $ids ) {
	if ( ! get_option( 'lcrm_icount_automatic', false ) ) {
		return;
	}
	foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
		if ( $id && ! wp_next_scheduled( 'lcrm_icount_demand', array( $id ) ) ) {
			$queued = wp_schedule_single_event( time() + 1, 'lcrm_icount_demand', array( $id ), true );
			if ( is_wp_error( $queued ) || false === $queued ) {
				locked(
					function () use ( $id ) {
						audit( 'icount_automatic_error', $id, array( 'operation' => 'schedule' ) );
						return true;
					}
				);
			}
		}
	}
}

add_action(
	'lcrm_icount_demand',
	function ( $id ) {
		// Turning automation off also cancels pending work; unresolved actions never resend.
		if ( ! get_option( 'lcrm_icount_automatic', false ) ) {
			return;
		}
		$result = icount_issue_automatic_demand( absint( $id ) );
		if ( is_wp_error( $result ) ) {
			locked(
				function () use ( $id ) {
					audit( 'icount_automatic_error', absint( $id ) );
					return true;
				}
			);
		}
	}
);
