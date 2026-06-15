-- فصل قواعد الضمان القديمة عن مصروف الرواتب (5038)

UPDATE acc_posting_setting ps
INNER JOIN acc_posting_setting exp ON exp.rule_code = 'salaries_expense'
SET ps.account_id = NULL
WHERE ps.rule_code IN ('hr_social_insurance_employer', 'hr_social_insurance_employee')
  AND ps.account_id = exp.account_id
  AND exp.account_id IS NOT NULL;

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'expense' AND a.code = '5119'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_social_insurance_employer'
  AND (ps.account_id IS NULL OR ps.account_id = 0);
