#!/usr/bin/env bash
set -euo pipefail
CPUSER="$(whoami)"
ROOT="/home/${CPUSER}"
REPO="${ROOT}/repositories/teashop-bd-business-os"
CONFIG="${ROOT}/teashop-os-config.php"
FIRSTLOGIN="${ROOT}/teashop-os-first-login.txt"
DEFAULT_DB="${CPUSER}_tsbos"
DEFAULT_DBUSER="${CPUSER}_tsbusr"

PHPCLI=""
for candidate in /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
  if [ -x "$candidate" ] && "$candidate" -r 'echo "CLI_OK";' 2>/dev/null | grep -q "CLI_OK"; then
    PHPCLI="$candidate"
    break
  fi
done
if [ -z "$PHPCLI" ]; then
  echo "ERROR: a real PHP CLI binary was not found." >&2
  exit 1
fi
echo "PHP CLI: $PHPCLI"

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
"$PHPCLI" <<'PHPTEST'
<?php
$dsn='mysql:host=localhost;dbname='.getenv('TSB_DB').';charset=utf8mb4';
try { new PDO($dsn,getenv('TSB_DBUSER'),getenv('TSB_DBPASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo "DB_CONNECTION_OK\n"; }
catch(Throwable $e){ fwrite(STDERR,"DB_CONNECTION_FAILED\n"); exit(2); }
PHPTEST
TABLES="$("$PHPCLI" <<'PHPCOUNT'
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
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/migrations/2026_10_05_business_os_full_roles_modules.sql"
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/migrations/2026_10_05_operations_patch1_outlet_network_core.sql"
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/migrations/2026_10_05_operations_patch2_daily_support.sql"
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/migrations/2026_10_05_operations_patch3_intelligence.sql"
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "${REPO}/database/migrations/2026_10_05_operations_patch4_final_closure.sql"
echo "Creating first-login users..."
"$PHPCLI" <<'PHPBOOT'
<?php
$config=require getenv('HOME').'/teashop-os-config.php';
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$users=[
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
"$PHPCLI" <<'PHPCONFIG'
<?php
$p=getenv("HOME")."/teashop-os-config.php"; $c=require $p; $c["setup_token"]="";
file_put_contents($p,"<?php\nreturn ".var_export($c,true).";\n"); chmod($p,0600);
PHPCONFIG
echo "INSTALL COMPLETE"
echo "Config file: $CONFIG"
echo "First-login credentials file: $FIRSTLOGIN"
echo "Do NOT paste either file into chat."
"$PHPCLI" <<'PHPVERIFY'
<?php
$c=require getenv("HOME")."/teashop-os-config.php";
$p=new PDO($c["dsn"],$c["user"],$c["pass"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo "DB_OK products=".$p->query("SELECT COUNT(*) FROM products")->fetchColumn()." users=".$p->query("SELECT COUNT(*) FROM users")->fetchColumn()." roles=".$p->query("SELECT COUNT(*) FROM roles")->fetchColumn().PHP_EOL;
PHPVERIFY
