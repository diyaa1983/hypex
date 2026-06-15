-- سند صرف على كشف المورد + نوع دفع شيك

ALTER TABLE crm_supplier_ledger
  MODIFY txn_type ENUM('purchase_invoice','purchase_return','cash_payment') NOT NULL;

ALTER TABLE crm_supplier_ledger
  MODIFY payment_type ENUM('cash','credit','check') NOT NULL DEFAULT 'credit';
