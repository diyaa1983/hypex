-- رواتب مستحقة: خصوم فقط — لا تربطها بمصروف الرواتب (5038)

UPDATE acc_posting_setting sp
INNER JOIN acc_posting_setting se ON se.rule_code IN ('salaries_expense', 'hr_payroll_accrual')
    AND se.account_id IS NOT NULL AND se.account_id > 0 AND se.account_id = sp.account_id
SET sp.account_id = NULL
WHERE sp.rule_code = 'salaries_payable';

UPDATE acc_posting_setting ps
INNER JOIN acc_account bad ON bad.id = ps.account_id AND bad.account_type = 'expense'
SET ps.account_id = NULL
WHERE ps.rule_code = 'salaries_payable';

UPDATE acc_posting_setting ps
SET ps.account_id = (
    SELECT a.id FROM acc_account a
    WHERE a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'liability'
      AND a.name_ar LIKE '%رواتب%' AND a.name_ar LIKE '%مستحق%'
      AND a.name_ar NOT LIKE '%ضمان%'
    ORDER BY (a.code = '23') DESC, a.id ASC
    LIMIT 1
)
WHERE ps.rule_code = 'salaries_payable'
  AND (ps.account_id IS NULL OR ps.account_id = 0);
