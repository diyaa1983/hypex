-- تاريخ التسليم على طلب شراء العميل (موبايل الجولات + شاشة الكمبيوتر)
SET @db := DATABASE();

SET @has_dd := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_customer_order' AND COLUMN_NAME = 'delivery_date'
);
SET @sql_dd := IF(@has_dd = 0,
  'ALTER TABLE sal_customer_order ADD COLUMN delivery_date DATE NULL DEFAULT NULL AFTER notes',
  'SELECT 1');
PREPARE s_dd FROM @sql_dd; EXECUTE s_dd; DEALLOCATE PREPARE s_dd;
