-- شاشات الموبايل لاعتماد خروج يدوي واعتماد تعديل موقع العميل (مدير المبيعات)

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_visit_checkout_approve', 'هاتف — اعتماد خروج يدوي', 'screen', 9050),
('m_customer_gps_approve', 'هاتف — اعتماد موقع العميل', 'screen', 9051);

-- ADMINS
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code IN ('ADMINS', 'administrators', 'admin')
  AND s.code IN ('m_visit_checkout_approve', 'm_customer_gps_approve');

-- من لديه اعتماد الخروج اليدوي على سطح المكتب
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code = 'sales_rep_visit_checkout_approve'
INNER JOIN sys_screen s ON s.code = 'm_visit_checkout_approve'
WHERE gp.allowed = 1;

-- من لديه اعتماد موقع العميل على سطح المكتب
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code = 'crm_customer_gps_approve'
INNER JOIN sys_screen s ON s.code = 'm_customer_gps_approve'
WHERE gp.allowed = 1;
