-- إقفال السنة المالية وفتح سنة جديدة
CREATE TABLE IF NOT EXISTS acc_fiscal_year (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fiscal_year SMALLINT NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    journal_id INT UNSIGNED NULL COMMENT 'قيد إقفال السنة',
    closed_at DATETIME NULL,
    closed_by INT UNSIGNED NULL,
    opened_at DATETIME NULL,
    opened_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_acc_fiscal_year (fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حساب الأرباح المحتجزة (32) تحت حقوق الملكية
INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '32', 'أرباح محتجزة', p.id, 'equity', 1, 1, 20
FROM acc_account p
WHERE p.code = '3' AND p.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM acc_account a WHERE a.code = '32' AND a.is_active = 1)
LIMIT 1;

INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order)
SELECT 'retained_earnings', 'أرباح محتجزة', 'دائن عند إقفال السنة المالية — صافي الربح المحوّل من الإيرادات والمصروفات', 12
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM acc_posting_setting WHERE rule_code = 'retained_earnings');

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.code = '32' AND a.is_active = 1
SET ps.account_id = a.id
WHERE ps.rule_code = 'retained_earnings' AND (ps.account_id IS NULL OR ps.account_id = 0);

-- السنة المالية الحالية 2026 مفتوحة افتراضياً
INSERT INTO acc_fiscal_year (fiscal_year, status, opened_at)
SELECT 2026, 'open', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM acc_fiscal_year WHERE fiscal_year = 2026);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'acc_year_close', 'إقفال السنة المالية', 'screen', 159
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'acc_year_close');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS' AND s.code = 'acc_year_close';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'acc_period_close'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'acc_year_close';
