-- إعداد النظام: إرسال طلبات شراء العملاء من الموبايل تلقائياً عند الحفظ
-- 0 = يبقى في «غير المرسلة» حتى يرسله المندوب (السلوك الحالي)
-- 1 = يُرسل فور الحفظ ويظهر في نظام ويندوز

SET @db := DATABASE();

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sys_company_settings' AND COLUMN_NAME = 'mobile_order_auto_send'
);
SET @sql := IF(@col = 0,
  "ALTER TABLE sys_company_settings
   ADD COLUMN mobile_order_auto_send TINYINT(1) NOT NULL DEFAULT 0
   COMMENT 'إرسال طلبات الموبايل تلقائياً عند الحفظ'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
