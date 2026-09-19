-- اسم المنطقة/العنوان عند ترحيل الفاتورة (من إحداثيات GPS)

ALTER TABLE sal_invoice
    ADD COLUMN post_gps_place VARCHAR(500) NULL DEFAULT NULL AFTER post_gps_source;
