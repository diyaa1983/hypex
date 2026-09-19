-- ترحيل طلب شراء العميل إلى فاتورة بيع Oracle (MAS.DAILY / INV00024)

SET @db = DATABASE();

SET @s = (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_customer_order' AND COLUMN_NAME = 'oracle_v_num'
    ),
    'SELECT 1',
    'ALTER TABLE sal_customer_order
       ADD COLUMN oracle_v_num INT UNSIGNED NULL DEFAULT NULL,
       ADD COLUMN oracle_vyear SMALLINT UNSIGNED NULL DEFAULT NULL,
       ADD COLUMN oracle_posted_at DATETIME NULL DEFAULT NULL,
       ADD COLUMN oracle_post_status VARCHAR(20) NULL DEFAULT NULL,
       ADD COLUMN oracle_post_message VARCHAR(500) NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_warehouse' AND COLUMN_NAME = 'oracle_store'
    ),
    'SELECT 1',
    'ALTER TABLE inv_warehouse ADD COLUMN oracle_store INT UNSIGNED NULL DEFAULT NULL'
  )
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
