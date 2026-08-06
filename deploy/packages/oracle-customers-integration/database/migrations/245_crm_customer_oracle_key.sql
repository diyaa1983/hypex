-- إضافة مفتاح ربط عملاء Oracle في crm_customer
-- (إن وُجد العمود مسبقاً يُتجاهل الخطأ في sql_migration)

ALTER TABLE crm_customer
  ADD COLUMN oracle_key VARCHAR(80) NULL;

ALTER TABLE crm_customer
  ADD UNIQUE KEY uq_crm_customer_oracle_key (oracle_key);
