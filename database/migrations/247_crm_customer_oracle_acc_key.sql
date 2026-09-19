-- رقم/مفتاح حساب العميل من Oracle (AGLACTMF.ACC_NUM غالباً)
SET @db := DATABASE();

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crm_customer' AND COLUMN_NAME = 'oracle_acc_key'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE crm_customer ADD COLUMN oracle_acc_key VARCHAR(80) NULL, ADD KEY idx_crm_customer_oracle_acc (oracle_acc_key)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
