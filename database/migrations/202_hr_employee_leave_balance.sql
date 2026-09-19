-- رصيد إجازات الموظفين

CREATE TABLE IF NOT EXISTS hr_employee_leave_balance (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    opening_balance DECIMAL(8, 2) NOT NULL DEFAULT 0,
    entitled_balance DECIMAL(8, 2) NOT NULL DEFAULT 0,
    taken_days DECIMAL(8, 2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_emp_leave_bal (employee_id, leave_type_id),
    CONSTRAINT fk_hr_emp_leave_bal_emp FOREIGN KEY (employee_id)
        REFERENCES hr_employee(id) ON DELETE CASCADE,
    CONSTRAINT fk_hr_emp_leave_bal_type FOREIGN KEY (leave_type_id)
        REFERENCES hr_leave_type(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_employee_leave_balances', 'رصيد إجازات الموظفين', 'screen', 832
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_employee_leave_balances');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employees'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_employee_leave_balances';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_employee_leave_balances'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
