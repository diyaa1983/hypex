-- Namma ERP / Accounting core schema
-- Charset: utf8mb4 | Engine: InnoDB
--
-- مبدأ التصميم (إلزامي): كل كيان = جدول مستقل؛ المستندات = رأس + بنود في جدولين.
-- لا تخزين بيانات أعمال في JSON أو عمود واحد لعدة أنواع — استخدم FK بين الجداول.
--
-- البادئات: sys_ نظام | acc_ محاسبة | crm_ أطراف | inv_ مخزون | sal_ مبيعات | pur_ مشتريات | fin_ نقدية
-- خريطة الكيانات: includes/db_tables.php
-- ترحيل تدريجي: database/migrations/
--
-- إن لم تختر قاعدة من القائمة الجانبية، نفّذ السطرين التاليين تلقائياً:
CREATE DATABASE IF NOT EXISTS namma_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE namma_erp;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS inv_wh_move_line;
DROP TABLE IF EXISTS inv_wh_move;
DROP TABLE IF EXISTS inv_stock_move;
DROP TABLE IF EXISTS inv_movement_type;
DROP TABLE IF EXISTS acc_journal_line;
DROP TABLE IF EXISTS acc_journal_entry;
DROP TABLE IF EXISTS fin_credit_note_line;
DROP TABLE IF EXISTS fin_credit_note;
DROP TABLE IF EXISTS fin_debit_note_line;
DROP TABLE IF EXISTS fin_debit_note;
DROP TABLE IF EXISTS fin_voucher;
DROP TABLE IF EXISTS sal_return_line;
DROP TABLE IF EXISTS sal_return;
DROP TABLE IF EXISTS sal_invoice_line;
DROP TABLE IF EXISTS sal_invoice;
DROP TABLE IF EXISTS pur_invoice_line;
DROP TABLE IF EXISTS pur_invoice;
DROP TABLE IF EXISTS inv_item;
DROP TABLE IF EXISTS inv_warehouse;
DROP TABLE IF EXISTS crm_customer_ledger;
DROP TABLE IF EXISTS crm_customer;
DROP TABLE IF EXISTS crm_supplier;
DROP TABLE IF EXISTS crm_sales_rep;
DROP TABLE IF EXISTS acc_account;
DROP TABLE IF EXISTS sys_group_permission;
DROP TABLE IF EXISTS sys_user_group;
DROP TABLE IF EXISTS sys_screen;
DROP TABLE IF EXISTS sys_user;
DROP TABLE IF EXISTS sys_group;
DROP TABLE IF EXISTS sys_tax_rate;
DROP TABLE IF EXISTS sys_einvoice_settings;
DROP TABLE IF EXISTS sys_company_settings;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE sys_group (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40) NOT NULL UNIQUE,
  name_ar       VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_user (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(64) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  full_name_ar    VARCHAR(150) NOT NULL,
  email           VARCHAR(150) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_user_group (
  user_id   INT UNSIGNED NOT NULL,
  group_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, group_id),
  CONSTRAINT fk_sug_user   FOREIGN KEY (user_id)  REFERENCES sys_user(id)  ON DELETE CASCADE,
  CONSTRAINT fk_sug_group  FOREIGN KEY (group_id) REFERENCES sys_group(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_screen (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(64) NOT NULL UNIQUE,
  name_ar       VARCHAR(150) NOT NULL,
  screen_type   ENUM('screen','report') NOT NULL DEFAULT 'screen',
  sort_order    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_group_permission (
  group_id    INT UNSIGNED NOT NULL,
  screen_id   INT UNSIGNED NOT NULL,
  allowed     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (group_id, screen_id),
  CONSTRAINT fk_sgp_group  FOREIGN KEY (group_id)  REFERENCES sys_group(id)  ON DELETE CASCADE,
  CONSTRAINT fk_sgp_screen FOREIGN KEY (screen_id) REFERENCES sys_screen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_company_settings (
  id                  TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  company_name_ar     VARCHAR(255) NOT NULL DEFAULT 'اسم الشركة',
  tax_rate_percent    DECIMAL(6,3) NOT NULL DEFAULT 15.000,
  decimal_places      TINYINT UNSIGNED NOT NULL DEFAULT 2,
  rows_per_page       TINYINT UNSIGNED NOT NULL DEFAULT 10,
  logo_path           VARCHAR(500) NULL,
  address_ar          VARCHAR(500) NULL,
  phone               VARCHAR(50) NULL,
  email               VARCHAR(120) NULL,
  updated_at          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_einvoice_settings (
  id                TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  company_name      VARCHAR(255) NOT NULL DEFAULT '',
  trade_name        VARCHAR(255) NULL,
  vat_no            VARCHAR(64) NULL,
  gst_no            VARCHAR(64) NULL,
  company_email     VARCHAR(120) NULL,
  company_phone     VARCHAR(64) NULL,
  address           VARCHAR(500) NULL,
  city              VARCHAR(120) NULL,
  taxes_type        TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=income 2=sales VAT',
  invoice_cash      VARCHAR(10) NOT NULL DEFAULT '011',
  invoice_debit     VARCHAR(10) NOT NULL DEFAULT '021',
  client_id         VARCHAR(255) NULL,
  secret_key        LONGTEXT NULL,
  admin_email       VARCHAR(120) NULL,
  jofotara_api_url  VARCHAR(255) NOT NULL DEFAULT 'https://backend.jofotara.gov.jo/core/invoices/',
  notes             VARCHAR(500) NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_tax_rate (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name_ar       VARCHAR(100) NOT NULL,
  rate_percent  DECIMAL(6,3) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tax_name_ar (name_ar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_account (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(32) NOT NULL UNIQUE,
  name_ar        VARCHAR(200) NOT NULL,
  parent_id      INT UNSIGNED NULL,
  account_type   ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  is_leaf        TINYINT(1) NOT NULL DEFAULT 1,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  sort_order     INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_acc_parent FOREIGN KEY (parent_id) REFERENCES acc_account(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crm_sales_rep (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name_ar     VARCHAR(200) NOT NULL,
  phone       VARCHAR(40) NULL,
  address_ar  VARCHAR(500) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_sales_rep_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crm_customer (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40) NOT NULL UNIQUE,
  name_ar       VARCHAR(200) NOT NULL,
  phone         VARCHAR(40) NULL,
  email         VARCHAR(120) NULL,
  tax_number    VARCHAR(50) NULL,
  address_ar    VARCHAR(500) NULL,
  sales_rep_id  INT UNSIGNED NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_crm_cust_rep (sales_rep_id),
  CONSTRAINT fk_crm_cust_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crm_customer_sales_rep (
  customer_id   INT UNSIGNED NOT NULL,
  sales_rep_id  INT UNSIGNED NOT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (customer_id, sales_rep_id),
  KEY idx_ccsr_rep (sales_rep_id),
  CONSTRAINT fk_ccsr_customer FOREIGN KEY (customer_id) REFERENCES crm_customer(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccsr_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crm_customer_ledger (
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

CREATE TABLE crm_supplier (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL UNIQUE,
  name_ar     VARCHAR(200) NOT NULL,
  phone       VARCHAR(40) NULL,
  email       VARCHAR(120) NULL,
  tax_number  VARCHAR(50) NULL,
  address_ar  VARCHAR(500) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_warehouse (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL UNIQUE,
  name_ar     VARCHAR(200) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_movement_type (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40) NOT NULL,
  name_ar      VARCHAR(120) NOT NULL,
  hint_ar      VARCHAR(255) NULL,
  post_auto    TINYINT(1) NOT NULL DEFAULT 0,
  post_manual  TINYINT(1) NOT NULL DEFAULT 1,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  affects_gl   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   INT NOT NULL DEFAULT 0,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_movement_type_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_wh_move (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  move_no VARCHAR(20) NOT NULL,
  move_date DATE NOT NULL,
  movement_type_code VARCHAR(40) NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  warehouse_to_id INT UNSIGNED NULL,
  status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at DATETIME NULL,
  UNIQUE KEY uq_inv_wh_move_no (move_no),
  KEY idx_inv_wh_move_date (move_date),
  KEY idx_inv_wh_move_type (movement_type_code),
  KEY idx_inv_wh_move_status (status),
  CONSTRAINT fk_iwm_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
  CONSTRAINT fk_iwm_wh_to FOREIGN KEY (warehouse_to_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
  CONSTRAINT fk_iwm_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_wh_move_line (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  move_id BIGINT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(18,6) NOT NULL,
  CONSTRAINT fk_iwml_move FOREIGN KEY (move_id) REFERENCES inv_wh_move(id) ON DELETE CASCADE,
  CONSTRAINT fk_iwml_item FOREIGN KEY (item_id) REFERENCES inv_item(id),
  UNIQUE KEY uq_iwml_move_line (move_id, line_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_item_category (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name_ar     VARCHAR(200) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_cat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_unit (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name_ar     VARCHAR(100) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_unit_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_item (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku                   VARCHAR(64) NOT NULL UNIQUE,
  barcode               VARCHAR(14) NOT NULL,
  name_ar               VARCHAR(200) NOT NULL,
  category_id           INT UNSIGNED NULL,
  unit_id               INT UNSIGNED NULL,
  default_warehouse_id  INT UNSIGNED NULL,
  unit_name             VARCHAR(30) NOT NULL DEFAULT 'قطعة',
  default_cost          DECIMAL(18,6) NOT NULL DEFAULT 0,
  default_sale          DECIMAL(18,6) NOT NULL DEFAULT 0,
  track_inventory       TINYINT(1) NOT NULL DEFAULT 1,
  expiry_date           DATE NULL,
  notify_on_expiry      TINYINT(1) NOT NULL DEFAULT 0,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_item_barcode (barcode),
  CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES inv_item_category(id) ON DELETE SET NULL,
  CONSTRAINT fk_item_unit FOREIGN KEY (unit_id) REFERENCES inv_unit(id) ON DELETE SET NULL,
  CONSTRAINT fk_item_wh   FOREIGN KEY (default_warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sal_invoice (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no    VARCHAR(40) NOT NULL,
  invoice_date  DATE NOT NULL,
  customer_id   INT UNSIGNED NOT NULL,
  sales_rep_id  INT UNSIGNED NULL,
  warehouse_id  INT UNSIGNED NULL,
  payment_type  ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  subtotal      DECIMAL(18,6) NOT NULL DEFAULT 0,
  tax_amount    DECIMAL(18,6) NOT NULL DEFAULT 0,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  status        ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  notes         VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sal_invoice_no (invoice_no),
  CONSTRAINT fk_sal_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id),
  CONSTRAINT fk_sal_rep  FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL,
  CONSTRAINT fk_sal_wh   FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
  CONSTRAINT fk_sal_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sal_invoice_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  line_desc     VARCHAR(255) NULL,
  qty           DECIMAL(18,6) NOT NULL,
  qty_extra     DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_price    DECIMAL(18,6) NOT NULL,
  discount_pct      DECIMAL(6,3) NOT NULL DEFAULT 0,
  line_total        DECIMAL(18,6) NOT NULL,
  tax_rate_percent  DECIMAL(6,3) NOT NULL DEFAULT 0,
  tax_amount        DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_gross        DECIMAL(18,6) NOT NULL DEFAULT 0,
  CONSTRAINT fk_sill_inv FOREIGN KEY (invoice_id) REFERENCES sal_invoice(id) ON DELETE CASCADE,
  CONSTRAINT fk_sill_it  FOREIGN KEY (item_id)   REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sal_return (
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

CREATE TABLE sal_return_line (
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

CREATE TABLE pur_invoice (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no            VARCHAR(40) NOT NULL,
  supplier_invoice_no   VARCHAR(80) NULL,
  invoice_date          DATE NOT NULL,
  supplier_id   INT UNSIGNED NOT NULL,
  warehouse_id  INT UNSIGNED NULL,
  subtotal      DECIMAL(18,6) NOT NULL DEFAULT 0,
  tax_amount    DECIMAL(18,6) NOT NULL DEFAULT 0,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  status        ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  notes         VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pur_invoice_no (invoice_no),
  CONSTRAINT fk_pur_sup  FOREIGN KEY (supplier_id) REFERENCES crm_supplier(id),
  CONSTRAINT fk_pur_wh   FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
  CONSTRAINT fk_pur_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pur_invoice_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  line_desc     VARCHAR(255) NULL,
  qty           DECIMAL(18,6) NOT NULL,
  qty_extra     DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_price    DECIMAL(18,6) NOT NULL,
  discount_pct  DECIMAL(6,3) NOT NULL DEFAULT 0,
  line_total    DECIMAL(18,6) NOT NULL,
  CONSTRAINT fk_pill_inv FOREIGN KEY (invoice_id) REFERENCES pur_invoice(id) ON DELETE CASCADE,
  CONSTRAINT fk_pill_it  FOREIGN KEY (item_id)   REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_voucher (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_type    ENUM('receipt','payment') NOT NULL,
  voucher_no      VARCHAR(40) NOT NULL,
  voucher_date    DATE NOT NULL,
  amount          DECIMAL(18,6) NOT NULL,
  description     VARCHAR(500) NULL,
  party_type      ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
  party_id        INT UNSIGNED NULL,
  cash_account_id INT UNSIGNED NOT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_voucher_type_no (voucher_type, voucher_no),
  CONSTRAINT fk_fv_cash FOREIGN KEY (cash_account_id) REFERENCES acc_account(id),
  CONSTRAINT fk_fv_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_debit_note (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_no       VARCHAR(40) NOT NULL,
  note_date     DATE NOT NULL,
  party_type    ENUM('customer','supplier') NOT NULL,
  party_id      INT UNSIGNED NOT NULL,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  reason        VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_dn_no (note_no),
  CONSTRAINT fk_fdn_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_debit_note_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id       INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NULL,
  description_ar VARCHAR(255) NULL,
  qty           DECIMAL(18,6) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_total    DECIMAL(18,6) NOT NULL,
  CONSTRAINT fk_fdnl_note FOREIGN KEY (note_id) REFERENCES fin_debit_note(id) ON DELETE CASCADE,
  CONSTRAINT fk_fdnl_item FOREIGN KEY (item_id) REFERENCES inv_item(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_credit_note (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_no       VARCHAR(40) NOT NULL,
  note_date     DATE NOT NULL,
  party_type    ENUM('customer','supplier') NOT NULL,
  party_id      INT UNSIGNED NOT NULL,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  reason        VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_cn_no (note_no),
  CONSTRAINT fk_fcn_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_credit_note_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id       INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NULL,
  description_ar VARCHAR(255) NULL,
  qty           DECIMAL(18,6) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_total    DECIMAL(18,6) NOT NULL,
  CONSTRAINT fk_fcnl_note FOREIGN KEY (note_id) REFERENCES fin_credit_note(id) ON DELETE CASCADE,
  CONSTRAINT fk_fcnl_item FOREIGN KEY (item_id) REFERENCES inv_item(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_journal_entry (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no      VARCHAR(40) NOT NULL,
  entry_date    DATE NOT NULL,
  description_ar VARCHAR(500) NULL,
  status        ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acc_je_no (entry_no),
  CONSTRAINT fk_aje_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_journal_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id    INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  debit         DECIMAL(18,6) NOT NULL DEFAULT 0,
  credit        DECIMAL(18,6) NOT NULL DEFAULT 0,
  memo          VARCHAR(255) NULL,
  CONSTRAINT fk_ajl_j FOREIGN KEY (journal_id) REFERENCES acc_journal_entry(id) ON DELETE CASCADE,
  CONSTRAINT fk_ajl_a FOREIGN KEY (account_id) REFERENCES acc_account(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inv_stock_move (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  move_date     DATE NOT NULL,
  warehouse_id  INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  qty_delta     DECIMAL(18,6) NOT NULL,
  ref_type      VARCHAR(40) NOT NULL,
  ref_id        BIGINT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ism_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
  CONSTRAINT fk_ism_it FOREIGN KEY (item_id) REFERENCES inv_item(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seeds: screens, admin group, admin user, permissions (all screens), COA sample
-- ---------------------------------------------------------------------------

INSERT INTO sys_company_settings (id, company_name_ar, tax_rate_percent, decimal_places) VALUES
(1, 'شركة النماء الحيوية للصناعات الزراعية والبيطرية', 15.000, 2);

INSERT INTO sys_einvoice_settings (id) VALUES (1);

INSERT INTO sys_tax_rate (name_ar, rate_percent, sort_order) VALUES
('معفى', 0.000, 0),
('ضريبة 5%', 5.000, 5),
('ضريبة قياسية 15%', 15.000, 10);

INSERT INTO sys_group (code, name_ar, description) VALUES
('ADMINS', 'مديرو النظام', 'صلاحيات كاملة'),
('ACCOUNTING', 'محاسبون', 'مجموعة محاسبة'),
('VIEWERS', 'مشاهدون', 'قراءة فقط لشاشات محددة');

INSERT INTO sys_user (username, password_hash, full_name_ar, email, is_active) VALUES
('admin', '$2y$10$qI5ocJ2TWKNwF0o9JD54JOoJVbNrUFuQvF97YO5i1HExZfdas/fbi', 'مدير النظام', 'admin@local.test', 1);

INSERT INTO sys_user_group (user_id, group_id) VALUES (1, 1);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('dashboard', 'لوحة التحكم', 'screen', 10),
('sales_invoices', 'فواتير البيع', 'screen', 20),
('sales_returns', 'مردود مبيعات', 'screen', 25),
('purchase_invoices', 'فواتير الشراء', 'screen', 30),
('customers', 'العملاء', 'screen', 40),
('sales_reps', 'مندوبو المبيعات', 'screen', 45),
('suppliers', 'الموردون', 'screen', 50),
('users', 'المستخدمون', 'screen', 60),
('groups', 'مجموعات المستخدمين', 'screen', 70),
('permissions', 'صلاحيات الشاشات والتقارير', 'screen', 80),
('cash_receipt', 'سند قبض', 'screen', 90),
('cash_payment', 'سند صرف', 'screen', 100),
('debit_notes', 'إشعارات مدينة', 'screen', 110),
('credit_notes', 'إشعارات دائنة', 'screen', 120),
('chart_of_accounts', 'شجرة الحسابات', 'screen', 115),
('warehouses', 'المستودعات', 'screen', 130),
('items', 'المواد والأصناف', 'screen', 140),
('item_categories', 'فئات المواد', 'screen', 145),
('item_units', 'وحدات القياس', 'screen', 148),
('journal_entries', 'القيود المحاسبية', 'screen', 150),
('settings', 'الإعدادات', 'screen', 160),
('report_sales', 'تقرير المبيعات', 'report', 200),
('report_sales_by_rep', 'تقرير المبيعات حسب المندوب', 'report', 201),
('report_sales_by_item', 'تقرير المبيعات حسب المادة', 'report', 202),
('report_sales_returns', 'تقرير المرتجعات', 'report', 203),
('report_sales_returns_totals', 'إجمالي المرتجعات', 'report', 204),
('report_sales_qty_extra', 'تقرير الكميات الإضافية على الفواتير', 'report', 205),
('report_customers', 'تقرير العملاء', 'report', 206),
('report_suppliers', 'تقرير الموردين', 'report', 207),
('report_purchases', 'تقرير المشتريات بين تاريخين حسب المورد', 'report', 210),
('item_stock_movements', 'كشف حركات مادة', 'screen', 215),
('report_warehouse_items', 'تقرير المواد', 'report', 221),
('report_trial_balance', 'ميزان المراجعة', 'report', 230),
('report_journal', 'تقرير القيود', 'report', 240),
('report_invoice_tax', 'تقرير الضريبة المستحقة على الفواتير', 'report', 246),
('report_party_statement', 'كشف حساب مورد - عميل', 'report', 249),
('report_customer_statement', 'كشف حساب مورد - عميل', 'report', 250),
('report_supplier_statement', 'كشف حساب مورد - عميل', 'report', 260);

INSERT INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1 AS group_id, id AS screen_id, 1 FROM sys_screen;

INSERT INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 2 AS group_id, id AS screen_id, 1 FROM sys_screen WHERE code IN (
  'dashboard','sales_invoices','sales_invoices_list','sales_returns','sales_returns_list','purchase_invoices','purchase_invoices_list',
  'purchase_returns','purchase_returns_list','customers','sales_reps','suppliers',
  'cash_receipt','cash_payment','debit_notes','credit_notes','chart_of_accounts',
  'warehouses','items','item_categories','item_units','journal_entries',
  'report_sales','report_sales_by_rep','report_sales_by_item','report_sales_returns','report_sales_returns_totals','report_sales_qty_extra','report_customers','report_suppliers','report_purchases','item_stock_movements','report_warehouse_items','report_trial_balance','report_journal','report_invoice_tax',
  'report_party_statement','report_customer_statement','report_supplier_statement','settings'
);

INSERT INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 3 AS group_id, id AS screen_id, 1 FROM sys_screen WHERE code IN ('dashboard');

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order) VALUES
('1', 'الأصول', NULL, 'asset', 0, 1),
('2', 'الخصوم', NULL, 'liability', 0, 2),
('3', 'حقوق الملكية', NULL, 'equity', 0, 3),
('4', 'الإيرادات', NULL, 'revenue', 0, 4),
('5', 'المصروفات', NULL, 'expense', 0, 5);

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order) VALUES
('11', 'النقدية والبنوك', 1, 'asset', 0, 10),
('12', 'العملاء', 1, 'asset', 1, 20),
('13', 'المخزون', 1, 'asset', 1, 30),
('21', 'الموردون', 2, 'liability', 1, 10),
('41', 'مبيعات', 4, 'revenue', 1, 10),
('51', 'تكلفة البضاعة المباعة', 5, 'expense', 1, 10),
('52', 'مصروفات عمومية', 5, 'expense', 0, 20);

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order) VALUES
('111', 'الصندوق', 11, 'asset', 1, 11),
('112', 'البنك', 11, 'asset', 1, 12);
