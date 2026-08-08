-- ربط المندوب بالمناطق (مندوب يغطي منطقة أو أكثر)
CREATE TABLE IF NOT EXISTS crm_sales_rep_region (
  sales_rep_id INT UNSIGNED NOT NULL,
  region_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (sales_rep_id, region_id),
  KEY idx_csrr_region (region_id),
  CONSTRAINT fk_csrr_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE CASCADE,
  CONSTRAINT fk_csrr_region FOREIGN KEY (region_id) REFERENCES crm_region(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
