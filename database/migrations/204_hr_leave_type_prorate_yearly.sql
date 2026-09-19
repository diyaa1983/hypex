-- جدولة الإجازة على مدار السنة (تقسيم حسب تاريخ التعيين)

ALTER TABLE hr_leave_type
    ADD COLUMN prorate_yearly TINYINT(1) NOT NULL DEFAULT 0 AFTER default_days;
