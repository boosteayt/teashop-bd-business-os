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
