<?php
declare(strict_types=1);

$home=getenv('HOME');
$repo=dirname(__DIR__);
$cfg=$home.'/teashop-os-config.php';
if(!is_file($cfg)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}
$c=require $cfg;
$p=new PDO($c['dsn'],$c['user'],$c['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);

$products=(int)$p->query("SELECT COUNT(*) FROM products")->fetchColumn();
$roles=(int)$p->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$users=(int)$p->query("SELECT COUNT(*) FROM users")->fetchColumn();

$tables=[
 'outlet_profiles','outlet_pipeline','outlet_checklist_items','outlet_staff','outlet_training_records','outlet_timeline',
 'operations_tasks','field_visits','support_tickets','support_ticket_updates','outlet_communications','outlet_compliance_checks','marketing_executions',
 'outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts',
 'operations_alerts','operations_report_snapshots','outlet_daily_checkins','outlet_contracts','operations_automation_links','settlement_followups','operations_benchmark_snapshots','operations_notification_routes','operations_closure_handovers','performance_records','notifications','audit_logs'
];
$missing=[];
$q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $t){$q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;}

$app=is_file($repo.'/deploy/app.js')?file_get_contents($repo.'/deploy/app.js'):'';
$api=is_file($repo.'/deploy/api/index.php')?file_get_contents($repo.'/deploy/api/index.php'):'';
$source=$app."\n".$api;

$requiredMarkers=[
 'function Outlet360',
 'function OperationsPatch2View',
 'function OperationsPatch3View',
 'function OperationsPatch4View',
 "$route==='operations.alerts.refresh'",
 "$route==='operations.performance.generate'",
 "$route==='operations.network.report'",
 "$route==='operations.security.audit'",
 'OPENING_CHECKLIST_INCOMPLETE',
 'CLOSURE_CHECKLIST_INCOMPLETE',
 'function OperationsRound1View',
 'operations.round1',
 'operations.checkin.save',
 'operations.contract.save',
 'contract_expiry',
 'function OperationsRound2View',
 'operations.round2',
 'operations.automation.run',
 'operations.settlement.followup.save',
 'function OperationsRound3View',
 'operations.round3',
 'operations.approval.request',
 'operations.approval.decide',
 'operations.evidence.save',
 'operations.notification.route',
 'operations.closure.handover.save',
 'operations.management.snapshot'
];
$missingMarkers=[];foreach($requiredMarkers as $m)if(strpos($source,$m)===false)$missingMarkers[]=$m;

$forbidden=['Omar Faruk','Md. Omar Faruk','Shahidur Rahman','faruk_active_tier','faruk_manual_percent'];
$forbiddenHits=[];foreach($forbidden as $x)if(stripos($source,$x)!==false)$forbiddenHits[]=$x;

$accessOk=strpos($app,"OPERATIONS:['Dashboard','Tea','Inventory / Warehouse','Outlets / Franchise','Franchise & Retail Operations','POS / Sales','Margin & Settlement','Performance & Incentives','Customers','Logistics','Reports','Notifications']")!==false;
$weightsOk=strpos($app,'Sales Growth · 30%')!==false&&strpos($app,'Stock Rotation · 20%')!==false&&strpos($app,'Outlet Health · 15%')!==false&&strpos($app,'Settlement · 15%')!==false&&strpos($app,'Retention · 10%')!==false&&strpos($app,'SOP Compliance · 10%')!==false;
$businessRulesOk=strpos($api,"'stock_received_is_not_profit'=>true")!==false&&strpos($api,"'verified_pos_sale_earns_margin'=>true")!==false&&strpos($api,"'operations_cannot_override_margin'=>true")!==false&&strpos($api,"'operations_cannot_approve_own_performance'=>true")!==false;
$round3OwnerGate=strpos($api,"if($handover==='approved'&&$u['role']!=='OWNER')")!==false&&strpos($app,'Owner-only final Close gate')!==false;

$dbOk=$products===99&&$roles>=12&&!$missing;
$sourceOk=!$missingMarkers&&!$forbiddenHits&&$accessOk&&$weightsOk&&$businessRulesOk&&$round3OwnerGate;
echo $dbOk?"OPERATIONS_FINAL_DB_GREEN\n":"OPERATIONS_FINAL_DB_NOT_GREEN\n";
echo $sourceOk?"OPERATIONS_FINAL_SOURCE_GREEN\n":"OPERATIONS_FINAL_SOURCE_NOT_GREEN\n";
echo "products={$products}\nroles={$roles}\nusers={$users}\n";
echo "missing_tables=".($missing?implode(',',$missing):'none')."\n";
echo "missing_markers=".($missingMarkers?implode(' | ',$missingMarkers):'none')."\n";
echo "forbidden_hits=".($forbiddenHits?implode(',',$forbiddenHits):'none')."\n";
echo "operations_access_gate=".($accessOk?'PASS':'FAIL')."\n";
echo "performance_weights_gate=".($weightsOk?'PASS':'FAIL')."\n";
echo "business_rules_gate=".($businessRulesOk?'PASS':'FAIL')."\n";
echo "round3_owner_gate=".($round3OwnerGate?'PASS':'FAIL')."\n";
exit($dbOk&&$sourceOk?0:1);
