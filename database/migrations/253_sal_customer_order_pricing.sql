-- أسعار وخصومات وضريبة لطلبات شراء العملاء (مطابقة هيكل فاتورة المبيعات)
SET @db := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order' AND COLUMN_NAME='subtotal'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order ADD COLUMN subtotal DECIMAL(18,6) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order' AND COLUMN_NAME='discount_amount'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order ADD COLUMN discount_amount DECIMAL(18,6) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order' AND COLUMN_NAME='tax_amount'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order ADD COLUMN tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order' AND COLUMN_NAME='total'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order ADD COLUMN total DECIMAL(18,6) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order' AND COLUMN_NAME='invoice_discount_input'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order ADD COLUMN invoice_discount_input VARCHAR(40) NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='qty_extra'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='unit_price'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN unit_price DECIMAL(18,10) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='discount_pct'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='discount_amount'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN discount_amount DECIMAL(18,10) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='line_total'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN line_total DECIMAL(18,10) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='tax_rate_percent'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='tax_amount'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN tax_amount DECIMAL(18,10) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order_line' AND COLUMN_NAME='line_gross'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order_line ADD COLUMN line_gross DECIMAL(18,10) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
