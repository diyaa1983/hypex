-- مردود مبيعات (إرجاع فاتورة بيع)
USE namma_erp;

CREATE TABLE IF NOT EXISTS sal_return (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no     VARCHAR(40) NOT NULL,
  return_date   DATE NOT NULL,
  customer_id   INT UNSIGNED NOT NULL,
  invoice_id    INT UNSIGNED NOT NULL,
  warehouse_id  INT UNSIGNED NULL,
  subtotal      DECIMAL(18,6) NOT NULL DEFAULT 0,
  tax_amount    DECIMAL(18,6) NOT NULL DEFAULT 0,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  status        ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  notes         VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sal_return_no (return_no),
  KEY idx_sal_return_inv (invoice_id),
  CONSTRAINT fk_sret_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id),
  CONSTRAINT fk_sret_inv  FOREIGN KEY (invoice_id) REFERENCES sal_invoice(id),
  CONSTRAINT fk_sret_wh   FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
  CONSTRAINT fk_sret_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sal_return_line (
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
  CONSTRAINT fk_srll_ret FOREIGN KEY (return_id) REFERENCES sal_return(id) ON DELETE CASCADE,
  CONSTRAINT fk_srll_il  FOREIGN KEY (invoice_line_id) REFERENCES sal_invoice_line(id),
  CONSTRAINT fk_srll_it  FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
