<?php
declare(strict_types=1);

/**
 * Tea Shop BD — Operations Command Center V2 / Round 1 E2E
 * Tests Regional Command, Daily Check-in, Visit Planner, Renewal Alerts and Launch War Room.
 * All test identities/outlet/records are temporary and removed at shutdown.
 */

$configFile=getenv('HOME').'/teashop-os-config.php';
$baseUrl=rtrim((string)(getenv('TSB_BASE_URL') ?: 'https://teashop.bd'),'/');
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI_ONLY\n");exit(2);}
if(!extension_loaded('curl')){fwrite(STDERR,"CURL_EXTENSION_REQUIRED\n");exit(3);}
if(!is_file($configFile)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(4);}

$config=require $configFile;
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);

function fail(string $message): never { throw new RuntimeException($message); }
function ok(bool $condition,string $message): void { if(!$condition) fail($message); }

final class ApiClient {
 private string $base;
 private CurlHandle $ch;
 private ?string $csrf=null;
 private array $cookies=[];
 public function __construct(string $base){
  $this->base=$base;$ch=curl_init();if($ch===false)fail('CURL_INIT_FAILED');$this->ch=$ch;
  curl_setopt_array($this->ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false]);
 }
 public function __destruct(){curl_close($this->ch);}
 private function cookieHeader(): string {$p=[];foreach($this->cookies as $k=>$v)$p[]=$k.'='.$v;return implode('; ',$p);}
 public function request(string $method,string $route,?array $body=null): array {
  $parts=explode('?',$route,2);$url=$this->base.'/api/index.php?route='.rawurlencode($parts[0]).(isset($parts[1])?'&'.$parts[1]:'');
  $headers=['Accept: application/json'];if($body!==null)$headers[]='Content-Type: application/json';if($this->csrf!==null&&strtoupper($method)!=='GET')$headers[]='X-CSRF-Token: '.$this->csrf;
  curl_setopt_array($this->ch,[
   CURLOPT_URL=>$url,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,
   CURLOPT_POSTFIELDS=>$body!==null?json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
   CURLOPT_COOKIE=>$this->cookieHeader(),
   CURLOPT_HEADERFUNCTION=>function($ch,string $line): int {
    if(strncasecmp($line,'Set-Cookie:',11)===0){$cookie=trim(substr($line,11));$pair=explode(';',$cookie,2)[0]??'';if(str_contains($pair,'=')){[$name,$value]=explode('=',$pair,2);$name=trim($name);$value=trim($value);if($name!=='')$this->cookies[$name]=$value;}}
    return strlen($line);
   },
  ]);
  $raw=curl_exec($this->ch);$error=curl_error($this->ch);$status=(int)curl_getinfo($this->ch,CURLINFO_RESPONSE_CODE);
  if($raw===false)fail('HTTP_TRANSPORT_FAILED: '.$error);
  $data=json_decode((string)$raw,true);if(!is_array($data))fail('NON_JSON_RESPONSE route='.$route.' status='.$status);
  if(isset($data['csrf'])&&is_string($data['csrf'])&&$data['csrf']!=='')$this->csrf=$data['csrf'];
  return [$status,$data];
 }
 public function expect(string $method,string $route,?array $body,int $status=200,?string $code=null): array {
  [$got,$data]=$this->request($method,$route,$body);
  if($got!==$status)fail("HTTP_EXPECTATION_FAILED {$route} expected={$status} got={$got} code=".($data['code']??'none'));
  if($code!==null&&($data['code']??null)!==$code)fail("CODE_EXPECTATION_FAILED {$route} expected={$code} got=".($data['code']??'none'));
  if($status<400&&(($data['ok']??false)!==true))fail("API_NOT_OK {$route}");
  return $data;
 }
 public function login(string $email,string $password): void {
  $r=$this->expect('POST','login',['email'=>$email,'password'=>$password],200);
  ok(isset($this->cookies['TSBOS'])&&$this->cookies['TSBOS']!=='','LOGIN_SESSION_COOKIE_MISSING');
  $me=$this->expect('GET','me',null,200);ok(!empty($me['csrf']),'ME_CSRF_MISSING');$this->csrf=(string)$me['csrf'];
  ok((int)($me['user']['id']??0)===(int)($r['user']['id']??0),'SESSION_USER_MISMATCH');
 }
}

$suffix=strtolower(bin2hex(random_bytes(5)));
$password='V2r1!'.bin2hex(random_bytes(10)).'Aa7';
$ownerEmail="v2r1-owner-{$suffix}@teashop.bd";
$opsEmail="v2r1-ops-{$suffix}@teashop.bd";
$financeEmail="v2r1-finance-{$suffix}@teashop.bd";
$outletCode='V2R1-'.strtoupper($suffix);
$outletName='V2 ROUND1 OUTLET '.$suffix;
$division='V2 Division '.strtoupper($suffix);
$district='V2 District '.strtoupper($suffix);
$ownerId=$opsId=$financeId=$franchiseId=$contractId=$visitId=$taskId=null;$cleaned=false;

$cleanup=function() use (&$cleaned,$pdo,&$ownerId,&$opsId,&$financeId,&$franchiseId,$ownerEmail,$opsEmail,$financeEmail,$outletCode,$outletName): void {
 if($cleaned)return;$cleaned=true;
 try{
  $pdo->beginTransaction();
  $fid=(int)($franchiseId??0);
  if($fid<=0){$q=$pdo->prepare("SELECT id FROM franchises WHERE code=? LIMIT 1");$q->execute([$outletCode]);$fid=(int)($q->fetchColumn()?:0);}
  $ids=array_values(array_filter([(int)($ownerId??0),(int)($opsId??0),(int)($financeId??0)]));
  if($fid>0){
   $pdo->prepare("DELETE FROM support_ticket_updates WHERE support_ticket_id IN (SELECT id FROM support_tickets WHERE franchise_id=?)")->execute([$fid]);
   foreach(['operations_alerts','operations_tasks','field_visits','support_tickets','outlet_communications','outlet_compliance_checks','marketing_executions','outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts','outlet_daily_checkins','outlet_contracts','outlet_health_checks','outlet_training_records','outlet_staff','outlet_timeline','outlet_checklist_items','outlet_pipeline','outlet_profiles'] as $table){
    $pdo->prepare("DELETE FROM {$table} WHERE franchise_id=?")->execute([$fid]);
   }
   $pdo->prepare("DELETE FROM documents WHERE reference_type IN('franchise','outlet') AND reference_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM franchises WHERE id=?")->execute([$fid]);
  }
  if($ids){
   $marks=implode(',',array_fill(0,count($ids),'?'));
   $pdo->prepare("DELETE FROM audit_logs WHERE user_id IN ({$marks})")->execute($ids);
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE user_id IN ({$marks})")->execute($ids);
   $pdo->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($ids);
  }else{
   $pdo->prepare("DELETE FROM users WHERE email IN(?,?,?)")->execute([$ownerEmail,$opsEmail,$financeEmail]);
  }
  $like='%'.$outletName.'%';$pdo->prepare("DELETE FROM notifications WHERE title LIKE ? OR message LIKE ?")->execute([$like,$like]);
  $pdo->commit();
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  fwrite(STDERR,"CLEANUP_WARNING ".$e->getMessage()."\n");
 }
};
register_shutdown_function($cleanup);

try{
 echo "=== OPERATIONS V2 ROUND 1 E2E ===\n";
 echo "harness_version=1-daily-operations-core\n";
 echo "base_url={$baseUrl}\n";

 $products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
 ok($products===99,'PRODUCT_COUNT_CHANGED_'.$products);ok($roles>=12,'ROLES_MISSING');
 foreach(['outlet_daily_checkins','outlet_contracts'] as $table){$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);ok((int)$q->fetchColumn()===1,'TABLE_MISSING_'.$table);}
 echo "PRECONDITION=PASS products={$products} roles={$roles} tables=2/2\n";

 $roleIds=[];foreach(['OWNER','OPERATIONS','FINANCE'] as $code){$q=$pdo->prepare("SELECT id FROM roles WHERE code=?");$q->execute([$code]);$roleIds[$code]=(int)($q->fetchColumn()?:0);ok($roleIds[$code]>0,'ROLE_MISSING_'.$code);}
 $ins=$pdo->prepare("INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,0,1)");
 $ins->execute([$roleIds['OWNER'],'V2 R1 Test Owner',$ownerEmail,password_hash($password,PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
 $ins->execute([$roleIds['OPERATIONS'],'V2 R1 Test Operations',$opsEmail,password_hash($password,PASSWORD_DEFAULT)]);$opsId=(int)$pdo->lastInsertId();
 $ins->execute([$roleIds['FINANCE'],'V2 R1 Test Finance',$financeEmail,password_hash($password,PASSWORD_DEFAULT)]);$financeId=(int)$pdo->lastInsertId();

 $owner=new ApiClient($baseUrl);$owner->login($ownerEmail,$password);
 $ops=new ApiClient($baseUrl);$ops->login($opsEmail,$password);
 $finance=new ApiClient($baseUrl);$finance->login($financeEmail,$password);
 $finance->expect('GET','operations.round1',null,403,'ROLE_DENIED');
 echo "AUTH_RBAC=PASS owner_operations finance_denied\n";

 $created=$owner->expect('POST','franchise.create',[
  'code'=>$outletCode,'name'=>$outletName,'division'=>$division,'district'=>$district,'upazila'=>'V2 Upazila',
  'address'=>'Temporary Operations V2 Round 1','owner_name'=>'Temporary Franchisee','owner_phone'=>'01700000003',
  'owner_email'=>"v2r1-{$suffix}@example.invalid",'territory_code'=>'V2-'.$suffix,'margin_tier'=>'Starter','margin_percent'=>25
 ],201);
 $franchiseId=(int)$created['id'];ok($franchiseId>0,'OUTLET_CREATE_FAILED');

 $r1=$ops->expect('GET','operations.round1',null,200);
 $launch=array_values(array_filter($r1['launches']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));
 ok(count($launch)===1,'LAUNCH_ROOM_MISSING');ok(($launch[0]['readiness_state']??'')==='blocked','LAUNCH_BLOCKER_STATE_FAILED');ok(trim((string)($launch[0]['blockers']??''))!=='','LAUNCH_BLOCKERS_EMPTY');
 $regional=array_values(array_filter($r1['regional']??[],fn($r)=>($r['division']??'')===$division&&($r['district']??'')===$district));
 ok(count($regional)===1,'REGIONAL_COMMAND_ROW_MISSING');
 echo "REGIONAL_COMMAND_LAUNCH_WAR_ROOM=PASS readiness=".$launch[0]['readiness_score']." blockers=".$launch[0]['blockers']."\n";

 $today=date('Y-m-d');$now=date('Y-m-d\TH:i');
 $check=$ops->expect('POST','operations.checkin.save',[
  'franchise_id'=>$franchiseId,'checkin_date'=>$today,'opening_status'=>'on_time','opened_at'=>$now,'closing_status'=>'pending',
  'opening_photo_ref'=>'e2e://opening-proof','manager_note'=>'Round 1 daily opening check'
 ],200);
 $checkId=(int)$check['id'];ok($checkId>0,'CHECKIN_ID_MISSING');
 $check2=$ops->expect('POST','operations.checkin.save',[
  'franchise_id'=>$franchiseId,'checkin_date'=>$today,'opening_status'=>'on_time','opened_at'=>$now,'closing_status'=>'on_time',
  'closed_at'=>$now,'opening_photo_ref'=>'e2e://opening-proof','closing_photo_ref'=>'e2e://closing-proof','manager_note'=>'Round 1 open and close verified'
 ],200);
 ok((int)$check2['id']===$checkId,'CHECKIN_UPSERT_DUPLICATED');
 $r1=$ops->expect('GET','operations.round1',null,200);
 $checkRows=array_values(array_filter($r1['checkins']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));
 ok(count($checkRows)===1&&($checkRows[0]['opening_status']??'')==='on_time'&&($checkRows[0]['closing_status']??'')==='on_time','DAILY_CHECKIN_READBACK_FAILED');
 echo "DAILY_CHECKIN=PASS opening=on_time closing=on_time evidence=PASS\n";

 $scheduled=date('Y-m-d\TH:i',strtotime('+1 day'));
 $visit=$ops->expect('POST','operations.visit.save',[
  'franchise_id'=>$franchiseId,'visit_type'=>'launch','status'=>'scheduled','scheduled_at'=>$scheduled,'visitor_user_id'=>$opsId
 ],200);
 $visitId=(int)$visit['id'];ok($visitId>0,'VISIT_ID_MISSING');
 $r1=$ops->expect('GET','operations.round1',null,200);
 $visits=array_values(array_filter($r1['visits']??[],fn($r)=>(int)($r['id']??0)===$visitId));
 ok(count($visits)===1&&($visits[0]['planner_status']??'')==='next_7d','VISIT_PLANNER_FAILED');
 echo "FIELD_VISIT_PLANNER=PASS visit_id={$visitId} next_7d=PASS\n";

 $expiry=date('Y-m-d',strtotime('+5 days'));
 $contract=$ops->expect('POST','operations.contract.save',[
  'franchise_id'=>$franchiseId,'contract_type'=>'lease','document_no'=>'E2E-LEASE-'.$suffix,'start_date'=>$today,'expiry_date'=>$expiry,
  'renewal_status'=>'active','reminder_days'=>30,'evidence_ref'=>'e2e://lease','owner_note'=>'Round 1 renewal watch'
 ],200);
 $contractId=(int)$contract['id'];ok($contractId>0&&($contract['renewal_status']??'')==='due','CONTRACT_DUE_STATUS_FAILED');
 $r1=$ops->expect('GET','operations.round1',null,200);
 $contracts=array_values(array_filter($r1['contracts']??[],fn($r)=>(int)($r['id']??0)===$contractId));
 ok(count($contracts)===1&&($contracts[0]['renewal_health']??'')==='critical','RENEWAL_HEALTH_FAILED');
 $ops->expect('POST','operations.alerts.refresh',['franchise_id'=>$franchiseId],200);
 $alerts=$ops->expect('GET','operations.alerts',null,200);
 $contractAlerts=array_values(array_filter($alerts['alerts']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId&&($r['alert_type']??'')==='contract_expiry'));
 ok(count($contractAlerts)===1&&($contractAlerts[0]['status']??'')==='open','CONTRACT_ALERT_MISSING');
 echo "CONTRACT_RENEWAL_ALERT=PASS days=5 severity=".$contractAlerts[0]['severity']."\n";

 $updated=$ops->expect('POST','operations.contract.save',[
  'id'=>$contractId,'franchise_id'=>$franchiseId,'contract_type'=>'lease','document_no'=>'E2E-LEASE-'.$suffix,'start_date'=>$today,'expiry_date'=>$expiry,
  'renewal_status'=>'renewed','reminder_days'=>30,'evidence_ref'=>'e2e://lease-renewed','owner_note'=>'Renewal completed'
 ],200);
 ok(($updated['renewal_status']??'')==='renewed','CONTRACT_RENEWED_SAVE_FAILED');
 $ops->expect('POST','operations.alerts.refresh',['franchise_id'=>$franchiseId],200);
 $alerts=$ops->expect('GET','operations.alerts',null,200);
 $contractAlerts=array_values(array_filter($alerts['alerts']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId&&($r['alert_type']??'')==='contract_expiry'));
 ok(count($contractAlerts)===1&&($contractAlerts[0]['status']??'')==='resolved','CONTRACT_ALERT_RESOLVE_FAILED');
 echo "RENEWAL_ALERT_LIFECYCLE=PASS open_to_resolved\n";

 $task=$ops->expect('POST','operations.task.create',[
  'franchise_id'=>$franchiseId,'task_type'=>'launch','title'=>'Launch blocker · '.$outletName,'detail'=>$launch[0]['blockers'],'priority'=>'high','assigned_user_id'=>$opsId
 ],201);
 $taskId=(int)$task['id'];$work=$ops->expect('GET','operations.workboard',null,200);
 $tasks=array_values(array_filter($work['tasks']??[],fn($r)=>(int)($r['id']??0)===$taskId));
 ok(count($tasks)===1&&($tasks[0]['status']??'')==='open','LAUNCH_TASK_WORKBOARD_FAILED');
 echo "LAUNCH_BLOCKER_TASK=PASS task_id={$taskId}\n";

 $q=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id IN(?,?,?)");$q->execute([$ownerId,$opsId,$financeId]);$audit=(int)$q->fetchColumn();
 ok($audit>=8,'AUDIT_COVERAGE_LOW_'.$audit);
 echo "AUDIT_LOG=PASS rows={$audit}\n";

 echo "OPERATIONS_V2_ROUND1_E2E_RESULT=GREEN\n";
 echo "OPERATIONS_V2_ROUND1_CLEANUP=PENDING\n";
}catch(Throwable $e){
 fwrite(STDERR,"OPERATIONS_V2_ROUND1_E2E_RESULT=FAIL\n");
 fwrite(STDERR,"OPERATIONS_V2_ROUND1_E2E_ERROR=".$e->getMessage()."\n");
 $cleanup();echo "OPERATIONS_V2_ROUND1_CLEANUP=DONE\n";exit(1);
}

$cleanup();echo "OPERATIONS_V2_ROUND1_CLEANUP=DONE\n";

$q=$pdo->prepare("SELECT COUNT(*) FROM franchises WHERE code=?");$q->execute([$outletCode]);$fr=(int)$q->fetchColumn();
$q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN(?,?,?)");$q->execute([$ownerEmail,$opsEmail,$financeEmail]);$users=(int)$q->fetchColumn();
if($fr||$users){fwrite(STDERR,"OPERATIONS_V2_ROUND1_CLEANUP_VERIFY=FAIL franchise={$fr} users={$users}\n");exit(1);}
ok((int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn()===99,'POST_CLEANUP_PRODUCT_COUNT_CHANGED');
echo "OPERATIONS_V2_ROUND1_CLEANUP_VERIFY=PASS\n";
echo "OPERATIONS_V2_ROUND1_FINAL_GREEN\n";
