-- خط سير المندوب: تعيين العملاء للزيارة من مدير المبيعات

CREATE TABLE IF NOT EXISTS sal_rep_route (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sales_rep_id INT UNSIGNED NOT NULL,
  route_date DATE NOT NULL,
  notes VARCHAR(500) NULL DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  updated_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sal_rep_route_day (sales_rep_id, route_date),
  KEY idx_sal_rep_route_date (route_date),
  CONSTRAINT fk_sal_rep_route_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_rep_route_line (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sal_rep_route_line (route_id, customer_id),
  KEY idx_sal_rep_route_line_cust (customer_id),
  CONSTRAINT fk_sal_rep_route_line_route FOREIGN KEY (route_id) REFERENCES sal_rep_route(id) ON DELETE CASCADE,
  CONSTRAINT fk_sal_rep_route_line_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_rep_route', 'خط سير المندوب', 'screen', 239
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_rep_route');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'sales_rep_route'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
