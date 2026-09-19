-- المستخدم الذي رحّل الفاتورة (يُحفظ عند أول ترحيل ناجح)

ALTER TABLE sal_invoice
    ADD COLUMN posted_by INT UNSIGNED NULL DEFAULT NULL;
