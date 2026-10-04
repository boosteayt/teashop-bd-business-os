INSERT INTO roles(code,name) VALUES
('OWNER','Founder / Owner / CEO'),
('OPERATIONS','Head of Franchise & Retail Operations'),
('FINANCE','Finance & Accounts'),
('WAREHOUSE','Warehouse & Inventory'),
('REGIONAL','Regional Operations'),
('FRANCHISE','Franchise Owner'),
('CASHIER','POS / Cashier');

INSERT INTO performance_share_rules(person_key,tier,percent,effective_from) VALUES
('OMAR_FARUK','Base',15.00,CURDATE()),
('OMAR_FARUK','Growth',20.00,CURDATE()),
('OMAR_FARUK','Elite',25.00,CURDATE());

INSERT INTO system_settings(setting_key,setting_value) VALUES
('franchise_margin_starter','25'),
('franchise_margin_growth','27'),
('franchise_margin_elite','30'),
('faruk_share_base','15'),
('faruk_share_growth','20'),
('faruk_share_elite','25'),
('tax_provision_percent','0'),
('pricing_wastage_percent','3');
