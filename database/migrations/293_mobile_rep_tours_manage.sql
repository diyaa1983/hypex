-- هاتف: إدارة جولات المندوبين (اطلاع مدير المبيعات على جولة أي مندوب)

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_rep_tours_manage', 'هاتف — إدارة جولات المندوبين', 'screen', 9052);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code IN ('ADMINS', 'administrators', 'admin')
  AND s.code = 'm_rep_tours_manage';

-- مديرو المبيعات: من لديه إدارة الجولات / تقرير الجولات / اعتمادات المدير
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT DISTINCT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id
  AND src.code IN (
    'sales_rep_route',
    'report_sales_rep_tours',
    'sales_rep_visit_checkout_approve',
    'crm_customer_gps_approve',
    'm_visit_checkout_approve',
    'm_customer_gps_approve'
  )
INNER JOIN sys_screen s ON s.code = 'm_rep_tours_manage'
WHERE gp.allowed = 1;
