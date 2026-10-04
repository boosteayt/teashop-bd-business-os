<?php
declare(strict_types=1);

$configFile=getenv('HOME').'/teashop-os-config.php';
if(!is_file($configFile)){fwrite(STDERR,"ERROR: secure DB config not found\n");exit(1);}
$c=require $configFile;
$pdo=new PDO($c['dsn'],$c['user'],$c['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

function columnExists(PDO $pdo,string $table,string $column): bool {
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$table,$column]);
    return (int)$q->fetchColumn()>0;
}
function addColumn(PDO $pdo,string $table,string $column,string $definition): void {
    if(!columnExists($pdo,$table,$column)){
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "ADD_COLUMN {$table}.{$column}\n";
    }
}
function createTable(PDO $pdo,string $sql,string $name): void {
    $pdo->exec($sql);
    echo "TABLE_OK {$name}\n";
}

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS raw_tea_materials (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 code VARCHAR(50) UNIQUE NOT NULL,
 name VARCHAR(160) NOT NULL,
 tea_type VARCHAR(80) NULL,
 origin VARCHAR(120) NULL,
 active TINYINT(1) DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
SQL,'raw_tea_materials');

addColumn($pdo,'purchase_items','raw_tea_material_id','BIGINT UNSIGNED NULL AFTER product_id');
addColumn($pdo,'purchase_receipts','invoice_no','VARCHAR(100) NULL AFTER reference_no');
addColumn($pdo,'purchase_receipts','challan_no','VARCHAR(100) NULL AFTER invoice_no');
addColumn($pdo,'purchase_receipts','due_date','DATE NULL AFTER received_date');

addColumn($pdo,'suppliers','contact_person','VARCHAR(120) NULL AFTER name');
addColumn($pdo,'suppliers','payment_terms_days','INT NOT NULL DEFAULT 0 AFTER address');
addColumn($pdo,'suppliers','credit_limit','DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER payment_terms_days');
addColumn($pdo,'suppliers','opening_balance','DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER credit_limit');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS supplier_payments (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 supplier_id BIGINT UNSIGNED NOT NULL,
 payment_date DATE NOT NULL,
 amount DECIMAL(14,2) NOT NULL,
 payment_method VARCHAR(40) NULL,
 reference_no VARCHAR(100) NULL,
 memo VARCHAR(255) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_supplier_payment_supplier(supplier_id),
 KEY idx_supplier_payment_date(payment_date)
)
SQL,'supplier_payments');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS supplier_returns (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 supplier_id BIGINT UNSIGNED NOT NULL,
 return_date DATE NOT NULL,
 source_type ENUM('tea','packaging','other') NOT NULL,
 amount DECIMAL(14,2) NOT NULL,
 reference_no VARCHAR(100) NULL,
 memo VARCHAR(255) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_supplier_return_supplier(supplier_id),
 KEY idx_supplier_return_date(return_date)
)
SQL,'supplier_returns');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS raw_tea_stock_ledger (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 raw_tea_material_id BIGINT UNSIGNED NOT NULL,
 movement_type ENUM('opening','purchase_in','blend_out','return_out','adjustment_in','adjustment_out') NOT NULL,
 qty_kg DECIMAL(14,3) NOT NULL,
 unit_cost DECIMAL(12,2) DEFAULT 0,
 reference_type VARCHAR(40) NULL,
 reference_id BIGINT UNSIGNED NULL,
 batch_no VARCHAR(80) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_raw_stock_material(raw_tea_material_id),
 KEY idx_raw_stock_time(created_at)
)
SQL,'raw_tea_stock_ledger');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS production_batch_inputs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 production_batch_id BIGINT UNSIGNED NOT NULL,
 raw_tea_material_id BIGINT UNSIGNED NOT NULL,
 qty_kg DECIMAL(12,3) NOT NULL,
 unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
 KEY idx_blend_input_batch(production_batch_id),
 KEY idx_blend_input_material(raw_tea_material_id)
)
SQL,'production_batch_inputs');

addColumn($pdo,'packaging_materials','reorder_level','DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER stock_qty');
addColumn($pdo,'packaging_materials','created_at','TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER active');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS packaging_purchase_receipts (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 supplier_id BIGINT UNSIGNED NOT NULL,
 reference_no VARCHAR(80) NOT NULL,
 invoice_no VARCHAR(100) NULL,
 challan_no VARCHAR(100) NULL,
 received_date DATE NOT NULL,
 due_date DATE NULL,
 status ENUM('draft','received','cancelled') DEFAULT 'received',
 total_amount DECIMAL(14,2) DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_pack_purchase_supplier(supplier_id),
 KEY idx_pack_purchase_date(received_date)
)
SQL,'packaging_purchase_receipts');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS packaging_purchase_items (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 packaging_purchase_receipt_id BIGINT UNSIGNED NOT NULL,
 packaging_material_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(14,3) NOT NULL,
 unit_rate DECIMAL(12,4) NOT NULL,
 line_total DECIMAL(14,2) NOT NULL,
 batch_no VARCHAR(80) NULL,
 KEY idx_pack_purchase_item_receipt(packaging_purchase_receipt_id),
 KEY idx_pack_purchase_item_material(packaging_material_id)
)
SQL,'packaging_purchase_items');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS packaging_stock_ledger (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 packaging_material_id BIGINT UNSIGNED NOT NULL,
 movement_type ENUM('opening','purchase_in','production_out','return_out','damage_out','adjustment_in','adjustment_out') NOT NULL,
 qty DECIMAL(14,3) NOT NULL,
 unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
 reference_type VARCHAR(40) NULL,
 reference_id BIGINT UNSIGNED NULL,
 batch_no VARCHAR(80) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_pack_stock_material(packaging_material_id),
 KEY idx_pack_stock_time(created_at)
)
SQL,'packaging_stock_ledger');

createTable($pdo,<<<'SQL'
CREATE TABLE IF NOT EXISTS product_packaging_bom (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 packaging_material_id BIGINT UNSIGNED NOT NULL,
 qty_per_pack DECIMAL(12,4) NOT NULL,
 UNIQUE KEY uq_packaging_bom(product_pack_id,packaging_material_id),
 KEY idx_bom_material(packaging_material_id)
)
SQL,'product_packaging_bom');

$required=['supplier_payments','supplier_returns','raw_tea_materials','raw_tea_stock_ledger','production_batch_inputs','packaging_purchase_receipts','packaging_purchase_items','packaging_stock_ledger','product_packaging_bom'];
$in="'".implode("','",$required)."'";
$count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ({$in})")->fetchColumn();
if($count!==count($required)){fwrite(STDERR,"MIGRATION_VERIFY_FAILED tables={$count}\n");exit(2);}
echo "MIGRATION_OK tables={$count}\n";
