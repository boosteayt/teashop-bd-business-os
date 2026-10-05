-- Tea Shop BD Business OS: role-based lifecycle + control modules
SET NAMES utf8mb4;

INSERT IGNORE INTO roles(code,name) VALUES
('OWNER','Founder / Owner / CEO'),
('OPERATIONS','Franchise & Retail Operations'),
('FINANCE','Finance & Accounts'),
('WAREHOUSE','Warehouse & Inventory'),
('PRODUCTION','Production & Blending'),
('QC','Quality Control'),
('PACKAGING','Packaging'),
('REGIONAL','Regional Operations'),
('FRANCHISE','Franchise Owner'),
('OUTLET_MANAGER','Outlet Manager'),
('CASHIER','POS / Cashier'),
('AUDITOR','Auditor / Read Only');

INSERT INTO system_settings(setting_key,setting_value)
SELECT 'management_share_active_tier',setting_value FROM system_settings WHERE setting_key='faruk_active_tier'
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO system_settings(setting_key,setting_value)
SELECT 'management_share_manual_percent',setting_value FROM system_settings WHERE setting_key='faruk_manual_percent'
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT IGNORE INTO system_settings(setting_key,setting_value) VALUES
('management_share_active_tier','Base'),
('management_share_manual_percent','20'),
('franchise_margin_starter','25'),
('franchise_margin_growth','27'),
('franchise_margin_elite','30');

UPDATE performance_share_rules
SET person_key='FRANCHISE_RETAIL_OPERATIONS'
WHERE person_key='OMAR_FARUK';

CREATE TABLE IF NOT EXISTS product_price_history (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 old_mrp DECIMAL(14,2) NULL,
 new_mrp DECIMAL(14,2) NOT NULL,
 reason VARCHAR(255) NULL,
 effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 changed_by BIGINT UNSIGNED NULL,
 INDEX idx_pph_pack_date(product_pack_id,effective_at)
);

CREATE TABLE IF NOT EXISTS qc_checks (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 production_batch_id BIGINT UNSIGNED NULL,
 check_type VARCHAR(60) NOT NULL DEFAULT 'finished_tea',
 status ENUM('pending','passed','hold','failed') NOT NULL DEFAULT 'pending',
 moisture_percent DECIMAL(8,3) NULL,
 sample_weight_kg DECIMAL(12,3) NULL,
 notes TEXT NULL,
 checked_by BIGINT UNSIGNED NULL,
 checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_qc_batch(production_batch_id),
 INDEX idx_qc_date(checked_at)
);

CREATE TABLE IF NOT EXISTS wastage_records (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 reference_type VARCHAR(50) NOT NULL,
 reference_id BIGINT UNSIGNED NULL,
 product_id BIGINT UNSIGNED NULL,
 qty_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
 value_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 reason VARCHAR(160) NULL,
 status ENUM('draft','approved','rejected') NOT NULL DEFAULT 'draft',
 approved_by BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_waste_ref(reference_type,reference_id),
 INDEX idx_waste_product(product_id)
);

CREATE TABLE IF NOT EXISTS franchise_margin_history (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 margin_mode ENUM('tier','manual') NOT NULL DEFAULT 'tier',
 margin_tier ENUM('Starter','Growth','Elite','Manual') NOT NULL DEFAULT 'Starter',
 margin_percent DECIMAL(5,2) NOT NULL,
 reason VARCHAR(255) NULL,
 effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 effective_to DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL,
 INDEX idx_fmh_franchise_date(franchise_id,effective_from)
);

CREATE TABLE IF NOT EXISTS outlet_health_checks (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 sales_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 stock_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 settlement_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 compliance_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 total_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 health ENUM('healthy','watch','critical','new') NOT NULL DEFAULT 'new',
 notes TEXT NULL,
 checked_by BIGINT UNSIGNED NULL,
 checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ohc_franchise_date(franchise_id,checked_at)
);

CREATE TABLE IF NOT EXISTS customers (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NULL,
 name VARCHAR(160) NOT NULL,
 phone VARCHAR(40) NULL,
 email VARCHAR(190) NULL,
 loyalty_points DECIMAL(14,2) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_customer_franchise(franchise_id),
 INDEX idx_customer_phone(phone)
);

CREATE TABLE IF NOT EXISTS corporate_orders (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 order_no VARCHAR(60) NOT NULL UNIQUE,
 company_name VARCHAR(180) NOT NULL,
 contact_name VARCHAR(160) NULL,
 contact_phone VARCHAR(40) NULL,
 order_date DATE NOT NULL,
 total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 status ENUM('lead','quoted','confirmed','processing','delivered','cancelled') NOT NULL DEFAULT 'lead',
 referral_source VARCHAR(160) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS logistics_shipments (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 shipment_no VARCHAR(60) NOT NULL UNIQUE,
 source_type VARCHAR(40) NOT NULL DEFAULT 'central',
 source_id BIGINT UNSIGNED NULL,
 destination_type VARCHAR(40) NOT NULL DEFAULT 'franchise',
 destination_id BIGINT UNSIGNED NULL,
 carrier VARCHAR(160) NULL,
 challan_no VARCHAR(100) NULL,
 delivery_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
 dispatch_at DATETIME NULL,
 received_at DATETIME NULL,
 status ENUM('draft','dispatched','in_transit','received','issue','cancelled') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_logistics_destination(destination_type,destination_id)
);

CREATE TABLE IF NOT EXISTS approvals (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 approval_type VARCHAR(80) NOT NULL,
 reference_type VARCHAR(80) NOT NULL,
 reference_id BIGINT UNSIGNED NULL,
 requested_by BIGINT UNSIGNED NULL,
 assigned_role_code VARCHAR(40) NULL,
 status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
 request_json JSON NULL,
 decision_note VARCHAR(255) NULL,
 decided_by BIGINT UNSIGNED NULL,
 decided_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_approval_status(status,created_at)
);

CREATE TABLE IF NOT EXISTS documents (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 document_type VARCHAR(80) NOT NULL,
 title VARCHAR(190) NOT NULL,
 reference_type VARCHAR(80) NULL,
 reference_id BIGINT UNSIGNED NULL,
 file_path VARCHAR(255) NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'active',
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_document_ref(reference_type,reference_id)
);

CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NULL,
 role_code VARCHAR(40) NULL,
 severity ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
 title VARCHAR(190) NOT NULL,
 message TEXT NOT NULL,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notification_user(user_id,read_at),
 INDEX idx_notification_role(role_code,read_at)
);

CREATE TABLE IF NOT EXISTS performance_records (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 role_code VARCHAR(40) NOT NULL,
 user_id BIGINT UNSIGNED NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 sales_growth_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 stock_rotation_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 outlet_health_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 settlement_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 retention_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 compliance_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 total_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 distributable_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
 performance_share_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
 performance_share_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 status ENUM('draft','review','approved','paid') NOT NULL DEFAULT 'draft',
 approved_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_perf_role_period(role_code,period_start,period_end)
);
