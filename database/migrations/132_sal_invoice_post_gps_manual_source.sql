-- مصدر يدوي (تحديد على الخريطة بدون GPS المتصفح)

ALTER TABLE sal_invoice
    MODIFY COLUMN post_gps_source ENUM('mobile', 'desktop', 'manual') NULL DEFAULT NULL;
