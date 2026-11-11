<?php
require getenv('LIMU_WP_ROOT') . '/wp-load.php';
if (DB_NAME !== 'limu_test') { exit(1); }
wp_set_current_user(get_users(array('role'=>'administrator','number'=>1))[0]->ID);
register_post_type('institutions',array('public'=>false));
$ids=array(); $original=get_option('lcrm_settings');
function verify_crm($ok,$label) { if (!$ok) {throw new RuntimeException($label);} echo "PASS $label\n"; }
try {
$s=LimuCRM\settings(); $s['start_date']='2020-01-01';update_option('lcrm_settings',$s,false);
$i=wp_insert_post(array('post_type'=>'institutions','post_status'=>'publish','post_title'=>'Debt fixture'));$ids[]=$i;
update_post_meta($i,'_lcrm_agreement',array('rates'=>array(array('from'=>'2025-02-01','price'=>1000))));
foreach(array('unique','duplicate','excluded','historical','missing','billed','before') as $kind) {
$d=LimuCRM\save_record('lcrm_delivery',array('institution'=>$i,'date'=> $kind==='before'?'2019-01-01 12:00:00':($kind==='missing'?'2025-01-01 12:00:00':'2025-03-01 12:00:00'),'month'=>$kind==='before'?'2019-01':($kind==='missing'?'2025-01':'2025-03'),'source'=>'debt-fixture:'.$kind,'state'=>$kind==='historical'?'historical':'sent','duplicate_of'=>$kind==='duplicate'?1:0,'billing_excluded'=>$kind==='excluded','bill'=>$kind==='billed'?999:0,'phone_key'=>'','email_key'=>'')); $ids[]=$d['id'];
}
$b=LimuCRM\save_record('lcrm_bill',array('institution'=>$i,'month'=>'2025-03','state'=>'approved','total'=>1180,'paid'=>500,'due'=>'2025-04-01'));$ids[]=$b['id'];
$r=new WP_REST_Request('GET','/limu-crm/v1/summary');$r->set_param('institution',$i);
$out=LimuCRM\summary($r);
verify_crm($out['unbilled']===1180,'Only eligible unbilled live lead contributes');
verify_crm($out['balance']===1860,'Approved balance and payment counted once');
verify_crm($out['missing_rate_leads']===1,'Future tariff never applied to earlier lead');
foreach(array('duplicates'=>1,'unique'=>6) as $status=>$expected) {
 foreach(array('deliveries','report','export') as $route) {
 $q=new WP_REST_Request('GET','/limu-crm/v1/'.$route);$q->set_param('institution',$i);$q->set_param('duplicate_status',$status);$q->set_param('target','deliveries');
 $v=LimuCRM\read_api($q);verify_crm(!is_wp_error($v),"$route accepts $status");
 if($route==='deliveries')verify_crm($v['total']===$expected,"$status filtered before pagination");
 }
}
$q->set_param('duplicate_status','bad');verify_crm(is_wp_error(LimuCRM\read_api($q)),'Invalid duplicate filter rejected');
} finally {foreach(array_reverse($ids) as $id)wp_delete_post($id,true);update_option('lcrm_settings',$original,false);}
