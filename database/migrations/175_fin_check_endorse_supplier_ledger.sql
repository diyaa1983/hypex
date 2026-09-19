-- تجيير الشيك في كشف حساب المورد المُجيَّر إليه

ALTER TABLE crm_supplier_ledger
    MODIFY txn_type ENUM(
        'purchase_invoice',
        'purchase_return',
        'cash_payment',
        'journal_voucher',
        'check_return',
        'check_endorse'
    ) NOT NULL;
