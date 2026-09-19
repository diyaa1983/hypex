-- أقرب معلم/مكان مميز حسب إحداثيات GPS (يُجلب عند فتح الخريطة)

ALTER TABLE sal_invoice
    ADD COLUMN post_gps_landmark VARCHAR(300) NULL DEFAULT NULL;
