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

$requiredColumns=[
 'operations_tasks'=>['priority','assigned_user_id','due_at','escalation_level','escalated_at'],
 'field_visits'=>['visitor_user_id','overall_score','corrective_action','evidence_ref','next_visit_at'],
 'support_tickets'=>['priority','assigned_user_id','due_at','first_response_at','resolved_at','escalation_level'],
 'outlet_communications'=>['channel','direction','promised_date','follow_up_at'],
 'outlet_compliance_checks'=>['overall_score','status','corrective_action','corrective_due_at','resolved_at'],
 'marketing_executions'=>['priority','assigned_user_id','due_at','escalation_level','assets_ready','execution_verified'],
];
$missingColumns=[];
$cq=$p->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
foreach($requiredColumns as $table=>$columns){
 foreach($columns as $column){
  $cq->execute([$table,$column]);
  if((int)$cq->fetchColumn()!==1)$missingColumns[]=$table.'.'.$column;
 }
}

$ok=!$missing && !$missingColumns;
echo $ok?"OPERATIONS_PATCH2_DB_GREEN\n":"OPERATIONS_PATCH2_DB_NOT_GREEN\n";
echo "missing_tables=".($missing?implode(',',$missing):'none')."\n";
echo "missing_columns=".($missingColumns?implode(',',$missingColumns):'none')."\n";
foreach($counts as $k=>$v)echo $k."=".$v."\n";
exit($ok?0:1);
