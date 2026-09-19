-- مصدر إحداثيات الترحيل: هاتف أو نظام ويندوز

ALTER TABLE sal_invoice
    ADD COLUMN post_gps_source ENUM('mobile', 'desktop') NULL DEFAULT NULL AFTER post_gps_at;
