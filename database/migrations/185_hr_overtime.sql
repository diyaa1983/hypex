-- العمل الإضافي — إعدادات وتسجيل ساعات

CREATE TABLE IF NOT EXISTS hr_overtime_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    hour_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.250,
    monthly_work_hours DECIMAL(8,3) NOT NULL DEFAULT 160.000,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_overtime_config (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS hr_employee_overtime (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    pay_year SMALLINT UNSIGNED NOT NULL,
    pay_month TINYINT UNSIGNED NOT NULL,
    overtime_hours DECIMAL(8,3) NOT NULL DEFAULT 0.000,
    hour_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.250,
    base_salary DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    overtime_amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_emp_overtime_period (employee_id, pay_year, pay_month),
    KEY idx_hr_emp_overtime_period (pay_year, pay_month),
    CONSTRAINT fk_hr_emp_overtime_emp FOREIGN KEY (employee_id)
        REFERENCES hr_employee(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_overtime_settings', 'إعدادات العمل الإضافي', 'master', 249
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_overtime_settings');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_employee_overtime', 'تسجيل العمل الإضافي', 'screen', 829
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_employee_overtime');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_social_security_rates'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_overtime_settings';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_monthly_payroll_adjustments'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_employee_overtime';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('hr_overtime_settings', 'hr_employee_overtime')
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
