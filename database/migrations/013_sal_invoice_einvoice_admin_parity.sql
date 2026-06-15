-- حقول الفوترة على الفاتورة — مطابقة glx_sales في admin

ALTER TABLE sal_invoice ADD COLUMN reference_status VARCHAR(20) NULL;
ALTER TABLE sal_invoice ADD COLUMN return_id INT UNSIGNED NULL;
ALTER TABLE sal_invoice ADD COLUMN einv_hash VARCHAR(255) NULL;

ALTER TABLE sal_return ADD COLUMN reason_return TEXT NULL;
ALTER TABLE sal_return ADD COLUMN reference_status VARCHAR(20) NULL;

ALTER TABLE sal_invoice MODIFY COLUMN einv_status VARCHAR(50) NULL;
ALTER TABLE sal_invoice MODIFY COLUMN einv_num VARCHAR(100) NULL;
ALTER TABLE sal_invoice MODIFY COLUMN einv_inv_uuid VARCHAR(100) NULL;
ALTER TABLE sal_invoice MODIFY COLUMN invoice_uuid VARCHAR(50) NULL;

ALTER TABLE sal_return MODIFY COLUMN einv_status VARCHAR(50) NULL;
ALTER TABLE sal_return MODIFY COLUMN einv_num VARCHAR(100) NULL;
ALTER TABLE sal_return MODIFY COLUMN einv_inv_uuid VARCHAR(100) NULL;
ALTER TABLE sal_return MODIFY COLUMN invoice_uuid VARCHAR(50) NULL;
