-- إعدادات النسخ الاحتياطي للنظام

CREATE TABLE IF NOT EXISTS sys_backup_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    backup_dir VARCHAR(500) NOT NULL DEFAULT '',
    last_backup_at DATETIME NULL,
    last_backup_path VARCHAR(500) NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO sys_backup_settings (id, backup_dir)
SELECT 1, ''
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_backup_settings WHERE id = 1);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'system_backup', 'النسخ الاحتياطي', 'screen', 910
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'system_backup');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'settings'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'system_backup';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'system_backup'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
