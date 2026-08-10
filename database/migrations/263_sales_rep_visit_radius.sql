-- حدود منطقة العميل (نصف قطر الزيارة المسموح للمندوب بالمتر)
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sys_company_settings'
    AND COLUMN_NAME = 'sales_rep_visit_radius_m'
);
SET @sql := IF(
  @col = 0,
  'ALTER TABLE sys_company_settings ADD COLUMN sales_rep_visit_radius_m INT UNSIGNED NOT NULL DEFAULT 200 AFTER sales_rep_visit_geofence',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
