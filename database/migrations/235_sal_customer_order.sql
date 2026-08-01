CREATE TABLE IF NOT EXISTS sal_customer_order (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(40) NOT NULL,
  order_date DATE NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  sales_rep_id INT UNSIGNED NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','approved') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  UNIQUE KEY uq_sal_customer_order_no (order_no),
  KEY idx_sal_customer_order_customer (customer_id),
  KEY idx_sal_customer_order_rep (sales_rep_id),
  KEY idx_sal_customer_order_status (status),
  KEY idx_sal_customer_order_date (order_date),
  CONSTRAINT fk_sco_customer FOREIGN KEY (customer_id) REFERENCES crm_customer(id),
  CONSTRAINT fk_sco_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL,
  CONSTRAINT fk_sco_warehouse FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
  CONSTRAINT fk_sco_created_by FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL,
  CONSTRAINT fk_sco_updated_by FOREIGN KEY (updated_by) REFERENCES sys_user(id) ON DELETE SET NULL,
  CONSTRAINT fk_sco_approved_by FOREIGN KEY (approved_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_customer_order_line (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL DEFAULT 1,
  item_id INT UNSIGNED NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  unit_id INT UNSIGNED NULL,
  unit_name VARCHAR(120) NULL,
  qty DECIMAL(18,6) NOT NULL,
  notes TEXT NULL,
  KEY idx_scol_order (order_id),
  KEY idx_scol_item (item_id),
  CONSTRAINT fk_scol_order FOREIGN KEY (order_id) REFERENCES sal_customer_order(id) ON DELETE CASCADE,
  CONSTRAINT fk_scol_item FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_customer_orders', 'طلبات شراء العملاء', 'screen', 235
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='sales_customer_orders');
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_customer_orders_approve', 'اعتماد طلبات الشراء', 'screen', 236
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='sales_customer_orders_approve');
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'm_customer_orders', 'طلبات شراء العملاء', 'screen', 237
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code='m_customer_orders');
INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1 FROM sys_group g INNER JOIN sys_screen s
ON s.code IN ('sales_customer_orders','sales_customer_orders_approve','m_customer_orders')
WHERE g.code IN ('ADMINS','administrators','admin');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'm_customer_orders'
WHERE g.code IN ('MOBILE', 'ADMINS');
