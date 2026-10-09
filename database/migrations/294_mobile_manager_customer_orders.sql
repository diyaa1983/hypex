-- هاتف: طلبات شراء العملاء لمدير المبيعات (حسب المندوب)

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_manager_customer_orders', 'هاتف — طلبات شراء العملاء', 'screen', 9053);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code IN ('ADMINS', 'administrators', 'admin')
  AND s.code = 'm_manager_customer_orders';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT DISTINCT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id
  AND src.code IN (
    'sales_customer_orders',
    'sales_customer_orders_approve',
    'sales_customer_orders_approved',
    'm_visit_checkout_approve',
    'm_customer_gps_approve',
    'm_rep_tours_manage'
  )
INNER JOIN sys_screen s ON s.code = 'm_manager_customer_orders'
WHERE gp.allowed = 1;
