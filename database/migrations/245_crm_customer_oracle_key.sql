-- ربط العملاء بمفتاح Oracle (يُكمَّل أيضاً من oracle_customer_schema_ensure)
-- نفّذ يدوياً على MySQL 5.7 إن لزم:
-- ALTER TABLE crm_customer ADD COLUMN oracle_key VARCHAR(80) NULL;
-- ALTER TABLE crm_customer ADD UNIQUE KEY uq_crm_customer_oracle_key (oracle_key);
SELECT 1;
