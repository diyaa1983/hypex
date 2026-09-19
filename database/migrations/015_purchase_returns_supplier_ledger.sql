-- ذمة المورد + مرتجع مشتريات (أعمدة pur_invoice تُضاف من PHP عبر pur_invoice_ensure_schema)

CREATE TABLE IF NOT EXISTS crm_supplier_ledger (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  txn_date      DATE NOT NULL,
  txn_type      ENUM('purchase_invoice','purchase_return') NOT NULL,
  ref_id        INT UNSIGNED NOT NULL,
  ref_no        VARCHAR(40) NOT NULL,
  payment_type  ENUM('cash','credit') NOT NULL DEFAULT 'credit',
  debit         DECIMAL(18,6) NOT NULL DEFAULT 0,
  credit        DECIMAL(18,6) NOT NULL DEFAULT 0,
  memo          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sup_led_ref (txn_type, ref_id),
  KEY idx_sup_led_sup (supplier_id, txn_date),
  CONSTRAINT fk_csuled_sup FOREIGN KEY (supplier_id) REFERENCES crm_supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pur_return (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no     VARCHAR(40) NOT NULL,
  return_date   DATE NOT NULL,
  supplier_id   INT UNSIGNED NOT NULL,
  invoice_id    INT UNSIGNED NOT NULL,
  warehouse_id  INT UNSIGNED NULL,
  subtotal      DECIMAL(18,6) NOT NULL DEFAULT 0,
  tax_amount    DECIMAL(18,6) NOT NULL DEFAULT 0,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  status        ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  notes         VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pur_return_no (return_no),
  KEY idx_pur_return_inv (invoice_id),
  CONSTRAINT fk_pret_sup FOREIGN KEY (supplier_id) REFERENCES crm_supplier(id),
  CONSTRAINT fk_pret_inv FOREIGN KEY (invoice_id) REFERENCES pur_invoice(id),
  CONSTRAINT fk_pret_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
  CONSTRAINT fk_pret_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pur_return_line (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id         INT UNSIGNED NOT NULL,
  invoice_line_id   INT UNSIGNED NOT NULL,
  item_id           INT UNSIGNED NOT NULL,
  qty               DECIMAL(18,6) NOT NULL,
  unit_price        DECIMAL(18,6) NOT NULL,
  tax_rate_percent  DECIMAL(6,3) NOT NULL DEFAULT 0,
  line_subtotal     DECIMAL(18,6) NOT NULL DEFAULT 0,
  tax_amount        DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_gross        DECIMAL(18,6) NOT NULL DEFAULT 0,
  CONSTRAINT fk_prll_ret FOREIGN KEY (return_id) REFERENCES pur_return(id) ON DELETE CASCADE,
  CONSTRAINT fk_prll_il FOREIGN KEY (invoice_line_id) REFERENCES pur_invoice_line(id),
  CONSTRAINT fk_prll_it FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
