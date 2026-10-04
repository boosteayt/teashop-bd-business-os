#!/usr/bin/env bash
set -euo pipefail

CPUSER="$(whoami)"
ROOT="/home/${CPUSER}"
REPO="${ROOT}/repositories/teashop-bd-business-os"
LIVE="${ROOT}/teashop.bd"
CONFIG="${ROOT}/teashop-os-config.php"
MIGRATOR="${REPO}/server/migrate-procurement-v2.php"

PHPCLI="/opt/cpanel/ea-php82/root/usr/bin/php"
if [ ! -x "$PHPCLI" ]; then
  for candidate in /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
    if [ -x "$candidate" ]; then PHPCLI="$candidate"; break; fi
  done
fi

[ -x "$PHPCLI" ] || { echo "ERROR: PHP CLI not found"; exit 1; }
[ -f "$CONFIG" ] || { echo "ERROR: secure DB config not found"; exit 1; }
[ -f "$MIGRATOR" ] || { echo "ERROR: procurement migrator not found"; exit 1; }

echo "Applying idempotent procurement v2 migration..."
"$PHPCLI" "$MIGRATOR"

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
echo "=== DB VERIFY ==="
"$PHPCLI" <<'PHP'
<?php
$c=require getenv('HOME').'/teashop-os-config.php';
$p=new PDO($c['dsn'],$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=['supplier_payments','supplier_returns','raw_tea_materials','raw_tea_stock_ledger','production_batch_inputs','packaging_purchase_receipts','packaging_purchase_items','packaging_stock_ledger','product_packaging_bom'];
$in="'".implode("','",$tables)."'";
echo "procurement_tables=".$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ({$in})")->fetchColumn().PHP_EOL;
echo "suppliers=".$p->query("SELECT COUNT(*) FROM suppliers")->fetchColumn().PHP_EOL;
echo "raw_tea_materials=".$p->query("SELECT COUNT(*) FROM raw_tea_materials")->fetchColumn().PHP_EOL;
echo "packaging_materials=".$p->query("SELECT COUNT(*) FROM packaging_materials")->fetchColumn().PHP_EOL;
PHP

echo
echo "=== HEAD ==="
git -C "$REPO" rev-parse HEAD

echo
echo "=== API ==="
curl -fsS "https://teashop.bd/api/index.php?route=health"
echo
echo "PROCUREMENT_V2_DEPLOY_OK"
