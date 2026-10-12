-- هاتف: تسمية شاشة مدير المبيعات لاعتماد طلبات شراء المندوبين

UPDATE sys_screen
SET name_ar = 'هاتف — اعتماد طلبات شراء المندوبين'
WHERE code = 'm_manager_customer_orders';

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_manager_customer_orders', 'هاتف — اعتماد طلبات شراء المندوبين', 'screen', 9053);

-- منح الصلاحية لمجموعات لديها اعتماد طلبات الشراء على سطح المكتب
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT DISTINCT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id
  AND src.code IN (
    'sales_customer_orders_approve',
    'sales_customer_orders_approved',
    'sales_customer_orders'
  )
INNER JOIN sys_screen s ON s.code = 'm_manager_customer_orders'
WHERE gp.allowed = 1;
