CREATE TABLE IF NOT EXISTS inv_stocktake_doc (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  take_no VARCHAR(20) NOT NULL,
  take_date DATE NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at DATETIME NULL,
  UNIQUE KEY uq_inv_stocktake_doc_no (take_no),
  KEY idx_inv_stocktake_doc_date (take_date),
  KEY idx_inv_stocktake_doc_wh (warehouse_id),
  KEY idx_inv_stocktake_doc_status (status),
  CONSTRAINT fk_istd_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
  CONSTRAINT fk_istd_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_stocktake_line (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_id BIGINT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  book_qty DECIMAL(18,6) NOT NULL DEFAULT 0,
  counted_qty DECIMAL(18,6) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_istl_doc_line (doc_id, line_no),
  KEY idx_istl_doc_item (doc_id, item_id),
  CONSTRAINT fk_istl_doc FOREIGN KEY (doc_id) REFERENCES inv_stocktake_doc(id) ON DELETE CASCADE,
  CONSTRAINT fk_istl_item FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
