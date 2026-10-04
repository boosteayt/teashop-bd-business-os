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
 if(!$category||$qty<=0||$mrp<=0) out(['ok'=>false,'code'=>'INVALID_PACKAGING'],422);
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
 $rows=$pdo->query("SELECT pp.id pack_id,p.name product,p.category,pp.grams pack,pp.mrp,SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) qty FROM inventory_ledger il JOIN product_packs pp ON pp.id=il.product_pack_id JOIN products p ON p.id=pp.product_id WHERE il.location_type='central' GROUP BY il.product_pack_id,p.name,p.category,pp.grams,pp.mrp HAVING ABS(qty)>0.0001 ORDER BY p.category,p.name,pp.grams")->fetchAll();
 $raw=(float)$pdo->query('SELECT COALESCE(SUM(quantity_kg),0) FROM purchase_items')->fetchColumn();
 $produced=(float)$pdo->query("SELECT COALESCE(SUM(output_kg),0) FROM production_batches WHERE qc_status='pass'")->fetchColumn();
 $waste=(float)$pdo->query('SELECT COALESCE(SUM(wastage_kg),0) FROM production_batches')->fetchColumn();
 out(['ok'=>true,'raw_received_kg'=>$raw,'produced_kg'=>$produced,'wastage_kg'=>$waste,'stock'=>$rows]);
}

if($route==='inventory.franchise'){
 auth();
 $fid=(int)($_GET['franchise_id']??0);
 if($fid<=0) out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);
 $q=$pdo->prepare("SELECT pp.id pack_id,p.name product,p.category,pp.grams pack,pp.mrp,SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) qty FROM inventory_ledger il JOIN product_packs pp ON pp.id=il.product_pack_id JOIN products p ON p.id=pp.product_id WHERE il.location_type='franchise' AND il.location_id=? GROUP BY il.product_pack_id,p.name,p.category,pp.grams,pp.mrp HAVING qty>0.0001 ORDER BY p.category,p.name,pp.grams");
 $q->execute([$fid]);
 out(['ok'=>true,'stock'=>$q->fetchAll()]);
}

if($route==='inventory.transfer.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','OPERATIONS'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $fid=(int)($body['franchise_id']??0); $packId=(int)($body['product_pack_id']??0); $qty=(float)($body['qty']??0);
 if($fid<=0||$packId<=0||$qty<=0) out(['ok'=>false,'code'=>'INVALID_TRANSFER'],422);
 $fq=$pdo->prepare("SELECT id FROM franchises WHERE id=? AND status<>'closed'");$fq->execute([$fid]);if(!$fq->fetchColumn())out(['ok'=>false,'code'=>'FRANCHISE_NOT_FOUND'],404);
 $sq=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty WHEN movement_type IN('transfer_out','sale','damage') THEN -qty ELSE qty END),0) FROM inventory_ledger WHERE location_type='central' AND product_pack_id=?");
 $sq->execute([$packId]);$available=(float)$sq->fetchColumn();
 if($qty>$available+0.00001) out(['ok'=>false,'code'=>'INSUFFICIENT_CENTRAL_STOCK','available'=>$available],422);
 $pq=$pdo->prepare('SELECT mrp FROM product_packs WHERE id=?');$pq->execute([$packId]);$mrp=(float)$pq->fetchColumn();
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare("INSERT INTO inventory_ledger(location_type,location_id,product_pack_id,movement_type,qty,unit_value,reference_type,reference_id,created_by) VALUES('central',NULL,?,'transfer_out',?,?,'franchise_transfer',?,?)");
  $ref=time(); $q->execute([$packId,$qty,$mrp,$ref,(int)$u['id']]);
  $q=$pdo->prepare("INSERT INTO inventory_ledger(location_type,location_id,product_pack_id,movement_type,qty,unit_value,reference_type,reference_id,created_by) VALUES('franchise',?,?,'transfer_in',?,?,'franchise_transfer',?,?)");
  $q->execute([$fid,$packId,$qty,$mrp,$ref,(int)$u['id']]);
  $pdo->commit(); audit($pdo,(int)$u['id'],'create','inventory_transfer',(string)$ref,['franchise_id'=>$fid,'pack_id'=>$packId,'qty'=>$qty,'unit_value'=>$mrp]);
  out(['ok'=>true,'reference'=>$ref],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'TRANSFER_FAILED'],422);}
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
 $fid=(int)($body['franchise_id']??0); $items=$body['items']??[];
 if($fid<=0||!is_array($items)||count($items)===0) out(['ok'=>false,'code'=>'INVALID_SALE'],422);
 $q=$pdo->prepare('SELECT margin_percent FROM franchises WHERE id=? AND status<>"closed"'); $q->execute([$fid]); $fr=$q->fetch();
 if(!$fr) out(['ok'=>false,'code'=>'FRANCHISE_NOT_FOUND'],404);
 $normalized=[];$gross=0.0;
 foreach($items as $item){
  $packId=(int)($item['product_pack_id']??0);$qty=(float)($item['qty']??0);
  if($packId<=0||$qty<=0) out(['ok'=>false,'code'=>'INVALID_SALE_ITEM'],422);
  $pq=$pdo->prepare('SELECT pp.mrp,p.name,pp.grams FROM product_packs pp JOIN products p ON p.id=pp.product_id WHERE pp.id=? AND pp.active=1');$pq->execute([$packId]);$pack=$pq->fetch();
  if(!$pack||(float)$pack['mrp']<=0) out(['ok'=>false,'code'=>'PACK_NOT_SELLABLE'],422);
  $sq=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty WHEN movement_type IN('transfer_out','sale','damage') THEN -qty ELSE qty END),0) FROM inventory_ledger WHERE location_type='franchise' AND location_id=? AND product_pack_id=?");
  $sq->execute([$fid,$packId]);$available=(float)$sq->fetchColumn();
  if($qty>$available+0.00001) out(['ok'=>false,'code'=>'INSUFFICIENT_OUTLET_STOCK','product'=>$pack['name'],'available'=>$available],422);
  $line=round((float)$pack['mrp']*$qty,2);$gross+=$line;$normalized[]=['pack_id'=>$packId,'qty'=>$qty,'price'=>(float)$pack['mrp'],'line'=>$line];
 }
 $pct=(float)$fr['margin_percent']; $gross=round($gross,2); $earned=round($gross*$pct/100,2); $receipt='TSB-'.date('ymdHis').'-'.random_int(100,999);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('INSERT INTO pos_sales(franchise_id,receipt_no,gross_amount,eligible_amount,margin_percent,earned_margin,payment_method,sold_at,created_by) VALUES(?,?,?,?,?,?,?,NOW(),?)');
  $q->execute([$fid,$receipt,$gross,$gross,$pct,$earned,$body['payment_method']??'cash',(int)$u['id']]); $saleId=(int)$pdo->lastInsertId();
  foreach($normalized as $row){
   $q=$pdo->prepare('INSERT INTO pos_sale_items(pos_sale_id,product_pack_id,qty,unit_price,line_total) VALUES(?,?,?,?,?)');$q->execute([$saleId,$row['pack_id'],$row['qty'],$row['price'],$row['line']]);
   $q=$pdo->prepare("INSERT INTO inventory_ledger(location_type,location_id,product_pack_id,movement_type,qty,unit_value,reference_type,reference_id,created_by) VALUES('franchise',?,?,'sale',?,?,'pos_sale',?,?)");
   $q->execute([$fid,$row['pack_id'],$row['qty'],$row['price'],$saleId,(int)$u['id']]);
  }
  $pdo->commit(); audit($pdo,(int)$u['id'],'create','pos_sale',(string)$saleId,['receipt'=>$receipt,'gross'=>$gross,'margin'=>$pct,'items'=>count($normalized)]);
  out(['ok'=>true,'id'=>$saleId,'receipt'=>$receipt,'gross_amount'=>$gross,'earned_margin'=>$earned],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'SALE_CREATE_FAILED'],422);}
}

if($route==='finance.summary'){
 auth();
 $s=(float)$pdo->query('SELECT COALESCE(SUM(gross_amount),0) FROM pos_sales')->fetchColumn();
 $m=(float)$pdo->query('SELECT COALESCE(SUM(earned_margin),0) FROM pos_sales')->fetchColumn();
 $e=(float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM finance_ledger")->fetchColumn();
 $dist=max(0,$s-$m-$e);
 $settings=[];
 foreach($pdo->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN('faruk_active_tier','faruk_manual_percent')")->fetchAll() as $row){$settings[$row['setting_key']]=$row['setting_value'];}
 $tier=$settings['faruk_active_tier']??'Base';
 $pct=['Base'=>15.0,'Growth'=>20.0,'Elite'=>25.0][$tier]??(float)($settings['faruk_manual_percent']??20);
 if($tier==='Manual') $pct=(float)($settings['faruk_manual_percent']??20);
 $share=round($dist*$pct/100,2);
 out(['ok'=>true,'verified_sales'=>$s,'franchise_earned_margin'=>$m,'approved_expenses'=>$e,'distributable_profit'=>$dist,'faruk_tier'=>$tier,'faruk_percent'=>$pct,'faruk_share'=>$share,'company_net_after_faruk'=>round($dist-$share,2)]);
}

if($route==='settings'){
 $u=auth();
 $rows=$pdo->query('SELECT setting_key,setting_value FROM system_settings ORDER BY setting_key')->fetchAll();
 $settings=[]; foreach($rows as $row){$settings[$row['setting_key']]=$row['setting_value'];}
 $defaults=[
  'pricing_tube30'=>'60','pricing_pouch50'=>'18','pricing_pouch100'=>'22','pricing_ctc250'=>'24','pricing_ctc500'=>'28',
  'pricing_labour'=>'5','pricing_overhead'=>'7','pricing_logistics'=>'4','pricing_wastage_percent'=>'3','tax_provision_percent'=>'0',
  'faruk_active_tier'=>'Base','faruk_manual_percent'=>'20'
 ];
 foreach($defaults as $k=>$v){if(!array_key_exists($k,$settings))$settings[$k]=$v;}
 out(['ok'=>true,'settings'=>$settings]);
}

if($route==='settings.save' && $method==='POST'){
 csrf(); $u=owner();
 $allowed=[
  'pricing_tube30','pricing_pouch50','pricing_pouch100','pricing_ctc250','pricing_ctc500','pricing_labour','pricing_overhead','pricing_logistics',
  'pricing_wastage_percent','tax_provision_percent','faruk_active_tier','faruk_manual_percent'
 ];
 $incoming=$body['settings']??[];
 if(!is_array($incoming)) out(['ok'=>false,'code'=>'INVALID_SETTINGS'],422);
 $q=$pdo->prepare('INSERT INTO system_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
 foreach($incoming as $k=>$v){
  if(!in_array($k,$allowed,true)) continue;
  if($k==='faruk_active_tier'){
   if(!in_array((string)$v,['Base','Growth','Elite','Manual'],true)) out(['ok'=>false,'code'=>'INVALID_FARUK_TIER'],422);
  }else{
   if(!is_numeric($v) || (float)$v<0) out(['ok'=>false,'code'=>'INVALID_SETTING_VALUE','key'=>$k],422);
  }
  $q->execute([$k,(string)$v,(int)$u['id']]);
 }
 audit($pdo,(int)$u['id'],'update','system_settings',null,$incoming);
 out(['ok'=>true]);
}

if($route==='roles'){
 owner();
 $rows=$pdo->query('SELECT id,code,name FROM roles ORDER BY id')->fetchAll();
 out(['ok'=>true,'roles'=>$rows]);
}

if($route==='user.create' && $method==='POST'){
 csrf(); $u=owner();
 $name=trim((string)($body['name']??''));$email=strtolower(trim((string)($body['email']??'')));$role=(string)($body['role']??'');
 if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) out(['ok'=>false,'code'=>'INVALID_USER'],422);
 $rq=$pdo->prepare('SELECT id FROM roles WHERE code=?');$rq->execute([$role]);$rid=$rq->fetchColumn();
 if(!$rid) out(['ok'=>false,'code'=>'INVALID_ROLE'],422);
 $temp='Tsb!'.bin2hex(random_bytes(6)).'A7';
 try{
  $q=$pdo->prepare('INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,1,1)');
  $q->execute([$rid,$name,$email,password_hash($temp,PASSWORD_DEFAULT)]);
  $id=(int)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','user',(string)$id,['name'=>$name,'email'=>$email,'role'=>$role]);
  out(['ok'=>true,'id'=>$id,'temporary_password'=>$temp],201);
 }catch(Throwable $e){out(['ok'=>false,'code'=>'USER_CREATE_FAILED'],422);}
}

if($route==='user.toggle' && $method==='POST'){
 csrf(); $u=owner();$id=(int)($body['id']??0);$active=(int)!empty($body['active']);
 if($id<=0||$id===(int)$u['id']) out(['ok'=>false,'code'=>'INVALID_USER_ACTION'],422);
 $q=$pdo->prepare('UPDATE users SET active=? WHERE id=?');$q->execute([$active,$id]);
 audit($pdo,(int)$u['id'],'update_status','user',(string)$id,['active'=>$active]);
 out(['ok'=>true]);
}

if($route==='user.reset' && $method==='POST'){
 csrf(); $u=owner();$id=(int)($body['id']??0);
 if($id<=0) out(['ok'=>false,'code'=>'INVALID_USER'],422);
 $temp='Tsb!'.bin2hex(random_bytes(6)).'R9';
 $q=$pdo->prepare('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?');$q->execute([password_hash($temp,PASSWORD_DEFAULT),$id]);
 audit($pdo,(int)$u['id'],'reset_password','user',(string)$id);
 out(['ok'=>true,'temporary_password'=>$temp]);
}

if($route==='audit'){
 owner();
 $rows=$pdo->query("SELECT a.id,a.created_at,a.action,a.entity_type,a.entity_id,a.ip_address,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200")->fetchAll();
 out(['ok'=>true,'audit'=>$rows]);
}

if($route==='reports.summary'){
 auth();
 $totals=[
  'sales'=>(float)$pdo->query('SELECT COALESCE(SUM(gross_amount),0) FROM pos_sales')->fetchColumn(),
  'earned_margin'=>(float)$pdo->query('SELECT COALESCE(SUM(earned_margin),0) FROM pos_sales')->fetchColumn(),
  'receipts'=>(int)$pdo->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn(),
  'purchases'=>(float)$pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM purchase_receipts WHERE status="received"')->fetchColumn(),
  'raw_kg'=>(float)$pdo->query('SELECT COALESCE(SUM(quantity_kg),0) FROM purchase_items')->fetchColumn(),
  'production_kg'=>(float)$pdo->query("SELECT COALESCE(SUM(output_kg),0) FROM production_batches WHERE qc_status='pass'")->fetchColumn(),
  'wastage_kg'=>(float)$pdo->query('SELECT COALESCE(SUM(wastage_kg),0) FROM production_batches')->fetchColumn(),
  'active_outlets'=>(int)$pdo->query("SELECT COUNT(*) FROM franchises WHERE status='active'")->fetchColumn()
 ];
 $top=$pdo->query("SELECT f.name outlet,COUNT(s.id) receipts,COALESCE(SUM(s.gross_amount),0) sales,COALESCE(SUM(s.earned_margin),0) margin FROM franchises f LEFT JOIN pos_sales s ON s.franchise_id=f.id GROUP BY f.id,f.name ORDER BY sales DESC LIMIT 20")->fetchAll();
 $monthly=$pdo->query("SELECT DATE_FORMAT(sold_at,'%Y-%m') period,COUNT(*) receipts,SUM(gross_amount) sales,SUM(earned_margin) margin FROM pos_sales GROUP BY DATE_FORMAT(sold_at,'%Y-%m') ORDER BY period DESC LIMIT 12")->fetchAll();
 out(['ok'=>true,'totals'=>$totals,'top_outlets'=>$top,'monthly'=>$monthly]);
}

if($route==='settlements'){
 auth();
 $rows=$pdo->query("SELECT s.id,f.name outlet,s.period_start,s.period_end,s.opening_stock_value,s.stock_received_value,s.verified_sales,s.approved_returns,s.closing_stock_value,s.earned_margin,s.tax_adjustment,s.previous_balance,s.net_payable,s.status,s.locked_at FROM settlements s JOIN franchises f ON f.id=s.franchise_id ORDER BY s.period_end DESC,f.name")->fetchAll();
 out(['ok'=>true,'settlements'=>$rows]);
}

if($route==='settlement.generate' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $period=(string)($body['period']??'');
 if(!preg_match('/^\d{4}-\d{2}$/',$period)) out(['ok'=>false,'code'=>'INVALID_PERIOD'],422);
 $start=$period.'-01';$end=date('Y-m-t',strtotime($start));
 $franchises=$pdo->query("SELECT id FROM franchises WHERE status<>'closed' ORDER BY id")->fetchAll();
 $pdo->beginTransaction();
 try{
  foreach($franchises as $fr){
   $fid=(int)$fr['id'];
   $check=$pdo->prepare("SELECT id,status FROM settlements WHERE franchise_id=? AND period_start=? AND period_end=?");$check->execute([$fid,$start,$end]);$existing=$check->fetch();
   if($existing && in_array($existing['status'],['locked','paid'],true)) continue;
   $q=$pdo->prepare("SELECT COALESCE(SUM(gross_amount),0) sales,COALESCE(SUM(earned_margin),0) margin FROM pos_sales WHERE franchise_id=? AND DATE(sold_at) BETWEEN ? AND ?");$q->execute([$fid,$start,$end]);$sale=$q->fetch();
   $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty*unit_value WHEN movement_type IN('transfer_out','sale','damage') THEN -qty*unit_value ELSE qty*unit_value END),0) FROM inventory_ledger WHERE location_type='franchise' AND location_id=? AND DATE(created_at)<?");$q->execute([$fid,$start]);$opening=(float)$q->fetchColumn();
   $q=$pdo->prepare("SELECT COALESCE(SUM(qty*unit_value),0) FROM inventory_ledger WHERE location_type='franchise' AND location_id=? AND movement_type='transfer_in' AND DATE(created_at) BETWEEN ? AND ?");$q->execute([$fid,$start,$end]);$received=(float)$q->fetchColumn();
   $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty*unit_value WHEN movement_type IN('transfer_out','sale','damage') THEN -qty*unit_value ELSE qty*unit_value END),0) FROM inventory_ledger WHERE location_type='franchise' AND location_id=? AND DATE(created_at)<=?");$q->execute([$fid,$end]);$closing=(float)$q->fetchColumn();
   $verified=(float)$sale['sales'];$earned=(float)$sale['margin'];$net=round($verified-$earned,2);
   if($existing){
    $q=$pdo->prepare("UPDATE settlements SET opening_stock_value=?,stock_received_value=?,verified_sales=?,closing_stock_value=?,earned_margin=?,net_payable=?,status='review',approved_by=NULL,locked_at=NULL WHERE id=?");
    $q->execute([$opening,$received,$verified,$closing,$earned,$net,(int)$existing['id']]);
   }else{
    $q=$pdo->prepare("INSERT INTO settlements(franchise_id,period_start,period_end,opening_stock_value,stock_received_value,verified_sales,closing_stock_value,earned_margin,net_payable,status) VALUES(?,?,?,?,?,?,?,?,?,'review')");
    $q->execute([$fid,$start,$end,$opening,$received,$verified,$closing,$earned,$net]);
   }
  }
  $pdo->commit(); audit($pdo,(int)$u['id'],'generate','settlement_period',$period,['period_start'=>$start,'period_end'=>$end]);
  out(['ok'=>true,'period'=>$period]);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'SETTLEMENT_GENERATE_FAILED'],422);}
}

if($route==='settlement.lock' && $method==='POST'){
 csrf(); $u=owner();$id=(int)($body['id']??0);
 if($id<=0) out(['ok'=>false,'code'=>'INVALID_SETTLEMENT'],422);
 $q=$pdo->prepare("UPDATE settlements SET status='locked',approved_by=?,locked_at=NOW() WHERE id=? AND status IN('draft','review','approved')");
 $q->execute([(int)$u['id'],$id]);
 if($q->rowCount()!==1) out(['ok'=>false,'code'=>'SETTLEMENT_NOT_LOCKABLE'],422);
 audit($pdo,(int)$u['id'],'lock','settlement',(string)$id);
 out(['ok'=>true]);
}

if($route==='settlement.paid' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $id=(int)($body['id']??0);$q=$pdo->prepare("UPDATE settlements SET status='paid' WHERE id=? AND status='locked'");$q->execute([$id]);
 if($q->rowCount()!==1) out(['ok'=>false,'code'=>'SETTLEMENT_NOT_PAYABLE'],422);
 audit($pdo,(int)$u['id'],'mark_paid','settlement',(string)$id);
 out(['ok'=>true]);
}


if($route==='suppliers'){
 auth();
 $rows=$pdo->query("SELECT s.id,s.name,s.phone,s.email,s.address,s.active,
  COALESCE((SELECT SUM(pr.total_amount) FROM purchase_receipts pr WHERE pr.supplier_id=s.id AND pr.status='received'),0) purchases,
  COALESCE((SELECT SUM(sp.amount) FROM supplier_payments sp WHERE sp.supplier_id=s.id),0) payments
  FROM suppliers s WHERE s.supplier_type='tea' ORDER BY s.name")->fetchAll();
 foreach($rows as &$row){$row['balance']=round((float)$row['purchases']-(float)$row['payments'],2);} unset($row);
 out(['ok'=>true,'suppliers'=>$rows]);
}

if($route==='supplier.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $name=trim((string)($body['name']??'')); if($name==='') out(['ok'=>false,'code'=>'INVALID_SUPPLIER'],422);
 $q=$pdo->prepare("INSERT INTO suppliers(supplier_type,name,phone,email,address,active) VALUES('tea',?,?,?,?,1)");
 try{$q->execute([$name,$body['phone']??null,$body['email']??null,$body['address']??null]);}
 catch(Throwable $e){out(['ok'=>false,'code'=>'SUPPLIER_CREATE_FAILED'],422);}
 $id=(int)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','supplier',(string)$id,['name'=>$name]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='supplier.payment.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $sid=(int)($body['supplier_id']??0); $amount=(float)($body['amount']??0);
 if($sid<=0||$amount<=0) out(['ok'=>false,'code'=>'INVALID_SUPPLIER_PAYMENT'],422);
 $q=$pdo->prepare("INSERT INTO supplier_payments(supplier_id,payment_date,amount,payment_method,reference_no,memo,created_by) VALUES(?,CURDATE(),?,?,?,?,?)");
 $q->execute([$sid,$amount,$body['payment_method']??'bank',$body['reference_no']??null,$body['memo']??null,(int)$u['id']]);
 $id=(int)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','supplier_payment',(string)$id,['supplier_id'=>$sid,'amount'=>$amount]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='rawtea'){
 auth();
 $rows=$pdo->query("SELECT r.id,r.code,r.name,r.tea_type,r.origin,r.active,
  COALESCE(SUM(CASE WHEN l.movement_type IN('opening','purchase_in','adjustment_in') THEN l.qty_kg WHEN l.movement_type IN('blend_out','return_out','adjustment_out') THEN -l.qty_kg ELSE 0 END),0) stock_kg,
  COALESCE((SELECT AVG(pi.unit_rate) FROM purchase_items pi WHERE pi.raw_tea_material_id=r.id AND pi.unit_rate>0),0) avg_rate
  FROM raw_tea_materials r
  LEFT JOIN raw_tea_stock_ledger l ON l.raw_tea_material_id=r.id
  GROUP BY r.id,r.code,r.name,r.tea_type,r.origin,r.active
  ORDER BY r.name")->fetchAll();
 out(['ok'=>true,'rawtea'=>$rows]);
}

if($route==='rawtea.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $name=trim((string)($body['name']??'')); if($name==='') out(['ok'=>false,'code'=>'INVALID_RAW_TEA'],422);
 $code=trim((string)($body['code']??'')); if($code==='') $code='RAW-'.date('ymdHis').'-'.random_int(10,99);
 $q=$pdo->prepare("INSERT INTO raw_tea_materials(code,name,tea_type,origin,active) VALUES(?,?,?,?,1)");
 try{$q->execute([$code,$name,$body['tea_type']??null,$body['origin']??null]);}
 catch(Throwable $e){out(['ok'=>false,'code'=>'RAW_TEA_CREATE_FAILED'],422);}
 $id=(int)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','raw_tea_material',(string)$id,['code'=>$code,'name'=>$name]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='rawtea.purchase.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $sid=(int)($body['supplier_id']??0);$rid=(int)($body['raw_tea_material_id']??0);$kg=(float)($body['kg']??0);$rate=(float)($body['rate']??0);$lot=trim((string)($body['batch_no']??''));
 if($sid<=0||$rid<=0||$kg<=0||$rate<0) out(['ok'=>false,'code'=>'INVALID_PURCHASE'],422);
 $sq=$pdo->prepare("SELECT id FROM suppliers WHERE id=? AND supplier_type='tea' AND active=1");$sq->execute([$sid]);if(!$sq->fetchColumn())out(['ok'=>false,'code'=>'SUPPLIER_NOT_FOUND'],404);
 $rq=$pdo->prepare("SELECT name FROM raw_tea_materials WHERE id=? AND active=1");$rq->execute([$rid]);$rawName=$rq->fetchColumn();if(!$rawName)out(['ok'=>false,'code'=>'RAW_TEA_NOT_FOUND'],404);
 $pdo->beginTransaction();
 try{
  $ref='GRN-'.date('ymdHis').'-'.random_int(100,999);$total=round($kg*$rate,2);
  $q=$pdo->prepare("INSERT INTO purchase_receipts(supplier_id,reference_no,received_date,status,total_amount,created_by) VALUES(?,?,CURDATE(),'received',?,?)");
  $q->execute([$sid,$ref,$total,(int)$u['id']]);$pr=(int)$pdo->lastInsertId();
  $q=$pdo->prepare("INSERT INTO purchase_items(purchase_receipt_id,product_id,raw_tea_material_id,material_name,quantity_kg,unit_rate,batch_no) VALUES(?,NULL,?,?,?,?,?)");
  $q->execute([$pr,$rid,$rawName,$kg,$rate,$lot?:null]);
  $q=$pdo->prepare("INSERT INTO raw_tea_stock_ledger(raw_tea_material_id,movement_type,qty_kg,unit_cost,reference_type,reference_id,batch_no,created_by) VALUES(?,'purchase_in',?,?,'purchase_receipt',?,?,?)");
  $q->execute([$rid,$kg,$rate,$pr,$lot?:null,(int)$u['id']]);
  $pdo->commit(); audit($pdo,(int)$u['id'],'create','raw_tea_purchase',(string)$pr,['supplier_id'=>$sid,'raw_tea_material_id'=>$rid,'kg'=>$kg,'rate'=>$rate]);
  out(['ok'=>true,'id'=>$pr,'reference_no'=>$ref,'total_amount'=>$total],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'RAW_TEA_PURCHASE_FAILED'],422);}
}

if($route==='blends'){
 auth();
 $rows=$pdo->query("SELECT pb.id,pb.batch_no,p.name product,pb.input_kg input,pb.output_kg output,pb.wastage_kg waste,pb.qc_status,pb.produced_at,
  GROUP_CONCAT(CONCAT(r.name,' ',TRIM(TRAILING '0' FROM TRIM(TRAILING '.' FROM pbi.qty_kg)),'kg') ORDER BY r.name SEPARATOR ' + ') components
  FROM production_batches pb
  JOIN products p ON p.id=pb.product_id
  LEFT JOIN production_batch_inputs pbi ON pbi.production_batch_id=pb.id
  LEFT JOIN raw_tea_materials r ON r.id=pbi.raw_tea_material_id
  GROUP BY pb.id,pb.batch_no,p.name,pb.input_kg,pb.output_kg,pb.wastage_kg,pb.qc_status,pb.produced_at
  ORDER BY pb.id DESC LIMIT 300")->fetchAll();
 out(['ok'=>true,'blends'=>$rows]);
}

if($route==='blend.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $pid=(int)($body['product_id']??0);$output=(float)($body['output_kg']??0);$qc=(string)($body['qc_status']??'pass');$items=$body['components']??[];
 if($pid<=0||$output<0||!is_array($items)||count($items)<1||!in_array($qc,['pending','pass','hold','reject'],true)) out(['ok'=>false,'code'=>'INVALID_BLEND'],422);
 $pq=$pdo->prepare("SELECT id FROM products WHERE id=? AND active=1");$pq->execute([$pid]);if(!$pq->fetchColumn())out(['ok'=>false,'code'=>'PRODUCT_NOT_FOUND'],404);
 $input=0.0;$normalized=[];
 foreach($items as $item){
  $rid=(int)($item['raw_tea_material_id']??0);$kg=(float)($item['kg']??0);if($rid<=0||$kg<=0)out(['ok'=>false,'code'=>'INVALID_BLEND_COMPONENT'],422);
  $sq=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','purchase_in','adjustment_in') THEN qty_kg WHEN movement_type IN('blend_out','return_out','adjustment_out') THEN -qty_kg ELSE 0 END),0) FROM raw_tea_stock_ledger WHERE raw_tea_material_id=?");
  $sq->execute([$rid]);$available=(float)$sq->fetchColumn();if($kg>$available+0.00001)out(['ok'=>false,'code'=>'INSUFFICIENT_RAW_TEA','raw_tea_material_id'=>$rid,'available'=>$available],422);
  $cq=$pdo->prepare("SELECT COALESCE(AVG(unit_rate),0) FROM purchase_items WHERE raw_tea_material_id=? AND unit_rate>0");$cq->execute([$rid]);$cost=(float)$cq->fetchColumn();
  $input+=$kg;$normalized[]=['id'=>$rid,'kg'=>$kg,'cost'=>$cost];
 }
 if($output>$input+0.00001) out(['ok'=>false,'code'=>'OUTPUT_EXCEEDS_INPUT'],422);
 $batch=trim((string)($body['batch_no']??''));if($batch==='')$batch='BLEND-'.date('ymdHis').'-'.random_int(100,999);
 $waste=round($input-$output,3);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare("INSERT INTO production_batches(batch_no,product_id,input_kg,output_kg,wastage_kg,qc_status,produced_at,approved_by) VALUES(?,?,?,?,?,?,NOW(),?)");
  $q->execute([$batch,$pid,$input,$output,$waste,$qc,(int)$u['id']]);$bid=(int)$pdo->lastInsertId();
  foreach($normalized as $row){
   $q=$pdo->prepare("INSERT INTO production_batch_inputs(production_batch_id,raw_tea_material_id,qty_kg,unit_cost) VALUES(?,?,?,?)");
   $q->execute([$bid,$row['id'],$row['kg'],$row['cost']]);
   $q=$pdo->prepare("INSERT INTO raw_tea_stock_ledger(raw_tea_material_id,movement_type,qty_kg,unit_cost,reference_type,reference_id,batch_no,created_by) VALUES(?,'blend_out',?,?,'production_batch',?,?,?)");
   $q->execute([$row['id'],$row['kg'],$row['cost'],$bid,$batch,(int)$u['id']]);
  }
  $pdo->commit();audit($pdo,(int)$u['id'],'create','blend_batch',(string)$bid,['batch_no'=>$batch,'input_kg'=>$input,'output_kg'=>$output,'wastage_kg'=>$waste,'components'=>count($normalized)]);
  out(['ok'=>true,'id'=>$bid,'batch_no'=>$batch,'input_kg'=>$input,'output_kg'=>$output,'wastage_kg'=>$waste],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'BLEND_CREATE_FAILED'],422);}
}

out(['ok'=>false,'code'=>'NOT_FOUND'],404);
