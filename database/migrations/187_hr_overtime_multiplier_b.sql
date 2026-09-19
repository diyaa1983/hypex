-- العمل الإضافي — مضاعف ثانٍ (مثلاً ساعة ونصف)

ALTER TABLE hr_overtime_config
    ADD COLUMN IF NOT EXISTS hour_multiplier_b DECIMAL(6,3) NOT NULL DEFAULT 1.500 AFTER hour_multiplier;

UPDATE hr_overtime_config
SET hour_multiplier_b = 1.500
WHERE id = 1 AND (hour_multiplier_b IS NULL OR hour_multiplier_b <= 0);
