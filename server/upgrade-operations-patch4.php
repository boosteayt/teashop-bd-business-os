<?php
declare(strict_types=1);
$root=dirname(__DIR__);$cfg=getenv('HOME').'/teashop-os-config.php';$mig=$root.'/database/migrations/2026_10_05_operations_patch4_final_closure.sql';
if(!is_file($cfg)){fwrite(STDERR,"CONFIG_NOT_FOUND\n");exit(2);} if(!is_file($mig)){fwrite(STDERR,"PATCH4_MIGRATION_NOT_FOUND\n");exit(3);}
$c=require $cfg;$p=new PDO($c['dsn'],$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$products=(int)$p->query("SELECT COUNT(*) FROM products")->fetchColumn();$roles=(int)$p->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$patch3=(int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='outlet_sales_targets'")->fetchColumn();
if($products!==99||$roles<12||$patch3!==1){fwrite(STDERR,"PATCH4_PRECONDITION_FAILED products={$products} roles={$roles} patch3={$patch3}\n");exit(4);}
$sql=file_get_contents($mig);foreach((preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[]) as $s){$s=trim($s);if($s===''||str_starts_with($s,'--'))continue;$p->exec($s);}
$tables=['operations_alerts','operations_report_snapshots'];$present=0;foreach($tables as $t){$q=$p->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);$present+=(int)$q->fetchColumn();}
echo "OPERATIONS_PATCH4_UPGRADE_OK\nproducts={$products}\nroles={$roles}\npatch4_tables={$present}/2\n";
