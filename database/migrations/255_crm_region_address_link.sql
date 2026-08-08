-- فصل أسماء المناطق عن العناوين (حي/شارع) + ربط المندوب بعدة منطقة+عنوان
CREATE TABLE IF NOT EXISTS crm_region_address (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  region_id INT UNSIGNED NOT NULL,
  name_ar VARCHAR(180) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_ra_region_name (region_id, name_ar),
  KEY idx_crm_ra_region (region_id),
  CONSTRAINT fk_crm_ra_region FOREIGN KEY (region_id) REFERENCES crm_region(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_sales_rep_region_address (
  sales_rep_id INT UNSIGNED NOT NULL,
  region_address_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (sales_rep_id, region_address_id),
  KEY idx_csrra_addr (region_address_id),
  CONSTRAINT fk_csrra_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE CASCADE,
  CONSTRAINT fk_csrra_addr FOREIGN KEY (region_address_id) REFERENCES crm_region_address(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crm_customer' AND COLUMN_NAME = 'region_address_id'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE crm_customer ADD COLUMN region_address_id INT UNSIGNED NULL DEFAULT NULL AFTER region_id, ADD KEY idx_crm_customer_region_address (region_address_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- نقل address_ar القديم إلى crm_region_address (مرة واحدة؛ يُعاد بأمان عبر IGNORE)
INSERT IGNORE INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, TRIM(r.address_ar), r.sort_order, r.is_active
FROM crm_region r
WHERE r.address_ar IS NOT NULL AND TRIM(r.address_ar) <> '';
