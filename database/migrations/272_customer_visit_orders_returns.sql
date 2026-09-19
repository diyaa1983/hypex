-- قائمة العملاء / إرسال الطلبات / مرتجع طلب شراء عميل
SET @db := DATABASE();

-- إخفاء طلب الموبايل عن ويندوز حتى يرسله المندوب
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order' AND COLUMN_NAME='is_sent'),
  'SELECT 1',
  'ALTER TABLE sal_customer_order
     ADD COLUMN is_sent TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
     ADD COLUMN sent_at DATETIME NULL DEFAULT NULL AFTER is_sent,
     ADD COLUMN sent_by INT UNSIGNED NULL DEFAULT NULL AFTER sent_at,
     ADD KEY idx_sco_is_sent (is_sent)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- الطلبات الحالية تُعتبر مرسلة (ظاهر على ويندوز)
UPDATE sal_customer_order SET is_sent = 1 WHERE is_sent = 0 AND created_at < NOW();

CREATE TABLE IF NOT EXISTS sal_customer_order_return (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(40) NOT NULL,
  return_date DATE NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  sales_rep_id INT UNSIGNED NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  subtotal DECIMAL(18,6) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
  total DECIMAL(18,6) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  posted_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  posted_at DATETIME NULL,
  UNIQUE KEY uq_sco_return_no (return_no),
  KEY idx_sco_ret_customer (customer_id),
  KEY idx_sco_ret_order (order_id),
  KEY idx_sco_ret_status (status),
  KEY idx_sco_ret_date (return_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_customer_order_return_line (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id INT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL DEFAULT 1,
  order_line_id INT UNSIGNED NULL,
  item_id INT UNSIGNED NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  unit_id INT UNSIGNED NULL,
  unit_name VARCHAR(120) NULL,
  unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1,
  qty DECIMAL(18,6) NOT NULL DEFAULT 0,
  qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0,
  qty_base DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_price DECIMAL(18,10) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(18,10) NOT NULL DEFAULT 0,
  line_total DECIMAL(18,10) NOT NULL DEFAULT 0,
  tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(18,10) NOT NULL DEFAULT 0,
  line_gross DECIMAL(18,10) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  KEY idx_scorl_return (return_id),
  KEY idx_scorl_item (item_id),
  CONSTRAINT fk_scorl_return FOREIGN KEY (return_id) REFERENCES sal_customer_order_return(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'm_customer_list', 'قائمة العملاء', 'screen', 238
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='m_customer_list');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'm_customer_orders_pending', 'طلبات غير مرسلة', 'screen', 239
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='m_customer_orders_pending');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'm_customer_orders_sent', 'الطلبات المرسلة', 'screen', 240
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='m_customer_orders_sent');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'm_customer_orders_query', 'طلبات عملاء', 'screen', 241
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='m_customer_orders_query');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'm_customer_order_returns', 'مرتجع طلب شراء عميل', 'screen', 242
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='m_customer_order_returns');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_customer_order_returns', 'مرتجع طلب شراء عميل', 'screen', 243
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='sales_customer_order_returns');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_customer_order_returns', 'تقرير مرتجعات طلبات الشراء', 'report', 244
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='report_customer_order_returns');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
  'm_customer_list','m_customer_orders_pending','m_customer_orders_sent',
  'm_customer_orders_query','m_customer_order_returns',
  'sales_customer_order_returns','report_customer_order_returns'
)
WHERE g.code IN ('MOBILE','ADMINS','administrators','admin');
