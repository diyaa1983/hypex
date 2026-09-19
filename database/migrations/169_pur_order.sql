-- طلبات الشراء (Purchase Orders)

CREATE TABLE IF NOT EXISTS pur_order (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(40) NOT NULL,
  order_date DATE NOT NULL,
  expected_date DATE NULL,
  supplier_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NULL,
  reference_no VARCHAR(80) NULL,
  payment_type ENUM('cash','credit') NOT NULL DEFAULT 'credit',
  subtotal DECIMAL(18,6) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
  total DECIMAL(18,6) NOT NULL DEFAULT 0,
  status ENUM('draft','submitted','approved','partial','closed','cancelled') NOT NULL DEFAULT 'draft',
  notes VARCHAR(500) NULL,
  invoice_discount_input VARCHAR(40) NULL,
  amount_decimals TINYINT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pur_order_no (order_no),
  KEY idx_pur_order_supplier (supplier_id),
  KEY idx_pur_order_date (order_date),
  KEY idx_pur_order_status (status),
  CONSTRAINT fk_pur_order_sup FOREIGN KEY (supplier_id) REFERENCES crm_supplier(id),
  CONSTRAINT fk_pur_order_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
  CONSTRAINT fk_pur_order_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL,
  CONSTRAINT fk_pur_order_approver FOREIGN KEY (approved_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pur_order_line (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  line_desc VARCHAR(255) NULL,
  qty DECIMAL(18,6) NOT NULL,
  qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0,
  qty_invoiced DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_price DECIMAL(18,6) NOT NULL,
  discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_total DECIMAL(18,6) NOT NULL,
  tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_gross DECIMAL(18,6) NOT NULL DEFAULT 0,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_poll_order FOREIGN KEY (order_id) REFERENCES pur_order(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_item FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ربط فاتورة الشراء بطلب الشراء (يُتجاهل الخطأ إن وُجد العمود أو الجدول)
ALTER TABLE pur_invoice ADD COLUMN order_id INT UNSIGNED NULL AFTER supplier_id;
ALTER TABLE pur_invoice ADD KEY idx_pur_inv_order (order_id);

-- شاشات طلبات الشراء
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'purchase_orders', 'طلب شراء', 'screen', 198
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_orders');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_invoices'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'purchase_orders';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'purchase_orders_documents_list', 'قائمة طلبات الشراء', 'screen', 199
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_orders_documents_list');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'purchase_orders_documents_list';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'purchase_orders_list', 'اعتماد طلبات الشراء', 'screen', 200
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_orders_list');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'purchase_orders_list';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_purchase_orders', 'تقرير طلبات الشراء', 'report', 201
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_purchase_orders');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_purchases'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_purchase_orders';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_purchase_orders_by_item', 'تقرير طلبات الشراء حسب المادة', 'report', 202
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_purchase_orders_by_item');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_purchases_by_item'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_purchase_orders_by_item';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_purchase_orders_open', 'تقرير طلبات الشراء المفتوحة', 'report', 203
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_purchase_orders_open');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_purchase_orders_open';

-- صلاحيات الإجراءات
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_approve_purchase_order', 'اعتماد طلب شراء', 'screen', 900
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_approve_purchase_order');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_approve_purchase_order';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unapprove_purchase_order', 'فك اعتماد طلب شراء', 'screen', 901
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unapprove_purchase_order');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_unapprove_purchase_order';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_delete_purchase_order', 'حذف طلب شراء', 'screen', 902
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_delete_purchase_order');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_delete_purchase_order';

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_convert_purchase_order', 'تحويل طلب شراء إلى فاتورة', 'screen', 903
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_convert_purchase_order');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'purchase_orders'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'action_convert_purchase_order';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
  'purchase_orders', 'purchase_orders_documents_list', 'purchase_orders_list',
  'report_purchase_orders', 'report_purchase_orders_by_item', 'report_purchase_orders_open',
  'action_approve_purchase_order', 'action_unapprove_purchase_order',
  'action_delete_purchase_order', 'action_convert_purchase_order'
)
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
