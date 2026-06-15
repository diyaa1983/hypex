INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES
(
    'hr_social_insurance_employee',
    'ضمان اجتماعي (حصة الموظف)',
    'مدين عند ترحيل الرواتب — اقتطاع حصة الموظف من الراتب',
    85
),
(
    'hr_social_insurance_employer',
    'ضمان اجتماعي (حصة الشركة)',
    'مدين عند ترحيل الرواتب — مصروف حصة الشركة على الضمان',
    86
)
ON DUPLICATE KEY UPDATE
  label_ar = VALUES(label_ar),
  hint_ar = VALUES(hint_ar),
  sort_order = VALUES(sort_order);

UPDATE acc_posting_setting AS emp
INNER JOIN acc_posting_setting AS src ON src.rule_code = 'salaries_payable'
SET emp.account_id = src.account_id
WHERE emp.rule_code = 'hr_social_insurance_employee'
  AND (emp.account_id IS NULL OR emp.account_id = 0)
  AND src.account_id IS NOT NULL AND src.account_id > 0;

UPDATE acc_posting_setting AS er
INNER JOIN acc_posting_setting AS src ON src.rule_code = 'salaries_expense'
SET er.account_id = src.account_id
WHERE er.rule_code = 'hr_social_insurance_employer'
  AND (er.account_id IS NULL OR er.account_id = 0)
  AND src.account_id IS NOT NULL AND src.account_id > 0;
