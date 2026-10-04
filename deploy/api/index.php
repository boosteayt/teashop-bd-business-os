<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

session_name('TSBOS');
session_set_cookie_params([
 'lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict'
]);
session_start();

$configFile='/home/teashopc/teashop-os-config.php';
function out(array $data,int $status=200): never {
 http_response_code($status);
 echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 exit;
}
if(!is_file($configFile)) out(['ok'=>false,'code'=>'CONFIG_REQUIRED'],503);
$config=require $configFile;

try{
 $pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES=>false
 ]);
}catch(Throwable $e){ out(['ok'=>false,'code'=>'DB_UNAVAILABLE'],503); }

$route=$_GET['route']??'health';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$body=json_decode(file_get_contents('php://input'),true)?:[];

function me(): ?array { return $_SESSION['user']??null; }
function auth(): array { $u=me(); if(!$u) out(['ok'=>false,'code'=>'AUTH_REQUIRED'],401); return $u; }
function owner(): array { $u=auth(); if(($u['role']??'')!=='OWNER') out(['ok'=>false,'code'=>'OWNER_REQUIRED'],403); return $u; }
function csrf(): void {
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET') return;
 $expected=$_SESSION['csrf']??'';
 $got=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
 if(!$expected || !$got || !hash_equals($expected,$got)) out(['ok'=>false,'code'=>'CSRF_FAILED'],419);
}
function audit(PDO $pdo,?int $uid,string $action,string $type,?string $id=null,array $after=[]): void {
 $q=$pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,after_json,ip_address) VALUES(?,?,?,?,?,?)');
 $q->execute([$uid,$action,$type,$id,json_encode($after,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);
}

if($route==='health') out(['ok'=>true,'service'=>'Tea Shop BD Business OS API','database'=>'connected']);

if($route==='bootstrap.users' && $method==='POST'){
 $token=(string)($_SERVER['HTTP_X_SETUP_TOKEN']??'');
 if(empty($config['setup_token']) || !hash_equals((string)$config['setup_token'],$token)) out(['ok'=>false,'code'=>'SETUP_DENIED'],403);
 $count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
 if($count>0) out(['ok'=>false,'code'=>'ALREADY_BOOTSTRAPPED'],409);
 $pdo->beginTransaction();
 try{
  foreach(($body['users']??[]) as $row){
   $rq=$pdo->prepare('SELECT id FROM roles WHERE code=?'); $rq->execute([$row['role']]); $rid=$rq->fetchColumn();
   if(!$rid) throw new RuntimeException('Unknown role');
   $q=$pdo->prepare('INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,1,1)');
   $q->execute([$rid,$row['name'],$row['email'],password_hash((string)$row['password'],PASSWORD_DEFAULT)]);
  }
  $pdo->commit();
 }catch(Throwable $e){ $pdo->rollBack(); out(['ok'=>false,'code'=>'BOOTSTRAP_FAILED'],422); }
 out(['ok'=>true,'created'=>count($body['users']??[])],201);
}

if($route==='login' && $method==='POST'){
 $email=trim((string)($body['email']??'')); $password=(string)($body['password']??'');
 $q=$pdo->prepare('SELECT u.id,u.name,u.email,u.password_hash,u.must_change_password,r.code role,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.active=1 LIMIT 1');
 $q->execute([$email]); $u=$q->fetch();
 if(!$u || !password_verify($password,$u['password_hash'])) out(['ok'=>false,'code'=>'INVALID_LOGIN'],401);
 unset($u['password_hash']);
 session_regenerate_id(true);
 $_SESSION['user']=$u;
 $_SESSION['csrf']=bin2hex(random_bytes(24));
 audit($pdo,(int)$u['id'],'login','user',(string)$u['id']);
 out(['ok'=>true,'user'=>$u,'csrf'=>$_SESSION['csrf']]);
}
if($route==='logout' && $method==='POST'){
 csrf(); $u=me(); if($u) audit($pdo,(int)$u['id'],'logout','user',(string)$u['id']);
 $_SESSION=[]; session_destroy(); out(['ok'=>true]);
}
if($route==='me') out(['ok'=>true,'user'=>auth(),'csrf'=>$_SESSION['csrf']??null]);

if($route==='password.change' && $method==='POST'){
 csrf(); $u=auth();
 $current=(string)($body['current_password']??'');
 $next=(string)($body['new_password']??'');
 if(strlen($next)<10) out(['ok'=>false,'code'=>'PASSWORD_TOO_SHORT'],422);
 if(!preg_match('/[A-Z]/',$next) || !preg_match('/[a-z]/',$next) || !preg_match('/[0-9]/',$next)){
  out(['ok'=>false,'code'=>'PASSWORD_WEAK'],422);
 }
 $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=? AND active=1');
 $q->execute([(int)$u['id']]); $hash=$q->fetchColumn();
 if(!$hash || !password_verify($current,(string)$hash)) out(['ok'=>false,'code'=>'CURRENT_PASSWORD_INVALID'],401);
 if(password_verify($next,(string)$hash)) out(['ok'=>false,'code'=>'PASSWORD_REUSED'],422);
 $q=$pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?');
 $q->execute([password_hash($next,PASSWORD_DEFAULT),(int)$u['id']]);
 $_SESSION['user']['must_change_password']=0;
 audit($pdo,(int)$u['id'],'password_change','user',(string)$u['id']);
 out(['ok'=>true,'user'=>$_SESSION['user'],'csrf'=>$_SESSION['csrf']]);
}

if($route==='users'){
 owner();
 $rows=$pdo->query('SELECT u.id,u.name,u.email,u.active,u.must_change_password,r.code role,r.name role_name,u.created_at FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id')->fetchAll();
 out(['ok'=>true,'users'=>$rows]);
}

if($route==='products'){
 auth();
 $rows=$pdo->query('SELECT id,sku,name,category,purchase_cost_per_kg,active FROM products ORDER BY category,name')->fetchAll();
 out(['ok'=>true,'products'=>$rows]);
}

if($route==='purchases'){
 $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $rows=$pdo->query("SELECT pr.id,pr.reference_no,pr.received_date date,s.name supplier,pi.material_name product,pi.quantity_kg kg,pi.unit_rate rate,pi.batch_no,pr.total_amount FROM purchase_receipts pr JOIN purchase_items pi ON pi.purchase_receipt_id=pr.id LEFT JOIN suppliers s ON s.id=pr.supplier_id ORDER BY pr.id DESC LIMIT 300")->fetchAll();
 out(['ok'=>true,'purchases'=>$rows]);
}

if($route==='purchase.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $supplier=trim((string)($body['supplier']??'')); $pid=(int)($body['product_id']??0); $kg=(float)($body['kg']??0); $rate=(float)($body['rate']??0); $batch=trim((string)($body['batch_no']??''));
 if($supplier===''||$pid<=0||$kg<=0||$rate<0) out(['ok'=>false,'code'=>'INVALID_PURCHASE'],422);
 $pq=$pdo->prepare('SELECT name FROM products WHERE id=? AND active=1'); $pq->execute([$pid]); $productName=$pq->fetchColumn();
 if(!$productName) out(['ok'=>false,'code'=>'PRODUCT_NOT_FOUND'],404);
 $pdo->beginTransaction();
 try{
  $sq=$pdo->prepare("SELECT id FROM suppliers WHERE name=? AND supplier_type='tea' LIMIT 1"); $sq->execute([$supplier]); $sid=$sq->fetchColumn();
  if(!$sid){$iq=$pdo->prepare("INSERT INTO suppliers(supplier_type,name) VALUES('tea',?)");$iq->execute([$supplier]);$sid=$pdo->lastInsertId();}
  $ref='GRN-'.date('ymdHis').'-'.random_int(100,999); $total=round($kg*$rate,2);
  $q=$pdo->prepare("INSERT INTO purchase_receipts(supplier_id,reference_no,received_date,status,total_amount,created_by) VALUES(?,?,CURDATE(),'received',?,?)");
  $q->execute([$sid,$ref,$total,(int)$u['id']]); $rid=(int)$pdo->lastInsertId();
  $q=$pdo->prepare('INSERT INTO purchase_items(purchase_receipt_id,product_id,material_name,quantity_kg,unit_rate,batch_no) VALUES(?,?,?,?,?,?)');
  $q->execute([$rid,$pid,$productName,$kg,$rate,$batch?:null]);
  $pdo->commit(); audit($pdo,(int)$u['id'],'create','purchase_receipt',(string)$rid,['reference'=>$ref,'product_id'=>$pid,'kg'=>$kg,'rate'=>$rate]);
  out(['ok'=>true,'id'=>$rid,'reference_no'=>$ref],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'PURCHASE_CREATE_FAILED'],422);}
}

if($route==='production'){
 $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $rows=$pdo->query("SELECT pb.id,pb.batch_no,p.name product,pb.input_kg input,pb.output_kg output,pb.wastage_kg waste,pb.qc_status,pb.produced_at FROM production_batches pb JOIN products p ON p.id=pb.product_id ORDER BY pb.id DESC LIMIT 300")->fetchAll();
 out(['ok'=>true,'production'=>$rows]);
}

if($route==='production.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $pid=(int)($body['product_id']??0); $input=(float)($body['input']??0); $output=(float)($body['output']??0); $batch=trim((string)($body['batch_no']??''));
 $qc=(string)($body['qc_status']??'pass');
 if($pid<=0||$input<=0||$output<0||$output>$input||!in_array($qc,['pending','pass','hold','reject'],true)) out(['ok'=>false,'code'=>'INVALID_PRODUCTION'],422);
 if($batch==='') $batch='BATCH-'.date('ymdHis').'-'.random_int(100,999);
 $waste=round($input-$output,3);
 try{
  $q=$pdo->prepare('INSERT INTO production_batches(batch_no,product_id,input_kg,output_kg,wastage_kg,qc_status,produced_at,approved_by) VALUES(?,?,?,?,?,?,NOW(),?)');
  $q->execute([$batch,$pid,$input,$output,$waste,$qc,(int)$u['id']]); $id=(int)$pdo->lastInsertId();
  audit($pdo,(int)$u['id'],'create','production_batch',(string)$id,['batch_no'=>$batch,'product_id'=>$pid,'input'=>$input,'output'=>$output,'waste'=>$waste,'qc'=>$qc]);
  out(['ok'=>true,'id'=>$id,'batch_no'=>$batch,'wastage_kg'=>$waste],201);
 }catch(Throwable $e){out(['ok'=>false,'code'=>'PRODUCTION_CREATE_FAILED'],422);}
}

if($route==='packaging'){
 $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $rows=$pdo->query("SELECT pj.id,pj.job_no,p.name product,pp.grams pack,pj.pack_qty qty,(pp.grams*pj.pack_qty) grams,pj.batch_no,pj.labour_cost,pj.sealing_cost,pj.other_cost,pj.completed_at FROM packaging_jobs pj JOIN product_packs pp ON pp.id=pj.product_pack_id JOIN products p ON p.id=pp.product_id ORDER BY pj.id DESC LIMIT 300")->fetchAll();
 out(['ok'=>true,'packaging'=>$rows]);
}

if($route==='packaging.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $pid=(int)($body['product_id']??0); $grams=(int)($body['grams']??0); $qty=(int)($body['qty']??0); $batch=trim((string)($body['batch_no']??''));
 $labour=(float)($body['labour_cost']??0); $sealing=(float)($body['sealing_cost']??0); $other=(float)($body['other_cost']??0); $mrp=(float)($body['mrp']??0);
 $pq=$pdo->prepare('SELECT category FROM products WHERE id=? AND active=1');$pq->execute([$pid]);$category=$pq->fetchColumn();
 if(!$category||$qty<=0) out(['ok'=>false,'code'=>'INVALID_PACKAGING'],422);
 $allowed=$category==='CTC / Black Tea'?[250,500,1000,2000,5000]:[30,50,100,200,250];
 if(!in_array($grams,$allowed,true)) out(['ok'=>false,'code'=>'INVALID_PACK_SIZE'],422);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('SELECT id FROM product_packs WHERE product_id=? AND grams=? LIMIT 1');$q->execute([$pid,$grams]);$packId=$q->fetchColumn();
  if(!$packId){$q=$pdo->prepare('INSERT INTO product_packs(product_id,grams,mrp,active) VALUES(?,?,?,1)');$q->execute([$pid,$grams,$mrp]);$packId=$pdo->lastInsertId();}
  elseif($mrp>0){$q=$pdo->prepare('UPDATE product_packs SET mrp=? WHERE id=?');$q->execute([$mrp,$packId]);}
  $job='PKG-'.date('ymdHis').'-'.random_int(100,999);
  $q=$pdo->prepare('INSERT INTO packaging_jobs(job_no,product_pack_id,batch_no,pack_qty,labour_cost,sealing_cost,other_cost,completed_at,created_by) VALUES(?,?,?,?,?,?,?,NOW(),?)');
  $q->execute([$job,$packId,$batch?:null,$qty,$labour,$sealing,$other,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
  $q=$pdo->prepare("INSERT INTO inventory_ledger(location_type,location_id,product_pack_id,movement_type,qty,unit_value,reference_type,reference_id,batch_no,created_by) VALUES('central',NULL,?,'production_in',?,?,?,?,?,?)");
  $q->execute([$packId,$qty,$mrp,'packaging_job',$id,$batch?:null,(int)$u['id']]);
  $pdo->commit(); audit($pdo,(int)$u['id'],'create','packaging_job',(string)$id,['job_no'=>$job,'product_id'=>$pid,'grams'=>$grams,'qty'=>$qty]);
  out(['ok'=>true,'id'=>$id,'job_no'=>$job],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'PACKAGING_CREATE_FAILED'],422);}
}

if($route==='inventory'){
 auth();
 $rows=$pdo->query("SELECT p.name product,p.category,pp.grams pack,pp.mrp,SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) qty FROM inventory_ledger il JOIN product_packs pp ON pp.id=il.product_pack_id JOIN products p ON p.id=pp.product_id WHERE il.location_type='central' GROUP BY il.product_pack_id,p.name,p.category,pp.grams,pp.mrp HAVING ABS(qty)>0.0001 ORDER BY p.category,p.name,pp.grams")->fetchAll();
 $raw=(float)$pdo->query('SELECT COALESCE(SUM(quantity_kg),0) FROM purchase_items')->fetchColumn();
 $produced=(float)$pdo->query("SELECT COALESCE(SUM(output_kg),0) FROM production_batches WHERE qc_status='pass'")->fetchColumn();
 $waste=(float)$pdo->query('SELECT COALESCE(SUM(wastage_kg),0) FROM production_batches')->fetchColumn();
 out(['ok'=>true,'raw_received_kg'=>$raw,'produced_kg'=>$produced,'wastage_kg'=>$waste,'stock'=>$rows]);
}

if($route==='dashboard'){
 auth();
 $sales=(float)$pdo->query('SELECT COALESCE(SUM(gross_amount),0) FROM pos_sales')->fetchColumn();
 $margin=(float)$pdo->query('SELECT COALESCE(SUM(earned_margin),0) FROM pos_sales')->fetchColumn();
 $expenses=(float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM finance_ledger WHERE division='FRANCHISE'")->fetchColumn();
 $outlets=(int)$pdo->query("SELECT COUNT(*) FROM franchises WHERE status<>'closed'")->fetchColumn();
 $receipts=(int)$pdo->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn();
 out(['ok'=>true,'verified_sales'=>$sales,'franchise_earned_margin'=>$margin,'approved_expenses'=>$expenses,'company_contribution'=>$sales-$margin-$expenses,'active_outlets'=>$outlets,'receipts'=>$receipts]);
}

if($route==='expenses'){
 $u=auth();
 if(!in_array($u['role'],['OWNER','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $rows=$pdo->query("SELECT id,entry_date date,memo name,amount FROM finance_ledger WHERE division='FRANCHISE' AND entry_type='debit' ORDER BY id DESC LIMIT 200")->fetchAll();
 out(['ok'=>true,'expenses'=>$rows]);
}

if($route==='expense.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $name=trim((string)($body['name']??'')); $amount=(float)($body['amount']??0);
 if($name==='' || $amount<=0) out(['ok'=>false,'code'=>'INVALID_EXPENSE'],422);
 $q=$pdo->prepare("INSERT INTO finance_ledger(entry_date,division,account_code,entry_type,amount,memo,created_by) VALUES(CURDATE(),'FRANCHISE','OPERATING_EXPENSE','debit',?,?,?)");
 $q->execute([$amount,$name,(int)$u['id']]);
 $id=(string)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','finance_expense',$id,['name'=>$name,'amount'=>$amount]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='settlement.summary'){
 auth();
 $rows=$pdo->query("SELECT DATE_FORMAT(sold_at,'%Y-%m') period,COUNT(*) receipts,SUM(gross_amount) sales,SUM(earned_margin) margin FROM pos_sales GROUP BY DATE_FORMAT(sold_at,'%Y-%m') ORDER BY period DESC LIMIT 24")->fetchAll();
 out(['ok'=>true,'periods'=>$rows]);
}
if($route==='franchises'){
 auth();
 $rows=$pdo->query('SELECT id,code,name,district,upazila,status,margin_mode,margin_tier,margin_percent,opened_at FROM franchises ORDER BY id DESC')->fetchAll();
 out(['ok'=>true,'franchises'=>$rows]);
}
if($route==='franchise.create' && $method==='POST'){
 csrf(); $u=owner();
 $q=$pdo->prepare('INSERT INTO franchises(code,name,district,upazila,status,margin_mode,margin_tier,margin_percent,opened_at) VALUES(?,?,?,?,?,?,?,?,?)');
 $q->execute([$body['code'],$body['name'],$body['district']??null,$body['upazila']??null,'active',$body['margin_mode']??'tier',$body['margin_tier']??'Starter',(float)($body['margin_percent']??25),$body['opened_at']??date('Y-m-d')]);
 $id=(string)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','franchise',$id,$body);
 out(['ok'=>true,'id'=>$id],201);
}
if($route==='sale.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','OPERATIONS','FRANCHISE','CASHIER'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $fid=(int)($body['franchise_id']??0); $gross=(float)($body['gross_amount']??0);
 if($gross<=0||$fid<=0) out(['ok'=>false,'code'=>'INVALID_SALE'],422);
 $q=$pdo->prepare('SELECT margin_percent FROM franchises WHERE id=? AND status<>"closed"'); $q->execute([$fid]); $fr=$q->fetch();
 if(!$fr) out(['ok'=>false,'code'=>'FRANCHISE_NOT_FOUND'],404);
 $pct=(float)$fr['margin_percent']; $earned=round($gross*$pct/100,2); $receipt='TSB-'.date('ymdHis').'-'.random_int(100,999);
 $q=$pdo->prepare('INSERT INTO pos_sales(franchise_id,receipt_no,gross_amount,eligible_amount,margin_percent,earned_margin,payment_method,sold_at,created_by) VALUES(?,?,?,?,?,?,?,NOW(),?)');
 $q->execute([$fid,$receipt,$gross,$gross,$pct,$earned,$body['payment_method']??'cash',(int)$u['id']]);
 $id=(string)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','pos_sale',$id,['receipt'=>$receipt,'gross'=>$gross,'margin'=>$pct]);
 out(['ok'=>true,'id'=>$id,'receipt'=>$receipt,'earned_margin'=>$earned],201);
}
if($route==='finance.summary'){
 auth();
 $s=(float)$pdo->query('SELECT COALESCE(SUM(gross_amount),0) FROM pos_sales')->fetchColumn();
 $m=(float)$pdo->query('SELECT COALESCE(SUM(earned_margin),0) FROM pos_sales')->fetchColumn();
 $e=(float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM finance_ledger")->fetchColumn();
 $dist=max(0,$s-$m-$e);
 out(['ok'=>true,'verified_sales'=>$s,'franchise_earned_margin'=>$m,'approved_expenses'=>$e,'distributable_profit'=>$dist]);
}
out(['ok'=>false,'code'=>'NOT_FOUND'],404);
