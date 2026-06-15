-- تاريخ انتهاء المادة + إشعار عند الانتهاء
USE namma_erp;

ALTER TABLE inv_item ADD COLUMN expiry_date DATE NULL AFTER track_inventory;
ALTER TABLE inv_item ADD COLUMN notify_on_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date;
