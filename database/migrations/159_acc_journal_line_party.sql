-- ربط أسطر سند القيد بالعميل/المورد (ذمم) + دعم كشف الحساب

ALTER TABLE acc_journal_line
    ADD COLUMN party_type ENUM('customer','supplier') NULL DEFAULT NULL AFTER memo,
    ADD COLUMN party_id INT UNSIGNED NULL DEFAULT NULL AFTER party_type,
    ADD KEY idx_jl_party (party_type, party_id);

-- توسيع txn_type في دفتر العميل
ALTER TABLE crm_customer_ledger
    MODIFY txn_type ENUM(
        'sale_invoice',
        'sale_return',
        'cash_receipt',
        'cash_payment',
        'journal_voucher'
    ) NOT NULL;

-- توسيع txn_type في دفتر المورد
ALTER TABLE crm_supplier_ledger
    MODIFY txn_type ENUM(
        'purchase_invoice',
        'purchase_return',
        'cash_payment',
        'journal_voucher'
    ) NOT NULL;
