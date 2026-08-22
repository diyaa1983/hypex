-- عميل موبايل: فترة السداد + انتظار ربط Oracle

SET @db := DATABASE();

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crm_customer' AND COLUMN_NAME = 'payment_period'
);
SET @sql := IF(@col = 0,
  "ALTER TABLE crm_customer ADD COLUMN payment_period VARCHAR(32) NULL DEFAULT NULL AFTER address_ar",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crm_customer' AND COLUMN_NAME = 'oracle_pending'
);
SET @sql := IF(@col = 0,
  "ALTER TABLE crm_customer ADD COLUMN oracle_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER oracle_key",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
