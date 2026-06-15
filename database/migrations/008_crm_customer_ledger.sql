-- حركات حساب العميل (فواتير بيع ذمم + مردودات)
USE namma_erp;

CREATE TABLE IF NOT EXISTS crm_customer_ledger (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  txn_date      DATE NOT NULL,
  txn_type      ENUM('sale_invoice','sale_return') NOT NULL,
  ref_id        INT UNSIGNED NOT NULL,
  ref_no        VARCHAR(40) NOT NULL,
  payment_type  ENUM('cash','credit') NOT NULL DEFAULT 'credit',
  debit         DECIMAL(18,6) NOT NULL DEFAULT 0,
  credit        DECIMAL(18,6) NOT NULL DEFAULT 0,
  memo          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ccl_ref (txn_type, ref_id),
  KEY idx_ccl_cust_date (customer_id, txn_date),
  CONSTRAINT fk_ccl_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
