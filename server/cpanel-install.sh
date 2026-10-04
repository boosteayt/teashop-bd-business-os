#!/usr/bin/env bash
set -euo pipefail

CPUSER="$(whoami)"
UAPI="/usr/local/cpanel/bin/uapi"
ROOT="/home/\${CPUSER}"
REPO="\${ROOT}/repositories/teashop-bd-business-os"
DB="\${CPUSER}_tsbos"
DBUSER="\${CPUSER}_tsbusr"
CONFIG="\${ROOT}/teashop-os-config.php"
FIRSTLOGIN="\${ROOT}/teashop-os-first-login.txt"

if [ ! -x "$UAPI" ]; then
  echo "ERROR: cPanel UAPI not found at $UAPI" >&2
  exit 1
fi

echo "Tea Shop BD Business OS installer"
echo "Account: $CPUSER"
echo "Database: $DB"
echo "DB user: $DBUSER"

DBPASS="$(php -r 'echo bin2hex(random_bytes(18));')"
SETUPTOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"

echo "Creating database..."
"$UAPI" --output=jsonpretty --user="$CPUSER" Mysql create_database name="$DB"

echo "Creating database user..."
"$UAPI" --output=jsonpretty --user="$CPUSER" Mysql create_user name="$DBUSER" password="$DBPASS"

echo "Granting privileges..."
"$UAPI" --output=jsonpretty --user="$CPUSER" Mysql set_privileges_on_database user="$DBUSER" database="$DB" privileges=ALL%20PRIVILEGES

umask 077
cat > "$CONFIG" <<PHP
<?php
return [
  'dsn' => 'mysql:host=localhost;dbname=\${DB};charset=utf8mb4',
  'user' => '\${DBUSER}',
  'pass' => '\${DBPASS}',
  'setup_token' => '\${SETUPTOKEN}',
];
PHP
chmod 600 "$CONFIG"

echo "Importing schema and business-rule seeds..."
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "\${REPO}/database/schema.sql"
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "\${REPO}/database/seed_core.sql"
mysql --protocol=socket -u"$DBUSER" -p"$DBPASS" "$DB" < "\${REPO}/database/seed_products.sql"

echo "Creating first-login users..."
php <<'PHPBOOT'
<?php
$config=require getenv('HOME').'/teashop-os-config.php';
$pdo=new PDO($config['dsn'],$config['user'],$config['pass'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$users=[
 ['OWNER','Shahidur Rahman','owner@teashop.bd'],
 ['OPERATIONS','Md. Omar Faruk','faruk@teashop.bd'],
 ['FINANCE','Finance Manager','finance@teashop.bd'],
 ['WAREHOUSE','Warehouse Manager','warehouse@teashop.bd'],
 ['REGIONAL','Regional Manager','regional@teashop.bd'],
 ['FRANCHISE','Franchise Owner','franchise@teashop.bd'],
 ['CASHIER','POS Cashier','cashier@teashop.bd'],
];
$out="Tea Shop BD Business OS — FIRST LOGIN CREDENTIALS\n";
$out.="Generated: ".date('c')."\n\n";
foreach($users as [$role,$name,$email]){
 $rid=$pdo->prepare('SELECT id FROM roles WHERE code=?');
 $rid->execute([$role]);
 $roleId=$rid->fetchColumn();
 if(!$roleId) throw new RuntimeException("Role missing: $role");
 $password=bin2hex(random_bytes(6));
 $q=$pdo->prepare('INSERT INTO users(role_id,name,email,password_hash,must_change_password,active) VALUES(?,?,?,?,1,1)');
 $q->execute([$roleId,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
 $out.=$name." | ".$email." | ".$password."\n";
}
file_put_contents(getenv('HOME').'/teashop-os-first-login.txt',$out,LOCK_EX);
chmod(getenv('HOME').'/teashop-os-first-login.txt',0600);
PHPBOOT

php -r '
$p=getenv("HOME")."/teashop-os-config.php";
$c=require $p;
$c["setup_token"]="";
file_put_contents($p,"<?php\nreturn ".var_export($c,true).";\n");
chmod($p,0600);
'

echo
echo "INSTALL COMPLETE"
echo "Config: $CONFIG"
echo "First-login credentials: $FIRSTLOGIN"
echo "Do not paste either file into chat."
echo
echo "Verification:"
php -r '$c=require getenv("HOME")."/teashop-os-config.php"; $p=new PDO($c["dsn"],$c["user"],$c["pass"]); echo "DB_OK products=".$p->query("SELECT COUNT(*) FROM products")->fetchColumn()." users=".$p->query("SELECT COUNT(*) FROM users")->fetchColumn().PHP_EOL;'
