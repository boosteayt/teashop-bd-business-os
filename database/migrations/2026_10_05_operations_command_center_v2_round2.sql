-- Tea Shop BD Business OS — Operations Command Center V2 / Round 2
-- Intelligence & Automation: idempotent auto-task links + settlement collection follow-up.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS operations_automation_links (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 automation_key VARCHAR(190) NOT NULL UNIQUE,
 franchise_id BIGINT UNSIGNED NULL,
 rule_code VARCHAR(80) NOT NULL,
 task_id BIGINT UNSIGNED NULL,
 source_type VARCHAR(80) NULL,
 source_id BIGINT UNSIGNED NULL,
 detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_ops_auto_franchise(franchise_id,rule_code,resolved_at),
 INDEX idx_ops_auto_task(task_id)
);

CREATE TABLE IF NOT EXISTS settlement_followups (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 settlement_id BIGINT UNSIGNED NOT NULL,
 franchise_id BIGINT UNSIGNED NOT NULL,
 contact_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 channel ENUM('call','whatsapp','email','meeting','visit','internal_note','other') NOT NULL DEFAULT 'call',
 status ENUM('contacted','promised','partial','paid_confirmed','disputed','escalated') NOT NULL DEFAULT 'contacted',
 promised_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 promised_date DATE NULL,
 note TEXT NULL,
 evidence_ref VARCHAR(255) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_settlement_followup_settlement(settlement_id,contact_at),
 INDEX idx_settlement_followup_franchise(franchise_id,contact_at),
 INDEX idx_settlement_followup_promise(promised_date,status)
);
