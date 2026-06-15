-- حساب ضريبة الدخل في شجرة الحسابات + ربطه بقاعدة الترحيل hr_income_tax (رقم 2007)

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '2007', 'ضريبة دخل مستحقة', p.id, 'liability', 1, 1, 27
FROM acc_account p
WHERE p.is_active = 1
  AND p.is_leaf = 0
  AND p.account_type = 'liability'
  AND (p.code = '2' OR p.parent_id IS NULL)
  AND NOT EXISTS (
      SELECT 1 FROM acc_account x
      WHERE x.is_active = 1
        AND (x.code = '2007' OR x.name_ar LIKE '%ضريبة%دخل%')
      LIMIT 1
  )
ORDER BY (p.code = '2') DESC, p.id ASC
LIMIT 1;

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1
    AND (a.code = '2007' OR a.name_ar LIKE '%ضريبة%دخل%')
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_income_tax'
  AND (ps.account_id IS NULL OR ps.account_id = 0);
