-- Tea Shop BD Business OS / MySQL 8+ production foundation
SET NAMES utf8mb4;

CREATE TABLE roles (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 code VARCHAR(40) UNIQUE NOT NULL,
 name VARCHAR(100) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 role_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) UNIQUE NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 must_change_password TINYINT(1) DEFAULT 1,
 active TINYINT(1) DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(role_id) REFERENCES roles(id)
);

CREATE TABLE user_franchise_scope (
 user_id BIGINT UNSIGNED NOT NULL,
 franchise_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,franchise_id)
);

CREATE TABLE suppliers (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 supplier_type ENUM('tea','packaging','logistics','other') NOT NULL,
 name VARCHAR(160) NOT NULL,
 contact_person VARCHAR(120) NULL,
 phone VARCHAR(50), email VARCHAR(190), address VARCHAR(255),
 payment_terms_days INT NOT NULL DEFAULT 0,
 credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
 opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
 active TINYINT(1) DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE raw_tea_materials (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 code VARCHAR(50) UNIQUE NOT NULL,
 name VARCHAR(160) NOT NULL,
 tea_type VARCHAR(80) NULL,
 origin VARCHAR(120) NULL,
 active TINYINT(1) DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE products (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 sku VARCHAR(50) UNIQUE NOT NULL,
 name VARCHAR(160) NOT NULL,
 category VARCHAR(80) NOT NULL,
 purchase_cost_per_kg DECIMAL(12,2) DEFAULT 0,
 active TINYINT(1) DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE product_packs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 product_id BIGINT UNSIGNED NOT NULL,
 grams INT NOT NULL,
 packaging_bom JSON NULL,
 mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
 active TINYINT(1) DEFAULT 1,
 UNIQUE KEY uq_product_pack(product_id,grams),
 FOREIGN KEY(product_id) REFERENCES products(id)
);

CREATE TABLE purchase_receipts (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 supplier_id BIGINT UNSIGNED NULL,
 reference_no VARCHAR(80),
 invoice_no VARCHAR(100) NULL,
 challan_no VARCHAR(100) NULL,
 received_date DATE NOT NULL,
 due_date DATE NULL,
 status ENUM('draft','received','cancelled') DEFAULT 'received',
 total_amount DECIMAL(14,2) DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE purchase_items (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 purchase_receipt_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NULL,
 raw_tea_material_id BIGINT UNSIGNED NULL,
 material_name VARCHAR(160) NOT NULL,
 quantity_kg DECIMAL(12,3) DEFAULT 0,
 unit_rate DECIMAL(12,2) DEFAULT 0,
 batch_no VARCHAR(80),
 FOREIGN KEY(purchase_receipt_id) REFERENCES purchase_receipts(id),
 FOREIGN KEY(raw_tea_material_id) REFERENCES raw_tea_materials(id)
);

CREATE TABLE supplier_payments (
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

CREATE TABLE supplier_returns (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 supplier_id BIGINT UNSIGNED NOT NULL,
 return_date DATE NOT NULL,
 source_type ENUM('tea','packaging','other') NOT NULL,
 amount DECIMAL(14,2) NOT NULL,
 reference_no VARCHAR(100) NULL,
 memo VARCHAR(255) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 KEY idx_supplier_return_supplier(supplier_id),
 KEY idx_supplier_return_date(return_date)
);

CREATE TABLE production_batches (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 batch_no VARCHAR(80) UNIQUE NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 input_kg DECIMAL(12,3) NOT NULL,
 output_kg DECIMAL(12,3) NOT NULL,
 wastage_kg DECIMAL(12,3) NOT NULL DEFAULT 0,
 qc_status ENUM('pending','pass','hold','reject') DEFAULT 'pending',
 produced_at DATETIME NOT NULL,
 approved_by BIGINT UNSIGNED NULL,
 FOREIGN KEY(product_id) REFERENCES products(id)
);

CREATE TABLE production_batch_inputs (
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

CREATE TABLE raw_tea_stock_ledger (
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

CREATE TABLE packaging_materials (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 code VARCHAR(50) UNIQUE NOT NULL,
 name VARCHAR(160) NOT NULL,
 unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
 unit_cost DECIMAL(12,4) DEFAULT 0,
 stock_qty DECIMAL(14,3) DEFAULT 0,
 reorder_level DECIMAL(14,3) NOT NULL DEFAULT 0,
 active TINYINT(1) DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE packaging_purchase_receipts (
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
 FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
 KEY idx_pack_purchase_supplier(supplier_id),
 KEY idx_pack_purchase_date(received_date)
);

CREATE TABLE packaging_purchase_items (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 packaging_purchase_receipt_id BIGINT UNSIGNED NOT NULL,
 packaging_material_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(14,3) NOT NULL,
 unit_rate DECIMAL(12,4) NOT NULL,
 line_total DECIMAL(14,2) NOT NULL,
 batch_no VARCHAR(80) NULL,
 FOREIGN KEY(packaging_purchase_receipt_id) REFERENCES packaging_purchase_receipts(id),
 FOREIGN KEY(packaging_material_id) REFERENCES packaging_materials(id),
 KEY idx_pack_purchase_item_receipt(packaging_purchase_receipt_id),
 KEY idx_pack_purchase_item_material(packaging_material_id)
);

CREATE TABLE packaging_stock_ledger (
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
 FOREIGN KEY(packaging_material_id) REFERENCES packaging_materials(id),
 KEY idx_pack_stock_material(packaging_material_id),
 KEY idx_pack_stock_time(created_at)
);

CREATE TABLE product_packaging_bom (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 packaging_material_id BIGINT UNSIGNED NOT NULL,
 qty_per_pack DECIMAL(12,4) NOT NULL,
 UNIQUE KEY uq_packaging_bom(product_pack_id,packaging_material_id),
 FOREIGN KEY(product_pack_id) REFERENCES product_packs(id),
 FOREIGN KEY(packaging_material_id) REFERENCES packaging_materials(id),
 KEY idx_bom_material(packaging_material_id)
);

CREATE TABLE packaging_jobs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 job_no VARCHAR(80) UNIQUE NOT NULL,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 batch_no VARCHAR(80),
 pack_qty INT NOT NULL,
 labour_cost DECIMAL(12,2) DEFAULT 0,
 sealing_cost DECIMAL(12,2) DEFAULT 0,
 other_cost DECIMAL(12,2) DEFAULT 0,
 completed_at DATETIME NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 FOREIGN KEY(product_pack_id) REFERENCES product_packs(id)
);

CREATE TABLE franchises (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 code VARCHAR(30) UNIQUE NOT NULL,
 name VARCHAR(150) NOT NULL,
 district VARCHAR(100), upazila VARCHAR(100), address VARCHAR(255),
 status ENUM('pipeline','setup','active','watch','critical','closed') DEFAULT 'pipeline',
 margin_mode ENUM('tier','manual') DEFAULT 'tier',
 margin_tier ENUM('Starter','Growth','Elite','Manual') DEFAULT 'Starter',
 margin_percent DECIMAL(5,2) DEFAULT 25.00,
 opened_at DATE NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventory_ledger (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 location_type ENUM('central','franchise') NOT NULL,
 location_id BIGINT UNSIGNED NULL,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 movement_type ENUM('opening','production_in','transfer_out','transfer_in','sale','return','damage','adjustment') NOT NULL,
 qty DECIMAL(12,3) NOT NULL,
 unit_value DECIMAL(12,2) DEFAULT 0,
 reference_type VARCHAR(40), reference_id BIGINT UNSIGNED NULL,
 batch_no VARCHAR(80),
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(product_pack_id) REFERENCES product_packs(id),
 KEY idx_inventory_location(location_type,location_id),
 KEY idx_inventory_pack(product_pack_id)
);

CREATE TABLE pos_sales (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 receipt_no VARCHAR(60) UNIQUE NOT NULL,
 gross_amount DECIMAL(12,2) NOT NULL,
 eligible_amount DECIMAL(12,2) NOT NULL,
 margin_percent DECIMAL(5,2) NOT NULL,
 earned_margin DECIMAL(12,2) NOT NULL,
 payment_method VARCHAR(30),
 sold_at DATETIME NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 FOREIGN KEY(franchise_id) REFERENCES franchises(id),
 KEY idx_sale_period(franchise_id,sold_at)
);

CREATE TABLE pos_sale_items (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 pos_sale_id BIGINT UNSIGNED NOT NULL,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 qty DECIMAL(12,3) NOT NULL,
 unit_price DECIMAL(12,2) NOT NULL,
 line_total DECIMAL(12,2) NOT NULL,
 FOREIGN KEY(pos_sale_id) REFERENCES pos_sales(id),
 FOREIGN KEY(product_pack_id) REFERENCES product_packs(id)
);

CREATE TABLE settlements (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL, period_end DATE NOT NULL,
 opening_stock_value DECIMAL(12,2) DEFAULT 0,
 stock_received_value DECIMAL(12,2) DEFAULT 0,
 verified_sales DECIMAL(12,2) DEFAULT 0,
 approved_returns DECIMAL(12,2) DEFAULT 0,
 stock_adjustment DECIMAL(12,2) DEFAULT 0,
 closing_stock_value DECIMAL(12,2) DEFAULT 0,
 earned_margin DECIMAL(12,2) DEFAULT 0,
 tax_adjustment DECIMAL(12,2) DEFAULT 0,
 previous_balance DECIMAL(12,2) DEFAULT 0,
 net_payable DECIMAL(12,2) DEFAULT 0,
 status ENUM('draft','review','approved','locked','paid') DEFAULT 'draft',
 approved_by BIGINT UNSIGNED NULL,
 locked_at DATETIME NULL,
 UNIQUE KEY uq_settlement_period(franchise_id,period_start,period_end),
 FOREIGN KEY(franchise_id) REFERENCES franchises(id)
);

CREATE TABLE finance_ledger (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 entry_date DATE NOT NULL,
 division VARCHAR(60) NOT NULL,
 account_code VARCHAR(40) NOT NULL,
 entry_type ENUM('debit','credit') NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 reference_type VARCHAR(40), reference_id BIGINT UNSIGNED NULL,
 memo VARCHAR(255), created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_finance_date(entry_date)
);

CREATE TABLE performance_share_rules (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 person_key VARCHAR(60) NOT NULL,
 tier ENUM('Base','Growth','Elite','Manual') NOT NULL,
 percent DECIMAL(5,2) NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 approved_by BIGINT UNSIGNED NULL
);

CREATE TABLE system_settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 setting_value TEXT NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE audit_logs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 entity_type VARCHAR(80) NOT NULL,
 entity_id VARCHAR(80),
 before_json JSON NULL,
 after_json JSON NULL,
 ip_address VARCHAR(64),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_audit_entity(entity_type,entity_id),
 KEY idx_audit_time(created_at)
);
