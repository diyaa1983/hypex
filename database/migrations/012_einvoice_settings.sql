-- إعدادات الفوترة الإلكترونية (الأردن) + حقول الإرسال على فواتير البيع

CREATE TABLE IF NOT EXISTS sys_einvoice_settings (
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

INSERT IGNORE INTO sys_einvoice_settings (id) VALUES (1);

ALTER TABLE sal_invoice ADD COLUMN invoice_uuid VARCHAR(64) NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_status VARCHAR(40) NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_results TEXT NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_signed_invoice LONGTEXT NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_qr TEXT NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_num VARCHAR(80) NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_inv_uuid VARCHAR(80) NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_sent_at DATETIME NULL;

ALTER TABLE sal_return ADD COLUMN invoice_uuid VARCHAR(64) NULL;
ALTER TABLE sal_return ADD COLUMN einv_status VARCHAR(40) NULL;
ALTER TABLE sal_return ADD COLUMN einv_results TEXT NULL;
ALTER TABLE sal_return ADD COLUMN einv_signed_invoice LONGTEXT NULL;
ALTER TABLE sal_return ADD COLUMN einv_qr TEXT NULL;
ALTER TABLE sal_return ADD COLUMN einv_num VARCHAR(80) NULL;
ALTER TABLE sal_return ADD COLUMN einv_inv_uuid VARCHAR(80) NULL;
ALTER TABLE sal_return ADD COLUMN einv_sent_at DATETIME NULL;
ALTER TABLE sal_return ADD COLUMN einv_original_invoice_id INT UNSIGNED NULL;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'einvoice_settings', 'إعدادات الفوترة الإلكترونية', 'screen', 520
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'einvoice_settings');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'sales_send_einvoice', 'إرسال فاتورة للفوترة', 'screen', 196
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_send_einvoice');

-- لا تُمنح صلاحيات المجموعات هنا (كان يُعاد منح einvoice_settings مع كل طلب فاتورة مبيعات).
-- المنح من شاشة الصلاحيات أو عند أول مزامنة شاشة في sys_sync_screens_from_routes.
