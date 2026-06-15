-- سندات قبض وصرف (بدون حذف بيانات)

CREATE TABLE IF NOT EXISTS fin_voucher (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_type    ENUM('receipt','payment') NOT NULL,
  voucher_no      VARCHAR(40) NOT NULL,
  voucher_date    DATE NOT NULL,
  amount          DECIMAL(18,6) NOT NULL,
  description     VARCHAR(500) NULL,
  check_no        VARCHAR(80) NULL,
  party_type      ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
  party_id        INT UNSIGNED NULL,
  cash_account_id INT UNSIGNED NOT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_voucher_type_no (voucher_type, voucher_no),
  KEY idx_fv_type_date (voucher_type, voucher_date),
  KEY idx_fv_party (party_type, party_id),
  CONSTRAINT fk_fv_cash FOREIGN KEY (cash_account_id) REFERENCES acc_account(id),
  CONSTRAINT fk_fv_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
