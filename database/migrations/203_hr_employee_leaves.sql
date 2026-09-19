-- إدخال إجازات الموظفين

CREATE TABLE IF NOT EXISTS hr_employee_leave (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    voucher_no VARCHAR(16) NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    leave_date DATE NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    days_count DECIMAL(8, 2) NOT NULL,
    notes VARCHAR(500) NULL,
    is_posted TINYINT(1) NOT NULL DEFAULT 0,
    posted_at DATETIME NULL,
    posted_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_emp_leave_voucher (voucher_no),
    KEY idx_hr_emp_leave_emp_dates (employee_id, date_from, date_to),
    KEY idx_hr_emp_leave_posted (is_posted, date_from),
    CONSTRAINT fk_hr_emp_leave_emp FOREIGN KEY (employee_id)
        REFERENCES hr_employee(id) ON DELETE RESTRICT,
    CONSTRAINT fk_hr_emp_leave_type FOREIGN KEY (leave_type_id)
        REFERENCES hr_leave_type(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_employee_leaves', 'إدخال الإجازات', 'screen', 833
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_employee_leaves');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employees'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_employee_leaves';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_employee_leaves'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_post_employee_leave', 'ترحيل إجازة موظف', 'screen', 9116
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_post_employee_leave');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_employee_leave', 'فك ترحيل إجازة موظف', 'screen', 9117
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_employee_leave');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS'
  AND s.code IN ('action_post_employee_leave', 'action_unpost_employee_leave');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employee_leaves'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('action_post_employee_leave', 'action_unpost_employee_leave');
