#!/usr/bin/env bash
set -euo pipefail

CPUSER="$(whoami)"
ROOT="/home/${CPUSER}"
REPO="${ROOT}/repositories/teashop-bd-business-os"
LIVE="${ROOT}/teashop.bd"
CONFIG="${ROOT}/teashop-os-config.php"
MIGRATION="${REPO}/database/migrations/2026_10_05_procurement_v2.sql"

PHPCLI="/opt/cpanel/ea-php82/root/usr/bin/php"
if [ ! -x "$PHPCLI" ]; then
  for candidate in /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
    if [ -x "$candidate" ]; then PHPCLI="$candidate"; break; fi
  done
fi

[ -x "$PHPCLI" ] || { echo "ERROR: PHP CLI not found"; exit 1; }
[ -f "$CONFIG" ] || { echo "ERROR: secure DB config not found"; exit 1; }
[ -f "$MIGRATION" ] || { echo "ERROR: procurement migration not found"; exit 1; }
command -v mysql >/dev/null 2>&1 || { echo "ERROR: mysql client not found"; exit 1; }

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
chmod 600 "$TMP"

TSB_CONFIG="$CONFIG" TSB_MY_CNF="$TMP" "$PHPCLI" <<'PHP'
<?php
$c=require getenv('TSB_CONFIG');
$dsn=$c['dsn']??'';
if(!preg_match('/host=([^;]+)/',$dsn,$hm) || !preg_match('/dbname=([^;]+)/',$dsn,$dm)){
    fwrite(STDERR,"ERROR: invalid DB config\n"); exit(2);
}
$esc=function($v){ return str_replace(['\\\\','"'],['\\\\\\\\','\\"'],(string)$v); };
$txt="[client]\n".
     'host="'.$esc($hm[1])."\"\n".
     'user="'.$esc($c['user'])."\"\n".
     'password="'.$esc($c['pass'])."\"\n".
     'database="'.$esc($dm[1])."\"\n";
file_put_contents(getenv('TSB_MY_CNF'),$txt,LOCK_EX);
PHP

echo "Applying procurement v2 migration..."
mysql --defaults-extra-file="$TMP" < "$MIGRATION"

echo "Linting production API..."
"$PHPCLI" -l "${REPO}/deploy/api/index.php"

if command -v node >/dev/null 2>&1; then
  echo "Checking browser JavaScript..."
  node --check "${REPO}/deploy/app.js"
  node --check "${REPO}/deploy/sw.js"
fi

echo "Deploying live files..."
cp -a "${REPO}/deploy/." "${LIVE}/"

echo
echo "=== DB OBJECTS ==="
mysql --defaults-extra-file="$TMP" -N -e "SELECT CONCAT('suppliers=',COUNT(*)) FROM suppliers; SELECT CONCAT('raw_tea_materials=',COUNT(*)) FROM raw_tea_materials; SELECT CONCAT('packaging_materials=',COUNT(*)) FROM packaging_materials; SELECT CONCAT('supplier_tables=',COUNT(*)) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('supplier_payments','supplier_returns','packaging_purchase_receipts','packaging_purchase_items','packaging_stock_ledger','product_packaging_bom','raw_tea_stock_ledger','production_batch_inputs');"

echo
echo "=== HEAD ==="
git -C "$REPO" rev-parse HEAD

echo
echo "=== API ==="
curl -fsS "https://teashop.bd/api/index.php?route=health"
echo
echo "PROCUREMENT_V2_DEPLOY_OK"
