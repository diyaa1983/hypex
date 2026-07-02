-- أنواع مغادرات الموظفين

CREATE TABLE IF NOT EXISTS hr_departure_type (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_code VARCHAR(16) NOT NULL,
    name_ar VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_departure_type_code (type_code),
    KEY idx_hr_departure_type_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_departure_types', 'أنواع المغادرات', 'screen', 829
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_departure_types');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employees'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_departure_types';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_departure_types'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
