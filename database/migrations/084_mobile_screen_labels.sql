-- تحديث أسماء شاشات الهاتف في sys_screen

UPDATE sys_screen SET name_ar = 'هاتف — فواتير المبيعات' WHERE code = 'm_sales_invoices';
UPDATE sys_screen SET name_ar = 'هاتف — قائمة فواتير المبيعات' WHERE code = 'm_sales_invoice_list';
UPDATE sys_screen SET name_ar = 'هاتف — سندات القبض' WHERE code = 'm_receipt';
UPDATE sys_screen SET name_ar = 'هاتف — قائمة سندات القبض' WHERE code = 'm_receipt_list';
UPDATE sys_screen SET name_ar = 'هاتف — قائمة مرتجعات المبيعات' WHERE code = 'm_sales_returns_list';
