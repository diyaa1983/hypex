ALTER TABLE hr_employee
    ADD COLUMN subject_to_social_security TINYINT(1) NOT NULL DEFAULT 0
    AFTER social_security_no;
