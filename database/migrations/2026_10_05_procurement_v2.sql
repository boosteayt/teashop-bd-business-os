-- Tea Shop BD Business OS
-- Procurement v2: all suppliers, supplier ledger/reports, packaging procurement/stock/BOM,
-- raw-tea kg stock and blend costing. Safe additive migration for existing database.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS raw_tea_materials (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 code VARCHAR(50) UNIQUE NOT NULL,
 name VARCHAR(160) NOT NULL,
 tea_type VARCHAR(80) NULL,
 origin VARCHAR(120) NULL,
 active TINYINT(1) DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE purchase_items
  ADD COLUMN IF NOT EXISTS raw_tea_material_id BIGINT UNSIGNED NULL AFTER product_id;

ALTER TABLE purchase_receipts
  ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(100) NULL AFTER reference_no,
  ADD COLUMN IF NOT EXISTS challan_no VARCHAR(100) NULL AFTER invoice_no,
  ADD COLUMN IF NOT EXISTS due_date DATE NULL AFTER received_date;

ALTER TABLE suppliers
  ADD COLUMN IF NOT EXISTS contact_person VARCHAR(120) NULL AFTER name,
  ADD COLUMN IF NOT EXISTS payment_terms_days INT NOT NULL DEFAULT 0 AFTER address,
  ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER payment_terms_days,
  ADD COLUMN IF NOT EXISTS opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER credit_limit;

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
);

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
);

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
);

CREATE TABLE IF NOT EXISTS production_batch_inputs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 production_batch_id BIGINT UNSIGNED NOT NULL,
 raw_tea_material_id BIGINT UNSIGNED NOT NULL,
 qty_kg DECIMAL(12,3) NOT NULL,
 unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
 KEY idx_blend_input_batch(production_batch_id),
 KEY idx_blend_input_material(raw_tea_material_id)
);

ALTER TABLE packaging_materials
  ADD COLUMN IF NOT EXISTS reorder_level DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER stock_qty,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER active;

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
);

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
);

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
);

CREATE TABLE IF NOT EXISTS product_packaging_bom (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 packaging_material_id BIGINT UNSIGNED NOT NULL,
 qty_per_pack DECIMAL(12,4) NOT NULL,
 UNIQUE KEY uq_packaging_bom(product_pack_id,packaging_material_id),
 KEY idx_bom_material(packaging_material_id)
);
