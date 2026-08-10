-- جولات المندوبين: خطة زيارات بين تاريخين + ترحيل لخط السير اليومي (الموبايل)

CREATE TABLE IF NOT EXISTS sal_rep_tour (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sales_rep_id INT UNSIGNED NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  notes VARCHAR(500) NULL DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  posted_at DATETIME NULL DEFAULT NULL,
  posted_by INT UNSIGNED NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  updated_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sal_rep_tour_rep (sales_rep_id),
  KEY idx_sal_rep_tour_dates (date_from, date_to),
  KEY idx_sal_rep_tour_status (status),
  CONSTRAINT fk_sal_rep_tour_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_rep_tour_line (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tour_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  region_id INT UNSIGNED NULL DEFAULT NULL,
  region_address_id INT UNSIGNED NULL DEFAULT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sal_rep_tour_line (tour_id, customer_id),
  KEY idx_sal_rep_tour_line_cust (customer_id),
  KEY idx_sal_rep_tour_line_dates (date_from, date_to),
  CONSTRAINT fk_sal_rep_tour_line_tour FOREIGN KEY (tour_id) REFERENCES sal_rep_tour(id) ON DELETE CASCADE,
  CONSTRAINT fk_sal_rep_tour_line_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ربط خط السير اليومي بالجولات (إن وُجد الجدول)
SET @tour_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sal_rep_route' AND COLUMN_NAME = 'tour_id'
);
SET @sql_tour := IF(
  @tour_col = 0,
  'ALTER TABLE sal_rep_route ADD COLUMN tour_id INT UNSIGNED NULL DEFAULT NULL AFTER notes, ADD KEY idx_sal_rep_route_tour (tour_id)',
  'SELECT 1'
);
PREPARE stmt_tour FROM @sql_tour;
EXECUTE stmt_tour;
DEALLOCATE PREPARE stmt_tour;

UPDATE sys_screen SET name_ar = 'جولات المندوبين' WHERE code = 'sales_rep_route';
