-- إعدادات دوام الموظفين — تعريف الشفتات

CREATE TABLE IF NOT EXISTS hr_att_shift (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_code VARCHAR(20) NOT NULL,
    shift_name VARCHAR(80) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_att_shift_code (shift_code),
    KEY idx_hr_att_shift_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_att_shift (shift_code, shift_name, start_time, end_time, is_active)
SELECT '1', 'شفت صباحي', '07:00:00', '15:00:00', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM hr_att_shift LIMIT 1);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_attendance_settings', 'إعدادات دوام الموظفين', 'master', 827
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_attendance_settings');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employee_attendance'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_attendance_settings';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_attendance_settings'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
