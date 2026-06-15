-- ربط المندوب بالعميل وفاتورة المبيعات
USE namma_erp;

ALTER TABLE crm_customer
  ADD COLUMN sales_rep_id INT UNSIGNED NULL AFTER address_ar,
  ADD KEY idx_crm_cust_rep (sales_rep_id),
  ADD CONSTRAINT fk_crm_cust_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL;

ALTER TABLE sal_invoice
  ADD COLUMN sales_rep_id INT UNSIGNED NULL AFTER customer_id,
  ADD KEY idx_sal_inv_rep (sales_rep_id),
  ADD CONSTRAINT fk_sal_inv_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL;
