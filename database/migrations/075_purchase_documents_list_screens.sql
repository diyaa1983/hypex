INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)

SELECT 'purchase_documents_list', 'قائمة فواتير الشراء', 'screen', 196

FROM DUAL

WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_documents_list');



INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)

SELECT gp.group_id, s_new.id, 1

FROM sys_screen s_new

INNER JOIN sys_screen s_old ON s_old.code = 'purchase_invoices'

INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1

WHERE s_new.code = 'purchase_documents_list';



INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)

SELECT 'purchase_returns_documents_list', 'قائمة مردودات المشتريات', 'screen', 197

FROM DUAL

WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'purchase_returns_documents_list');



INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)

SELECT gp.group_id, s_new.id, 1

FROM sys_screen s_new

INNER JOIN sys_screen s_old ON s_old.code = 'purchase_documents_list'

INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1

WHERE s_new.code = 'purchase_returns_documents_list';

