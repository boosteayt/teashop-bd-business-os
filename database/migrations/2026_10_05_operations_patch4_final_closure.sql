-- Tea Shop BD Business OS — Operations Patch 4: Alerts, Performance & Final Closure
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS operations_alerts (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 alert_key VARCHAR(190) NOT NULL UNIQUE,
 franchise_id BIGINT UNSIGNED NULL,
 alert_type VARCHAR(80) NOT NULL,
 severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
 title VARCHAR(190) NOT NULL,
 message TEXT NOT NULL,
 status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
 source_type VARCHAR(80) NULL,
 source_id BIGINT UNSIGNED NULL,
 assigned_user_id BIGINT UNSIGNED NULL,
 detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 acknowledged_at DATETIME NULL,
 resolved_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_ops_alert_status(status,severity,last_seen_at),
 INDEX idx_ops_alert_franchise(franchise_id,status),
 INDEX idx_ops_alert_assignee(assigned_user_id,status)
);

CREATE TABLE IF NOT EXISTS operations_report_snapshots (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 report_type VARCHAR(80) NOT NULL DEFAULT 'network_monthly',
 payload_json JSON NOT NULL,
 generated_by BIGINT UNSIGNED NULL,
 generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ops_report_period(period_start,period_end,report_type)
);
