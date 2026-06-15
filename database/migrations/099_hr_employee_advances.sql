CREATE TABLE IF NOT EXISTS hr_employee_advance (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    advance_code VARCHAR(40) NULL,
    employee_id INT UNSIGNED NOT NULL,
    advance_type ENUM('once', 'long') NOT NULL,
    total_amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hr_emp_advance_employee (employee_id),
    KEY idx_hr_emp_advance_status (status),
    KEY idx_hr_emp_advance_dates (start_date, end_date),
    CONSTRAINT fk_hr_emp_advance_employee FOREIGN KEY (employee_id) REFERENCES hr_employee(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_salary_advance_deduction (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    salary_id INT UNSIGNED NOT NULL,
    advance_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_sal_adv_ded (salary_id, advance_id),
    KEY idx_hr_sal_adv_ded_advance (advance_id),
    CONSTRAINT fk_hr_sal_adv_ded_salary FOREIGN KEY (salary_id) REFERENCES hr_salary(id) ON DELETE CASCADE,
    CONSTRAINT fk_hr_sal_adv_ded_advance FOREIGN KEY (advance_id) REFERENCES hr_employee_advance(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
