<?php
/**
 * Verified iCount v3 accounting documents and persistent idempotent intents.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Read the accounting API token only from server configuration.
 *
 * @return string Server secret, never included in public responses.
 */
function icount_token() {
	$token = defined( 'LIMU_CRM_ICOUNT_TOKEN' ) ? constant( 'LIMU_CRM_ICOUNT_TOKEN' ) : getenv( 'LIMU_CRM_ICOUNT_TOKEN' );
	return is_string( $token ) && strlen( $token ) <= 4096 && ! preg_match( '/[\x00-\x20\x7f]/', $token ) ? $token : '';
}
/**
 * Bind verified client mappings and pending operations to the configured account.
 *
 * @return string Private account fingerprint.
 */
function icount_account() {
	$token = icount_token();
	return $token ? hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) : '';
}
/**
 * Return cached connection information without contacting the provider.
 *
 * @return array Safe connection state, with no token or account fingerprint.
 */
function icount_status() {
	$config   = get_option( 'lcrm_icount_config', array() );
	$verified = icount_account() && is_array( $config ) && isset( $config['account'], $config['company'], $config['bank_accounts'], $config['doctypes'] ) && is_string( $config['account'] ) && is_array( $config['company'] ) && is_array( $config['bank_accounts'] ) && is_array( $config['doctypes'] ) && hash_equals( $config['account'], icount_account() );
	return array(
		'configured'    => (bool) icount_token(),
		'automatic'     => true === get_option( 'lcrm_icount_automatic', false ),
		'verified'      => (bool) $verified,
		'company'       => $verified ? $config['company'] : null,
		'bank_accounts' => $verified ? $config['bank_accounts'] : array(),
		'doctypes'      => $verified ? $config['doctypes'] : array(),
	);
}
/**
 * Call only verified, fixed HTTPS API endpoints; never call during a transaction.
 *
 * @param string $method Whitelisted API operation.
 * @param array  $body Validated request payload.
 * @return array|\WP_Error Decoded provider response or a safe error.
 */
function icount_request( $method, $body ) {
	if ( isset( $GLOBALS['lcrm_changed_records'] ) ) {
		return error( 'פעולת iCount מחייבת לסיים את השמירה המקומית לפני השליחה.', 409 );
	}
	if ( ! in_array( $method, array( 'company/info', 'doc/types', 'client/info', 'doc/create', 'doc/info' ), true ) || ! icount_token() ) {
		return error( 'יש להגדיר בשרת טוקן API של iCount.', 503 );
	}
	$response = wp_remote_post(
		'https://api.icount.co.il/api/v3.php/' . $method,
		array(
			'headers'             => array(
				'Authorization' => 'Bearer ' . icount_token(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'                => wp_json_encode( (object) $body ),
			'timeout'             => 25,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 1048576,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return error( 'לא התקבלה תשובה מאומתת מ־iCount. יש לבדוק את מצב הפעולה לפני ניסיון נוסף.', 502 );
	}
	$value = json_decode( wp_remote_retrieve_body( $response ), true, 32 );
	if ( ! is_array( $value ) || ! isset( $value['status'] ) || ! is_bool( $value['status'] ) ) {
		return error( 'תגובת iCount אינה תקינה. יש לבדוק את מצב הפעולה.', 502 );
	}
	return $value;
}
/**
 * Verify the configured company and supported fiscal document types explicitly.
 *
 * @return array|\WP_Error Safe, cached connection state.
 */
function icount_check_connection() {
	if ( ! manager() ) {
		return error( 'אין הרשאה לבצע פעולה זו.', 403 );
	}
	$account = icount_account();
	$company = icount_request( 'company/info', array( 'get_bank_accounts' => true ) );
	$types   = icount_request( 'doc/types', array() );
	if ( is_wp_error( $company ) || is_wp_error( $types ) || empty( $company['status'] ) || empty( $types['status'] ) ) {
		return error( 'לא ניתן לאמת את החיבור ל־iCount. בדקו את פרטי החיבור וההרשאות.', 502 );
	}
	$info = $company['company_info'] ?? array();
	if ( ! is_array( $info ) || empty( $info['vat_id'] ) || empty( $info['businessName'] ) || ! isset( $info['is_vat_exempt'] ) || false !== $info['is_vat_exempt'] ) {
		return error( 'החיבור דורש חשבון iCount של עסק המחויב במע״מ.', 409 );
	}
	$banks = array();
	foreach ( (array) ( $company['bank_accounts'] ?? array() ) as $bank ) {
		if ( is_array( $bank ) && ! empty( $bank['account_id'] ) && ctype_digit( (string) $bank['account_id'] ) ) {
			$banks[] = array(
				'id'    => (int) $bank['account_id'],
				'title' => sanitize_text_field( $bank['title'] ?? 'חשבון בנק' ),
			);
		}
	}
	$available = array();
	foreach ( (array) ( $types['doctypes'] ?? array() ) as $code => $type ) {
		$code = is_array( $type ) ? ( $type['doctype'] ?? $code ) : $code;
		if ( in_array( $code, array( 'deal', 'invoice', 'invrec', 'receipt' ), true ) ) {
			$available[] = $code;
		}
	}
	if ( ! get_option( 'lcrm_icount_namespace' ) ) {
		add_option( 'lcrm_icount_namespace', bin2hex( random_bytes( 16 ) ), '', false );
	}
	$config = array(
		'account'       => $account,
		'company'       => array(
			'name'   => sanitize_text_field( $info['businessName'] ),
			'vat_id' => sanitize_text_field( $info['vat_id'] ),
		),
		'bank_accounts' => $banks,
		'doctypes'      => array_values( array_unique( $available ) ),
	);
	$result = locked(
		static function () use ( $account, $config ) {
			if ( ! $account || ! hash_equals( $account, icount_account() ) ) {
				return error( 'פרטי החיבור השתנו. יש לבדוק את החיבור מחדש.', 409 );
			}
			update_option( 'lcrm_icount_config', $config, false );
			return get_option( 'lcrm_icount_config' ) === $config ? true : error( 'לא ניתן לשמור את אימות החיבור.', 500 );
		}
	);
	if ( is_wp_error( $result ) ) {
		wp_cache_delete( 'lcrm_icount_config', 'options' );
		return $result;
	}
	return icount_status();
}
/**
 * Return a safe institution client mapping for the configured account.
 *
 * @param int $institution Institution post ID.
 * @return array|null Verified mapping or no usable mapping.
 */
function icount_client( $institution ) {
	$client = get_post_meta( $institution, '_lcrm_icount_client', true );
	if ( ! is_array( $client ) || empty( $client['account'] ) || ! hash_equals( $client['account'], icount_account() ) ) {
		return null;
	}
	unset( $client['account'] );
	return $client;
}
/**
 * Preview a fiscal client before the manager explicitly confirms the link.
 *
 * @param int $client_id Explicit fiscal client ID.
 * @return array|\WP_Error Safe identity fields without provider secrets.
 */
function icount_preview_client( $client_id ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה לבצע פעולה זו.', 403 );
	}
	if ( ( ! is_int( $client_id ) && ! is_string( $client_id ) ) || ! preg_match( '/^[1-9]\d{0,9}$/D', (string) $client_id ) || ! icount_status()['verified'] ) {
		return error( 'יש לאמת חיבור ולבחור מזהה לקוח תקין.' );
	}
	$result = icount_request( 'client/info', array( 'client_id' => (int) $client_id ) );
	$info   = is_array( $result ) ? ( $result['client_info'] ?? array() ) : array();
	if ( is_wp_error( $result ) || empty( $result['status'] ) || ! is_array( $info ) || (int) ( $info['client_id'] ?? 0 ) !== (int) $client_id || empty( $info['client_name'] ) || ! is_string( $info['client_name'] ) ) {
		return error( 'לא נמצא לקוח iCount מאומת עם המזהה שנבחר.', 409 );
	}
	return array(
		'id'     => (int) $client_id,
		'name'   => sanitize_text_field( $info['client_name'] ),
		'vat_id' => sanitize_text_field( $info['vat_id'] ?? '' ),
	);
}
/**
 * Link an existing fiscal client only by its explicitly supplied provider ID.
 *
 * @param int $institution Institution post ID.
 * @param int $client_id Explicit fiscal client ID, unrelated to site lead CID.
 * @return array|\WP_Error Safe verified mapping.
 */
function icount_link_client( $institution, $client_id ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה לבצע פעולה זו.', 403 );
	}
	if ( 'institutions' !== get_post_type( $institution ) || ( ! is_int( $client_id ) && ! is_string( $client_id ) ) || ! preg_match( '/^[1-9]\d{0,9}$/D', (string) $client_id ) || ! icount_status()['verified'] ) {
		return error( 'יש לאמת חיבור ולבחור מוסד ומזהה לקוח תקינים.' );
	}
	$account = icount_account();
	$client  = icount_preview_client( $client_id );
	if ( is_wp_error( $client ) ) {
		return $client;
	}
	$client['verified_at'] = current_time( 'mysql' );
	$client['account']     = $account;
	return locked(
		static function () use ( $institution, $account, $client ) {
			if ( ! hash_equals( $account, icount_account() ) ) {
				return error( 'פרטי החיבור השתנו. יש לבדוק את הלקוח מחדש.', 409 );
			}
			$GLOBALS['lcrm_changed_records'][] = $institution;
			update_post_meta( $institution, '_lcrm_icount_client', $client );
			if ( get_post_meta( $institution, '_lcrm_icount_client', true ) !== $client ) {
				return error( 'לא ניתן לשמור את הקישור ללקוח.', 500 );
			}
			audit( 'icount_client_linked', $institution, array( 'client_id' => $client['id'] ) );
			return icount_client( $institution );
		}
	);
}
/**
 * Read all fiscal operations for one approved billing snapshot.
 *
 * @param int $bill Bill post ID.
 * @return array Private outbox; never included in REST responses.
 */
function icount_outbox( $bill ) {
	$value = get_post_meta( $bill, '_lcrm_icount', true );
	return is_array( $value ) ? $value : array();
}
/**
 * Persist an outbox under the existing transaction and invalidate rollback caches.
 *
 * @param int   $bill Bill post ID.
 * @param array $outbox Private operation map.
 * @return true|\WP_Error Persistence outcome.
 */
function icount_save_outbox( $bill, $outbox ) {
	$GLOBALS['lcrm_changed_records'][] = $bill;
	update_post_meta( $bill, '_lcrm_icount', wp_slash( $outbox ) );
	return get_post_meta( $bill, '_lcrm_icount', true ) === $outbox ? true : error( 'לא ניתן לשמור את מצב מסמך iCount.', 500 );
}
/**
 * Strip private payloads, idempotency keys and account fingerprints from an operation.
 *
 * @param array $operation Private outbox operation.
 * @return array Safe document metadata.
 */
function icount_public_document( $operation ) {
	return array_intersect_key( $operation, array_flip( array( 'key', 'state', 'doctype', 'docnum', 'url', 'issued', 'total', 'paydate', 'target' ) ) );
}
/**
 * Return authorized documents for a bill or a single recorded payment.
 *
 * @param int $target Bill or payment post ID.
 * @return array Safe fiscal metadata; institutions see issued documents only.
 */
function icount_documents( $target ) {
	$type = get_post_type( $target );
	$row  = data( $target );
	if ( ! in_array( $type, array( 'lcrm_bill', 'lcrm_payment' ), true ) || empty( $row['institution'] ) || ! owns( $row['institution'] ) ) {
		return array();
	}
	$bill = 'lcrm_payment' === $type ? (int) $row['bill'] : $target;
	$out  = array();
	foreach ( icount_outbox( $bill ) as $operation ) {
		if ( ( 'lcrm_payment' !== $type || (int) $operation['target'] === $target ) && ( manager() || 'issued' === $operation['state'] ) ) {
			$out[] = icount_public_document( $operation );
		}
	}
	return $out;
}
/**
 * Convert immutable integer agorot to an exact decimal representation.
 *
 * @param int $amount Integer agorot.
 * @return string Decimal money with two places.
 */
function icount_decimal( $amount ) {
	return intdiv( $amount, 100 ) . '.' . str_pad( (string) ( $amount % 100 ), 2, '0', STR_PAD_LEFT );
}
/**
 * Validate supplier monetary values without accepting fractional agorot.
 *
 * @param mixed $value Provider numeric value.
 * @return int|null Exact agorot or invalid data.
 */
function icount_money( $value ) {
	if ( is_float( $value ) ) {
		if ( ! is_finite( $value ) || abs( $value * 100 - round( $value * 100 ) ) > 0.000001 ) {
			return null;
		}
		$value = number_format( $value, 2, '.', '' );
	}
	return money( $value );
}
/**
 * Validate payment details before creating any accounting document.
 *
 * @param array $payment Immutable recorded payment.
 * @param array $details Explicit transfer account or cheque details.
 * @return array|\WP_Error Official payment method payload.
 */
function icount_payment_payload( $payment, $details ) {
	$sum = icount_decimal( $payment['amount'] );
	if ( 'cash' === $payment['method'] ) {
		return array( 'cash' => array( 'sum' => $sum ) );
	}
	if ( 'transfer' === $payment['method'] ) {
		$account = $details['account'] ?? '';
		if ( ( ! is_int( $account ) && ! is_string( $account ) ) || ! ctype_digit( (string) $account ) || ! in_array( (int) $account, array_column( icount_status()['bank_accounts'], 'id' ), true ) ) {
			return error( 'יש לבחור את חשבון הבנק המקבל מתוך חשבונות iCount המאומתים.' );
		}
		return array(
			'banktransfer' => array(
				'sum'     => $sum,
				'date'    => $payment['date'],
				'account' => (int) $account,
			),
		);
	}
	if ( 'check' === $payment['method'] ) {
		$cheque = array(
			'sum'  => $sum,
			'date' => $details['check_date'] ?? $payment['date'],
		);
		foreach ( array( 'bank', 'branch', 'account', 'number' ) as $field ) {
			$value = $details[ $field ] ?? '';
			if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[1-9]\d{0,9}$/D', (string) $value ) ) {
				return error( 'להפקת קבלה עבור המחאה דרושים בנק, סניף, חשבון ומספר המחאה.' );
			}
			$cheque[ $field ] = (int) $value;
		}
		return valid_date( $cheque['date'] ) ? array( 'cheques' => array( $cheque ) ) : error( 'תאריך ההמחאה אינו תקין.' );
	}
	return error( 'אמצעי התשלום הזה אינו מחובר עדיין ל־iCount. ניתן להפיק חשבונית מס עבור החיוב בנפרד.', 409 );
}
/**
 * Reserve and freeze one operation under lock before any external side effect.
 *
 * @param int    $bill_id Approved bill ID.
 * @param string $kind Requested document type.
 * @param int    $payment_id Optional recorded payment ID.
 * @param array  $details Explicit receipt details.
 * @return array|\WP_Error Frozen operation or safe error.
 */
function icount_reserve( $bill_id, $kind, $payment_id = 0, $details = array() ) {
	return locked(
		static function () use ( $bill_id, $kind, $payment_id, $details ) {
			$bill   = data( $bill_id );
			$status = icount_status();
			$client = icount_client( $bill['institution'] ?? 0 );
			if ( 'lcrm_bill' !== get_post_type( $bill_id ) || 'approved' !== ( $bill['state'] ?? '' ) || ! $status['verified'] || ! $client ) {
				return error( 'להפקה דרושים חיוב מאושר, חיבור מאומת וקישור המוסד ללקוח iCount.', 409 );
			}
			$outbox = icount_outbox( $bill_id );
			$key    = $payment_id ? 'payment:' . $payment_id : $kind;
			if ( isset( $outbox[ $key ] ) ) {
				return 'issued' === $outbox[ $key ]['state'] ? $outbox[ $key ] : error( 'הפקה זו כבר נשלחה. יש לבדוק את מצב המסמך לפני ניסיון נוסף.', 409 );
			}
			$tax = null;
			foreach ( $outbox as $operation ) {
				if ( ! hash_equals( $operation['account'], icount_account() ) || $operation['payload']['client_id'] !== $client['id'] ) {
					return error( 'קישור הלקוח או חשבון iCount השתנה מאז הפקת מסמך לחיוב. יש להחזיר את הקישור המקורי.', 409 );
				}
				if ( 'invoice' === $kind && 'deal' === $operation['doctype'] && 'issued' !== $operation['state'] ) {
					return error( 'מצב דרישת תשלום קיימת אינו ודאי. יש לברר אותה לפני הפקת חשבונית מס.', 409 );
				}
				if ( in_array( $operation['doctype'], array( 'invoice', 'invrec' ), true ) ) {
					if ( 'issued' !== $operation['state'] ) {
						return error( 'מצב מסמך מס קיים אינו ודאי. יש לברר אותו לפני הפקה נוספת.', 409 );
					}
					$tax = $operation;
				}
			}
			$payment_payload = array();
			$payment         = array();
			if ( $payment_id ) {
				$payment = data( $payment_id );
				if ( 'lcrm_payment' !== get_post_type( $payment_id ) || (int) ( $payment['bill'] ?? 0 ) !== $bill_id ) {
					return error( 'התשלום אינו שייך לחיוב שנבחר.' );
				}
				$payment_payload = icount_payment_payload( $payment, $details );
				if ( is_wp_error( $payment_payload ) ) {
					return $payment_payload;
				}
				if ( $tax ) {
					if ( 'invoice' !== $tax['doctype'] ) {
						return error( 'כבר הופקה חשבונית מס/קבלה עבור החיוב.', 409 );
					}
					$kind = 'receipt';
				} elseif ( (int) $payment['amount'] === (int) $bill['total'] && (int) $bill['paid'] === (int) $bill['total'] ) {
					$kind = 'invrec';
				} else {
					return error( 'לתשלום חלקי יש להפיק תחילה חשבונית מס עבור החיוב, ואז קבלה לתשלום.', 409 );
				}
			} elseif ( 'invoice' === $kind && $tax ) {
				return error( 'כבר קיים מסמך מס עבור החיוב.', 409 );
			} elseif ( 'deal' === $kind && $tax && 'invrec' === $tax['doctype'] ) {
				return error( 'כבר הופקה חשבונית מס/קבלה עבור מלוא התשלום. אין יתרה לדרישת תשלום חדשה.', 409 );
			}
			if ( ! in_array( $kind, $status['doctypes'], true ) || ! in_array( $kind, array( 'deal', 'invoice', 'invrec', 'receipt' ), true ) ) {
				return error( 'סוג המסמך אינו זמין בחשבון iCount.', 409 );
			}
			$namespace = get_option( 'lcrm_icount_namespace' );
			if ( ! is_string( $namespace ) || ! preg_match( '/^[a-f0-9]{32}$/D', $namespace ) ) {
				return error( 'מפתח ההתקנה לחיבור iCount חסר. יש לבדוק את החיבור מחדש.', 503 );
			}
			$sanity = 'lcrm-' . substr( hash( 'sha256', $namespace . ':' . $bill_id . ':' . $key . ':' . icount_account() ), 0, 25 );
			$issued = current_time( 'Y-m-d' );
			$body   = array(
				'doctype'       => $kind,
				'client_id'     => $client['id'],
				'doc_date'      => $issued,
				'currency_code' => 'ILS',
				'vat_percent'   => 18,
				'sanity_string' => $sanity,
				'send_email'    => false,
				'send_sms'      => false,
			);
			if ( 'receipt' !== $kind ) {
				$subtotal = 0;
				$vat      = 0;
				$items    = array();
				foreach ( $bill['lines'] as $line ) {
					if ( ! is_int( $line['price'] ) || ! is_int( $line['vat'] ) || VAT_BP !== $line['vat_bp'] || intdiv( $line['price'] * VAT_BP + 5000, 10000 ) !== $line['vat'] ) {
						return error( 'שורות החיוב המאושר אינן מתאימות להפקת מסמך.' );
					}
					$subtotal += $line['price'];
					$vat      += $line['vat'];
					$items[]   = array(
						'description' => 'ליד לחודש ' . $bill['month'],
						'unitprice'   => icount_decimal( $line['price'] ),
						'quantity'    => 1,
					);
				}
				if ( $subtotal !== $bill['subtotal'] || $vat !== $bill['vat'] || $subtotal + $vat !== $bill['total'] || intdiv( $subtotal * VAT_BP + 5000, 10000 ) !== $vat ) {
					return error( 'עיגול המע״מ של החיוב אינו תואם להפקה ב־iCount. יש לבדוק את החיוב בלי לשנות את הסכום המאושר.', 409 );
				}
				$body['items']         = $items;
				$body['totalsum']      = icount_decimal( $subtotal );
				$body['afterdiscount'] = icount_decimal( $subtotal );
				$body['totalwithvat']  = icount_decimal( $bill['total'] );
			}
			$credit_anchor = '';
			if ( isset( $outbox['deal'] ) && 'issued' === $outbox['deal']['state'] ) {
				$credit_anchor = ! empty( $outbox['deal']['credit_anchor'] ) ? $outbox['deal']['credit_anchor'] : $outbox['deal']['issued'];
			} elseif ( $tax && 'invoice' === $tax['doctype'] ) {
				$credit_anchor = ! empty( $tax['credit_anchor'] ) ? $tax['credit_anchor'] : $tax['issued'];
			}
			if ( in_array( $kind, array( 'deal', 'invoice' ), true ) ) {
				$body['paydate'] = due_date( $credit_anchor ? $credit_anchor : $issued, $bill['credit_days'] );
			}
			$base = 'receipt' === $kind ? $tax : ( $outbox['deal'] ?? null );
			if ( $base && 'issued' === $base['state'] ) {
				$body['based_on'] = array(
					array(
						'doctype' => $base['doctype'],
						'docnum'  => $base['docnum'],
					),
				);
			}
			$body = array_merge( $body, $payment_payload );
			if ( $payment_id ) {
				$body['paid']      = icount_decimal( $payment['amount'] );
				$body['totalpaid'] = icount_decimal( $payment['amount'] );
			}
			$operation      = array(
				'key'           => $key,
				'state'         => 'sending',
				'doctype'       => $kind,
				'target'        => $payment_id ? $payment_id : $bill_id,
				'started'       => time(),
				'issued'        => $issued,
				'paydate'       => $body['paydate'] ?? '',
				'total'         => $payment_id ? $payment['amount'] : $bill['total'],
				'account'       => icount_account(),
				'credit_days'   => $bill['credit_days'],
				'credit_anchor' => in_array( $kind, array( 'deal', 'invoice' ), true ) ? $credit_anchor : '',
				'payload'       => $body,
				'docnum'        => 0,
				'url'           => '',
			);
			$outbox[ $key ] = $operation;
			$saved          = icount_save_outbox( $bill_id, $outbox );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			audit( 'icount_sending', $operation['target'], array( 'doctype' => $kind ) );
			return $operation;
		}
	);
}
/**
 * Accept only HTTPS links on the fiscal provider's own domain.
 *
 * @param mixed $url Provider document link.
 * @return string Safe URL or an empty value.
 */
function icount_document_url( $url ) {
	if ( ! is_string( $url ) || strlen( $url ) > 4096 ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	$host  = strtolower( $parts['host'] ?? '' );
	return 'https' === ( $parts['scheme'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ( ! isset( $parts['port'] ) || 443 === $parts['port'] ) && ( 'icount.co.il' === $host || '.icount.co.il' === substr( $host, -13 ) ) ? esc_url_raw( $url, array( 'https' ) ) : '';
}
/**
 * Parse documented creation-time values without guessing malformed timestamps.
 *
 * @param array $info Verified provider document information.
 * @return string|null Actual creation date in the site timezone.
 */
function icount_issue_date( $info ) {
	$value = $info['timeissued'] ?? null;
	if ( ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^\d{10}$/D', (string) $value ) && (int) $value >= 946684800 && (int) $value <= time() + 300 ) {
		return ( new \DateTimeImmutable( '@' . $value ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}
	if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$/D', $value ) && valid_date( substr( $value, 0, 10 ) ) ) {
		try {
			$date = new \DateTimeImmutable( $value, wp_timezone() );
			return $date->getTimestamp() <= time() + 300 ? $date->setTimezone( wp_timezone() )->format( 'Y-m-d' ) : null;
		} catch ( \Exception $exception ) {
			return null;
		}
	}
	return null;
}
/**
 * Validate the actual provider document against the frozen intent in agorot.
 *
 * @param array $operation Frozen outbox operation.
 * @param int   $docnum Proven provider document number.
 * @return array|\WP_Error Verified issue date and local due date.
 */
function icount_verify_document( $operation, $docnum ) {
	$result = icount_request(
		'doc/info',
		array(
			'doctype'      => $operation['doctype'],
			'docnum'       => $docnum,
			'get_items'    => true,
			'get_payments' => true,
		)
	);
	$info   = is_array( $result ) ? ( $result['doc_info'] ?? array() ) : array();
	$body   = $operation['payload'];
	if ( is_wp_error( $result ) || empty( $result['status'] ) || ! is_array( $info ) || ( $info['doctype'] ?? '' ) !== $operation['doctype'] || (int) ( $info['docnum'] ?? 0 ) !== $docnum || (int) ( $info['client_id'] ?? 0 ) !== $body['client_id'] || ( $info['dateissued'] ?? '' ) !== $body['doc_date'] || 'ILS' !== ( $info['currency_code'] ?? '' ) || ! empty( $info['is_cancelled'] ) || ! empty( $info['is_cancellation'] ) ) {
		return error( 'המסמך ב־iCount עדיין לא אומת מול החיוב. יש לבצע בירור לפני הפקה נוספת.', 409 );
	}
	if ( 'receipt' === $operation['doctype'] ) {
		if ( icount_money( $info['paid'] ?? null ) !== $operation['total'] || icount_money( $info['totalpaid'] ?? null ) !== $operation['total'] ) {
			return error( 'סכום הקבלה ב־iCount אינו תואם לתשלום שנרשם.', 409 );
		}
	} elseif ( icount_money( $body['totalsum'] ) !== icount_money( $info['totalsum'] ?? null ) || icount_money( $body['afterdiscount'] ) !== icount_money( $info['afterdiscount'] ?? null ) || icount_money( $body['totalwithvat'] ) !== icount_money( $info['totalwithvat'] ?? null ) || 18.0 !== (float) ( $info['vat_percent'] ?? -1 ) ) {
		return error( 'סכום המסמך או המע״מ ב־iCount אינו תואם לחיוב המאושר.', 409 );
	}
	if ( isset( $body['paid'] ) && ( icount_money( $body['paid'] ) !== icount_money( $info['paid'] ?? null ) || icount_money( $body['totalpaid'] ) !== icount_money( $info['totalpaid'] ?? null ) ) ) {
		return error( 'סכום התקבול ב־iCount אינו תואם לתשלום שנרשם.', 409 );
	}
	if ( isset( $body['paydate'] ) && ( $info['paydate'] ?? '' ) !== $body['paydate'] ) {
		return error( 'מועד הפירעון ב־iCount אינו תואם לתנאי החיוב.', 409 );
	}
	foreach ( $body['based_on'] ?? array() as $base ) {
		$matched = false;
		foreach ( (array) ( $info['based_on'] ?? array() ) as $candidate ) {
			$matched = $matched || ( is_array( $candidate ) && ( $candidate['doctype'] ?? '' ) === $base['doctype'] && (int) ( $candidate['docnum'] ?? 0 ) === $base['docnum'] );
		}
		if ( ! $matched ) {
			return error( 'קשרי המסמך ב־iCount אינם תואמים לחיוב.', 409 );
		}
	}
	if ( isset( $body['items'] ) ) {
		$items = $info['items'] ?? array();
		if ( ! is_array( $items ) || count( $items ) !== count( $body['items'] ) ) {
			return error( 'שורות המסמך ב־iCount אינן תואמות לחיוב המאושר.', 409 );
		}
		$items = array_values( $items );
		foreach ( $body['items'] as $index => $item ) {
			$actual_item = $items[ $index ];
			if ( ! is_array( $actual_item ) || ( $actual_item['description'] ?? '' ) !== $item['description'] || icount_money( $item['unitprice'] ) !== icount_money( $actual_item['unitprice'] ?? null ) || 1.0 !== (float) ( $actual_item['quantity'] ?? 0 ) ) {
				return error( 'שורות המסמך ב־iCount אינן תואמות לחיוב המאושר.', 409 );
			}
		}
	}
	foreach ( array( 'cash', 'banktransfer' ) as $method ) {
		if ( isset( $body[ $method ] ) ) {
			$actual_payment = $info[ $method ] ?? array();
			if ( ! is_array( $actual_payment ) || icount_money( $body[ $method ]['sum'] ) !== icount_money( $actual_payment['sum'] ?? null ) || ( 'banktransfer' === $method && ( (int) ( $actual_payment['account'] ?? 0 ) !== $body[ $method ]['account'] || ( $actual_payment['date'] ?? '' ) !== $body[ $method ]['date'] ) ) ) {
				return error( 'פרטי התקבול ב־iCount אינם תואמים לתשלום שנרשם.', 409 );
			}
		}
	}
	if ( isset( $body['cheques'] ) ) {
		$cheques = $info['cheques'] ?? array();
		if ( ! is_array( $cheques ) || 1 !== count( $cheques ) ) {
			return error( 'פרטי ההמחאה ב־iCount אינם תואמים לתשלום שנרשם.', 409 );
		}
		$actual_cheque = array_values( $cheques )[0];
		$cheque        = $body['cheques'][0];
		if ( ! is_array( $actual_cheque ) || icount_money( $cheque['sum'] ) !== icount_money( $actual_cheque['sum'] ?? null ) || ( $actual_cheque['date'] ?? '' ) !== $cheque['date'] ) {
			return error( 'פרטי ההמחאה ב־iCount אינם תואמים לתשלום שנרשם.', 409 );
		}
		foreach ( array( 'bank', 'branch', 'account', 'number' ) as $field ) {
			if ( (int) ( $actual_cheque[ $field ] ?? 0 ) !== $cheque[ $field ] ) {
				return error( 'פרטי ההמחאה ב־iCount אינם תואמים לתשלום שנרשם.', 409 );
			}
		}
	}
	$actual = icount_issue_date( $info );
	if ( ! $actual && current_time( 'Y-m-d' ) !== $body['doc_date'] ) {
		return error( 'מועד ההפקה בפועל טרם אומת. יש לברר את המסמך מול iCount.', 409 );
	}
	$actual = $actual ? $actual : $body['doc_date'];
	$anchor = ! empty( $operation['credit_anchor'] ) ? $operation['credit_anchor'] : $actual;
	if ( isset( $body['paydate'] ) && due_date( $anchor, $operation['credit_days'] ) !== ( $info['paydate'] ?? '' ) ) {
		return error( 'מועד הפירעון אינו תואם לתנאי האשראי ממועד ההפקה בפועל. יש לברר מול iCount.', 409 );
	}
	return array(
		'issued'  => $actual,
		'paydate' => isset( $body['paydate'] ) ? due_date( $anchor, $operation['credit_days'] ) : '',
	);
}
/**
 * Send an immutable intent or recover it using the same provider idempotency key.
 *
 * @param int   $bill_id Approved bill ID.
 * @param array $operation Frozen operation already committed locally.
 * @param bool  $recover Explicit recovery of a previously uncertain operation.
 * @return array|\WP_Error Safe issued metadata or an uncertain outcome.
 */
function icount_send( $bill_id, $operation, $recover = false ) {
	if ( 'issued' === $operation['state'] ) {
		return icount_public_document( $operation );
	}
	if ( ! hash_equals( $operation['account'], icount_account() ) ) {
		return error( 'פרטי חשבון iCount השתנו. יש להחזיר את החיבור המקורי לפני בירור המסמך.', 409 );
	}
	if ( $recover && ! empty( $operation['docnum'] ) ) {
		$result = array(
			'status'       => true,
			'doctype'      => $operation['doctype'],
			'client_id'    => $operation['payload']['client_id'],
			'docnum'       => $operation['docnum'],
			'doc_copy_url' => $operation['url'],
		);
	} elseif ( $recover && current_time( 'Y-m-d' ) !== $operation['payload']['doc_date'] ) {
		return error( 'הפעולה ממתינה לבירור מול iCount; אין ניסיון הפקה בתאריך אחר.', 409 );
	} else {
		$result = icount_request( 'doc/create', $operation['payload'] );
	}
	$proven = is_array( $result ) && ( true === $result['status'] || 'doc_exists_based_on_sanity_string' === ( $result['reason'] ?? '' ) ) && ( $result['doctype'] ?? '' ) === $operation['doctype'] && (int) ( $result['client_id'] ?? 0 ) === $operation['payload']['client_id'] && ! empty( $result['docnum'] ) && ctype_digit( (string) $result['docnum'] );
	$docnum = $proven ? (int) $result['docnum'] : 0;
	$valid  = $proven ? icount_verify_document( $operation, $docnum ) : error( 'תוצאת ההפקה אינה ודאית. יש לבצע בירור ב־iCount לפני ניסיון נוסף.', 409 );
	$state  = is_wp_error( $valid ) ? 'unknown' : 'issued';
	$saved  = locked(
		static function () use ( $bill_id, $operation, $state, $docnum, $result, $valid ) {
			$outbox  = icount_outbox( $bill_id );
			$current = $outbox[ $operation['key'] ] ?? null;
			if ( ! $current || $current['payload'] !== $operation['payload'] || ! hash_equals( $current['account'], $operation['account'] ) ) {
				return error( 'מצב ההפקה השתנה. יש לבדוק את המסמך ב־iCount.', 409 );
			}
			if ( 'issued' === $current['state'] ) {
				return icount_public_document( $current );
			}
			if ( ! is_wp_error( $valid ) ) {
				$current['issued']  = $valid['issued'];
				$current['paydate'] = $valid['paydate'];
			}
			$current['state']            = $state;
			$current['docnum']           = $docnum;
			$current['url']              = is_array( $result ) ? icount_document_url( $result['doc_copy_url'] ?? $result['doc_url'] ?? '' ) : '';
			$outbox[ $operation['key'] ] = $current;
			$outcome                     = icount_save_outbox( $bill_id, $outbox );
			if ( is_wp_error( $outcome ) ) {
				return $outcome;
			}
			if ( 'issued' === $state && in_array( $current['doctype'], array( 'deal', 'invoice' ), true ) ) {
				$bill                   = data( $bill_id );
				$bill['document_state'] = 'issued';
				$bill['issued']         = ! empty( $current['credit_anchor'] ) ? $current['credit_anchor'] : $current['issued'];
				$bill['due']            = $current['paydate'];
				$outcome                = save_record( 'lcrm_bill', $bill, $bill_id );
				if ( is_wp_error( $outcome ) ) {
					return $outcome;
				}
			}
			audit(
				'icount_' . $state,
				$current['target'],
				array(
					'doctype' => $current['doctype'],
					'docnum'  => $docnum,
				)
			);
			return icount_public_document( $current );
		}
	);
	return is_wp_error( $saved ) ? $saved : ( is_wp_error( $valid ) ? $valid : $saved );
}
/**
 * Issue a demand or tax invoice only after the local approval transaction commits.
 *
 * @param int    $bill_id Approved bill ID.
 * @param string $doctype Deal or invoice.
 * @return array|\WP_Error Safe issued document metadata.
 */
function icount_issue_bill( $bill_id, $doctype ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה לבצע פעולה זו.', 403 );
	}
	if ( ! in_array( $doctype, array( 'deal', 'invoice' ), true ) ) {
		return error( 'יש לבחור דרישת תשלום או חשבונית מס.' );
	}
	$operation = icount_reserve( $bill_id, $doctype );
	return is_wp_error( $operation ) ? $operation : icount_send( $bill_id, $operation );
}
/**
 * Issue an explicitly enabled automatic demand from the trusted cron worker only.
 *
 * @param int $bill_id Approved bill from a newly queued approval event.
 * @return array|\WP_Error Safe issued metadata or an automation error.
 */
function icount_issue_automatic_demand( $bill_id ) {
	if ( ! wp_doing_cron() || true !== get_option( 'lcrm_icount_automatic', false ) ) {
		return error( 'הפקת דרישות אוטומטית אינה פעילה.', 403 );
	}
	$operation = icount_reserve( $bill_id, 'deal' );
	return is_wp_error( $operation ) ? $operation : icount_send( $bill_id, $operation );
}
/**
 * Issue one receipt, or an invoice/receipt for a single full payment without tax docs.
 *
 * @param int   $payment_id Immutable recorded payment ID.
 * @param array $details Explicit transfer account or cheque details.
 * @return array|\WP_Error Safe document metadata.
 */
function icount_issue_payment( $payment_id, $details = array() ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה לבצע פעולה זו.', 403 );
	}
	$payment = data( $payment_id );
	if ( 'lcrm_payment' !== get_post_type( $payment_id ) || empty( $payment['bill'] ) ) {
		return error( 'תשלום לא נמצא.', 404 );
	}
	$operation = icount_reserve( (int) $payment['bill'], 'receipt', $payment_id, $details );
	return is_wp_error( $operation ) ? $operation : icount_send( (int) $payment['bill'], $operation );
}
/**
 * Resolve an uncertain operation only through the saved payload and provider sanity proof.
 *
 * @param int    $target Original bill or recorded payment ID.
 * @param string $doctype Original document type.
 * @param int    $docnum Optional expected number; never used to attach an arbitrary document.
 * @return array|\WP_Error Issued safe metadata or unresolved state.
 */
function icount_reconcile( $target, $doctype, $docnum = 0 ) {
	if ( ! manager() ) {
		return error( 'אין הרשאה לבצע פעולה זו.', 403 );
	}
	$row     = data( $target );
	$payment = 'lcrm_payment' === get_post_type( $target );
	$bill_id = $payment ? (int) ( $row['bill'] ?? 0 ) : $target;
	$key     = $payment ? 'payment:' . $target : $doctype;
	$result  = locked(
		static function () use ( $bill_id, $key, $doctype, $docnum ) {
			$outbox    = icount_outbox( $bill_id );
			$operation = $outbox[ $key ] ?? null;
			if ( ! $operation || $doctype !== $operation['doctype'] || ! hash_equals( $operation['account'], icount_account() ) || ( $docnum && (int) $operation['docnum'] !== $docnum ) ) {
				return error( 'אין פעולת הפקה תואמת לבירור.', 409 );
			}
			if ( 'issued' === $operation['state'] ) {
				return $operation;
			}
			if ( 'sending' === $operation['state'] && (int) ( $operation['started'] ?? time() ) > time() - 120 ) {
				return error( 'ההפקה עדיין בתהליך. יש להמתין לפני בירור.', 409 );
			}
			if ( empty( $operation['docnum'] ) && current_time( 'Y-m-d' ) !== $operation['payload']['doc_date'] ) {
				return error( 'הפעולה ממתינה לבירור מול iCount; אין ניסיון הפקה בתאריך אחר.', 409 );
			}
			$operation['state']   = 'sending';
			$operation['started'] = time();
			$outbox[ $key ]       = $operation;
			$saved                = icount_save_outbox( $bill_id, $outbox );
			return is_wp_error( $saved ) ? $saved : $operation;
		}
	);
	return is_wp_error( $result ) ? $result : icount_send( $bill_id, $result, true );
}
