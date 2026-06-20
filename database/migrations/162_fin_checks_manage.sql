-- شاشة إدارة الشيكات: حالة الشيك (قيد / مصروف / مُرجَع) + ترحيل الشيكات القديمة
-- (بدون PREPARE/EXECUTE — متوافق مع PDO)

ALTER TABLE fin_voucher_check
    ADD COLUMN lifecycle_status ENUM('pending','cleared','returned') NOT NULL DEFAULT 'pending' AFTER notes,
    ADD COLUMN action_date DATE NULL AFTER lifecycle_status,
    ADD COLUMN return_reason VARCHAR(500) NULL AFTER action_date,
    ADD COLUMN action_account_id INT UNSIGNED NULL AFTER return_reason,
    ADD COLUMN action_journal_id INT UNSIGNED NULL AFTER action_account_id,
    ADD COLUMN action_at DATETIME NULL AFTER action_journal_id,
    ADD COLUMN action_by INT UNSIGNED NULL AFTER action_at,
    ADD KEY idx_fvc_lifecycle (lifecycle_status),
    ADD KEY idx_fvc_action_date (action_date);

-- ترحيل الشيكات المخزّنة في رأس السند فقط
INSERT INTO fin_voucher_check (voucher_id, sort_order, check_no, bank_name, check_amount, due_date, notes, lifecycle_status)
SELECT v.id, 0, NULLIF(TRIM(v.check_no), ''), NULLIF(TRIM(v.bank_name), ''), v.check_amount, NULL, NULL, 'pending'
FROM fin_voucher v
WHERE v.pay_method = 'check'
  AND v.check_amount > 0.000001
  AND NOT EXISTS (SELECT 1 FROM fin_voucher_check c WHERE c.voucher_id = v.id);

-- شاشة إدارة الشيكات
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'fin_checks', 'شاشة الشيكات', 'screen', 118
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'fin_checks');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('cash_receipt', 'cash_payment', 'journal_voucher')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'fin_checks';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'fin_checks'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
