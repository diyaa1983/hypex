-- تعريف دوام الموظف — شفت افتراضي + جدول أسبوعي بفترات

CREATE TABLE IF NOT EXISTS hr_att_employee_default_shift (
    employee_id INT UNSIGNED NOT NULL,
    shift_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id),
    CONSTRAINT fk_hr_att_emp_def_shift_emp FOREIGN KEY (employee_id)
        REFERENCES hr_employee(id) ON DELETE CASCADE,
    CONSTRAINT fk_hr_att_emp_def_shift_shift FOREIGN KEY (shift_id)
        REFERENCES hr_att_shift(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_att_employee_weekly (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hr_att_emp_weekly_emp (employee_id),
    KEY idx_hr_att_emp_weekly_range (employee_id, date_from, date_to),
    CONSTRAINT fk_hr_att_emp_weekly_emp FOREIGN KEY (employee_id)
        REFERENCES hr_employee(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_att_employee_weekly_day (
    weekly_id INT UNSIGNED NOT NULL,
    day_index TINYINT UNSIGNED NOT NULL,
    shift_id INT UNSIGNED NULL,
    PRIMARY KEY (weekly_id, day_index),
    KEY idx_hr_att_emp_weekly_day_shift (shift_id),
    CONSTRAINT fk_hr_att_emp_weekly_day_weekly FOREIGN KEY (weekly_id)
        REFERENCES hr_att_employee_weekly(id) ON DELETE CASCADE,
    CONSTRAINT fk_hr_att_emp_weekly_day_shift FOREIGN KEY (shift_id)
        REFERENCES hr_att_shift(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_employee_schedule', 'تعريف دوام الموظف', 'screen', 829
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_employee_schedule');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employee_attendance'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_employee_schedule';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_employee_schedule'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
