<?php
declare(strict_types=1);
$cfg=getenv('HOME').'/teashop-os-config.php';if(!is_file($cfg)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}$c=require $cfg;
$p=new PDO($c['dsn'],$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$required=['operations_alerts','operations_report_snapshots'];$missing=[];foreach($required as $t){$q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;}
$cols=['operations_alerts'=>['alert_key','alert_type','severity','status','last_seen_at','acknowledged_at','resolved_at'],'operations_report_snapshots'=>['period_start','period_end','report_type','payload_json','generated_at']];
$missingCols=[];$q=$p->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");foreach($cols as $t=>$cs)foreach($cs as $col){$q->execute([$t,$col]);if((int)$q->fetchColumn()!==1)$missingCols[]=$t.'.'.$col;}
$weights=['sales_growth_score','stock_rotation_score','outlet_health_score','settlement_score','retention_score','compliance_score'];
$perfMissing=[];foreach($weights as $col){$q->execute(['performance_records',$col]);if((int)$q->fetchColumn()!==1)$perfMissing[]='performance_records.'.$col;}
$ok=!$missing&&!$missingCols&&!$perfMissing;
echo $ok?"OPERATIONS_PATCH4_DB_GREEN\n":"OPERATIONS_PATCH4_DB_NOT_GREEN\n";
echo "missing_tables=".($missing?implode(',',$missing):'none')."\n";
echo "missing_columns=".($missingCols?implode(',',$missingCols):'none')."\n";
echo "performance_columns=".($perfMissing?implode(',',$perfMissing):'ok')."\n";
foreach($required as $t)echo $t."=".$p->query("SELECT COUNT(*) FROM {$t}")->fetchColumn()."\n";
exit($ok?0:1);
