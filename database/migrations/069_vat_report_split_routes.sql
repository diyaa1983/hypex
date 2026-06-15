-- مسارات تقارير الضريبة المنفصلة (بيع / شراء) — نفس صلاحيات التقرير الأصلي
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_invoice_tax_purchase', 'الضريبة المستحقة على فواتير الشراء', 'report', 247
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_invoice_tax_purchase');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_vat_return_tax_purchase', 'الضريبة المستحقة على مردود الشراء', 'report', 248
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_vat_return_tax_purchase');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_invoice_tax'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_invoice_tax_purchase';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'report_vat_return_tax'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_vat_return_tax_purchase';

UPDATE sys_screen SET name_ar = 'الضريبة المستحقة على فواتير البيع' WHERE code = 'report_invoice_tax';
UPDATE sys_screen SET name_ar = 'الضريبة المستحقة على مردود البيع' WHERE code = 'report_vat_return_tax';
