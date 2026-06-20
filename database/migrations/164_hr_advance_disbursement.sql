-- صرف السلف: حساب مستحق للصرف + ربط سند الصرف + حالة الصرف

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '2009', 'سلف موظفين مستحقة الصرف', p.id, 'liability', 1, 1, 49
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'liability'
  AND (p.code = '2' OR p.parent_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '2009' LIMIT 1)
ORDER BY (p.code = '2') DESC, p.id ASC
LIMIT 1;

INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order)
SELECT 'hr_employee_advance_payable', 'سلف موظفين مستحقة الصرف', 'دائن عند اعتماد السلفة من الشؤون — مدين عند صرف النقد من المحاسبة', 89
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM acc_posting_setting WHERE rule_code = 'hr_employee_advance_payable');

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '2009'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_employee_advance_payable'
  AND (ps.account_id IS NULL OR ps.account_id = 0);

ALTER TABLE hr_employee_advance
    ADD COLUMN is_disbursed TINYINT(1) NOT NULL DEFAULT 0 AFTER posted_by,
    ADD COLUMN disbursed_at DATETIME NULL AFTER is_disbursed,
    ADD COLUMN disbursement_voucher_id INT UNSIGNED NULL AFTER disbursed_at,
    ADD KEY idx_hr_adv_disburse_voucher (disbursement_voucher_id);

UPDATE hr_employee_advance
SET is_disbursed = 1,
    disbursed_at = COALESCE(posted_at, updated_at, created_at, NOW())
WHERE is_posted = 1
  AND is_disbursed = 0
  AND disbursement_voucher_id IS NOT NULL
  AND disbursement_voucher_id > 0;

ALTER TABLE fin_voucher
    ADD COLUMN hr_advance_id INT UNSIGNED NULL AFTER offset_account_id,
    ADD KEY idx_fv_hr_advance (hr_advance_id);
