-- سند تسليم: مستودع + صرف مخزون عند الترحيل
-- فاتورة مبيعات: ربط اختياري بسند تسليم (1:1)

ALTER TABLE sal_delivery
  ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER customer_id;

ALTER TABLE sal_delivery
  ADD CONSTRAINT fk_sdel_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL;

ALTER TABLE sal_invoice
  ADD COLUMN delivery_id INT UNSIGNED NULL AFTER warehouse_id;

ALTER TABLE sal_invoice
  ADD UNIQUE KEY uq_sal_invoice_delivery (delivery_id);

ALTER TABLE sal_invoice
  ADD CONSTRAINT fk_sal_delivery FOREIGN KEY (delivery_id) REFERENCES sal_delivery(id) ON DELETE SET NULL;
