-- سند قبض/صرف على كشف العميل

ALTER TABLE crm_customer_ledger
  MODIFY txn_type ENUM('sale_invoice','sale_return','cash_receipt','cash_payment') NOT NULL;

ALTER TABLE crm_customer_ledger
  MODIFY payment_type ENUM('cash','credit','check') NOT NULL DEFAULT 'credit';
