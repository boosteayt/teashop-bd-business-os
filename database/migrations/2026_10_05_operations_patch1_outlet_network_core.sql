-- Tea Shop BD Business OS — Operations Patch 1: Outlet Network Core
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS outlet_profiles (
 franchise_id BIGINT UNSIGNED PRIMARY KEY,
 owner_name VARCHAR(160) NULL,
 owner_phone VARCHAR(50) NULL,
 owner_email VARCHAR(190) NULL,
 division VARCHAR(100) NULL,
 territory_code VARCHAR(80) NULL,
 shop_type VARCHAR(80) NULL,
 shop_size_sqft DECIMAL(10,2) NULL,
 agreement_no VARCHAR(100) NULL,
 agreement_date DATE NULL,
 lease_start DATE NULL,
 lease_end DATE NULL,
 target_open_date DATE NULL,
 operational_state ENUM('normal','suspended') NOT NULL DEFAULT 'normal',
 suspended_at DATETIME NULL,
 suspension_reason VARCHAR(255) NULL,
 closure_reason VARCHAR(255) NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_outlet_profile_division(division),
 INDEX idx_outlet_profile_territory(territory_code)
);

CREATE TABLE IF NOT EXISTS outlet_pipeline (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 stage ENUM('lead','verification','agreement','shop_ready','training','stock_ready','pos_ready','launch','live','suspended','closed') NOT NULL DEFAULT 'lead',
 target_open_date DATE NULL,
 next_action VARCHAR(255) NULL,
 blocking_reason VARCHAR(255) NULL,
 assigned_user_id BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_outlet_pipeline_franchise(franchise_id),
 INDEX idx_outlet_pipeline_stage(stage),
 INDEX idx_outlet_pipeline_target(target_open_date)
);

CREATE TABLE IF NOT EXISTS outlet_checklist_items (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 checklist_type ENUM('opening','closure') NOT NULL,
 item_code VARCHAR(80) NOT NULL,
 item_label VARCHAR(190) NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 required TINYINT(1) NOT NULL DEFAULT 1,
 completed TINYINT(1) NOT NULL DEFAULT 0,
 completed_at DATETIME NULL,
 completed_by BIGINT UNSIGNED NULL,
 notes VARCHAR(255) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_outlet_checklist_item(franchise_id,checklist_type,item_code),
 INDEX idx_outlet_checklist(franchise_id,checklist_type,completed)
);

CREATE TABLE IF NOT EXISTS outlet_staff (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 staff_role VARCHAR(100) NOT NULL,
 phone VARCHAR(50) NULL,
 email VARCHAR(190) NULL,
 joined_at DATE NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 notes VARCHAR(255) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_outlet_staff_franchise(franchise_id,active)
);

CREATE TABLE IF NOT EXISTS outlet_training_records (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 outlet_staff_id BIGINT UNSIGNED NULL,
 course_code VARCHAR(80) NULL,
 course_title VARCHAR(190) NOT NULL,
 status ENUM('pending','scheduled','completed','expired') NOT NULL DEFAULT 'pending',
 scheduled_at DATETIME NULL,
 completed_at DATETIME NULL,
 expires_at DATE NULL,
 trainer VARCHAR(160) NULL,
 certificate_ref VARCHAR(160) NULL,
 notes VARCHAR(255) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_outlet_training_franchise(franchise_id,status),
 INDEX idx_outlet_training_staff(outlet_staff_id)
);

CREATE TABLE IF NOT EXISTS outlet_timeline (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 title VARCHAR(190) NOT NULL,
 detail TEXT NULL,
 reference_type VARCHAR(80) NULL,
 reference_id BIGINT UNSIGNED NULL,
 payload_json JSON NULL,
 occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_by BIGINT UNSIGNED NULL,
 INDEX idx_outlet_timeline(franchise_id,occurred_at),
 INDEX idx_outlet_timeline_type(franchise_id,event_type)
);
