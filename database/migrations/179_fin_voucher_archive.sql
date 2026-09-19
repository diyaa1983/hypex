-- أرشيف مرفقات سندات القبض والصرف والقيد

ALTER TABLE sys_company_settings
    ADD COLUMN document_archive_dir VARCHAR(500) NULL DEFAULT NULL,
    ADD COLUMN document_archive_max_mb TINYINT UNSIGNED NOT NULL DEFAULT 10;

CREATE TABLE IF NOT EXISTS fin_voucher_document (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    voucher_kind ENUM('receipt','payment','journal') NOT NULL,
    voucher_id INT UNSIGNED NOT NULL,
    voucher_no VARCHAR(40) NOT NULL DEFAULT '',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    relative_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NOT NULL DEFAULT '',
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fvd_voucher (voucher_kind, voucher_id),
    KEY idx_fvd_uploaded (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_cash_receipt', 'أرشيف مرفقات سند قبض', 'screen', 9110
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_cash_receipt');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_cash_payment', 'أرشيف مرفقات سند صرف', 'screen', 9111
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_cash_payment');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_archive_journal_voucher', 'أرشيف مرفقات سند قيد', 'screen', 9112
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_archive_journal_voucher');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
    'action_archive_cash_receipt',
    'action_archive_cash_payment',
    'action_archive_journal_voucher'
)
WHERE g.code = 'ADMINS';
