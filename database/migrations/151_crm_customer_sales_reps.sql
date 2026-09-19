-- ربط العميل بعدة مندوبي مبيعات
CREATE TABLE IF NOT EXISTS crm_customer_sales_rep (
  customer_id   INT UNSIGNED NOT NULL,
  sales_rep_id  INT UNSIGNED NOT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (customer_id, sales_rep_id),
  KEY idx_ccsr_rep (sales_rep_id),
  CONSTRAINT fk_ccsr_customer FOREIGN KEY (customer_id) REFERENCES crm_customer(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccsr_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, c.sales_rep_id, 0
FROM crm_customer c
WHERE c.sales_rep_id IS NOT NULL;
