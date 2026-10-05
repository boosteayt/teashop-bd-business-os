<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$configFile=getenv('HOME').'/teashop-os-config.php';
if(!is_file($configFile)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}
$config=require $configFile;
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);

$products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
if($products!==99||$roles<12){
 fwrite(STDERR,"FINAL_PRECONDITION_FAILED products={$products} roles={$roles}\n");
 exit(3);
}

$migrations=[
 'database/migrations/2026_10_05_operations_patch1_outlet_network_core.sql',
 'database/migrations/2026_10_05_operations_patch2_daily_support.sql',
 'database/migrations/2026_10_05_operations_patch3_intelligence.sql',
 'database/migrations/2026_10_05_operations_patch4_final_closure.sql',
 'database/migrations/2026_10_05_operations_command_center_v2_round1.sql',
 'database/migrations/2026_10_05_operations_command_center_v2_round2.sql',
];
foreach($migrations as $relative){
 $file=$root.'/'.$relative;
 if(!is_file($file)){fwrite(STDERR,"MIGRATION_NOT_FOUND {$relative}\n");exit(4);}
 $sql=file_get_contents($file);
 foreach((preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[]) as $statement){
  $statement=trim($statement);
  if($statement===''||str_starts_with($statement,'--'))continue;
  $pdo->exec($statement);
 }
}

$opening=[
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
$closure=[
 ['closure_approved','Closure / suspension decision approved',10],
 ['sales_stopped','POS sales stopped at effective time',20],
 ['stock_counted','Physical stock counted',30],
 ['stock_returned','Approved stock returned / transferred',40],
 ['dues_reconciled','Dues and settlement reconciled',50],
 ['pos_access_closed','Outlet POS access disabled / reassigned',60],
 ['brand_assets_removed','Brand assets / signage recovery completed',70],
 ['final_handover','Final documents and handover completed',80],
];

$profile=$pdo->prepare("INSERT IGNORE INTO outlet_profiles(franchise_id,target_open_date) VALUES(?,?)");
$pipeline=$pdo->prepare("INSERT IGNORE INTO outlet_pipeline(franchise_id,stage,target_open_date) VALUES(?,?,?)");
$item=$pdo->prepare("INSERT IGNORE INTO outlet_checklist_items(franchise_id,checklist_type,item_code,item_label,sort_order,required) VALUES(?,?,?,?,?,1)");
$timeline=$pdo->prepare("INSERT INTO outlet_timeline(franchise_id,event_type,title,detail,reference_type,reference_id)
 SELECT ?,'system','Final Operations migration reconciled','Outlet linked to Patch 1–4 Operations model','franchise',?
 WHERE NOT EXISTS(SELECT 1 FROM outlet_timeline WHERE franchise_id=? AND event_type='system' AND title='Final Operations migration reconciled')");

$franchises=$pdo->query("SELECT id,status,opened_at FROM franchises ORDER BY id")->fetchAll();
foreach($franchises as $fr){
 $fid=(int)$fr['id'];$target=$fr['opened_at']?:null;
 $stage=match($fr['status']){
  'active','watch','critical'=>'live',
  'setup'=>'shop_ready',
  'closed'=>'closed',
  default=>'lead',
 };
 $profile->execute([$fid,$target]);
 $pipeline->execute([$fid,$stage,$target]);
 foreach($opening as [$code,$label,$sort])$item->execute([$fid,'opening',$code,$label,$sort]);
 foreach($closure as [$code,$label,$sort])$item->execute([$fid,'closure',$code,$label,$sort]);
 $timeline->execute([$fid,$fid,$fid]);
}

$tables=[
 'outlet_profiles','outlet_pipeline','outlet_checklist_items','outlet_staff','outlet_training_records','outlet_timeline',
 'operations_tasks','field_visits','support_tickets','support_ticket_updates','outlet_communications','outlet_compliance_checks','marketing_executions',
 'outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts',
 'operations_alerts','operations_report_snapshots','outlet_daily_checkins','outlet_contracts','operations_automation_links','settlement_followups'
];
$missing=[];
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $t){$q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;}

if($missing){
 fwrite(STDERR,"OPERATIONS_FINAL_UPGRADE_NOT_GREEN\nmissing_tables=".implode(',',$missing)."\n");
 exit(5);
}

echo "OPERATIONS_FINAL_UPGRADE_OK\n";
echo "products={$products}\nroles={$roles}\n";
echo "operations_tables=".count($tables)."/".count($tables)."\n";
echo "franchises=".count($franchises)."\n";
echo "opening_checklist_items=".$pdo->query("SELECT COUNT(*) FROM outlet_checklist_items WHERE checklist_type='opening'")->fetchColumn()."\n";
echo "closure_checklist_items=".$pdo->query("SELECT COUNT(*) FROM outlet_checklist_items WHERE checklist_type='closure'")->fetchColumn()."\n";
