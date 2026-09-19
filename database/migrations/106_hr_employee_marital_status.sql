-- الحالة الاجتماعية للموظف (0 = أعزب، 1 = متزوج)
ALTER TABLE hr_employee
    ADD COLUMN is_married TINYINT(1) NOT NULL DEFAULT 0 AFTER gender;
