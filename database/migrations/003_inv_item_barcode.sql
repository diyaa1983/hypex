-- Barcode للمواد: توليد تلقائي + فريد + قابل للتعديل
-- نفّذ مرة واحدة على قاعدة namma_erp

USE namma_erp;

ALTER TABLE inv_item
  ADD COLUMN barcode VARCHAR(14) NULL AFTER sku;

UPDATE inv_item
SET barcode = LPAD(id, 6, '0')
WHERE barcode IS NULL OR TRIM(barcode) = '';

ALTER TABLE inv_item
  MODIFY barcode VARCHAR(14) NOT NULL;

ALTER TABLE inv_item
  ADD UNIQUE KEY uq_inv_item_barcode (barcode);
