CREATE TABLE IF NOT EXISTS hr_employee_salary_line (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    component_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_emp_salary_line_comp (employee_id, component_id),
    KEY idx_hr_emp_salary_line_emp (employee_id),
    CONSTRAINT fk_hr_emp_salary_line_emp FOREIGN KEY (employee_id) REFERENCES hr_employee(id) ON DELETE CASCADE,
    CONSTRAINT fk_hr_emp_salary_line_comp FOREIGN KEY (component_id) REFERENCES hr_payroll_component(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
