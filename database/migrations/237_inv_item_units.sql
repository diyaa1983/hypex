-- وحدات متعددة للمادة + أعمدة الوحدة على أسطر المستندات

CREATE TABLE IF NOT EXISTS inv_item_unit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NOT NULL,
  factor_to_base DECIMAL(18,6) NOT NULL DEFAULT 1,
  is_base TINYINT(1) NOT NULL DEFAULT 0,
  is_default_issue TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_item_unit (item_id, unit_id),
  KEY idx_inv_item_unit_item (item_id),
  CONSTRAINT fk_iiu_item FOREIGN KEY (item_id) REFERENCES inv_item(id) ON DELETE CASCADE,
  CONSTRAINT fk_iiu_unit FOREIGN KEY (unit_id) REFERENCES inv_unit(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ترحيل الوحدات الحالية كأساسية
INSERT IGNORE INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, i.unit_id, 1, 1, 1
FROM inv_item i
WHERE i.unit_id IS NOT NULL AND i.unit_id > 0;

-- أعمدة أسطر فاتورة المبيعات
SET @db := DATABASE();
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_invoice_line' AND COLUMN_NAME='unit_id'),
    'SELECT 1',
    'ALTER TABLE sal_invoice_line ADD COLUMN unit_id INT UNSIGNED NULL, ADD COLUMN unit_name VARCHAR(120) NULL, ADD COLUMN unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1, ADD COLUMN qty_base DECIMAL(18,6) NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='pur_invoice_line' AND COLUMN_NAME='unit_id'),
    'SELECT 1',
    'ALTER TABLE pur_invoice_line ADD COLUMN unit_id INT UNSIGNED NULL, ADD COLUMN unit_name VARCHAR(120) NULL, ADD COLUMN unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1, ADD COLUMN qty_base DECIMAL(18,6) NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='unit_factor'),
    'SELECT 1',
    'ALTER TABLE sal_customer_order_line ADD COLUMN unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1, ADD COLUMN qty_base DECIMAL(18,6) NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='pur_order_line' AND COLUMN_NAME='unit_id'),
    'SELECT 1',
    'ALTER TABLE pur_order_line ADD COLUMN unit_id INT UNSIGNED NULL, ADD COLUMN unit_name VARCHAR(120) NULL, ADD COLUMN unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1, ADD COLUMN qty_base DECIMAL(18,6) NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
