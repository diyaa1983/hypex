-- لوحة التحكم لمستخدمي مجموعة «مشاهدون»، وتكميل صلاحيات «محاسبون» بقوائم الترحيل والمشتريات بعد إضافة الشاشات لاحقاً.

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'dashboard'
WHERE g.code = 'VIEWERS';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
    'sales_invoices_list',
    'sales_returns_list',
    'purchase_invoices_list',
    'purchase_returns',
    'purchase_returns_list'
)
WHERE g.code = 'ACCOUNTING';
