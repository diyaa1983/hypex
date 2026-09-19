-- حسابات ترحيل الرواتب: سلفة الشؤons، ضمان (2005، 2006، 2008، 5119)

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '2005', 'سلفة شؤون الموظفين (رواتب)', p.id, 'liability', 1, 1, 25
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'liability'
  AND (p.code = '2' OR p.parent_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '2005' LIMIT 1)
ORDER BY (p.code = '2') DESC, p.id ASC
LIMIT 1;

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '2008', 'ضمان اجتماعي — حصة الموظف', p.id, 'liability', 1, 1, 26
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'liability'
  AND (p.code = '2' OR p.parent_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '2008' LIMIT 1)
ORDER BY (p.code = '2') DESC, p.id ASC
LIMIT 1;

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '2006', 'ضمان اجتماعي مستحق — حصة الشركة', p.id, 'liability', 1, 1, 28
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'liability'
  AND (p.code = '2' OR p.parent_id IS NULL)
  AND NOT EXISTS (
      SELECT 1 FROM acc_account x
      WHERE x.is_active = 1 AND (x.code = '2006' OR x.name_ar LIKE '%ضمان%مستحق%')
      LIMIT 1
  )
ORDER BY (p.code = '2') DESC, p.id ASC
LIMIT 1;

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '5119', 'ضمان اجتماعي — حصة الشركة (مصروف)', p.id, 'expense', 1, 1, 25
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'expense'
  AND (p.code = '5' OR p.parent_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '5119' LIMIT 1)
ORDER BY (p.code = '5') DESC, p.id ASC
LIMIT 1;

UPDATE acc_posting_setting SET
    label_ar = 'سلفة شؤون الموظفين (رواتب)',
    hint_ar = 'دائن عند ترحيل الرواتب — صافي الرواتب لصرفها عبر قسم الموظفين (بعد اقتطاع الضمان وضريبة الدخل)'
WHERE rule_code = 'salaries_payable';

UPDATE acc_posting_setting SET
    label_ar = 'ضمان اجتماعي — حصة الموظف',
    hint_ar = 'دائن عند ترحيل الرواتب — حصة الموظف المقتطعة من الراتب (تُسدّد للضمان مع حصة الشركة)'
WHERE rule_code = 'hr_social_insurance_employee';

UPDATE acc_posting_setting SET
    label_ar = 'ضمان اجتماعي مستحق — حصة الشركة',
    hint_ar = 'دائن عند ترحيل الرواتب — حصة الشركة المستحقة للضمان الاجتماعي'
WHERE rule_code = 'hr_social_insurance_payable';

UPDATE acc_posting_setting SET
    label_ar = 'ضمان اجتماعي — حصة الشركة (مصروف)',
    hint_ar = 'مدين عند ترحيل الرواتب — مصروف حصة الشركة على الضمان'
WHERE rule_code = 'hr_social_insurance_employer';

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '2005'
SET ps.account_id = a.id
WHERE ps.rule_code = 'salaries_payable'
  AND (ps.account_id IS NULL OR ps.account_id = 0);

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '2008'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_social_insurance_employee'
  AND (
      ps.account_id IS NULL OR ps.account_id = 0
      OR ps.account_id = (SELECT account_id FROM acc_posting_setting WHERE rule_code = 'salaries_payable' LIMIT 1)
  );

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '2006'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_social_insurance_payable'
  AND (ps.account_id IS NULL OR ps.account_id = 0);

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '5119'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_social_insurance_employer'
  AND (
      ps.account_id IS NULL OR ps.account_id = 0
      OR ps.account_id = (SELECT account_id FROM acc_posting_setting WHERE rule_code = 'salaries_expense' LIMIT 1)
  );
