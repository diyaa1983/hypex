ALTER TABLE hr_employee
    ADD COLUMN nationality_id INT UNSIGNED NULL AFTER gender,
    ADD KEY idx_hr_employee_nat_id (nationality_id);
