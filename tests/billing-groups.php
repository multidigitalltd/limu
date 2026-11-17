<?php
/** Shared billing regressions on isolated, synthetic fixtures only. */
define('DISABLE_WP_CRON',true);
define('WP_HTTP_BLOCK_EXTERNAL',true);
require getenv('LIMU_WP_ROOT') . '/wp-load.php';
if ('limu_test' !== DB_NAME) { exit(1); }
register_post_type('institutions',array('public'=>false));
$bg_admin=get_users(array('role'=>'administrator','number'=>1))[0]->ID;
wp_set_current_user($bg_admin);
if(($argv[1]??'')==='--payment-worker'){
 $payload=json_decode(getenv('LIMU_GROUP_TEST_PAYMENT'),true);
 if(!is_array($payload)||!is_int($payload['bill']??null)||!is_array($payload['payment']??null))exit(2);
 $result=LimuCRM\locked(fn()=>LimuCRM\record_payment($payload['bill'],$payload['payment']));
 if(is_wp_error($result)){fwrite(STDERR,$result->get_error_message());exit(1);}echo $result['id'];exit(0);
}

$bg_original=get_option('lcrm_billing_groups',null);
$bg_settings=get_option('lcrm_settings');
$bg_token=getenv('LIMU_CRM_ICOUNT_TOKEN');$bg_icount=array();foreach(array('lcrm_icount_config','lcrm_icount_namespace') as $key)$bg_icount[$key]=get_option($key,null);
$bg_mock=null;
$bg_posts=array();$bg_users=array();$bg_count=0;
add_action('wp_insert_post',function($id,$post,$update)use(&$bg_posts){if(!$update)$bg_posts[]=$id;},10,3);
function bg_check($ok,$label){global $bg_count;if(!$ok)throw new RuntimeException($label);++$bg_count;echo "PASS $label\n";}
function bg_request($data=null,$nonce=true){$r=new WP_REST_Request(null===$data?'GET':'POST','/limu-crm/v1/billing-groups');if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if(null!==$data){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));}return rest_do_request($r);}
function bg_read($route,$params=array()){$r=new WP_REST_Request('GET','/limu-crm/v1/'.$route);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));foreach($params as $k=>$v)$r->set_param($k,$v);return rest_do_request($r);}
function bg_post($route,$data){$r=new WP_REST_Request('POST','/limu-crm/v1/'.$route);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));return rest_do_request($r);}
function bg_ok($result){if(is_wp_error($result))throw new RuntimeException($result->get_error_message());return $result;}
try {
 update_option('lcrm_billing_groups',array(),false);
 $settings=LimuCRM\settings();$settings['start_date']='2020-01-01';update_option('lcrm_settings',$settings,false);
 $a=wp_insert_post(array('post_type'=>'institutions','post_status'=>'publish','post_title'=>'Group A'));
 $b=wp_insert_post(array('post_type'=>'institutions','post_status'=>'publish','post_title'=>'Group B'));
 $c=wp_insert_post(array('post_type'=>'institutions','post_status'=>'publish','post_title'=>'Unrelated C'));
 foreach(array($a=>1000,$b=>2000,$c=>500) as $i=>$price)update_post_meta($i,'_lcrm_agreement',array('credit_days'=>30,'rates'=>array(array('from'=>'2020-01-01','price'=>$price))));
 $bg_baseline=bg_read('summary')->get_data();
 $id=wp_generate_uuid4();$input=array('id'=>$id,'name'=>'Shared payer','members'=>array($a,$b),'payer'=>$a,'from'=>current_time('Y-m'),'credit_days'=>14);
 bg_check(bg_request($input,false)->get_status()===403,'Group changes require REST nonce');
 $fail_audit=function($empty,$post){return ($post['post_type']??'')==='lcrm_audit'?true:$empty;};
 add_filter('wp_insert_post_empty_content',$fail_audit,10,2);$failed=bg_request($input);remove_filter('wp_insert_post_empty_content',$fail_audit,10);
 bg_check($failed->get_status()===500&&LimuCRM\billing_groups()===array(),'Audit failure rolls back group option and option caches');
 bg_check(bg_request($input)->get_status()===200,'Manager creates a future-effective group');
 $saved=LimuCRM\billing_groups();bg_check(bg_request($input)->get_status()===200&&LimuCRM\billing_groups()===$saved,'Creation replay preserves one identical group');
 $bad=$input;$bad['id']=wp_generate_uuid4();bg_check(bg_request($bad)->get_status()===409,'Overlapping membership is rejected');
 foreach(array('members'=>array($a,$a),'payer'=>$c,'credit_days'=>true,'from'=>'2020-01','name'=>'<b></b>') as $key=>$value){$bad=$input;$bad['id']=wp_generate_uuid4();$bad[$key]=$value;bg_check(bg_request($bad)->get_status()>=400,'Reject invalid group '.$key);}
 $month=(new DateTimeImmutable(current_time('Y-m-d'),wp_timezone()))->modify('first day of last month')->format('Y-m');
 // Simulate a group created in a previous month; production API forbids retroactive configuration.
 $saved[$id]['from']=$month;update_option('lcrm_billing_groups',$saved,false);
 $lead=array('name'=>'Shared lead','phone'=>'0505671234','email'=>'shared-fixture@example.test','date'=>$month.'-10 12:00:00','form'=>'Group fixture');
 $da=bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_delivery($lead,$a,'group-fixture:'.wp_generate_uuid4(),false)));
 $db=bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_delivery($lead,$b,'group-fixture:'.wp_generate_uuid4(),false)));
 bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_delivery(array_merge($lead,array('date'=>$month.'-11 12:00:00')),$a,'group-fixture:'.wp_generate_uuid4(),false)));
 bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_delivery(array_merge($lead,array('phone'=>'0505671235','email'=>'history@example.test')),$b,'group-fixture:'.wp_generate_uuid4(),true)));
 $excluded=bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_delivery(array_merge($lead,array('phone'=>'0505671236','email'=>'excluded@example.test')),$b,'group-fixture:'.wp_generate_uuid4(),false)));
 $excluded['billing_excluded']=true;bg_ok(LimuCRM\locked(fn()=>LimuCRM\save_record('lcrm_delivery',$excluded,$excluded['id'])));
 $oldmonth=(new DateTimeImmutable($month.'-01'))->modify('-1 month')->format('Y-m');
 // Create an independent pre-group approved snapshot without changing the current group's members.
 $before_groups=LimuCRM\billing_groups();update_option('lcrm_billing_groups',array(),false);
 bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_delivery(array_merge($lead,array('date'=>$oldmonth.'-10 12:00:00','phone'=>'0501111222','email'=>'old-group@example.test')),$a,'group-old:'.wp_generate_uuid4(),false)));
 $old_draft=bg_ok(LimuCRM\locked(fn()=>LimuCRM\prepare_bill($a,$oldmonth)));$old_bill=bg_ok(LimuCRM\locked(fn()=>LimuCRM\approve_bill($old_draft['id'])));
 update_option('lcrm_billing_groups',$before_groups,false);$bg_baseline=bg_read('summary')->get_data();
 $draft=bg_ok(LimuCRM\locked(fn()=>LimuCRM\prepare_bill($a,$month)));
 bg_check(count($draft['lines'])===2&&$draft['total']===3540,'One draft bills the same source separately at each institution tariff');
 bg_check($draft['duplicates']===1&&$draft['historical']===1,'Duplicates, exclusions and report-only history never add debt');
 $batch=bg_post('prepare',array('institutions'=>array($a,$b),'month'=>$month))->get_data();bg_check(count($batch['results'])===1&&$batch['results'][0]['id']===$draft['id'],'Batch preparation resolves both members to one group result');
 $again=bg_ok(LimuCRM\locked(fn()=>LimuCRM\prepare_bill($b,$month)));bg_check($again['id']===$draft['id'],'Preparing the second member returns the same bill');
 bg_check(LimuCRM\query_records('lcrm_bill',array('billing_group'=>$id),1,30)['total']===1,'Group appears once despite multiple membership index rows');
 $scoped=LimuCRM\query_records('lcrm_bill',array('institution'=>$b),1,30)['items'][0];bg_check($scoped['total']===2360&&count($scoped['lines'])===1&&$scoped['scoped_group'],'Institution-filtered report uses only that institution share');
 $original_agreement=LimuCRM\agreement($b);update_post_meta($b,'_lcrm_agreement',array('credit_days'=>30,'rates'=>array(array('from'=>current_time('Y-m').'-01','price'=>2000))));
 $missing=LimuCRM\locked(fn()=>LimuCRM\prepare_bill($a,$month));
 bg_check(is_wp_error($missing)&&LimuCRM\data($draft['id'])===$draft,'Missing historical rate blocks the whole group without overwriting the draft');
 update_post_meta($b,'_lcrm_agreement',$original_agreement);
 $unaware=bg_post('approve',array('ids'=>array($draft['id'])))->get_data();bg_check(isset($unaware['results'][0]['error'])&&LimuCRM\data($draft['id'])['state']==='draft','An old client cannot approve a group without group-aware acknowledgement');
 $approved=bg_post('approve',array('ids'=>array($draft['id']),'shared_billing'=>true));$bill=$approved->get_data()['results'][0];bg_check($approved->get_status()===200&&$bill['state']==='approved','Group-aware approval succeeds through the real REST endpoint');
 bg_check(LimuCRM\data($da['id'])['bill']===$bill['id']&&LimuCRM\data($db['id'])['bill']===$bill['id'],'Approval locks both institutions deliveries into the shared bill');
 bg_check($bill['credit_days']===14,'Group has one explicit credit term');
 bg_check(is_wp_error(LimuCRM\locked(fn()=>LimuCRM\agreement_write($b,$month.'-01','9.00',null))),'Approved group month locks each member retrospective tariff');
 bg_check(is_wp_error(LimuCRM\locked(fn()=>LimuCRM\record_delivery(array_merge($lead,array('phone'=>'0505671237','email'=>'late@example.test')),$b,'group-fixture:'.wp_generate_uuid4(),false))),'Late capture cannot mutate an approved shared period');
 $payment=array('amount'=>'10.01','date'=>current_time('Y-m-d'),'method'=>'transfer','reference'=>'Synthetic only','request_key'=>wp_generate_uuid4());
 bg_check(bg_post('payment',array_merge($payment,array('bill'=>$bill['id'])))->get_status()===409,'Old clients cannot unintentionally apply a single-institution payment to a group');
 $before_payment=LimuCRM\data($bill['id']);
 $fail_snapshot=function($check,$object,$key,$value)use($bill){return $object===$bill['id']&&$key==='_lcrm_data'&&($value['paid']??0)>0?false:$check;};
 add_filter('update_post_metadata',$fail_snapshot,10,4);$failed=LimuCRM\locked(fn()=>LimuCRM\record_payment($bill['id'],$payment));remove_filter('update_post_metadata',$fail_snapshot,10);
 bg_check(is_wp_error($failed)&&LimuCRM\data($bill['id'])===$before_payment&&LimuCRM\query_records('lcrm_payment',array('bill'=>$bill['id']),1,30)['total']===0,'Snapshot write failure rolls back payment, shares and indexes together');
 $audit_args=array('post_type'=>'lcrm_audit','post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids','cache_results'=>false);
 $audits_before=get_posts($audit_args);$worker_env=getenv('LIMU_GROUP_TEST_PAYMENT');putenv('LIMU_GROUP_TEST_PAYMENT='.wp_json_encode(array('bill'=>$bill['id'],'payment'=>$payment)));
 $workers=array();for($n=0;$n<2;$n++){
  $pipes=array();$process=proc_open(array(PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--payment-worker'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
  if(!is_resource($process))throw new RuntimeException('Cannot start concurrent payment worker');fclose($pipes[0]);$workers[]=array($process,$pipes);
 }
 $payment_ids=array();foreach($workers as $worker){$output=stream_get_contents($worker[1][1]);$errors=stream_get_contents($worker[1][2]);fclose($worker[1][1]);fclose($worker[1][2]);$code=proc_close($worker[0]);if($code!==0||!ctype_digit($output))throw new RuntimeException('Concurrent payment failed: '.$errors);$payment_ids[]=(int)$output;}
 if(false===$worker_env)putenv('LIMU_GROUP_TEST_PAYMENT');else putenv('LIMU_GROUP_TEST_PAYMENT='.$worker_env);
 clean_post_cache($bill['id']);$bg_posts=array_merge($bg_posts,array_diff(get_posts($audit_args),$audits_before),array_unique($payment_ids));
 bg_check($payment_ids[0]===$payment_ids[1]&&LimuCRM\query_records('lcrm_payment',array('bill'=>$bill['id']),1,30)['total']===1,'Concurrent payment replays create one payment and one allocation');
 $p=LimuCRM\raw_records(array($payment_ids[0]))[0];
 bg_check($p['allocations'][$a]===334&&$p['allocations'][$b]===667&&array_sum($p['allocations'])===1001,'Partial payment uses exact proportional cents and largest remainder');
 $clean=LimuCRM\data($bill['id']);$corrupt=$clean;$corrupt['shares'][$a]['paid']--;$corrupt['shares'][$b]['paid']++;update_post_meta($bill['id'],'_lcrm_data',$corrupt);
 bg_check(is_wp_error(LimuCRM\locked(fn()=>LimuCRM\record_payment($bill['id'],$payment))),'Even a replay fails closed when institution shares disagree with the authoritative ledger');
 update_post_meta($bill['id'],'_lcrm_data',$clean);
 $failed_payment=$payment;$failed_payment['request_key']=wp_generate_uuid4();$failed_payment['amount']='0.03';
 $fail_commit=fn($query)=>$query==='COMMIT'?'SELECT missing_group_column FROM missing_group_table':$query;
 global $wpdb;$old_suppress=$wpdb->suppress_errors(true);add_filter('query',$fail_commit);
 $failed=LimuCRM\locked(fn()=>LimuCRM\record_payment($bill['id'],$failed_payment));remove_filter('query',$fail_commit);$wpdb->suppress_errors($old_suppress);
 bg_check(is_wp_error($failed)&&LimuCRM\data($bill['id'])===$clean&&LimuCRM\query_records('lcrm_payment',array('bill'=>$bill['id']),1,30)['total']===1,'Failed COMMIT restores payment ledger and each institution share');
 $replay=bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_payment($bill['id'],$payment)));bg_check($replay['id']===$p['id'],'Payment replay cannot allocate money twice');
 $wrong=$payment;$wrong['amount']='10.02';bg_check(is_wp_error(LimuCRM\locked(fn()=>LimuCRM\record_payment($bill['id'],$wrong))),'Conflicting replay is rejected');
 $rest=$payment;$rest['request_key']=wp_generate_uuid4();$rest['amount']='25.40';bg_check(is_wp_error(LimuCRM\locked(fn()=>LimuCRM\record_payment($bill['id'],$rest))),'Overpayment rejected for whole group');
 $rest['amount']='25.39';bg_ok(LimuCRM\locked(fn()=>LimuCRM\record_payment($bill['id'],$rest)));
 bg_check(is_wp_error(LimuCRM\save_record('lcrm_bill',LimuCRM\data($bill['id']),$bill['id'])),'Shared snapshots cannot be written without the transaction guard');
 $paid=LimuCRM\data($bill['id']);bg_check($paid['paid']===3540&&$paid['shares'][$a]['paid']===1180&&$paid['shares'][$b]['paid']===2360,'Final payment reconciles all institution balances exactly');
 bg_check(bg_request(array('id'=>$id,'cancelled'=>true))->get_status()===409,'A billed group cannot be cancelled or erased');
 bg_check(bg_request(array('id'=>$id,'until'=>current_time('Y-m')))->get_status()===200,'Group can end prospectively while preserving bills');
 bg_check(LimuCRM\billing_group_for($b,(new DateTimeImmutable(current_time('Y-m').'-01'))->modify('+1 month')->format('Y-m'))===null,'After closing month institutions return to independent billing');
 bg_check(LimuCRM\data($bill['id'])===$paid,'Configuration changes do not rewrite the approved snapshot');
 bg_check(LimuCRM\data($old_bill['id'])===$old_bill,'Independent approved history is preserved byte-for-byte');
 // Entire provider interaction is mocked: no requests reach a real iCount account.
 putenv('LIMU_CRM_ICOUNT_TOKEN=group-fixture-token');
 update_option('lcrm_icount_config',array('account'=>LimuCRM\icount_account(),'company'=>array('name'=>'Synthetic'),'bank_accounts'=>array(array('id'=>7,'name'=>'Test')),'doctypes'=>array('invoice','receipt','deal','invrec')),false);
 update_option('lcrm_icount_namespace',str_repeat('a',32),false);
 update_post_meta($a,'_lcrm_icount_client',array('account'=>LimuCRM\icount_account(),'id'=>700,'name'=>'Payer A'));
 update_post_meta($b,'_lcrm_icount_client',array('account'=>LimuCRM\icount_account(),'id'=>800,'name'=>'Different client B'));
 $provider=array();$creates=0;
 $bg_mock=function($pre,$args,$url)use(&$provider,&$creates){
  if(strpos($url,'https://api.icount.co.il/api/v3.php/')!==0)return new WP_Error('blocked','Non-provider network blocked');
  if(isset($GLOBALS['lcrm_changed_records']))throw new RuntimeException('Provider called inside transaction');
  $method=substr($url,strlen('https://api.icount.co.il/api/v3.php/'));$body=json_decode($args['body'],true);
  $response=function($data){return array('response'=>array('code'=>200),'body'=>wp_json_encode($data),'headers'=>array());};
  if($method==='doc/create'){
   if($body['send_email']!==false||$body['send_sms']!==false||$body['client_id']!==700)throw new RuntimeException('Unsafe payer/document settings');
   $number=9000+(++$creates);$provider[$number]=$body;return $response(array('status'=>true,'doctype'=>$body['doctype'],'client_id'=>700,'docnum'=>$number,'doc_copy_url'=>'https://app.icount.co.il/copy/'.$number));
  }
  if($method==='doc/info'){
   $payload=$provider[$body['docnum']];$payload['docnum']=$body['docnum'];$payload['dateissued']=$payload['doc_date'];$payload['timeissued']=time();$payload['is_cancelled']=false;$payload['is_cancellation']=false;return $response(array('status'=>true,'doc_info'=>$payload));
  }
  throw new RuntimeException('Unexpected provider call');
 };
 add_filter('pre_http_request',$bg_mock,10,3);
 bg_check(bg_post('icount/issue',array('bill'=>$bill['id'],'doctype'=>'invoice'))->get_status()===409&&$creates===0,'Unaware client cannot issue a combined financial document');
 $doc_response=bg_post('icount/issue',array('bill'=>$bill['id'],'doctype'=>'invoice','shared_billing'=>true));$document=$doc_response->get_data();bg_check($doc_response->get_status()===200,'Group-aware invoice REST request succeeds');
 bg_check($document['state']==='issued'&&$creates===1&&count($provider[9001]['items'])===2&&$provider[9001]['totalwithvat']==='35.40','One iCount invoice includes both institutions at their own rates for the chosen payer');
 bg_check(strpos($provider[9001]['items'][0]['description'],'Group A')!==false&&strpos($provider[9001]['items'][1]['description'],'Group B')!==false,'Invoice lines retain institution identity');
 bg_ok(LimuCRM\icount_issue_bill($bill['id'],'invoice'));bg_check($creates===1,'Invoice replay cannot issue a duplicate document');
 bg_check(bg_post('icount/payment',array('payment'=>$p['id'],'account'=>7))->get_status()===409,'Unaware client cannot issue a combined receipt');
 $receipt_response=bg_post('icount/payment',array('payment'=>$p['id'],'account'=>7,'shared_billing'=>true));$receipt=$receipt_response->get_data();bg_check($receipt_response->get_status()===200,'Group-aware receipt REST request succeeds');
 bg_check($receipt['state']==='issued'&&$provider[9002]['paid']==='10.01'&&$provider[9002]['based_on'][0]['docnum']===9001,'Partial payment produces one receipt based on the shared invoice');
 $retrospective=$input;$retrospective['name']='Changed';bg_check(bg_request($retrospective)->get_status()>=400,'Existing group payer/membership cannot be rewritten retrospectively');
 $user=wp_create_user('group-member-'.wp_generate_password(8,false),'fixture-'.wp_generate_password(20));$bg_users[]=$user;$u=new WP_User($user);$u->set_role('subscriber');$u->add_cap('lcrm_view');update_user_meta($user,'_lcrm_institutions',array($b));wp_set_current_user($user);
 bg_check(bg_request()->get_status()===403&&bg_request($input)->get_status()===403,'Institution cannot read or mutate billing-group settings');
 $member=bg_read('bills')->get_data()['items'][0];bg_check($member['total']===2360&&$member['paid']===2360&&$member['institution']===$b&&!isset($member['shares'],$member['billing_members'],$member['lines']),'Institution sees only its share with internal membership/lines removed');
 $payments=bg_read('payments')->get_data()['items'];bg_check(array_sum(array_column($payments,'amount'))===2360&&!isset($payments[0]['allocations'])&&!isset($payments[0]['reference']),'Institution payment report exposes only its allocated money');
 $summary=bg_read('summary')->get_data();bg_check($summary['approved']===2360&&$summary['paid']===2360,'Member dashboard does not count group totals or payment twice');
 bg_check(bg_read('bills',array('institution'=>$a))->get_status()===403,'Member cannot request another institution share');
 update_user_meta($user,'_lcrm_institutions',array($a));
 $payer_view=bg_read('bills')->get_data()['items'];$payer_group=array_values(array_filter($payer_view,fn($row)=>$row['id']===$bill['id']))[0];
 bg_check($payer_group['total']===1180&&$payer_group['documents']===array(),'A payer-only representative cannot read shared documents exposing another institution');
 $report=bg_read('report',array('target'=>'bills','institution'=>$a))->get_data();
 bg_check(strpos(wp_json_encode($report),'Group B')===false,'Print/report payload cannot disclose the other institution name');
 update_user_meta($user,'_lcrm_institutions',array($a,$b));
 $both=bg_read('bills')->get_data();$both_group=array_values(array_filter($both['items'],fn($row)=>$row['id']===$bill['id']));
 bg_check(count($both_group)===1&&$both_group[0]['total']===3540&&count($both_group[0]['documents'])===2,'A representative authorized for all members sees one whole bill and its documents');
 wp_set_current_user($bg_admin);
 $summary=bg_read('summary')->get_data();bg_check($summary['approved']===$bg_baseline['approved']+3540&&$summary['paid']===$bg_baseline['paid']+3540,'Manager dashboard counts one shared approved bill and one total payment balance');
 for($n=1;$n<=30;$n++)foreach(array(1,2,3,7,19,100) as $den)foreach(array(0,1,$den) as $weight){list($q,$rem)=LimuCRM\billing_ratio($n,$weight,$den);if($q!==intdiv($n*$weight,$den)||$rem!==($n*$weight)%$den)throw new RuntimeException('Integer ratio mismatch');}
 list($q,$rem)=LimuCRM\billing_ratio(PHP_INT_MAX-1,PHP_INT_MAX-1,PHP_INT_MAX);bg_check($q===PHP_INT_MAX-2&&$rem===1,'Proportional allocation remains exact at 64-bit multiplication overflow boundary');
 bg_check(true,'Small quotient/remainder cases agree with integer arithmetic');
 echo "OK $bg_count shared billing assertions\n";
} finally {
 if($bg_mock)remove_filter('pre_http_request',$bg_mock,10);
 if(false===$bg_token)putenv('LIMU_CRM_ICOUNT_TOKEN');else putenv('LIMU_CRM_ICOUNT_TOKEN='.$bg_token);
 foreach($bg_icount as $key=>$value){if(null===$value)delete_option($key);else update_option($key,$value,false);}
 wp_set_current_user($bg_admin);
 foreach(array_reverse($bg_posts) as $post)wp_delete_post($post,true);
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($bg_users as $user)wp_delete_user($user);
 if(null===$bg_original)delete_option('lcrm_billing_groups');else update_option('lcrm_billing_groups',$bg_original,false);
 update_option('lcrm_settings',$bg_settings,false);
}
