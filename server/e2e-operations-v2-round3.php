<?php
declare(strict_types=1);

/**
 * Tea Shop BD — Operations Command Center V2 / Round 3 E2E
 * Benchmarking, Health Forecast, Performance+, Approval Queue, Evidence Vault,
 * Management Report, Mobile/PWA Action Center, Notification Routing,
 * Closure Handover and final Owner-only closure control.
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

function fail(string $m): never {throw new RuntimeException($m);}
function ok(bool $v,string $m): void {if(!$v)fail($m);}

final class ApiClient {
 private string $base; private CurlHandle $ch; private ?string $csrf=null; private array $cookies=[];
 public function __construct(string $base){$this->base=$base;$ch=curl_init();if($ch===false)fail('CURL_INIT_FAILED');$this->ch=$ch;curl_setopt_array($this->ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false]);}
 public function __destruct(){curl_close($this->ch);}
 private function cookieHeader(): string {$p=[];foreach($this->cookies as $k=>$v)$p[]=$k.'='.$v;return implode('; ',$p);}
 public function request(string $method,string $route,?array $body=null): array {
  $parts=explode('?',$route,2);$url=$this->base.'/api/index.php?route='.rawurlencode($parts[0]).(isset($parts[1])?'&'.$parts[1]:'');
  $headers=['Accept: application/json'];if($body!==null)$headers[]='Content-Type: application/json';if($this->csrf!==null&&strtoupper($method)!=='GET')$headers[]='X-CSRF-Token: '.$this->csrf;
  curl_setopt_array($this->ch,[CURLOPT_URL=>$url,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body!==null?json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,CURLOPT_COOKIE=>$this->cookieHeader(),CURLOPT_HEADERFUNCTION=>function($ch,string $line): int {if(strncasecmp($line,'Set-Cookie:',11)===0){$cookie=trim(substr($line,11));$pair=explode(';',$cookie,2)[0]??'';if(str_contains($pair,'=')){[$n,$v]=explode('=',$pair,2);$n=trim($n);$v=trim($v);if($n!=='')$this->cookies[$n]=$v;}}return strlen($line);}]);
  $raw=curl_exec($this->ch);$err=curl_error($this->ch);$status=(int)curl_getinfo($this->ch,CURLINFO_RESPONSE_CODE);if($raw===false)fail('HTTP_TRANSPORT_FAILED '.$err);
  $data=json_decode((string)$raw,true);if(!is_array($data))fail('NON_JSON_RESPONSE route='.$route.' status='.$status);
  if(isset($data['csrf'])&&is_string($data['csrf'])&&$data['csrf']!=='')$this->csrf=$data['csrf'];return[$status,$data];
 }
 public function expect(string $method,string $route,?array $body,int $status=200,?string $code=null): array {
  [$got,$data]=$this->request($method,$route,$body);if($got!==$status)fail("HTTP_EXPECTATION_FAILED {$route} expected={$status} got={$got} code=".($data['code']??'none'));
  if($code!==null&&($data['code']??null)!==$code)fail("CODE_EXPECTATION_FAILED {$route} expected={$code} got=".($data['code']??'none'));
  if($status<400&&(($data['ok']??false)!==true))fail("API_NOT_OK {$route}");return$data;
 }
 public function login(string $email,string $password): void {
  $r=$this->expect('POST','login',['email'=>$email,'password'=>$password],200);ok(isset($this->cookies['TSBOS'])&&$this->cookies['TSBOS']!=='','LOGIN_SESSION_COOKIE_MISSING');
  $me=$this->expect('GET','me',null,200);ok(!empty($me['csrf']),'ME_CSRF_MISSING');$this->csrf=(string)$me['csrf'];ok((int)($me['user']['id']??0)===(int)($r['user']['id']??0),'SESSION_USER_MISMATCH');
 }
}

$suffix=strtolower(bin2hex(random_bytes(5)));$password='V2r3!'.bin2hex(random_bytes(10)).'Aa7';
$ownerEmail="v2r3-owner-{$suffix}@teashop.bd";$opsEmail="v2r3-ops-{$suffix}@teashop.bd";
$outletCode='V2R3-'.strtoupper($suffix);$outletName='V2 ROUND3 OUTLET '.$suffix;
$ownerId=$opsId=$franchiseId=$approvalId=$evidenceId=$routeId=$handoverId=$snapshotId=null;$cleaned=false;

$cleanup=function() use (&$cleaned,$pdo,&$ownerId,&$opsId,&$franchiseId,&$approvalId,&$evidenceId,&$routeId,&$handoverId,&$snapshotId,$ownerEmail,$opsEmail,$outletCode,$outletName): void {
 if($cleaned)return;$cleaned=true;
 try{
  $pdo->beginTransaction();$fid=(int)($franchiseId??0);
  if($fid<=0){$q=$pdo->prepare("SELECT id FROM franchises WHERE code=? LIMIT 1");$q->execute([$outletCode]);$fid=(int)($q->fetchColumn()?:0);}
  $ids=array_values(array_filter([(int)($ownerId??0),(int)($opsId??0)]));
  if($fid>0){
   $pdo->prepare("DELETE FROM support_ticket_updates WHERE support_ticket_id IN (SELECT id FROM support_tickets WHERE franchise_id=?)")->execute([$fid]);
   foreach(['operations_notification_routes','operations_closure_handovers','operations_alerts','operations_tasks','support_tickets','settlement_followups','operations_automation_links','outlet_daily_checkins','outlet_contracts','outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts','field_visits','outlet_communications','outlet_compliance_checks','marketing_executions','outlet_training_records','outlet_staff','outlet_health_checks','outlet_timeline','outlet_checklist_items','outlet_pipeline','outlet_profiles'] as $table){$pdo->prepare("DELETE FROM {$table} WHERE franchise_id=?")->execute([$fid]);}
   $pdo->prepare("DELETE FROM documents WHERE reference_type IN('franchise','outlet') AND reference_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM approvals WHERE reference_type='franchise' AND reference_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM franchises WHERE id=?")->execute([$fid]);
  }
  if((int)($snapshotId??0)>0)$pdo->prepare("DELETE FROM operations_benchmark_snapshots WHERE id=?")->execute([(int)$snapshotId]);
  if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM audit_logs WHERE user_id IN ({$marks})")->execute($ids);$pdo->prepare("DELETE FROM user_franchise_scope WHERE user_id IN ({$marks})")->execute($ids);$pdo->prepare("DELETE FROM notifications WHERE user_id IN ({$marks})")->execute($ids);$pdo->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($ids);}
  else{$pdo->prepare("DELETE FROM users WHERE email IN(?,?)")->execute([$ownerEmail,$opsEmail]);}
  $like='%'.$outletName.'%';$pdo->prepare("DELETE FROM notifications WHERE title LIKE ? OR message LIKE ?")->execute([$like,$like]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"CLEANUP_WARNING ".$e->getMessage()."\n");}
};
register_shutdown_function($cleanup);

try{
 echo "=== OPERATIONS V2 ROUND 3 E2E ===\n";echo "harness_version=1-management-closure\n";echo "base_url={$baseUrl}\n";
 $products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();ok($products===99,'PRODUCT_COUNT_CHANGED_'.$products);ok($roles>=12,'ROLES_MISSING');
 foreach(['operations_benchmark_snapshots','operations_notification_routes','operations_closure_handovers'] as $table){$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);ok((int)$q->fetchColumn()===1,'TABLE_MISSING_'.$table);}
 echo "PRECONDITION=PASS products={$products} roles={$roles} tables=3/3\n";

 $roleIds=[];foreach(['OWNER','OPERATIONS'] as $code){$q=$pdo->prepare("SELECT id FROM roles WHERE code=?");$q->execute([$code]);$roleIds[$code]=(int)($q->fetchColumn()?:0);ok($roleIds[$code]>0,'ROLE_MISSING_'.$code);}
 $ins=$pdo->prepare("INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,0,1)");
 $ins->execute([$roleIds['OWNER'],'V2 R3 Test Owner',$ownerEmail,password_hash($password,PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
 $ins->execute([$roleIds['OPERATIONS'],'V2 R3 Test Operations',$opsEmail,password_hash($password,PASSWORD_DEFAULT)]);$opsId=(int)$pdo->lastInsertId();
 $owner=new ApiClient($baseUrl);$owner->login($ownerEmail,$password);$ops=new ApiClient($baseUrl);$ops->login($opsEmail,$password);
 echo "AUTH_OWNER_OPERATIONS=PASS\n";

 $created=$owner->expect('POST','franchise.create',['code'=>$outletCode,'name'=>$outletName,'division'=>'V2 R3 Division','district'=>'V2 R3 District','upazila'=>'V2 R3 Upazila','address'=>'Temporary V2 Round 3','owner_name'=>'Temporary Franchisee','owner_phone'=>'01700000005','owner_email'=>"v2r3-{$suffix}@example.invalid",'territory_code'=>'V2R3-'.$suffix,'margin_tier'=>'Starter','margin_percent'=>25],201);
 $franchiseId=(int)$created['id'];$z=$ops->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 foreach(($z['checklists']['opening']??[]) as $item)$ops->expect('POST','franchise.checklist.toggle',['franchise_id'=>$franchiseId,'id'=>(int)$item['id'],'completed'=>true,'label'=>$item['item_label']],200);
 $ops->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'live','next_action'=>'V2 Round 3'],200);
 $ops->expect('POST','franchise.health.save',['franchise_id'=>$franchiseId,'sales_score'=>50,'stock_score'=>55,'settlement_score'=>55,'compliance_score'=>60,'notes'=>'V2 Round 3 forecast foundation'],201);
 $ops->expect('POST','operations.target.save',['franchise_id'=>$franchiseId,'period_start'=>date('Y-m-01'),'period_end'=>date('Y-m-t'),'sales_target'=>1000,'receipt_target'=>10,'notes'=>'V2 Round 3 benchmark target'],200);
 $ops->expect('POST','operations.task.create',['franchise_id'=>$franchiseId,'task_type'=>'follow_up','title'=>'V2 Round 3 overdue management task','detail'=>'Forecast risk foundation','priority'=>'high','assigned_user_id'=>$opsId,'due_at'=>date('Y-m-d H:i:s',strtotime('-2 hours'))],201);
 $ops->expect('POST','operations.ticket.create',['franchise_id'=>$franchiseId,'category'=>'customer','subject'=>'V2 Round 3 customer risk','detail'=>'Forecast risk foundation','priority'=>'high','assigned_user_id'=>$opsId],201);
 echo "OUTLET_MANAGEMENT_FOUNDATION=PASS id={$franchiseId}\n";

 $r3=$ops->expect('GET','operations.round3?period='.date('Y-m'),null,200);
 $bench=array_values(array_filter($r3['benchmarks']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));ok(count($bench)===1,'BENCHMARK_MISSING');ok(isset($bench[0]['benchmark_index'])&&isset($bench[0]['network_rank']),'BENCHMARK_VALUES_MISSING');
 $forecast=array_values(array_filter($r3['forecast']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));ok(count($forecast)===1,'FORECAST_MISSING');ok(in_array($forecast[0]['forecast_30d'],['watch','critical'],true),'FORECAST_NOT_ATTENTION_'.$forecast[0]['forecast_30d']);
 echo "BENCHMARK_FORECAST=PASS benchmark=".$bench[0]['benchmark_index']." forecast=".$forecast[0]['forecast_30d']." risk=".$forecast[0]['risk_score_30d']."\n";

 $req=$ops->expect('POST','operations.approval.request',['franchise_id'=>$franchiseId,'approval_type'=>'margin_exception','reason'=>'V2 Round 3 owner-control test','amount'=>0,'requested_value'=>'28.5','evidence_ref'=>'e2e://approval'],201);$approvalId=(int)$req['id'];ok($approvalId>0,'APPROVAL_CREATE_FAILED');
 $ops->expect('POST','operations.approval.decide',['id'=>$approvalId,'status'=>'approved'],403,'OWNER_REQUIRED');
 $dec=$owner->expect('POST','operations.approval.decide',['id'=>$approvalId,'status'=>'approved','decision_note'=>'V2 Round 3 approved'],200);ok(($dec['status']??'')==='approved','APPROVAL_DECISION_FAILED');
 echo "APPROVAL_QUEUE=PASS operations_request owner_decision\n";

 $ev=$ops->expect('POST','operations.evidence.save',['franchise_id'=>$franchiseId,'evidence_type'=>'closure_handover','title'=>'V2 Round 3 closure evidence','evidence_ref'=>'e2e://closure-handover'],201);$evidenceId=(int)$ev['id'];ok($evidenceId>0,'EVIDENCE_CREATE_FAILED');
 echo "EVIDENCE_VAULT=PASS id={$evidenceId}\n";

 $nr=$ops->expect('POST','operations.notification.route',['franchise_id'=>$franchiseId,'target_role_code'=>'FINANCE','severity'=>'warning','title'=>'V2 Round 3 finance coordination | '.$outletName,'message'=>'Management closure finance coordination'],201);$routeId=(int)$nr['id'];ok($routeId>0&&(int)$nr['notification_id']>0,'NOTIFICATION_ROUTE_FAILED');
 echo "NOTIFICATION_ROUTING=PASS target=FINANCE\n";

 $ready=$ops->expect('POST','operations.closure.handover.save',['franchise_id'=>$franchiseId,'handover_status'=>'ready','stock_status'=>'reconciled','dues_status'=>'reconciled','documents_status'=>'complete','evidence_ref'=>'e2e://closure-handover','note'=>'V2 Round 3 ready for Owner'],200);$handoverId=(int)$ready['id'];ok($handoverId>0,'HANDOVER_READY_FAILED');
 $ops->expect('POST','operations.closure.handover.save',['franchise_id'=>$franchiseId,'handover_status'=>'approved','stock_status'=>'reconciled','dues_status'=>'reconciled','documents_status'=>'complete','evidence_ref'=>'e2e://closure-handover','note'=>'Operations must not approve'],403,'OWNER_APPROVAL_REQUIRED');
 $approved=$owner->expect('POST','operations.closure.handover.save',['franchise_id'=>$franchiseId,'handover_status'=>'approved','stock_status'=>'reconciled','dues_status'=>'reconciled','documents_status'=>'complete','evidence_ref'=>'e2e://closure-handover','note'=>'Owner approved management handover'],200);ok(($approved['handover_status']??'')==='approved','HANDOVER_OWNER_APPROVAL_FAILED');
 echo "CLOSURE_HANDOVER=PASS operations_ready owner_approved\n";

 $snap=$ops->expect('POST','operations.management.snapshot',['period'=>date('Y-m')],201);$snapshotId=(int)$snap['id'];ok($snapshotId>0,'MANAGEMENT_SNAPSHOT_FAILED');
 $r3b=$ops->expect('GET','operations.round3?period='.date('Y-m'),null,200);
 $aHit=array_values(array_filter($r3b['approvals']??[],fn($r)=>(int)($r['id']??0)===$approvalId));ok(count($aHit)===1&&$aHit[0]['status']==='approved','APPROVAL_READBACK_FAILED');
 $eHit=array_values(array_filter($r3b['evidence']??[],fn($r)=>(int)($r['id']??0)===$evidenceId));ok(count($eHit)===1,'EVIDENCE_READBACK_FAILED');
 $hHit=array_values(array_filter($r3b['closures']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));ok(count($hHit)===1&&$hHit[0]['handover_status']==='approved','HANDOVER_READBACK_FAILED');
 $rtHit=array_values(array_filter($r3b['notification_routes']??[],fn($r)=>(int)($r['id']??0)===$routeId));ok(count($rtHit)===1,'ROUTING_READBACK_FAILED');
 echo "MANAGEMENT_REPORT_ACTION_CENTER=PASS snapshot={$snapshotId} actions=".count($r3b['actions']??[])."\n";

 $app=@file_get_contents(dirname(__DIR__).'/deploy/app.js')?:'';
 foreach(['Export CSV','Print / Save PDF','Mobile / PWA Action Center','Operations Performance Trend'] as $marker)ok(strpos($app,$marker)!==false,'UI_MARKER_MISSING_'.str_replace(' ','_',$marker));
 echo "REPORT_EXPORT_PERFORMANCE_UI=PASS csv pdf pwa trend\n";

 $ops->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'closed','blocking_reason'=>'Operations final close attempt'],403,'OWNER_APPROVAL_REQUIRED');
 $owner->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'closed','blocking_reason'=>'Owner pre-checklist attempt'],422,'CLOSURE_CHECKLIST_INCOMPLETE');
 $cz=$owner->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);ok(count($cz['checklists']['closure']??[])===8,'CLOSURE_ITEMS_NOT_8');
 foreach(($cz['checklists']['closure']??[]) as $item)$ops->expect('POST','franchise.checklist.toggle',['franchise_id'=>$franchiseId,'id'=>(int)$item['id'],'completed'=>true,'label'=>$item['item_label']],200);
 $owner->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'closed','blocking_reason'=>'V2 Round 3 final owner closure'],200);
 echo "FINAL_CLOSURE_RBAC=PASS checklist=8/8 owner_only\n";

 $q=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id IN(?,?)");$q->execute([$ownerId,$opsId]);$audit=(int)$q->fetchColumn();ok($audit>=15,'AUDIT_COVERAGE_LOW_'.$audit);
 echo "AUDIT_LOG=PASS rows={$audit}\n";
 echo "OPERATIONS_V2_ROUND3_E2E_RESULT=GREEN\n";echo "OPERATIONS_V2_ROUND3_CLEANUP=PENDING\n";
}catch(Throwable $e){
 fwrite(STDERR,"OPERATIONS_V2_ROUND3_E2E_RESULT=FAIL\n");fwrite(STDERR,"OPERATIONS_V2_ROUND3_E2E_ERROR=".$e->getMessage()."\n");$cleanup();echo "OPERATIONS_V2_ROUND3_CLEANUP=DONE\n";exit(1);
}

$cleanup();echo "OPERATIONS_V2_ROUND3_CLEANUP=DONE\n";
$q=$pdo->prepare("SELECT COUNT(*) FROM franchises WHERE code=?");$q->execute([$outletCode]);$fr=(int)$q->fetchColumn();
$q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN(?,?)");$q->execute([$ownerEmail,$opsEmail]);$users=(int)$q->fetchColumn();
$extra=0;foreach(['operations_benchmark_snapshots','operations_notification_routes','operations_closure_handovers'] as $table){if($table==='operations_benchmark_snapshots'&&$snapshotId){$q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id=?");$q->execute([(int)$snapshotId]);$extra+=(int)$q->fetchColumn();}elseif($franchiseId&&$table!=='operations_benchmark_snapshots'){$q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE franchise_id=?");$q->execute([(int)$franchiseId]);$extra+=(int)$q->fetchColumn();}}
if($fr||$users||$extra){fwrite(STDERR,"OPERATIONS_V2_ROUND3_CLEANUP_VERIFY=FAIL franchise={$fr} users={$users} extra={$extra}\n");exit(1);}
ok((int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn()===99,'POST_CLEANUP_PRODUCT_COUNT_CHANGED');
echo "OPERATIONS_V2_ROUND3_CLEANUP_VERIFY=PASS\n";echo "OPERATIONS_V2_ROUND3_FINAL_GREEN\n";
