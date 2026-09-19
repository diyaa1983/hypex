-- تسجيل أكواد صلاحيات الإجراءات (مرة واحدة). لا تُمنح صلاحيات المجموعات هنا —
-- المنع/المنح يتم من شاشة الصلاحيات فقط. المنح الأولي: sys_sync_action_permissions() → ADMINS فقط.

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_sales_invoice', 'فك ترحيل فاتورة مبيعات', 'screen', 9101
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_sales_invoice');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_sales_return', 'فك ترحيل مرتجع مبيعات', 'screen', 9102
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_sales_return');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_purchase_invoice', 'فك ترحيل فاتورة شراء', 'screen', 9103
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_purchase_invoice');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_cash_receipt', 'فك ترحيل سند قبض', 'screen', 9104
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_cash_receipt');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_cash_payment', 'فك ترحيل سند صرف', 'screen', 9105
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_cash_payment');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_journal_voucher', 'فك ترحيل سند قيد', 'screen', 9106
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_journal_voucher');
