-- منطقة العملاء: حقل العنوان + مساحة الاسم
ALTER TABLE crm_region
  MODIFY name_ar VARCHAR(180) NOT NULL;

SET @db := DATABASE();

SET @has_addr := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crm_region' AND COLUMN_NAME = 'address_ar'
);
SET @sql := IF(
  @has_addr = 0,
  'ALTER TABLE crm_region ADD COLUMN address_ar VARCHAR(255) NULL DEFAULT NULL AFTER name_ar',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
