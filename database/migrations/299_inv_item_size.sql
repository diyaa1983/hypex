-- حجم المادة: قائمة تعريفية فقط (ليست وحدة قياس)
USE hypex;

CREATE TABLE IF NOT EXISTS inv_item_size (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NULL DEFAULT NULL,
  name_ar VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_item_size_code (code),
  KEY idx_inv_item_size_active (is_active, sort_order, name_ar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_size := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_item' AND COLUMN_NAME = 'size_id'
);
SET @sql := IF(
  @has_size = 0,
  'ALTER TABLE inv_item ADD COLUMN size_id INT UNSIGNED NULL DEFAULT NULL AFTER category_id, ADD KEY idx_inv_item_size (size_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('item_sizes', 'حجم المادة', 'screen', 146);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'item_sizes';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen items ON items.id = gp.screen_id AND items.code = 'items'
INNER JOIN sys_screen s ON s.code = 'item_sizes'
WHERE gp.allowed = 1;
