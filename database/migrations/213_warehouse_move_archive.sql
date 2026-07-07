-- أرشيف مرفقات حركات المستودع (يشمل عهدة المندوب على الموبايل)

ALTER TABLE fin_voucher_document
    MODIFY voucher_kind ENUM(
        'receipt',
        'payment',
        'journal',
        'sales_invoice',
        'purchase_invoice',
        'sales_delivery',
        'sales_return',
        'purchase_return',
        'warehouse_move'
    ) NOT NULL;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_warehouse_move', 'أرشيف مرفقات حركة مستودع', 'screen', 9118
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_warehouse_move');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'action_archive_warehouse_move'
WHERE g.code IN ('ADMINS', 'MOBILE');
