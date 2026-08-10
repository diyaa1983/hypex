-- نوع تسعير العميل: سعر البيع أو سعر الجملة عند الفاتورة وطلب العميل
ALTER TABLE crm_customer
  ADD COLUMN use_wholesale_price TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1=سعر الجملة من بطاقة المادة، 0=سعر البيع'
  AFTER tax_number;
