-- إعدادات أنواع الإجازات

CREATE TABLE IF NOT EXISTS hr_leave_type (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    leave_code VARCHAR(16) NOT NULL,
    name_ar VARCHAR(120) NOT NULL,
    default_days DECIMAL(8, 2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_leave_type_code (leave_code),
    KEY idx_hr_leave_type_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_leave_types', 'إعدادات الإجازات', 'screen', 831
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_leave_types');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employees'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_leave_types';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_leave_types'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
