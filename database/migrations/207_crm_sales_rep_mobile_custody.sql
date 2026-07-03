-- ربط مستخدم النظام بالمندوب + مستودع عهدة المندوب
USE namma_erp;

ALTER TABLE crm_sales_rep
  ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER address_ar,
  ADD KEY idx_crm_sales_rep_wh (warehouse_id);

ALTER TABLE crm_sales_rep
  ADD CONSTRAINT fk_crm_sales_rep_wh
    FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL;

ALTER TABLE sys_user
  ADD COLUMN sales_rep_id INT UNSIGNED NULL AFTER email,
  ADD UNIQUE KEY uq_sys_user_sales_rep (sales_rep_id),
  ADD KEY idx_sys_user_sales_rep (sales_rep_id);

ALTER TABLE sys_user
  ADD CONSTRAINT fk_sys_user_sales_rep
    FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL;
