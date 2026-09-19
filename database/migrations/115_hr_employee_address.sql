-- عنوان الموظف (تبويب الهوية والتواصل)
ALTER TABLE hr_employee
    ADD COLUMN address_ar VARCHAR(500) NULL AFTER email,
    ADD COLUMN address_city VARCHAR(120) NULL AFTER address_ar,
    ADD COLUMN address_district VARCHAR(120) NULL AFTER address_city;
