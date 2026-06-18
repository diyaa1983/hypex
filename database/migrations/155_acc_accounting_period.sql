-- إغلاق الأشهر المحاسبية
CREATE TABLE IF NOT EXISTS acc_accounting_period (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_year SMALLINT NOT NULL,
    period_month TINYINT NOT NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=مغلق لا إدخال',
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_acc_period_ym (period_year, period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'acc_period_close', 'إغلاق الأشهر المحاسبية', 'screen', 158
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'acc_period_close');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS' AND s.code = 'acc_period_close';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'chart_of_accounts'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'acc_period_close';
