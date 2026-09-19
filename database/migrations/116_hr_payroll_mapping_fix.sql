-- ربط ترحيل الرواتب: خصوم الميزانية فقط (23، 2006، 2007) — وليس 2005

UPDATE acc_posting_setting SET
    label_ar = 'رواتب مستحقة',
    hint_ar = 'دائن عند ترحيل الرواتب — صافي مستحق للموظفين. استخدم حساب 23 «رواتب مستحقة» وليس 2005.'
WHERE rule_code = 'salaries_payable';

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'liability' AND a.code = '23'
SET ps.account_id = a.id
WHERE ps.rule_code = 'salaries_payable';

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'liability' AND a.code = '2006'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_social_insurance_payable'
  AND (ps.account_id IS NULL OR ps.account_id = 0);

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'liability' AND a.code = '2007'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_income_tax'
  AND (ps.account_id IS NULL OR ps.account_id = 0);
