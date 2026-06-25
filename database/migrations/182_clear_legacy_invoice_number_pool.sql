-- إزالة أرقام الفواتير القديمة من doc_number_pool (ترحيل سابق).
-- من الآن: إعادة استخدام الرقم تطبّق فقط عند حذف فاتورة بعد تفعيل القاعدة الجديدة.

DELETE FROM doc_number_pool WHERE pool_key IN ('sal_invoice', 'pur_invoice');
