#!/usr/bin/env bash
set -euo pipefail
CPUSER="$(whoami)"
ROOT="/home/${CPUSER}"
REPO="${ROOT}/repositories/teashop-bd-business-os"
CONFIG="${ROOT}/teashop-os-config.php"
FIRSTLOGIN="${ROOT}/teashop-os-first-login.txt"
DEFAULT_DB="${CPUSER}_tsbos"
DEFAULT_DBUSER="${CPUSER}_tsbusr"

echo "Tea Shop BD Business OS — existing cPanel MySQL installer"
read -r -p "Database name [${DEFAULT_DB}]: " DB
DB="${DB:-$DEFAULT_DB}"
read -r -p "Database user [${DEFAULT_DBUSER}]: " DBUSER
DBUSER="${DBUSER:-$DEFAULT_DBUSER}"
read -r -s -p "Database user password: " DBPASS
echo
if [ -z "$DBPASS" ]; then echo "ERROR: database password cannot be empty." >&2; exit 1; fi
export TSB_DB="$DB" TSB_DBUSER="$DBUSER" TSB_DBPASS="$DBPASS"
echo "Testing MySQL connection..."
php <<'PHPTEST'
<?php
$dsn='mysql:host=localhost;dbname='.getenv('TSB_DB').';charset=utf8mb4';
try { new PDO($dsn,getenv('TSB_DBUSER'),getenv('TSB_DBPASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo "DB_CONNECTION_OK\n"; }
catch(Throwable $e){ fwrite(STDERR,"DB_CONNECTION_FAILED\n"); exit(2); }
PHPTEST
TABLES="$(php <<'PHPCOUNT'
<?php
$pdo=new PDO('mysql:host=localhost;dbname='.getenv('TSB_DB').';charset=utf8mb4',getenv('TSB_DBUSER'),getenv('TSB_DBPASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
PHPCOUNT
)"
if [ "$TABLES" != "0" ]; then echo "ERROR: database is not empty (${TABLES} tables found). Use a new empty database."; exit 3; fi
SETUPTOKEN="$(openssl rand -hex 24)"
umask 077
cat > "$CONFIG" <<PHP
<?php
return [
  'dsn' => 'mysql:host=localhost;dbname=${DB};charset=utf8mb4',
  'user' => '${DBUSER}',
  'pass' => '${DBPASS}',
  'setup_token' => '${SETUPTOKEN}',
];
PHP
chmod 600 "$CONFIG"
echo "Importing schema..."
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/schema.sql"
echo "Loading business rules..."
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/seed_core.sql"
echo "Loading 99-SKU tea master..."
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/seed_products.sql"
echo "Creating first-login users..."
php <<'PHPBOOT'
<?php
$config=require getenv('HOME').'/teashop-os-config.php';
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$users=[
 ['OWNER','Shahidur Rahman','owner@teashop.bd'],
 ['OPERATIONS','Md. Omar Faruk','faruk@teashop.bd'],
 ['FINANCE','Finance Manager','finance@teashop.bd'],
 ['WAREHOUSE','Warehouse Manager','warehouse@teashop.bd'],
 ['REGIONAL','Regional Manager','regional@teashop.bd'],
 ['FRANCHISE','Franchise Owner','franchise@teashop.bd'],
 ['CASHIER','POS Cashier','cashier@teashop.bd'],
];
$out="Tea Shop BD Business OS — FIRST LOGIN CREDENTIALS\nGenerated: ".date('c')."\n\n";
foreach($users as [$role,$name,$email]){
 $rq=$pdo->prepare('SELECT id FROM roles WHERE code=?'); $rq->execute([$role]); $rid=$rq->fetchColumn();
 $pw=bin2hex(random_bytes(6));
 $q=$pdo->prepare('INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,1,1)');
 $q->execute([$rid,$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
 $out.=$name." | ".$email." | ".$pw."\n";
}
file_put_contents(getenv('HOME').'/teashop-os-first-login.txt',$out,LOCK_EX);
chmod(getenv('HOME').'/teashop-os-first-login.txt',0600);
PHPBOOT
php <<'PHPCONFIG'
<?php
$p=getenv("HOME")."/teashop-os-config.php"; $c=require $p; $c["setup_token"]="";
file_put_contents($p,"<?php\nreturn ".var_export($c,true).";\n"); chmod($p,0600);
PHPCONFIG
echo "INSTALL COMPLETE"
echo "Config file: $CONFIG"
echo "First-login credentials file: $FIRSTLOGIN"
echo "Do NOT paste either file into chat."
php <<'PHPVERIFY'
<?php
$c=require getenv("HOME")."/teashop-os-config.php";
$p=new PDO($c["dsn"],$c["user"],$c["pass"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo "DB_OK products=".$p->query("SELECT COUNT(*) FROM products")->fetchColumn()." users=".$p->query("SELECT COUNT(*) FROM users")->fetchColumn()." roles=".$p->query("SELECT COUNT(*) FROM roles")->fetchColumn().PHP_EOL;
PHPVERIFY
