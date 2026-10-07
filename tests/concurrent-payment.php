<?php
/** Retry/concurrency probe against the integration fixture in a disposable database. */
$root = getenv( 'LIMU_WP_ROOT' );
if ( ! $root ) { exit( 1 ); }
require $root . '/wp-load.php';
if ( 'limu_test' !== DB_NAME ) { exit( 1 ); }
wp_set_current_user( get_user_by( 'login', 'crm-admin' )->ID );
$id = 0;
foreach ( LimuCRM\query_records( 'lcrm_bill' )['items'] as $bill ) { if ( 8850 === $bill['total'] ) { $id = $bill['id']; } }
if ( ! $id ) { exit( 1 ); }
if ( in_array( '--verify', $argv, true ) ) {
 $rows = LimuCRM\query_records( 'lcrm_payment', array( 'bill' => $id ) )['items'];
 $matches = array_filter( $rows, fn( $p ) => 'concurrent-payment-test-00001' === $p['request_key'] );
 if ( 1 !== count( $matches ) || 500 !== LimuCRM\data( $id )['paid'] ) { exit( 1 ); }
 echo "One payment; balance changed once.\n"; exit;
}
$result = LimuCRM\locked( fn() => LimuCRM\record_payment( $id, array( 'amount' => '5', 'date' => current_time( 'Y-m-d' ), 'method' => 'transfer', 'request_key' => 'concurrent-payment-test-00001' ) ) );
if ( is_wp_error( $result ) ) { exit( 1 ); }
echo $result['id'] . "\n";
