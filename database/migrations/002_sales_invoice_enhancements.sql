-- تشغيل مرة واحدة على قاعدة namma_erp (أو اسم قاعدتك)
-- إضافة نقدي/ذمم، نسب ضريبة متعددة، وأعمدة ضريبة لبنود فاتورة البيع

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sys_tax_rate (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name_ar       VARCHAR(100) NOT NULL,
  rate_percent  DECIMAL(6,3) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tax_name_ar (name_ar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sys_tax_rate (name_ar, rate_percent, sort_order) VALUES
('معفى', 0.000, 0),
('ضريبة 5%', 5.000, 5),
('ضريبة قياسية 15%', 15.000, 10);

ALTER TABLE sal_invoice
  ADD COLUMN payment_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER warehouse_id;

ALTER TABLE sal_invoice_line
  ADD COLUMN tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER discount_pct,
  ADD COLUMN tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER tax_rate_percent,
  ADD COLUMN line_gross DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER tax_amount;

-- line_total = المجموع قبل الضريبة (بعد الخصم إن وُجد لاحقاً)
