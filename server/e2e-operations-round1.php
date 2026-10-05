<?php
declare(strict_types=1);

/**
 * Tea Shop BD — Operations Round 1 API E2E
 *
 * Runs against the deployed same-origin API with temporary Owner + Operations
 * accounts and a temporary outlet. All test data is removed at shutdown.
 *
 * Usage:
 *   php server/e2e-operations-round1.php
 * Optional:
 *   TSB_BASE_URL=https://teashop.bd php server/e2e-operations-round1.php
 */

$root=dirname(__DIR__);
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
function ts(?string $value=null): string {
 $t=$value?strtotime($value):time();
 return date('Y-m-d H:i:s',$t===false?time():$t);
}

final class ApiClient {
 private string $base;
 private CurlHandle $ch;
 private ?string $csrf=null;
 private array $cookies=[];
 public function __construct(string $base){
  $this->base=$base;
  $ch=curl_init();
  if($ch===false) fail('CURL_INIT_FAILED');
  $this->ch=$ch;
  curl_setopt_array($this->ch,[
   CURLOPT_RETURNTRANSFER=>true,
   CURLOPT_HEADER=>false,
   CURLOPT_CONNECTTIMEOUT=>10,
   CURLOPT_TIMEOUT=>30,
   CURLOPT_FOLLOWLOCATION=>false,
  ]);
 }
 public function __destruct(){ curl_close($this->ch); }
 private function cookieHeader(): string {
  $parts=[];
  foreach($this->cookies as $k=>$v)$parts[]=$k.'='.$v;
  return implode('; ',$parts);
 }
 public function request(string $method,string $route,?array $body=null): array {
  $parts=explode('?',$route,2);
  $url=$this->base.'/api/index.php?route='.rawurlencode($parts[0]).(isset($parts[1])?'&'.$parts[1]:'');
  $headers=['Accept: application/json'];
  if($body!==null)$headers[]='Content-Type: application/json';
  if($this->csrf!==null && strtoupper($method)!=='GET')$headers[]='X-CSRF-Token: '.$this->csrf;

  curl_setopt_array($this->ch,[
   CURLOPT_URL=>$url,
   CURLOPT_CUSTOMREQUEST=>strtoupper($method),
   CURLOPT_HTTPHEADER=>$headers,
   CURLOPT_POSTFIELDS=>$body!==null?json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
   CURLOPT_COOKIE=>$this->cookieHeader(),
   CURLOPT_HEADERFUNCTION=>function($ch,string $line): int {
    if(strncasecmp($line,'Set-Cookie:',11)===0){
     $cookie=trim(substr($line,11));
     $pair=explode(';',$cookie,2)[0]??'';
     if(str_contains($pair,'=')){
      [$name,$value]=explode('=',$pair,2);
      $name=trim($name);$value=trim($value);
      if($name!=='')$this->cookies[$name]=$value;
     }
    }
    return strlen($line);
   },
  ]);

  $raw=curl_exec($this->ch);
  $error=curl_error($this->ch);
  $status=(int)curl_getinfo($this->ch,CURLINFO_RESPONSE_CODE);
  if($raw===false) fail('HTTP_TRANSPORT_FAILED: '.$error);
  $data=json_decode((string)$raw,true);
  if(!is_array($data)) fail('NON_JSON_RESPONSE status='.$status);
  if(isset($data['csrf']) && is_string($data['csrf']) && $data['csrf']!=='')$this->csrf=$data['csrf'];
  return [$status,$data];
 }
 public function expect(string $method,string $route,?array $body,int $status=200,?string $code=null): array {
  [$got,$data]=$this->request($method,$route,$body);
  if($got!==$status) fail("HTTP_EXPECTATION_FAILED {$route} expected={$status} got={$got} code=".($data['code']??'none')." cookies=".count($this->cookies)." csrf=".($this->csrf!==null?'set':'missing'));
  if($code!==null && ($data['code']??null)!==$code) fail("CODE_EXPECTATION_FAILED {$route} expected={$code} got=".($data['code']??'none'));
  if($status<400 && (($data['ok']??false)!==true)) fail("API_NOT_OK {$route}");
  return $data;
 }
 public function login(string $email,string $password): array {
  $r=$this->expect('POST','login',['email'=>$email,'password'=>$password],200);
  ok(!empty($r['csrf']),'LOGIN_CSRF_MISSING');
  ok(isset($this->cookies['TSBOS']) && $this->cookies['TSBOS']!=='','LOGIN_SESSION_COOKIE_MISSING');
  $me=$this->expect('GET','me',null,200);
  ok(!empty($me['csrf']),'ME_CSRF_MISSING');
  $this->csrf=(string)$me['csrf'];
  ok((int)($me['user']['id']??0)===(int)($r['user']['id']??0),'SESSION_USER_MISMATCH');
  return $me;
 }
}

$suffix=strtolower(bin2hex(random_bytes(5)));
$password='E2e!'.bin2hex(random_bytes(12)).'Aa7';
$ownerEmail="e2e-owner-{$suffix}@teashop.bd";
$opsEmail="e2e-operations-{$suffix}@teashop.bd";
$outletCode='E2E-'.strtoupper($suffix);
$outletName='ROUND1 E2E OUTLET '.$suffix;
$ownerId=null;$opsId=null;$franchiseId=null;$cleaned=false;
$createdTicketIds=[];

$cleanup=function() use (&$cleaned,$pdo,&$ownerId,&$opsId,&$franchiseId,$ownerEmail,$opsEmail,$outletCode): void {
 if($cleaned)return;
 $cleaned=true;
 try{
  $pdo->beginTransaction();
  $fid=(int)($franchiseId??0);
  if($fid<=0){
   $q=$pdo->prepare("SELECT id FROM franchises WHERE code=? LIMIT 1");$q->execute([$outletCode]);$fid=(int)($q->fetchColumn()?:0);
  }
  if($fid>0){
   $pdo->prepare("DELETE FROM support_ticket_updates WHERE support_ticket_id IN (SELECT id FROM support_tickets WHERE franchise_id=?)")->execute([$fid]);
   foreach([
    'support_tickets','operations_tasks','field_visits','outlet_communications','outlet_compliance_checks','marketing_executions',
    'outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts','operations_alerts','outlet_health_checks',
    'outlet_training_records','outlet_staff','outlet_timeline','outlet_checklist_items','outlet_pipeline','outlet_profiles'
   ] as $table){
    $pdo->prepare("DELETE FROM {$table} WHERE franchise_id=?")->execute([$fid]);
   }
   $pdo->prepare("DELETE FROM documents WHERE reference_type IN('franchise','outlet') AND reference_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM franchises WHERE id=?")->execute([$fid]);
  }
  $ids=array_values(array_filter([(int)($ownerId??0),(int)($opsId??0)]));
  if(!$ids){
   $q=$pdo->prepare("SELECT id FROM users WHERE email IN(?,?)");$q->execute([$ownerEmail,$opsEmail]);$ids=array_map('intval',array_column($q->fetchAll(),'id'));
  }
  if($ids){
   $marks=implode(',',array_fill(0,count($ids),'?'));
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE user_id IN ({$marks})")->execute($ids);
   $pdo->prepare("DELETE FROM audit_logs WHERE user_id IN ({$marks})")->execute($ids);
   $pdo->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($ids);
  }else{
   $pdo->prepare("DELETE FROM users WHERE email IN(?,?)")->execute([$ownerEmail,$opsEmail]);
  }
  $pdo->commit();
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  fwrite(STDERR,"CLEANUP_WARNING ".$e->getMessage()."\n");
 }
};
register_shutdown_function($cleanup);

try{
 echo "=== ROUND 1 OPERATIONS E2E ===\n";
echo "harness_version=3-explicit-cookie-csrf\n";
 echo "base_url={$baseUrl}\n";

 // Preflight.
 $products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
 $roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
 ok($products===99,'PRECONDITION_PRODUCTS_'.$products);
 ok($roles>=12,'PRECONDITION_ROLES_'.$roles);
 echo "PRECONDITION=PASS products={$products} roles={$roles}\n";

 $requiredTables=[
  'franchises','outlet_profiles','outlet_pipeline','outlet_checklist_items','outlet_staff','outlet_training_records','outlet_timeline',
  'outlet_health_checks','documents','operations_tasks','field_visits','support_tickets','support_ticket_updates','outlet_communications',
  'outlet_compliance_checks','marketing_executions','audit_logs'
 ];
 $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
 foreach($requiredTables as $table){$q->execute([$table]);ok((int)$q->fetchColumn()===1,'TABLE_MISSING_'.$table);}
 echo "SCHEMA=PASS tables=".count($requiredTables)."\n";

 // Temporary role accounts. Password is never printed.
 $ownerRole=(int)($pdo->query("SELECT id FROM roles WHERE code='OWNER' LIMIT 1")->fetchColumn()?:0);
 $opsRole=(int)($pdo->query("SELECT id FROM roles WHERE code='OPERATIONS' LIMIT 1")->fetchColumn()?:0);
 ok($ownerRole>0&&$opsRole>0,'ROLE_IDS_MISSING');
 $ins=$pdo->prepare("INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,0,1)");
 $ins->execute([$ownerRole,'Round 1 E2E Owner',$ownerEmail,password_hash($password,PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
 $ins->execute([$opsRole,'Round 1 E2E Operations',$opsEmail,password_hash($password,PASSWORD_DEFAULT)]);$opsId=(int)$pdo->lastInsertId();
 echo "TEMP_AUTH_IDENTITIES=PASS\n";

 $public=new ApiClient($baseUrl);
 $health=$public->expect('GET','health',null,200);
 ok(($health['database']??'')==='connected','HEALTH_DATABASE_NOT_CONNECTED');
 echo "API_HEALTH=PASS\n";

 $owner=new ApiClient($baseUrl);$ownerLogin=$owner->login($ownerEmail,$password);
 ok(($ownerLogin['user']['role']??'')==='OWNER','OWNER_LOGIN_ROLE_MISMATCH');
 $ops=new ApiClient($baseUrl);$opsLogin=$ops->login($opsEmail,$password);
 ok(($opsLogin['user']['role']??'')==='OPERATIONS','OPERATIONS_LOGIN_ROLE_MISMATCH');
 echo "AUTH_OWNER_OPERATIONS=PASS\n";

 // Owner creates the test outlet.
 $create=$owner->expect('POST','franchise.create',[
  'code'=>$outletCode,'name'=>$outletName,'division'=>'Dhaka','district'=>'Dhaka','upazila'=>'Gulshan',
  'address'=>'Round 1 E2E temporary address','owner_name'=>'E2E Franchise Owner','owner_phone'=>'01700000000',
  'owner_email'=>"franchise-{$suffix}@example.invalid",'territory_code'=>'DHK-E2E','shop_type'=>'Franchise',
  'shop_size_sqft'=>450,'agreement_no'=>'AGR-'.$suffix,'agreement_date'=>date('Y-m-d'),
  'lease_start'=>date('Y-m-d'),'lease_end'=>date('Y-m-d',strtotime('+1 year')),
  'target_open_date'=>date('Y-m-d',strtotime('+7 days')),'margin_tier'=>'Starter','margin_percent'=>25
 ],201);
 $franchiseId=(int)$create['id'];ok($franchiseId>0,'FRANCHISE_ID_MISSING');
 echo "OUTLET_CREATE=PASS id={$franchiseId}\n";

 // Operations cannot create outlets or override commercial margin.
 $ops->expect('POST','franchise.create',['code'=>'DENY-'.$suffix,'name'=>'Should Not Create'],403,'OWNER_REQUIRED');
 $ops->expect('POST','franchise.margin.update',['id'=>$franchiseId,'margin_percent'=>30],403,'OWNER_REQUIRED');
 echo "AUTHORITY_CREATE_MARGIN=PASS\n";

 $first=$owner->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 ok(count($first['checklists']['opening']??[])===10,'OPENING_CHECKLIST_COUNT');
 ok(count($first['checklists']['closure']??[])===8,'CLOSURE_CHECKLIST_COUNT');
 ok(($first['pipeline']['stage']??'')==='lead','PIPELINE_INITIAL_STAGE');
 echo "OUTLET_360_FOUNDATION=PASS opening=10 closure=8\n";

 // Operations owns day-to-day profile and territory work.
 $ops->expect('POST','franchise.profile.update',[
  'franchise_id'=>$franchiseId,'name'=>$outletName,'owner_name'=>'E2E Franchise Owner Updated','owner_phone'=>'01700000001',
  'owner_email'=>"franchise-{$suffix}@example.invalid",'division'=>'Dhaka','district'=>'Dhaka','upazila'=>'Gulshan',
  'address'=>'Round 1 E2E temporary address updated','territory_code'=>'DHK-GUL-E2E','shop_type'=>'Flagship Franchise',
  'shop_size_sqft'=>475,'agreement_no'=>'AGR-'.$suffix,'agreement_date'=>date('Y-m-d'),
  'lease_start'=>date('Y-m-d'),'lease_end'=>date('Y-m-d',strtotime('+1 year')),'target_open_date'=>date('Y-m-d',strtotime('+7 days'))
 ],200);
 $territory=$ops->expect('GET','franchise.territory',null,200);
 $territoryHit=false;
 foreach(($territory['outlets']??[]) as $r)if((int)$r['id']===$franchiseId && ($r['division']??'')==='Dhaka' && ($r['district']??'')==='Dhaka' && ($r['upazila']??'')==='Gulshan')$territoryHit=true;
 ok($territoryHit,'TERRITORY_HIERARCHY_MISSING');
 echo "PROFILE_TERRITORY=PASS\n";

 // Normal opening pipeline belongs to Operations.
 foreach(['verification','agreement','shop_ready','training','stock_ready','pos_ready','launch'] as $stage){
  $ops->expect('POST','franchise.pipeline.update',[
   'franchise_id'=>$franchiseId,'stage'=>$stage,'target_open_date'=>date('Y-m-d',strtotime('+7 days')),
   'next_action'=>'E2E '.$stage,'blocking_reason'=>''
  ],200);
 }
 $ops->expect('POST','franchise.pipeline.update',[
  'franchise_id'=>$franchiseId,'stage'=>'live','target_open_date'=>date('Y-m-d'),'next_action'=>'Attempt before checklist','blocking_reason'=>''
 ],422,'OPENING_CHECKLIST_INCOMPLETE');
 echo "PIPELINE_PRELIVE_GATE=PASS\n";

 // Complete required opening checklist.
 $check=$ops->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 foreach(($check['checklists']['opening']??[]) as $item){
  $ops->expect('POST','franchise.checklist.toggle',[
   'franchise_id'=>$franchiseId,'id'=>(int)$item['id'],'completed'=>true,'label'=>$item['item_label'],'notes'=>'Round 1 E2E completed'
  ],200);
 }
 $ops->expect('POST','franchise.pipeline.update',[
  'franchise_id'=>$franchiseId,'stage'=>'live','target_open_date'=>date('Y-m-d'),'next_action'=>'Operate outlet','blocking_reason'=>''
 ],200);
 $live=$ops->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 ok((int)($live['checklists']['opening_progress']??0)===100,'OPENING_PROGRESS_NOT_100');
 ok(($live['pipeline']['stage']??'')==='live','PIPELINE_NOT_LIVE');
 ok(($live['franchise']['status']??'')==='active','FRANCHISE_NOT_ACTIVE');
 echo "OPENING_CHECKLIST_LIVE=PASS progress=100\n";

 // Operations cannot perform Founder-final suspension/closure.
 $ops->expect('POST','franchise.pipeline.update',[
  'franchise_id'=>$franchiseId,'stage'=>'suspended','target_open_date'=>date('Y-m-d'),'next_action'=>'','blocking_reason'=>'E2E authority check'
 ],403,'OWNER_APPROVAL_REQUIRED');
 $ops->expect('POST','franchise.pipeline.update',[
  'franchise_id'=>$franchiseId,'stage'=>'closed','target_open_date'=>date('Y-m-d'),'next_action'=>'','blocking_reason'=>'E2E authority check'
 ],403,'OWNER_APPROVAL_REQUIRED');
 echo "SUSPEND_CLOSE_OWNER_GATE=PASS\n";

 // Staff + training.
 $staff=$ops->expect('POST','franchise.staff.create',[
  'franchise_id'=>$franchiseId,'name'=>'E2E Outlet Manager','staff_role'=>'Outlet Manager','phone'=>'01800000000',
  'email'=>"staff-{$suffix}@example.invalid",'joined_at'=>date('Y-m-d'),'notes'=>'Round 1 E2E'
 ],201);
 $staffId=(int)$staff['id'];ok($staffId>0,'STAFF_ID_MISSING');
 $training=$ops->expect('POST','franchise.training.save',[
  'franchise_id'=>$franchiseId,'outlet_staff_id'=>$staffId,'course_code'=>'E2E-POS','course_title'=>'POS & Retail Operations',
  'status'=>'completed','scheduled_at'=>ts('-2 hours'),'completed_at'=>ts('-1 hour'),'expires_at'=>date('Y-m-d',strtotime('+1 year')),
  'trainer'=>'Tea Shop BD E2E','certificate_ref'=>'CERT-'.$suffix,'notes'=>'Round 1 E2E'
 ],200);
 ok((int)$training['id']>0,'TRAINING_ID_MISSING');
 echo "STAFF_TRAINING=PASS\n";

 // Document registry.
 $doc=$ops->expect('POST','franchise.document.create',[
  'franchise_id'=>$franchiseId,'document_type'=>'franchise_agreement','title'=>'Round 1 E2E Agreement',
  'file_path'=>'e2e://agreement/'.$suffix,'status'=>'active'
 ],201);
 ok((int)$doc['id']>0,'DOCUMENT_ID_MISSING');
 echo "DOCUMENT_REGISTRY=PASS\n";

 // Health foundation.
 $healthResult=$ops->expect('POST','franchise.health.save',[
  'franchise_id'=>$franchiseId,'sales_score'=>90,'stock_score'=>85,'settlement_score'=>90,'compliance_score'=>95,'notes'=>'Round 1 E2E healthy'
 ],201);
 ok(($healthResult['health']??'')==='healthy','HEALTH_NOT_HEALTHY');
 ok((float)($healthResult['total_score']??0)>=75,'HEALTH_SCORE_LOW');
 echo "OUTLET_HEALTH=PASS score=".$healthResult['total_score']."\n";

 // Daily task and lifecycle.
 $task=$ops->expect('POST','operations.task.create',[
  'franchise_id'=>$franchiseId,'task_type'=>'follow_up','title'=>'Round 1 E2E follow-up','detail'=>'Verify outlet launch',
  'priority'=>'high','assigned_user_id'=>$opsId,'due_at'=>ts('+12 hours')
 ],201);
 $taskId=(int)$task['id'];
 $ops->expect('POST','operations.task.update',['id'=>$taskId,'status'=>'in_progress'],200);
 $ops->expect('POST','operations.task.update',['id'=>$taskId,'status'=>'done'],200);
 echo "DAILY_TASK=PASS\n";

 // Field visit / inspection.
 $visit=$ops->expect('POST','operations.visit.save',[
  'franchise_id'=>$franchiseId,'visit_type'=>'launch','status'=>'completed','scheduled_at'=>ts('-1 hour'),'visited_at'=>ts(),
  'visitor_user_id'=>$opsId,'cleanliness_score'=>95,'branding_score'=>90,'product_display_score'=>92,'pricing_compliance_score'=>100,
  'pos_usage_score'=>95,'stock_handling_score'=>90,'findings'=>'Round 1 E2E visit passed','corrective_action'=>'Maintain standards',
  'evidence_ref'=>'e2e://visit-photo/'.$suffix,'next_visit_at'=>ts('+30 days')
 ],200);
 ok((float)($visit['overall_score']??0)>=90,'VISIT_SCORE_LOW');
 echo "FIELD_VISIT=PASS score=".$visit['overall_score']."\n";

 // Support ticket lifecycle.
 $ticket=$ops->expect('POST','operations.ticket.create',[
  'franchise_id'=>$franchiseId,'category'=>'pos','subject'=>'Round 1 E2E POS support','detail'=>'Temporary E2E ticket',
  'priority'=>'medium','assigned_user_id'=>$opsId,'due_at'=>ts('+24 hours')
 ],201);
 $ticketId=(int)$ticket['id'];
 $ops->expect('POST','operations.ticket.update',['id'=>$ticketId,'status'=>'in_progress','note'=>'Investigating'],200);
 $ops->expect('POST','operations.ticket.update',['id'=>$ticketId,'status'=>'resolved','resolution'=>'Round 1 E2E resolved','note'=>'Resolved'],200);
 echo "SUPPORT_TICKET=PASS\n";

 // Communication note creates a linked follow-up task.
 $comm=$ops->expect('POST','operations.communication.create',[
  'franchise_id'=>$franchiseId,'channel'=>'whatsapp','direction'=>'outbound','subject'=>'Round 1 E2E follow-up',
  'note'=>'Franchise owner confirmed next follow-up.','promised_date'=>date('Y-m-d',strtotime('+1 day')),'follow_up_at'=>ts('+1 day')
 ],201);
 ok((int)$comm['id']>0,'COMMUNICATION_ID_MISSING');
 echo "COMMUNICATION_FOLLOWUP=PASS\n";

 // Compliance with corrective action -> task -> resolution.
 $compliance=$ops->expect('POST','operations.compliance.save',[
  'franchise_id'=>$franchiseId,'branding_score'=>75,'pricing_score'=>80,'pos_usage_score'=>75,'stock_handling_score'=>70,'customer_service_score'=>80,
  'findings'=>'Minor E2E merchandising gap','corrective_action'=>'Correct display before next review','corrective_due_at'=>ts('+2 days')
 ],201);
 ok(in_array($compliance['status']??'',['watch','non_compliant'],true),'COMPLIANCE_NOT_ACTIONABLE');
 $ops->expect('POST','operations.compliance.resolve',['id'=>(int)$compliance['id']],200);
 echo "COMPLIANCE=PASS status=".$compliance['status']."\n";

 // Marketing execution.
 $marketing=$ops->expect('POST','operations.marketing.save',[
  'franchise_id'=>$franchiseId,'campaign_code'=>'E2E-'.$suffix,'campaign_name'=>'Round 1 Launch Campaign','status'=>'live',
  'priority'=>'medium','assigned_user_id'=>$opsId,'due_at'=>ts('+2 days'),'start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+7 days')),
  'assets_ready'=>true,'execution_verified'=>true,'sales_before'=>0,'sales_during'=>1250,'notes'=>'Round 1 E2E campaign'
 ],200);
 $marketingId=(int)$marketing['id'];
 $ops->expect('POST','operations.marketing.save',[
  'id'=>$marketingId,'franchise_id'=>$franchiseId,'campaign_code'=>'E2E-'.$suffix,'campaign_name'=>'Round 1 Launch Campaign','status'=>'completed',
  'priority'=>'medium','assigned_user_id'=>$opsId,'due_at'=>ts('+2 days'),'start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+7 days')),
  'assets_ready'=>true,'execution_verified'=>true,'sales_before'=>0,'sales_during'=>1250,'notes'=>'Round 1 E2E completed'
 ],200);
 echo "MARKETING_EXECUTION=PASS\n";

 // Workboard aggregation.
 $board=$ops->expect('GET','operations.workboard',null,200);
 $hasTask=false;$hasVisit=false;$hasTicket=false;$hasComm=false;$hasComp=false;$hasMarketing=false;
 foreach(($board['tasks']??[]) as $r)if((int)($r['franchise_id']??0)===$franchiseId)$hasTask=true;
 foreach(($board['visits']??[]) as $r)if((int)($r['franchise_id']??0)===$franchiseId)$hasVisit=true;
 foreach(($board['tickets']??[]) as $r)if((int)($r['franchise_id']??0)===$franchiseId)$hasTicket=true;
 foreach(($board['communications']??[]) as $r)if((int)($r['franchise_id']??0)===$franchiseId)$hasComm=true;
 foreach(($board['compliance']??[]) as $r)if((int)($r['franchise_id']??0)===$franchiseId)$hasComp=true;
 foreach(($board['marketing']??[]) as $r)if((int)($r['franchise_id']??0)===$franchiseId)$hasMarketing=true;
 ok($hasTask&&$hasVisit&&$hasTicket&&$hasComm&&$hasComp&&$hasMarketing,'WORKBOARD_AGGREGATION_INCOMPLETE');
 echo "OPERATIONS_WORKBOARD=PASS\n";

 // Final Outlet 360 aggregation + timeline.
 $final=$ops->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 ok(($final['pipeline']['stage']??'')==='live','FINAL_PIPELINE_NOT_LIVE');
 ok((int)($final['checklists']['opening_progress']??0)===100,'FINAL_OPENING_NOT_100');
 ok(count($final['checklists']['closure']??[])===8,'FINAL_CLOSURE_CHECKLIST_NOT_VISIBLE');
 ok(count($final['staff']??[])>=1,'FINAL_STAFF_MISSING');
 ok(count($final['training']??[])>=1,'FINAL_TRAINING_MISSING');
 ok(count($final['documents']??[])>=1,'FINAL_DOCUMENT_MISSING');
 ok(($final['health_latest']['health']??'')==='healthy','FINAL_HEALTH_MISMATCH');
 foreach(['tasks','visits','tickets','communications','compliance','marketing'] as $key)ok(count($final['operations'][$key]??[])>=1,'FINAL_DAILYOPS_'.$key.'_MISSING');

 $eventTypes=array_values(array_unique(array_map(fn($r)=>(string)($r['event_type']??''),$final['timeline']??[])));
 $requiredEvents=['created','profile','pipeline','checklist','staff','training','document','health','task','field_visit','support_ticket','communication','compliance','marketing'];
 $missingEvents=array_values(array_diff($requiredEvents,$eventTypes));
 ok(!$missingEvents,'TIMELINE_EVENTS_MISSING_'.implode(',',$missingEvents));
 echo "OUTLET_360_DAILYOPS_TIMELINE=PASS events=".count($eventTypes)."\n";

 // Audit coverage for temporary identities.
 $q=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id IN(?,?)");$q->execute([$ownerId,$opsId]);$auditCount=(int)$q->fetchColumn();
 ok($auditCount>=15,'AUDIT_LOG_COVERAGE_LOW_'.$auditCount);
 echo "AUDIT_LOG=PASS rows={$auditCount}\n";

 echo "ROUND1_E2E_RESULT=GREEN\n";
 echo "ROUND1_E2E_CLEANUP=PENDING\n";
}catch(Throwable $e){
 fwrite(STDERR,"ROUND1_E2E_RESULT=FAIL\n");
 fwrite(STDERR,"ROUND1_E2E_ERROR=".$e->getMessage()."\n");
 $cleanup();
 echo "ROUND1_E2E_CLEANUP=DONE\n";
 exit(1);
}

$cleanup();
echo "ROUND1_E2E_CLEANUP=DONE\n";

// Verify no test residue.
$q=$pdo->prepare("SELECT COUNT(*) FROM franchises WHERE code=?");$q->execute([$outletCode]);$residueFranchise=(int)$q->fetchColumn();
$q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN(?,?)");$q->execute([$ownerEmail,$opsEmail]);$residueUsers=(int)$q->fetchColumn();
if($residueFranchise!==0||$residueUsers!==0){
 fwrite(STDERR,"ROUND1_E2E_CLEANUP_VERIFY=FAIL franchises={$residueFranchise} users={$residueUsers}\n");
 exit(1);
}
echo "ROUND1_E2E_CLEANUP_VERIFY=PASS\n";
echo "OPERATIONS_ROUND1_FINAL_GREEN\n";
