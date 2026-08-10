-- تعديل أسعار البيع: سعر الجملة + موظف الترحيل
SET @db := DATABASE();

SET @has_ow := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_item_sale_price_adj' AND COLUMN_NAME = 'old_wholesale'
);
SET @sql := IF(@has_ow = 0,
  'ALTER TABLE inv_item_sale_price_adj ADD COLUMN old_wholesale DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER new_sale_price',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_nw := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_item_sale_price_adj' AND COLUMN_NAME = 'new_wholesale'
);
SET @sql := IF(@has_nw = 0,
  'ALTER TABLE inv_item_sale_price_adj ADD COLUMN new_wholesale DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER old_wholesale',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- رأس الوثيقة: posted_by
SET @has_pb := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_price_adj_doc' AND COLUMN_NAME = 'posted_by'
);
SET @sql := IF(@has_pb = 0,
  'ALTER TABLE inv_price_adj_doc ADD COLUMN posted_by INT UNSIGNED NULL DEFAULT NULL AFTER posted_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('item_sale_price_adjust', 'تعديل أسعار البيع', 'screen', 152),
('report_item_price_adjustments', 'تقرير الأسعار المعدّلة', 'report', 153);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s
WHERE s.code IN ('item_sale_price_adjust', 'report_item_price_adjustments');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen items ON items.id = gp.screen_id AND items.code = 'items'
INNER JOIN sys_screen s ON s.code = 'report_item_price_adjustments'
WHERE gp.allowed = 1;
