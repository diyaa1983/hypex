INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES
(
    'hr_payroll_deductions',
    'خصومات وسلف رواتب',
    'دائن عند ترحيل الرواتب — سلف وخصومات أخرى غير الضمان',
    87
)
ON DUPLICATE KEY UPDATE
  label_ar = VALUES(label_ar),
  hint_ar = VALUES(hint_ar),
  sort_order = VALUES(sort_order);
