-- زيارة المندوب: إحداثيات الدخول/الخروج + طلب خروج يدوي بعد دخول GPS
SET @db := DATABASE();

-- أعمدة GPS على خط السير اليومي
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_rep_route_line' AND COLUMN_NAME = 'checkin_lat'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE sal_rep_route_line
     ADD COLUMN checkin_lat DECIMAL(10,7) NULL DEFAULT NULL,
     ADD COLUMN checkin_lng DECIMAL(10,7) NULL DEFAULT NULL,
     ADD COLUMN checkin_accuracy DECIMAL(10,2) NULL DEFAULT NULL,
     ADD COLUMN checkin_distance_m DECIMAL(10,2) NULL DEFAULT NULL,
     ADD COLUMN checkout_lat DECIMAL(10,7) NULL DEFAULT NULL,
     ADD COLUMN checkout_lng DECIMAL(10,7) NULL DEFAULT NULL,
     ADD COLUMN checkout_accuracy DECIMAL(10,2) NULL DEFAULT NULL,
     ADD COLUMN checkout_distance_m DECIMAL(10,2) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ضمان أعمدة الزيارة الأساسية (إن لم تُنفَّذ 266)
SET @has2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_rep_route_line' AND COLUMN_NAME = 'visit_checkin_at'
);
SET @sql2 := IF(@has2 = 0,
  'ALTER TABLE sal_rep_route_line
     ADD COLUMN visit_checkin_at DATETIME NULL DEFAULT NULL,
     ADD COLUMN visit_checkout_at DATETIME NULL DEFAULT NULL,
     ADD COLUMN checkin_method VARCHAR(20) NULL DEFAULT NULL,
     ADD COLUMN checkout_method VARCHAR(20) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

CREATE TABLE IF NOT EXISTS sal_rep_visit_checkout_request (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_line_id INT UNSIGNED NOT NULL,
  sales_rep_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NULL DEFAULT NULL,
  request_lat DECIMAL(10,7) NULL DEFAULT NULL,
  request_lng DECIMAL(10,7) NULL DEFAULT NULL,
  request_accuracy DECIMAL(10,2) NULL DEFAULT NULL,
  request_distance_m DECIMAL(10,2) NULL DEFAULT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by INT UNSIGNED NULL DEFAULT NULL,
  decided_at DATETIME NULL DEFAULT NULL,
  decision_note VARCHAR(500) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_srvcr_status (status, created_at),
  KEY idx_srvcr_rep (sales_rep_id, status),
  KEY idx_srvcr_line (route_line_id),
  CONSTRAINT fk_srvcr_line FOREIGN KEY (route_line_id) REFERENCES sal_rep_route_line(id) ON DELETE CASCADE,
  CONSTRAINT fk_srvcr_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE CASCADE,
  CONSTRAINT fk_srvcr_customer FOREIGN KEY (customer_id) REFERENCES crm_customer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- شاشة الموبايل
INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_rep_visits', 'هاتف — تسجيل زيارة العميل', 'screen', 9045);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code IN ('MOBILE', 'ADMINS') AND s.code = 'm_rep_visits';

-- شاشة ويندوز لاعتماد الخروج اليدوي
INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('sales_rep_visit_checkout_approve', 'اعتماد خروج يدوي من الزيارة', 'screen', 242);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'sales_rep_visit_checkout_approve';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code IN ('sales_rep_route', 'sales_reps', 'sales_customer_orders_approve')
INNER JOIN sys_screen s ON s.code = 'sales_rep_visit_checkout_approve'
WHERE gp.allowed = 1;
