-- شاشة العروض: رأس + بنود (كمية إضافية أو خصم %)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sal_offer (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_no VARCHAR(20) NOT NULL,
  name_ar VARCHAR(200) NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sal_offer_no (offer_no),
  KEY idx_sal_offer_dates (date_from, date_to),
  KEY idx_sal_offer_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_offer_line (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id BIGINT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL DEFAULT 1,
  item_id INT UNSIGNED NOT NULL,
  offer_type ENUM('bonus','discount_pct') NOT NULL DEFAULT 'bonus',
  trigger_qty DECIMAL(18,3) NOT NULL DEFAULT 1,
  bonus_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sal_offer_line_item (offer_id, item_id),
  KEY idx_sal_offer_line_item (item_id),
  CONSTRAINT fk_sal_offer_line_offer FOREIGN KEY (offer_id) REFERENCES sal_offer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_offer_application (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id BIGINT UNSIGNED NOT NULL,
  offer_line_id BIGINT UNSIGNED NULL,
  item_id INT UNSIGNED NOT NULL,
  doc_type ENUM('invoice','order') NOT NULL,
  doc_id BIGINT UNSIGNED NOT NULL,
  doc_no VARCHAR(40) NULL,
  doc_date DATE NOT NULL,
  qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  bonus_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_soa_offer (offer_id),
  KEY idx_soa_doc (doc_type, doc_id),
  KEY idx_soa_date (doc_date),
  KEY idx_soa_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_offers', 'شاشة العرض', 'screen', 155
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_offers');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_sales_offers', 'تقرير العروض', 'report', 208
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_sales_offers');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'sales_invoices'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('sales_offers', 'report_sales_offers');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('sales_offers', 'report_sales_offers')
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
