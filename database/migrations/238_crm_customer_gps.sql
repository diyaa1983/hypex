-- موقع العميل الجغرافي (GPS / خريطة)

ALTER TABLE crm_customer
    ADD COLUMN latitude DECIMAL(10, 7) NULL DEFAULT NULL AFTER address_ar,
    ADD COLUMN longitude DECIMAL(10, 7) NULL DEFAULT NULL AFTER latitude,
    ADD COLUMN gps_accuracy DECIMAL(10, 2) NULL DEFAULT NULL AFTER longitude,
    ADD COLUMN gps_at DATETIME NULL DEFAULT NULL AFTER gps_accuracy;
