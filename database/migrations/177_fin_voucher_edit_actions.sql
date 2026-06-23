-- صلاحيات تعديل سند قبض/صرف مرحّل (بعد التحقق بكلمة المرور)
-- المنح الأولي: مجموعة ADMINS فقط (باقي المجموعات من شاشة الصلاحيات).

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_edit_cash_receipt', 'تعديل سند قبض مرحّل', 'screen', 9108
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_edit_cash_receipt');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_edit_cash_payment', 'تعديل سند صرف مرحّل', 'screen', 9109
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_edit_cash_payment');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('action_edit_cash_receipt', 'action_edit_cash_payment')
WHERE g.code = 'ADMINS';
