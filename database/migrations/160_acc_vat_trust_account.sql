-- ربط ضريبة المبيعات والمشتريات بحساب أمانات ضريبة المبيعات 3001002
-- (إنشاء الحساب وترحيل القيود القديمة عبر includes/acc_vat_trust_account.php)

UPDATE acc_posting_setting
SET hint_ar = 'يُسجَّل في حساب أمانات ضريبة المبيعات 3001002'
WHERE rule_code IN ('vat_output', 'vat_input');
