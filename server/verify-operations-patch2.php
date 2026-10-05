<?php
declare(strict_types=1);

$configFile=getenv('HOME').'/teashop-os-config.php';
if(!is_file($configFile)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}
$c=require $configFile;
$p=new PDO($c['dsn'],$c['user'],$c['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);

$required=['operations_tasks','field_visits','support_tickets','support_ticket_updates','outlet_communications','outlet_compliance_checks','marketing_executions'];
$missing=[];
foreach($required as $t){
 $q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
 $q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;
}
$counts=[];
foreach($required as $t)$counts[$t]=(int)$p->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();

$ok=!$missing;
echo $ok?"OPERATIONS_PATCH2_DB_GREEN\n":"OPERATIONS_PATCH2_DB_NOT_GREEN\n";
echo "missing_tables=".($missing?implode(',',$missing):'none')."\n";
foreach($counts as $k=>$v)echo $k."=".$v."\n";
exit($ok?0:1);
