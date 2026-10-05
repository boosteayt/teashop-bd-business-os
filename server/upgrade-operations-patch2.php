<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$configFile=getenv('HOME').'/teashop-os-config.php';
$migrationFile=$root.'/database/migrations/2026_10_05_operations_patch2_daily_support.sql';

if(!is_file($configFile)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}
if(!is_file($migrationFile)){fwrite(STDERR,"PATCH2_MIGRATION_NOT_FOUND\n");exit(3);}

$config=require $configFile;
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);

$products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$patch1=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='outlet_profiles'")->fetchColumn();
if($products!==99||$roles<12||$patch1!==1){
 fwrite(STDERR,"PATCH2_PRECONDITION_FAILED products={$products} roles={$roles} patch1={$patch1}\n");
 exit(4);
}

$sql=file_get_contents($migrationFile);
$parts=preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[];
foreach($parts as $statement){
 $statement=trim($statement);
 if($statement===''||str_starts_with($statement,'--'))continue;
 $pdo->exec($statement);
}

$tables=['operations_tasks','field_visits','support_tickets','support_ticket_updates','outlet_communications','outlet_compliance_checks','marketing_executions'];
$present=0;
foreach($tables as $t){
 $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
 $q->execute([$t]);$present+=(int)$q->fetchColumn();
}

echo "OPERATIONS_PATCH2_UPGRADE_OK\n";
echo "products={$products}\nroles={$roles}\n";
echo "patch2_tables={$present}/".count($tables)."\n";
echo "existing_tasks=".$pdo->query("SELECT COUNT(*) FROM operations_tasks")->fetchColumn()."\n";
echo "existing_tickets=".$pdo->query("SELECT COUNT(*) FROM support_tickets")->fetchColumn()."\n";
