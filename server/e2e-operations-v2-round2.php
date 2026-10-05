<?php
declare(strict_types=1);

/**
 * Tea Shop BD — Operations Command Center V2 / Round 2 E2E
 * Auto Task Engine, Target Breakdown, Reorder Center, Settlement Follow-up,
 * Risk Center, Customer Care and Training Compliance.
 * Temporary production data is removed and residue-verified.
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
function closeEnough(float $a,float $b,float $eps=0.01): bool { return abs($a-$b)<=$eps; }

final class ApiClient {
 private string $base; private CurlHandle $ch; private ?string $csrf=null; private array $cookies=[];
 public function __construct(string $base){$this->base=$base;$ch=curl_init();if($ch===false)fail('CURL_INIT_FAILED');$this->ch=$ch;curl_setopt_array($this->ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false]);}
 public function __destruct(){curl_close($this->ch);}
 private function cookieHeader(): string {$p=[];foreach($this->cookies as $k=>$v)$p[]=$k.'='.$v;return implode('; ',$p);}
 public function request(string $method,string $route,?array $body=null): array {
  $parts=explode('?',$route,2);$url=$this->base.'/api/index.php?route='.rawurlencode($parts[0]).(isset($parts[1])?'&'.$parts[1]:'');
  $headers=['Accept: application/json'];if($body!==null)$headers[]='Content-Type: application/json';if($this->csrf!==null&&strtoupper($method)!=='GET')$headers[]='X-CSRF-Token: '.$this->csrf;
  curl_setopt_array($this->ch,[CURLOPT_URL=>$url,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body!==null?json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,CURLOPT_COOKIE=>$this->cookieHeader(),CURLOPT_HEADERFUNCTION=>function($ch,string $line): int {if(strncasecmp($line,'Set-Cookie:',11)===0){$cookie=trim(substr($line,11));$pair=explode(';',$cookie,2)[0]??'';if(str_contains($pair,'=')){[$n,$v]=explode('=',$pair,2);$n=trim($n);$v=trim($v);if($n!=='')$this->cookies[$n]=$v;}}return strlen($line);}]);
  $raw=curl_exec($this->ch);$err=curl_error($this->ch);$status=(int)curl_getinfo($this->ch,CURLINFO_RESPONSE_CODE);if($raw===false)fail('HTTP_TRANSPORT_FAILED: '.$err);
  $data=json_decode((string)$raw,true);if(!is_array($data))fail('NON_JSON_RESPONSE route='.$route.' status='.$status);
  if(isset($data['csrf'])&&is_string($data['csrf'])&&$data['csrf']!=='')$this->csrf=$data['csrf'];return[$status,$data];
 }
 public function expect(string $method,string $route,?array $body,int $status=200,?string $code=null): array {
  [$got,$data]=$this->request($method,$route,$body);
  if($got!==$status)fail("HTTP_EXPECTATION_FAILED {$route} expected={$status} got={$got} code=".($data['code']??'none'));
  if($code!==null&&($data['code']??null)!==$code)fail("CODE_EXPECTATION_FAILED {$route} expected={$code} got=".($data['code']??'none'));
  if($status<400&&(($data['ok']??false)!==true))fail("API_NOT_OK {$route}");return$data;
 }
 public function login(string $email,string $password): void {
  $r=$this->expect('POST','login',['email'=>$email,'password'=>$password],200);ok(isset($this->cookies['TSBOS'])&&$this->cookies['TSBOS']!=='','LOGIN_SESSION_COOKIE_MISSING');
  $me=$this->expect('GET','me',null,200);ok(!empty($me['csrf']),'ME_CSRF_MISSING');$this->csrf=(string)$me['csrf'];ok((int)($me['user']['id']??0)===(int)($r['user']['id']??0),'SESSION_USER_MISMATCH');
 }
}

$suffix=strtolower(bin2hex(random_bytes(5)));$password='V2r2!'.bin2hex(random_bytes(10)).'Aa7';
$ownerEmail="v2r2-owner-{$suffix}@teashop.bd";$opsEmail="v2r2-ops-{$suffix}@teashop.bd";$financeEmail="v2r2-finance-{$suffix}@teashop.bd";
$outletCode='V2R2-'.strtoupper($suffix);$outletName='V2 ROUND2 OUTLET '.$suffix;
$ownerId=$opsId=$financeId=$franchiseId=$packId=$settlementId=null;$cleaned=false;

$cleanup=function() use (&$cleaned,$pdo,&$ownerId,&$opsId,&$financeId,&$franchiseId,&$packId,$ownerEmail,$opsEmail,$financeEmail,$outletCode,$outletName): void {
 if($cleaned)return;$cleaned=true;
 try{
  $pdo->beginTransaction();$fid=(int)($franchiseId??0);
  if($fid<=0){$q=$pdo->prepare("SELECT id FROM franchises WHERE code=? LIMIT 1");$q->execute([$outletCode]);$fid=(int)($q->fetchColumn()?:0);}
  $ids=array_values(array_filter([(int)($ownerId??0),(int)($opsId??0),(int)($financeId??0)]));
  if($fid>0){
   $pdo->prepare("DELETE FROM support_ticket_updates WHERE support_ticket_id IN (SELECT id FROM support_tickets WHERE franchise_id=?)")->execute([$fid]);
   $pdo->prepare("DELETE FROM settlement_followups WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM operations_automation_links WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM operations_alerts WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM operations_tasks WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM support_tickets WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM pos_sale_items WHERE pos_sale_id IN (SELECT id FROM pos_sales WHERE franchise_id=?)")->execute([$fid]);
   $pdo->prepare("DELETE FROM pos_sales WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM settlements WHERE franchise_id=?")->execute([$fid]);
   foreach(['outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts','outlet_training_records','outlet_staff','outlet_daily_checkins','outlet_contracts','field_visits','outlet_communications','outlet_compliance_checks','marketing_executions','outlet_health_checks','outlet_timeline','outlet_checklist_items','outlet_pipeline','outlet_profiles'] as $table){$pdo->prepare("DELETE FROM {$table} WHERE franchise_id=?")->execute([$fid]);}
   $pdo->prepare("DELETE FROM documents WHERE reference_type IN('franchise','outlet') AND reference_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM franchises WHERE id=?")->execute([$fid]);
  }
  if((int)($packId??0)>0){$pdo->prepare("DELETE FROM inventory_ledger WHERE product_pack_id=?")->execute([(int)$packId]);$pdo->prepare("DELETE FROM product_packs WHERE id=?")->execute([(int)$packId]);}
  if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM audit_logs WHERE user_id IN ({$marks})")->execute($ids);$pdo->prepare("DELETE FROM user_franchise_scope WHERE user_id IN ({$marks})")->execute($ids);$pdo->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($ids);}
  else{$pdo->prepare("DELETE FROM users WHERE email IN(?,?,?)")->execute([$ownerEmail,$opsEmail,$financeEmail]);}
  $like='%'.$outletName.'%';$pdo->prepare("DELETE FROM notifications WHERE title LIKE ? OR message LIKE ?")->execute([$like,$like]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"CLEANUP_WARNING ".$e->getMessage()."\n");}
};
register_shutdown_function($cleanup);

try{
 echo "=== OPERATIONS V2 ROUND 2 E2E ===\n";echo "harness_version=1-intelligence-automation\n";echo "base_url={$baseUrl}\n";
 $products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
 ok($products===99,'PRODUCT_COUNT_CHANGED_'.$products);ok($roles>=12,'ROLES_MISSING');
 foreach(['operations_automation_links','settlement_followups'] as $table){$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);ok((int)$q->fetchColumn()===1,'TABLE_MISSING_'.$table);}
 echo "PRECONDITION=PASS products={$products} roles={$roles} tables=2/2\n";

 $roleIds=[];foreach(['OWNER','OPERATIONS','FINANCE'] as $code){$q=$pdo->prepare("SELECT id FROM roles WHERE code=?");$q->execute([$code]);$roleIds[$code]=(int)($q->fetchColumn()?:0);ok($roleIds[$code]>0,'ROLE_MISSING_'.$code);}
 $ins=$pdo->prepare("INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,0,1)");
 $ins->execute([$roleIds['OWNER'],'V2 R2 Test Owner',$ownerEmail,password_hash($password,PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
 $ins->execute([$roleIds['OPERATIONS'],'V2 R2 Test Operations',$opsEmail,password_hash($password,PASSWORD_DEFAULT)]);$opsId=(int)$pdo->lastInsertId();
 $ins->execute([$roleIds['FINANCE'],'V2 R2 Test Finance',$financeEmail,password_hash($password,PASSWORD_DEFAULT)]);$financeId=(int)$pdo->lastInsertId();

 $owner=new ApiClient($baseUrl);$owner->login($ownerEmail,$password);$ops=new ApiClient($baseUrl);$ops->login($opsEmail,$password);$finance=new ApiClient($baseUrl);$finance->login($financeEmail,$password);
 $finance->expect('GET','operations.round2',null,403,'ROLE_DENIED');echo "AUTH_RBAC=PASS owner_operations finance_denied\n";

 $created=$owner->expect('POST','franchise.create',['code'=>$outletCode,'name'=>$outletName,'division'=>'V2 R2 Division','district'=>'V2 R2 District','upazila'=>'V2 R2 Upazila','address'=>'Temporary V2 Round 2','owner_name'=>'Temporary Franchisee','owner_phone'=>'01700000004','owner_email'=>"v2r2-{$suffix}@example.invalid",'territory_code'=>'V2R2-'.$suffix,'margin_tier'=>'Starter','margin_percent'=>25],201);
 $franchiseId=(int)$created['id'];$z=$ops->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 foreach(($z['checklists']['opening']??[]) as $item)$ops->expect('POST','franchise.checklist.toggle',['franchise_id'=>$franchiseId,'id'=>(int)$item['id'],'completed'=>true,'label'=>$item['item_label']],200);
 $ops->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'live','next_action'=>'V2 Round 2'],200);
 echo "OUTLET_LIVE=PASS id={$franchiseId}\n";

 $productId=(int)($pdo->query("SELECT id FROM products WHERE active=1 ORDER BY id LIMIT 1")->fetchColumn()?:0);ok($productId>0,'NO_ACTIVE_TEA');
 $grams=8000+random_int(1,900);for($i=0;$i<50;$i++){$q=$pdo->prepare("SELECT COUNT(*) FROM product_packs WHERE product_id=? AND grams=?");$q->execute([$productId,$grams]);if((int)$q->fetchColumn()===0)break;$grams++;}
 $q=$pdo->prepare("INSERT INTO product_packs(product_id,grams,packaging_bom,mrp,active) VALUES(?,?,NULL,100,1)");$q->execute([$productId,$grams]);$packId=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO inventory_ledger(location_type,location_id,product_pack_id,movement_type,qty,unit_value,reference_type,reference_id,created_by) VALUES('central',NULL,?,'opening',10,100,'e2e_v2_round2',?,?)");$q->execute([$packId,random_int(100000,999999),$ownerId]);
 $ops->expect('POST','inventory.transfer.create',['franchise_id'=>$franchiseId,'product_pack_id'=>$packId,'qty'=>10],201);
 $sale=$ops->expect('POST','sale.create',['franchise_id'=>$franchiseId,'items'=>[['product_pack_id'=>$packId,'qty'=>9]],'payment_method'=>'cash'],201);ok(closeEnough((float)$sale['gross_amount'],900),'POS_GROSS_FAILED');
 $monthStart=date('Y-m-01');$monthEnd=date('Y-m-t');
 $ops->expect('POST','operations.target.save',['franchise_id'=>$franchiseId,'period_start'=>$monthStart,'period_end'=>$monthEnd,'sales_target'=>1000,'receipt_target'=>1,'notes'=>'V2 Round 2 target'],200);
 $ops->expect('POST','operations.inventory_policy.save',['franchise_id'=>$franchiseId,'product_pack_id'=>$packId,'min_days_cover'=>7,'target_days_cover'=>21,'max_days_cover'=>60,'dead_stock_days'=>30],200);
 echo "TARGET_REORDER_FOUNDATION=PASS sales=900 target=1000 stock=1\n";

 $finance->expect('POST','settlement.generate',['period'=>date('Y-m'),'franchise_id'=>$franchiseId],200);
 $q=$pdo->prepare("SELECT id FROM settlements WHERE franchise_id=? ORDER BY id DESC LIMIT 1");$q->execute([$franchiseId]);$settlementId=(int)($q->fetchColumn()?:0);ok($settlementId>0,'SETTLEMENT_MISSING');
 $agedEnd=date('Y-m-d',strtotime('-20 days'));$agedStart=date('Y-m-01',strtotime($agedEnd));$q=$pdo->prepare("UPDATE settlements SET period_start=?,period_end=? WHERE id=?");$q->execute([$agedStart,$agedEnd,$settlementId]);
 $follow=$ops->expect('POST','operations.settlement.followup.save',['settlement_id'=>$settlementId,'franchise_id'=>$franchiseId,'channel'=>'whatsapp','status'=>'promised','promised_amount'=>100,'promised_date'=>date('Y-m-d',strtotime('+2 days')),'note'=>'V2 Round 2 payment promise','evidence_ref'=>'e2e://payment-promise'],201);
 ok((int)$follow['id']>0&&$follow['status']==='promised','SETTLEMENT_FOLLOWUP_SAVE_FAILED');
 echo "SETTLEMENT_FOLLOWUP=PASS settlement_id={$settlementId}\n";

 $staff=$ops->expect('POST','franchise.staff.create',['franchise_id'=>$franchiseId,'name'=>'V2 R2 Staff','staff_role'=>'Outlet Manager'],201);$staffId=(int)$staff['id'];
 $training=$ops->expect('POST','franchise.training.save',['franchise_id'=>$franchiseId,'outlet_staff_id'=>$staffId,'course_code'=>'V2R2','course_title'=>'POS & Retail Operations','status'=>'expired','scheduled_at'=>null,'completed_at'=>date('Y-m-d H:i:s',strtotime('-40 days')),'expires_at'=>date('Y-m-d',strtotime('-1 day')),'trainer'=>'Operations','certificate_ref'=>'E2E-CERT','notes'=>'Intentional expired training'],200);
 $trainingId=(int)$training['id'];ok($trainingId>0,'TRAINING_MISSING');
 echo "TRAINING_ATTENTION_FOUNDATION=PASS training_id={$trainingId}\n";

 $ticketIds=[];
 for($i=1;$i<=3;$i++){$t=$ops->expect('POST','operations.ticket.create',['franchise_id'=>$franchiseId,'category'=>'customer','subject'=>'V2 Round 2 customer complaint '.$i,'detail'=>'Temporary complaint for automation and care center','priority'=>'high','assigned_user_id'=>$opsId],201);$ticketIds[]=(int)$t['id'];}
 echo "CUSTOMER_CARE_FOUNDATION=PASS complaints=3\n";

 $run=$ops->expect('POST','operations.automation.run',['franchise_id'=>$franchiseId],200);
 ok((int)($run['signals']??0)>=6,'AUTOMATION_SIGNALS_LOW_'.($run['signals']??0));ok((int)($run['created_tasks']??0)>=6,'AUTOMATION_CREATED_LOW_'.($run['created_tasks']??0));
 $run2=$ops->expect('POST','operations.automation.run',['franchise_id'=>$franchiseId],200);ok((int)($run2['created_tasks']??-1)===0,'AUTOMATION_NOT_IDEMPOTENT');
 echo "AUTO_TASK_ENGINE=PASS signals=".$run['signals']." created=".$run['created_tasks']." rerun_created=0\n";

 $r2=$ops->expect('GET','operations.round2',null,200);
 $targetRows=array_values(array_filter($r2['targets']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));ok(count($targetRows)===1,'TARGET_BREAKDOWN_MISSING');
 ok(closeEnough((float)$targetRows[0]['sales_mtd'],900)&&closeEnough((float)$targetRows[0]['achievement'],90)&&closeEnough((float)$targetRows[0]['remaining_target'],100),'TARGET_BREAKDOWN_VALUES_FAILED');
 $reorderRows=array_values(array_filter($r2['reorders']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId&&(int)($r['product_pack_id']??0)===$packId));ok(count($reorderRows)===1,'REORDER_ROW_MISSING');ok($reorderRows[0]['stock_status']==='low'&&(int)$reorderRows[0]['suggested_reorder_qty']>0,'REORDER_VALUES_FAILED');
 echo "TARGET_BREAKDOWN_REORDER=PASS achievement=90 remaining=100 reorder=".$reorderRows[0]['suggested_reorder_qty']."\n";

 $setRows=array_values(array_filter($r2['settlements']??[],fn($r)=>(int)($r['id']??0)===$settlementId));ok(count($setRows)===1&&($setRows[0]['followup_status']??'')==='promised','FOLLOWUP_READBACK_FAILED');
 $complaints=array_values(array_filter($r2['complaints']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));ok(count($complaints)===3,'CUSTOMER_CARE_READBACK_FAILED');
 $trainRows=array_values(array_filter($r2['training_attention']??[],fn($r)=>(int)($r['id']??0)===$trainingId));ok(count($trainRows)===1&&($trainRows[0]['attention_state']??'')==='expired','TRAINING_COMPLIANCE_READBACK_FAILED');
 echo "FOLLOWUP_CUSTOMER_TRAINING=PASS promised complaints=3 training=expired\n";

 $auto=array_values(array_filter($r2['automation']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId&&empty($r['resolved_at'])));ok(count($auto)>=6,'AUTOMATION_LINKS_MISSING');
 $risk=array_values(array_filter($r2['risk']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));$types=array_values(array_unique(array_column($risk,'risk_type')));
 foreach(['settlement_overdue','stock_risk','training_expired','complaint_spike'] as $type)ok(in_array($type,$types,true),'RISK_TYPE_MISSING_'.$type);
 echo "RISK_CENTER=PASS types=".implode(',',$types)."\n";

 $q=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id IN(?,?,?)");$q->execute([$ownerId,$opsId,$financeId]);$audit=(int)$q->fetchColumn();ok($audit>=15,'AUDIT_COVERAGE_LOW_'.$audit);
 echo "AUDIT_LOG=PASS rows={$audit}\n";
 echo "OPERATIONS_V2_ROUND2_E2E_RESULT=GREEN\n";echo "OPERATIONS_V2_ROUND2_CLEANUP=PENDING\n";
}catch(Throwable $e){
 fwrite(STDERR,"OPERATIONS_V2_ROUND2_E2E_RESULT=FAIL\n");fwrite(STDERR,"OPERATIONS_V2_ROUND2_E2E_ERROR=".$e->getMessage()."\n");$cleanup();echo "OPERATIONS_V2_ROUND2_CLEANUP=DONE\n";exit(1);
}

$cleanup();echo "OPERATIONS_V2_ROUND2_CLEANUP=DONE\n";
$q=$pdo->prepare("SELECT COUNT(*) FROM franchises WHERE code=?");$q->execute([$outletCode]);$fr=(int)$q->fetchColumn();
$q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN(?,?,?)");$q->execute([$ownerEmail,$opsEmail,$financeEmail]);$users=(int)$q->fetchColumn();
$pack=0;if((int)($packId??0)>0){$q=$pdo->prepare("SELECT COUNT(*) FROM product_packs WHERE id=?");$q->execute([(int)$packId]);$pack=(int)$q->fetchColumn();}
if($fr||$users||$pack){fwrite(STDERR,"OPERATIONS_V2_ROUND2_CLEANUP_VERIFY=FAIL franchise={$fr} users={$users} pack={$pack}\n");exit(1);}
ok((int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn()===99,'POST_CLEANUP_PRODUCT_COUNT_CHANGED');
echo "OPERATIONS_V2_ROUND2_CLEANUP_VERIFY=PASS\n";echo "OPERATIONS_V2_ROUND2_FINAL_GREEN\n";
