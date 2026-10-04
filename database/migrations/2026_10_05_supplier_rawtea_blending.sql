-- Tea Shop BD Business OS
-- Supplier accounting + raw tea stock + multi-component blending
-- Safe additive migration for existing production database.

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
  ADD COLUMN raw_tea_material_id BIGINT UNSIGNED NULL AFTER product_id,
  ADD KEY idx_purchase_raw_tea(raw_tea_material_id),
  ADD CONSTRAINT fk_purchase_raw_tea FOREIGN KEY(raw_tea_material_id) REFERENCES raw_tea_materials(id);

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
 FOREIGN KEY(raw_tea_material_id) REFERENCES raw_tea_materials(id),
 KEY idx_raw_stock_material(raw_tea_material_id),
 KEY idx_raw_stock_time(created_at)
);

CREATE TABLE IF NOT EXISTS production_batch_inputs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 production_batch_id BIGINT UNSIGNED NOT NULL,
 raw_tea_material_id BIGINT UNSIGNED NOT NULL,
 qty_kg DECIMAL(12,3) NOT NULL,
 unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
 FOREIGN KEY(production_batch_id) REFERENCES production_batches(id),
 FOREIGN KEY(raw_tea_material_id) REFERENCES raw_tea_materials(id),
 KEY idx_blend_input_batch(production_batch_id),
 KEY idx_blend_input_material(raw_tea_material_id)
);

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
 FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 KEY idx_supplier_payment_supplier(supplier_id),
 KEY idx_supplier_payment_date(payment_date)
);
