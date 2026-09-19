ALTER TABLE hr_employee
    ADD COLUMN salary_bank_id INT UNSIGNED NULL AFTER social_security_no,
    ADD KEY idx_hr_employee_salary_bank (salary_bank_id);

ALTER TABLE hr_employee
    ADD CONSTRAINT fk_hr_employee_salary_bank
        FOREIGN KEY (salary_bank_id) REFERENCES hr_salary_bank(id) ON DELETE SET NULL;
