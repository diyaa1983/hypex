ALTER TABLE hr_employee
    ADD COLUMN IF NOT EXISTS department_id INT UNSIGNED NULL AFTER department,
    ADD COLUMN IF NOT EXISTS job_title_id INT UNSIGNED NULL AFTER job_title,
    ADD COLUMN IF NOT EXISTS gender VARCHAR(10) NULL AFTER national_id;

ALTER TABLE hr_employee
    ADD KEY IF NOT EXISTS idx_hr_employee_dept_id (department_id),
    ADD KEY IF NOT EXISTS idx_hr_employee_jt_id (job_title_id);
