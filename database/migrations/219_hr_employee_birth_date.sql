-- تاريخ ميلاد الموظف
ALTER TABLE hr_employee
    ADD COLUMN birth_date DATE NULL AFTER national_id;
