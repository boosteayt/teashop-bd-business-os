<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$configFile=getenv('HOME').'/teashop-os-config.php';
$migrationFile=$root.'/database/migrations/2026_10_05_operations_command_center_v2_round1.sql';
if(!is_file($configFile)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}
if(!is_file($migrationFile)){fwrite(STDERR,"MIGRATION_NOT_FOUND\n");exit(3);}

$config=require $configFile;
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);

$products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
if($products!==99||$roles<12){fwrite(STDERR,"ROUND1_V2_PRECONDITION_FAILED products={$products} roles={$roles}\n");exit(4);}

$sql=file_get_contents($migrationFile);
foreach((preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[]) as $statement){
 $statement=trim($statement);
 if($statement===''||str_starts_with($statement,'--'))continue;
 $pdo->exec($statement);
}

// Reconcile existing agreement / lease dates into renewal control without duplicates.
$profiles=$pdo->query("SELECT franchise_id,agreement_no,agreement_date,lease_start,lease_end FROM outlet_profiles")->fetchAll();
$exists=$pdo->prepare("SELECT id FROM outlet_contracts WHERE franchise_id=? AND contract_type=? LIMIT 1");
$insert=$pdo->prepare("INSERT INTO outlet_contracts(franchise_id,contract_type,document_no,start_date,expiry_date,renewal_status,reminder_days,owner_note) VALUES(?,?,?,?,?,'active',30,?)");
$seeded=0;
foreach($profiles as $p){
 $fid=(int)$p['franchise_id'];
 if(!empty($p['agreement_no'])||!empty($p['agreement_date'])){
  $exists->execute([$fid,'franchise_agreement']);
  if(!$exists->fetchColumn()){$insert->execute([$fid,'franchise_agreement',$p['agreement_no']?:null,$p['agreement_date']?:null,null,'Seeded from existing Outlet 360 agreement profile']);$seeded++;}
 }
 if(!empty($p['lease_start'])||!empty($p['lease_end'])){
  $exists->execute([$fid,'lease']);
  if(!$exists->fetchColumn()){$insert->execute([$fid,'lease',null,$p['lease_start']?:null,$p['lease_end']?:null,'Seeded from existing Outlet 360 lease profile']);$seeded++;}
 }
}

$tables=['outlet_daily_checkins','outlet_contracts'];$missing=[];
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $t){$q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;}
if($missing){fwrite(STDERR,"OPERATIONS_V2_ROUND1_UPGRADE_NOT_GREEN\nmissing_tables=".implode(',',$missing)."\n");exit(5);}

echo "OPERATIONS_V2_ROUND1_UPGRADE_OK\n";
echo "products={$products}\nroles={$roles}\n";
echo "round1_tables=2/2\n";
echo "contract_records=".$pdo->query("SELECT COUNT(*) FROM outlet_contracts")->fetchColumn()."\n";
echo "seeded_contract_records={$seeded}\n";
echo "daily_checkins=".$pdo->query("SELECT COUNT(*) FROM outlet_daily_checkins")->fetchColumn()."\n";
