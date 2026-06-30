-- كمية إضافية على بنود مرتجع المبيعات (مطابقة لفاتورة البيع — للمخزون فقط).
ALTER TABLE sal_return_line
  ADD COLUMN qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER qty;
