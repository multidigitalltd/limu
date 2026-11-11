<?php
/** Audit retention regression checks, restricted to disposable fixtures. */
require getenv('LIMU_WP_ROOT') . '/wp-load.php';
if ('limu_test' !== DB_NAME) { exit(1); }
wp_set_current_user(get_users(array('role'=>'administrator','number'=>1))[0]->ID);
$original = get_option('lcrm_settings');
$ids = array();
function audit_check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS $label\n"; }
function retention_request($days, $include = true) {
 $s=LimuCRM\settings(); $input=array('start_date'=>$s['start_date'],'automatic'=>$s['automatic']);
 if($include)$input['audit_retention_days']=$days;
 $r=new WP_REST_Request('POST','/limu-crm/v1/settings');$r->set_header('Content-Type','application/json');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode($input));return rest_do_request($r);
}
try {
 foreach(array(-1,1.5,'30',true,null,36501,array()) as $bad) audit_check(retention_request($bad)->get_status()===400,'Invalid retention rejected: '.wp_json_encode($bad));
 audit_check(retention_request(30)->get_status()===200,'Manager can save retention with nonce');
 audit_check(retention_request(0,false)->get_status()===200 && LimuCRM\settings()['audit_retention_days']===30,'Cached clients preserve retention');
 foreach(array('old'=>'-31 days','fresh'=>'-1 day','business'=>'-31 days') as $kind=>$age) {
 $id=wp_insert_post(array('post_type'=>$kind==='business'?'lcrm_bill':'lcrm_audit','post_status'=>'private','post_title'=>'retention fixture','post_date_gmt'=>gmdate('Y-m-d H:i:s',strtotime($age)),'post_date'=>get_date_from_gmt(gmdate('Y-m-d H:i:s',strtotime($age)))));$ids[$kind]=$id;
 }
 retention_request(0);audit_check(LimuCRM\cleanup_audit()===0 && get_post($ids['old']),'Zero disables deletion');
 retention_request(30);LimuCRM\cleanup_audit();audit_check(!get_post($ids['old']),'Expired audit deleted permanently');audit_check((bool)get_post($ids['fresh']),'Recent audit retained');audit_check((bool)get_post($ids['business']),'Old business record retained');
 audit_check(LimuCRM\cleanup_audit()===0,'Repeated cleanup is idempotent');
 for ($n=0;$n<101;$n++) {
 $id=wp_insert_post(array('post_type'=>'lcrm_audit','post_status'=>'private','post_title'=>'old batch','post_date_gmt'=>gmdate('Y-m-d H:i:s',strtotime('-40 days')),'post_date'=>get_date_from_gmt(gmdate('Y-m-d H:i:s',strtotime('-40 days')))));$ids[]=$id;
 }
 audit_check(LimuCRM\cleanup_audit()===100,'Cleanup is bounded to 100 records');
 audit_check((bool)wp_next_scheduled('lcrm_audit_cleanup_batch'),'Full batch schedules continuation');
 audit_check(LimuCRM\cleanup_audit()===1,'Continuation clears remaining expired record');
 wp_set_current_user(0);audit_check(retention_request(1)->get_status()===403,'Anonymous cannot change retention');
} finally {foreach($ids as $id) wp_delete_post($id,true);update_option('lcrm_settings',$original,false);wp_clear_scheduled_hook('lcrm_audit_cleanup_batch');}
