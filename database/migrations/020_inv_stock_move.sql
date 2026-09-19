-- جدول حركات المخزون (إن وُجدت قاعدة قديمة بدونه)
CREATE TABLE IF NOT EXISTS inv_stock_move (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  move_date DATE NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty_delta DECIMAL(18,6) NOT NULL,
  ref_type VARCHAR(40) NOT NULL,
  ref_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ism_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
  CONSTRAINT fk_ism_it FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
