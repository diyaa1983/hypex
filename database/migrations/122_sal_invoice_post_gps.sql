-- إحداثيات GPS عند ترحيل فاتورة المبيعات (من تطبيق الهاتف)

ALTER TABLE sal_invoice
    ADD COLUMN post_latitude DECIMAL(10, 7) NULL DEFAULT NULL AFTER delivery_id,
    ADD COLUMN post_longitude DECIMAL(10, 7) NULL DEFAULT NULL AFTER post_latitude,
    ADD COLUMN post_gps_accuracy DECIMAL(10, 2) NULL DEFAULT NULL AFTER post_longitude,
    ADD COLUMN post_gps_at DATETIME NULL DEFAULT NULL AFTER post_gps_accuracy;
