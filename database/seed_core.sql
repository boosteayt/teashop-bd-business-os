INSERT INTO roles(code,name) VALUES
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

INSERT INTO performance_share_rules(person_key,tier,percent,effective_from) VALUES
('FRANCHISE_RETAIL_OPERATIONS','Base',15.00,CURDATE()),
('FRANCHISE_RETAIL_OPERATIONS','Growth',20.00,CURDATE()),
('FRANCHISE_RETAIL_OPERATIONS','Elite',25.00,CURDATE());

INSERT INTO system_settings(setting_key,setting_value) VALUES
('franchise_margin_starter','25'),
('franchise_margin_growth','27'),
('franchise_margin_elite','30'),
('management_share_active_tier','Base'),
('management_share_manual_percent','20'),
('tax_provision_percent','0'),
('pricing_wastage_percent','3');
