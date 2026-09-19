-- رقم فاتورة المورد (غير فريد — قد يتكرر)
ALTER TABLE pur_invoice
  ADD COLUMN supplier_invoice_no VARCHAR(80) NULL AFTER invoice_no;
