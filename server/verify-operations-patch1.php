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

$required=['outlet_profiles','outlet_pipeline','outlet_checklist_items','outlet_staff','outlet_training_records','outlet_timeline'];
$missing=[];
foreach($required as $t){
 $q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
 $q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;
}
$franchises=(int)$p->query("SELECT COUNT(*) FROM franchises")->fetchColumn();
$profiles=(int)$p->query("SELECT COUNT(*) FROM outlet_profiles")->fetchColumn();
$pipelines=(int)$p->query("SELECT COUNT(*) FROM outlet_pipeline")->fetchColumn();
$opening=(int)$p->query("SELECT COUNT(*) FROM outlet_checklist_items WHERE checklist_type='opening'")->fetchColumn();
$closure=(int)$p->query("SELECT COUNT(*) FROM outlet_checklist_items WHERE checklist_type='closure'")->fetchColumn();
$health=(int)$p->query("SELECT COUNT(*) FROM outlet_health_checks")->fetchColumn();

$ok=!$missing && $profiles===$franchises && $pipelines===$franchises && $opening>=($franchises*10) && $closure>=($franchises*8);
echo $ok?"OPERATIONS_PATCH1_DB_GREEN\n":"OPERATIONS_PATCH1_DB_NOT_GREEN\n";
echo "franchises={$franchises}\nprofiles={$profiles}\npipelines={$pipelines}\n";
echo "opening_items={$opening}\nclosure_items={$closure}\nhealth_checks={$health}\n";
echo "missing_tables=".($missing?implode(',',$missing):'none')."\n";
exit($ok?0:1);
