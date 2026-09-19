-- بطاقة المادة: اسم إنجليزي + سعر جملة + ضريبة المادة
SET @db := DATABASE();

SET @has_name_en := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_item' AND COLUMN_NAME = 'name_en'
);
SET @sql := IF(@has_name_en = 0,
  'ALTER TABLE inv_item ADD COLUMN name_en VARCHAR(200) NULL DEFAULT NULL AFTER name_ar',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_wholesale := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_item' AND COLUMN_NAME = 'default_wholesale'
);
SET @sql := IF(@has_wholesale = 0,
  'ALTER TABLE inv_item ADD COLUMN default_wholesale DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER default_sale',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_tax := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_item' AND COLUMN_NAME = 'tax_rate_id'
);
SET @sql := IF(@has_tax = 0,
  'ALTER TABLE inv_item ADD COLUMN tax_rate_id INT UNSIGNED NULL DEFAULT NULL AFTER default_wholesale',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
