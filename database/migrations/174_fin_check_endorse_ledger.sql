-- تجيير الشيك في كشف حساب العميل

ALTER TABLE crm_customer_ledger
    MODIFY txn_type ENUM(
        'sale_invoice',
        'sale_return',
        'cash_receipt',
        'cash_payment',
        'journal_voucher',
        'check_return',
        'check_endorse'
    ) NOT NULL;
