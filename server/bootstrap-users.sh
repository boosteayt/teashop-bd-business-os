#!/usr/bin/env bash
set -euo pipefail

CPUSER="$(whoami)"
ROOT="/home/${CPUSER}"
DB="${CPUSER}_tsbos"
DBUSER="${CPUSER}_tsbusr"
CONFIG="${ROOT}/teashop-os-config.php"
FIRSTLOGIN="${ROOT}/teashop-os-first-login.txt"

PHPCLI="/opt/cpanel/ea-php82/root/usr/bin/php"
if [ ! -x "$PHPCLI" ]; then
  for candidate in /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
    if [ -x "$candidate" ]; then PHPCLI="$candidate"; break; fi
  done
fi
if [ ! -x "$PHPCLI" ]; then echo "ERROR: real PHP CLI binary not found." >&2; exit 1; fi

echo "Tea Shop BD Business OS — secure user bootstrap"
echo "Database: $DB"
echo "DB user: $DBUSER"
read -r -s -p "Database user password: " DBPASS
echo
if [ -z "$DBPASS" ]; then echo "ERROR: password cannot be empty." >&2; exit 1; fi
export TSB_DB="$DB" TSB_DBUSER="$DBUSER" TSB_DBPASS="$DBPASS" TSB_CONFIG="$CONFIG" TSB_FIRSTLOGIN="$FIRSTLOGIN"

"$PHPCLI" <<'PHP'
<?php
$db=getenv('TSB_DB'); $dbu=getenv('TSB_DBUSER'); $dbp=getenv('TSB_DBPASS');
$config=getenv('TSB_CONFIG'); $first=getenv('TSB_FIRSTLOGIN');
try {
  $pdo=new PDO("mysql:host=localhost;dbname={$db};charset=utf8mb4",$dbu,$dbp,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
  ]);
} catch(Throwable $e) { fwrite(STDERR,"DB_CONNECTION_FAILED\n"); exit(2); }
$roles=(int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$products=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$users=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if($roles!==7 || $products!==99){ fwrite(STDERR,"FOUNDATION_CHECK_FAILED roles={$roles} products={$products}\n"); exit(3); }
if($users!==0){ fwrite(STDERR,"ABORT: users table is not empty ({$users}). No changes made.\n"); exit(4); }
$accounts=[
 ['OWNER','Shahidur Rahman','owner@teashop.bd'],
 ['OPERATIONS','Md. Omar Faruk','faruk@teashop.bd'],
 ['FINANCE','Finance Manager','finance@teashop.bd'],
 ['WAREHOUSE','Warehouse Manager','warehouse@teashop.bd'],
 ['REGIONAL','Regional Manager','regional@teashop.bd'],
 ['FRANCHISE','Franchise Owner','franchise@teashop.bd'],
 ['CASHIER','POS Cashier','cashier@teashop.bd'],
];
$out="Tea Shop BD Business OS — FIRST LOGIN CREDENTIALS\nGenerated: ".date('c')."\nChange every password after first login.\n\n";
$pdo->beginTransaction();
try {
 foreach($accounts as [$role,$name,$email]){
   $rq=$pdo->prepare("SELECT id FROM roles WHERE code=?"); $rq->execute([$role]); $rid=$rq->fetchColumn();
   if(!$rid) throw new RuntimeException("Missing role {$role}");
   $pw=bin2hex(random_bytes(8));
   $q=$pdo->prepare("INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,1,1)");
   $q->execute([$rid,$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
   $out.=$name." | ".$email." | ".$pw."\n";
 }
 $pdo->commit();
} catch(Throwable $e) { $pdo->rollBack(); fwrite(STDERR,"USER_BOOTSTRAP_FAILED\n"); exit(5); }
$configData=['dsn'=>"mysql:host=localhost;dbname={$db};charset=utf8mb4",'user'=>$dbu,'pass'=>$dbp,'setup_token'=>''];
file_put_contents($config,"<?php\nreturn ".var_export($configData,true).";\n",LOCK_EX); chmod($config,0600);
file_put_contents($first,$out,LOCK_EX); chmod($first,0600);
$count=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
echo "USER_BOOTSTRAP_OK users={$count}\nCONFIG_OK {$config}\nFIRST_LOGIN_FILE_OK {$first}\n";
PHP
