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

function outlet_ops_user(array $roles=['OWNER','OPERATIONS']): array {
 $u=auth();
 if(!in_array((string)($u['role']??''),$roles,true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 return $u;
}
function outlet_checklist_defaults(string $type): array {
 if($type==='opening') return [
  ['agreement_signed','Franchise agreement signed',10],
  ['premises_confirmed','Premises / lease confirmed',20],
  ['branding_ready','Branding & decoration ready',30],
  ['utilities_ready','Electricity / internet / counter ready',40],
  ['staff_hired','Outlet staff hired',50],
  ['training_complete','Required staff training completed',60],
  ['pos_ready','POS login / device / connectivity ready',70],
  ['opening_stock_received','Opening stock received',80],
  ['stock_reconciled','Opening stock reconciled in system',90],
  ['launch_approved','Launch approval completed',100],
 ];
 return [
  ['closure_approved','Closure / suspension decision approved',10],
  ['sales_stopped','POS sales stopped at effective time',20],
  ['stock_counted','Physical stock counted',30],
  ['stock_returned','Approved stock returned / transferred',40],
  ['dues_reconciled','Dues and settlement reconciled',50],
  ['pos_access_closed','Outlet POS access disabled / reassigned',60],
  ['brand_assets_removed','Brand assets / signage recovery completed',70],
  ['final_handover','Final documents and handover completed',80],
 ];
}
function ensure_outlet_checklist(PDO $pdo,int $fid,string $type): void {
 if(!in_array($type,['opening','closure'],true)) return;
 $q=$pdo->prepare('INSERT IGNORE INTO outlet_checklist_items(franchise_id,checklist_type,item_code,item_label,sort_order,required) VALUES(?,?,?,?,?,1)');
 foreach(outlet_checklist_defaults($type) as [$code,$label,$sort]) $q->execute([$fid,$type,$code,$label,$sort]);
}
function outlet_timeline(PDO $pdo,int $fid,?int $uid,string $event,string $title,string $detail='',?string $refType=null,?int $refId=null,array $payload=[]): void {
 $q=$pdo->prepare('INSERT INTO outlet_timeline(franchise_id,event_type,title,detail,reference_type,reference_id,payload_json,created_by) VALUES(?,?,?,?,?,?,?,?)');
 $q->execute([$fid,$event,$title,$detail?:null,$refType,$refId,$payload?json_encode($payload,JSON_UNESCAPED_UNICODE):null,$uid]);
}
function outlet_exists(PDO $pdo,int $fid): array {
 $q=$pdo->prepare('SELECT * FROM franchises WHERE id=? LIMIT 1');$q->execute([$fid]);$row=$q->fetch();
 if(!$row) out(['ok'=>false,'code'=>'FRANCHISE_NOT_FOUND'],404);
 return $row;
}

function ops_sla_due(string $priority,?string $requested=null): string {
 if($requested){
  $ts=strtotime($requested);if($ts!==false)return date('Y-m-d H:i:s',$ts);
 }
 $hours=['critical'=>4,'high'=>12,'medium'=>48,'low'=>96][$priority]??48;
 return date('Y-m-d H:i:s',time()+($hours*3600));
}
function ops_assignee(PDO $pdo,$id): ?int {
 if($id===null||$id===''||(int)$id<=0)return null;
 $uid=(int)$id;
 $q=$pdo->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.active=1 AND r.code IN('OWNER','OPERATIONS','REGIONAL') LIMIT 1");
 $q->execute([$uid]);
 if(!$q->fetchColumn())out(['ok'=>false,'code'=>'INVALID_OPERATIONS_ASSIGNEE'],422);
 return $uid;
}
function ops_priority(string $priority): string {
 return in_array($priority,['low','medium','high','critical'],true)?$priority:'medium';
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
 $rows=$pdo->query("SELECT p.id,p.sku,p.name,p.category,p.purchase_cost_per_kg,p.active,
  COALESCE((
    SELECT ROUND(SUM(pbi.qty_kg*pbi.unit_cost)/NULLIF(pb.output_kg,0),2)
    FROM production_batches pb
    JOIN production_batch_inputs pbi ON pbi.production_batch_id=pb.id
    WHERE pb.product_id=p.id AND pb.qc_status='pass'
    GROUP BY pb.id,pb.output_kg
    ORDER BY pb.id DESC LIMIT 1
  ),p.purchase_cost_per_kg) effective_cost_per_kg
  FROM products p ORDER BY p.category,p.name")->fetchAll();
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
 if(!in_array($u['role'],['OWNER','WAREHOUSE','PRODUCTION','QC'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $rows=$pdo->query("SELECT pb.id,pb.batch_no,p.name product,pb.input_kg input,pb.output_kg output,pb.wastage_kg waste,pb.qc_status,pb.produced_at FROM production_batches pb JOIN products p ON p.id=pb.product_id ORDER BY pb.id DESC LIMIT 300")->fetchAll();
 out(['ok'=>true,'production'=>$rows]);
}

if($route==='production.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','PRODUCTION'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
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

if($route==='packaging.materials'){
 auth();
 $rows=$pdo->query("SELECT pm.id,pm.code,pm.name,pm.unit,pm.reorder_level,pm.active,
  COALESCE(SUM(CASE WHEN psl.movement_type IN('opening','purchase_in','adjustment_in') THEN psl.qty WHEN psl.movement_type IN('production_out','return_out','damage_out','adjustment_out') THEN -psl.qty ELSE 0 END),0) stock_qty,
  COALESCE((SELECT SUM(ppi.quantity*ppi.unit_rate)/NULLIF(SUM(ppi.quantity),0) FROM packaging_purchase_items ppi WHERE ppi.packaging_material_id=pm.id AND ppi.unit_rate>0),pm.unit_cost,0) avg_rate
  FROM packaging_materials pm
  LEFT JOIN packaging_stock_ledger psl ON psl.packaging_material_id=pm.id
  GROUP BY pm.id,pm.code,pm.name,pm.unit,pm.reorder_level,pm.active,pm.unit_cost
  ORDER BY pm.name")->fetchAll();
 foreach($rows as &$row){$row['stock_value']=round((float)$row['stock_qty']*(float)$row['avg_rate'],2);$row['low_stock']=(float)$row['stock_qty']<=(float)$row['reorder_level']?1:0;}unset($row);
 out(['ok'=>true,'materials'=>$rows]);
}

if($route==='packaging.material.create' && $method==='POST'){
 csrf();$u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','PACKAGING'],true))out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $name=trim((string)($body['name']??''));$unit=trim((string)($body['unit']??'pcs'));if($name==='')out(['ok'=>false,'code'=>'INVALID_PACKAGING_MATERIAL'],422);
 $code=trim((string)($body['code']??''));if($code==='')$code='PKM-'.date('ymdHis').'-'.random_int(10,99);
 try{
  $q=$pdo->prepare("INSERT INTO packaging_materials(code,name,unit,unit_cost,stock_qty,reorder_level,active) VALUES(?,?,?,0,0,?,1)");
  $q->execute([$code,$name,$unit,(float)($body['reorder_level']??0)]);
 }catch(Throwable $e){out(['ok'=>false,'code'=>'PACKAGING_MATERIAL_CREATE_FAILED'],422);}
 $id=(int)$pdo->lastInsertId();audit($pdo,(int)$u['id'],'create','packaging_material',(string)$id,['code'=>$code,'name'=>$name,'unit'=>$unit]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='packaging.purchase.create' && $method==='POST'){
 csrf();$u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','PACKAGING'],true))out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $sid=(int)($body['supplier_id']??0);$mid=(int)($body['packaging_material_id']??0);$qty=(float)($body['qty']??0);$rate=(float)($body['rate']??0);$lot=trim((string)($body['batch_no']??''));
 if($sid<=0||$mid<=0||$qty<=0||$rate<0)out(['ok'=>false,'code'=>'INVALID_PACKAGING_PURCHASE'],422);
 $sq=$pdo->prepare("SELECT id,payment_terms_days FROM suppliers WHERE id=? AND supplier_type='packaging' AND active=1");$sq->execute([$sid]);$supplier=$sq->fetch();if(!$supplier)out(['ok'=>false,'code'=>'SUPPLIER_NOT_FOUND'],404);
 $mq=$pdo->prepare("SELECT name FROM packaging_materials WHERE id=? AND active=1");$mq->execute([$mid]);if(!$mq->fetchColumn())out(['ok'=>false,'code'=>'PACKAGING_MATERIAL_NOT_FOUND'],404);
 $received=$body['received_date']??date('Y-m-d');$due=$body['due_date']??date('Y-m-d',strtotime($received.' +'.(int)$supplier['payment_terms_days'].' days'));
 $ref='PGRN-'.date('ymdHis').'-'.random_int(100,999);$total=round($qty*$rate,2);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare("INSERT INTO packaging_purchase_receipts(supplier_id,reference_no,invoice_no,challan_no,received_date,due_date,status,total_amount,created_by) VALUES(?,?,?,?,?,?,'received',?,?)");
  $q->execute([$sid,$ref,$body['invoice_no']??null,$body['challan_no']??null,$received,$due,$total,(int)$u['id']]);$pr=(int)$pdo->lastInsertId();
  $q=$pdo->prepare("INSERT INTO packaging_purchase_items(packaging_purchase_receipt_id,packaging_material_id,quantity,unit_rate,line_total,batch_no) VALUES(?,?,?,?,?,?)");
  $q->execute([$pr,$mid,$qty,$rate,$total,$lot?:null]);
  $q=$pdo->prepare("INSERT INTO packaging_stock_ledger(packaging_material_id,movement_type,qty,unit_cost,reference_type,reference_id,batch_no,created_by) VALUES(?,'purchase_in',?,?,'packaging_purchase',?,?,?)");
  $q->execute([$mid,$qty,$rate,$pr,$lot?:null,(int)$u['id']]);
  $q=$pdo->prepare("UPDATE packaging_materials SET unit_cost=? WHERE id=?");$q->execute([$rate,$mid]);
  $pdo->commit();audit($pdo,(int)$u['id'],'create','packaging_purchase',(string)$pr,['supplier_id'=>$sid,'material_id'=>$mid,'qty'=>$qty,'rate'=>$rate]);
  out(['ok'=>true,'id'=>$pr,'reference_no'=>$ref,'total_amount'=>$total],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'PACKAGING_PURCHASE_FAILED'],422);}
}

if($route==='packaging.bom'){
 auth();$pid=(int)($_GET['product_id']??0);$grams=(int)($_GET['grams']??0);
 if($pid<=0||$grams<=0)out(['ok'=>false,'code'=>'INVALID_PACK'],422);
 $q=$pdo->prepare("SELECT pp.id pack_id,pm.id material_id,pm.name,pm.unit,b.qty_per_pack,
   COALESCE((SELECT SUM(ppi.quantity*ppi.unit_rate)/NULLIF(SUM(ppi.quantity),0) FROM packaging_purchase_items ppi WHERE ppi.packaging_material_id=pm.id AND ppi.unit_rate>0),pm.unit_cost,0) unit_cost
  FROM product_packs pp
  LEFT JOIN product_packaging_bom b ON b.product_pack_id=pp.id
  LEFT JOIN packaging_materials pm ON pm.id=b.packaging_material_id
  WHERE pp.product_id=? AND pp.grams=? ORDER BY pm.name");
 $q->execute([$pid,$grams]);$rows=$q->fetchAll();
 out(['ok'=>true,'bom'=>array_values(array_filter($rows,fn($r)=>!empty($r['material_id'])))]);
}

if($route==='packaging'){
 $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','PACKAGING','PRODUCTION','QC'],true))out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $rows=$pdo->query("SELECT pj.id,pj.job_no,p.name product,pp.grams pack,pj.pack_qty qty,(pp.grams*pj.pack_qty) grams,pj.batch_no,pj.labour_cost,pj.sealing_cost,pj.other_cost,pj.completed_at,
  COALESCE((SELECT SUM(psl.qty*psl.unit_cost) FROM packaging_stock_ledger psl WHERE psl.reference_type='packaging_job' AND psl.reference_id=pj.id AND psl.movement_type='production_out'),0) material_cost
  FROM packaging_jobs pj JOIN product_packs pp ON pp.id=pj.product_pack_id JOIN products p ON p.id=pp.product_id ORDER BY pj.id DESC LIMIT 300")->fetchAll();
 out(['ok'=>true,'packaging'=>$rows]);
}

if($route==='packaging.create' && $method==='POST'){
 csrf();$u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','PACKAGING'],true))out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $pid=(int)($body['product_id']??0);$grams=(int)($body['grams']??0);$qty=(int)($body['qty']??0);$batch=trim((string)($body['batch_no']??''));
 $labour=(float)($body['labour_cost']??0);$sealing=(float)($body['sealing_cost']??0);$other=(float)($body['other_cost']??0);$mrp=(float)($body['mrp']??0);$bom=$body['bom']??null;
 $pq=$pdo->prepare('SELECT category FROM products WHERE id=? AND active=1');$pq->execute([$pid]);$category=$pq->fetchColumn();
 if(!$category||$qty<=0||$mrp<=0)out(['ok'=>false,'code'=>'INVALID_PACKAGING'],422);
 $allowed=$category==='CTC / Black Tea'?[250,500,1000,2000,5000]:[30,50,100,200,250];if(!in_array($grams,$allowed,true))out(['ok'=>false,'code'=>'INVALID_PACK_SIZE'],422);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('SELECT id FROM product_packs WHERE product_id=? AND grams=? LIMIT 1');$q->execute([$pid,$grams]);$packId=$q->fetchColumn();
  if(!$packId){$q=$pdo->prepare('INSERT INTO product_packs(product_id,grams,mrp,active) VALUES(?,?,?,1)');$q->execute([$pid,$grams,$mrp]);$packId=(int)$pdo->lastInsertId();}else{$packId=(int)$packId;$q=$pdo->prepare('UPDATE product_packs SET mrp=? WHERE id=?');$q->execute([$mrp,$packId]);}
  if(is_array($bom)){
   $pdo->prepare('DELETE FROM product_packaging_bom WHERE product_pack_id=?')->execute([$packId]);
   $bi=$pdo->prepare('INSERT INTO product_packaging_bom(product_pack_id,packaging_material_id,qty_per_pack) VALUES(?,?,?)');
   foreach($bom as $row){$mid=(int)($row['packaging_material_id']??0);$per=(float)($row['qty_per_pack']??0);if($mid>0&&$per>0)$bi->execute([$packId,$mid,$per]);}
  }
  $bq=$pdo->prepare("SELECT b.packaging_material_id,b.qty_per_pack,pm.name,
    COALESCE((SELECT SUM(ppi.quantity*ppi.unit_rate)/NULLIF(SUM(ppi.quantity),0) FROM packaging_purchase_items ppi WHERE ppi.packaging_material_id=b.packaging_material_id AND ppi.unit_rate>0),pm.unit_cost,0) unit_cost
   FROM product_packaging_bom b JOIN packaging_materials pm ON pm.id=b.packaging_material_id WHERE b.product_pack_id=?");
  $bq->execute([$packId]);$bomRows=$bq->fetchAll();
  foreach($bomRows as $row){
   $need=(float)$row['qty_per_pack']*$qty;
   $sq=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','purchase_in','adjustment_in') THEN qty WHEN movement_type IN('production_out','return_out','damage_out','adjustment_out') THEN -qty ELSE 0 END),0) FROM packaging_stock_ledger WHERE packaging_material_id=?");
   $sq->execute([(int)$row['packaging_material_id']]);$available=(float)$sq->fetchColumn();
   if($need>$available+0.00001)throw new RuntimeException('INSUFFICIENT_PACKAGING_MATERIAL:'.$row['name']);
  }
  $job='PKG-'.date('ymdHis').'-'.random_int(100,999);
  $q=$pdo->prepare('INSERT INTO packaging_jobs(job_no,product_pack_id,batch_no,pack_qty,labour_cost,sealing_cost,other_cost,completed_at,created_by) VALUES(?,?,?,?,?,?,?,NOW(),?)');
  $q->execute([$job,$packId,$batch?:null,$qty,$labour,$sealing,$other,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
  foreach($bomRows as $row){
   $need=(float)$row['qty_per_pack']*$qty;
   $q=$pdo->prepare("INSERT INTO packaging_stock_ledger(packaging_material_id,movement_type,qty,unit_cost,reference_type,reference_id,batch_no,created_by) VALUES(?,'production_out',?,?,'packaging_job',?,?,?)");
   $q->execute([(int)$row['packaging_material_id'],$need,(float)$row['unit_cost'],$id,$batch?:null,(int)$u['id']]);
  }
  $q=$pdo->prepare("INSERT INTO inventory_ledger(location_type,location_id,product_pack_id,movement_type,qty,unit_value,reference_type,reference_id,batch_no,created_by) VALUES('central',NULL,?,'production_in',?,?,?,?,?,?)");
  $q->execute([$packId,$qty,$mrp,'packaging_job',$id,$batch?:null,(int)$u['id']]);
  $pdo->commit();audit($pdo,(int)$u['id'],'create','packaging_job',(string)$id,['job_no'=>$job,'product_id'=>$pid,'grams'=>$grams,'qty'=>$qty,'bom_items'=>count($bomRows)]);
  out(['ok'=>true,'id'=>$id,'job_no'=>$job],201);
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  if(str_starts_with($e->getMessage(),'INSUFFICIENT_PACKAGING_MATERIAL:')){$parts=explode(':',$e->getMessage(),2);out(['ok'=>false,'code'=>'INSUFFICIENT_PACKAGING_MATERIAL','material'=>$parts[1]??''],422);}
  out(['ok'=>false,'code'=>'PACKAGING_CREATE_FAILED'],422);
 }
}

if($route==='packaging.report'){
 auth();
 $materials=$pdo->query("SELECT pm.name,pm.unit,pm.reorder_level,
  COALESCE(SUM(CASE WHEN psl.movement_type IN('opening','purchase_in','adjustment_in') THEN psl.qty WHEN psl.movement_type IN('production_out','return_out','damage_out','adjustment_out') THEN -psl.qty ELSE 0 END),0) stock_qty,
  COALESCE((SELECT SUM(ppi.quantity*ppi.unit_rate)/NULLIF(SUM(ppi.quantity),0) FROM packaging_purchase_items ppi WHERE ppi.packaging_material_id=pm.id AND ppi.unit_rate>0),pm.unit_cost,0) avg_rate
  FROM packaging_materials pm LEFT JOIN packaging_stock_ledger psl ON psl.packaging_material_id=pm.id
  GROUP BY pm.id,pm.name,pm.unit,pm.reorder_level,pm.unit_cost ORDER BY pm.name")->fetchAll();
 foreach($materials as &$m){$m['stock_value']=round((float)$m['stock_qty']*(float)$m['avg_rate'],2);$m['low_stock']=(float)$m['stock_qty']<=(float)$m['reorder_level']?1:0;}unset($m);
 $totalPurchases=(float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM packaging_purchase_receipts WHERE status='received'")->fetchColumn();
 $consumed=(float)$pdo->query("SELECT COALESCE(SUM(qty*unit_cost),0) FROM packaging_stock_ledger WHERE movement_type='production_out'")->fetchColumn();
 $stockValue=array_sum(array_map(fn($x)=>(float)$x['stock_value'],$materials));
 $supplier=$pdo->query("SELECT s.name supplier,COUNT(p.id) receipts,COALESCE(SUM(p.total_amount),0) purchases FROM suppliers s LEFT JOIN packaging_purchase_receipts p ON p.supplier_id=s.id AND p.status='received' WHERE s.supplier_type='packaging' GROUP BY s.id,s.name ORDER BY purchases DESC")->fetchAll();
 out(['ok'=>true,'materials'=>$materials,'total_purchases'=>$totalPurchases,'consumed_value'=>$consumed,'stock_value'=>$stockValue,'suppliers'=>$supplier]);
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



if($route==='operations.workboard'){
 $u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);

 $tasks=$pdo->query("SELECT t.id,t.franchise_id,f.code outlet_code,f.name outlet,t.task_type,t.title,t.detail,t.priority,t.status,t.assigned_user_id,u.name assigned_to,t.due_at,t.completed_at,t.escalation_level,t.escalated_at,t.created_at,
   CASE WHEN t.status NOT IN('done','cancelled') AND t.due_at IS NOT NULL AND t.due_at<NOW() THEN 'overdue'
        WHEN t.status NOT IN('done','cancelled') AND t.due_at IS NOT NULL AND t.due_at<=DATE_ADD(NOW(),INTERVAL 24 HOUR) THEN 'due_soon'
        ELSE 'on_time' END sla_status
   FROM operations_tasks t
   LEFT JOIN franchises f ON f.id=t.franchise_id
   LEFT JOIN users u ON u.id=t.assigned_user_id
   ORDER BY (t.status NOT IN('done','cancelled')) DESC,(t.due_at IS NULL),t.due_at,t.id DESC LIMIT 300")->fetchAll();

 $visits=$pdo->query("SELECT v.id,v.franchise_id,f.code outlet_code,f.name outlet,v.visit_type,v.status,v.scheduled_at,v.visited_at,v.visitor_user_id,u.name visitor,
   v.overall_score,v.findings,v.corrective_action,v.next_visit_at,v.created_at
   FROM field_visits v JOIN franchises f ON f.id=v.franchise_id LEFT JOIN users u ON u.id=v.visitor_user_id
   ORDER BY (v.status='scheduled') DESC,v.scheduled_at DESC,v.id DESC LIMIT 200")->fetchAll();

 $tickets=$pdo->query("SELECT t.id,t.ticket_no,t.franchise_id,f.code outlet_code,f.name outlet,t.category,t.subject,t.detail,t.priority,t.status,t.assigned_user_id,u.name assigned_to,
   t.opened_at,t.due_at,t.first_response_at,t.resolved_at,t.resolution,t.escalation_level,t.escalated_at,
   CASE WHEN t.status NOT IN('resolved','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at<NOW() THEN 'overdue'
        WHEN t.status NOT IN('resolved','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at<=DATE_ADD(NOW(),INTERVAL 24 HOUR) THEN 'due_soon'
        ELSE 'on_time' END sla_status
   FROM support_tickets t JOIN franchises f ON f.id=t.franchise_id LEFT JOIN users u ON u.id=t.assigned_user_id
   ORDER BY (t.status NOT IN('resolved','closed','cancelled')) DESC,t.due_at,t.id DESC LIMIT 300")->fetchAll();

 $communications=$pdo->query("SELECT c.id,c.franchise_id,f.code outlet_code,f.name outlet,c.channel,c.direction,c.subject,c.note,c.promised_date,c.follow_up_at,u.name created_by_name,c.created_at
   FROM outlet_communications c JOIN franchises f ON f.id=c.franchise_id LEFT JOIN users u ON u.id=c.created_by
   ORDER BY c.created_at DESC,c.id DESC LIMIT 200")->fetchAll();

 $compliance=$pdo->query("SELECT c.id,c.franchise_id,f.code outlet_code,f.name outlet,c.field_visit_id,c.branding_score,c.pricing_score,c.pos_usage_score,c.stock_handling_score,c.customer_service_score,
   c.overall_score,c.status,c.findings,c.corrective_action,c.corrective_due_at,c.resolved_at,u.name checked_by_name,c.checked_at
   FROM outlet_compliance_checks c JOIN franchises f ON f.id=c.franchise_id LEFT JOIN users u ON u.id=c.checked_by
   ORDER BY c.checked_at DESC,c.id DESC LIMIT 200")->fetchAll();

 $training=$pdo->query("SELECT tr.id,tr.franchise_id,f.code outlet_code,f.name outlet,tr.course_title,tr.status,tr.scheduled_at,tr.completed_at,tr.expires_at,tr.trainer
   FROM outlet_training_records tr JOIN franchises f ON f.id=tr.franchise_id
   WHERE tr.status IN('pending','scheduled','expired') OR (tr.expires_at IS NOT NULL AND tr.expires_at<=DATE_ADD(CURDATE(),INTERVAL 30 DAY))
   ORDER BY FIELD(tr.status,'expired','pending','scheduled'),tr.expires_at,tr.scheduled_at LIMIT 200")->fetchAll();

 $marketing=$pdo->query("SELECT m.id,m.franchise_id,f.code outlet_code,f.name outlet,m.campaign_code,m.campaign_name,m.status,m.priority,m.assigned_user_id,u.name assigned_to,m.due_at,m.escalation_level,m.escalated_at,m.start_date,m.end_date,m.assets_ready,m.execution_verified,m.sales_before,m.sales_during,m.notes,m.updated_at,
   CASE WHEN m.status NOT IN('completed','not_participating') AND m.due_at IS NOT NULL AND m.due_at<NOW() THEN 'overdue'
        WHEN m.status NOT IN('completed','not_participating') AND m.due_at IS NOT NULL AND m.due_at<=DATE_ADD(NOW(),INTERVAL 24 HOUR) THEN 'due_soon'
        ELSE 'on_time' END sla_status
   FROM marketing_executions m JOIN franchises f ON f.id=m.franchise_id LEFT JOIN users u ON u.id=m.assigned_user_id
   ORDER BY FIELD(m.status,'live','ready','planned','completed','not_participating'),m.due_at,m.id DESC LIMIT 200")->fetchAll();

 $outlets=$pdo->query("SELECT id,code,name,district,upazila,status FROM franchises WHERE status<>'closed' ORDER BY name")->fetchAll();
 $assignees=$pdo->query("SELECT u.id,u.name,u.email,r.code role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code IN('OWNER','OPERATIONS','REGIONAL') ORDER BY FIELD(r.code,'OPERATIONS','REGIONAL','OWNER'),u.name")->fetchAll();

 $taskOpen=count(array_filter($tasks,fn($r)=>!in_array($r['status'],['done','cancelled'],true)));
 $taskOver=count(array_filter($tasks,fn($r)=>$r['sla_status']==='overdue'));
 $ticketOpen=count(array_filter($tickets,fn($r)=>!in_array($r['status'],['resolved','closed','cancelled'],true)));
 $ticketOver=count(array_filter($tickets,fn($r)=>$r['sla_status']==='overdue'));
 $visitsDue=count(array_filter($visits,fn($r)=>$r['status']==='scheduled' && !empty($r['scheduled_at']) && strtotime($r['scheduled_at'])<=strtotime('+7 days')));
 $complianceOpen=count(array_filter($compliance,fn($r)=>in_array($r['status'],['watch','non_compliant'],true) && empty($r['resolved_at'])));

 out(['ok'=>true,
  'metrics'=>['open_tasks'=>$taskOpen,'overdue_tasks'=>$taskOver,'open_tickets'=>$ticketOpen,'overdue_tickets'=>$ticketOver,'visits_next_7d'=>$visitsDue,'open_compliance'=>$complianceOpen,'training_attention'=>count($training),'active_marketing'=>count(array_filter($marketing,fn($r)=>in_array($r['status'],['planned','ready','live'],true)))],
  'tasks'=>$tasks,'visits'=>$visits,'tickets'=>$tickets,'communications'=>$communications,'compliance'=>$compliance,'training_attention'=>$training,'marketing'=>$marketing,'outlets'=>$outlets,'assignees'=>$assignees
 ]);
}

if($route==='operations.task.create' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $fid=(int)($body['franchise_id']??0);if($fid>0)outlet_exists($pdo,$fid);
 $title=trim((string)($body['title']??''));if($title==='')out(['ok'=>false,'code'=>'INVALID_TASK'],422);
 $priority=ops_priority((string)($body['priority']??'medium'));$assignee=ops_assignee($pdo,$body['assigned_user_id']??null);
 $due=ops_sla_due($priority,($body['due_at']??null)?:null);
 $q=$pdo->prepare("INSERT INTO operations_tasks(franchise_id,task_type,title,detail,priority,status,assigned_user_id,due_at,source_type,source_id,created_by) VALUES(?,?,?,?,?,'open',?,?,?,?,?)");
 $q->execute([$fid?:null,$body['task_type']??'follow_up',$title,$body['detail']??null,$priority,$assignee,$due,$body['source_type']??null,($body['source_id']??null)?:null,(int)$u['id']]);
 $id=(int)$pdo->lastInsertId();
 if($fid>0)outlet_timeline($pdo,$fid,(int)$u['id'],'task','Task created',$title,'operations_task',$id,['priority'=>$priority,'due_at'=>$due]);
 audit($pdo,(int)$u['id'],'create','operations_task',(string)$id,['franchise_id'=>$fid?:null,'title'=>$title,'priority'=>$priority,'due_at'=>$due,'assigned_user_id'=>$assignee]);
 out(['ok'=>true,'id'=>$id,'due_at'=>$due],201);
}

if($route==='operations.task.update' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$id=(int)($body['id']??0);
 $q=$pdo->prepare("SELECT * FROM operations_tasks WHERE id=?");$q->execute([$id]);$before=$q->fetch();if(!$before)out(['ok'=>false,'code'=>'TASK_NOT_FOUND'],404);
 $status=(string)($body['status']??$before['status']);if(!in_array($status,['open','in_progress','waiting','done','cancelled'],true))out(['ok'=>false,'code'=>'INVALID_TASK_STATUS'],422);
 $priority=ops_priority((string)($body['priority']??$before['priority']));
 $assignee=array_key_exists('assigned_user_id',$body)?ops_assignee($pdo,$body['assigned_user_id']):(($before['assigned_user_id']??null)?(int)$before['assigned_user_id']:null);
 $escalate=!empty($body['escalate']);$level=(int)$before['escalation_level']+($escalate?1:0);
 $due=array_key_exists('due_at',$body)?ops_sla_due($priority,$body['due_at']?:null):$before['due_at'];
 $q=$pdo->prepare("UPDATE operations_tasks SET status=?,priority=?,assigned_user_id=?,due_at=?,detail=?,completed_at=IF(?='done',COALESCE(completed_at,NOW()),NULL),escalation_level=?,escalated_at=IF(?,NOW(),escalated_at) WHERE id=?");
 $q->execute([$status,$priority,$assignee,$due,$body['detail']??$before['detail'],$status,$level,$escalate?1:0,$id]);
 if(!empty($before['franchise_id']))outlet_timeline($pdo,(int)$before['franchise_id'],(int)$u['id'],'task','Task '.$status,$before['title'],'operations_task',$id,['priority'=>$priority,'escalation_level'=>$level]);
 audit($pdo,(int)$u['id'],'update','operations_task',(string)$id,['status'=>$status,'priority'=>$priority,'assigned_user_id'=>$assignee,'escalation_level'=>$level]);
 out(['ok'=>true,'id'=>$id,'status'=>$status,'escalation_level'=>$level]);
}

if($route==='operations.visit.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $id=(int)($body['id']??0);$status=(string)($body['status']??'scheduled');if(!in_array($status,['scheduled','completed','cancelled','follow_up'],true))out(['ok'=>false,'code'=>'INVALID_VISIT_STATUS'],422);
 $visitor=ops_assignee($pdo,$body['visitor_user_id']??($u['id']??null));
 $scores=[];foreach(['cleanliness_score','branding_score','product_display_score','pricing_compliance_score','pos_usage_score','stock_handling_score'] as $k){$v=$body[$k]??null;$scores[$k]=$v===''||$v===null?null:max(0,min(100,(float)$v));}
 $vals=array_values(array_filter($scores,fn($v)=>$v!==null));$overall=$vals?round(array_sum($vals)/count($vals),2):null;
 if($id>0){
  $q=$pdo->prepare("UPDATE field_visits SET visit_type=?,status=?,scheduled_at=?,visited_at=?,visitor_user_id=?,cleanliness_score=?,branding_score=?,product_display_score=?,pricing_compliance_score=?,pos_usage_score=?,stock_handling_score=?,overall_score=?,findings=?,corrective_action=?,next_visit_at=? WHERE id=? AND franchise_id=?");
  $q->execute([$body['visit_type']??'routine',$status,($body['scheduled_at']??null)?:null,$status==='completed'?(($body['visited_at']??null)?:date('Y-m-d H:i:s')):(($body['visited_at']??null)?:null),$visitor,$scores['cleanliness_score'],$scores['branding_score'],$scores['product_display_score'],$scores['pricing_compliance_score'],$scores['pos_usage_score'],$scores['stock_handling_score'],$overall,$body['findings']??null,$body['corrective_action']??null,($body['next_visit_at']??null)?:null,$id,$fid]);
 }else{
  $q=$pdo->prepare("INSERT INTO field_visits(franchise_id,visit_type,status,scheduled_at,visited_at,visitor_user_id,cleanliness_score,branding_score,product_display_score,pricing_compliance_score,pos_usage_score,stock_handling_score,overall_score,findings,corrective_action,next_visit_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $q->execute([$fid,$body['visit_type']??'routine',$status,($body['scheduled_at']??null)?:null,$status==='completed'?(($body['visited_at']??null)?:date('Y-m-d H:i:s')):(($body['visited_at']??null)?:null),$visitor,$scores['cleanliness_score'],$scores['branding_score'],$scores['product_display_score'],$scores['pricing_compliance_score'],$scores['pos_usage_score'],$scores['stock_handling_score'],$overall,$body['findings']??null,$body['corrective_action']??null,($body['next_visit_at']??null)?:null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'field_visit','Field visit '.$status,$body['findings']??($body['visit_type']??'routine'),'field_visit',$id,['overall_score'=>$overall,'next_visit_at'=>$body['next_visit_at']??null]);
 audit($pdo,(int)$u['id'],'visit_save','franchise',(string)$fid,['visit_id'=>$id,'status'=>$status,'overall_score'=>$overall]);
 out(['ok'=>true,'id'=>$id,'overall_score'=>$overall]);
}

if($route==='operations.ticket.create' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $subject=trim((string)($body['subject']??''));if($subject==='')out(['ok'=>false,'code'=>'INVALID_TICKET'],422);
 $category=(string)($body['category']??'other');if(!in_array($category,['stock','pos','delivery','customer','branding','payment','staff_training','other'],true))$category='other';
 $priority=ops_priority((string)($body['priority']??'medium'));$assignee=ops_assignee($pdo,$body['assigned_user_id']??null);$due=ops_sla_due($priority,($body['due_at']??null)?:null);
 $ticketNo='TSB-TKT-'.date('ymdHis').'-'.random_int(100,999);
 $q=$pdo->prepare("INSERT INTO support_tickets(ticket_no,franchise_id,category,subject,detail,priority,status,assigned_user_id,due_at,created_by) VALUES(?,?,?,?,?,?,'open',?,?,?)");
 $q->execute([$ticketNo,$fid,$category,$subject,$body['detail']??null,$priority,$assignee,$due,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO support_ticket_updates(support_ticket_id,update_type,note,new_status,created_by) VALUES(?,'status',?,'open',?)");$q->execute([$id,'Ticket opened',(int)$u['id']]);
 outlet_timeline($pdo,$fid,(int)$u['id'],'support_ticket','Support ticket opened',$ticketNo.' · '.$subject,'support_ticket',$id,['priority'=>$priority,'due_at'=>$due]);
 audit($pdo,(int)$u['id'],'create','support_ticket',(string)$id,['ticket_no'=>$ticketNo,'franchise_id'=>$fid,'category'=>$category,'priority'=>$priority,'due_at'=>$due]);
 out(['ok'=>true,'id'=>$id,'ticket_no'=>$ticketNo,'due_at'=>$due],201);
}

if($route==='operations.ticket.update' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$id=(int)($body['id']??0);
 $q=$pdo->prepare("SELECT * FROM support_tickets WHERE id=?");$q->execute([$id]);$before=$q->fetch();if(!$before)out(['ok'=>false,'code'=>'TICKET_NOT_FOUND'],404);
 $status=(string)($body['status']??$before['status']);if(!in_array($status,['open','assigned','in_progress','waiting','resolved','closed','cancelled'],true))out(['ok'=>false,'code'=>'INVALID_TICKET_STATUS'],422);
 $priority=ops_priority((string)($body['priority']??$before['priority']));$assignee=array_key_exists('assigned_user_id',$body)?ops_assignee($pdo,$body['assigned_user_id']):(($before['assigned_user_id']??null)?(int)$before['assigned_user_id']:null);
 $escalate=!empty($body['escalate']);$level=(int)$before['escalation_level']+($escalate?1:0);$resolution=$body['resolution']??$before['resolution'];
 $firstResponse=in_array($status,['assigned','in_progress','waiting','resolved','closed'],true)?'COALESCE(first_response_at,NOW())':'first_response_at';
 $resolved=in_array($status,['resolved','closed'],true)?'COALESCE(resolved_at,NOW())':($status==='open'?'NULL':'resolved_at');
 $q=$pdo->prepare("UPDATE support_tickets SET status=?,priority=?,assigned_user_id=?,resolution=?,first_response_at={$firstResponse},resolved_at={$resolved},escalation_level=?,escalated_at=IF(?,NOW(),escalated_at) WHERE id=?");
 $q->execute([$status,$priority,$assignee,$resolution,$level,$escalate?1:0,$id]);
 $type=$escalate?'escalation':(in_array($status,['resolved','closed'],true)?'resolution':'status');
 $q=$pdo->prepare("INSERT INTO support_ticket_updates(support_ticket_id,update_type,note,old_status,new_status,created_by) VALUES(?,?,?,?,?,?)");
 $q->execute([$id,$type,$body['note']??($escalate?'Escalated':'Status updated'),$before['status'],$status,(int)$u['id']]);
 outlet_timeline($pdo,(int)$before['franchise_id'],(int)$u['id'],'support_ticket','Ticket '.$status,$before['ticket_no'].' · '.$before['subject'],'support_ticket',$id,['escalation_level'=>$level]);
 audit($pdo,(int)$u['id'],'update','support_ticket',(string)$id,['status'=>$status,'priority'=>$priority,'assigned_user_id'=>$assignee,'escalation_level'=>$level]);
 out(['ok'=>true,'id'=>$id,'status'=>$status,'escalation_level'=>$level]);
}

if($route==='operations.communication.create' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $note=trim((string)($body['note']??''));if($note==='')out(['ok'=>false,'code'=>'INVALID_COMMUNICATION'],422);
 $channel=(string)($body['channel']??'call');if(!in_array($channel,['call','whatsapp','email','meeting','visit','internal_note','other'],true))$channel='other';
 $direction=(string)($body['direction']??'outbound');if(!in_array($direction,['inbound','outbound','internal'],true))$direction='outbound';
 $q=$pdo->prepare("INSERT INTO outlet_communications(franchise_id,channel,direction,subject,note,promised_date,follow_up_at,created_by) VALUES(?,?,?,?,?,?,?,?)");
 $q->execute([$fid,$channel,$direction,$body['subject']??null,$note,($body['promised_date']??null)?:null,($body['follow_up_at']??null)?:null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 if(!empty($body['follow_up_at'])){
  $title='Follow up: '.(($body['subject']??'')?:ucfirst($channel));
  $due=ops_sla_due('medium',$body['follow_up_at']);
  $q=$pdo->prepare("INSERT INTO operations_tasks(franchise_id,task_type,title,detail,priority,status,assigned_user_id,due_at,source_type,source_id,created_by) VALUES(?,'communication_follow_up',?,?, 'medium','open',?,?, 'outlet_communication',?,?)");
  $q->execute([$fid,$title,$note,(int)$u['id'],$due,$id,(int)$u['id']]);
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'communication','Communication · '.$channel,$body['subject']??$note,'outlet_communication',$id,['follow_up_at'=>$body['follow_up_at']??null]);
 audit($pdo,(int)$u['id'],'create','outlet_communication',(string)$id,['franchise_id'=>$fid,'channel'=>$channel,'direction'=>$direction]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='operations.compliance.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $scores=[];foreach(['branding_score','pricing_score','pos_usage_score','stock_handling_score','customer_service_score'] as $k)$scores[$k]=max(0,min(100,(float)($body[$k]??0)));
 $overall=round(array_sum($scores)/count($scores),2);$status=$overall>=80?'compliant':($overall>=60?'watch':'non_compliant');$due=($body['corrective_due_at']??null)?:null;
 $q=$pdo->prepare("INSERT INTO outlet_compliance_checks(franchise_id,field_visit_id,branding_score,pricing_score,pos_usage_score,stock_handling_score,customer_service_score,overall_score,status,findings,corrective_action,corrective_due_at,checked_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
 $q->execute([$fid,($body['field_visit_id']??null)?:null,$scores['branding_score'],$scores['pricing_score'],$scores['pos_usage_score'],$scores['stock_handling_score'],$scores['customer_service_score'],$overall,$status,$body['findings']??null,$body['corrective_action']??null,$due,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 if($status!=='compliant' && trim((string)($body['corrective_action']??''))!==''){
  $priority=$status==='non_compliant'?'high':'medium';$taskDue=ops_sla_due($priority,$due);
  $q=$pdo->prepare("INSERT INTO operations_tasks(franchise_id,task_type,title,detail,priority,status,assigned_user_id,due_at,source_type,source_id,created_by) VALUES(?,'compliance','Compliance corrective action',?,?, 'open',?,?, 'compliance_check',?,?)");
  $q->execute([$fid,$body['corrective_action'],$priority,(int)$u['id'],$taskDue,$id,(int)$u['id']]);
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'compliance','Compliance · '.$status,'Score '.$overall,'compliance_check',$id,['overall_score'=>$overall,'corrective_due_at'=>$due]);
 audit($pdo,(int)$u['id'],'compliance_check','franchise',(string)$fid,['check_id'=>$id,'overall_score'=>$overall,'status'=>$status]);
 out(['ok'=>true,'id'=>$id,'overall_score'=>$overall,'status'=>$status],201);
}


if($route==='operations.marketing.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $name=trim((string)($body['campaign_name']??''));if($name==='')out(['ok'=>false,'code'=>'INVALID_CAMPAIGN'],422);
 $status=(string)($body['status']??'planned');if(!in_array($status,['planned','ready','live','completed','not_participating'],true))out(['ok'=>false,'code'=>'INVALID_CAMPAIGN_STATUS'],422);
 $priority=ops_priority((string)($body['priority']??'medium'));$assignee=ops_assignee($pdo,$body['assigned_user_id']??($u['id']??null));$due=ops_sla_due($priority,($body['due_at']??null)?:null);
 $id=(int)($body['id']??0);$escalate=!empty($body['escalate']);
 if($id>0){
  $q=$pdo->prepare("SELECT escalation_level FROM marketing_executions WHERE id=? AND franchise_id=?");$q->execute([$id,$fid]);$old=$q->fetch();if(!$old)out(['ok'=>false,'code'=>'CAMPAIGN_NOT_FOUND'],404);
  $level=(int)$old['escalation_level']+($escalate?1:0);
  $q=$pdo->prepare("UPDATE marketing_executions SET campaign_code=?,campaign_name=?,status=?,priority=?,assigned_user_id=?,due_at=?,escalation_level=?,escalated_at=IF(?,NOW(),escalated_at),start_date=?,end_date=?,assets_ready=?,execution_verified=?,sales_before=?,sales_during=?,notes=? WHERE id=? AND franchise_id=?");
  $q->execute([$body['campaign_code']??null,$name,$status,$priority,$assignee,$due,$level,$escalate?1:0,($body['start_date']??null)?:null,($body['end_date']??null)?:null,!empty($body['assets_ready'])?1:0,!empty($body['execution_verified'])?1:0,(float)($body['sales_before']??0),(float)($body['sales_during']??0),$body['notes']??null,$id,$fid]);
 }else{
  $level=$escalate?1:0;
  $q=$pdo->prepare("INSERT INTO marketing_executions(franchise_id,campaign_code,campaign_name,status,priority,assigned_user_id,due_at,escalation_level,escalated_at,start_date,end_date,assets_ready,execution_verified,sales_before,sales_during,notes,created_by) VALUES(?,?,?,?,?,?,?,?,IF(?,NOW(),NULL),?,?,?,?,?,?,?,?)");
  $q->execute([$fid,$body['campaign_code']??null,$name,$status,$priority,$assignee,$due,$level,$escalate?1:0,($body['start_date']??null)?:null,($body['end_date']??null)?:null,!empty($body['assets_ready'])?1:0,!empty($body['execution_verified'])?1:0,(float)($body['sales_before']??0),(float)($body['sales_during']??0),$body['notes']??null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'marketing','Marketing · '.$status,$name,'marketing_execution',$id,['priority'=>$priority,'due_at'=>$due,'escalation_level'=>$level,'assets_ready'=>!empty($body['assets_ready']),'execution_verified'=>!empty($body['execution_verified'])]);
 audit($pdo,(int)$u['id'],'marketing_save','franchise',(string)$fid,['marketing_id'=>$id,'campaign_name'=>$name,'status'=>$status,'priority'=>$priority,'assigned_user_id'=>$assignee,'due_at'=>$due,'escalation_level'=>$level]);
 out(['ok'=>true,'id'=>$id,'escalation_level'=>$level]);
}

if($route==='operations.dashboard'){
 $u=auth();
 if(!in_array($u['role'],['OWNER','OPERATIONS'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);

 $today=date('Y-m-d');
 $monthStart=date('Y-m-01');
 $prevStart=date('Y-m-01',strtotime('first day of previous month'));
 $prevEnd=date('Y-m-t',strtotime('last day of previous month'));

 $network=$pdo->query("SELECT
   COUNT(*) total_outlets,
   SUM(f.status='active') active_outlets,
   SUM(f.status IN('pipeline','setup')) pipeline_outlets,
   SUM(CASE WHEN COALESCE((SELECT oh.health FROM outlet_health_checks oh WHERE oh.franchise_id=f.id ORDER BY oh.checked_at DESC,oh.id DESC LIMIT 1),'new') IN('watch','critical') THEN 1 ELSE 0 END) attention_outlets
   FROM franchises f WHERE f.status<>'closed'")->fetch();

 $q=$pdo->prepare("SELECT
   COALESCE(SUM(gross_amount),0) sales,
   COALESCE(SUM(earned_margin),0) earned_margin,
   COUNT(*) receipts
   FROM pos_sales WHERE DATE(sold_at) BETWEEN ? AND ?");
 $q->execute([$monthStart,$today]); $current=$q->fetch();

 $q=$pdo->prepare("SELECT COALESCE(SUM(gross_amount),0) sales,COUNT(*) receipts
   FROM pos_sales WHERE DATE(sold_at) BETWEEN ? AND ?");
 $q->execute([$prevStart,$prevEnd]); $previous=$q->fetch();

 $unsettled=$pdo->query("SELECT
   COUNT(*) rows_count,
   COALESCE(SUM(net_payable),0) amount
   FROM settlements WHERE status IN('draft','review','approved','locked')")->fetch();

 $outlets=$pdo->query("SELECT f.id,f.code,f.name,f.district,f.upazila,f.status,f.margin_tier,f.margin_percent,
   op.division,op.target_open_date,op.operational_state,
   pl.stage pipeline_stage,pl.next_action,pl.blocking_reason,pl.updated_at pipeline_updated_at,
   COALESCE((SELECT SUM(ps.gross_amount) FROM pos_sales ps WHERE ps.franchise_id=f.id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) sales_30d,
   COALESCE((SELECT COUNT(*) FROM pos_sales ps WHERE ps.franchise_id=f.id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) receipts_30d,
   (SELECT MAX(ps.sold_at) FROM pos_sales ps WHERE ps.franchise_id=f.id) last_sale_at,
   COALESCE((SELECT SUM(
      CASE
       WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty*il.unit_value
       WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty*il.unit_value
       ELSE il.qty*il.unit_value
      END
    ) FROM inventory_ledger il WHERE il.location_type='franchise' AND il.location_id=f.id),0) stock_value,
   COALESCE((SELECT oh.health FROM outlet_health_checks oh WHERE oh.franchise_id=f.id ORDER BY oh.checked_at DESC,oh.id DESC LIMIT 1),
     CASE WHEN f.status='critical' THEN 'critical' WHEN f.status='watch' THEN 'watch' WHEN f.status='active' THEN 'healthy' ELSE 'new' END) health,
   COALESCE((SELECT oh.total_score FROM outlet_health_checks oh WHERE oh.franchise_id=f.id ORDER BY oh.checked_at DESC,oh.id DESC LIMIT 1),0) health_score
   FROM franchises f
   LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
   LEFT JOIN outlet_pipeline pl ON pl.franchise_id=f.id
   WHERE f.status<>'closed'
   ORDER BY sales_30d DESC,f.name LIMIT 150")->fetchAll();

 $low=array_values(array_slice(array_filter($outlets,fn($r)=>$r['status']==='active'),-10));
 usort($low,fn($a,$b)=>(float)$a['sales_30d']<=>(float)$b['sales_30d']);

 $stockGaps=array_values(array_filter($outlets,fn($r)=>$r['status']==='active' && (float)$r['stock_value']<=0));
 usort($stockGaps,fn($a,$b)=>strcmp((string)$a['name'],(string)$b['name']));
 $stockGaps=array_slice($stockGaps,0,10);

 $settlements=$pdo->query("SELECT s.id,f.code,f.name outlet,s.period_start,s.period_end,s.verified_sales,s.earned_margin,s.net_payable,s.status
   FROM settlements s JOIN franchises f ON f.id=s.franchise_id
   WHERE s.status IN('draft','review','approved','locked')
   ORDER BY s.period_end DESC,s.net_payable DESC LIMIT 12")->fetchAll();

 $performance=$pdo->query("SELECT pr.id,pr.period_start,pr.period_end,pr.total_score,pr.sales_growth_score,pr.stock_rotation_score,pr.outlet_health_score,
   pr.settlement_score,pr.retention_score,pr.compliance_score,pr.performance_share_percent,pr.performance_share_amount,pr.status
   FROM performance_records pr
   WHERE pr.role_code='OPERATIONS'
   ORDER BY pr.period_end DESC,pr.id DESC LIMIT 1")->fetch() ?: null;

 $currentSales=(float)($current['sales']??0);
 $previousSales=(float)($previous['sales']??0);
 $growth=$previousSales>0?round((($currentSales-$previousSales)/$previousSales)*100,2):null;

 out([
   'ok'=>true,
   'period'=>['from'=>$monthStart,'to'=>$today,'previous_from'=>$prevStart,'previous_to'=>$prevEnd],
   'network'=>[
     'total_outlets'=>(int)($network['total_outlets']??0),
     'active_outlets'=>(int)($network['active_outlets']??0),
     'pipeline_outlets'=>(int)($network['pipeline_outlets']??0),
     'attention_outlets'=>(int)($network['attention_outlets']??0),
   ],
   'sales'=>[
     'current'=>$currentSales,
     'previous'=>$previousSales,
     'growth_percent'=>$growth,
     'receipts'=>(int)($current['receipts']??0),
     'earned_margin'=>(float)($current['earned_margin']??0),
   ],
   'settlement'=>[
     'open_count'=>(int)($unsettled['rows_count']??0),
     'open_amount'=>(float)($unsettled['amount']??0),
   ],
   'outlets'=>$outlets,
   'low_performers'=>$low,
   'stock_gaps'=>$stockGaps,
   'settlements_due'=>$settlements,
   'performance'=>$performance
 ]);
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
 $rows=$pdo->query("SELECT f.id,f.code,f.name,f.district,f.upazila,f.address,f.status,f.margin_mode,f.margin_tier,f.margin_percent,f.opened_at,
   op.owner_name,op.owner_phone,op.owner_email,op.division,op.territory_code,op.target_open_date,op.operational_state,op.suspended_at,
   pl.stage pipeline_stage,pl.next_action,pl.blocking_reason,pl.updated_at pipeline_updated_at,
   COALESCE((SELECT oh.health FROM outlet_health_checks oh WHERE oh.franchise_id=f.id ORDER BY oh.checked_at DESC,oh.id DESC LIMIT 1),'new') health,
   COALESCE((SELECT oh.total_score FROM outlet_health_checks oh WHERE oh.franchise_id=f.id ORDER BY oh.checked_at DESC,oh.id DESC LIMIT 1),0) health_score,
   COALESCE((SELECT SUM(ps.gross_amount) FROM pos_sales ps WHERE ps.franchise_id=f.id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) sales_30d,
   COALESCE((SELECT SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty*il.unit_value WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty*il.unit_value ELSE il.qty*il.unit_value END) FROM inventory_ledger il WHERE il.location_type='franchise' AND il.location_id=f.id),0) stock_value
   FROM franchises f
   LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
   LEFT JOIN outlet_pipeline pl ON pl.franchise_id=f.id
   ORDER BY f.id DESC")->fetchAll();
 out(['ok'=>true,'franchises'=>$rows]);
}

if($route==='franchise.create' && $method==='POST'){
 csrf(); $u=owner();
 $code=trim((string)($body['code']??''));$name=trim((string)($body['name']??''));
 if($code===''||$name==='') out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);
 $pct=(float)($body['margin_percent']??25);
 if($pct<=0||$pct>=100) out(['ok'=>false,'code'=>'INVALID_MARGIN'],422);
 $tier=(string)($body['margin_tier']??'Starter');if(!in_array($tier,['Starter','Growth','Elite','Manual'],true))$tier='Starter';
 $mode=$tier==='Manual'?'manual':'tier';
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare("INSERT INTO franchises(code,name,district,upazila,address,status,margin_mode,margin_tier,margin_percent,opened_at) VALUES(?,?,?,?,?,'pipeline',?,?,?,NULL)");
  $q->execute([$code,$name,$body['district']??null,$body['upazila']??null,$body['address']??null,$mode,$tier,$pct]);
  $id=(int)$pdo->lastInsertId();
  $q=$pdo->prepare("INSERT INTO outlet_profiles(franchise_id,owner_name,owner_phone,owner_email,division,territory_code,shop_type,shop_size_sqft,agreement_no,agreement_date,lease_start,lease_end,target_open_date,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $q->execute([$id,$body['owner_name']??null,$body['owner_phone']??null,$body['owner_email']??null,$body['division']??null,$body['territory_code']??null,$body['shop_type']??null,$body['shop_size_sqft']??null,$body['agreement_no']??null,$body['agreement_date']??null,$body['lease_start']??null,$body['lease_end']??null,$body['target_open_date']??null,(int)$u['id']]);
  $q=$pdo->prepare("INSERT INTO outlet_pipeline(franchise_id,stage,target_open_date,next_action,updated_by) VALUES(?,'lead',?,?,?)");
  $q->execute([$id,$body['target_open_date']??null,$body['next_action']??'Verify franchise application and premises',(int)$u['id']]);
  ensure_outlet_checklist($pdo,$id,'opening');ensure_outlet_checklist($pdo,$id,'closure');
  outlet_timeline($pdo,$id,(int)$u['id'],'created','Outlet record created','New franchise entered the opening pipeline','franchise',$id,['stage'=>'lead']);
  $pdo->commit();
  audit($pdo,(int)$u['id'],'create','franchise',(string)$id,['code'=>$code,'name'=>$name,'stage'=>'lead','margin_percent'=>$pct]);
  out(['ok'=>true,'id'=>$id],201);
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  out(['ok'=>false,'code'=>'FRANCHISE_CREATE_FAILED'],422);
 }
}

if($route==='franchise.360'){
 auth();$fid=(int)($_GET['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);
 $fr=outlet_exists($pdo,$fid);

 $q=$pdo->prepare("SELECT * FROM outlet_profiles WHERE franchise_id=?");$q->execute([$fid]);$profile=$q->fetch()?:[];
 $q=$pdo->prepare("SELECT * FROM outlet_pipeline WHERE franchise_id=?");$q->execute([$fid]);$pipeline=$q->fetch()?:null;
 $q=$pdo->prepare("SELECT * FROM outlet_checklist_items WHERE franchise_id=? ORDER BY checklist_type,sort_order,id");$q->execute([$fid]);$check=$q->fetchAll();
 $opening=array_values(array_filter($check,fn($r)=>$r['checklist_type']==='opening'));
 $closure=array_values(array_filter($check,fn($r)=>$r['checklist_type']==='closure'));

 $q=$pdo->prepare("SELECT id,name,staff_role,phone,email,joined_at,active,notes,created_at FROM outlet_staff WHERE franchise_id=? ORDER BY active DESC,id DESC");$q->execute([$fid]);$staff=$q->fetchAll();
 $q=$pdo->prepare("SELECT tr.id,tr.outlet_staff_id,os.name staff_name,tr.course_code,tr.course_title,tr.status,tr.scheduled_at,tr.completed_at,tr.expires_at,tr.trainer,tr.certificate_ref,tr.notes
   FROM outlet_training_records tr LEFT JOIN outlet_staff os ON os.id=tr.outlet_staff_id WHERE tr.franchise_id=? ORDER BY tr.id DESC");$q->execute([$fid]);$training=$q->fetchAll();
 $q=$pdo->prepare("SELECT id,document_type,title,file_path,status,created_at FROM documents WHERE reference_type IN('franchise','outlet') AND reference_id=? ORDER BY id DESC");$q->execute([$fid]);$documents=$q->fetchAll();
 $q=$pdo->prepare("SELECT id,sales_score,stock_score,settlement_score,compliance_score,total_score,health,notes,checked_at FROM outlet_health_checks WHERE franchise_id=? ORDER BY checked_at DESC,id DESC LIMIT 24");$q->execute([$fid]);$health=$q->fetchAll();

 $q=$pdo->prepare("SELECT COALESCE(SUM(gross_amount),0) sales_30d,COALESCE(SUM(earned_margin),0) margin_30d,COUNT(*) receipts_30d,MAX(sold_at) last_sale_at FROM pos_sales WHERE franchise_id=? AND sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
 $q->execute([$fid]);$sales=$q->fetch();
 $q=$pdo->prepare("SELECT pp.id pack_id,p.name product,pp.grams,pp.mrp,
   SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) qty
   FROM inventory_ledger il JOIN product_packs pp ON pp.id=il.product_pack_id JOIN products p ON p.id=pp.product_id
   WHERE il.location_type='franchise' AND il.location_id=? GROUP BY pp.id,p.name,pp.grams,pp.mrp HAVING ABS(qty)>0.0001 ORDER BY p.name,pp.grams");
 $q->execute([$fid]);$stock=$q->fetchAll();
 $stockValue=0.0;foreach($stock as $s)$stockValue+=(float)$s['qty']*(float)$s['mrp'];

 $q=$pdo->prepare("SELECT id,period_start,period_end,verified_sales,earned_margin,net_payable,status,locked_at FROM settlements WHERE franchise_id=? ORDER BY period_end DESC,id DESC LIMIT 12");$q->execute([$fid]);$settlements=$q->fetchAll();

 $q=$pdo->prepare("SELECT t.id,t.task_type,t.title,t.detail,t.priority,t.status,t.assigned_user_id,u.name assigned_to,t.due_at,t.completed_at,t.escalation_level,t.escalated_at,t.created_at,
   CASE WHEN t.status NOT IN('done','cancelled') AND t.due_at IS NOT NULL AND t.due_at<NOW() THEN 'overdue' WHEN t.status NOT IN('done','cancelled') AND t.due_at IS NOT NULL AND t.due_at<=DATE_ADD(NOW(),INTERVAL 24 HOUR) THEN 'due_soon' ELSE 'on_time' END sla_status
   FROM operations_tasks t LEFT JOIN users u ON u.id=t.assigned_user_id WHERE t.franchise_id=? ORDER BY (t.status NOT IN('done','cancelled')) DESC,t.due_at,t.id DESC LIMIT 100");$q->execute([$fid]);$opsTasks=$q->fetchAll();
 $q=$pdo->prepare("SELECT v.id,v.visit_type,v.status,v.scheduled_at,v.visited_at,v.visitor_user_id,u.name visitor,v.overall_score,v.findings,v.corrective_action,v.next_visit_at
   FROM field_visits v LEFT JOIN users u ON u.id=v.visitor_user_id WHERE v.franchise_id=? ORDER BY v.scheduled_at DESC,v.id DESC LIMIT 100");$q->execute([$fid]);$visits=$q->fetchAll();
 $q=$pdo->prepare("SELECT t.id,t.ticket_no,t.category,t.subject,t.detail,t.priority,t.status,t.assigned_user_id,u.name assigned_to,t.opened_at,t.due_at,t.first_response_at,t.resolved_at,t.resolution,t.escalation_level,t.escalated_at,
   CASE WHEN t.status NOT IN('resolved','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at<NOW() THEN 'overdue' WHEN t.status NOT IN('resolved','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at<=DATE_ADD(NOW(),INTERVAL 24 HOUR) THEN 'due_soon' ELSE 'on_time' END sla_status
   FROM support_tickets t LEFT JOIN users u ON u.id=t.assigned_user_id WHERE t.franchise_id=? ORDER BY (t.status NOT IN('resolved','closed','cancelled')) DESC,t.due_at,t.id DESC LIMIT 100");$q->execute([$fid]);$tickets=$q->fetchAll();
 $q=$pdo->prepare("SELECT c.id,c.channel,c.direction,c.subject,c.note,c.promised_date,c.follow_up_at,u.name created_by_name,c.created_at FROM outlet_communications c LEFT JOIN users u ON u.id=c.created_by WHERE c.franchise_id=? ORDER BY c.created_at DESC,c.id DESC LIMIT 150");$q->execute([$fid]);$communications=$q->fetchAll();
 $q=$pdo->prepare("SELECT c.id,c.field_visit_id,c.branding_score,c.pricing_score,c.pos_usage_score,c.stock_handling_score,c.customer_service_score,c.overall_score,c.status,c.findings,c.corrective_action,c.corrective_due_at,c.resolved_at,u.name checked_by_name,c.checked_at FROM outlet_compliance_checks c LEFT JOIN users u ON u.id=c.checked_by WHERE c.franchise_id=? ORDER BY c.checked_at DESC,c.id DESC LIMIT 100");$q->execute([$fid]);$compliance=$q->fetchAll();
 $q=$pdo->prepare("SELECT m.id,m.campaign_code,m.campaign_name,m.status,m.priority,m.assigned_user_id,u.name assigned_to,m.due_at,m.escalation_level,m.escalated_at,m.start_date,m.end_date,m.assets_ready,m.execution_verified,m.sales_before,m.sales_during,m.notes,m.updated_at,
   CASE WHEN m.status NOT IN('completed','not_participating') AND m.due_at IS NOT NULL AND m.due_at<NOW() THEN 'overdue' WHEN m.status NOT IN('completed','not_participating') AND m.due_at IS NOT NULL AND m.due_at<=DATE_ADD(NOW(),INTERVAL 24 HOUR) THEN 'due_soon' ELSE 'on_time' END sla_status
   FROM marketing_executions m LEFT JOIN users u ON u.id=m.assigned_user_id WHERE m.franchise_id=? ORDER BY m.due_at,m.id DESC LIMIT 100");$q->execute([$fid]);$marketing=$q->fetchAll();

 $events=[];
 $q=$pdo->prepare("SELECT occurred_at,event_type,title,detail,reference_type,reference_id FROM outlet_timeline WHERE franchise_id=? ORDER BY occurred_at DESC,id DESC LIMIT 150");$q->execute([$fid]);
 foreach($q->fetchAll() as $e)$events[]=$e;
 $q=$pdo->prepare("SELECT sold_at occurred_at,'pos_sale' event_type,CONCAT('POS sale · ',receipt_no) title,CONCAT('Verified ',gross_amount,' · margin ',earned_margin) detail,'pos_sale' reference_type,id reference_id FROM pos_sales WHERE franchise_id=? ORDER BY id DESC LIMIT 100");$q->execute([$fid]);foreach($q->fetchAll() as $e)$events[]=$e;
 $q=$pdo->prepare("SELECT created_at occurred_at,'stock' event_type,CONCAT('Stock ',movement_type) title,CONCAT(qty,' units · value ',unit_value) detail,reference_type,reference_id FROM inventory_ledger WHERE location_type='franchise' AND location_id=? ORDER BY id DESC LIMIT 100");$q->execute([$fid]);foreach($q->fetchAll() as $e)$events[]=$e;
 $q=$pdo->prepare("SELECT period_end occurred_at,'settlement' event_type,CONCAT('Settlement ',status) title,CONCAT('Verified ',verified_sales,' · payable ',net_payable) detail,'settlement' reference_type,id reference_id FROM settlements WHERE franchise_id=? ORDER BY id DESC LIMIT 50");$q->execute([$fid]);foreach($q->fetchAll() as $e)$events[]=$e;
 $q=$pdo->prepare("SELECT checked_at occurred_at,'health' event_type,CONCAT('Outlet health · ',health) title,CONCAT('Score ',total_score) detail,'outlet_health' reference_type,id reference_id FROM outlet_health_checks WHERE franchise_id=? ORDER BY id DESC LIMIT 50");$q->execute([$fid]);foreach($q->fetchAll() as $e)$events[]=$e;
 usort($events,fn($a,$b)=>strcmp((string)$b['occurred_at'],(string)$a['occurred_at']));

 $openingDone=count(array_filter($opening,fn($r)=>(int)$r['completed']===1));
 $closureDone=count(array_filter($closure,fn($r)=>(int)$r['completed']===1));
 out(['ok'=>true,'franchise'=>$fr,'profile'=>$profile,'pipeline'=>$pipeline,
  'checklists'=>['opening'=>$opening,'closure'=>$closure,'opening_progress'=>count($opening)?round($openingDone/count($opening)*100):0,'closure_progress'=>count($closure)?round($closureDone/count($closure)*100):0],
  'staff'=>$staff,'training'=>$training,'documents'=>$documents,'health_history'=>$health,'health_latest'=>$health[0]??null,
  'operations'=>['tasks'=>$opsTasks,'visits'=>$visits,'tickets'=>$tickets,'communications'=>$communications,'compliance'=>$compliance,'marketing'=>$marketing],
  'sales'=>$sales,'stock'=>$stock,'stock_value'=>round($stockValue,2),'settlements'=>$settlements,'timeline'=>array_slice($events,0,250)]);
}

if($route==='franchise.profile.update' && $method==='POST'){
 csrf();$u=outlet_ops_user();$fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);
 $before=outlet_exists($pdo,$fid);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare("UPDATE franchises SET name=?,district=?,upazila=?,address=? WHERE id=?");
  $q->execute([trim((string)($body['name']??$before['name'])),$body['district']??$before['district'],$body['upazila']??$before['upazila'],$body['address']??$before['address'],$fid]);
  $q=$pdo->prepare("INSERT INTO outlet_profiles(franchise_id,owner_name,owner_phone,owner_email,division,territory_code,shop_type,shop_size_sqft,agreement_no,agreement_date,lease_start,lease_end,target_open_date,updated_by)
   VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE owner_name=VALUES(owner_name),owner_phone=VALUES(owner_phone),owner_email=VALUES(owner_email),division=VALUES(division),territory_code=VALUES(territory_code),shop_type=VALUES(shop_type),shop_size_sqft=VALUES(shop_size_sqft),agreement_no=VALUES(agreement_no),agreement_date=VALUES(agreement_date),lease_start=VALUES(lease_start),lease_end=VALUES(lease_end),target_open_date=VALUES(target_open_date),updated_by=VALUES(updated_by)");
  $q->execute([$fid,$body['owner_name']??null,$body['owner_phone']??null,$body['owner_email']??null,$body['division']??null,$body['territory_code']??null,$body['shop_type']??null,($body['shop_size_sqft']??null) ?: null,$body['agreement_no']??null,($body['agreement_date']??null) ?: null,($body['lease_start']??null) ?: null,($body['lease_end']??null) ?: null,($body['target_open_date']??null) ?: null,(int)$u['id']]);
  outlet_timeline($pdo,$fid,(int)$u['id'],'profile','Outlet profile updated','Owner, territory, agreement or premises profile updated','franchise',$fid);
  $pdo->commit();audit($pdo,(int)$u['id'],'update_profile','franchise',(string)$fid,['name'=>$body['name']??$before['name'],'division'=>$body['division']??null,'district'=>$body['district']??$before['district'],'upazila'=>$body['upazila']??$before['upazila']]);
  out(['ok'=>true]);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'PROFILE_UPDATE_FAILED'],422);}
}

if($route==='franchise.pipeline.update' && $method==='POST'){
 csrf();$u=outlet_ops_user();$fid=(int)($body['franchise_id']??0);$stage=(string)($body['stage']??'');
 $stages=['lead','verification','agreement','shop_ready','training','stock_ready','pos_ready','launch','live','suspended','closed'];
 if($fid<=0||!in_array($stage,$stages,true))out(['ok'=>false,'code'=>'INVALID_PIPELINE'],422);
 $fr=outlet_exists($pdo,$fid);
 $q=$pdo->prepare("SELECT operational_state FROM outlet_profiles WHERE franchise_id=?");$q->execute([$fid]);$state=(string)($q->fetchColumn()?:'normal');
 if(in_array($stage,['suspended','closed'],true)&&$u['role']!=='OWNER')out(['ok'=>false,'code'=>'OWNER_APPROVAL_REQUIRED'],403);
 if($state==='suspended'&&$stage==='live'&&$u['role']!=='OWNER')out(['ok'=>false,'code'=>'OWNER_APPROVAL_REQUIRED'],403);
 if($stage==='live'){
  $q=$pdo->prepare("SELECT COUNT(*) FROM outlet_checklist_items WHERE franchise_id=? AND checklist_type='opening' AND required=1 AND completed=0");$q->execute([$fid]);$remaining=(int)$q->fetchColumn();
  if($remaining>0)out(['ok'=>false,'code'=>'OPENING_CHECKLIST_INCOMPLETE','remaining'=>$remaining],422);
 }
 if($stage==='closed'){
  $q=$pdo->prepare("SELECT COUNT(*) FROM outlet_checklist_items WHERE franchise_id=? AND checklist_type='closure' AND required=1 AND completed=0");$q->execute([$fid]);$remaining=(int)$q->fetchColumn();
  if($remaining>0)out(['ok'=>false,'code'=>'CLOSURE_CHECKLIST_INCOMPLETE','remaining'=>$remaining],422);
 }

 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare("INSERT INTO outlet_pipeline(franchise_id,stage,target_open_date,next_action,blocking_reason,assigned_user_id,updated_by)
   VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE stage=VALUES(stage),target_open_date=VALUES(target_open_date),next_action=VALUES(next_action),blocking_reason=VALUES(blocking_reason),assigned_user_id=VALUES(assigned_user_id),updated_by=VALUES(updated_by)");
  $q->execute([$fid,$stage,($body['target_open_date']??null) ?: null,$body['next_action']??null,$body['blocking_reason']??null,($body['assigned_user_id']??null) ?: null,(int)$u['id']]);

  $status=in_array($stage,['lead','verification','agreement'],true)?'pipeline':(in_array($stage,['shop_ready','training','stock_ready','pos_ready','launch'],true)?'setup':($stage==='live'?'active':($stage==='closed'?'closed':'watch')));
  $opened=$stage==='live'?"COALESCE(opened_at,CURDATE())":"opened_at";
  $q=$pdo->prepare("UPDATE franchises SET status=?,opened_at={$opened} WHERE id=?");$q->execute([$status,$fid]);
  if($stage==='suspended'){
   $q=$pdo->prepare("INSERT INTO outlet_profiles(franchise_id,operational_state,suspended_at,suspension_reason,updated_by) VALUES(?,'suspended',NOW(),?,?) ON DUPLICATE KEY UPDATE operational_state='suspended',suspended_at=NOW(),suspension_reason=VALUES(suspension_reason),updated_by=VALUES(updated_by)");
   $q->execute([$fid,$body['blocking_reason']??'Owner-approved suspension',(int)$u['id']]);
  }elseif($stage==='live'){
   $q=$pdo->prepare("INSERT INTO outlet_profiles(franchise_id,operational_state,suspended_at,suspension_reason,updated_by) VALUES(?,'normal',NULL,NULL,?) ON DUPLICATE KEY UPDATE operational_state='normal',suspended_at=NULL,suspension_reason=NULL,updated_by=VALUES(updated_by)");
   $q->execute([$fid,(int)$u['id']]);
  }elseif($stage==='closed'){
   $q=$pdo->prepare("INSERT INTO outlet_profiles(franchise_id,closure_reason,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE closure_reason=VALUES(closure_reason),updated_by=VALUES(updated_by)");
   $q->execute([$fid,$body['blocking_reason']??'Owner-approved closure',(int)$u['id']]);
  }
  outlet_timeline($pdo,$fid,(int)$u['id'],'pipeline','Pipeline moved to '.str_replace('_',' ',$stage),$body['next_action']??'','outlet_pipeline',null,['stage'=>$stage,'previous_status'=>$fr['status']]);
  $pdo->commit();audit($pdo,(int)$u['id'],'pipeline_update','franchise',(string)$fid,['stage'=>$stage,'next_action'=>$body['next_action']??null,'blocking_reason'=>$body['blocking_reason']??null]);
  out(['ok'=>true,'stage'=>$stage,'status'=>$status]);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'code'=>'PIPELINE_UPDATE_FAILED'],422);}
}

if($route==='franchise.checklist.toggle' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);$id=(int)($body['id']??0);$done=!empty($body['completed'])?1:0;
 if($fid<=0||$id<=0)out(['ok'=>false,'code'=>'INVALID_CHECKLIST_ITEM'],422);outlet_exists($pdo,$fid);
 $q=$pdo->prepare("UPDATE outlet_checklist_items SET completed=?,completed_at=IF(?=1,NOW(),NULL),completed_by=IF(?=1,?,NULL),notes=? WHERE id=? AND franchise_id=?");
 $q->execute([$done,$done,$done,(int)$u['id'],$body['notes']??null,$id,$fid]);
 if($q->rowCount()!==1)out(['ok'=>false,'code'=>'CHECKLIST_ITEM_NOT_FOUND'],404);
 outlet_timeline($pdo,$fid,(int)$u['id'],'checklist',$done?'Checklist completed':'Checklist reopened',(string)($body['label']??'Outlet checklist item'),'outlet_checklist',$id,['completed'=>$done]);
 audit($pdo,(int)$u['id'],'checklist_update','franchise',(string)$fid,['item_id'=>$id,'completed'=>$done]);
 out(['ok'=>true]);
}

if($route==='franchise.staff.create' && $method==='POST'){
 csrf();$u=outlet_ops_user();$fid=(int)($body['franchise_id']??0);$name=trim((string)($body['name']??''));$role=trim((string)($body['staff_role']??''));
 if($fid<=0||$name===''||$role==='')out(['ok'=>false,'code'=>'INVALID_STAFF'],422);outlet_exists($pdo,$fid);
 $q=$pdo->prepare("INSERT INTO outlet_staff(franchise_id,name,staff_role,phone,email,joined_at,notes,created_by) VALUES(?,?,?,?,?,?,?,?)");
 $q->execute([$fid,$name,$role,$body['phone']??null,$body['email']??null,($body['joined_at']??null) ?: null,$body['notes']??null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 outlet_timeline($pdo,$fid,(int)$u['id'],'staff','Outlet staff added',$name.' · '.$role,'outlet_staff',$id);
 audit($pdo,(int)$u['id'],'create','outlet_staff',(string)$id,['franchise_id'=>$fid,'name'=>$name,'role'=>$role]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='franchise.staff.toggle' && $method==='POST'){
 csrf();$u=outlet_ops_user();$fid=(int)($body['franchise_id']??0);$id=(int)($body['id']??0);$active=!empty($body['active'])?1:0;
 $q=$pdo->prepare("UPDATE outlet_staff SET active=? WHERE id=? AND franchise_id=?");$q->execute([$active,$id,$fid]);
 if($q->rowCount()!==1)out(['ok'=>false,'code'=>'STAFF_NOT_FOUND'],404);
 outlet_timeline($pdo,$fid,(int)$u['id'],'staff',$active?'Outlet staff activated':'Outlet staff deactivated','Staff status updated','outlet_staff',$id);
 audit($pdo,(int)$u['id'],'status','outlet_staff',(string)$id,['active'=>$active]);
 out(['ok'=>true]);
}

if($route==='franchise.training.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);$title=trim((string)($body['course_title']??''));$status=(string)($body['status']??'pending');
 if($fid<=0||$title===''||!in_array($status,['pending','scheduled','completed','expired'],true))out(['ok'=>false,'code'=>'INVALID_TRAINING'],422);outlet_exists($pdo,$fid);
 $id=(int)($body['id']??0);
 if($id>0){
  $q=$pdo->prepare("UPDATE outlet_training_records SET outlet_staff_id=?,course_code=?,course_title=?,status=?,scheduled_at=?,completed_at=?,expires_at=?,trainer=?,certificate_ref=?,notes=? WHERE id=? AND franchise_id=?");
  $q->execute([($body['outlet_staff_id']??null) ?: null,$body['course_code']??null,$title,$status,($body['scheduled_at']??null) ?: null,($body['completed_at']??null) ?: null,($body['expires_at']??null) ?: null,$body['trainer']??null,$body['certificate_ref']??null,$body['notes']??null,$id,$fid]);
 }else{
  $q=$pdo->prepare("INSERT INTO outlet_training_records(franchise_id,outlet_staff_id,course_code,course_title,status,scheduled_at,completed_at,expires_at,trainer,certificate_ref,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
  $q->execute([$fid,$body['outlet_staff_id']?:null,$body['course_code']??null,$title,$status,$body['scheduled_at']?:null,$body['completed_at']?:null,$body['expires_at']?:null,$body['trainer']??null,$body['certificate_ref']??null,$body['notes']??null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'training','Training '.$status,$title,'outlet_training',$id);
 audit($pdo,(int)$u['id'],'training_save','franchise',(string)$fid,['training_id'=>$id,'title'=>$title,'status'=>$status]);
 out(['ok'=>true,'id'=>$id]);
}

if($route==='franchise.health.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $sales=max(0,min(100,(float)($body['sales_score']??0)));$stock=max(0,min(100,(float)($body['stock_score']??0)));$settlement=max(0,min(100,(float)($body['settlement_score']??0)));$compliance=max(0,min(100,(float)($body['compliance_score']??0)));
 $total=round($sales*.35+$stock*.25+$settlement*.25+$compliance*.15,2);
 $health=$total>=75?'healthy':($total>=50?'watch':'critical');
 $q=$pdo->prepare("INSERT INTO outlet_health_checks(franchise_id,sales_score,stock_score,settlement_score,compliance_score,total_score,health,notes,checked_by) VALUES(?,?,?,?,?,?,?,?,?)");
 $q->execute([$fid,$sales,$stock,$settlement,$compliance,$total,$health,$body['notes']??null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 outlet_timeline($pdo,$fid,(int)$u['id'],'health','Outlet health · '.$health,'Score '.$total,'outlet_health',$id,['score'=>$total]);
 audit($pdo,(int)$u['id'],'health_check','franchise',(string)$fid,['score'=>$total,'health'=>$health]);
 out(['ok'=>true,'id'=>$id,'total_score'=>$total,'health'=>$health],201);
}

if($route==='franchise.document.create' && $method==='POST'){
 csrf();$u=outlet_ops_user();$fid=(int)($body['franchise_id']??0);$title=trim((string)($body['title']??''));$type=trim((string)($body['document_type']??'outlet_document'));
 if($fid<=0||$title==='')out(['ok'=>false,'code'=>'INVALID_DOCUMENT'],422);outlet_exists($pdo,$fid);
 $q=$pdo->prepare("INSERT INTO documents(document_type,title,reference_type,reference_id,file_path,status,created_by) VALUES(?,?,'franchise',?,?,?,?)");
 $q->execute([$type,$title,$fid,$body['file_path']??null,$body['status']??'active',(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 outlet_timeline($pdo,$fid,(int)$u['id'],'document','Outlet document registered',$title,'document',$id);
 audit($pdo,(int)$u['id'],'create','document',(string)$id,['franchise_id'=>$fid,'title'=>$title,'document_type'=>$type]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='franchise.territory'){
 auth();
 $rows=$pdo->query("SELECT COALESCE(NULLIF(op.division,''),'Unassigned') division,COALESCE(NULLIF(f.district,''),'Unassigned') district,COALESCE(NULLIF(f.upazila,''),'Unassigned') upazila,
   COUNT(*) total_outlets,SUM(f.status='active') active_outlets,SUM(f.status IN('pipeline','setup')) pipeline_outlets,SUM(f.status IN('watch','critical')) attention_outlets,
   COALESCE(SUM(COALESCE(s.sales_30d,0)),0) sales_30d
   FROM franchises f
   LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
   LEFT JOIN (SELECT franchise_id,SUM(gross_amount) sales_30d FROM pos_sales WHERE sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY franchise_id) s ON s.franchise_id=f.id
   WHERE f.status<>'closed'
   GROUP BY COALESCE(NULLIF(op.division,''),'Unassigned'),COALESCE(NULLIF(f.district,''),'Unassigned'),COALESCE(NULLIF(f.upazila,''),'Unassigned')
   ORDER BY division,district,upazila")->fetchAll();
 $outlets=$pdo->query("SELECT f.id,f.code,f.name,COALESCE(NULLIF(op.division,''),'Unassigned') division,COALESCE(NULLIF(f.district,''),'Unassigned') district,COALESCE(NULLIF(f.upazila,''),'Unassigned') upazila,f.status,pl.stage pipeline_stage,
   COALESCE((SELECT SUM(ps.gross_amount) FROM pos_sales ps WHERE ps.franchise_id=f.id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) sales_30d
   FROM franchises f LEFT JOIN outlet_profiles op ON op.franchise_id=f.id LEFT JOIN outlet_pipeline pl ON pl.franchise_id=f.id WHERE f.status<>'closed' ORDER BY division,district,upazila,f.name")->fetchAll();
 out(['ok'=>true,'territories'=>$rows,'outlets'=>$outlets]);
}

if($route==='sale.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','OPERATIONS','FRANCHISE','OUTLET_MANAGER','CASHIER'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
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


if($route==='franchise.margin.update' && $method==='POST'){
 csrf(); $u=owner();
 $id=(int)($body['id']??0); $pct=(float)($body['margin_percent']??0);
 if($id<=0 || $pct<=0 || $pct>=100) out(['ok'=>false,'code'=>'INVALID_MARGIN'],422);
 $tier=abs($pct-25)<0.0001?'Starter':(abs($pct-27)<0.0001?'Growth':(abs($pct-30)<0.0001?'Elite':'Manual'));
 $mode=$tier==='Manual'?'manual':'tier';
 $q=$pdo->prepare('SELECT id,margin_mode,margin_tier,margin_percent FROM franchises WHERE id=? LIMIT 1');
 $q->execute([$id]); $before=$q->fetch(); if(!$before) out(['ok'=>false,'code'=>'FRANCHISE_NOT_FOUND'],404);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('UPDATE franchises SET margin_mode=?,margin_tier=?,margin_percent=? WHERE id=?');
  $q->execute([$mode,$tier,$pct,$id]);
  $q=$pdo->prepare('UPDATE franchise_margin_history SET effective_to=NOW() WHERE franchise_id=? AND effective_to IS NULL');
  $q->execute([$id]);
  $q=$pdo->prepare('INSERT INTO franchise_margin_history(franchise_id,margin_mode,margin_tier,margin_percent,reason,effective_from,approved_by) VALUES(?,?,?,?,?,NOW(),?)');
  $q->execute([$id,$mode,$tier,$pct,$body['reason']??'Owner margin update',(int)$u['id']]);
  $pdo->commit();
  audit($pdo,(int)$u['id'],'update_margin','franchise',(string)$id,['before'=>$before,'margin_mode'=>$mode,'margin_tier'=>$tier,'margin_percent'=>$pct]);
  out(['ok'=>true,'id'=>$id,'margin_mode'=>$mode,'margin_tier'=>$tier,'margin_percent'=>$pct]);
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  out(['ok'=>false,'code'=>'MARGIN_UPDATE_FAILED'],422);
 }
}

if($route==='finance.summary'){
 auth();
 $s=(float)$pdo->query('SELECT COALESCE(SUM(gross_amount),0) FROM pos_sales')->fetchColumn();
 $m=(float)$pdo->query('SELECT COALESCE(SUM(earned_margin),0) FROM pos_sales')->fetchColumn();
 $e=(float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM finance_ledger")->fetchColumn();
 $dist=max(0,$s-$m-$e);
 $settings=[];
 foreach($pdo->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN('management_share_active_tier','management_share_manual_percent')")->fetchAll() as $row){$settings[$row['setting_key']]=$row['setting_value'];}
 $tier=$settings['management_share_active_tier']??'Base';
 $pct=['Base'=>15.0,'Growth'=>20.0,'Elite'=>25.0][$tier]??(float)($settings['management_share_manual_percent']??20);
 if($tier==='Manual') $pct=(float)($settings['management_share_manual_percent']??20);
 $share=round($dist*$pct/100,2);
 out(['ok'=>true,'verified_sales'=>$s,'franchise_earned_margin'=>$m,'approved_expenses'=>$e,'distributable_profit'=>$dist,'management_tier'=>$tier,'management_percent'=>$pct,'management_share'=>$share,'company_net_after_management'=>round($dist-$share,2)]);
}

if($route==='settings'){
 $u=auth();
 $rows=$pdo->query('SELECT setting_key,setting_value FROM system_settings ORDER BY setting_key')->fetchAll();
 $settings=[]; foreach($rows as $row){$settings[$row['setting_key']]=$row['setting_value'];}
 $defaults=[
  'pricing_tube30'=>'60','pricing_pouch50'=>'18','pricing_pouch100'=>'22','pricing_ctc250'=>'24','pricing_ctc500'=>'28',
  'pricing_labour'=>'5','pricing_overhead'=>'7','pricing_logistics'=>'4','pricing_wastage_percent'=>'3','tax_provision_percent'=>'0',
  'management_share_active_tier'=>'Base','management_share_manual_percent'=>'20'
 ];
 foreach($defaults as $k=>$v){if(!array_key_exists($k,$settings))$settings[$k]=$v;}
 out(['ok'=>true,'settings'=>$settings]);
}

if($route==='settings.save' && $method==='POST'){
 csrf(); $u=owner();
 $allowed=[
  'pricing_tube30','pricing_pouch50','pricing_pouch100','pricing_ctc250','pricing_ctc500','pricing_labour','pricing_overhead','pricing_logistics',
  'pricing_wastage_percent','tax_provision_percent','management_share_active_tier','management_share_manual_percent'
 ];
 $incoming=$body['settings']??[];
 if(!is_array($incoming)) out(['ok'=>false,'code'=>'INVALID_SETTINGS'],422);
 $q=$pdo->prepare('INSERT INTO system_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
 foreach($incoming as $k=>$v){
  if(!in_array($k,$allowed,true)) continue;
  if($k==='management_share_active_tier'){
   if(!in_array((string)$v,['Base','Growth','Elite','Manual'],true)) out(['ok'=>false,'code'=>'INVALID_MANAGEMENT_TIER'],422);
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
 $type=(string)($_GET['type']??'all');
 $where=in_array($type,['tea','packaging','logistics','other'],true)?" WHERE s.supplier_type=".$pdo->quote($type):'';
 $rows=$pdo->query("SELECT s.id,s.supplier_type,s.name,s.contact_person,s.phone,s.email,s.address,s.payment_terms_days,s.credit_limit,s.opening_balance,s.active,
  COALESCE((SELECT SUM(pr.total_amount) FROM purchase_receipts pr WHERE pr.supplier_id=s.id AND pr.status='received'),0) tea_purchases,
  COALESCE((SELECT SUM(ppr.total_amount) FROM packaging_purchase_receipts ppr WHERE ppr.supplier_id=s.id AND ppr.status='received'),0) packaging_purchases,
  COALESCE((SELECT SUM(sp.amount) FROM supplier_payments sp WHERE sp.supplier_id=s.id),0) payments,
  COALESCE((SELECT SUM(sr.amount) FROM supplier_returns sr WHERE sr.supplier_id=s.id),0) returns
  FROM suppliers s".$where." ORDER BY s.supplier_type,s.name")->fetchAll();
 foreach($rows as &$row){
  $row['purchases']=round((float)$row['tea_purchases']+(float)$row['packaging_purchases'],2);
  $row['balance']=round((float)$row['opening_balance']+$row['purchases']-(float)$row['payments']-(float)$row['returns'],2);
 } unset($row);
 out(['ok'=>true,'suppliers'=>$rows]);
}

if($route==='supplier.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','WAREHOUSE','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $name=trim((string)($body['name']??''));$type=(string)($body['supplier_type']??'tea');
 if($name===''||!in_array($type,['tea','packaging','logistics','other'],true)) out(['ok'=>false,'code'=>'INVALID_SUPPLIER'],422);
 $q=$pdo->prepare("INSERT INTO suppliers(supplier_type,name,contact_person,phone,email,address,payment_terms_days,credit_limit,opening_balance,active) VALUES(?,?,?,?,?,?,?,?,?,1)");
 try{$q->execute([$type,$name,$body['contact_person']??null,$body['phone']??null,$body['email']??null,$body['address']??null,(int)($body['payment_terms_days']??0),(float)($body['credit_limit']??0),(float)($body['opening_balance']??0)]);}
 catch(Throwable $e){out(['ok'=>false,'code'=>'SUPPLIER_CREATE_FAILED'],422);}
 $id=(int)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','supplier',(string)$id,['name'=>$name,'supplier_type'=>$type]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='supplier.payment.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','FINANCE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $sid=(int)($body['supplier_id']??0);$amount=(float)($body['amount']??0);
 if($sid<=0||$amount<=0) out(['ok'=>false,'code'=>'INVALID_SUPPLIER_PAYMENT'],422);
 $q=$pdo->prepare("INSERT INTO supplier_payments(supplier_id,payment_date,amount,payment_method,reference_no,memo,created_by) VALUES(?,COALESCE(?,CURDATE()),?,?,?,?,?)");
 $date=$body['payment_date']??null;
 $q->execute([$sid,$date,$amount,$body['payment_method']??'bank',$body['reference_no']??null,$body['memo']??null,(int)$u['id']]);
 $id=(int)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','supplier_payment',(string)$id,['supplier_id'=>$sid,'amount'=>$amount]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='supplier.return.create' && $method==='POST'){
 csrf(); $u=auth();
 if(!in_array($u['role'],['OWNER','FINANCE','WAREHOUSE'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $sid=(int)($body['supplier_id']??0);$amount=(float)($body['amount']??0);$type=(string)($body['source_type']??'other');
 if($sid<=0||$amount<=0||!in_array($type,['tea','packaging','other'],true)) out(['ok'=>false,'code'=>'INVALID_SUPPLIER_RETURN'],422);
 $q=$pdo->prepare("INSERT INTO supplier_returns(supplier_id,return_date,source_type,amount,reference_no,memo,created_by) VALUES(?,COALESCE(?,CURDATE()),?,?,?,?,?)");
 $q->execute([$sid,$body['return_date']??null,$type,$amount,$body['reference_no']??null,$body['memo']??null,(int)$u['id']]);
 $id=(int)$pdo->lastInsertId();audit($pdo,(int)$u['id'],'create','supplier_return',(string)$id,['supplier_id'=>$sid,'amount'=>$amount,'source_type'=>$type]);
 out(['ok'=>true,'id'=>$id],201);
}

if($route==='supplier.ledger'){
 auth();$sid=(int)($_GET['supplier_id']??0);if($sid<=0)out(['ok'=>false,'code'=>'INVALID_SUPPLIER'],422);
 $q=$pdo->prepare("SELECT * FROM (
   SELECT CONCAT('RT-',pr.id) row_id,pr.received_date entry_date,'Raw tea purchase' entry_type,pr.reference_no reference_no,pr.total_amount debit,0 credit
   FROM purchase_receipts pr WHERE pr.supplier_id=? AND pr.status='received'
   UNION ALL
   SELECT CONCAT('PK-',ppr.id),ppr.received_date,'Packaging purchase',ppr.reference_no,ppr.total_amount,0
   FROM packaging_purchase_receipts ppr WHERE ppr.supplier_id=? AND ppr.status='received'
   UNION ALL
   SELECT CONCAT('PM-',sp.id),sp.payment_date,'Payment',sp.reference_no,0,sp.amount
   FROM supplier_payments sp WHERE sp.supplier_id=?
   UNION ALL
   SELECT CONCAT('RTN-',sr.id),sr.return_date,CONCAT(UPPER(sr.source_type),' return'),sr.reference_no,0,sr.amount
   FROM supplier_returns sr WHERE sr.supplier_id=?
 ) x ORDER BY entry_date DESC,row_id DESC");
 $q->execute([$sid,$sid,$sid,$sid]);$rows=$q->fetchAll();
 $sq=$pdo->prepare("SELECT opening_balance FROM suppliers WHERE id=?");$sq->execute([$sid]);$opening=(float)$sq->fetchColumn();
 $asc=array_reverse($rows);$balance=$opening;
 foreach($asc as &$r){$balance+=(float)$r['debit']-(float)$r['credit'];$r['balance']=round($balance,2);}unset($r);
 $rows=array_reverse($asc);
 out(['ok'=>true,'opening_balance'=>$opening,'closing_balance'=>round($balance,2),'ledger'=>$rows]);
}

if($route==='supplier.report'){
 auth();
 $suppliers=$pdo->query("SELECT id,supplier_type,name,opening_balance FROM suppliers WHERE active=1 ORDER BY supplier_type,name")->fetchAll();
 $report=[];$today=new DateTimeImmutable('today');
 foreach($suppliers as $srow){
  $sid=(int)$srow['id'];
  $q=$pdo->prepare("SELECT entry_date,due_date,amount FROM (
    SELECT received_date entry_date,COALESCE(due_date,received_date) due_date,total_amount amount FROM purchase_receipts WHERE supplier_id=? AND status='received'
    UNION ALL
    SELECT received_date,COALESCE(due_date,received_date),total_amount FROM packaging_purchase_receipts WHERE supplier_id=? AND status='received'
  ) x ORDER BY entry_date ASC");
  $q->execute([$sid,$sid]);$invoices=$q->fetchAll();
  $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=?");$q->execute([$sid]);$paid=(float)$q->fetchColumn();
  $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_returns WHERE supplier_id=?");$q->execute([$sid]);$returns=(float)$q->fetchColumn();
  $credits=$paid+$returns;$aging=['current'=>0.0,'d0_30'=>0.0,'d31_60'=>0.0,'d61_90'=>0.0,'d90_plus'=>max(0,(float)$srow['opening_balance'])];$purchases=0.0;
  foreach($invoices as $inv){
   $amount=(float)$inv['amount'];$purchases+=$amount;
   $apply=min($credits,$amount);$credits-=$apply;$remain=$amount-$apply;if($remain<=0)continue;
   $due=new DateTimeImmutable($inv['due_date']);$days=(int)$due->diff($today)->format('%r%a');
   if($days<0)$aging['current']+=$remain;elseif($days<=30)$aging['d0_30']+=$remain;elseif($days<=60)$aging['d31_60']+=$remain;elseif($days<=90)$aging['d61_90']+=$remain;else$aging['d90_plus']+=$remain;
  }
  $balance=round((float)$srow['opening_balance']+$purchases-$paid-$returns,2);
  $report[]=['id'=>$sid,'supplier_type'=>$srow['supplier_type'],'name'=>$srow['name'],'purchases'=>round($purchases,2),'payments'=>round($paid,2),'returns'=>round($returns,2),'balance'=>$balance]+$aging;
 }
 out(['ok'=>true,'report'=>$report]);
}

if($route==='rawtea'){
 auth();
 $rows=$pdo->query("SELECT r.id,r.code,r.name,r.tea_type,r.origin,r.active,
  COALESCE(SUM(CASE WHEN l.movement_type IN('opening','purchase_in','adjustment_in') THEN l.qty_kg WHEN l.movement_type IN('blend_out','return_out','adjustment_out') THEN -l.qty_kg ELSE 0 END),0) stock_kg,
  COALESCE((SELECT SUM(pi.quantity_kg*pi.unit_rate)/NULLIF(SUM(pi.quantity_kg),0) FROM purchase_items pi WHERE pi.raw_tea_material_id=r.id AND pi.unit_rate>0),0) avg_rate
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
 $sq=$pdo->prepare("SELECT id,payment_terms_days FROM suppliers WHERE id=? AND supplier_type='tea' AND active=1");$sq->execute([$sid]);$supplier=$sq->fetch();if(!$supplier)out(['ok'=>false,'code'=>'SUPPLIER_NOT_FOUND'],404);
 $rq=$pdo->prepare("SELECT name FROM raw_tea_materials WHERE id=? AND active=1");$rq->execute([$rid]);$rawName=$rq->fetchColumn();if(!$rawName)out(['ok'=>false,'code'=>'RAW_TEA_NOT_FOUND'],404);
 $pdo->beginTransaction();
 try{
  $ref='GRN-'.date('ymdHis').'-'.random_int(100,999);$total=round($kg*$rate,2);
  $received=$body['received_date']??date('Y-m-d');$due=$body['due_date']??date('Y-m-d',strtotime($received.' +'.(int)$supplier['payment_terms_days'].' days'));
  $q=$pdo->prepare("INSERT INTO purchase_receipts(supplier_id,reference_no,invoice_no,challan_no,received_date,due_date,status,total_amount,created_by) VALUES(?,?,?,?,?,?,'received',?,?)");
  $q->execute([$sid,$ref,$body['invoice_no']??null,$body['challan_no']??null,$received,$due,$total,(int)$u['id']]);$pr=(int)$pdo->lastInsertId();
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
  GROUP_CONCAT(CONCAT(r.name,' ',TRIM(TRAILING '0' FROM TRIM(TRAILING '.' FROM pbi.qty_kg)),'kg') ORDER BY r.name SEPARATOR ' + ') components,
  COALESCE(SUM(pbi.qty_kg*pbi.unit_cost),0) input_cost,
  COALESCE(ROUND(SUM(pbi.qty_kg*pbi.unit_cost)/NULLIF(pb.output_kg,0),2),0) cost_per_output_kg
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
 if(!in_array($u['role'],['OWNER','WAREHOUSE','PRODUCTION'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $pid=(int)($body['product_id']??0);$output=(float)($body['output_kg']??0);$qc=(string)($body['qc_status']??'pass');$items=$body['components']??[];
 if($pid<=0||$output<0||!is_array($items)||count($items)<1||!in_array($qc,['pending','pass','hold','reject'],true)) out(['ok'=>false,'code'=>'INVALID_BLEND'],422);
 $pq=$pdo->prepare("SELECT id FROM products WHERE id=? AND active=1");$pq->execute([$pid]);if(!$pq->fetchColumn())out(['ok'=>false,'code'=>'PRODUCT_NOT_FOUND'],404);
 $input=0.0;$normalized=[];
 foreach($items as $item){
  $rid=(int)($item['raw_tea_material_id']??0);$kg=(float)($item['kg']??0);if($rid<=0||$kg<=0)out(['ok'=>false,'code'=>'INVALID_BLEND_COMPONENT'],422);
  $sq=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','purchase_in','adjustment_in') THEN qty_kg WHEN movement_type IN('blend_out','return_out','adjustment_out') THEN -qty_kg ELSE 0 END),0) FROM raw_tea_stock_ledger WHERE raw_tea_material_id=?");
  $sq->execute([$rid]);$available=(float)$sq->fetchColumn();if($kg>$available+0.00001)out(['ok'=>false,'code'=>'INSUFFICIENT_RAW_TEA','raw_tea_material_id'=>$rid,'available'=>$available],422);
  $cq=$pdo->prepare("SELECT COALESCE(SUM(quantity_kg*unit_rate)/NULLIF(SUM(quantity_kg),0),0) FROM purchase_items WHERE raw_tea_material_id=? AND unit_rate>0");$cq->execute([$rid]);$cost=(float)$cq->fetchColumn();
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

if($route==='pricing.packaging-costs'){
 auth();
 $rows=$pdo->query("SELECT pp.product_id,pp.grams,
  COALESCE(SUM(b.qty_per_pack*COALESCE((
    SELECT SUM(ppi.quantity*ppi.unit_rate)/NULLIF(SUM(ppi.quantity),0)
    FROM packaging_purchase_items ppi
    WHERE ppi.packaging_material_id=b.packaging_material_id AND ppi.unit_rate>0
  ),pm.unit_cost,0)),0) packaging_cost
  FROM product_packs pp
  LEFT JOIN product_packaging_bom b ON b.product_pack_id=pp.id
  LEFT JOIN packaging_materials pm ON pm.id=b.packaging_material_id
  GROUP BY pp.id,pp.product_id,pp.grams")->fetchAll();
 out(['ok'=>true,'costs'=>$rows]);
}


if($route==='tea.overview'){
 auth();
 $rows=$pdo->query("SELECT p.id,p.sku,p.name,p.category,p.purchase_cost_per_kg,
  (SELECT COUNT(*) FROM product_packs pp WHERE pp.product_id=p.id AND pp.active=1) sku_count,
  (SELECT MAX(pb.produced_at) FROM production_batches pb WHERE pb.product_id=p.id) last_produced_at,
  COALESCE((SELECT SUM(CASE
    WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty
    WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty
    ELSE il.qty END)
   FROM inventory_ledger il JOIN product_packs pp2 ON pp2.id=il.product_pack_id WHERE pp2.product_id=p.id),0) finished_stock_qty
  FROM products p WHERE p.active=1 ORDER BY p.category,p.name")->fetchAll();
 out(['ok'=>true,'teas'=>$rows,'count'=>count($rows)]);
}

if($route==='tea.history'){
 auth(); $pid=(int)($_GET['product_id']??0);
 if($pid<=0) out(['ok'=>false,'code'=>'INVALID_TEA'],422);
 $q=$pdo->prepare("SELECT p.id,p.sku,p.name,p.category,p.purchase_cost_per_kg,p.active FROM products p WHERE p.id=? LIMIT 1");
 $q->execute([$pid]); $tea=$q->fetch(); if(!$tea) out(['ok'=>false,'code'=>'TEA_NOT_FOUND'],404);

 $q=$pdo->prepare("SELECT pp.id,pp.grams,pp.mrp,pp.active,
   COALESCE((SELECT SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) FROM inventory_ledger il WHERE il.product_pack_id=pp.id),0) stock_qty
   FROM product_packs pp WHERE pp.product_id=? ORDER BY pp.grams");
 $q->execute([$pid]); $packs=$q->fetchAll();

 $q=$pdo->prepare("SELECT pr.id,pr.reference_no,pr.received_date event_at,s.name supplier,pi.quantity_kg qty_kg,pi.unit_rate,pi.batch_no,pr.total_amount
   FROM purchase_items pi JOIN purchase_receipts pr ON pr.id=pi.purchase_receipt_id LEFT JOIN suppliers s ON s.id=pr.supplier_id
   WHERE pi.product_id=? ORDER BY pr.id DESC LIMIT 100");
 $q->execute([$pid]); $purchases=$q->fetchAll();

 $q=$pdo->prepare("SELECT pb.id,pb.batch_no,pb.input_kg,pb.output_kg,pb.wastage_kg,pb.qc_status,pb.produced_at event_at,
   GROUP_CONCAT(CONCAT(r.name,' ',TRIM(TRAILING '0' FROM TRIM(TRAILING '.' FROM pbi.qty_kg)),'kg') ORDER BY r.name SEPARATOR ' + ') components,
   COALESCE(SUM(pbi.qty_kg*pbi.unit_cost),0) input_cost
   FROM production_batches pb
   LEFT JOIN production_batch_inputs pbi ON pbi.production_batch_id=pb.id
   LEFT JOIN raw_tea_materials r ON r.id=pbi.raw_tea_material_id
   WHERE pb.product_id=? GROUP BY pb.id ORDER BY pb.id DESC LIMIT 100");
 $q->execute([$pid]); $production=$q->fetchAll();

 $q=$pdo->prepare("SELECT pj.id,pj.job_no,pj.batch_no,pj.pack_qty,pp.grams,pj.labour_cost,pj.sealing_cost,pj.other_cost,pj.completed_at event_at
   FROM packaging_jobs pj JOIN product_packs pp ON pp.id=pj.product_pack_id WHERE pp.product_id=? ORDER BY pj.id DESC LIMIT 100");
 $q->execute([$pid]); $packaging=$q->fetchAll();

 $q=$pdo->prepare("SELECT il.id,il.location_type,il.location_id,il.movement_type,il.qty,il.unit_value,il.reference_type,il.reference_id,il.batch_no,il.created_at event_at,pp.grams
   FROM inventory_ledger il JOIN product_packs pp ON pp.id=il.product_pack_id
   WHERE pp.product_id=? ORDER BY il.id DESC LIMIT 200");
 $q->execute([$pid]); $stock=$q->fetchAll();

 $q=$pdo->prepare("SELECT ps.id sale_id,ps.receipt_no,ps.franchise_id,ps.gross_amount,ps.earned_margin,ps.margin_percent,ps.payment_method,ps.sold_at event_at,
   psi.qty,psi.unit_price,psi.line_total,pp.grams
   FROM pos_sale_items psi JOIN pos_sales ps ON ps.id=psi.pos_sale_id JOIN product_packs pp ON pp.id=psi.product_pack_id
   WHERE pp.product_id=? ORDER BY ps.id DESC LIMIT 200");
 $q->execute([$pid]); $sales=$q->fetchAll();

 $q=$pdo->prepare("SELECT pph.id,pph.old_mrp,pph.new_mrp,pph.reason,pph.effective_at,pp.grams
   FROM product_price_history pph JOIN product_packs pp ON pp.id=pph.product_pack_id WHERE pp.product_id=? ORDER BY pph.id DESC LIMIT 100");
 $q->execute([$pid]); $prices=$q->fetchAll();

 $events=[];
 foreach($purchases as $x)$events[]=['event_at'=>$x['event_at'],'type'=>'PURCHASE','reference'=>$x['reference_no'],'detail'=>($x['supplier']?:'Supplier').' · '.(float)$x['qty_kg'].'kg @ '.(float)$x['unit_rate'],'amount'=>$x['total_amount']];
 foreach($production as $x)$events[]=['event_at'=>$x['event_at'],'type'=>'BLEND / PRODUCTION','reference'=>$x['batch_no'],'detail'=>($x['components']?:'Production batch').' · output '.(float)$x['output_kg'].'kg · waste '.(float)$x['wastage_kg'].'kg · QC '.$x['qc_status'],'amount'=>$x['input_cost']];
 foreach($packaging as $x)$events[]=['event_at'=>$x['event_at'],'type'=>'PACKAGING','reference'=>$x['job_no'],'detail'=>(int)$x['pack_qty'].' × '.(int)$x['grams'].'g · batch '.($x['batch_no']?:'—'),'amount'=>(float)$x['labour_cost']+(float)$x['sealing_cost']+(float)$x['other_cost']];
 foreach($stock as $x)$events[]=['event_at'=>$x['event_at'],'type'=>'STOCK '.strtoupper((string)$x['movement_type']),'reference'=>$x['reference_type'].'#'.$x['reference_id'],'detail'=>(float)$x['qty'].' × '.(int)$x['grams'].'g · '.$x['location_type'],'amount'=>(float)$x['qty']*(float)$x['unit_value']];
 foreach($sales as $x)$events[]=['event_at'=>$x['event_at'],'type'=>'POS SALE','reference'=>$x['receipt_no'],'detail'=>(float)$x['qty'].' × '.(int)$x['grams'].'g @ '.(float)$x['unit_price'].' · margin '.(float)$x['margin_percent'].'%','amount'=>$x['line_total']];
 foreach($prices as $x)$events[]=['event_at'=>$x['effective_at'],'type'=>'MRP CHANGE','reference'=>(int)$x['grams'].'g','detail'=>'MRP '.(float)$x['old_mrp'].' → '.(float)$x['new_mrp'].($x['reason']?' · '.$x['reason']:''),'amount'=>$x['new_mrp']];
 usort($events,fn($a,$b)=>strcmp((string)$b['event_at'],(string)$a['event_at']));

 out(['ok'=>true,'tea'=>$tea,'packs'=>$packs,'purchases'=>$purchases,'production'=>$production,'packaging'=>$packaging,'stock_movements'=>$stock,'sales'=>$sales,'price_history'=>$prices,'events'=>array_slice($events,0,400)]);
}

if($route==='workspace.records'){
 $u=auth(); $module=(string)($_GET['module']??'');
 if($module==='audit' && !in_array($u['role'],['OWNER','AUDITOR'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $map=[
  'qc'=>"SELECT q.id,q.checked_at,q.check_type,q.status,q.moisture_percent,q.sample_weight_kg,q.notes,pb.batch_no,p.name tea
        FROM qc_checks q LEFT JOIN production_batches pb ON pb.id=q.production_batch_id LEFT JOIN products p ON p.id=pb.product_id ORDER BY q.id DESC LIMIT 300",
  'wastage'=>"SELECT w.id,w.created_at,w.reference_type,w.reference_id,p.name tea,w.qty_kg,w.value_amount,w.reason,w.status
        FROM wastage_records w LEFT JOIN products p ON p.id=w.product_id ORDER BY w.id DESC LIMIT 300",
  'performance'=>"SELECT pr.id,pr.role_code,u.name user_name,pr.period_start,pr.period_end,pr.total_score,pr.distributable_profit,pr.performance_share_percent,pr.performance_share_amount,pr.status
        FROM performance_records pr LEFT JOIN users u ON u.id=pr.user_id ORDER BY pr.id DESC LIMIT 300",
  'customers'=>"SELECT id,franchise_id,name,phone,email,loyalty_points,active,created_at FROM customers ORDER BY id DESC LIMIT 300",
  'b2b'=>"SELECT id,order_no,company_name,contact_name,contact_phone,order_date,total_amount,paid_amount,status,referral_source FROM corporate_orders ORDER BY id DESC LIMIT 300",
  'logistics'=>"SELECT id,shipment_no,source_type,source_id,destination_type,destination_id,carrier,challan_no,delivery_cost,dispatch_at,received_at,status FROM logistics_shipments ORDER BY id DESC LIMIT 300",
  'approvals'=>"SELECT id,approval_type,reference_type,reference_id,assigned_role_code,status,decision_note,decided_at,created_at FROM approvals ORDER BY id DESC LIMIT 300",
  'documents'=>"SELECT id,document_type,title,reference_type,reference_id,file_path,status,created_at FROM documents ORDER BY id DESC LIMIT 300",
  'notifications'=>"SELECT id,user_id,role_code,severity,title,message,read_at,created_at FROM notifications ORDER BY id DESC LIMIT 300",
  'audit'=>"SELECT a.id,a.created_at,u.name user_name,u.email,a.action,a.entity_type,a.entity_id,a.ip_address FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 300",
  'price_history'=>"SELECT ph.id,p.name tea,pp.grams,ph.old_mrp,ph.new_mrp,ph.reason,ph.effective_at FROM product_price_history ph JOIN product_packs pp ON pp.id=ph.product_pack_id JOIN products p ON p.id=pp.product_id ORDER BY ph.id DESC LIMIT 300"
 ];
 if(!isset($map[$module])) out(['ok'=>false,'code'=>'INVALID_MODULE'],422);
 $rows=$pdo->query($map[$module])->fetchAll();
 out(['ok'=>true,'module'=>$module,'records'=>$rows]);
}

out(['ok'=>false,'code'=>'NOT_FOUND'],404);
