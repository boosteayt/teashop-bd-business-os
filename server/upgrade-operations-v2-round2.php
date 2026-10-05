<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$configFile=getenv('HOME').'/teashop-os-config.php';
$migrationFile=$root.'/database/migrations/2026_10_05_operations_command_center_v2_round2.sql';
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
if($products!==99||$roles<12){fwrite(STDERR,"ROUND2_V2_PRECONDITION_FAILED products={$products} roles={$roles}\n");exit(4);}

$sql=file_get_contents($migrationFile);
foreach((preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[]) as $statement){
 $statement=trim($statement);
 if($statement===''||str_starts_with($statement,'--'))continue;
 $pdo->exec($statement);
}

$tables=['operations_automation_links','settlement_followups'];$missing=[];
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $t){$q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;}
if($missing){fwrite(STDERR,"OPERATIONS_V2_ROUND2_UPGRADE_NOT_GREEN\nmissing_tables=".implode(',',$missing)."\n");exit(5);}

echo "OPERATIONS_V2_ROUND2_UPGRADE_OK\n";
echo "products={$products}\nroles={$roles}\n";
echo "round2_tables=2/2\n";
echo "automation_links=".$pdo->query("SELECT COUNT(*) FROM operations_automation_links")->fetchColumn()."\n";
echo "settlement_followups=".$pdo->query("SELECT COUNT(*) FROM settlement_followups")->fetchColumn()."\n";
