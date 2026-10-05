<?php
declare(strict_types=1);
$cfg=getenv('HOME').'/teashop-os-config.php';if(!is_file($cfg)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);}$c=require $cfg;
$p=new PDO($c['dsn'],$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$required=['outlet_sales_targets','outlet_inventory_policies','outlet_stock_counts'];$missing=[];foreach($required as $t){$q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);if((int)$q->fetchColumn()!==1)$missing[]=$t;}
$cols=['outlet_sales_targets'=>['sales_target','receipt_target','approved_by'],'outlet_inventory_policies'=>['min_days_cover','target_days_cover','max_days_cover','dead_stock_days'],'outlet_stock_counts'=>['system_qty','physical_qty','variance_qty','variance_value','status']];
$missingCols=[];$q=$p->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");foreach($cols as $t=>$cs)foreach($cs as $col){$q->execute([$t,$col]);if((int)$q->fetchColumn()!==1)$missingCols[]=$t.'.'.$col;}
$ok=!$missing&&!$missingCols;echo $ok?"OPERATIONS_PATCH3_DB_GREEN\n":"OPERATIONS_PATCH3_DB_NOT_GREEN\n";echo "missing_tables=".($missing?implode(',',$missing):'none')."\n";echo "missing_columns=".($missingCols?implode(',',$missingCols):'none')."\n";foreach($required as $t)echo $t."=".$p->query("SELECT COUNT(*) FROM {$t}")->fetchColumn()."\n";exit($ok?0:1);
