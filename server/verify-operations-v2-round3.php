<?php
declare(strict_types=1);

$root=dirname(__DIR__);$configFile=getenv('HOME').'/teashop-os-config.php';
if(!is_file($configFile)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}
$config=require $configFile;
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);
$products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$tables=['operations_benchmark_snapshots','operations_notification_routes','operations_closure_handovers'];$missing=[];$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $t){$q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;}

$app=is_file($root.'/deploy/app.js')?file_get_contents($root.'/deploy/app.js'):'';
$api=is_file($root.'/deploy/api/index.php')?file_get_contents($root.'/deploy/api/index.php'):'';
$sw=is_file($root.'/deploy/sw.js')?file_get_contents($root.'/deploy/sw.js'):'';
$source=$app."\n".$api."\n".$sw;
$markers=['function OperationsRound3View','operations.round3','operations.approval.request','operations.approval.decide','operations.evidence.save','operations.notification.route','operations.closure.handover.save','operations.management.snapshot','Outlet Benchmarking','Outlet Health Forecast','Approval Queue','Evidence & Documents Vault','Monthly Management Closure Report','Mobile / PWA Action Center','Notification Routing','Closure Handover Center','tsb-os-v20-operations-v2-round3'];
$missingMarkers=[];foreach($markers as $m)if(strpos($source,$m)===false)$missingMarkers[]=$m;
$forbidden=['Omar Faruk','Md. Omar Faruk','faruk_active_tier','faruk_manual_percent'];$forbiddenHits=[];foreach($forbidden as $x)if(stripos($source,$x)!==false)$forbiddenHits[]=$x;
$accessOk=strpos($app,"OPERATIONS:['Dashboard','Tea','Inventory / Warehouse','Outlets / Franchise','Franchise & Retail Operations','POS / Sales','Margin & Settlement','Performance & Incentives','Customers','Logistics','Reports','Notifications']")!==false;
$ownerGateOk=strpos($api,"if($handover==='approved'&&$u['role']!=='OWNER')")!==false&&strpos($api,"csrf();$u=owner();$id=(int)($body['id']??0)")!==false;
$closureRuleOk=strpos($app,'does not bypass the authoritative 8/8 closure checklist or the Owner-only final Close gate')!==false;
$dbOk=$products===99&&$roles>=12&&!$missing;
$sourceOk=!$missingMarkers&&!$forbiddenHits&&$accessOk&&$ownerGateOk&&$closureRuleOk;
echo $dbOk?"OPERATIONS_V2_ROUND3_DB_GREEN\n":"OPERATIONS_V2_ROUND3_DB_NOT_GREEN\n";
echo $sourceOk?"OPERATIONS_V2_ROUND3_SOURCE_GREEN\n":"OPERATIONS_V2_ROUND3_SOURCE_NOT_GREEN\n";
echo "products={$products}\nroles={$roles}\n";
echo "missing_tables=".($missing?implode(',',$missing):'none')."\n";
echo "missing_markers=".($missingMarkers?implode(' | ',$missingMarkers):'none')."\n";
echo "forbidden_hits=".($forbiddenHits?implode(',',$forbiddenHits):'none')."\n";
echo "operations_access_gate=".($accessOk?'PASS':'FAIL')."\n";
echo "owner_management_gate=".($ownerGateOk?'PASS':'FAIL')."\n";
echo "closure_no_bypass_gate=".($closureRuleOk?'PASS':'FAIL')."\n";
exit($dbOk&&$sourceOk?0:1);
