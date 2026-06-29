-- فصل حساب خصومات الرواتb عن ذمة السلف (1215)

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '2416', 'خصومات واقتطاعات موظفين', p.id, 'liability', 1, 1, 46
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'liability'
  AND (p.code = '24' OR p.name_ar LIKE '%مستحقات%موظ%')
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '2416' LIMIT 1)
ORDER BY (p.code = '24') DESC, p.id ASC
LIMIT 1;

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '2416', 'خصومات واقتطاعات موظفين', p.id, 'liability', 1, 1, 46
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'liability'
  AND (p.code = '2' OR p.parent_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '2416' LIMIT 1)
ORDER BY (p.code = '2') DESC, p.id ASC
LIMIT 1;

UPDATE acc_posting_setting SET
    label_ar = 'خصومات واقتطاعات موظفين',
    hint_ar = 'دائن عند ترحيل الرواتب — خصومات واقتطاعات أخرى غير السلف والضمان'
WHERE rule_code = 'hr_payroll_deductions';

UPDATE acc_posting_setting SET
    label_ar = 'ذمة سلف الموظفين',
    hint_ar = 'مدين عند ترحيل السلفة — دائن عند اقتطاع السلف من الراتب (حساب 1215)'
WHERE rule_code = 'hr_employee_advance_receivable';

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '2416'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_payroll_deductions'
  AND (
      ps.account_id IS NULL OR ps.account_id = 0
      OR ps.account_id = (SELECT account_id FROM acc_posting_setting WHERE rule_code = 'hr_employee_advance_receivable' LIMIT 1)
  );

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '1215'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_employee_advance_receivable'
  AND (ps.account_id IS NULL OR ps.account_id = 0);
