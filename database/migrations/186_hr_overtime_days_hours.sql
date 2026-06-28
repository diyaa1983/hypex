-- العمل الإضافي — أيام الشهر وساعات اليوم لاحتساب أجر الساعة

ALTER TABLE hr_overtime_config
    ADD COLUMN IF NOT EXISTS monthly_work_days DECIMAL(6,3) NOT NULL DEFAULT 30.000 AFTER hour_multiplier,
    ADD COLUMN IF NOT EXISTS daily_work_hours DECIMAL(6,3) NOT NULL DEFAULT 8.000 AFTER monthly_work_days;

UPDATE hr_overtime_config
SET monthly_work_hours = monthly_work_days * daily_work_hours
WHERE id = 1;
