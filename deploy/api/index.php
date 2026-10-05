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

function ops_alert_upsert(PDO $pdo,string $key,?int $fid,string $type,string $severity,string $title,string $message,?string $sourceType=null,?int $sourceId=null,?int $assignee=null): string {
 $q=$pdo->prepare("SELECT id,status FROM operations_alerts WHERE alert_key=? LIMIT 1");$q->execute([$key]);$before=$q->fetch();
 $q=$pdo->prepare("INSERT INTO operations_alerts(alert_key,franchise_id,alert_type,severity,title,message,status,source_type,source_id,assigned_user_id,detected_at,last_seen_at)
   VALUES(?,?,?,?,?,?,'open',?,?,?,NOW(),NOW())
   ON DUPLICATE KEY UPDATE franchise_id=VALUES(franchise_id),alert_type=VALUES(alert_type),severity=VALUES(severity),title=VALUES(title),message=VALUES(message),
   source_type=VALUES(source_type),source_id=VALUES(source_id),assigned_user_id=COALESCE(VALUES(assigned_user_id),assigned_user_id),last_seen_at=NOW(),
   resolved_at=IF(status='resolved',NULL,resolved_at),status=IF(status='resolved','open',status)");
 $q->execute([$key,$fid,$type,$severity,$title,$message,$sourceType,$sourceId,$assignee]);
 if(!$before || $before['status']==='resolved'){
  $sev=$severity==='critical'?'critical':'warning';
  $n=$pdo->prepare("INSERT INTO notifications(role_code,severity,title,message) VALUES('OPERATIONS',?,?,?)");
  $n->execute([$sev,$title,$message]);
 }
 return $key;
}
function ops_refresh_alerts(PDO $pdo): array {
 $keys=[];
 $generatedTypes=['pos_inactive','settlement_overdue','task_overdue','ticket_overdue','compliance','stock_mismatch','training_expired','contract_expiry','stock_risk','complaint_spike'];

 $opsUser=(int)($pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code='OPERATIONS' ORDER BY u.id LIMIT 1")->fetchColumn()?:0);
 $assignee=$opsUser?:null;

 $rows=$pdo->query("SELECT f.id,f.code,f.name,f.status,f.opened_at,MAX(ps.sold_at) last_sale_at
   FROM franchises f LEFT JOIN pos_sales ps ON ps.franchise_id=f.id
   WHERE f.status IN('active','watch','critical')
   GROUP BY f.id,f.code,f.name,f.status,f.opened_at")->fetchAll();
 foreach($rows as $r){
  $last=$r['last_sale_at']?:$r['opened_at'];
  if(!$last)continue;
  $days=max(0,(int)floor((time()-strtotime($last))/86400));
  if($days>=5){
   $sev=$days>=7?'critical':'warning';$key='pos_inactive:'.$r['id'];
   $keys[]=ops_alert_upsert($pdo,$key,(int)$r['id'],'pos_inactive',$sev,'POS inactivity · '.$r['name'],$days.' days without verified POS sale','franchise',(int)$r['id'],$assignee);
  }
 }

 $rows=$pdo->query("SELECT s.id,s.franchise_id,f.name,DATEDIFF(CURDATE(),s.period_end) age_days,s.net_payable,s.status
   FROM settlements s JOIN franchises f ON f.id=s.franchise_id
   WHERE s.status<>'paid' AND DATEDIFF(CURDATE(),s.period_end)>7")->fetchAll();
 foreach($rows as $r){
  $age=(int)$r['age_days'];$sev=$age>15?'critical':'warning';$key='settlement_overdue:'.$r['id'];
  $keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'settlement_overdue',$sev,'Settlement overdue · '.$r['name'],$age.' days old · '.number_format(abs((float)$r['net_payable']),2),'settlement',(int)$r['id'],$assignee);
 }

 $rows=$pdo->query("SELECT t.id,t.franchise_id,COALESCE(f.name,'Network') outlet,t.title,t.priority,t.due_at FROM operations_tasks t LEFT JOIN franchises f ON f.id=t.franchise_id
   WHERE t.status NOT IN('done','cancelled') AND t.due_at<NOW()")->fetchAll();
 foreach($rows as $r){
  $sev=$r['priority']==='critical'?'critical':'warning';$key='task_overdue:'.$r['id'];
  $keys[]=ops_alert_upsert($pdo,$key,$r['franchise_id']?(int)$r['franchise_id']:null,'task_overdue',$sev,'Task overdue · '.$r['outlet'],$r['title'].' · due '.$r['due_at'],'operations_task',(int)$r['id'],$assignee);
 }

 $rows=$pdo->query("SELECT t.id,t.franchise_id,f.name,t.ticket_no,t.subject,t.priority,t.due_at FROM support_tickets t JOIN franchises f ON f.id=t.franchise_id
   WHERE t.status NOT IN('resolved','closed','cancelled') AND t.due_at<NOW()")->fetchAll();
 foreach($rows as $r){
  $sev=$r['priority']==='critical'?'critical':'warning';$key='ticket_overdue:'.$r['id'];
  $keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'ticket_overdue',$sev,'Ticket overdue · '.$r['name'],$r['ticket_no'].' · '.$r['subject'],'support_ticket',(int)$r['id'],$assignee);
 }

 $rows=$pdo->query("SELECT c.id,c.franchise_id,f.name,c.status,c.overall_score,c.corrective_due_at FROM outlet_compliance_checks c JOIN franchises f ON f.id=c.franchise_id
   WHERE c.resolved_at IS NULL AND c.status IN('watch','non_compliant')")->fetchAll();
 foreach($rows as $r){
  $sev=$r['status']==='non_compliant'?'critical':'warning';$key='compliance:'.$r['id'];
  $keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'compliance',$sev,'Compliance action · '.$r['name'],'Score '.number_format((float)$r['overall_score'],1).($r['corrective_due_at']?' · due '.$r['corrective_due_at']:''),'compliance_check',(int)$r['id'],$assignee);
 }

 $rows=$pdo->query("SELECT sc.id,sc.franchise_id,f.name,sc.variance_qty,sc.variance_value FROM outlet_stock_counts sc JOIN franchises f ON f.id=sc.franchise_id
   JOIN (SELECT franchise_id,product_pack_id,MAX(id) max_id FROM outlet_stock_counts GROUP BY franchise_id,product_pack_id) x ON x.max_id=sc.id
   WHERE sc.status='review' AND ABS(sc.variance_qty)>0.001")->fetchAll();
 foreach($rows as $r){
  $sev=abs((float)$r['variance_value'])>=1000?'critical':'warning';$key='stock_mismatch:'.$r['id'];
  $keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'stock_mismatch',$sev,'Stock mismatch · '.$r['name'],'Variance '.number_format((float)$r['variance_qty'],3).' · value '.number_format((float)$r['variance_value'],2),'outlet_stock_count',(int)$r['id'],$assignee);
 }

 $rows=$pdo->query("SELECT tr.id,tr.franchise_id,f.name,tr.course_title,tr.expires_at FROM outlet_training_records tr JOIN franchises f ON f.id=tr.franchise_id
   WHERE tr.status='expired' OR (tr.expires_at IS NOT NULL AND tr.expires_at<CURDATE())")->fetchAll();
 foreach($rows as $r){
  $key='training_expired:'.$r['id'];$keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'training_expired','warning','Training expired · '.$r['name'],$r['course_title'].($r['expires_at']?' · '.$r['expires_at']:''),'outlet_training',(int)$r['id'],$assignee);
 }

 $rows=$pdo->query("SELECT oc.id,oc.franchise_id,f.name,oc.contract_type,oc.expiry_date,oc.reminder_days,oc.renewal_status,DATEDIFF(oc.expiry_date,CURDATE()) days_left
   FROM outlet_contracts oc JOIN franchises f ON f.id=oc.franchise_id
   WHERE oc.expiry_date IS NOT NULL AND oc.renewal_status NOT IN('renewed','not_required')
     AND DATEDIFF(oc.expiry_date,CURDATE())<=oc.reminder_days")->fetchAll();
 foreach($rows as $r){
  $days=(int)$r['days_left'];$sev=$days<0?'critical':($days<=7?'critical':'warning');$key='contract_expiry:'.$r['id'];
  $message=$days<0?(abs($days).' days expired'):($days.' days remaining');
  $keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'contract_expiry',$sev,'Renewal due · '.$r['name'],str_replace('_',' ',$r['contract_type']).' · '.$message,'outlet_contract',(int)$r['id'],$assignee);
 }

 $rows=$pdo->query("SELECT x.franchise_id,f.name,
   SUM(CASE WHEN x.stock_qty<=0 THEN 1 ELSE 0 END) out_count,
   SUM(CASE WHEN x.daily_velocity>0 AND x.stock_qty/x.daily_velocity<7 THEN 1 ELSE 0 END) low_count
   FROM (
    SELECT il.location_id franchise_id,il.product_pack_id,
     SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) stock_qty,
     COALESCE((SELECT SUM(psi.qty)/30 FROM pos_sales ps JOIN pos_sale_items psi ON psi.pos_sale_id=ps.id WHERE ps.franchise_id=il.location_id AND psi.product_pack_id=il.product_pack_id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) daily_velocity
    FROM inventory_ledger il WHERE il.location_type='franchise' AND il.location_id IS NOT NULL GROUP BY il.location_id,il.product_pack_id
   ) x JOIN franchises f ON f.id=x.franchise_id WHERE f.status IN('active','watch','critical') GROUP BY x.franchise_id,f.name HAVING out_count>0 OR low_count>0")->fetchAll();
 foreach($rows as $r){
  $sev=(int)$r['out_count']>0?'critical':'warning';$key='stock_risk:'.$r['franchise_id'];
  $keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'stock_risk',$sev,'Stock risk · '.$r['name'],(int)$r['out_count'].' out-of-stock · '.(int)$r['low_count'].' low-cover SKU lines','franchise',(int)$r['franchise_id'],$assignee);
 }

 $rows=$pdo->query("SELECT t.franchise_id,f.name,COUNT(*) cnt FROM support_tickets t JOIN franchises f ON f.id=t.franchise_id
   WHERE t.category='customer' AND t.opened_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY t.franchise_id,f.name HAVING cnt>=3")->fetchAll();
 foreach($rows as $r){
  $key='complaint_spike:'.$r['franchise_id'];$keys[]=ops_alert_upsert($pdo,$key,(int)$r['franchise_id'],'complaint_spike','critical','Customer complaint spike · '.$r['name'],(int)$r['cnt'].' customer tickets in the last 7 days','franchise',(int)$r['franchise_id'],$assignee);
 }

 $q=$pdo->query("SELECT id,alert_key,alert_type FROM operations_alerts WHERE status IN('open','acknowledged')");
 foreach($q->fetchAll() as $r){
  if(in_array($r['alert_type'],$generatedTypes,true) && !in_array($r['alert_key'],$keys,true)){
   $u=$pdo->prepare("UPDATE operations_alerts SET status='resolved',resolved_at=NOW() WHERE id=?");$u->execute([(int)$r['id']]);
  }
 }
 return ['generated'=>count($keys),'open'=>(int)$pdo->query("SELECT COUNT(*) FROM operations_alerts WHERE status='open'")->fetchColumn(),'critical'=>(int)$pdo->query("SELECT COUNT(*) FROM operations_alerts WHERE status='open' AND severity='critical'")->fetchColumn()];
}

function ops_refresh_alerts_for_franchise(PDO $pdo,int $fid): array {
 outlet_exists($pdo,$fid);
 $keys=[];
 $generatedTypes=['pos_inactive','settlement_overdue','task_overdue','ticket_overdue','compliance','stock_mismatch','training_expired','contract_expiry','stock_risk','complaint_spike'];
 $opsUser=(int)($pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code='OPERATIONS' ORDER BY u.id LIMIT 1")->fetchColumn()?:0);
 $assignee=$opsUser?:null;

 $q=$pdo->prepare("SELECT f.id,f.name,f.status,f.opened_at,MAX(ps.sold_at) last_sale_at FROM franchises f LEFT JOIN pos_sales ps ON ps.franchise_id=f.id WHERE f.id=? AND f.status IN('active','watch','critical') GROUP BY f.id,f.name,f.status,f.opened_at");
 $q->execute([$fid]);if($r=$q->fetch()){
  $last=$r['last_sale_at']?:$r['opened_at'];
  if($last){$days=max(0,(int)floor((time()-strtotime($last))/86400));if($days>=5){$sev=$days>=7?'critical':'warning';$key='pos_inactive:'.$fid;$keys[]=ops_alert_upsert($pdo,$key,$fid,'pos_inactive',$sev,'POS inactivity · '.$r['name'],$days.' days without verified POS sale','franchise',$fid,$assignee);}}
 }

 $q=$pdo->prepare("SELECT s.id,s.franchise_id,f.name,DATEDIFF(CURDATE(),s.period_end) age_days,s.net_payable FROM settlements s JOIN franchises f ON f.id=s.franchise_id WHERE s.franchise_id=? AND s.status<>'paid' AND DATEDIFF(CURDATE(),s.period_end)>7");
 $q->execute([$fid]);foreach($q->fetchAll() as $r){$age=(int)$r['age_days'];$sev=$age>15?'critical':'warning';$key='settlement_overdue:'.$r['id'];$keys[]=ops_alert_upsert($pdo,$key,$fid,'settlement_overdue',$sev,'Settlement overdue · '.$r['name'],$age.' days old · '.number_format(abs((float)$r['net_payable']),2),'settlement',(int)$r['id'],$assignee);}

 $q=$pdo->prepare("SELECT t.id,t.title,t.priority,t.due_at,f.name outlet FROM operations_tasks t JOIN franchises f ON f.id=t.franchise_id WHERE t.franchise_id=? AND t.status NOT IN('done','cancelled') AND t.due_at<NOW()");
 $q->execute([$fid]);foreach($q->fetchAll() as $r){$sev=$r['priority']==='critical'?'critical':'warning';$key='task_overdue:'.$r['id'];$keys[]=ops_alert_upsert($pdo,$key,$fid,'task_overdue',$sev,'Task overdue · '.$r['outlet'],$r['title'].' · due '.$r['due_at'],'operations_task',(int)$r['id'],$assignee);}

 $q=$pdo->prepare("SELECT t.id,t.ticket_no,t.subject,t.priority,f.name FROM support_tickets t JOIN franchises f ON f.id=t.franchise_id WHERE t.franchise_id=? AND t.status NOT IN('resolved','closed','cancelled') AND t.due_at<NOW()");
 $q->execute([$fid]);foreach($q->fetchAll() as $r){$sev=$r['priority']==='critical'?'critical':'warning';$key='ticket_overdue:'.$r['id'];$keys[]=ops_alert_upsert($pdo,$key,$fid,'ticket_overdue',$sev,'Ticket overdue · '.$r['name'],$r['ticket_no'].' · '.$r['subject'],'support_ticket',(int)$r['id'],$assignee);}

 $q=$pdo->prepare("SELECT c.id,c.status,c.overall_score,c.corrective_due_at,f.name FROM outlet_compliance_checks c JOIN franchises f ON f.id=c.franchise_id WHERE c.franchise_id=? AND c.resolved_at IS NULL AND c.status IN('watch','non_compliant')");
 $q->execute([$fid]);foreach($q->fetchAll() as $r){$sev=$r['status']==='non_compliant'?'critical':'warning';$key='compliance:'.$r['id'];$keys[]=ops_alert_upsert($pdo,$key,$fid,'compliance',$sev,'Compliance action · '.$r['name'],'Score '.number_format((float)$r['overall_score'],1).($r['corrective_due_at']?' · due '.$r['corrective_due_at']:''),'compliance_check',(int)$r['id'],$assignee);}

 $q=$pdo->prepare("SELECT sc.id,sc.variance_qty,sc.variance_value,f.name FROM outlet_stock_counts sc JOIN franchises f ON f.id=sc.franchise_id JOIN (SELECT franchise_id,product_pack_id,MAX(id) max_id FROM outlet_stock_counts WHERE franchise_id=? GROUP BY franchise_id,product_pack_id) x ON x.max_id=sc.id WHERE sc.franchise_id=? AND sc.status='review' AND ABS(sc.variance_qty)>0.001");
 $q->execute([$fid,$fid]);foreach($q->fetchAll() as $r){$sev=abs((float)$r['variance_value'])>=1000?'critical':'warning';$key='stock_mismatch:'.$r['id'];$keys[]=ops_alert_upsert($pdo,$key,$fid,'stock_mismatch',$sev,'Stock mismatch · '.$r['name'],'Variance '.number_format((float)$r['variance_qty'],3).' · value '.number_format((float)$r['variance_value'],2),'outlet_stock_count',(int)$r['id'],$assignee);}

 $q=$pdo->prepare("SELECT tr.id,tr.course_title,tr.expires_at,f.name FROM outlet_training_records tr JOIN franchises f ON f.id=tr.franchise_id WHERE tr.franchise_id=? AND (tr.status='expired' OR (tr.expires_at IS NOT NULL AND tr.expires_at<CURDATE()))");
 $q->execute([$fid]);foreach($q->fetchAll() as $r){$key='training_expired:'.$r['id'];$keys[]=ops_alert_upsert($pdo,$key,$fid,'training_expired','warning','Training expired · '.$r['name'],$r['course_title'].($r['expires_at']?' · '.$r['expires_at']:''),'outlet_training',(int)$r['id'],$assignee);}

 $q=$pdo->prepare("SELECT oc.id,f.name,oc.contract_type,oc.expiry_date,oc.reminder_days,oc.renewal_status,DATEDIFF(oc.expiry_date,CURDATE()) days_left
   FROM outlet_contracts oc JOIN franchises f ON f.id=oc.franchise_id
   WHERE oc.franchise_id=? AND oc.expiry_date IS NOT NULL AND oc.renewal_status NOT IN('renewed','not_required')
     AND DATEDIFF(oc.expiry_date,CURDATE())<=oc.reminder_days");
 $q->execute([$fid]);foreach($q->fetchAll() as $r){$days=(int)$r['days_left'];$sev=$days<0?'critical':($days<=7?'critical':'warning');$key='contract_expiry:'.$r['id'];$message=$days<0?(abs($days).' days expired'):($days.' days remaining');$keys[]=ops_alert_upsert($pdo,$key,$fid,'contract_expiry',$sev,'Renewal due · '.$r['name'],str_replace('_',' ',$r['contract_type']).' · '.$message,'outlet_contract',(int)$r['id'],$assignee);}

 $q=$pdo->prepare("SELECT f.name,
   SUM(CASE WHEN x.stock_qty<=0 THEN 1 ELSE 0 END) out_count,
   SUM(CASE WHEN x.daily_velocity>0 AND x.stock_qty/x.daily_velocity<7 THEN 1 ELSE 0 END) low_count
   FROM (
    SELECT il.location_id franchise_id,il.product_pack_id,
     SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) stock_qty,
     COALESCE((SELECT SUM(psi.qty)/30 FROM pos_sales ps JOIN pos_sale_items psi ON psi.pos_sale_id=ps.id WHERE ps.franchise_id=il.location_id AND psi.product_pack_id=il.product_pack_id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) daily_velocity
    FROM inventory_ledger il WHERE il.location_type='franchise' AND il.location_id=? GROUP BY il.location_id,il.product_pack_id
   ) x JOIN franchises f ON f.id=x.franchise_id WHERE f.id=? AND f.status IN('active','watch','critical') GROUP BY f.name HAVING out_count>0 OR low_count>0");
 $q->execute([$fid,$fid]);foreach($q->fetchAll() as $r){$sev=(int)$r['out_count']>0?'critical':'warning';$key='stock_risk:'.$fid;$keys[]=ops_alert_upsert($pdo,$key,$fid,'stock_risk',$sev,'Stock risk · '.$r['name'],(int)$r['out_count'].' out-of-stock · '.(int)$r['low_count'].' low-cover SKU lines','franchise',$fid,$assignee);}

 $q=$pdo->prepare("SELECT f.name,COUNT(*) cnt FROM support_tickets t JOIN franchises f ON f.id=t.franchise_id WHERE t.franchise_id=? AND t.category='customer' AND t.opened_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY f.name HAVING cnt>=3");
 $q->execute([$fid]);foreach($q->fetchAll() as $r){$key='complaint_spike:'.$fid;$keys[]=ops_alert_upsert($pdo,$key,$fid,'complaint_spike','critical','Customer complaint spike · '.$r['name'],(int)$r['cnt'].' customer tickets in the last 7 days','franchise',$fid,$assignee);}

 $q=$pdo->prepare("SELECT id,alert_key,alert_type FROM operations_alerts WHERE franchise_id=? AND status IN('open','acknowledged')");$q->execute([$fid]);
 foreach($q->fetchAll() as $r){if(in_array($r['alert_type'],$generatedTypes,true)&&!in_array($r['alert_key'],$keys,true)){$u=$pdo->prepare("UPDATE operations_alerts SET status='resolved',resolved_at=NOW() WHERE id=?");$u->execute([(int)$r['id']]);}}

 $q=$pdo->prepare("SELECT COUNT(*) FROM operations_alerts WHERE franchise_id=? AND status='open'");$q->execute([$fid]);$open=(int)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(*) FROM operations_alerts WHERE franchise_id=? AND status='open' AND severity='critical'");$q->execute([$fid]);$critical=(int)$q->fetchColumn();
 return ['generated'=>count($keys),'open'=>$open,'critical'=>$critical,'franchise_id'=>$fid];
}


function ops_v2_auto_task(PDO $pdo,array $candidate,int $uid,?int $assignee): array {
 $key=(string)$candidate['key'];$fid=(int)($candidate['franchise_id']??0);
 $q=$pdo->prepare("SELECT id,task_id,resolved_at FROM operations_automation_links WHERE automation_key=? LIMIT 1");$q->execute([$key]);$link=$q->fetch();
 if($link && empty($link['resolved_at'])){
  $u=$pdo->prepare("UPDATE operations_automation_links SET last_seen_at=NOW() WHERE id=?");$u->execute([(int)$link['id']]);
  return ['created'=>0,'task_id'=>(int)($link['task_id']??0),'key'=>$key];
 }
 $priority=ops_priority((string)($candidate['priority']??'medium'));
 $due=ops_sla_due($priority,null);
 $q=$pdo->prepare("INSERT INTO operations_tasks(franchise_id,task_type,title,detail,priority,status,assigned_user_id,due_at,source_type,source_id,created_by) VALUES(?,?,?,?,?,'open',?,?,?,?,?)");
 $q->execute([$fid?:null,$candidate['task_type']??'follow_up',$candidate['title'],$candidate['detail']??null,$priority,$assignee,$due,$candidate['source_type']??null,($candidate['source_id']??null)?:null,$uid]);
 $taskId=(int)$pdo->lastInsertId();
 if($link){
  $q=$pdo->prepare("UPDATE operations_automation_links SET franchise_id=?,rule_code=?,task_id=?,source_type=?,source_id=?,detected_at=NOW(),last_seen_at=NOW(),resolved_at=NULL,created_by=? WHERE id=?");
  $q->execute([$fid?:null,$candidate['rule_code'],$taskId,$candidate['source_type']??null,($candidate['source_id']??null)?:null,$uid,(int)$link['id']]);
 }else{
  $q=$pdo->prepare("INSERT INTO operations_automation_links(automation_key,franchise_id,rule_code,task_id,source_type,source_id,created_by) VALUES(?,?,?,?,?,?,?)");
  $q->execute([$key,$fid?:null,$candidate['rule_code'],$taskId,$candidate['source_type']??null,($candidate['source_id']??null)?:null,$uid]);
 }
 if($fid>0)outlet_timeline($pdo,$fid,$uid,'automation','Auto task created',(string)$candidate['title'],'operations_task',$taskId,['rule_code'=>$candidate['rule_code'],'priority'=>$priority]);
 return ['created'=>1,'task_id'=>$taskId,'key'=>$key];
}

function ops_v2_run_automation(PDO $pdo,?int $scopeFid,int $uid): array {
 if($scopeFid!==null&&$scopeFid>0)outlet_exists($pdo,$scopeFid);
 $opsUser=(int)($pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code='OPERATIONS' ORDER BY u.id LIMIT 1")->fetchColumn()?:0);
 $assignee=$opsUser?:null;$candidates=[];$where=$scopeFid?" AND f.id=".(int)$scopeFid:'';

 $rows=$pdo->query("SELECT f.id franchise_id,f.name,COALESCE(MAX(ps.sold_at),CONCAT(f.opened_at,' 00:00:00')) last_activity
  FROM franchises f LEFT JOIN pos_sales ps ON ps.franchise_id=f.id
  WHERE f.status IN('active','watch','critical')".$where."
  GROUP BY f.id,f.name,f.opened_at HAVING last_activity IS NOT NULL AND DATEDIFF(NOW(),last_activity)>=3")->fetchAll();
 foreach($rows as $r){
  $days=max(3,(int)floor((time()-strtotime((string)$r['last_activity']))/86400));$fid=(int)$r['franchise_id'];
  $candidates[]=['key'=>'v2:pos_inactive:'.$fid,'franchise_id'=>$fid,'rule_code'=>'pos_inactive_3d','task_type'=>'follow_up','title'=>'POS inactivity follow-up | '.$r['name'],'detail'=>$days.' days without verified POS sale','priority'=>$days>=7?'critical':'high','source_type'=>'franchise','source_id'=>$fid];
 }

 $rows=$pdo->query("SELECT s.id,s.franchise_id,f.name,DATEDIFF(CURDATE(),s.period_end) age_days,s.net_payable
  FROM settlements s JOIN franchises f ON f.id=s.franchise_id
  WHERE s.status<>'paid' AND DATEDIFF(CURDATE(),s.period_end)>7".($scopeFid?" AND f.id=".(int)$scopeFid:'')." ORDER BY s.id")->fetchAll();
 foreach($rows as $r){$age=(int)$r['age_days'];$candidates[]=['key'=>'v2:settlement:'.$r['id'],'franchise_id'=>(int)$r['franchise_id'],'rule_code'=>'settlement_overdue','task_type'=>'settlement','title'=>'Settlement collection follow-up | '.$r['name'],'detail'=>$age.' days overdue | '.number_format(abs((float)$r['net_payable']),2),'priority'=>$age>15?'critical':'high','source_type'=>'settlement','source_id'=>(int)$r['id']];}

 $stockSql="SELECT x.franchise_id,x.product_pack_id,f.name outlet,p.name product,pp.grams,pp.mrp,x.stock_qty,COALESCE(s.qty_30d,0) qty_30d
  FROM (SELECT location_id franchise_id,product_pack_id,SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty WHEN movement_type IN('transfer_out','sale','damage') THEN -qty ELSE qty END) stock_qty
        FROM inventory_ledger WHERE location_type='franchise' AND location_id IS NOT NULL GROUP BY location_id,product_pack_id) x
  JOIN franchises f ON f.id=x.franchise_id JOIN product_packs pp ON pp.id=x.product_pack_id JOIN products p ON p.id=pp.product_id
  LEFT JOIN (SELECT ps.franchise_id,psi.product_pack_id,SUM(psi.qty) qty_30d FROM pos_sales ps JOIN pos_sale_items psi ON psi.pos_sale_id=ps.id WHERE ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY ps.franchise_id,psi.product_pack_id) s ON s.franchise_id=x.franchise_id AND s.product_pack_id=x.product_pack_id
  WHERE f.status IN('active','watch','critical')".($scopeFid?" AND f.id=".(int)$scopeFid:'');
 foreach($pdo->query($stockSql)->fetchAll() as $r){
  $stock=max(0,(float)$r['stock_qty']);$daily=(float)$r['qty_30d']/30;$days=$daily>0?$stock/$daily:null;
  if($stock<=0||($days!==null&&$days<7)){
   $fid=(int)$r['franchise_id'];$pid=(int)$r['product_pack_id'];$critical=$stock<=0;
   $candidates[]=['key'=>'v2:stock:'.$fid.':'.$pid,'franchise_id'=>$fid,'rule_code'=>'stock_risk','task_type'=>'stock','title'=>($critical?'Out of stock':'Low stock').' | '.$r['product'].' '.$r['grams'].'g','detail'=>'Outlet '.$r['outlet'].' | stock '.round($stock,3).($days!==null?' | '.round($days,1).' days cover':''),'priority'=>$critical?'critical':'high','source_type'=>'product_pack','source_id'=>$pid];
  }
 }

 $rows=$pdo->query("SELECT tr.id,tr.franchise_id,f.name,tr.course_title,tr.status,tr.expires_at FROM outlet_training_records tr JOIN franchises f ON f.id=tr.franchise_id
  WHERE (tr.status='expired' OR (tr.expires_at IS NOT NULL AND tr.expires_at<CURDATE()))".($scopeFid?" AND f.id=".(int)$scopeFid:'')." ORDER BY tr.id")->fetchAll();
 foreach($rows as $r){$candidates[]=['key'=>'v2:training:'.$r['id'],'franchise_id'=>(int)$r['franchise_id'],'rule_code'=>'training_expired','task_type'=>'training','title'=>'Training renewal | '.$r['name'],'detail'=>$r['course_title'].($r['expires_at']?' | expired '.$r['expires_at']:''),'priority'=>'high','source_type'=>'outlet_training','source_id'=>(int)$r['id']];}

 $rows=$pdo->query("SELECT t.id,t.franchise_id,f.name,t.ticket_no,t.subject,t.priority,t.due_at FROM support_tickets t JOIN franchises f ON f.id=t.franchise_id
  WHERE t.category='customer' AND t.status NOT IN('resolved','closed','cancelled') AND (t.priority IN('high','critical') OR (t.due_at IS NOT NULL AND t.due_at<NOW()))".($scopeFid?" AND f.id=".(int)$scopeFid:'')." ORDER BY t.id")->fetchAll();
 foreach($rows as $r){$candidates[]=['key'=>'v2:complaint:'.$r['id'],'franchise_id'=>(int)$r['franchise_id'],'rule_code'=>'customer_complaint','task_type'=>'support','title'=>'Customer complaint | '.$r['name'],'detail'=>$r['ticket_no'].' | '.$r['subject'],'priority'=>$r['priority']==='critical'?'critical':'high','source_type'=>'support_ticket','source_id'=>(int)$r['id']];}

 $keys=[];$created=0;$ruleCounts=[];
 $pdo->beginTransaction();
 try{
  foreach($candidates as $candidate){
   $res=ops_v2_auto_task($pdo,$candidate,$uid,$assignee);$keys[]=$candidate['key'];$created+=(int)$res['created'];
   $ruleCounts[$candidate['rule_code']]=($ruleCounts[$candidate['rule_code']]??0)+1;
  }
  $scopeSql=$scopeFid?' AND franchise_id='.(int)$scopeFid:'';
  $active=$pdo->query("SELECT id,automation_key FROM operations_automation_links WHERE resolved_at IS NULL".$scopeSql)->fetchAll();$resolved=0;
  foreach($active as $link){if(!in_array($link['automation_key'],$keys,true)){$q=$pdo->prepare("UPDATE operations_automation_links SET resolved_at=NOW(),last_seen_at=NOW() WHERE id=?");$q->execute([(int)$link['id']]);$resolved+=$q->rowCount();}}
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 audit($pdo,$uid,'automation_run','operations_v2_round2',$scopeFid?(string)$scopeFid:null,['scope_franchise_id'=>$scopeFid,'signals'=>count($candidates),'created_tasks'=>$created,'resolved_links'=>$resolved,'rules'=>$ruleCounts]);
 return ['signals'=>count($candidates),'created_tasks'=>$created,'resolved_links'=>$resolved,'rules'=>$ruleCounts];
}

function ops_performance_preview(PDO $pdo,string $period): array {
 if(!preg_match('/^\d{4}-\d{2}$/',$period))out(['ok'=>false,'code'=>'INVALID_PERIOD'],422);
 $start=$period.'-01';$end=date('Y-m-t',strtotime($start));$prevStart=date('Y-m-01',strtotime($start.' -1 month'));$prevEnd=date('Y-m-t',strtotime($prevStart));

 $q=$pdo->prepare("SELECT COALESCE(SUM(gross_amount),0) sales,COALESCE(SUM(earned_margin),0) margin FROM pos_sales WHERE DATE(sold_at) BETWEEN ? AND ?");$q->execute([$start,$end]);$cur=$q->fetch();
 $q->execute([$prevStart,$prevEnd]);$prev=$q->fetch();
 $curSales=(float)$cur['sales'];$prevSales=(float)$prev['sales'];$growth=$prevSales>0?(($curSales-$prevSales)/$prevSales*100):($curSales>0?10:0);
 $salesScore=max(0,min(100,50+($growth*5)));

 $stockLines=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT location_id,product_pack_id,SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty WHEN movement_type IN('transfer_out','sale','damage') THEN -qty ELSE qty END) qty FROM inventory_ledger WHERE location_type='franchise' GROUP BY location_id,product_pack_id HAVING qty>0) z")->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(DISTINCT CONCAT(ps.franchise_id,':',psi.product_pack_id)) FROM pos_sales ps JOIN pos_sale_items psi ON psi.pos_sale_id=ps.id WHERE DATE(ps.sold_at) BETWEEN ? AND ?");$q->execute([$start,$end]);$rotated=(int)$q->fetchColumn();
 $stockScore=$stockLines>0?max(0,min(100,$rotated/$stockLines*100)):0;

 $healthScore=(float)($pdo->query("SELECT COALESCE(AVG(h.total_score),0) FROM outlet_health_checks h JOIN (SELECT franchise_id,MAX(id) id FROM outlet_health_checks GROUP BY franchise_id) x ON x.id=h.id")->fetchColumn()?:0);

 $q=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='paid' OR DATEDIFF(CURDATE(),period_end)<=7 THEN 1 ELSE 0 END) good FROM settlements WHERE period_end BETWEEN ? AND ?");$q->execute([$start,$end]);$settle=$q->fetch();
 $settlementScore=(int)$settle['total']>0?max(0,min(100,(float)$settle['good']/(int)$settle['total']*100)):100;

 $totalOutlets=(int)$pdo->query("SELECT COUNT(*) FROM franchises WHERE created_at<=CONCAT(". $pdo->quote($end) .",' 23:59:59')")->fetchColumn();
 $retained=(int)$pdo->query("SELECT COUNT(*) FROM franchises WHERE created_at<=CONCAT(". $pdo->quote($end) .",' 23:59:59') AND status<>'closed'")->fetchColumn();
 $retentionScore=$totalOutlets>0?max(0,min(100,$retained/$totalOutlets*100)):0;

 $q=$pdo->prepare("SELECT COALESCE(AVG(overall_score),0) FROM outlet_compliance_checks WHERE DATE(checked_at) BETWEEN ? AND ?");$q->execute([$start,$end]);$complianceScore=(float)$q->fetchColumn();
 if($complianceScore<=0)$complianceScore=(float)($pdo->query("SELECT COALESCE(AVG(h.compliance_score),0) FROM outlet_health_checks h JOIN (SELECT franchise_id,MAX(id) id FROM outlet_health_checks GROUP BY franchise_id) x ON x.id=h.id")->fetchColumn()?:0);

 $total=round($salesScore*.30+$stockScore*.20+$healthScore*.15+$settlementScore*.15+$retentionScore*.10+$complianceScore*.10,2);

 $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END),0) FROM finance_ledger WHERE division='FRANCHISE' AND entry_date BETWEEN ? AND ?");$q->execute([$start,$end]);$expenses=max(0,(float)$q->fetchColumn());
 $distributable=max(0,round($curSales-(float)$cur['margin']-$expenses,2));

 $settings=[];foreach($pdo->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN('management_share_active_tier','management_share_manual_percent')")->fetchAll() as $r)$settings[$r['setting_key']]=$r['setting_value'];
 $tier=$settings['management_share_active_tier']??'Base';$pct=['Base'=>15.0,'Growth'=>20.0,'Elite'=>25.0][$tier]??(float)($settings['management_share_manual_percent']??20);
 if($tier==='Manual')$pct=(float)($settings['management_share_manual_percent']??20);
 $share=round($distributable*$pct/100,2);

 return ['period'=>$period,'period_start'=>$start,'period_end'=>$end,'previous_start'=>$prevStart,'previous_end'=>$prevEnd,
  'sales_growth_percent'=>round($growth,2),'sales_growth_score'=>round($salesScore,2),'stock_rotation_score'=>round($stockScore,2),'outlet_health_score'=>round($healthScore,2),
  'settlement_score'=>round($settlementScore,2),'retention_score'=>round($retentionScore,2),'compliance_score'=>round($complianceScore,2),'total_score'=>$total,
  'verified_sales'=>round($curSales,2),'franchise_earned_margin'=>round((float)$cur['margin'],2),'approved_franchise_expenses'=>round($expenses,2),'distributable_profit'=>$distributable,
  'management_tier'=>$tier,'performance_share_percent'=>round($pct,2),'performance_share_amount'=>$share,'company_net_after_share'=>round($distributable-$share,2)];
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





if($route==='operations.alerts.refresh' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $fid=(int)($body['franchise_id']??0);
 $result=$fid>0?ops_refresh_alerts_for_franchise($pdo,$fid):ops_refresh_alerts($pdo);
 out(['ok'=>true]+$result);
}

if($route==='operations.alerts'){
 outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $rows=$pdo->query("SELECT a.id,a.alert_key,a.franchise_id,f.code outlet_code,f.name outlet,a.alert_type,a.severity,a.title,a.message,a.status,a.source_type,a.source_id,a.assigned_user_id,u.name assigned_to,a.detected_at,a.last_seen_at,a.acknowledged_at,a.resolved_at
   FROM operations_alerts a LEFT JOIN franchises f ON f.id=a.franchise_id LEFT JOIN users u ON u.id=a.assigned_user_id
   ORDER BY FIELD(a.status,'open','acknowledged','resolved'),FIELD(a.severity,'critical','warning','info'),a.last_seen_at DESC,a.id DESC LIMIT 500")->fetchAll();
 $summary=['open'=>0,'critical'=>0,'warning'=>0,'acknowledged'=>0,'resolved'=>0];
 foreach($rows as $r){if(isset($summary[$r['status']]))$summary[$r['status']]++;if($r['status']==='open'&&isset($summary[$r['severity']]))$summary[$r['severity']]++;}
 out(['ok'=>true,'summary'=>$summary,'alerts'=>$rows]);
}

if($route==='operations.alert.update' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$id=(int)($body['id']??0);$status=(string)($body['status']??'');
 if($id<=0||!in_array($status,['open','acknowledged','resolved'],true))out(['ok'=>false,'code'=>'INVALID_ALERT_ACTION'],422);
 $q=$pdo->prepare("SELECT * FROM operations_alerts WHERE id=?");$q->execute([$id]);$row=$q->fetch();if(!$row)out(['ok'=>false,'code'=>'ALERT_NOT_FOUND'],404);
 $q=$pdo->prepare("UPDATE operations_alerts SET status=?,acknowledged_at=IF(?='acknowledged',COALESCE(acknowledged_at,NOW()),acknowledged_at),resolved_at=IF(?='resolved',NOW(),IF(?='open',NULL,resolved_at)) WHERE id=?");
 $q->execute([$status,$status,$status,$status,$id]);
 if(!empty($row['franchise_id']))outlet_timeline($pdo,(int)$row['franchise_id'],(int)$u['id'],'alert','Alert '.$status,$row['title'],'operations_alert',$id,['status'=>$status]);
 audit($pdo,(int)$u['id'],'alert_'.$status,'operations_alert',(string)$id,['alert_key'=>$row['alert_key'],'status'=>$status]);
 out(['ok'=>true,'id'=>$id,'status'=>$status]);
}

if($route==='operations.performance.preview'){
 outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $period=(string)($_GET['period']??date('Y-m'));
 out(['ok'=>true,'preview'=>ops_performance_preview($pdo,$period)]);
}

if($route==='operations.performance.generate' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS']);$period=(string)($body['period']??date('Y-m'));$x=ops_performance_preview($pdo,$period);
 $q=$pdo->prepare("SELECT id,status FROM performance_records WHERE role_code='OPERATIONS' AND period_start=? AND period_end=? ORDER BY id DESC LIMIT 1");$q->execute([$x['period_start'],$x['period_end']]);$existing=$q->fetch();
 if($existing&&in_array($existing['status'],['approved','paid'],true))out(['ok'=>false,'code'=>'PERFORMANCE_ALREADY_APPROVED'],422);
 $opsUser=(int)($pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code='OPERATIONS' ORDER BY u.id LIMIT 1")->fetchColumn()?:0);
 if($existing){
  $q=$pdo->prepare("UPDATE performance_records SET user_id=?,sales_growth_score=?,stock_rotation_score=?,outlet_health_score=?,settlement_score=?,retention_score=?,compliance_score=?,total_score=?,distributable_profit=?,performance_share_percent=?,performance_share_amount=?,status='review',approved_by=NULL WHERE id=?");
  $q->execute([$opsUser?:null,$x['sales_growth_score'],$x['stock_rotation_score'],$x['outlet_health_score'],$x['settlement_score'],$x['retention_score'],$x['compliance_score'],$x['total_score'],$x['distributable_profit'],$x['performance_share_percent'],$x['performance_share_amount'],(int)$existing['id']]);$id=(int)$existing['id'];
 }else{
  $q=$pdo->prepare("INSERT INTO performance_records(role_code,user_id,period_start,period_end,sales_growth_score,stock_rotation_score,outlet_health_score,settlement_score,retention_score,compliance_score,total_score,distributable_profit,performance_share_percent,performance_share_amount,status) VALUES('OPERATIONS',?,?,?,?,?,?,?,?,?,?,?,?,?,'review')");
  $q->execute([$opsUser?:null,$x['period_start'],$x['period_end'],$x['sales_growth_score'],$x['stock_rotation_score'],$x['outlet_health_score'],$x['settlement_score'],$x['retention_score'],$x['compliance_score'],$x['total_score'],$x['distributable_profit'],$x['performance_share_percent'],$x['performance_share_amount']]);$id=(int)$pdo->lastInsertId();
 }
 audit($pdo,(int)$u['id'],'generate','operations_performance',(string)$id,$x);
 out(['ok'=>true,'id'=>$id,'status'=>'review','preview'=>$x]);
}

if($route==='operations.performance.approve' && $method==='POST'){
 csrf();$u=owner();$id=(int)($body['id']??0);
 $q=$pdo->prepare("UPDATE performance_records SET status='approved',approved_by=? WHERE id=? AND role_code='OPERATIONS' AND status='review'");$q->execute([(int)$u['id'],$id]);
 if($q->rowCount()!==1)out(['ok'=>false,'code'=>'PERFORMANCE_NOT_APPROVABLE'],422);
 audit($pdo,(int)$u['id'],'approve','operations_performance',(string)$id);
 out(['ok'=>true,'id'=>$id,'status'=>'approved']);
}

if($route==='operations.performance.paid' && $method==='POST'){
 csrf();$u=auth();if(!in_array($u['role'],['OWNER','FINANCE'],true))out(['ok'=>false,'code'=>'ROLE_DENIED'],403);$id=(int)($body['id']??0);
 $q=$pdo->prepare("UPDATE performance_records SET status='paid' WHERE id=? AND role_code='OPERATIONS' AND status='approved'");$q->execute([$id]);
 if($q->rowCount()!==1)out(['ok'=>false,'code'=>'PERFORMANCE_NOT_PAYABLE'],422);
 audit($pdo,(int)$u['id'],'mark_paid','operations_performance',(string)$id);
 out(['ok'=>true,'id'=>$id,'status'=>'paid']);
}

if($route==='operations.performance.history'){
 $u=auth();if(!in_array($u['role'],['OWNER','OPERATIONS','REGIONAL','FINANCE'],true))out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $rows=$pdo->query("SELECT pr.id,pr.period_start,pr.period_end,pr.sales_growth_score,pr.stock_rotation_score,pr.outlet_health_score,pr.settlement_score,pr.retention_score,pr.compliance_score,pr.total_score,pr.distributable_profit,pr.performance_share_percent,pr.performance_share_amount,pr.status,u.name user_name,a.name approved_by_name,pr.created_at
   FROM performance_records pr LEFT JOIN users u ON u.id=pr.user_id LEFT JOIN users a ON a.id=pr.approved_by WHERE pr.role_code='OPERATIONS' ORDER BY pr.period_end DESC,pr.id DESC LIMIT 60")->fetchAll();
 out(['ok'=>true,'records'=>$rows]);
}

if($route==='operations.network.report'){
 outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$period=(string)($_GET['period']??date('Y-m'));if(!preg_match('/^\d{4}-\d{2}$/',$period))out(['ok'=>false,'code'=>'INVALID_PERIOD'],422);
 $start=$period.'-01';$end=date('Y-m-t',strtotime($start));
 $rows=$pdo->prepare("SELECT COALESCE(NULLIF(op.division,''),'Unassigned') division,COALESCE(NULLIF(f.district,''),'Unassigned') district,
   COUNT(*) outlets,SUM(f.status='active') active_outlets,SUM(f.status IN('watch','critical')) attention_outlets,
   COALESCE(SUM(s.sales),0) sales,COALESCE(SUM(t.target),0) target,
   COALESCE(AVG(h.score),0) health_score,
   COALESCE(SUM(a.open_alerts),0) open_alerts,COALESCE(SUM(a.critical_alerts),0) critical_alerts,
   COALESCE(SUM(st.overdue),0) overdue_settlements
   FROM franchises f
   LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
   LEFT JOIN (SELECT franchise_id,SUM(gross_amount) sales FROM pos_sales WHERE DATE(sold_at) BETWEEN ? AND ? GROUP BY franchise_id) s ON s.franchise_id=f.id
   LEFT JOIN (SELECT franchise_id,SUM(sales_target) target FROM outlet_sales_targets WHERE period_start<=? AND period_end>=? GROUP BY franchise_id) t ON t.franchise_id=f.id
   LEFT JOIN (SELECT h1.franchise_id,h1.total_score score FROM outlet_health_checks h1 JOIN (SELECT franchise_id,MAX(id) id FROM outlet_health_checks GROUP BY franchise_id) hx ON hx.id=h1.id) h ON h.franchise_id=f.id
   LEFT JOIN (SELECT franchise_id,SUM(status='open') open_alerts,SUM(status='open' AND severity='critical') critical_alerts FROM operations_alerts GROUP BY franchise_id) a ON a.franchise_id=f.id
   LEFT JOIN (SELECT franchise_id,SUM(status<>'paid' AND DATEDIFF(CURDATE(),period_end)>7) overdue FROM settlements GROUP BY franchise_id) st ON st.franchise_id=f.id
   WHERE f.status<>'closed'
   GROUP BY COALESCE(NULLIF(op.division,''),'Unassigned'),COALESCE(NULLIF(f.district,''),'Unassigned')
   ORDER BY division,district");
 $rows->execute([$start,$end,$end,$start]);$regions=$rows->fetchAll();
 foreach($regions as &$r)$r['target_achievement']=(float)$r['target']>0?round((float)$r['sales']/(float)$r['target']*100,1):0;unset($r);
 $perf=ops_performance_preview($pdo,$period);
 $alerts=$pdo->query("SELECT severity,status,COUNT(*) count FROM operations_alerts GROUP BY severity,status")->fetchAll();
 out(['ok'=>true,'period'=>$period,'period_start'=>$start,'period_end'=>$end,'regions'=>$regions,'performance'=>$perf,'alert_summary'=>$alerts]);
}

if($route==='operations.report.snapshot' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS']);$period=(string)($body['period']??date('Y-m'));if(!preg_match('/^\d{4}-\d{2}$/',$period))out(['ok'=>false,'code'=>'INVALID_PERIOD'],422);
 $start=$period.'-01';$end=date('Y-m-t',strtotime($start));$perf=ops_performance_preview($pdo,$period);
 $payload=['performance'=>$perf,'open_alerts'=>(int)$pdo->query("SELECT COUNT(*) FROM operations_alerts WHERE status='open'")->fetchColumn(),'critical_alerts'=>(int)$pdo->query("SELECT COUNT(*) FROM operations_alerts WHERE status='open' AND severity='critical'")->fetchColumn(),'generated_at'=>date('c')];
 $q=$pdo->prepare("INSERT INTO operations_report_snapshots(period_start,period_end,report_type,payload_json,generated_by) VALUES(?,?,'network_monthly',?,?)");
 $q->execute([$start,$end,json_encode($payload,JSON_UNESCAPED_UNICODE),(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 audit($pdo,(int)$u['id'],'snapshot','operations_report',(string)$id,['period'=>$period]);
 out(['ok'=>true,'id'=>$id,'period'=>$period]);
}

if($route==='operations.security.audit'){
 $u=outlet_ops_user(['OWNER','OPERATIONS']);
 $tables=['outlet_profiles','outlet_pipeline','operations_tasks','support_tickets','outlet_sales_targets','operations_alerts','operations_automation_links','settlement_followups','operations_benchmark_snapshots','operations_notification_routes','operations_closure_handovers','performance_records','audit_logs'];$present=[];
 $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");foreach($tables as $t){$q->execute([$t]);$present[$t]=(int)$q->fetchColumn()===1;}
 $opsUsers=(int)$pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code='OPERATIONS'")->fetchColumn();
 out(['ok'=>true,'role'=>$u['role'],'operations_users'=>$opsUsers,'tables'=>$present,
  'operations_allowed'=>['Dashboard','Tea','Inventory / Warehouse','Outlets / Franchise','Franchise & Retail Operations','POS / Sales','Margin & Settlement','Performance & Incentives','Customers','Logistics','Reports','Notifications'],
  'owner_controlled'=>['Purchase & Suppliers','Blending & Production authority','QC approval','Pricing Engine','Margin override','Finance & Accounts','Users & Roles','Audit administration','Settings','Suspend / Close final approval','Performance approval'],
  'rules'=>['stock_received_is_not_profit'=>true,'verified_pos_sale_earns_margin'=>true,'operations_cannot_override_margin'=>true,'operations_cannot_approve_own_performance'=>true,'operations_cannot_approve_closure_handover'=>true,'owner_final_close_only'=>true]]);
}

if($route==='operations.intelligence'){
 $u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $monthStart=date('Y-m-01');$today=date('Y-m-d');$intelPhase='start';
 try{
  $intelPhase='outlets';

 $outlets=$pdo->query("SELECT f.id,f.code,f.name,f.district,f.upazila,f.status,f.opened_at,
   COALESCE((SELECT oh.total_score FROM outlet_health_checks oh WHERE oh.franchise_id=f.id ORDER BY oh.checked_at DESC,oh.id DESC LIMIT 1),0) health_score,
   COALESCE((SELECT oh.health FROM outlet_health_checks oh WHERE oh.franchise_id=f.id ORDER BY oh.checked_at DESC,oh.id DESC LIMIT 1),'new') health,
   COALESCE((SELECT ost.sales_target FROM outlet_sales_targets ost WHERE ost.franchise_id=f.id AND CURDATE() BETWEEN ost.period_start AND ost.period_end ORDER BY ost.id DESC LIMIT 1),0) sales_target,
   COALESCE((SELECT ost.receipt_target FROM outlet_sales_targets ost WHERE ost.franchise_id=f.id AND CURDATE() BETWEEN ost.period_start AND ost.period_end ORDER BY ost.id DESC LIMIT 1),0) receipt_target
   FROM franchises f WHERE f.status<>'closed' ORDER BY f.name")->fetchAll();
 $outletMap=[];foreach($outlets as $o)$outletMap[(int)$o['id']]=$o;

 $intelPhase='sales';
 $salesRows=$pdo->query("SELECT franchise_id,
   SUM(CASE WHEN sold_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) THEN gross_amount ELSE 0 END) sales_7d,
   SUM(CASE WHEN sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN gross_amount ELSE 0 END) sales_30d,
   SUM(CASE WHEN DATE(sold_at)>=DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN gross_amount ELSE 0 END) sales_mtd,
   SUM(CASE WHEN DATE(sold_at)>=DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN 1 ELSE 0 END) receipts_mtd,
   MAX(sold_at) last_sale_at
   FROM pos_sales GROUP BY franchise_id")->fetchAll();
 $salesMap=[];foreach($salesRows as $r)$salesMap[(int)$r['franchise_id']]=$r;

 $intelPhase='pack_sales';
 $packSalesRows=$pdo->query("SELECT ps.franchise_id,psi.product_pack_id,
   SUM(CASE WHEN ps.sold_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) THEN psi.qty ELSE 0 END) qty_7d,
   SUM(CASE WHEN ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN psi.qty ELSE 0 END) qty_30d,
   MAX(ps.sold_at) last_sale_at
   FROM pos_sales ps JOIN pos_sale_items psi ON psi.pos_sale_id=ps.id GROUP BY ps.franchise_id,psi.product_pack_id")->fetchAll();
 $packSales=[];foreach($packSalesRows as $r)$packSales[(int)$r['franchise_id'].':'.(int)$r['product_pack_id']]=$r;

 $intelPhase='stock';
 $stockRows=$pdo->query("SELECT il.location_id franchise_id,il.product_pack_id,p.name product,pp.grams,pp.mrp,
   SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty
            WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) stock_qty,
   MAX(il.created_at) last_stock_at
   FROM inventory_ledger il JOIN product_packs pp ON pp.id=il.product_pack_id JOIN products p ON p.id=pp.product_id
   WHERE il.location_type='franchise' AND il.location_id IS NOT NULL
   GROUP BY il.location_id,il.product_pack_id,p.name,pp.grams,pp.mrp")->fetchAll();

 $intelPhase='policy';
 $polRows=$pdo->query("SELECT franchise_id,product_pack_id,min_days_cover,target_days_cover,max_days_cover,dead_stock_days FROM outlet_inventory_policies")->fetchAll();
 $pol=[];foreach($polRows as $r)$pol[(int)$r['franchise_id'].':'.(int)$r['product_pack_id']]=$r;

 $intelPhase='stock_counts';
 $countRows=$pdo->query("SELECT sc.* FROM outlet_stock_counts sc
   JOIN (SELECT franchise_id,product_pack_id,MAX(id) max_id FROM outlet_stock_counts GROUP BY franchise_id,product_pack_id) x ON x.max_id=sc.id")->fetchAll();
 $countMap=[];foreach($countRows as $r)$countMap[(int)$r['franchise_id'].':'.(int)$r['product_pack_id']]=$r;

 $inventory=[];$summary=['low'=>0,'overstock'=>0,'dead'=>0,'out_of_stock'=>0,'mismatch'=>0,'reorder_units'=>0,'reorder_value'=>0.0];
 foreach($stockRows as $r){
  $fid=(int)$r['franchise_id'];$pid=(int)$r['product_pack_id'];$key=$fid.':'.$pid;
  $s=$packSales[$key]??['qty_7d'=>0,'qty_30d'=>0,'last_sale_at'=>null];
  $policy=$pol[$key]??($pol[$fid.':0']??['min_days_cover'=>7,'target_days_cover'=>21,'max_days_cover'=>60,'dead_stock_days'=>30]);
  $stock=max(0,(float)$r['stock_qty']);$q7=(float)($s['qty_7d']??0);$q30=(float)($s['qty_30d']??0);$daily=$q30/30;
  $days=$daily>0?round($stock/$daily,1):null;
  $lastSale=$s['last_sale_at']??null;$daysSince=$lastSale?max(0,(int)floor((time()-strtotime($lastSale))/86400)):9999;
  $movement='steady';
  if($q30<=0 && $daysSince>=(int)$policy['dead_stock_days'])$movement='dead';
  elseif($q7/7 > ($q30/30)*1.25 && $q7>0)$movement='fast';
  elseif($q30>0 && $q7/7 < ($q30/30)*0.60)$movement='slow';
  $stockStatus='healthy';
  if($stock<=0)$stockStatus='out_of_stock';
  elseif($movement==='dead')$stockStatus='dead';
  elseif($days!==null && $days<(float)$policy['min_days_cover'])$stockStatus='low';
  elseif($days!==null && $days>(float)$policy['max_days_cover'])$stockStatus='overstock';
  $reorder=$daily>0?max(0,(int)ceil(((float)$policy['target_days_cover']*$daily)-$stock)):0;
  $cnt=$countMap[$key]??null;$mismatch=$cnt && abs((float)$cnt['variance_qty'])>0.001;
  if(isset($summary[$stockStatus]))$summary[$stockStatus]++;if($mismatch)$summary['mismatch']++;$summary['reorder_units']+=$reorder;$summary['reorder_value']+=$reorder*(float)$r['mrp'];
  $inventory[]=[
   'franchise_id'=>$fid,'outlet_code'=>$outletMap[$fid]['code']??'','outlet'=>$outletMap[$fid]['name']??'','product_pack_id'=>$pid,'product'=>$r['product'],'grams'=>(int)$r['grams'],'mrp'=>(float)$r['mrp'],
   'stock_qty'=>round($stock,3),'sales_qty_7d'=>round($q7,3),'sales_qty_30d'=>round($q30,3),'daily_velocity'=>round($daily,3),
   'days_cover'=>$days,'movement_class'=>$movement,'stock_status'=>$stockStatus,'suggested_reorder_qty'=>$reorder,'suggested_reorder_value'=>round($reorder*(float)$r['mrp'],2),
   'last_sale_at'=>$lastSale,'last_stock_at'=>$r['last_stock_at'],'last_counted_at'=>$cnt['counted_at']??null,'variance_qty'=>$cnt?(float)$cnt['variance_qty']:0,'variance_value'=>$cnt?(float)$cnt['variance_value']:0
  ];
 }

 $intelPhase='settlements';
 $settlements=$pdo->query("SELECT s.id,s.franchise_id,f.code outlet_code,f.name outlet,s.period_start,s.period_end,s.verified_sales,s.earned_margin,s.previous_balance,s.net_payable,s.status,
   GREATEST(DATEDIFF(CURDATE(),s.period_end),0) age_days
   FROM settlements s JOIN franchises f ON f.id=s.franchise_id WHERE s.status<>'paid'
   ORDER BY age_days DESC,ABS(s.net_payable) DESC")->fetchAll();
 $aging=['current'=>0.0,'d1_7'=>0.0,'d8_15'=>0.0,'d16_30'=>0.0,'d30_plus'=>0.0,'company_receivable'=>0.0,'franchise_payable'=>0.0];
 foreach($settlements as &$r){
  $age=(int)$r['age_days'];$amt=abs((float)$r['net_payable']);$r['direction']=(float)$r['net_payable']>=0?'company_receivable':'franchise_payable';
  $r['aging_bucket']=$age===0?'current':($age<=7?'1-7':($age<=15?'8-15':($age<=30?'16-30':'30+')));
  if($age===0)$aging['current']+=$amt;elseif($age<=7)$aging['d1_7']+=$amt;elseif($age<=15)$aging['d8_15']+=$amt;elseif($age<=30)$aging['d16_30']+=$amt;else$aging['d30_plus']+=$amt;
  $aging[$r['direction']]+=$amt;
 }unset($r);

 $rank=[];
 foreach($outlets as $o){
  $fid=(int)$o['id'];$s=$salesMap[$fid]??['sales_7d'=>0,'sales_30d'=>0,'sales_mtd'=>0,'receipts_mtd'=>0,'last_sale_at'=>null];
  $target=(float)$o['sales_target'];$achievement=$target>0?round((float)$s['sales_mtd']/$target*100,1):0;
  $last=$s['last_sale_at']??null;$inactive=$last?max(0,(int)floor((time()-strtotime($last))/86400)):($o['opened_at']?max(0,(int)floor((time()-strtotime($o['opened_at']))/86400)):9999);
  $posStatus=$inactive>=7?'critical':($inactive>=3?'watch':'active');
  $outInv=array_values(array_filter($inventory,fn($x)=>(int)$x['franchise_id']===$fid));
  $goodInv=count(array_filter($outInv,fn($x)=>in_array($x['stock_status'],['healthy','low'],true)));$invScore=count($outInv)?round($goodInv/count($outInv)*100,1):0;
  $overdue=count(array_filter($settlements,fn($x)=>(int)$x['franchise_id']===$fid && (int)$x['age_days']>7));
  $settleScore=$overdue===0?100:max(0,100-($overdue*25));
  $healthy=round(min(100,$achievement)*0.40+min(100,(float)$o['health_score'])*0.25+$invScore*0.20+$settleScore*0.15,1);
  $rank[]=['franchise_id'=>$fid,'code'=>$o['code'],'outlet'=>$o['name'],'district'=>$o['district'],'upazila'=>$o['upazila'],'status'=>$o['status'],
   'sales_7d'=>(float)$s['sales_7d'],'sales_30d'=>(float)$s['sales_30d'],'sales_mtd'=>(float)$s['sales_mtd'],'receipts_mtd'=>(int)$s['receipts_mtd'],
   'sales_target'=>$target,'target_achievement'=>$achievement,'last_sale_at'=>$last,'inactive_days'=>$inactive,'pos_status'=>$posStatus,
   'health'=>$o['health'],'health_score'=>(float)$o['health_score'],'inventory_score'=>$invScore,'settlement_score'=>$settleScore,'healthy_business_score'=>$healthy];
 }
 usort($rank,fn($a,$b)=>$b['healthy_business_score']<=>$a['healthy_business_score']);
 $liveRank=array_values(array_filter($rank,fn($r)=>in_array($r['status'],['active','watch','critical'],true)));
 $top=array_slice($liveRank,0,10);$bottom=array_slice(array_reverse($liveRank),0,10);

 $intelPhase='response';
 out(['ok'=>true,'period'=>['month_start'=>$monthStart,'today'=>$today],
  'summary'=>['inventory'=>$summary,'no_sale_3d'=>count(array_filter($liveRank,fn($r)=>$r['inactive_days']>=3)),'no_sale_7d'=>count(array_filter($liveRank,fn($r)=>$r['inactive_days']>=7)),'open_settlements'=>count($settlements)],
  'outlets'=>$liveRank,'inventory'=>$inventory,'settlements'=>$settlements,'aging'=>$aging,'top'=>$top,'bottom'=>$bottom]);
 }catch(Throwable $e){
  error_log('operations.intelligence failed phase='.$intelPhase.' type='.get_class($e).' code='.$e->getCode());
  out(['ok'=>false,'code'=>'OPERATIONS_INTELLIGENCE_FAILED','phase'=>$intelPhase],500);
 }
}

if($route==='operations.target.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS']);
 $fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $start=(string)($body['period_start']??date('Y-m-01'));$end=(string)($body['period_end']??date('Y-m-t'));
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||strtotime($end)<strtotime($start))out(['ok'=>false,'code'=>'INVALID_TARGET_PERIOD'],422);
 $target=max(0,(float)($body['sales_target']??0));$receipts=max(0,(int)($body['receipt_target']??0));
 $phase='begin';
 try{
  $pdo->beginTransaction();
  $phase='lookup';
  $q=$pdo->prepare("SELECT id FROM outlet_sales_targets WHERE franchise_id=? AND period_start=? AND period_end=? LIMIT 1");
  $q->execute([$fid,$start,$end]);$existingId=(int)($q->fetchColumn()?:0);

  $phase=$existingId>0?'update':'insert';
  if($existingId>0){
   $q=$pdo->prepare("UPDATE outlet_sales_targets SET sales_target=?,receipt_target=?,notes=?,approved_by=?,updated_at=NOW() WHERE id=?");
   $q->execute([$target,$receipts,$body['notes']??null,$u['role']==='OWNER'?(int)$u['id']:null,$existingId]);
   $id=$existingId;
  }else{
   $q=$pdo->prepare("INSERT INTO outlet_sales_targets(franchise_id,period_start,period_end,sales_target,receipt_target,notes,created_by,approved_by) VALUES(?,?,?,?,?,?,?,?)");
   $q->execute([$fid,$start,$end,$target,$receipts,$body['notes']??null,(int)$u['id'],$u['role']==='OWNER'?(int)$u['id']:null]);
   $id=(int)$pdo->lastInsertId();
  }

  $phase='timeline';
  outlet_timeline($pdo,$fid,(int)$u['id'],'sales_target','Sales target set',$start.' to '.$end.' | '.number_format($target,2),'sales_target',null,['sales_target'=>$target,'receipt_target'=>$receipts]);

  $phase='audit';
  audit($pdo,(int)$u['id'],'target_save','franchise',(string)$fid,['target_id'=>$id,'period_start'=>$start,'period_end'=>$end,'sales_target'=>$target,'receipt_target'=>$receipts]);

  $pdo->commit();
  out(['ok'=>true,'id'=>$id,'period_start'=>$start,'period_end'=>$end,'sales_target'=>$target,'receipt_target'=>$receipts]);
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  error_log('operations.target.save failed phase='.$phase.' type='.get_class($e).' code='.$e->getCode());
  out(['ok'=>false,'code'=>'OPERATIONS_TARGET_SAVE_FAILED','phase'=>$phase],500);
 }
}

if($route==='operations.inventory_policy.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS']);
 $fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $pid=max(0,(int)($body['product_pack_id']??0));
 $min=max(1,(float)($body['min_days_cover']??7));$target=max($min,(float)($body['target_days_cover']??21));$max=max($target,(float)($body['max_days_cover']??60));$dead=max(7,(int)($body['dead_stock_days']??30));
 if($pid>0){$q=$pdo->prepare("SELECT id FROM product_packs WHERE id=? LIMIT 1");$q->execute([$pid]);if(!$q->fetchColumn())out(['ok'=>false,'code'=>'PACK_NOT_FOUND'],404);}
 $phase='begin';
 try{
  $pdo->beginTransaction();
  $phase='lookup';
  $q=$pdo->prepare("SELECT id FROM outlet_inventory_policies WHERE franchise_id=? AND product_pack_id=? LIMIT 1");$q->execute([$fid,$pid]);$existingId=(int)($q->fetchColumn()?:0);

  $phase=$existingId>0?'update':'insert';
  if($existingId>0){
   $q=$pdo->prepare("UPDATE outlet_inventory_policies SET min_days_cover=?,target_days_cover=?,max_days_cover=?,dead_stock_days=?,updated_by=?,updated_at=NOW() WHERE id=?");
   $q->execute([$min,$target,$max,$dead,(int)$u['id'],$existingId]);$id=$existingId;
  }else{
   $q=$pdo->prepare("INSERT INTO outlet_inventory_policies(franchise_id,product_pack_id,min_days_cover,target_days_cover,max_days_cover,dead_stock_days,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?)");
   $q->execute([$fid,$pid,$min,$target,$max,$dead,(int)$u['id'],(int)$u['id']]);$id=(int)$pdo->lastInsertId();
  }

  $phase='audit';
  audit($pdo,(int)$u['id'],'inventory_policy_save','franchise',(string)$fid,['policy_id'=>$id,'product_pack_id'=>$pid,'min_days_cover'=>$min,'target_days_cover'=>$target,'max_days_cover'=>$max,'dead_stock_days'=>$dead]);

  $pdo->commit();
  out(['ok'=>true,'id'=>$id,'product_pack_id'=>$pid,'min_days_cover'=>$min,'target_days_cover'=>$target,'max_days_cover'=>$max,'dead_stock_days'=>$dead]);
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  error_log('operations.inventory_policy.save failed phase='.$phase.' type='.get_class($e).' code='.$e->getCode());
  out(['ok'=>false,'code'=>'OPERATIONS_INVENTORY_POLICY_SAVE_FAILED','phase'=>$phase],500);
 }
}

if($route==='operations.stock_count.save' && $method==='POST'){
 csrf();$u=auth();if(!in_array($u['role'],['OWNER','OPERATIONS','REGIONAL','WAREHOUSE'],true))out(['ok'=>false,'code'=>'ROLE_DENIED'],403);
 $fid=(int)($body['franchise_id']??0);$pid=(int)($body['product_pack_id']??0);$physical=(float)($body['physical_qty']??0);if($fid<=0||$pid<=0||$physical<0)out(['ok'=>false,'code'=>'INVALID_STOCK_COUNT'],422);outlet_exists($pdo,$fid);
 $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty WHEN movement_type IN('transfer_out','sale','damage') THEN -qty ELSE qty END),0) FROM inventory_ledger WHERE location_type='franchise' AND location_id=? AND product_pack_id=?");$q->execute([$fid,$pid]);$system=(float)$q->fetchColumn();
 $q=$pdo->prepare("SELECT mrp FROM product_packs WHERE id=?");$q->execute([$pid]);$mrp=$q->fetchColumn();if($mrp===false)out(['ok'=>false,'code'=>'PACK_NOT_FOUND'],404);
 $variance=round($physical-$system,3);$value=round($variance*(float)$mrp,2);$status=abs($variance)<=0.001?'matched':'review';
 $q=$pdo->prepare("INSERT INTO outlet_stock_counts(franchise_id,product_pack_id,counted_at,system_qty,physical_qty,variance_qty,variance_value,status,notes,counted_by) VALUES(?,?,NOW(),?,?,?,?,?,?,?)");
 $q->execute([$fid,$pid,$system,$physical,$variance,$value,$status,$body['notes']??null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 outlet_timeline($pdo,$fid,(int)$u['id'],'stock_count','Physical stock count',$status.' · variance '.$variance,'outlet_stock_count',$id,['product_pack_id'=>$pid,'system_qty'=>$system,'physical_qty'=>$physical,'variance_qty'=>$variance]);
 audit($pdo,(int)$u['id'],'stock_count','franchise',(string)$fid,['count_id'=>$id,'product_pack_id'=>$pid,'system_qty'=>$system,'physical_qty'=>$physical,'variance_qty'=>$variance,'status'=>$status]);
 out(['ok'=>true,'id'=>$id,'system_qty'=>$system,'physical_qty'=>$physical,'variance_qty'=>$variance,'variance_value'=>$value,'status'=>$status],201);
}




function ops_v2_round3_payload(PDO $pdo,string $period): array {
 if(!preg_match('/^\d{4}-\d{2}$/',$period))out(['ok'=>false,'code'=>'INVALID_PERIOD'],422);
 $start=$period.'-01';$end=date('Y-m-t',strtotime($start));
 $qs=$pdo->quote($start);$qe=$pdo->quote($end);
 $rows=$pdo->query("SELECT f.id franchise_id,f.code outlet_code,f.name outlet,COALESCE(NULLIF(op.division,''),'Unassigned') division,
   COALESCE(NULLIF(f.district,''),'Unassigned') district,COALESCE(NULLIF(f.upazila,''),'Unassigned') upazila,f.status,f.opened_at,
   COALESCE((SELECT SUM(ps.gross_amount) FROM pos_sales ps WHERE ps.franchise_id=f.id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) sales_30d,
   COALESCE((SELECT SUM(ps.gross_amount) FROM pos_sales ps WHERE ps.franchise_id=f.id AND DATE(ps.sold_at) BETWEEN {$qs} AND {$qe}),0) period_sales,
   (SELECT MAX(ps.sold_at) FROM pos_sales ps WHERE ps.franchise_id=f.id) last_sale_at,
   COALESCE((SELECT ost.sales_target FROM outlet_sales_targets ost WHERE ost.franchise_id=f.id AND ost.period_start<={$qe} AND ost.period_end>={$qs} ORDER BY ost.id DESC LIMIT 1),0) sales_target,
   COALESCE((SELECT h.total_score FROM outlet_health_checks h WHERE h.franchise_id=f.id ORDER BY h.checked_at DESC,h.id DESC LIMIT 1),0) health_score,
   COALESCE((SELECT COUNT(*) FROM (SELECT il.location_id,il.product_pack_id,SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty ELSE il.qty END) qty FROM inventory_ledger il WHERE il.location_type='franchise' GROUP BY il.location_id,il.product_pack_id HAVING qty>0) sx WHERE sx.location_id=f.id),0) stock_lines,
   COALESCE((SELECT COUNT(DISTINCT psi.product_pack_id) FROM pos_sales ps JOIN pos_sale_items psi ON psi.pos_sale_id=ps.id WHERE ps.franchise_id=f.id AND ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) rotated_lines,
   COALESCE((SELECT COUNT(*) FROM settlements s WHERE s.franchise_id=f.id AND s.status<>'paid' AND DATEDIFF(CURDATE(),s.period_end)>7),0) overdue_settlements,
   COALESCE((SELECT COUNT(*) FROM operations_alerts a WHERE a.franchise_id=f.id AND a.status='open'),0) open_alerts,
   COALESCE((SELECT COUNT(*) FROM operations_alerts a WHERE a.franchise_id=f.id AND a.status='open' AND a.severity='critical'),0) critical_alerts,
   COALESCE((SELECT COUNT(*) FROM support_tickets t WHERE t.franchise_id=f.id AND t.category='customer' AND t.status NOT IN('resolved','closed','cancelled')),0) open_complaints,
   COALESCE((SELECT COUNT(*) FROM operations_tasks t WHERE t.franchise_id=f.id AND t.status NOT IN('done','cancelled') AND t.due_at<NOW()),0) overdue_tasks,
   COALESCE((SELECT COUNT(*) FROM outlet_checklist_items ci WHERE ci.franchise_id=f.id AND ci.checklist_type='closure'),0) closure_total,
   COALESCE((SELECT SUM(ci.completed=1) FROM outlet_checklist_items ci WHERE ci.franchise_id=f.id AND ci.checklist_type='closure'),0) closure_done,
   COALESCE((SELECT COUNT(*) FROM documents d WHERE d.reference_type IN('franchise','outlet') AND d.reference_id=f.id AND d.status='active'),0) document_count,
   COALESCE((SELECT SUM(CASE WHEN il.movement_type IN('opening','production_in','transfer_in','return') THEN il.qty*il.unit_value WHEN il.movement_type IN('transfer_out','sale','damage') THEN -il.qty*il.unit_value ELSE il.qty*il.unit_value END) FROM inventory_ledger il WHERE il.location_type='franchise' AND il.location_id=f.id),0) stock_value,
   COALESCE((SELECT COUNT(*) FROM settlements s WHERE s.franchise_id=f.id AND s.status<>'paid'),0) unpaid_settlements,
   ch.id handover_id,ch.handover_status,ch.stock_status handover_stock_status,ch.dues_status handover_dues_status,ch.documents_status handover_documents_status,ch.evidence_ref handover_evidence_ref,ch.note handover_note,ch.prepared_at,ch.approved_at
  FROM franchises f LEFT JOIN outlet_profiles op ON op.franchise_id=f.id LEFT JOIN operations_closure_handovers ch ON ch.franchise_id=f.id
  WHERE f.status<>'closed' ORDER BY f.name")->fetchAll();

 $district=[];foreach($rows as $r){$k=$r['division'].'|'.$r['district'];if(!isset($district[$k]))$district[$k]=['sales'=>0.0,'count'=>0];$district[$k]['sales']+=(float)$r['sales_30d'];$district[$k]['count']++;}
 $bench=[];$forecast=[];
 foreach($rows as $r){
  $fid=(int)$r['franchise_id'];$k=$r['division'].'|'.$r['district'];$avg=$district[$k]['count']?($district[$k]['sales']/$district[$k]['count']):0;
  $sales30=(float)$r['sales_30d'];$salesIndex=$avg>0?min(100,$sales30/$avg*100):($sales30>0?100:0);
  $rotation=(int)$r['stock_lines']>0?min(100,(int)$r['rotated_lines']/(int)$r['stock_lines']*100):0;
  $settle=(int)$r['overdue_settlements']===0?100:max(0,100-((int)$r['overdue_settlements']*25));
  $health=max(0,min(100,(float)$r['health_score']));
  $score=round($salesIndex*.40+$health*.25+$rotation*.20+$settle*.15,1);
  $target=(float)$r['sales_target'];$periodSales=(float)$r['period_sales'];$ach=$target>0?round($periodSales/$target*100,1):0;
  $bench[]=[
   'franchise_id'=>$fid,'outlet_code'=>$r['outlet_code'],'outlet'=>$r['outlet'],'division'=>$r['division'],'district'=>$r['district'],'upazila'=>$r['upazila'],
   'sales_30d'=>round($sales30,2),'district_avg_sales'=>round($avg,2),'sales_index'=>round($salesIndex,1),'health_score'=>round($health,1),
   'stock_rotation_score'=>round($rotation,1),'settlement_score'=>round($settle,1),'benchmark_index'=>$score,'sales_target'=>$target,'period_sales'=>$periodSales,'target_achievement'=>$ach
  ];

  if(in_array($r['status'],['active','watch','critical'],true)){
   $last=$r['last_sale_at']?:($r['opened_at']?($r['opened_at'].' 00:00:00'):null);$inactive=$last?max(0,(int)floor((time()-strtotime($last))/86400)):9999;
   $risk=0;$drivers=[];
   if($inactive>=7){$risk+=30;$drivers[]='POS inactive 7+d';}elseif($inactive>=3){$risk+=15;$drivers[]='POS inactive 3+d';}
   if((int)$r['critical_alerts']>0){$risk+=25;$drivers[]='critical alerts';}elseif((int)$r['open_alerts']>0){$risk+=10;$drivers[]='open alerts';}
   if((int)$r['overdue_settlements']>0){$risk+=20;$drivers[]='overdue settlement';}
   if((int)$r['open_complaints']>=3){$risk+=10;$drivers[]='complaint spike';}elseif((int)$r['open_complaints']>0){$risk+=5;$drivers[]='open complaint';}
   if((int)$r['overdue_tasks']>0){$risk+=10;$drivers[]='overdue tasks';}
   if($health<60){$risk+=15;$drivers[]='low health';}elseif($health<75){$risk+=8;$drivers[]='health watch';}
   $risk=min(100,$risk);$state=$risk>=60?'critical':($risk>=30?'watch':'stable');$risk30=min(100,$risk+($risk>0?10:0));$state30=$risk30>=60?'critical':($risk30>=30?'watch':'stable');
   $forecast[]=['franchise_id'=>$fid,'outlet_code'=>$r['outlet_code'],'outlet'=>$r['outlet'],'district'=>$r['district'],'current_status'=>$r['status'],
    'risk_score_7d'=>$risk,'forecast_7d'=>$state,'risk_score_30d'=>$risk30,'forecast_30d'=>$state30,'inactive_days'=>$inactive,'drivers'=>$drivers?implode(', ',$drivers):'No material risk signal'];
  }
 }
 usort($bench,fn($a,$b)=>$b['benchmark_index']<=>$a['benchmark_index']);foreach($bench as $i=>&$r)$r['network_rank']=$i+1;unset($r);
 usort($forecast,fn($a,$b)=>$b['risk_score_30d']<=>$a['risk_score_30d']);

 $performance=$pdo->query("SELECT pr.id,pr.period_start,pr.period_end,pr.sales_growth_score,pr.stock_rotation_score,pr.outlet_health_score,pr.settlement_score,pr.retention_score,pr.compliance_score,pr.total_score,pr.distributable_profit,pr.performance_share_percent,pr.performance_share_amount,pr.status,u.name user_name,a.name approved_by_name
  FROM performance_records pr LEFT JOIN users u ON u.id=pr.user_id LEFT JOIN users a ON a.id=pr.approved_by WHERE pr.role_code='OPERATIONS' ORDER BY pr.period_end DESC,pr.id DESC LIMIT 12")->fetchAll();

 $approvals=$pdo->query("SELECT a.id,a.approval_type,a.reference_type,a.reference_id,f.code outlet_code,f.name outlet,a.requested_by,u.name requested_by_name,a.assigned_role_code,a.status,a.request_json,a.decision_note,a.decided_by,d.name decided_by_name,a.decided_at,a.created_at
  FROM approvals a LEFT JOIN franchises f ON a.reference_type='franchise' AND f.id=a.reference_id LEFT JOIN users u ON u.id=a.requested_by LEFT JOIN users d ON d.id=a.decided_by
  WHERE a.approval_type LIKE 'operations_%' ORDER BY FIELD(a.status,'pending','approved','rejected','cancelled'),a.id DESC LIMIT 250")->fetchAll();
 foreach($approvals as &$r){$j=json_decode((string)($r['request_json']??''),true);$r['request']=$j?:[];}unset($r);

 $evidence=$pdo->query("SELECT d.id,d.document_type,d.title,d.reference_type,d.reference_id,f.code outlet_code,f.name outlet,d.file_path evidence_ref,d.status,u.name created_by_name,d.created_at
  FROM documents d LEFT JOIN franchises f ON d.reference_type IN('franchise','outlet') AND f.id=d.reference_id LEFT JOIN users u ON u.id=d.created_by
  WHERE d.document_type LIKE 'operations_%' OR d.document_type LIKE 'closure_%' OR d.document_type IN('visit_evidence','settlement_proof','compliance_evidence','marketing_proof','training_certificate')
  ORDER BY d.id DESC LIMIT 300")->fetchAll();

 $routes=$pdo->query("SELECT nr.id,nr.source_type,nr.source_id,nr.franchise_id,f.code outlet_code,f.name outlet,nr.target_role_code,nr.severity,nr.title,nr.message,nr.notification_id,nr.route_status,u.name routed_by_name,nr.routed_at
  FROM operations_notification_routes nr LEFT JOIN franchises f ON f.id=nr.franchise_id LEFT JOIN users u ON u.id=nr.routed_by ORDER BY nr.id DESC LIMIT 250")->fetchAll();

 $closures=[];foreach($rows as $r){
  $total=(int)$r['closure_total'];$done=(int)$r['closure_done'];$progress=$total?round($done/$total*100):0;
  $hstatus=$r['handover_status']?:'not_started';$managementReady=$progress>=100&&abs((float)$r['stock_value'])<0.01&&(int)$r['unpaid_settlements']===0&&$hstatus==='approved';
  $closures[]=['franchise_id'=>(int)$r['franchise_id'],'outlet_code'=>$r['outlet_code'],'outlet'=>$r['outlet'],'district'=>$r['district'],'status'=>$r['status'],
   'closure_progress'=>$progress,'stock_value'=>round((float)$r['stock_value'],2),'unpaid_settlements'=>(int)$r['unpaid_settlements'],'document_count'=>(int)$r['document_count'],
   'handover_id'=>$r['handover_id'],'handover_status'=>$hstatus,'stock_status'=>$r['handover_stock_status']?:'pending','dues_status'=>$r['handover_dues_status']?:'pending',
   'documents_status'=>$r['handover_documents_status']?:'pending','evidence_ref'=>$r['handover_evidence_ref'],'note'=>$r['handover_note'],'prepared_at'=>$r['prepared_at'],'approved_at'=>$r['approved_at'],
   'management_readiness'=>$managementReady?'ready_to_close':($progress>=100?'handover_pending':'checklist_pending')];
 }

 $actions=[];
 foreach($forecast as $r)if($r['forecast_30d']!=='stable')$actions[]=['priority'=>$r['forecast_30d']==='critical'?'critical':'high','action_type'=>'health_forecast','franchise_id'=>$r['franchise_id'],'outlet'=>$r['outlet'],'title'=>'Health forecast | '.$r['forecast_30d'],'detail'=>$r['drivers']];
 foreach($approvals as $r)if($r['status']==='pending')$actions[]=['priority'=>'high','action_type'=>'owner_approval','franchise_id'=>(int)($r['reference_id']??0),'outlet'=>$r['outlet']??'','title'=>'Approval pending | '.str_replace('operations_','',$r['approval_type']),'detail'=>$r['request']['reason']??'Owner decision required'];
 foreach($closures as $r)if($r['handover_status']==='ready')$actions[]=['priority'=>'high','action_type'=>'closure_handover','franchise_id'=>$r['franchise_id'],'outlet'=>$r['outlet'],'title'=>'Closure handover ready','detail'=>'Owner approval pending'];
 usort($actions,fn($a,$b)=>(['critical'=>0,'high'=>1,'medium'=>2,'low'=>3][$a['priority']]??9)<=>(['critical'=>0,'high'=>1,'medium'=>2,'low'=>3][$b['priority']]??9));

 $metrics=['benchmarked_outlets'=>count($bench),'watch_forecast'=>count(array_filter($forecast,fn($r)=>$r['forecast_30d']==='watch')),
  'critical_forecast'=>count(array_filter($forecast,fn($r)=>$r['forecast_30d']==='critical')),'pending_approvals'=>count(array_filter($approvals,fn($r)=>$r['status']==='pending')),
  'evidence_items'=>count($evidence),'ready_handovers'=>count(array_filter($closures,fn($r)=>$r['handover_status']==='ready')),'routed_notifications'=>count($routes)];
 $outlets=array_map(fn($r)=>['id'=>(int)$r['franchise_id'],'code'=>$r['outlet_code'],'name'=>$r['outlet'],'district'=>$r['district'],'status'=>$r['status']],$rows);
 return ['period'=>$period,'period_start'=>$start,'period_end'=>$end,'metrics'=>$metrics,'benchmarks'=>$bench,'forecast'=>$forecast,'performance'=>$performance,'approvals'=>$approvals,'evidence'=>$evidence,'notification_routes'=>$routes,'closures'=>$closures,'actions'=>array_slice($actions,0,150),'outlets'=>$outlets];
}

if($route==='operations.round3'){
 outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $period=(string)($_GET['period']??date('Y-m'));
 out(['ok'=>true]+ops_v2_round3_payload($pdo,$period));
}

if($route==='operations.approval.request' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);$type=(string)($body['approval_type']??'');
 $allowed=['margin_exception','stock_adjustment','outlet_suspension','special_discount','exception_expense','closure_approval'];
 if($fid<=0||!in_array($type,$allowed,true))out(['ok'=>false,'code'=>'INVALID_APPROVAL_REQUEST'],422);$fr=outlet_exists($pdo,$fid);
 $reason=trim((string)($body['reason']??''));if($reason==='')out(['ok'=>false,'code'=>'APPROVAL_REASON_REQUIRED'],422);
 $payload=['reason'=>$reason,'amount'=>max(0,(float)($body['amount']??0)),'requested_value'=>$body['requested_value']??null,'evidence_ref'=>$body['evidence_ref']??null];
 $q=$pdo->prepare("INSERT INTO approvals(approval_type,reference_type,reference_id,requested_by,assigned_role_code,status,request_json) VALUES(?,'franchise',?,?,'OWNER','pending',?)");
 $q->execute(['operations_'.$type,$fid,(int)$u['id'],json_encode($payload,JSON_UNESCAPED_UNICODE)]);$id=(int)$pdo->lastInsertId();
 $title='Operations approval | '.str_replace('_',' ',$type).' | '.$fr['name'];$message=$reason;
 $q=$pdo->prepare("INSERT INTO notifications(role_code,severity,title,message) VALUES('OWNER','warning',?,?)");$q->execute([$title,$message]);$nid=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO operations_notification_routes(source_type,source_id,franchise_id,target_role_code,severity,title,message,notification_id,route_status,routed_by) VALUES('approval',?,?,'OWNER','warning',?,?,?,'sent',?)");
 $q->execute([$id,$fid,$title,$message,$nid,(int)$u['id']]);
 outlet_timeline($pdo,$fid,(int)$u['id'],'approval','Owner approval requested',str_replace('_',' ',$type).' | '.$reason,'approval',$id);
 audit($pdo,(int)$u['id'],'request','operations_approval',(string)$id,['franchise_id'=>$fid,'approval_type'=>$type]);
 out(['ok'=>true,'id'=>$id,'status'=>'pending'],201);
}

if($route==='operations.approval.decide' && $method==='POST'){
 csrf();$u=owner();$id=(int)($body['id']??0);$status=(string)($body['status']??'');if($id<=0||!in_array($status,['approved','rejected'],true))out(['ok'=>false,'code'=>'INVALID_APPROVAL_DECISION'],422);
 $q=$pdo->prepare("SELECT * FROM approvals WHERE id=? AND approval_type LIKE 'operations_%' LIMIT 1");$q->execute([$id]);$a=$q->fetch();if(!$a)out(['ok'=>false,'code'=>'APPROVAL_NOT_FOUND'],404);if($a['status']!=='pending')out(['ok'=>false,'code'=>'APPROVAL_ALREADY_DECIDED'],422);
 $note=trim((string)($body['decision_note']??''));
 $q=$pdo->prepare("UPDATE approvals SET status=?,decision_note=?,decided_by=?,decided_at=NOW() WHERE id=? AND status='pending'");$q->execute([$status,$note?:null,(int)$u['id'],$id]);
 $targetUser=(int)($a['requested_by']??0);$title='Operations approval '.$status;$message=str_replace('operations_','',$a['approval_type']).($note?' | '.$note:'');
 if($targetUser>0){$q=$pdo->prepare("INSERT INTO notifications(user_id,severity,title,message) VALUES(?,?,?,?)");$q->execute([$targetUser,$status==='approved'?'success':'warning',$title,$message]);$nid=(int)$pdo->lastInsertId();}else{$nid=null;}
 $fid=(int)($a['reference_id']??0);if($fid>0){outlet_timeline($pdo,$fid,(int)$u['id'],'approval','Approval '.$status,$message,'approval',$id);}
 audit($pdo,(int)$u['id'],'decision','operations_approval',(string)$id,['status'=>$status,'decision_note'=>$note]);
 out(['ok'=>true,'id'=>$id,'status'=>$status]);
}

if($route==='operations.evidence.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $type=(string)($body['evidence_type']??'other');$allowed=['visit_evidence','settlement_proof','compliance_evidence','closure_handover','marketing_proof','training_certificate','other'];
 if(!in_array($type,$allowed,true))$type='other';$title=trim((string)($body['title']??''));$ref=trim((string)($body['evidence_ref']??''));
 if($title===''||$ref==='')out(['ok'=>false,'code'=>'EVIDENCE_REQUIRED'],422);
 $docType=$type==='other'?'operations_evidence':'operations_'.$type;
 $q=$pdo->prepare("INSERT INTO documents(document_type,title,reference_type,reference_id,file_path,status,created_by) VALUES(?,?,'franchise',?,?,'active',?)");$q->execute([$docType,$title,$fid,$ref,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 outlet_timeline($pdo,$fid,(int)$u['id'],'evidence','Operations evidence registered',$title,'document',$id,['evidence_type'=>$type]);
 audit($pdo,(int)$u['id'],'create','operations_evidence',(string)$id,['franchise_id'=>$fid,'evidence_type'=>$type]);
 out(['ok'=>true,'id'=>$id,'document_type'=>$docType],201);
}

if($route==='operations.notification.route' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$target=(string)($body['target_role_code']??'');$severity=(string)($body['severity']??'info');
 if(!in_array($target,['OWNER','FINANCE','OPERATIONS'],true)||!in_array($severity,['info','success','warning','critical'],true))out(['ok'=>false,'code'=>'INVALID_NOTIFICATION_ROUTE'],422);
 $fid=(int)($body['franchise_id']??0);if($fid>0)outlet_exists($pdo,$fid);$title=trim((string)($body['title']??''));$message=trim((string)($body['message']??''));
 if($title===''||$message==='')out(['ok'=>false,'code'=>'NOTIFICATION_CONTENT_REQUIRED'],422);
 $q=$pdo->prepare("INSERT INTO notifications(role_code,severity,title,message) VALUES(?,?,?,?)");$q->execute([$target,$severity,$title,$message]);$nid=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO operations_notification_routes(source_type,source_id,franchise_id,target_role_code,severity,title,message,notification_id,route_status,routed_by) VALUES(?,?,?,?,?,?,?,?, 'sent',?)");
 $q->execute([$body['source_type']??'operations',($body['source_id']??null)?:null,$fid?:null,$target,$severity,$title,$message,$nid,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 if($fid>0)outlet_timeline($pdo,$fid,(int)$u['id'],'notification','Management notification routed',$target.' | '.$title,'notification',$nid);
 audit($pdo,(int)$u['id'],'route','operations_notification',(string)$id,['target_role'=>$target,'severity'=>$severity,'franchise_id'=>$fid?:null]);
 out(['ok'=>true,'id'=>$id,'notification_id'=>$nid,'target_role_code'=>$target],201);
}

if($route==='operations.closure.handover.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS']);$fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);$fr=outlet_exists($pdo,$fid);
 $handover=(string)($body['handover_status']??'preparing');$stock=(string)($body['stock_status']??'pending');$dues=(string)($body['dues_status']??'pending');$docs=(string)($body['documents_status']??'pending');
 if(!in_array($handover,['preparing','ready','approved','reopened'],true)||!in_array($stock,['pending','counted','reconciled','returned'],true)||!in_array($dues,['pending','review','reconciled'],true)||!in_array($docs,['pending','partial','complete'],true))out(['ok'=>false,'code'=>'INVALID_HANDOVER_STATE'],422);
 if($handover==='approved'&&$u['role']!=='OWNER')out(['ok'=>false,'code'=>'OWNER_APPROVAL_REQUIRED'],403);
 if(in_array($handover,['ready','approved'],true)&&!in_array($stock,['reconciled','returned'],true))out(['ok'=>false,'code'=>'HANDOVER_STOCK_NOT_RECONCILED'],422);
 if(in_array($handover,['ready','approved'],true)&&$dues!=='reconciled')out(['ok'=>false,'code'=>'HANDOVER_DUES_NOT_RECONCILED'],422);
 if(in_array($handover,['ready','approved'],true)&&$docs!=='complete')out(['ok'=>false,'code'=>'HANDOVER_DOCUMENTS_INCOMPLETE'],422);
 $approved=$handover==='approved';$q=$pdo->prepare("INSERT INTO operations_closure_handovers(franchise_id,handover_status,stock_status,dues_status,documents_status,evidence_ref,note,prepared_by,approved_by,prepared_at,approved_at)
  VALUES(?,?,?,?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE handover_status=VALUES(handover_status),stock_status=VALUES(stock_status),dues_status=VALUES(dues_status),documents_status=VALUES(documents_status),evidence_ref=VALUES(evidence_ref),note=VALUES(note),prepared_by=VALUES(prepared_by),approved_by=VALUES(approved_by),prepared_at=NOW(),approved_at=VALUES(approved_at)");
 $q->execute([$fid,$handover,$stock,$dues,$docs,$body['evidence_ref']??null,$body['note']??null,(int)$u['id'],$approved?(int)$u['id']:null,$approved?date('Y-m-d H:i:s'):null]);
 $q=$pdo->prepare("SELECT id FROM operations_closure_handovers WHERE franchise_id=?");$q->execute([$fid]);$id=(int)$q->fetchColumn();
 if($handover==='ready'){
  $title='Closure handover ready | '.$fr['name'];$message='Operations completed stock, dues and documents handover. Owner decision required.';
  $q=$pdo->prepare("INSERT INTO notifications(role_code,severity,title,message) VALUES('OWNER','warning',?,?)");$q->execute([$title,$message]);$nid=(int)$pdo->lastInsertId();
  $q=$pdo->prepare("INSERT INTO operations_notification_routes(source_type,source_id,franchise_id,target_role_code,severity,title,message,notification_id,route_status,routed_by) VALUES('closure_handover',?,?,'OWNER','warning',?,?,?,'sent',?)");$q->execute([$id,$fid,$title,$message,$nid,(int)$u['id']]);
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'closure_handover','Closure handover '.$handover,'Stock '.$stock.' | dues '.$dues.' | documents '.$docs,'closure_handover',$id);
 audit($pdo,(int)$u['id'],'save','operations_closure_handover',(string)$id,['franchise_id'=>$fid,'handover_status'=>$handover]);
 out(['ok'=>true,'id'=>$id,'handover_status'=>$handover]);
}

if($route==='operations.management.snapshot' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS']);$period=(string)($body['period']??date('Y-m'));$payload=ops_v2_round3_payload($pdo,$period);
 $q=$pdo->prepare("INSERT INTO operations_benchmark_snapshots(period_start,period_end,snapshot_type,payload_json,generated_by) VALUES(?,?,'management_closure',?,?)");
 $q->execute([$payload['period_start'],$payload['period_end'],json_encode(['metrics'=>$payload['metrics'],'benchmarks'=>$payload['benchmarks'],'forecast'=>$payload['forecast'],'performance'=>$payload['performance']],JSON_UNESCAPED_UNICODE),(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 audit($pdo,(int)$u['id'],'snapshot','operations_management',(string)$id,['period'=>$period]);
 out(['ok'=>true,'id'=>$id,'period'=>$period],201);
}

if($route==='operations.round2'){
 $u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $today=date('Y-m-d');$monthStart=date('Y-m-01');$monthEnd=date('Y-m-t');$day=(int)date('j');$daysIn=(int)date('t');$daysLeft=max(1,$daysIn-$day+1);

 $targets=$pdo->query("SELECT f.id franchise_id,f.code outlet_code,f.name outlet,f.district,f.upazila,
   COALESCE(t.sales_target,0) sales_target,COALESCE(t.receipt_target,0) receipt_target,
   COALESCE(s.sales_mtd,0) sales_mtd,COALESCE(s.receipts_mtd,0) receipts_mtd
  FROM franchises f
  LEFT JOIN (SELECT franchise_id,MAX(id) id FROM outlet_sales_targets WHERE CURDATE() BETWEEN period_start AND period_end GROUP BY franchise_id) tx ON tx.franchise_id=f.id
  LEFT JOIN outlet_sales_targets t ON t.id=tx.id
  LEFT JOIN (SELECT franchise_id,SUM(gross_amount) sales_mtd,COUNT(*) receipts_mtd FROM pos_sales WHERE DATE(sold_at)>=DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY franchise_id) s ON s.franchise_id=f.id
  WHERE f.status<>'closed' ORDER BY f.name")->fetchAll();
 foreach($targets as &$r){
  $target=(float)$r['sales_target'];$sales=(float)$r['sales_mtd'];$remain=max(0,$target-$sales);
  $r['remaining_target']=round($remain,2);$r['required_daily']=round($remain/$daysLeft,2);$r['achievement']=$target>0?round($sales/$target*100,1):0;
  $expected=$target>0?($day/$daysIn*100):0;$r['pace_status']=$target<=0?'no_target':($r['achievement']+5<$expected?'behind':($r['achievement']>$expected+10?'ahead':'on_pace'));
 }unset($r);

 $stock=$pdo->query("SELECT x.franchise_id,x.product_pack_id,f.code outlet_code,f.name outlet,p.name product,pp.grams,pp.mrp,x.stock_qty,COALESCE(s.qty_30d,0) qty_30d,
   COALESCE(pol.min_days_cover,7) min_days_cover,COALESCE(pol.target_days_cover,21) target_days_cover,COALESCE(pol.max_days_cover,60) max_days_cover
  FROM (SELECT location_id franchise_id,product_pack_id,SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty WHEN movement_type IN('transfer_out','sale','damage') THEN -qty ELSE qty END) stock_qty
        FROM inventory_ledger WHERE location_type='franchise' AND location_id IS NOT NULL GROUP BY location_id,product_pack_id) x
  JOIN franchises f ON f.id=x.franchise_id JOIN product_packs pp ON pp.id=x.product_pack_id JOIN products p ON p.id=pp.product_id
  LEFT JOIN (SELECT ps.franchise_id,psi.product_pack_id,SUM(psi.qty) qty_30d FROM pos_sales ps JOIN pos_sale_items psi ON psi.pos_sale_id=ps.id WHERE ps.sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY ps.franchise_id,psi.product_pack_id) s ON s.franchise_id=x.franchise_id AND s.product_pack_id=x.product_pack_id
  LEFT JOIN outlet_inventory_policies pol ON pol.franchise_id=x.franchise_id AND pol.product_pack_id=x.product_pack_id
  WHERE f.status<>'closed' ORDER BY f.name,p.name,pp.grams")->fetchAll();
 $reorders=[];
 foreach($stock as $r){
  $qty=max(0,(float)$r['stock_qty']);$daily=(float)$r['qty_30d']/30;$days=$daily>0?round($qty/$daily,1):null;
  $status=$qty<=0?'out_of_stock':($days!==null&&$days<(float)$r['min_days_cover']?'low':($days!==null&&$days>(float)$r['max_days_cover']?'overstock':'healthy'));
  $reorder=$daily>0?max(0,(int)ceil(((float)$r['target_days_cover']*$daily)-$qty)):0;
  $r['stock_qty']=round($qty,3);$r['daily_velocity']=round($daily,3);$r['days_cover']=$days;$r['stock_status']=$status;$r['suggested_reorder_qty']=$reorder;$r['suggested_reorder_value']=round($reorder*(float)$r['mrp'],2);
  if(in_array($status,['out_of_stock','low'],true)||$reorder>0)$reorders[]=$r;
 }
 usort($reorders,fn($a,$b)=>($a['stock_status']==='out_of_stock'?-1:1)<=>($b['stock_status']==='out_of_stock'?-1:1));

 $settlements=$pdo->query("SELECT s.id,s.franchise_id,f.code outlet_code,f.name outlet,s.period_start,s.period_end,s.net_payable,s.status,
   GREATEST(DATEDIFF(CURDATE(),s.period_end),0) age_days,
   sf.id followup_id,sf.contact_at last_contact_at,sf.channel last_channel,sf.status followup_status,sf.promised_amount,sf.promised_date,sf.note followup_note,sf.evidence_ref
  FROM settlements s JOIN franchises f ON f.id=s.franchise_id
  LEFT JOIN (SELECT settlement_id,MAX(id) max_id FROM settlement_followups GROUP BY settlement_id) sx ON sx.settlement_id=s.id
  LEFT JOIN settlement_followups sf ON sf.id=sx.max_id
  WHERE s.status<>'paid' ORDER BY age_days DESC,ABS(s.net_payable) DESC")->fetchAll();

 $automation=$pdo->query("SELECT al.id,al.automation_key,al.franchise_id,f.code outlet_code,f.name outlet,al.rule_code,al.task_id,t.title,t.priority,t.status task_status,t.due_at,al.detected_at,al.last_seen_at,al.resolved_at
  FROM operations_automation_links al LEFT JOIN franchises f ON f.id=al.franchise_id LEFT JOIN operations_tasks t ON t.id=al.task_id
  ORDER BY (al.resolved_at IS NULL) DESC,FIELD(t.priority,'critical','high','medium','low'),al.last_seen_at DESC LIMIT 400")->fetchAll();

 $complaints=$pdo->query("SELECT t.id,t.ticket_no,t.franchise_id,f.code outlet_code,f.name outlet,t.subject,t.detail,t.priority,t.status,t.assigned_user_id,u.name assigned_to,t.opened_at,t.due_at,t.first_response_at,t.resolved_at,t.resolution,t.escalation_level,
   CASE WHEN t.status NOT IN('resolved','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at<NOW() THEN 'overdue' WHEN t.status IN('resolved','closed') THEN 'resolved' ELSE 'open' END care_status
  FROM support_tickets t JOIN franchises f ON f.id=t.franchise_id LEFT JOIN users u ON u.id=t.assigned_user_id
  WHERE t.category='customer' AND (t.opened_at>=DATE_SUB(NOW(),INTERVAL 60 DAY) OR t.status NOT IN('resolved','closed','cancelled'))
  ORDER BY FIELD(care_status,'overdue','open','resolved'),FIELD(t.priority,'critical','high','medium','low'),t.opened_at DESC LIMIT 300")->fetchAll();

 $training=$pdo->query("SELECT f.id franchise_id,f.code outlet_code,f.name outlet,
   COALESCE(st.staff_count,0) staff_count,COALESCE(tr.total_records,0) training_records,COALESCE(tr.completed_records,0) completed_records,
   COALESCE(tr.attention_records,0) attention_records,COALESCE(tr.expiring_30d,0) expiring_30d,
   CASE WHEN COALESCE(st.staff_count,0)=0 THEN 0 ELSE ROUND(LEAST(100,COALESCE(tr.completed_records,0)/st.staff_count*100),1) END compliance_percent
  FROM franchises f
  LEFT JOIN (SELECT franchise_id,COUNT(*) staff_count FROM outlet_staff WHERE active=1 GROUP BY franchise_id) st ON st.franchise_id=f.id
  LEFT JOIN (SELECT franchise_id,COUNT(*) total_records,SUM(status='completed' AND (expires_at IS NULL OR expires_at>=CURDATE())) completed_records,
     SUM(status='expired' OR (expires_at IS NOT NULL AND expires_at<CURDATE())) attention_records,
     SUM(expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)) expiring_30d
    FROM outlet_training_records GROUP BY franchise_id) tr ON tr.franchise_id=f.id
  WHERE f.status<>'closed' ORDER BY attention_records DESC,compliance_percent,f.name")->fetchAll();

 $trainingAttention=$pdo->query("SELECT tr.id,tr.franchise_id,f.code outlet_code,f.name outlet,tr.course_title,tr.status,tr.scheduled_at,tr.completed_at,tr.expires_at,tr.trainer,tr.certificate_ref,
   CASE WHEN tr.status='expired' OR (tr.expires_at IS NOT NULL AND tr.expires_at<CURDATE()) THEN 'expired' WHEN tr.expires_at IS NOT NULL AND tr.expires_at<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 'expiring' WHEN tr.status IN('pending','scheduled') THEN 'pending' ELSE 'ok' END attention_state
  FROM outlet_training_records tr JOIN franchises f ON f.id=tr.franchise_id
  WHERE tr.status IN('pending','scheduled','expired') OR (tr.expires_at IS NOT NULL AND tr.expires_at<=DATE_ADD(CURDATE(),INTERVAL 30 DAY))
  ORDER BY FIELD(attention_state,'expired','expiring','pending','ok'),tr.expires_at,tr.id DESC LIMIT 300")->fetchAll();

 $risk=$pdo->query("SELECT a.id,a.franchise_id,f.code outlet_code,f.name outlet,a.alert_type risk_type,a.severity,a.title,a.message,a.status,a.last_seen_at
  FROM operations_alerts a LEFT JOIN franchises f ON f.id=a.franchise_id WHERE a.status IN('open','acknowledged')
  ORDER BY FIELD(a.severity,'critical','warning','info'),a.last_seen_at DESC LIMIT 400")->fetchAll();

 $assignees=$pdo->query("SELECT u.id,u.name,r.code role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code IN('OWNER','OPERATIONS','REGIONAL') ORDER BY FIELD(r.code,'OPERATIONS','REGIONAL','OWNER'),u.name")->fetchAll();
 $outlets=$pdo->query("SELECT id,code,name,district,upazila,status FROM franchises WHERE status<>'closed' ORDER BY name")->fetchAll();

 $metrics=[
  'behind_target'=>count(array_filter($targets,fn($r)=>$r['pace_status']==='behind')),
  'reorder_lines'=>count($reorders),
  'overdue_settlements'=>count(array_filter($settlements,fn($r)=>(int)$r['age_days']>7)),
  'active_automation'=>count(array_filter($automation,fn($r)=>empty($r['resolved_at']))),
  'open_complaints'=>count(array_filter($complaints,fn($r)=>!in_array($r['status'],['resolved','closed','cancelled'],true))),
  'training_attention'=>array_sum(array_map(fn($r)=>(int)$r['attention_records']+(int)$r['expiring_30d'],$training)),
 ];
 out(['ok'=>true,'period'=>['today'=>$today,'month_start'=>$monthStart,'month_end'=>$monthEnd,'days_left'=>$daysLeft],
  'metrics'=>$metrics,'targets'=>$targets,'reorders'=>$reorders,'settlements'=>$settlements,'automation'=>$automation,'risk'=>$risk,
  'complaints'=>$complaints,'training'=>$training,'training_attention'=>$trainingAttention,'assignees'=>$assignees,'outlets'=>$outlets]);
}

if($route==='operations.automation.run' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$fid=(int)($body['franchise_id']??0);
 try{
  $alertRefresh=$fid>0?ops_refresh_alerts_for_franchise($pdo,$fid):ops_refresh_alerts($pdo);
  $result=ops_v2_run_automation($pdo,$fid>0?$fid:null,(int)$u['id']);
  out(['ok'=>true,'alert_refresh'=>$alertRefresh]+$result);
 }catch(Throwable $e){error_log('operations.automation.run failed type='.get_class($e).' code='.$e->getCode());out(['ok'=>false,'code'=>'OPERATIONS_AUTOMATION_FAILED'],500);}
}

if($route==='operations.settlement.followup.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $sid=(int)($body['settlement_id']??0);$fid=(int)($body['franchise_id']??0);if($sid<=0||$fid<=0)out(['ok'=>false,'code'=>'INVALID_SETTLEMENT_FOLLOWUP'],422);
 $q=$pdo->prepare("SELECT s.id,s.franchise_id,s.status,f.name FROM settlements s JOIN franchises f ON f.id=s.franchise_id WHERE s.id=? AND s.franchise_id=? LIMIT 1");$q->execute([$sid,$fid]);$settlement=$q->fetch();
 if(!$settlement)out(['ok'=>false,'code'=>'SETTLEMENT_NOT_FOUND'],404);
 $status=(string)($body['status']??'contacted');if(!in_array($status,['contacted','promised','partial','paid_confirmed','disputed','escalated'],true))out(['ok'=>false,'code'=>'INVALID_FOLLOWUP_STATUS'],422);
 $channel=(string)($body['channel']??'call');if(!in_array($channel,['call','whatsapp','email','meeting','visit','internal_note','other'],true))$channel='other';
 $promised=max(0,(float)($body['promised_amount']??0));$date=trim((string)($body['promised_date']??''));$date=$date!==''?$date:null;
 if($date!==null&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))out(['ok'=>false,'code'=>'INVALID_PROMISED_DATE'],422);
 $q=$pdo->prepare("INSERT INTO settlement_followups(settlement_id,franchise_id,channel,status,promised_amount,promised_date,note,evidence_ref,created_by) VALUES(?,?,?,?,?,?,?,?,?)");
 $q->execute([$sid,$fid,$channel,$status,$promised,$date,$body['note']??null,$body['evidence_ref']??null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 outlet_timeline($pdo,$fid,(int)$u['id'],'settlement_followup','Settlement follow-up',$status.' | '.($date?:'no promise date'),'settlement_followup',$id,['settlement_id'=>$sid,'promised_amount'=>$promised,'promised_date'=>$date]);
 audit($pdo,(int)$u['id'],'followup','settlement',(string)$sid,['followup_id'=>$id,'status'=>$status,'channel'=>$channel,'promised_amount'=>$promised,'promised_date'=>$date]);
 out(['ok'=>true,'id'=>$id,'status'=>$status,'promised_amount'=>$promised,'promised_date'=>$date],201);
}

if($route==='operations.round1'){
 $u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);

 $regional=$pdo->query("SELECT
   COALESCE(NULLIF(op.division,''),'Unassigned') division,
   COALESCE(NULLIF(f.district,''),'Unassigned') district,
   COALESCE(NULLIF(f.upazila,''),'Unassigned') upazila,
   COUNT(*) total_outlets,
   SUM(f.status='active') active_outlets,
   SUM(f.status IN('pipeline','setup')) pipeline_outlets,
   SUM(f.status IN('watch','critical')) attention_outlets,
   COALESCE(SUM(s.sales_30d),0) sales_30d,
   COALESCE(SUM(a.open_alerts),0) open_alerts,
   COALESCE(SUM(t.overdue_tasks),0) overdue_tasks
  FROM franchises f
  LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
  LEFT JOIN (SELECT franchise_id,SUM(gross_amount) sales_30d FROM pos_sales WHERE sold_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY franchise_id) s ON s.franchise_id=f.id
  LEFT JOIN (SELECT franchise_id,SUM(status='open') open_alerts FROM operations_alerts GROUP BY franchise_id) a ON a.franchise_id=f.id
  LEFT JOIN (SELECT franchise_id,SUM(status NOT IN('done','cancelled') AND due_at IS NOT NULL AND due_at<NOW()) overdue_tasks FROM operations_tasks GROUP BY franchise_id) t ON t.franchise_id=f.id
  WHERE f.status<>'closed'
  GROUP BY COALESCE(NULLIF(op.division,''),'Unassigned'),COALESCE(NULLIF(f.district,''),'Unassigned'),COALESCE(NULLIF(f.upazila,''),'Unassigned')
  ORDER BY division,district,upazila")->fetchAll();

 $checkins=$pdo->query("SELECT f.id franchise_id,f.code outlet_code,f.name outlet,
   COALESCE(NULLIF(op.division,''),'Unassigned') division,f.district,f.upazila,f.status,
   dc.id checkin_id,dc.checkin_date,COALESCE(dc.opening_status,'pending') opening_status,dc.opened_at,
   COALESCE(dc.closing_status,'pending') closing_status,dc.closed_at,dc.opening_photo_ref,dc.closing_photo_ref,dc.manager_note,
   CASE WHEN dc.id IS NULL THEN 'missing' WHEN dc.opening_status IN('late','exception','closed_for_day') THEN 'attention' ELSE 'recorded' END checkin_health
  FROM franchises f LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
  LEFT JOIN outlet_daily_checkins dc ON dc.franchise_id=f.id AND dc.checkin_date=CURDATE()
  WHERE f.status IN('active','watch','critical','setup')
  ORDER BY FIELD(checkin_health,'missing','attention','recorded'),division,f.district,f.name")->fetchAll();

 $visits=$pdo->query("SELECT v.id,v.franchise_id,f.code outlet_code,f.name outlet,
   COALESCE(NULLIF(op.division,''),'Unassigned') division,f.district,f.upazila,v.visit_type,v.status,v.scheduled_at,
   v.visitor_user_id,u.name visitor,v.overall_score,v.findings,v.corrective_action,v.evidence_ref,v.next_visit_at,
   CASE WHEN v.status='scheduled' AND v.scheduled_at<NOW() THEN 'overdue'
        WHEN v.status='scheduled' AND v.scheduled_at<=DATE_ADD(NOW(),INTERVAL 7 DAY) THEN 'next_7d'
        ELSE 'planned' END planner_status
  FROM field_visits v JOIN franchises f ON f.id=v.franchise_id
  LEFT JOIN outlet_profiles op ON op.franchise_id=f.id LEFT JOIN users u ON u.id=v.visitor_user_id
  WHERE v.status IN('scheduled','follow_up') OR v.visited_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
  ORDER BY FIELD(planner_status,'overdue','next_7d','planned'),v.scheduled_at,v.id DESC LIMIT 300")->fetchAll();

 $contracts=$pdo->query("SELECT oc.id,oc.franchise_id,f.code outlet_code,f.name outlet,
   COALESCE(NULLIF(op.division,''),'Unassigned') division,f.district,f.upazila,
   oc.contract_type,oc.document_no,oc.start_date,oc.expiry_date,oc.renewal_status,oc.reminder_days,
   oc.evidence_ref,oc.owner_note,oc.updated_at,
   CASE WHEN oc.expiry_date IS NULL THEN NULL ELSE DATEDIFF(oc.expiry_date,CURDATE()) END days_to_expiry,
   CASE WHEN oc.expiry_date IS NULL OR oc.renewal_status='not_required' THEN 'no_expiry'
        WHEN oc.expiry_date<CURDATE() AND oc.renewal_status<>'renewed' THEN 'expired'
        WHEN DATEDIFF(oc.expiry_date,CURDATE())<=7 AND oc.renewal_status<>'renewed' THEN 'critical'
        WHEN DATEDIFF(oc.expiry_date,CURDATE())<=oc.reminder_days AND oc.renewal_status<>'renewed' THEN 'due'
        ELSE 'healthy' END renewal_health
  FROM outlet_contracts oc JOIN franchises f ON f.id=oc.franchise_id
  LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
  ORDER BY FIELD(renewal_health,'expired','critical','due','healthy','no_expiry'),oc.expiry_date,oc.id DESC LIMIT 400")->fetchAll();

 $launches=$pdo->query("SELECT f.id franchise_id,f.code outlet_code,f.name outlet,
   COALESCE(NULLIF(op.division,''),'Unassigned') division,f.district,f.upazila,f.status,
   pl.stage,pl.target_open_date,pl.next_action,pl.blocking_reason,
   COALESCE(ch.total_items,0) opening_items,COALESCE(ch.done_items,0) opening_done,
   COALESCE(st.staff_count,0) staff_count,COALESCE(tr.training_complete,0) training_complete,
   COALESCE(doc.document_count,0) document_count,COALESCE(inv.stock_value,0) stock_value
  FROM franchises f
  LEFT JOIN outlet_profiles op ON op.franchise_id=f.id
  LEFT JOIN outlet_pipeline pl ON pl.franchise_id=f.id
  LEFT JOIN (SELECT franchise_id,COUNT(*) total_items,SUM(completed=1) done_items FROM outlet_checklist_items WHERE checklist_type='opening' GROUP BY franchise_id) ch ON ch.franchise_id=f.id
  LEFT JOIN (SELECT franchise_id,COUNT(*) staff_count FROM outlet_staff WHERE active=1 GROUP BY franchise_id) st ON st.franchise_id=f.id
  LEFT JOIN (SELECT franchise_id,SUM(status='completed') training_complete FROM outlet_training_records GROUP BY franchise_id) tr ON tr.franchise_id=f.id
  LEFT JOIN (SELECT reference_id franchise_id,COUNT(*) document_count FROM documents WHERE reference_type IN('franchise','outlet') GROUP BY reference_id) doc ON doc.franchise_id=f.id
  LEFT JOIN (SELECT location_id franchise_id,SUM(CASE WHEN movement_type IN('opening','production_in','transfer_in','return') THEN qty*unit_value WHEN movement_type IN('transfer_out','sale','damage') THEN -qty*unit_value ELSE qty*unit_value END) stock_value FROM inventory_ledger WHERE location_type='franchise' GROUP BY location_id) inv ON inv.franchise_id=f.id
  WHERE f.status IN('pipeline','setup') OR pl.stage IN('lead','verification','agreement','shop_ready','training','stock_ready','pos_ready','launch')
  ORDER BY (pl.target_open_date IS NULL),pl.target_open_date,f.name")->fetchAll();

 foreach($launches as &$r){
  $total=max(1,(int)$r['opening_items']);$check=round(((int)$r['opening_done']/$total)*70,1);
  $staff=(int)$r['staff_count']>0?10:0;$training=(int)$r['training_complete']>0?10:0;$docs=(int)$r['document_count']>0?5:0;$stock=(float)$r['stock_value']>0?5:0;
  $r['opening_progress']=round(((int)$r['opening_done']/$total)*100,1);
  $r['readiness_score']=round($check+$staff+$training+$docs+$stock,1);
  $blockers=[];
  if((int)$r['opening_done']<$total)$blockers[]='opening checklist';
  if((int)$r['staff_count']<=0)$blockers[]='staff';
  if((int)$r['training_complete']<=0)$blockers[]='training';
  if((int)$r['document_count']<=0)$blockers[]='documents';
  if((float)$r['stock_value']<=0)$blockers[]='opening stock';
  if(trim((string)($r['blocking_reason']??''))!=='')$blockers[]=(string)$r['blocking_reason'];
  $r['readiness_state']=$r['readiness_score']>=100?'launch_ready':($r['readiness_score']>=70?'near_ready':'blocked');
  $r['blockers']=implode(' · ',array_values(array_unique($blockers)));
 } unset($r);

 $metrics=[
  'regional_units'=>count($regional),
  'checkins_today'=>count(array_filter($checkins,fn($r)=>!empty($r['checkin_id']))),
  'missing_checkins'=>count(array_filter($checkins,fn($r)=>empty($r['checkin_id']))),
  'visits_next_7d'=>count(array_filter($visits,fn($r)=>$r['planner_status']==='next_7d')),
  'overdue_visits'=>count(array_filter($visits,fn($r)=>$r['planner_status']==='overdue')),
  'renewals_due'=>count(array_filter($contracts,fn($r)=>in_array($r['renewal_health'],['expired','critical','due'],true))),
  'launch_outlets'=>count($launches),
  'launch_ready'=>count(array_filter($launches,fn($r)=>$r['readiness_state']==='launch_ready')),
 ];

 $assignees=$pdo->query("SELECT u.id,u.name,u.email,r.code role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 AND r.code IN('OWNER','OPERATIONS','REGIONAL') ORDER BY FIELD(r.code,'OPERATIONS','REGIONAL','OWNER'),u.name")->fetchAll();
 $outlets=$pdo->query("SELECT id,code,name,district,upazila,status FROM franchises WHERE status<>'closed' ORDER BY name")->fetchAll();

 out(['ok'=>true,'metrics'=>$metrics,'regional'=>$regional,'checkins'=>$checkins,'visits'=>$visits,'contracts'=>$contracts,'launches'=>$launches,'assignees'=>$assignees,'outlets'=>$outlets]);
}

if($route==='operations.checkin.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);$fr=outlet_exists($pdo,$fid);
 if($fr['status']==='closed')out(['ok'=>false,'code'=>'OUTLET_CLOSED'],422);
 $date=(string)($body['checkin_date']??date('Y-m-d'));
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))out(['ok'=>false,'code'=>'INVALID_CHECKIN_DATE'],422);
 $open=(string)($body['opening_status']??'pending');$close=(string)($body['closing_status']??'pending');
 if(!in_array($open,['pending','on_time','late','closed_for_day','exception'],true)||!in_array($close,['pending','on_time','late','exception'],true))out(['ok'=>false,'code'=>'INVALID_CHECKIN_STATUS'],422);
 $opened=($body['opened_at']??null)?:null;$closed=($body['closed_at']??null)?:null;
 $q=$pdo->prepare("SELECT id FROM outlet_daily_checkins WHERE franchise_id=? AND checkin_date=? LIMIT 1");$q->execute([$fid,$date]);$id=(int)($q->fetchColumn()?:0);
 if($id>0){
  $q=$pdo->prepare("UPDATE outlet_daily_checkins SET opening_status=?,opened_at=?,closing_status=?,closed_at=?,opening_photo_ref=?,closing_photo_ref=?,manager_note=?,updated_by=? WHERE id=?");
  $q->execute([$open,$opened,$close,$closed,$body['opening_photo_ref']??null,$body['closing_photo_ref']??null,$body['manager_note']??null,(int)$u['id'],$id]);
 }else{
  $q=$pdo->prepare("INSERT INTO outlet_daily_checkins(franchise_id,checkin_date,opening_status,opened_at,closing_status,closed_at,opening_photo_ref,closing_photo_ref,manager_note,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
  $q->execute([$fid,$date,$open,$opened,$close,$closed,$body['opening_photo_ref']??null,$body['closing_photo_ref']??null,$body['manager_note']??null,(int)$u['id'],(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'daily_checkin','Daily outlet check-in',$date.' | opening '.$open.' | closing '.$close,'outlet_daily_checkin',$id,['opening_status'=>$open,'closing_status'=>$close]);
 audit($pdo,(int)$u['id'],'daily_checkin_save','franchise',(string)$fid,['checkin_id'=>$id,'checkin_date'=>$date,'opening_status'=>$open,'closing_status'=>$close]);
 out(['ok'=>true,'id'=>$id,'checkin_date'=>$date,'opening_status'=>$open,'closing_status'=>$close]);
}

if($route==='operations.contract.save' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);
 $fid=(int)($body['franchise_id']??0);if($fid<=0)out(['ok'=>false,'code'=>'INVALID_FRANCHISE'],422);outlet_exists($pdo,$fid);
 $type=(string)($body['contract_type']??'other');$types=['franchise_agreement','lease','trade_license','food_license','fire_safety','tax_vat','other'];
 if(!in_array($type,$types,true))out(['ok'=>false,'code'=>'INVALID_CONTRACT_TYPE'],422);
 $start=($body['start_date']??null)?:null;$expiry=($body['expiry_date']??null)?:null;$reminder=max(1,min(365,(int)($body['reminder_days']??30)));
 $requested=(string)($body['renewal_status']??'active');$allowed=['active','due','renewing','renewed','expired','not_required'];
 if(!in_array($requested,$allowed,true))out(['ok'=>false,'code'=>'INVALID_RENEWAL_STATUS'],422);
 $status=$requested;
 if($expiry && !in_array($requested,['renewing','renewed','not_required'],true)){
  $days=(int)floor((strtotime($expiry)-strtotime(date('Y-m-d')))/86400);
  $status=$days<0?'expired':($days<=$reminder?'due':'active');
 }
 $id=(int)($body['id']??0);
 if($id>0){
  $q=$pdo->prepare("UPDATE outlet_contracts SET contract_type=?,document_no=?,start_date=?,expiry_date=?,renewal_status=?,reminder_days=?,evidence_ref=?,owner_note=?,updated_by=? WHERE id=? AND franchise_id=?");
  $q->execute([$type,$body['document_no']??null,$start,$expiry,$status,$reminder,$body['evidence_ref']??null,$body['owner_note']??null,(int)$u['id'],$id,$fid]);
  if($q->rowCount()===0){$q=$pdo->prepare("SELECT id FROM outlet_contracts WHERE id=? AND franchise_id=?");$q->execute([$id,$fid]);if(!$q->fetchColumn())out(['ok'=>false,'code'=>'CONTRACT_NOT_FOUND'],404);}
 }else{
  $q=$pdo->prepare("INSERT INTO outlet_contracts(franchise_id,contract_type,document_no,start_date,expiry_date,renewal_status,reminder_days,evidence_ref,owner_note,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
  $q->execute([$fid,$type,$body['document_no']??null,$start,$expiry,$status,$reminder,$body['evidence_ref']??null,$body['owner_note']??null,(int)$u['id'],(int)$u['id']]);$id=(int)$pdo->lastInsertId();
 }
 outlet_timeline($pdo,$fid,(int)$u['id'],'contract','Contract / renewal updated',str_replace('_',' ',$type).' | '.($expiry?:'no expiry').' | '.$status,'outlet_contract',$id,['contract_type'=>$type,'expiry_date'=>$expiry,'renewal_status'=>$status]);
 audit($pdo,(int)$u['id'],'contract_save','franchise',(string)$fid,['contract_id'=>$id,'contract_type'=>$type,'expiry_date'=>$expiry,'renewal_status'=>$status]);
 out(['ok'=>true,'id'=>$id,'renewal_status'=>$status,'expiry_date'=>$expiry]);
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
   v.cleanliness_score,v.branding_score,v.product_display_score,v.pricing_compliance_score,v.pos_usage_score,v.stock_handling_score,v.overall_score,v.findings,v.corrective_action,v.evidence_ref,v.next_visit_at,v.created_at
   FROM field_visits v JOIN franchises f ON f.id=v.franchise_id LEFT JOIN users u ON u.id=v.visitor_user_id
   ORDER BY (v.status='scheduled') DESC,v.scheduled_at DESC,v.id DESC LIMIT 200")->fetchAll();

 $tickets=$pdo->query("SELECT t.id,t.ticket_no,t.franchise_id,f.code outlet_code,f.name outlet,t.category,t.subject,t.detail,t.priority,t.status,t.assigned_user_id,u.name assigned_to,
   t.opened_at,t.due_at,t.first_response_at,t.resolved_at,t.resolution,t.escalation_level,t.escalated_at,(SELECT stu.note FROM support_ticket_updates stu WHERE stu.support_ticket_id=t.id ORDER BY stu.id DESC LIMIT 1) last_update,
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
 audit($pdo,(int)$u['id'],'update','operations_task',(string)$id,['status'=>$status,'priority'=>$priority,'assigned_user_id'=>$assignee,'due_at'=>$due,'escalation_level'=>$level]);
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
  $q=$pdo->prepare("UPDATE field_visits SET visit_type=?,status=?,scheduled_at=?,visited_at=?,visitor_user_id=?,cleanliness_score=?,branding_score=?,product_display_score=?,pricing_compliance_score=?,pos_usage_score=?,stock_handling_score=?,overall_score=?,findings=?,corrective_action=?,evidence_ref=?,next_visit_at=? WHERE id=? AND franchise_id=?");
  $q->execute([$body['visit_type']??'routine',$status,($body['scheduled_at']??null)?:null,$status==='completed'?(($body['visited_at']??null)?:date('Y-m-d H:i:s')):(($body['visited_at']??null)?:null),$visitor,$scores['cleanliness_score'],$scores['branding_score'],$scores['product_display_score'],$scores['pricing_compliance_score'],$scores['pos_usage_score'],$scores['stock_handling_score'],$overall,$body['findings']??null,$body['corrective_action']??null,$body['evidence_ref']??null,($body['next_visit_at']??null)?:null,$id,$fid]);
 }else{
  $q=$pdo->prepare("INSERT INTO field_visits(franchise_id,visit_type,status,scheduled_at,visited_at,visitor_user_id,cleanliness_score,branding_score,product_display_score,pricing_compliance_score,pos_usage_score,stock_handling_score,overall_score,findings,corrective_action,evidence_ref,next_visit_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $q->execute([$fid,$body['visit_type']??'routine',$status,($body['scheduled_at']??null)?:null,$status==='completed'?(($body['visited_at']??null)?:date('Y-m-d H:i:s')):(($body['visited_at']??null)?:null),$visitor,$scores['cleanliness_score'],$scores['branding_score'],$scores['product_display_score'],$scores['pricing_compliance_score'],$scores['pos_usage_score'],$scores['stock_handling_score'],$overall,$body['findings']??null,$body['corrective_action']??null,$body['evidence_ref']??null,($body['next_visit_at']??null)?:null,(int)$u['id']]);$id=(int)$pdo->lastInsertId();
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
 $due=array_key_exists('due_at',$body)?ops_sla_due($priority,($body['due_at']??null)?:null):$before['due_at'];
 $firstResponse=in_array($status,['assigned','in_progress','waiting','resolved','closed'],true)?'COALESCE(first_response_at,NOW())':'first_response_at';
 $resolved=in_array($status,['resolved','closed'],true)?'COALESCE(resolved_at,NOW())':($status==='open'?'NULL':'resolved_at');
 $q=$pdo->prepare("UPDATE support_tickets SET status=?,priority=?,assigned_user_id=?,due_at=?,resolution=?,first_response_at={$firstResponse},resolved_at={$resolved},escalation_level=?,escalated_at=IF(?,NOW(),escalated_at) WHERE id=?");
 $q->execute([$status,$priority,$assignee,$due,$resolution,$level,$escalate?1:0,$id]);
 $type=$escalate?'escalation':(in_array($status,['resolved','closed'],true)?'resolution':'status');
 $q=$pdo->prepare("INSERT INTO support_ticket_updates(support_ticket_id,update_type,note,old_status,new_status,created_by) VALUES(?,?,?,?,?,?)");
 $q->execute([$id,$type,$body['note']??($escalate?'Escalated':'Status updated'),$before['status'],$status,(int)$u['id']]);
 outlet_timeline($pdo,(int)$before['franchise_id'],(int)$u['id'],'support_ticket','Ticket '.$status,$before['ticket_no'].' · '.$before['subject'],'support_ticket',$id,['escalation_level'=>$level]);
 audit($pdo,(int)$u['id'],'update','support_ticket',(string)$id,['status'=>$status,'priority'=>$priority,'assigned_user_id'=>$assignee,'due_at'=>$due,'escalation_level'=>$level]);
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



if($route==='operations.compliance.resolve' && $method==='POST'){
 csrf();$u=outlet_ops_user(['OWNER','OPERATIONS','REGIONAL']);$id=(int)($body['id']??0);
 $q=$pdo->prepare("SELECT id,franchise_id,status,corrective_action FROM outlet_compliance_checks WHERE id=?");$q->execute([$id]);$row=$q->fetch();if(!$row)out(['ok'=>false,'code'=>'COMPLIANCE_NOT_FOUND'],404);
 $q=$pdo->prepare("UPDATE outlet_compliance_checks SET resolved_at=COALESCE(resolved_at,NOW()) WHERE id=?");$q->execute([$id]);
 outlet_timeline($pdo,(int)$row['franchise_id'],(int)$u['id'],'compliance','Compliance corrective action resolved',$row['corrective_action']?:'Compliance issue resolved','compliance_check',$id);
 audit($pdo,(int)$u['id'],'resolve','compliance_check',(string)$id,['franchise_id'=>(int)$row['franchise_id']]);
 out(['ok'=>true,'id'=>$id]);
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
 if(!in_array($u['role'],['OWNER','OPERATIONS','REGIONAL'],true)) out(['ok'=>false,'code'=>'ROLE_DENIED'],403);

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

 $work=$pdo->query("SELECT
   (SELECT COUNT(*) FROM operations_tasks WHERE status NOT IN('done','cancelled')) open_tasks,
   (SELECT COUNT(*) FROM operations_tasks WHERE status NOT IN('done','cancelled') AND due_at IS NOT NULL AND due_at<NOW()) overdue_tasks,
   (SELECT COUNT(*) FROM support_tickets WHERE status NOT IN('resolved','closed','cancelled')) open_tickets,
   (SELECT COUNT(*) FROM support_tickets WHERE status NOT IN('resolved','closed','cancelled') AND due_at IS NOT NULL AND due_at<NOW()) overdue_tickets,
   (SELECT COUNT(*) FROM field_visits WHERE status='scheduled' AND scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)) visits_next_7d,
   (SELECT COUNT(*) FROM outlet_compliance_checks WHERE status IN('watch','non_compliant') AND resolved_at IS NULL) open_compliance,
   (SELECT COUNT(*) FROM outlet_training_records WHERE status IN('pending','scheduled','expired') OR (expires_at IS NOT NULL AND expires_at<=DATE_ADD(CURDATE(),INTERVAL 30 DAY))) training_attention,
   (SELECT COUNT(*) FROM marketing_executions WHERE status IN('planned','ready','live')) active_marketing,
   (SELECT COUNT(*) FROM operations_alerts WHERE status='open') open_alerts,
   (SELECT COUNT(*) FROM operations_alerts WHERE status='open' AND severity='critical') critical_alerts,
   (SELECT COUNT(*) FROM outlet_daily_checkins WHERE checkin_date=CURDATE()) checkins_today,
   (SELECT COUNT(*) FROM franchises f2 WHERE f2.status IN('active','watch','critical','setup') AND NOT EXISTS(SELECT 1 FROM outlet_daily_checkins dc WHERE dc.franchise_id=f2.id AND dc.checkin_date=CURDATE())) missing_checkins,
   (SELECT COUNT(*) FROM outlet_contracts oc WHERE oc.expiry_date IS NOT NULL AND oc.renewal_status NOT IN('renewed','not_required') AND DATEDIFF(oc.expiry_date,CURDATE())<=oc.reminder_days) renewals_due,
   (SELECT COUNT(*) FROM outlet_pipeline pl2 JOIN franchises f2 ON f2.id=pl2.franchise_id WHERE f2.status IN('pipeline','setup') AND pl2.stage NOT IN('live','closed')) launch_rooms")->fetch();

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
   'work'=>[
     'open_tasks'=>(int)($work['open_tasks']??0),
     'overdue_tasks'=>(int)($work['overdue_tasks']??0),
     'open_tickets'=>(int)($work['open_tickets']??0),
     'overdue_tickets'=>(int)($work['overdue_tickets']??0),
     'visits_next_7d'=>(int)($work['visits_next_7d']??0),
     'open_compliance'=>(int)($work['open_compliance']??0),
     'training_attention'=>(int)($work['training_attention']??0),
     'active_marketing'=>(int)($work['active_marketing']??0),
     'open_alerts'=>(int)($work['open_alerts']??0),
     'critical_alerts'=>(int)($work['critical_alerts']??0),
     'checkins_today'=>(int)($work['checkins_today']??0),
     'missing_checkins'=>(int)($work['missing_checkins']??0),
     'renewals_due'=>(int)($work['renewals_due']??0),
     'launch_rooms'=>(int)($work['launch_rooms']??0),
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
 $q=$pdo->prepare("SELECT v.id,v.visit_type,v.status,v.scheduled_at,v.visited_at,v.visitor_user_id,u.name visitor,v.overall_score,v.findings,v.corrective_action,v.evidence_ref,v.next_visit_at
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
 $scopeFid=(int)($body['franchise_id']??0);
 if($scopeFid>0){
  $q=$pdo->prepare("SELECT id FROM franchises WHERE id=? AND status<>'closed'");$q->execute([$scopeFid]);$franchises=$q->fetchAll();
  if(!$franchises)out(['ok'=>false,'code'=>'FRANCHISE_NOT_FOUND'],404);
 }else{
  $franchises=$pdo->query("SELECT id FROM franchises WHERE status<>'closed' ORDER BY id")->fetchAll();
 }
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
  $pdo->commit(); audit($pdo,(int)$u['id'],'generate','settlement_period',$period,['period_start'=>$start,'period_end'=>$end,'franchise_id'=>$scopeFid?:null]);
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
