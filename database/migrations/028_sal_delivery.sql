-- سند تسليم بضاعة (مرجع فقط — لا أثر على الذمم أو المخزون)

CREATE TABLE IF NOT EXISTS sal_delivery (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_no   VARCHAR(40) NOT NULL,
  delivery_date DATE NOT NULL,
  customer_id   INT UNSIGNED NOT NULL,
  status        ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  notes         VARCHAR(500) NULL,
  is_posted     TINYINT(1) NOT NULL DEFAULT 0,
  posted_at     DATETIME NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sal_delivery_no (delivery_no),
  KEY idx_sal_delivery_cust_date (customer_id, delivery_date),
  CONSTRAINT fk_sdel_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id),
  CONSTRAINT fk_sdel_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_delivery_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id   INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  line_desc     VARCHAR(255) NULL,
  qty           DECIMAL(18,6) NOT NULL,
  sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_sdll_del FOREIGN KEY (delivery_id) REFERENCES sal_delivery(id) ON DELETE CASCADE,
  CONSTRAINT fk_sdll_item FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_delivery', 'سند تسليم بضاعة', 'screen', 26
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_delivery');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'sales_delivery'
WHERE g.code IN ('administrators', 'admin');
