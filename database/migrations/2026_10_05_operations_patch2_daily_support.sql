-- Tea Shop BD Business OS — Operations Patch 2: Daily Operations & Support
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS operations_tasks (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NULL,
 task_type VARCHAR(80) NOT NULL DEFAULT 'follow_up',
 title VARCHAR(190) NOT NULL,
 detail TEXT NULL,
 priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
 status ENUM('open','in_progress','waiting','done','cancelled') NOT NULL DEFAULT 'open',
 assigned_user_id BIGINT UNSIGNED NULL,
 due_at DATETIME NULL,
 completed_at DATETIME NULL,
 source_type VARCHAR(80) NULL,
 source_id BIGINT UNSIGNED NULL,
 escalation_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
 escalated_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_ops_task_status(status,due_at),
 INDEX idx_ops_task_franchise(franchise_id,status),
 INDEX idx_ops_task_assignee(assigned_user_id,status)
);

CREATE TABLE IF NOT EXISTS field_visits (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 visit_type VARCHAR(80) NOT NULL DEFAULT 'routine',
 status ENUM('scheduled','completed','cancelled','follow_up') NOT NULL DEFAULT 'scheduled',
 scheduled_at DATETIME NULL,
 visited_at DATETIME NULL,
 visitor_user_id BIGINT UNSIGNED NULL,
 cleanliness_score DECIMAL(6,2) NULL,
 branding_score DECIMAL(6,2) NULL,
 product_display_score DECIMAL(6,2) NULL,
 pricing_compliance_score DECIMAL(6,2) NULL,
 pos_usage_score DECIMAL(6,2) NULL,
 stock_handling_score DECIMAL(6,2) NULL,
 overall_score DECIMAL(6,2) NULL,
 findings TEXT NULL,
 corrective_action TEXT NULL,
 evidence_ref VARCHAR(255) NULL,
 next_visit_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_field_visit_franchise(franchise_id,scheduled_at),
 INDEX idx_field_visit_status(status,scheduled_at),
 INDEX idx_field_visit_visitor(visitor_user_id,status)
);

CREATE TABLE IF NOT EXISTS support_tickets (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 ticket_no VARCHAR(60) NOT NULL UNIQUE,
 franchise_id BIGINT UNSIGNED NOT NULL,
 category ENUM('stock','pos','delivery','customer','branding','payment','staff_training','other') NOT NULL DEFAULT 'other',
 subject VARCHAR(190) NOT NULL,
 detail TEXT NULL,
 priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
 status ENUM('open','assigned','in_progress','waiting','resolved','closed','cancelled') NOT NULL DEFAULT 'open',
 assigned_user_id BIGINT UNSIGNED NULL,
 opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 due_at DATETIME NULL,
 first_response_at DATETIME NULL,
 resolved_at DATETIME NULL,
 resolution TEXT NULL,
 escalation_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
 escalated_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_ticket_franchise(franchise_id,status),
 INDEX idx_ticket_status(status,due_at),
 INDEX idx_ticket_assignee(assigned_user_id,status)
);

CREATE TABLE IF NOT EXISTS support_ticket_updates (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 support_ticket_id BIGINT UNSIGNED NOT NULL,
 update_type ENUM('note','status','assignment','escalation','resolution') NOT NULL DEFAULT 'note',
 note TEXT NULL,
 old_status VARCHAR(40) NULL,
 new_status VARCHAR(40) NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ticket_update_ticket(support_ticket_id,created_at)
);

CREATE TABLE IF NOT EXISTS outlet_communications (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 channel ENUM('call','whatsapp','email','meeting','visit','internal_note','other') NOT NULL DEFAULT 'call',
 direction ENUM('inbound','outbound','internal') NOT NULL DEFAULT 'outbound',
 subject VARCHAR(190) NULL,
 note TEXT NOT NULL,
 promised_date DATE NULL,
 follow_up_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_outlet_comm_franchise(franchise_id,created_at),
 INDEX idx_outlet_comm_followup(follow_up_at)
);

CREATE TABLE IF NOT EXISTS outlet_compliance_checks (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 field_visit_id BIGINT UNSIGNED NULL,
 branding_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 pricing_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 pos_usage_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 stock_handling_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 customer_service_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 overall_score DECIMAL(6,2) NOT NULL DEFAULT 0,
 status ENUM('compliant','watch','non_compliant') NOT NULL DEFAULT 'watch',
 findings TEXT NULL,
 corrective_action TEXT NULL,
 corrective_due_at DATETIME NULL,
 resolved_at DATETIME NULL,
 checked_by BIGINT UNSIGNED NULL,
 checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_compliance_franchise(franchise_id,checked_at),
 INDEX idx_compliance_status(status,corrective_due_at)
);

CREATE TABLE IF NOT EXISTS marketing_executions (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 campaign_code VARCHAR(80) NULL,
 campaign_name VARCHAR(190) NOT NULL,
 status ENUM('planned','ready','live','completed','not_participating') NOT NULL DEFAULT 'planned',
 priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
 assigned_user_id BIGINT UNSIGNED NULL,
 due_at DATETIME NULL,
 escalation_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
 escalated_at DATETIME NULL,
 start_date DATE NULL,
 end_date DATE NULL,
 assets_ready TINYINT(1) NOT NULL DEFAULT 0,
 execution_verified TINYINT(1) NOT NULL DEFAULT 0,
 sales_before DECIMAL(14,2) NOT NULL DEFAULT 0,
 sales_during DECIMAL(14,2) NOT NULL DEFAULT 0,
 notes TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_marketing_franchise(franchise_id,status),
 INDEX idx_marketing_assignee(assigned_user_id,status),
 INDEX idx_marketing_due(status,due_at),
 INDEX idx_marketing_period(start_date,end_date)
);
