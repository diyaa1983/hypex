-- نوع دفع «شيك» في دفتر العملاء (سند قبض/صرف بشيك)
ALTER TABLE crm_customer_ledger
  MODIFY payment_type ENUM('cash','credit','check') NOT NULL DEFAULT 'credit';

UPDATE crm_customer_ledger
SET payment_type = 'check'
WHERE payment_type = 'cash'
  AND txn_type IN ('cash_receipt', 'cash_payment')
  AND memo LIKE '%شيك:%';
