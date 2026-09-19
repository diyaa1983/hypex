-- ربط حساب مصروف رواتب (بعد إعادة ترقيم الشجرة — ليس بالضرورة code=52)

UPDATE acc_posting_setting ps
SET ps.account_id = (
    SELECT a.id FROM acc_account a
    WHERE a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'expense'
      AND (
          a.name_ar LIKE '%رواتب%أجور%'
          OR (a.name_ar LIKE '%رواتب%' AND a.name_ar NOT LIKE '%مستحق%')
          OR a.code REGEXP '52$'
      )
    ORDER BY
      (a.name_ar LIKE '%رواتب%أجور%') DESC,
      (a.code REGEXP '^52$') DESC,
      a.id ASC
    LIMIT 1
)
WHERE ps.rule_code IN ('hr_payroll_accrual', 'salaries_expense')
  AND (ps.account_id IS NULL OR ps.account_id = 0);
