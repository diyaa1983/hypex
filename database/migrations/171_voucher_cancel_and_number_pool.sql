-- إلغاء السندات (بدلاً من حذف المرحّل) + إعادة استخدام أرقام المسودات المحذوفة

CREATE TABLE IF NOT EXISTS doc_number_pool (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pool_key VARCHAR(64) NOT NULL,
  doc_no VARCHAR(40) NOT NULL,
  doc_year SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doc_pool_key_no (pool_key, doc_no),
  KEY idx_doc_pool_key_year (pool_key, doc_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fin_voucher ADD COLUMN is_cancelled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE fin_voucher ADD COLUMN cancelled_at DATETIME NULL;
ALTER TABLE fin_voucher ADD COLUMN cancelled_by INT UNSIGNED NULL;
ALTER TABLE fin_voucher ADD KEY idx_fin_voucher_cancelled (is_cancelled);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_cancelled_vouchers', 'قائمة السندات الملغاة', 'report', 312
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_cancelled_vouchers');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_vouchers'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_cancelled_vouchers';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_cancel_cash_receipt', 'إلغاء سند قبض', 'screen', 910
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_cancel_cash_receipt');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_cancel_cash_payment', 'إلغاء سند صرف', 'screen', 911
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_cancel_cash_payment');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_cancel_journal_voucher', 'إلغاء سند قيد', 'screen', 912
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_cancel_journal_voucher');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'cash_receipt'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('action_cancel_cash_receipt', 'action_cancel_cash_payment', 'action_cancel_journal_voucher');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
  'report_cancelled_vouchers',
  'action_cancel_cash_receipt',
  'action_cancel_cash_payment',
  'action_cancel_journal_voucher'
)
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
