-- الكمية الإضافية على بنود فواتير البيع والشراء (تُحرَّك في المخزون مع الكمية دون تأثير على المبلغ)
ALTER TABLE sal_invoice_line
  ADD COLUMN qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER qty;

ALTER TABLE pur_invoice_line
  ADD COLUMN qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER qty;
