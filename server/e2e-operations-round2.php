<?php
declare(strict_types=1);

/**
 * Tea Shop BD — Operations Round 2 Commercial & Final Closure E2E
 *
 * Production-safe pattern:
 * - random temporary Owner / Operations / Finance users
 * - one temporary outlet
 * - one temporary product pack under an existing Tea (99 Tea master count unchanged)
 * - API-driven stock transfer, POS, margins, settlement, intelligence, alerts,
 *   performance approval/payment, reports and closure
 * - deterministic cleanup + residue verification
 *
 * Usage:
 *   php server/e2e-operations-round2.php
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
function closeEnough(float $a,float $b,float $eps=0.01): bool { return abs($a-$b)<=$eps; }
function sqlDate(string $expr): string { $t=strtotime($expr);if($t===false)fail('INVALID_DATE_'.$expr);return date('Y-m-d',$t); }
function sqlTime(string $expr): string { $t=strtotime($expr);if($t===false)fail('INVALID_TIME_'.$expr);return date('Y-m-d H:i:s',$t); }

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
   CURLOPT_TIMEOUT=>45,
   CURLOPT_FOLLOWLOCATION=>false,
  ]);
 }
 public function __destruct(){ curl_close($this->ch); }
 private function cookieHeader(): string {
  $parts=[];foreach($this->cookies as $k=>$v)$parts[]=$k.'='.$v;return implode('; ',$parts);
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
     $cookie=trim(substr($line,11));$pair=explode(';',$cookie,2)[0]??'';
     if(str_contains($pair,'=')){[$name,$value]=explode('=',$pair,2);$name=trim($name);$value=trim($value);if($name!=='')$this->cookies[$name]=$value;}
    }
    return strlen($line);
   },
  ]);
  $raw=curl_exec($this->ch);$error=curl_error($this->ch);$status=(int)curl_getinfo($this->ch,CURLINFO_RESPONSE_CODE);
  if($raw===false)fail('HTTP_TRANSPORT_FAILED: '.$error);
  $data=json_decode((string)$raw,true);
  if(!is_array($data))fail('NON_JSON_RESPONSE status='.$status);
  if(isset($data['csrf'])&&is_string($data['csrf'])&&$data['csrf']!=='')$this->csrf=$data['csrf'];
  return [$status,$data];
 }
 public function expect(string $method,string $route,?array $body,int $status=200,?string $code=null): array {
  [$got,$data]=$this->request($method,$route,$body);
  if($got!==$status)fail("HTTP_EXPECTATION_FAILED {$route} expected={$status} got={$got} code=".($data['code']??'none')." cookies=".count($this->cookies)." csrf=".($this->csrf!==null?'set':'missing'));
  if($code!==null&&($data['code']??null)!==$code)fail("CODE_EXPECTATION_FAILED {$route} expected={$code} got=".($data['code']??'none'));
  if($status<400&&(($data['ok']??false)!==true))fail("API_NOT_OK {$route}");
  return $data;
 }
 public function login(string $email,string $password): array {
  $r=$this->expect('POST','login',['email'=>$email,'password'=>$password],200);
  ok(!empty($r['csrf']),'LOGIN_CSRF_MISSING');
  ok(isset($this->cookies['TSBOS'])&&$this->cookies['TSBOS']!=='','LOGIN_SESSION_COOKIE_MISSING');
  $me=$this->expect('GET','me',null,200);
  ok(!empty($me['csrf']),'ME_CSRF_MISSING');
  $this->csrf=(string)$me['csrf'];
  ok((int)($me['user']['id']??0)===(int)($r['user']['id']??0),'SESSION_USER_MISMATCH');
  return $me;
 }
}

$suffix=strtolower(bin2hex(random_bytes(5)));
$password='E2e!'.bin2hex(random_bytes(12)).'Aa7';
$ownerEmail="e2e2-owner-{$suffix}@teashop.bd";
$opsEmail="e2e2-operations-{$suffix}@teashop.bd";
$financeEmail="e2e2-finance-{$suffix}@teashop.bd";
$outletCode='E2E2-'.strtoupper($suffix);
$outletName='ROUND2 E2E OUTLET '.$suffix;
$division='E2E Division '.strtoupper($suffix);
$district='E2E District '.strtoupper($suffix);
$futurePeriod='2099-12';$futureStart='2099-12-01';$futureEnd='2099-12-31';

$ownerId=null;$opsId=null;$financeId=null;$franchiseId=null;$packId=null;$performanceId=null;$snapshotId=null;$cleaned=false;
$tempIds=[];

$cleanup=function() use (&$cleaned,$pdo,&$ownerId,&$opsId,&$financeId,&$franchiseId,&$packId,&$performanceId,&$snapshotId,$ownerEmail,$opsEmail,$financeEmail,$outletCode,$outletName,$futureStart,$futureEnd): void {
 if($cleaned)return;$cleaned=true;
 try{
  $pdo->beginTransaction();
  $fid=(int)($franchiseId??0);
  if($fid<=0){$q=$pdo->prepare("SELECT id FROM franchises WHERE code=? LIMIT 1");$q->execute([$outletCode]);$fid=(int)($q->fetchColumn()?:0);}
  $ids=array_values(array_filter([(int)($ownerId??0),(int)($opsId??0),(int)($financeId??0)]));
  if(!$ids){$q=$pdo->prepare("SELECT id FROM users WHERE email IN(?,?,?)");$q->execute([$ownerEmail,$opsEmail,$financeEmail]);$ids=array_map('intval',array_column($q->fetchAll(),'id'));}

  if($fid>0){
   $pdo->prepare("DELETE FROM support_ticket_updates WHERE support_ticket_id IN (SELECT id FROM support_tickets WHERE franchise_id=?)")->execute([$fid]);
   $pdo->prepare("DELETE FROM pos_sale_items WHERE pos_sale_id IN (SELECT id FROM pos_sales WHERE franchise_id=?)")->execute([$fid]);
   $pdo->prepare("DELETE FROM pos_sales WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM settlements WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM franchise_margin_history WHERE franchise_id=?")->execute([$fid]);
   foreach([
    'operations_alerts','operations_tasks','field_visits','support_tickets','outlet_communications','outlet_compliance_checks','marketing_executions',
    'outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts','outlet_health_checks','outlet_training_records','outlet_staff',
    'outlet_timeline','outlet_checklist_items','outlet_pipeline','outlet_profiles'
   ] as $table){$pdo->prepare("DELETE FROM {$table} WHERE franchise_id=?")->execute([$fid]);}
   $pdo->prepare("DELETE FROM documents WHERE reference_type IN('franchise','outlet') AND reference_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE franchise_id=?")->execute([$fid]);
   $pdo->prepare("DELETE FROM franchises WHERE id=?")->execute([$fid]);
  }

  if($ids){
   $marks=implode(',',array_fill(0,count($ids),'?'));
   $pdo->prepare("DELETE FROM inventory_ledger WHERE created_by IN ({$marks})")->execute($ids);
   $pdo->prepare("DELETE FROM finance_ledger WHERE created_by IN ({$marks}) AND reference_type='e2e_round2'")->execute($ids);
   $pdo->prepare("DELETE FROM operations_report_snapshots WHERE generated_by IN ({$marks})")->execute($ids);
   $pdo->prepare("DELETE FROM user_franchise_scope WHERE user_id IN ({$marks})")->execute($ids);
   $pdo->prepare("DELETE FROM audit_logs WHERE user_id IN ({$marks})")->execute($ids);
  }
  if((int)($performanceId??0)>0)$pdo->prepare("DELETE FROM performance_records WHERE id=?")->execute([(int)$performanceId]);
  if((int)($snapshotId??0)>0)$pdo->prepare("DELETE FROM operations_report_snapshots WHERE id=?")->execute([(int)$snapshotId]);

  $like='%'.$outletName.'%';
  $pdo->prepare("DELETE FROM notifications WHERE title LIKE ? OR message LIKE ?")->execute([$like,$like]);

  if((int)($packId??0)>0){
   $pdo->prepare("DELETE FROM inventory_ledger WHERE product_pack_id=? AND reference_type='e2e_round2'")->execute([(int)$packId]);
   $pdo->prepare("DELETE FROM outlet_inventory_policies WHERE product_pack_id=?")->execute([(int)$packId]);
   $pdo->prepare("DELETE FROM outlet_stock_counts WHERE product_pack_id=?")->execute([(int)$packId]);
   $pdo->prepare("DELETE FROM product_packs WHERE id=?")->execute([(int)$packId]);
  }

  if($ids){
   $marks=implode(',',array_fill(0,count($ids),'?'));
   $pdo->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($ids);
  }else{
   $pdo->prepare("DELETE FROM users WHERE email IN(?,?,?)")->execute([$ownerEmail,$opsEmail,$financeEmail]);
  }
  $pdo->commit();
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  fwrite(STDERR,"CLEANUP_WARNING ".$e->getMessage()."\n");
 }
};
register_shutdown_function($cleanup);

try{
 echo "=== ROUND 2 COMMERCIAL & FINAL CLOSURE E2E ===\n";
 echo "harness_version=1-commercial-final\n";
 echo "base_url={$baseUrl}\n";

 $products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
 $roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
 ok($products===99,'PRECONDITION_PRODUCTS_'.$products);ok($roles>=12,'PRECONDITION_ROLES_'.$roles);
 $q=$pdo->prepare("SELECT COUNT(*) FROM performance_records WHERE role_code='OPERATIONS' AND period_start=? AND period_end=?");$q->execute([$futureStart,$futureEnd]);ok((int)$q->fetchColumn()===0,'FUTURE_PERFORMANCE_PERIOD_NOT_EMPTY');
 $q=$pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE DATE(sold_at) BETWEEN ? AND ?");$q->execute([$futureStart,$futureEnd]);ok((int)$q->fetchColumn()===0,'FUTURE_SALES_PERIOD_NOT_EMPTY');
 $q=$pdo->prepare("SELECT COUNT(*) FROM finance_ledger WHERE entry_date BETWEEN ? AND ?");$q->execute([$futureStart,$futureEnd]);ok((int)$q->fetchColumn()===0,'FUTURE_FINANCE_PERIOD_NOT_EMPTY');
 echo "PRECONDITION=PASS products={$products} roles={$roles} isolated_period={$futurePeriod}\n";

 // Temporary identities.
 $roleIds=[];
 foreach(['OWNER','OPERATIONS','FINANCE'] as $code){$q=$pdo->prepare("SELECT id FROM roles WHERE code=? LIMIT 1");$q->execute([$code]);$roleIds[$code]=(int)($q->fetchColumn()?:0);ok($roleIds[$code]>0,'ROLE_MISSING_'.$code);}
 $ins=$pdo->prepare("INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,0,1)");
 $ins->execute([$roleIds['OWNER'],'Round 2 E2E Owner',$ownerEmail,password_hash($password,PASSWORD_DEFAULT)]);$ownerId=(int)$pdo->lastInsertId();
 $ins->execute([$roleIds['OPERATIONS'],'Round 2 E2E Operations',$opsEmail,password_hash($password,PASSWORD_DEFAULT)]);$opsId=(int)$pdo->lastInsertId();
 $ins->execute([$roleIds['FINANCE'],'Round 2 E2E Finance',$financeEmail,password_hash($password,PASSWORD_DEFAULT)]);$financeId=(int)$pdo->lastInsertId();
 echo "TEMP_AUTH_IDENTITIES=PASS\n";

 $public=new ApiClient($baseUrl);$health=$public->expect('GET','health',null,200);ok(($health['database']??'')==='connected','HEALTH_DATABASE_NOT_CONNECTED');
 $owner=new ApiClient($baseUrl);$owner->login($ownerEmail,$password);
 $ops=new ApiClient($baseUrl);$ops->login($opsEmail,$password);
 $finance=new ApiClient($baseUrl);$finance->login($financeEmail,$password);
 echo "AUTH_OWNER_OPERATIONS_FINANCE=PASS\n";

 // Isolated temporary sellable pack under an existing Tea; Tea master stays 99.
 $productId=(int)($pdo->query("SELECT id FROM products WHERE active=1 ORDER BY id LIMIT 1")->fetchColumn()?:0);ok($productId>0,'NO_ACTIVE_TEA');
 $grams=9000+random_int(1,900);
 for($i=0;$i<50;$i++){
  $q=$pdo->prepare("SELECT COUNT(*) FROM product_packs WHERE product_id=? AND grams=?");$q->execute([$productId,$grams]);
  if((int)$q->fetchColumn()===0)break;$grams++;
 }
 $q=$pdo->prepare("INSERT INTO product_packs(product_id,grams,packaging_bom,mrp,active) VALUES(?,?,NULL,100.00,1)");$q->execute([$productId,$grams]);$packId=(int)$pdo->lastInsertId();
 ok((int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn()===99,'TEA_MASTER_COUNT_CHANGED');
 echo "TEMP_SELLABLE_PACK=PASS pack_id={$packId} mrp=100 tea_master=99\n";

 // Create and activate temporary outlet using real opening gate.
 $created=$owner->expect('POST','franchise.create',[
  'code'=>$outletCode,'name'=>$outletName,'division'=>$division,'district'=>$district,'upazila'=>'E2E Upazila',
  'address'=>'Round 2 E2E temporary address','owner_name'=>'Round 2 Franchisee','owner_phone'=>'01700000002',
  'owner_email'=>"round2-{$suffix}@example.invalid",'territory_code'=>'E2E-'.$suffix,'margin_tier'=>'Starter','margin_percent'=>25
 ],201);
 $franchiseId=(int)$created['id'];ok($franchiseId>0,'FRANCHISE_CREATE_FAILED');
 $setup=$ops->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 foreach(($setup['checklists']['opening']??[]) as $item)$ops->expect('POST','franchise.checklist.toggle',['franchise_id'=>$franchiseId,'id'=>(int)$item['id'],'completed'=>true,'label'=>$item['item_label'],'notes'=>'Round 2 setup'],200);
 $ops->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'live','target_open_date'=>date('Y-m-d'),'next_action'=>'Commercial E2E','blocking_reason'=>''],200);
 $ops->expect('POST','franchise.health.save',['franchise_id'=>$franchiseId,'sales_score'=>90,'stock_score'=>90,'settlement_score'=>90,'compliance_score'=>90,'notes'=>'Round 2 E2E'],201);
 echo "OUTLET_LIVE_FOUNDATION=PASS id={$franchiseId}\n";

 // Seed temporary central stock and transfer using actual API.
 $seedRef=random_int(100000000,999999999);
 $q=$pdo->prepare("INSERT INTO inventory_ledger(location_type,location_id,product_pack_id,movement_type,qty,unit_value,reference_type,reference_id,created_by) VALUES('central',NULL,?,'opening',10,100,'e2e_round2',?,?)");
 $q->execute([$packId,$seedRef,$ownerId]);
 $transfer=$ops->expect('POST','inventory.transfer.create',['franchise_id'=>$franchiseId,'product_pack_id'=>$packId,'qty'=>10],201);
 ok(!empty($transfer['reference']),'TRANSFER_REFERENCE_MISSING');
 $beforeSales=$ops->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 ok(closeEnough((float)($beforeSales['sales']['sales_30d']??-1),0),'STOCK_RECEIPT_CREATED_SALES');
 ok(closeEnough((float)($beforeSales['sales']['margin_30d']??-1),0),'STOCK_RECEIPT_CREATED_MARGIN');
 $stockHit=array_values(array_filter($beforeSales['stock']??[],fn($r)=>(int)($r['pack_id']??0)===$packId));
 ok(count($stockHit)===1&&closeEnough((float)$stockHit[0]['qty'],10),'OUTLET_TRANSFER_QTY_MISMATCH');
 echo "STOCK_TRANSFER=PASS stock=10 stock_received_not_profit=PASS\n";

 // Operations cannot override margin.
 $ops->expect('POST','franchise.margin.update',['id'=>$franchiseId,'margin_percent'=>30],403,'OWNER_REQUIRED');

 // 25 / 27 / 30 / custom margin sales using verified POS.
 $sales=[];
 $sale1=$ops->expect('POST','sale.create',['franchise_id'=>$franchiseId,'items'=>[['product_pack_id'=>$packId,'qty'=>2]],'payment_method'=>'cash'],201);
 ok(closeEnough((float)$sale1['gross_amount'],200)&&closeEnough((float)$sale1['earned_margin'],50),'MARGIN_25_CALC_FAILED');$sales[]=(int)$sale1['id'];

 $m27=$owner->expect('POST','franchise.margin.update',['id'=>$franchiseId,'margin_percent'=>27,'reason'=>'Round 2 Growth tier'],200);
 ok(($m27['margin_tier']??'')==='Growth','MARGIN_27_TIER_FAILED');
 $sale2=$ops->expect('POST','sale.create',['franchise_id'=>$franchiseId,'items'=>[['product_pack_id'=>$packId,'qty'=>2]],'payment_method'=>'card'],201);
 ok(closeEnough((float)$sale2['earned_margin'],54),'MARGIN_27_CALC_FAILED');$sales[]=(int)$sale2['id'];

 $m30=$owner->expect('POST','franchise.margin.update',['id'=>$franchiseId,'margin_percent'=>30,'reason'=>'Round 2 Elite tier'],200);
 ok(($m30['margin_tier']??'')==='Elite','MARGIN_30_TIER_FAILED');
 $sale3=$ops->expect('POST','sale.create',['franchise_id'=>$franchiseId,'items'=>[['product_pack_id'=>$packId,'qty'=>2]],'payment_method'=>'mobile'],201);
 ok(closeEnough((float)$sale3['earned_margin'],60),'MARGIN_30_CALC_FAILED');$sales[]=(int)$sale3['id'];

 $mCustom=$owner->expect('POST','franchise.margin.update',['id'=>$franchiseId,'margin_percent'=>28.5,'reason'=>'Round 2 approved custom margin'],200);
 ok(($mCustom['margin_tier']??'')==='Manual'&&($mCustom['margin_mode']??'')==='manual','CUSTOM_MARGIN_MODE_FAILED');
 $sale4=$ops->expect('POST','sale.create',['franchise_id'=>$franchiseId,'items'=>[['product_pack_id'=>$packId,'qty'=>3]],'payment_method'=>'cash'],201);
 ok(closeEnough((float)$sale4['gross_amount'],300)&&closeEnough((float)$sale4['earned_margin'],85.5),'CUSTOM_MARGIN_CALC_FAILED');$sales[]=(int)$sale4['id'];
 echo "POS_MARGIN_CHAIN=PASS starter25 growth27 elite30 custom28.5 gross=900 margin=249.50\n";

 // Physical count: mismatch record only, inventory ledger stays unchanged.
 $count=$ops->expect('POST','operations.stock_count.save',['franchise_id'=>$franchiseId,'product_pack_id'=>$packId,'physical_qty'=>0.5,'notes'=>'Round 2 controlled mismatch'],201);
 ok(closeEnough((float)$count['system_qty'],1)&&closeEnough((float)$count['variance_qty'],-0.5)&&($count['status']??'')==='review','PHYSICAL_COUNT_MISMATCH_FAILED');
 $inv=$ops->expect('GET','inventory.franchise?franchise_id='.$franchiseId,null,200);
 $line=array_values(array_filter($inv['stock']??[],fn($r)=>(int)($r['pack_id']??0)===$packId));
 ok(count($line)===1&&closeEnough((float)$line[0]['qty'],1),'PHYSICAL_COUNT_AUTO_ADJUSTED_LEDGER');
 echo "PHYSICAL_COUNT=PASS system=1 physical=0.5 ledger_unchanged=PASS\n";

 // Target + stock intelligence + reorder.
 $monthStart=date('Y-m-01');$monthEnd=date('Y-m-t');
 $ops->expect('POST','operations.target.save',['franchise_id'=>$franchiseId,'period_start'=>$monthStart,'period_end'=>$monthEnd,'sales_target'=>1000,'receipt_target'=>4,'notes'=>'Round 2 target'],200);
 $ops->expect('POST','operations.inventory_policy.save',['franchise_id'=>$franchiseId,'product_pack_id'=>$packId,'min_days_cover'=>7,'target_days_cover'=>21,'max_days_cover'=>60,'dead_stock_days'=>30],200);
 $intel=$ops->expect('GET','operations.intelligence',null,200);
 $outletRows=array_values(array_filter($intel['outlets']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));
 ok(count($outletRows)===1,'INTELLIGENCE_OUTLET_MISSING');
 $oi=$outletRows[0];ok(closeEnough((float)$oi['sales_mtd'],900)&&closeEnough((float)$oi['target_achievement'],90),'TARGET_ACHIEVEMENT_FAILED');
 $skuRows=array_values(array_filter($intel['inventory']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId&&(int)($r['product_pack_id']??0)===$packId));
 ok(count($skuRows)===1,'INTELLIGENCE_SKU_MISSING');
 $si=$skuRows[0];ok(($si['stock_status']??'')==='low','LOW_STOCK_CLASSIFICATION_FAILED');ok((int)($si['suggested_reorder_qty']??0)>0,'REORDER_SUGGESTION_MISSING');
 echo "SALES_TARGET_STOCK_INTELLIGENCE=PASS target=90% status=low reorder=".$si['suggested_reorder_qty']."\n";

 // Settlement: safe scoped generation. Operations is denied; Finance generates.
 $ops->expect('POST','settlement.generate',['period'=>date('Y-m'),'franchise_id'=>$franchiseId],403,'ROLE_DENIED');
 $finance->expect('POST','settlement.generate',['period'=>date('Y-m'),'franchise_id'=>$franchiseId],200);
 $q=$pdo->prepare("SELECT * FROM settlements WHERE franchise_id=? ORDER BY id DESC LIMIT 1");$q->execute([$franchiseId]);$settlement=$q->fetch();ok((bool)$settlement,'SETTLEMENT_NOT_CREATED');
 $settlementId=(int)$settlement['id'];
 ok(closeEnough((float)$settlement['verified_sales'],900),'SETTLEMENT_VERIFIED_SALES_FAILED');
 ok(closeEnough((float)$settlement['earned_margin'],249.5),'SETTLEMENT_MARGIN_FAILED');
 ok(closeEnough((float)$settlement['stock_received_value'],1000),'SETTLEMENT_STOCK_RECEIVED_FAILED');
 ok(closeEnough((float)$settlement['net_payable'],650.5),'SETTLEMENT_NET_PAYABLE_FAILED');
 echo "SETTLEMENT_FORMULA=PASS received=1000 sales=900 margin=249.50 company_receivable=650.50\n";

 // Age the isolated test settlement for aging/alert validation only.
 $agedEnd=sqlDate('-20 days');$agedStart=date('Y-m-01',strtotime($agedEnd));
 $q=$pdo->prepare("UPDATE settlements SET period_start=?,period_end=? WHERE id=?");$q->execute([$agedStart,$agedEnd,$settlementId]);
 $aging=$ops->expect('GET','operations.intelligence',null,200);
 $agedRows=array_values(array_filter($aging['settlements']??[],fn($r)=>(int)($r['id']??0)===$settlementId));
 ok(count($agedRows)===1&&($agedRows[0]['aging_bucket']??'')==='16-30','SETTLEMENT_AGING_BUCKET_FAILED');
 ok(($agedRows[0]['direction']??'')==='company_receivable','SETTLEMENT_DIRECTION_FAILED');
 echo "SETTLEMENT_AGING=PASS bucket=16-30 direction=company_receivable\n";

 // Overdue work + escalation for alert engine.
 $task=$ops->expect('POST','operations.task.create',[
  'franchise_id'=>$franchiseId,'task_type'=>'settlement','title'=>'Round 2 overdue settlement follow-up','detail'=>'E2E escalation',
  'priority'=>'critical','assigned_user_id'=>$opsId,'due_at'=>sqlTime('-2 hours')
 ],201);
 $taskId=(int)$task['id'];
 $taskEsc=$ops->expect('POST','operations.task.update',['id'=>$taskId,'status'=>'in_progress','escalate'=>true],200);
 ok((int)($taskEsc['escalation_level']??0)===1,'TASK_ESCALATION_FAILED');

 $ticket=$ops->expect('POST','operations.ticket.create',[
  'franchise_id'=>$franchiseId,'category'=>'payment','subject'=>'Round 2 overdue payment issue','detail'=>'E2E alert',
  'priority'=>'high','assigned_user_id'=>$opsId,'due_at'=>sqlTime('-1 hour')
 ],201);
 $ticketId=(int)$ticket['id'];
 $ticketEsc=$ops->expect('POST','operations.ticket.update',['id'=>$ticketId,'status'=>'in_progress','escalate'=>true,'note'=>'Round 2 escalation'],200);
 ok((int)($ticketEsc['escalation_level']??0)===1,'TICKET_ESCALATION_FAILED');

 $refresh=$ops->expect('POST','operations.alerts.refresh',['franchise_id'=>$franchiseId],200);
 ok((int)($refresh['generated']??0)>=4,'SCOPED_ALERT_GENERATION_LOW');
 $alerts=$ops->expect('GET','operations.alerts',null,200);
 $testAlerts=array_values(array_filter($alerts['alerts']??[],fn($r)=>(int)($r['franchise_id']??0)===$franchiseId));
 $types=array_values(array_unique(array_column($testAlerts,'alert_type')));
 foreach(['settlement_overdue','task_overdue','ticket_overdue','stock_mismatch','stock_risk'] as $type)ok(in_array($type,$types,true),'ALERT_TYPE_MISSING_'.$type);
 $firstAlert=$testAlerts[0]??null;ok((bool)$firstAlert,'NO_ALERT_TO_ACK');
 $ops->expect('POST','operations.alert.update',['id'=>(int)$firstAlert['id'],'status'=>'acknowledged'],200);
 $ops->expect('POST','operations.alert.update',['id'=>(int)$firstAlert['id'],'status'=>'resolved'],200);
 echo "ALERT_ESCALATION=PASS types=".implode(',',$types)."\n";

 // Owner lock, Finance pay. Operations cannot lock.
 $ops->expect('POST','settlement.lock',['id'=>$settlementId],403,'OWNER_REQUIRED');
 $owner->expect('POST','settlement.lock',['id'=>$settlementId],200);
 $finance->expect('POST','settlement.paid',['id'=>$settlementId],200);
 $q=$pdo->prepare("SELECT status FROM settlements WHERE id=?");$q->execute([$settlementId]);ok($q->fetchColumn()==='paid','SETTLEMENT_PAID_FAILED');
 echo "SETTLEMENT_APPROVAL_PAYMENT=PASS owner_lock finance_paid\n";

 // Isolate API-created sales in a far-future period for deterministic performance P&L.
 $marks=implode(',',array_fill(0,count($sales),'?'));
 $q=$pdo->prepare("UPDATE pos_sales SET sold_at='2099-12-15 12:00:00' WHERE id IN ({$marks})");$q->execute($sales);
 $q=$pdo->prepare("INSERT INTO finance_ledger(entry_date,division,account_code,entry_type,amount,reference_type,reference_id,memo,created_by) VALUES('2099-12-15','FRANCHISE','E2E-OPS','debit',100,'e2e_round2',?,'Round 2 approved franchise division expense',?)");
 $q->execute([$seedRef,$ownerId]);

 // Future target + scoped settlement for performance settlement-discipline input.
 $ops->expect('POST','operations.target.save',['franchise_id'=>$franchiseId,'period_start'=>$futureStart,'period_end'=>$futureEnd,'sales_target'=>1000,'receipt_target'=>4,'notes'=>'Round 2 future isolated target'],200);
 $finance->expect('POST','settlement.generate',['period'=>$futurePeriod,'franchise_id'=>$franchiseId],200);
 $q=$pdo->prepare("SELECT id FROM settlements WHERE franchise_id=? AND period_start=? AND period_end=?");$q->execute([$franchiseId,$futureStart,$futureEnd]);$futureSettlementId=(int)($q->fetchColumn()?:0);ok($futureSettlementId>0,'FUTURE_SETTLEMENT_MISSING');
 $owner->expect('POST','settlement.lock',['id'=>$futureSettlementId],200);
 $finance->expect('POST','settlement.paid',['id'=>$futureSettlementId],200);

 // P&L-based Operations performance: review -> Owner approval -> Finance paid.
 $preview=$ops->expect('GET','operations.performance.preview?period='.$futurePeriod,null,200);
 $p=$preview['preview']??[];
 ok(closeEnough((float)($p['verified_sales']??0),900),'PERFORMANCE_SALES_FAILED');
 ok(closeEnough((float)($p['franchise_earned_margin']??0),249.5),'PERFORMANCE_MARGIN_FAILED');
 ok(closeEnough((float)($p['approved_franchise_expenses']??0),100),'PERFORMANCE_EXPENSE_FAILED');
 ok(closeEnough((float)($p['distributable_profit']??0),550.5),'PERFORMANCE_DISTRIBUTABLE_FAILED');
 $expectedShare=round(550.5*(float)($p['performance_share_percent']??0)/100,2);
 ok(closeEnough((float)($p['performance_share_amount']??-1),$expectedShare),'PERFORMANCE_SHARE_FORMULA_FAILED');
 $gen=$ops->expect('POST','operations.performance.generate',['period'=>$futurePeriod],200);$performanceId=(int)$gen['id'];ok($performanceId>0,'PERFORMANCE_ID_MISSING');
 $ops->expect('POST','operations.performance.approve',['id'=>$performanceId],403,'OWNER_REQUIRED');
 $owner->expect('POST','operations.performance.approve',['id'=>$performanceId],200);
 $finance->expect('POST','operations.performance.paid',['id'=>$performanceId],200);
 $history=$finance->expect('GET','operations.performance.history',null,200);
 $perfHit=array_values(array_filter($history['records']??[],fn($r)=>(int)($r['id']??0)===$performanceId));
 ok(count($perfHit)===1&&($perfHit[0]['status']??'')==='paid','PERFORMANCE_PAYMENT_HISTORY_FAILED');
 echo "PERFORMANCE_PNL=PASS distributable=550.50 share=".$p['performance_share_amount']." score=".$p['total_score']." owner_approval finance_paid\n";

 // Regional report + month-end snapshot + CSV UI marker.
 $report=$ops->expect('GET','operations.network.report?period='.$futurePeriod,null,200);
 $regionRows=array_values(array_filter($report['regions']??[],fn($r)=>($r['division']??'')===$division&&($r['district']??'')===$district));
 ok(count($regionRows)===1,'REGIONAL_REPORT_ROW_MISSING');
 ok(closeEnough((float)$regionRows[0]['sales'],900)&&closeEnough((float)$regionRows[0]['target'],1000)&&closeEnough((float)$regionRows[0]['target_achievement'],90),'REGIONAL_REPORT_VALUES_FAILED');
 $snap=$ops->expect('POST','operations.report.snapshot',['period'=>$futurePeriod],200);$snapshotId=(int)$snap['id'];ok($snapshotId>0,'REPORT_SNAPSHOT_FAILED');
 $app=is_file($root.'/deploy/app.js')?file_get_contents($root.'/deploy/app.js'):'';
 ok(strpos($app,'Export CSV')!==false&&strpos($app,'TeaShopBD_Operations_')!==false,'REPORT_CSV_UI_MARKER_MISSING');
 echo "REPORTS=PASS regional_target=90% snapshot={$snapshotId} csv_export=PASS\n";

 // Leaderboard / healthy-business row was validated before sales were moved.
 ok(isset($oi['healthy_business_score'])&&is_numeric($oi['healthy_business_score']),'LEADERBOARD_SCORE_MISSING');
 echo "LEADERBOARD=PASS healthy_business_score=".$oi['healthy_business_score']."\n";

 // Final security/RBAC audit.
 $sec=$ops->expect('GET','operations.security.audit',null,200);
 foreach(['stock_received_is_not_profit','verified_pos_sale_earns_margin','operations_cannot_override_margin','operations_cannot_approve_own_performance'] as $rule)ok(($sec['rules'][$rule]??false)===true,'SECURITY_RULE_FAILED_'.$rule);
 $forbiddenMenus=['Pricing Engine','Finance & Accounts','Users & Roles','Settings'];
 foreach($forbiddenMenus as $menu)ok(!in_array($menu,$sec['operations_allowed']??[],true),'OPERATIONS_MENU_LEAK_'.$menu);
 $ops->expect('POST','settings.save',['settings'=>['management_share_active_tier'=>'Elite']],403,'OWNER_REQUIRED');
 echo "RBAC_SECURITY=PASS\n";

 // Closure: Operations denied, Owner blocked until 8/8 closure checklist, then Owner closes.
 $ops->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'closed','next_action'=>'','blocking_reason'=>'Round 2 ops close attempt'],403,'OWNER_APPROVAL_REQUIRED');
 $owner->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'closed','next_action'=>'','blocking_reason'=>'Round 2 pre-checklist close'],422,'CLOSURE_CHECKLIST_INCOMPLETE');
 $closure360=$owner->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 ok(count($closure360['checklists']['closure']??[])===8,'CLOSURE_CHECKLIST_COUNT_FAILED');
 foreach(($closure360['checklists']['closure']??[]) as $item)$ops->expect('POST','franchise.checklist.toggle',['franchise_id'=>$franchiseId,'id'=>(int)$item['id'],'completed'=>true,'label'=>$item['item_label'],'notes'=>'Round 2 closure complete'],200);
 $closed=$owner->expect('POST','franchise.pipeline.update',['franchise_id'=>$franchiseId,'stage'=>'closed','next_action'=>'Final handover complete','blocking_reason'=>'Round 2 approved closure'],200);
 ok(($closed['status']??'')==='closed','OWNER_CLOSE_FAILED');
 $final360=$owner->expect('GET','franchise.360?franchise_id='.$franchiseId,null,200);
 ok((int)($final360['checklists']['closure_progress']??0)===100&&($final360['franchise']['status']??'')==='closed','FINAL_CLOSURE_STATE_FAILED');
 $ops->expect('POST','sale.create',['franchise_id'=>$franchiseId,'items'=>[['product_pack_id'=>$packId,'qty'=>1]],'payment_method'=>'cash'],404,'FRANCHISE_NOT_FOUND');
 $ops->expect('POST','inventory.transfer.create',['franchise_id'=>$franchiseId,'product_pack_id'=>$packId,'qty'=>1],404,'FRANCHISE_NOT_FOUND');
 echo "FINAL_CLOSURE=PASS closure=100% owner_only=PASS post_close_sale_transfer_blocked=PASS\n";

 $q=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id IN(?,?,?)");$q->execute([$ownerId,$opsId,$financeId]);$auditCount=(int)$q->fetchColumn();
 ok($auditCount>=25,'AUDIT_COVERAGE_LOW_'.$auditCount);
 echo "AUDIT_LOG=PASS rows={$auditCount}\n";

 echo "ROUND2_E2E_RESULT=GREEN\n";
 echo "ROUND2_E2E_CLEANUP=PENDING\n";
}catch(Throwable $e){
 fwrite(STDERR,"ROUND2_E2E_RESULT=FAIL\n");
 fwrite(STDERR,"ROUND2_E2E_ERROR=".$e->getMessage()."\n");
 $cleanup();
 echo "ROUND2_E2E_CLEANUP=DONE\n";
 exit(1);
}

$cleanup();
echo "ROUND2_E2E_CLEANUP=DONE\n";

// Residue verification.
$q=$pdo->prepare("SELECT COUNT(*) FROM franchises WHERE code=?");$q->execute([$outletCode]);$frResidue=(int)$q->fetchColumn();
$q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN(?,?,?)");$q->execute([$ownerEmail,$opsEmail,$financeEmail]);$userResidue=(int)$q->fetchColumn();
$packResidue=0;if((int)($packId??0)>0){$q=$pdo->prepare("SELECT COUNT(*) FROM product_packs WHERE id=?");$q->execute([(int)$packId]);$packResidue=(int)$q->fetchColumn();}
$perfResidue=0;if((int)($performanceId??0)>0){$q=$pdo->prepare("SELECT COUNT(*) FROM performance_records WHERE id=?");$q->execute([(int)$performanceId]);$perfResidue=(int)$q->fetchColumn();}
$snapResidue=0;if((int)($snapshotId??0)>0){$q=$pdo->prepare("SELECT COUNT(*) FROM operations_report_snapshots WHERE id=?");$q->execute([(int)$snapshotId]);$snapResidue=(int)$q->fetchColumn();}
if($frResidue||$userResidue||$packResidue||$perfResidue||$snapResidue){
 fwrite(STDERR,"ROUND2_E2E_CLEANUP_VERIFY=FAIL franchise={$frResidue} users={$userResidue} pack={$packResidue} performance={$perfResidue} snapshot={$snapResidue}\n");
 exit(1);
}
ok((int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn()===99,'POST_CLEANUP_TEA_MASTER_CHANGED');
echo "ROUND2_E2E_CLEANUP_VERIFY=PASS\n";
echo "OPERATIONS_ROUND2_FINAL_GREEN\n";
