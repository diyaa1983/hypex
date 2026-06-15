-- خصم البنود (قبل الضريبة) وخصم الفاتورة
-- (بدون IF NOT EXISTS — متوافق مع MySQL 5.7 / MariaDB في XAMPP)
SET NAMES utf8mb4;

ALTER TABLE sal_invoice_line
  ADD COLUMN discount_amount DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER discount_pct;

ALTER TABLE pur_invoice_line
  ADD COLUMN discount_amount DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER discount_pct;

ALTER TABLE sal_invoice
  ADD COLUMN invoice_discount_input VARCHAR(40) NULL DEFAULT NULL AFTER notes;

ALTER TABLE pur_invoice
  ADD COLUMN invoice_discount_input VARCHAR(40) NULL DEFAULT NULL AFTER notes;
