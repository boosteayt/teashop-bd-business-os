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

-- Production passwords MUST be generated server-side with a strong password hash.
-- This legacy seed intentionally stores role-based policies only; user identities are bootstrapped server-side.
INSERT INTO performance_share_rules(person_key,tier,percent,effective_from) VALUES
('FRANCHISE_RETAIL_OPERATIONS','Base',15.00,CURDATE()),
('FRANCHISE_RETAIL_OPERATIONS','Growth',20.00,CURDATE()),
('FRANCHISE_RETAIL_OPERATIONS','Elite',25.00,CURDATE());
