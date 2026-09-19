-- طلبات تعديل موقع العميل بعد الحفظ الأول (تحتاج موافقة مدير المبيعات)

CREATE TABLE IF NOT EXISTS crm_customer_gps_change (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  sales_rep_id INT UNSIGNED NULL DEFAULT NULL,
  requested_by INT UNSIGNED NOT NULL,
  old_latitude DECIMAL(10,7) NULL DEFAULT NULL,
  old_longitude DECIMAL(10,7) NULL DEFAULT NULL,
  new_latitude DECIMAL(10,7) NULL DEFAULT NULL,
  new_longitude DECIMAL(10,7) NULL DEFAULT NULL,
  new_accuracy DECIMAL(10,2) NULL DEFAULT NULL,
  clear_gps TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by INT UNSIGNED NULL DEFAULT NULL,
  decided_at DATETIME NULL DEFAULT NULL,
  decision_note VARCHAR(500) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ccgc_status (status, created_at),
  KEY idx_ccgc_customer (customer_id, status),
  CONSTRAINT fk_ccgc_customer FOREIGN KEY (customer_id) REFERENCES crm_customer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('crm_customer_gps_approve', 'اعتماد تعديل موقع العميل', 'screen', 243);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'crm_customer_gps_approve';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code IN ('sales_rep_route', 'sales_reps', 'sales_customer_orders_approve', 'sales_rep_visit_checkout_approve')
INNER JOIN sys_screen s ON s.code = 'crm_customer_gps_approve'
WHERE gp.allowed = 1;
