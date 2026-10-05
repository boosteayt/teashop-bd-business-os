-- Tea Shop BD Business OS — Operations Command Center V2 / Round 1
-- Daily check-in + contract/renewal control. Existing field visits, pipeline and checklists remain authoritative.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS outlet_daily_checkins (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 checkin_date DATE NOT NULL,
 opening_status ENUM('pending','on_time','late','closed_for_day','exception') NOT NULL DEFAULT 'pending',
 opened_at DATETIME NULL,
 closing_status ENUM('pending','on_time','late','exception') NOT NULL DEFAULT 'pending',
 closed_at DATETIME NULL,
 opening_photo_ref VARCHAR(255) NULL,
 closing_photo_ref VARCHAR(255) NULL,
 manager_note TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_outlet_daily_checkin(franchise_id,checkin_date),
 INDEX idx_outlet_daily_checkin_date(checkin_date,opening_status,closing_status),
 INDEX idx_outlet_daily_checkin_franchise(franchise_id,checkin_date)
);

CREATE TABLE IF NOT EXISTS outlet_contracts (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 contract_type ENUM('franchise_agreement','lease','trade_license','food_license','fire_safety','tax_vat','other') NOT NULL DEFAULT 'other',
 document_no VARCHAR(120) NULL,
 start_date DATE NULL,
 expiry_date DATE NULL,
 renewal_status ENUM('active','due','renewing','renewed','expired','not_required') NOT NULL DEFAULT 'active',
 reminder_days INT UNSIGNED NOT NULL DEFAULT 30,
 evidence_ref VARCHAR(255) NULL,
 owner_note TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_outlet_contract_franchise(franchise_id,contract_type),
 INDEX idx_outlet_contract_expiry(expiry_date,renewal_status)
);
