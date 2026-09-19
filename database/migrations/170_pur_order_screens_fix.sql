-- إصلاح شاشات طلبات الشراء إذا فشلت inserts في 169 (screen_type غير صالح)

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'purchase_orders', 'طلب شراء', 'screen', 198
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_orders');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'purchase_orders_documents_list', 'قائمة طلبات الشراء', 'screen', 199
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_orders_documents_list');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'purchase_orders_list', 'اعتماد طلبات الشراء', 'screen', 200
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_orders_list');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_purchase_orders', 'تقرير طلبات الشراء', 'report', 201
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_purchase_orders');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_purchase_orders_by_item', 'تقرير طلبات الشراء حسب المادة', 'report', 202
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_purchase_orders_by_item');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_purchase_orders_open', 'تقرير طلبات الشراء المفتوحة', 'report', 203
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_purchase_orders_open');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_approve_purchase_order', 'اعتماد طلب شراء', 'screen', 900
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_approve_purchase_order');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unapprove_purchase_order', 'فك اعتماد طلب شراء', 'screen', 901
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unapprove_purchase_order');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_delete_purchase_order', 'حذف طلب شراء', 'screen', 902
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_delete_purchase_order');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_convert_purchase_order', 'تحويل طلب شراء إلى فاتورة', 'screen', 903
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_convert_purchase_order');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_invoices'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('purchase_orders', 'purchase_orders_documents_list', 'purchase_orders_list');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('report_purchases', 'report_purchases_by_item', 'report_purchase_orders')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('report_purchase_orders', 'report_purchase_orders_by_item', 'report_purchase_orders_open');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN (
  'action_approve_purchase_order', 'action_unapprove_purchase_order',
  'action_delete_purchase_order', 'action_convert_purchase_order'
);

-- تأكد من شاشة سلف المحاسبة
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'fin_employee_advances', 'سلف الموظفين — المحاسبة', 'screen', 119
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'fin_employee_advances');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('cash_payment', 'cash_payments_list')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'fin_employee_advances';
