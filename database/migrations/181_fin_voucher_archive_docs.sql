-- توسيع أرشيف المرفقات: فواتير، تسليم، مرتجعات

ALTER TABLE fin_voucher_document
    MODIFY voucher_kind ENUM(
        'receipt',
        'payment',
        'journal',
        'sales_invoice',
        'purchase_invoice',
        'sales_delivery',
        'sales_return',
        'purchase_return'
    ) NOT NULL;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_sales_invoice', 'أرشيف مرفقات فاتورة مبيعات', 'screen', 9113
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_sales_invoice');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_purchase_invoice', 'أرشيف مرفقات فاتورة شراء', 'screen', 9114
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_purchase_invoice');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_sales_delivery', 'أرشيف مرفقات سند تسليم', 'screen', 9115
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_sales_delivery');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_sales_return', 'أرشيف مرفقات مرتجع مبيعات', 'screen', 9116
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_sales_return');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_purchase_return', 'أرشيف مرفقات مرتجع شراء', 'screen', 9117
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_purchase_return');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
    'action_archive_sales_invoice',
    'action_archive_purchase_invoice',
    'action_archive_sales_delivery',
    'action_archive_sales_return',
    'action_archive_purchase_return'
)
WHERE g.code = 'ADMINS';
