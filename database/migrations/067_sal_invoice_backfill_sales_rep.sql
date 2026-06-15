-- ربط فواتير المبيعات القديمة (التي بدون مندوب) بالمندوب المُسَجَّل في كرت العميل.
-- idempotent: لو الفاتورة لها مندوب أصلاً، لا تُلمَس. لو العميل بدون مندوب، تُتجاهَل.

UPDATE sal_invoice i
INNER JOIN crm_customer c ON c.id = i.customer_id
SET i.sales_rep_id = c.sales_rep_id
WHERE i.sales_rep_id IS NULL
  AND c.sales_rep_id IS NOT NULL;
