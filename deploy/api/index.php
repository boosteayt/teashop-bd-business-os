<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
session_name('TSBOS');
session_start();

$configFile='/home/teashopc/teashop-os-config.php';
function out(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if(!is_file($configFile)) out(['ok'=>false,'code'=>'CONFIG_REQUIRED','message'=>'Server database configuration is not installed.'],503);
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
function audit(PDO $pdo,?int $uid,string $action,string $type,?string $id=null,array $after=[]): void {
 $q=$pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,after_json,ip_address) VALUES(?,?,?,?,?,?)');
 $q->execute([$uid,$action,$type,$id,json_encode($after,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);
}

if($route==='health') out(['ok'=>true,'service'=>'Tea Shop BD Business OS API','database'=>'connected']);

if($route==='login' && $method==='POST'){
 $email=trim((string)($body['email']??'')); $password=(string)($body['password']??'');
 $q=$pdo->prepare('SELECT u.id,u.name,u.email,u.password_hash,u.must_change_password,r.code role,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.active=1 LIMIT 1');
 $q->execute([$email]); $u=$q->fetch();
 if(!$u || !password_verify($password,$u['password_hash'])) out(['ok'=>false,'code'=>'INVALID_LOGIN'],401);
 unset($u['password_hash']); session_regenerate_id(true); $_SESSION['user']=$u; audit($pdo,(int)$u['id'],'login','user',(string)$u['id']);
 out(['ok'=>true,'user'=>$u]);
}
if($route==='logout' && $method==='POST'){ $u=me(); if($u) audit($pdo,(int)$u['id'],'logout','user',(string)$u['id']); $_SESSION=[]; session_destroy(); out(['ok'=>true]); }
if($route==='me') out(['ok'=>true,'user'=>auth()]);

if($route==='products'){
 auth();
 $rows=$pdo->query('SELECT p.id,p.sku,p.name,p.category,p.purchase_cost_per_kg,p.active FROM products p ORDER BY p.category,p.name')->fetchAll();
 out(['ok'=>true,'products'=>$rows]);
}
if($route==='franchises'){
 auth();
 $rows=$pdo->query('SELECT id,code,name,district,upazila,status,margin_mode,margin_percent,opened_at FROM franchises ORDER BY id DESC')->fetchAll();
 out(['ok'=>true,'franchises'=>$rows]);
}
if($route==='franchise.create' && $method==='POST'){
 $u=owner();
 $q=$pdo->prepare('INSERT INTO franchises(code,name,district,upazila,status,margin_mode,margin_percent,opened_at) VALUES(?,?,?,?,?,?,?,?)');
 $q->execute([$body['code'],$body['name'],$body['district']??null,$body['upazila']??null,'active',$body['margin_mode']??'tier',(float)($body['margin_percent']??25),$body['opened_at']??date('Y-m-d')]);
 $id=(string)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','franchise',$id,$body); out(['ok'=>true,'id'=>$id],201);
}
if($route==='sale.create' && $method==='POST'){
 $u=auth(); $fid=(int)($body['franchise_id']??0); $gross=(float)($body['gross_amount']??0);
 if($gross<=0||$fid<=0) out(['ok'=>false,'code'=>'INVALID_SALE'],422);
 $q=$pdo->prepare('SELECT margin_percent FROM franchises WHERE id=? AND status<>"closed"');$q->execute([$fid]);$f=$q->fetch();
 if(!$f) out(['ok'=>false,'code'=>'FRANCHISE_NOT_FOUND'],404);
 $pct=(float)$f['margin_percent']; $earned=round($gross*$pct/100,2); $receipt='TSB-'.date('ymdHis').'-'.random_int(100,999);
 $q=$pdo->prepare('INSERT INTO pos_sales(franchise_id,receipt_no,gross_amount,eligible_amount,margin_percent,earned_margin,payment_method,sold_at) VALUES(?,?,?,?,?,?,?,NOW())');
 $q->execute([$fid,$receipt,$gross,$gross,$pct,$earned,$body['payment_method']??'cash']);
 $id=(string)$pdo->lastInsertId(); audit($pdo,(int)$u['id'],'create','pos_sale',$id,['receipt'=>$receipt,'gross'=>$gross,'margin'=>$pct]); out(['ok'=>true,'id'=>$id,'receipt'=>$receipt,'earned_margin'=>$earned],201);
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
