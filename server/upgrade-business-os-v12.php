<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = getenv('HOME').'/teashop-os-config.php';
$migrationFile = $root.'/database/migrations/2026_10_05_business_os_full_roles_modules.sql';
$credentialsFile = getenv('HOME').'/teashop-os-new-role-credentials.txt';

if (!is_file($configFile)) { fwrite(STDERR, "CONFIG_NOT_FOUND\n"); exit(2); }
if (!is_file($migrationFile)) { fwrite(STDERR, "MIGRATION_NOT_FOUND\n"); exit(3); }

$config = require $configFile;
$pdo = new PDO($config['dsn'],$config['user'],$config['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
if($products!==99){ fwrite(STDERR,"ABORT_PRODUCTS_EXPECTED_99 actual={$products}\n"); exit(4); }

$sql=file_get_contents($migrationFile);
$statements=array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[]),fn($s)=>$s!=='' && !str_starts_with(ltrim($s),'--')));
foreach($statements as $statement){
    $pdo->exec($statement);
}

$accounts=[
 ['OWNER','Owner / Super Admin','owner@teashop.bd'],
 ['OPERATIONS','Franchise & Retail Operations','operations@teashop.bd'],
 ['FINANCE','Finance & Accounts','finance@teashop.bd'],
 ['WAREHOUSE','Warehouse & Inventory','warehouse@teashop.bd'],
 ['PRODUCTION','Production & Blending','production@teashop.bd'],
 ['QC','Quality Control','qc@teashop.bd'],
 ['PACKAGING','Packaging','packaging@teashop.bd'],
 ['REGIONAL','Regional Manager','regional@teashop.bd'],
 ['FRANCHISE','Franchise Owner','franchise@teashop.bd'],
 ['OUTLET_MANAGER','Outlet Manager','manager@teashop.bd'],
 ['CASHIER','POS / Cashier','cashier@teashop.bd'],
 ['AUDITOR','Auditor / Read Only','auditor@teashop.bd'],
];

$newCredentials=[];
foreach($accounts as [$roleCode,$displayName,$email]){
    $rq=$pdo->prepare("SELECT id FROM roles WHERE code=? LIMIT 1");
    $rq->execute([$roleCode]);
    $roleId=(int)$rq->fetchColumn();
    if(!$roleId) throw new RuntimeException("Missing role {$roleCode}");

    $uq=$pdo->prepare("SELECT id,email FROM users WHERE role_id=? ORDER BY id LIMIT 1");
    $uq->execute([$roleId]);
    $existing=$uq->fetch();

    if($existing){
        $conflict=$pdo->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
        $conflict->execute([$email,(int)$existing['id']]);
        if($conflict->fetchColumn()){
            throw new RuntimeException("Email conflict for {$email}");
        }
        $up=$pdo->prepare("UPDATE users SET name=?,email=? WHERE id=?");
        $up->execute([$displayName,$email,(int)$existing['id']]);
        continue;
    }

    $password='Tsb!'.bin2hex(random_bytes(8)).'A7';
    $iq=$pdo->prepare("INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,1,1)");
    $iq->execute([$roleId,$displayName,$email,password_hash($password,PASSWORD_DEFAULT)]);
    $newCredentials[]=$displayName." | ".$email." | ".$password;
}

if($newCredentials){
    $out="Tea Shop BD Business OS — NEW ROLE FIRST LOGIN CREDENTIALS\n";
    $out.="Generated: ".date('c')."\n";
    $out.="Existing-role passwords were NOT changed. New users must change password on first login.\n\n";
    $out.=implode("\n",$newCredentials)."\n";
    file_put_contents($credentialsFile,$out,LOCK_EX);
    chmod($credentialsFile,0600);
}

$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$users=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$tables=['qc_checks','wastage_records','franchise_margin_history','outlet_health_checks','customers','corporate_orders','logistics_shipments','approvals','documents','notifications','performance_records'];
$present=0;
foreach($tables as $t){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]);
    $present+=(int)$q->fetchColumn();
}

echo "BUSINESS_OS_UPGRADE_OK\n";
echo "products={$products}\nroles={$roles}\nusers={$users}\nlifecycle_tables={$present}/".count($tables)."\n";
if($newCredentials) echo "new_role_credentials={$credentialsFile}\n";
else echo "new_role_credentials=none_created\n";
