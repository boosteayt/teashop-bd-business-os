-- Tea Shop BD Business OS — Operations Command Center V2 / Round 3
-- Management Closure: benchmark snapshots, routed notifications, closure handover control.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS operations_benchmark_snapshots (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 snapshot_type VARCHAR(80) NOT NULL DEFAULT 'management_closure',
 payload_json JSON NOT NULL,
 generated_by BIGINT UNSIGNED NULL,
 generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ops_benchmark_period(period_start,period_end,snapshot_type)
);

CREATE TABLE IF NOT EXISTS operations_notification_routes (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 source_type VARCHAR(80) NULL,
 source_id BIGINT UNSIGNED NULL,
 franchise_id BIGINT UNSIGNED NULL,
 target_role_code VARCHAR(40) NOT NULL,
 severity ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
 title VARCHAR(190) NOT NULL,
 message TEXT NOT NULL,
 notification_id BIGINT UNSIGNED NULL,
 route_status ENUM('sent','acknowledged','closed') NOT NULL DEFAULT 'sent',
 routed_by BIGINT UNSIGNED NULL,
 routed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ops_route_target(target_role_code,route_status,routed_at),
 INDEX idx_ops_route_franchise(franchise_id,routed_at)
);

CREATE TABLE IF NOT EXISTS operations_closure_handovers (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 handover_status ENUM('preparing','ready','approved','reopened') NOT NULL DEFAULT 'preparing',
 stock_status ENUM('pending','counted','reconciled','returned') NOT NULL DEFAULT 'pending',
 dues_status ENUM('pending','review','reconciled') NOT NULL DEFAULT 'pending',
 documents_status ENUM('pending','partial','complete') NOT NULL DEFAULT 'pending',
 evidence_ref VARCHAR(255) NULL,
 note TEXT NULL,
 prepared_by BIGINT UNSIGNED NULL,
 approved_by BIGINT UNSIGNED NULL,
 prepared_at DATETIME NULL,
 approved_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ops_closure_handover(franchise_id),
 INDEX idx_ops_closure_status(handover_status,updated_at)
);
