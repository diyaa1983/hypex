-- نوع الدفع لطلب شراء العميل: ذمم (credit) أو نقدي (cash) — للترحيل إلى Oracle CACR/CASH

SET @db = DATABASE();

SET @s = (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_customer_order' AND COLUMN_NAME = 'payment_type'
    ),
    'SELECT 1',
    "ALTER TABLE sal_customer_order
       ADD COLUMN payment_type ENUM('credit','cash') NOT NULL DEFAULT 'credit' AFTER warehouse_id"
  )
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
