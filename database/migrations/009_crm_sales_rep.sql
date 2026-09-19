-- مندوبو المبيعات
USE namma_erp;

CREATE TABLE IF NOT EXISTS crm_sales_rep (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name_ar     VARCHAR(200) NOT NULL,
  phone       VARCHAR(40) NULL,
  address_ar  VARCHAR(500) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_sales_rep_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
