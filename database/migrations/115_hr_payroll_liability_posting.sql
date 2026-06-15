-- ترحيل رواتب: خصوم فقط في الربط (رواتب مستحقة + ضمان مستحق) — المدين داخلي

INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES
(
    'hr_payroll_accrual',
    'استحقاق رواتب (مدين — داخلي)',
    'مدين عند ترحيل الرواتب — موازنة القيد؛ لا يظهر في شاشة الربط',
    81
)
ON DUPLICATE KEY UPDATE
    label_ar = VALUES(label_ar),
    hint_ar = VALUES(hint_ar),
    sort_order = VALUES(sort_order);

UPDATE acc_posting_setting ps
INNER JOIN acc_posting_setting src ON src.rule_code = 'salaries_expense' AND src.account_id IS NOT NULL AND src.account_id > 0
SET ps.account_id = src.account_id
WHERE ps.rule_code = 'hr_payroll_accrual'
  AND (ps.account_id IS NULL OR ps.account_id = 0);

UPDATE acc_posting_setting SET
    label_ar = 'رواتب مستحقة',
    hint_ar = 'دائن عند ترحيل الرواتب — صافي مستحق للموظفين (بعد اقتطاع حصة الموظف من الضمان والخصومات). عند الدفع: مدين / دائن صندوق أو بنك'
WHERE rule_code = 'salaries_payable';

UPDATE acc_posting_setting SET
    label_ar = 'ضمان اجتماعي مستحق',
    hint_ar = 'دائن عند ترحيل الرواتب — حصة الموظف + حصة الشركة (يُسدّد للضمان الاجتماعي من الصندوق)'
WHERE rule_code = 'hr_social_insurance_payable';

UPDATE acc_posting_setting SET
    label_ar = 'رواتب وأجور (مصروف — داخلي)',
    hint_ar = 'لا يُستخدم في ترحيل الرواتب — يُستبدل بقاعدة داخلية'
WHERE rule_code = 'salaries_expense';
