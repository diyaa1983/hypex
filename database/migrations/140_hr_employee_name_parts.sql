ALTER TABLE hr_employee
    ADD COLUMN name_first VARCHAR(80) NOT NULL DEFAULT '' AFTER name_ar,
    ADD COLUMN name_father VARCHAR(80) NOT NULL DEFAULT '' AFTER name_first,
    ADD COLUMN name_grandfather VARCHAR(80) NOT NULL DEFAULT '' AFTER name_father,
    ADD COLUMN name_family VARCHAR(80) NOT NULL DEFAULT '' AFTER name_grandfather;
