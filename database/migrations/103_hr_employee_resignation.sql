-- استقالة الموظف وترحيل بطاقته
ALTER TABLE hr_employee
    ADD COLUMN resignation_date DATE NULL AFTER hire_date,
    ADD COLUMN is_resigned_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
