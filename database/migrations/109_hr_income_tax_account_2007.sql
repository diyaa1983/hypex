-- ترقيم حساب ضريبة الدخل إلى 2007 (نفس نمط 2006 للضمان) — الأكواد القصيرة (24) مرفوضة في ربط الحسابات

UPDATE acc_account
SET code = '2007'
WHERE is_active = 1
  AND is_leaf = 1
  AND name_ar LIKE '%ضريبة%دخل%'
  AND code IN ('24', '2004')
  AND NOT EXISTS (
      SELECT 1 FROM acc_account x
      WHERE x.code = '2007' AND x.id <> acc_account.id
      LIMIT 1
  );

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '2007'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_income_tax';
