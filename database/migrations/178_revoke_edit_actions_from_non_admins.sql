-- إزالة صلاحيات «تعديل سند مرحّل» من غير مجموعة ADMINS
-- (إن وُجدت بسبب منح تلقائي سابق — تُمنح يدوياً من شاشة الصلاحيات فقط).

DELETE gp FROM sys_group_permission gp
INNER JOIN sys_screen s ON s.id = gp.screen_id
INNER JOIN sys_group g ON g.id = gp.group_id
WHERE s.code IN (
    'action_edit_journal_voucher',
    'action_edit_cash_receipt',
    'action_edit_cash_payment'
)
  AND g.code <> 'ADMINS'
  AND gp.allowed = 1;
