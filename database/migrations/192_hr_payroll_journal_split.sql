-- قيد ترحيل الرواتب: مصروفات منفصلة (رواتب / ضمان شركة / بدلات) + خصوم وسلف + مستحقات

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '5120', 'بدلات ومكافئات', p.id, 'expense', 1, 1, 22
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'expense'
  AND (p.code = '5' OR p.parent_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '5120' LIMIT 1)
ORDER BY (p.code = '5') DESC, p.id ASC
LIMIT 1;

INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES
(
    'hr_payroll_allowances_expense',
    'بدلات ومكافئات (مصروف)',
    'مدين عند ترحيل الرواتب — علاوات شهرية ومكافآت وعمل إضافي فوق الراتب الأساسي',
    84
)
ON DUPLICATE KEY UPDATE
    label_ar = VALUES(label_ar),
    hint_ar = VALUES(hint_ar),
    sort_order = VALUES(sort_order);

UPDATE acc_posting_setting SET
    label_ar = 'رواتب وأجور (مصروف)',
    hint_ar = 'مدين عند ترحيل الرواتب — الراتب الأساسي + علاوات شاشة راتب الموظف (بدون علاوات شهرية وبدون ضمان)'
WHERE rule_code = 'salaries_expense';

UPDATE acc_posting_setting SET
    label_ar = 'ضمان اجتماعي — حصة الشركة (مصروف)',
    hint_ar = 'مدين عند ترحيل الرواتب — نسبة الشركة على الرواتب والأجور (مثلاً 14.25%)'
WHERE rule_code = 'hr_social_insurance_employer';

UPDATE acc_posting_setting SET
    label_ar = 'سلف وخصومات الموظفين',
    hint_ar = 'دائن عند ترحيل الرواتب — السلف والخصومات المقتطعة من رواتب الموظفين'
WHERE rule_code = 'hr_payroll_deductions';

UPDATE acc_posting_setting SET
    label_ar = 'رواتب مستحقة',
    hint_ar = 'دائن عند ترحيل الرواتب — صافي الرواتب المستحقة للموظفين للصرف'
WHERE rule_code = 'salaries_payable';

UPDATE acc_posting_setting SET
    label_ar = 'أمانات ضمان اجتماعي',
    hint_ar = 'دائن عند ترحيل الرواتب — مجموع حصة الموظف + حصة الشركة المستحقة للضمان'
WHERE rule_code = 'hr_social_insurance_payable';

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '5120'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_payroll_allowances_expense'
  AND (ps.account_id IS NULL OR ps.account_id = 0);

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '5119'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_social_insurance_employer'
  AND (ps.account_id IS NULL OR ps.account_id = 0);
