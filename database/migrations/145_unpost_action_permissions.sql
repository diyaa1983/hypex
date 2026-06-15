-- صلاحيات فك الترحيل الناقصة في شاشة الصلاحيات

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_sales_delivery', 'فك ترحيل سند تسليم بضاعة', 'screen', 9107
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_sales_delivery');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_warehouse_move', 'فك ترحيل حركة مستودع', 'screen', 9108
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_warehouse_move');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_inventory_stocktake', 'فك ترحيل سند جرد', 'screen', 9109
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_inventory_stocktake');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_purchase_return', 'فك ترحيل مردود مشتريات', 'screen', 9110
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_purchase_return');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_payroll', 'فك ترحيل رواتب الشهر (قيد الرواتب)', 'screen', 9111
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_payroll');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS'
  AND s.code IN (
    'action_unpost_sales_delivery',
    'action_unpost_warehouse_move',
    'action_unpost_inventory_stocktake',
    'action_unpost_purchase_return',
    'action_unpost_payroll'
  );

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'sales_delivery'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_unpost_sales_delivery';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'warehouse_moves'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_unpost_warehouse_move';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'inventory_stocktake'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_unpost_inventory_stocktake';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_returns'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_unpost_purchase_return';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('hr_salaries', 'hr_payroll_posting')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_unpost_payroll';
