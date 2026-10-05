-- Tea Shop BD Business OS — Operations Patch 3: Sales, Stock & Settlement Intelligence
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS outlet_sales_targets (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 sales_target DECIMAL(14,2) NOT NULL DEFAULT 0,
 receipt_target INT UNSIGNED NOT NULL DEFAULT 0,
 notes VARCHAR(255) NULL,
 created_by BIGINT UNSIGNED NULL,
 approved_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_outlet_target(franchise_id,period_start,period_end),
 INDEX idx_outlet_target_period(period_start,period_end)
);

CREATE TABLE IF NOT EXISTS outlet_inventory_policies (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 product_pack_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 min_days_cover DECIMAL(8,2) NOT NULL DEFAULT 7,
 target_days_cover DECIMAL(8,2) NOT NULL DEFAULT 21,
 max_days_cover DECIMAL(8,2) NOT NULL DEFAULT 60,
 dead_stock_days INT UNSIGNED NOT NULL DEFAULT 30,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_outlet_inventory_policy(franchise_id,product_pack_id),
 INDEX idx_outlet_inventory_policy(franchise_id)
);

CREATE TABLE IF NOT EXISTS outlet_stock_counts (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 franchise_id BIGINT UNSIGNED NOT NULL,
 product_pack_id BIGINT UNSIGNED NOT NULL,
 counted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 system_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
 physical_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
 variance_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
 variance_value DECIMAL(14,2) NOT NULL DEFAULT 0,
 status ENUM('matched','review','approved_adjustment') NOT NULL DEFAULT 'matched',
 notes VARCHAR(255) NULL,
 counted_by BIGINT UNSIGNED NULL,
 approved_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_outlet_stock_count(franchise_id,counted_at),
 INDEX idx_outlet_stock_pack(franchise_id,product_pack_id,counted_at)
);
