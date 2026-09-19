-- محاسبة رواتب قياسية: رواتب وأجور (مصروف) + رواتب مستحقة (خصوم) — بدون «سلفة شؤون»

UPDATE acc_posting_setting SET
    label_ar = 'رواتب مستحقة (خصوم)',
    hint_ar = 'دائن عند ترحيل الرواتب — صافي المستحق للموظفين. عند الدفع: مدين رواتب مستحقة / دائن صندوق أو بنك'
WHERE rule_code = 'salaries_payable';

UPDATE acc_posting_setting SET
    label_ar = 'رواتب وأجور (مصروف)',
    hint_ar = 'مدين عند ترحيل الرواتب — إثبات تكلفة الرواتب (+ حصة الشركة على الضمان) في قائمة الدخل'
WHERE rule_code = 'salaries_expense';

UPDATE acc_account
SET name_ar = 'رواتب مستحقة'
WHERE is_active = 1
  AND is_leaf = 1
  AND code = '2005'
  AND name_ar LIKE '%سلفة%';
