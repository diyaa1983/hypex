-- تعديل أسعار المواد: رأس حركة (رقم تسلسلي) + بنود

CREATE TABLE IF NOT EXISTS inv_price_adj_doc (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  adj_no VARCHAR(20) NOT NULL,
  adj_date DATE NOT NULL,
  status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at DATETIME NULL,
  UNIQUE KEY uq_inv_price_adj_doc_no (adj_no),
  KEY idx_inv_price_adj_doc_status (status),
  KEY idx_inv_price_adj_doc_date (adj_date),
  CONSTRAINT fk_ipad_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inv_item_sale_price_adj
  ADD COLUMN doc_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN line_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER doc_id;

ALTER TABLE inv_item_sale_price_adj
  ADD KEY idx_iispa_doc (doc_id),
  ADD CONSTRAINT fk_iispa_doc FOREIGN KEY (doc_id) REFERENCES inv_price_adj_doc(id) ON DELETE CASCADE;
