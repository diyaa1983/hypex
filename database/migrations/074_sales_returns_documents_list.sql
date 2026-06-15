UPDATE sys_screen SET name_ar = 'قائمة فواتير المبيعات' WHERE code = 'sales_documents_list';



INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)

SELECT 'sales_returns_documents_list', 'قائمة المرتجعات', 'screen', 195

FROM DUAL

WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_returns_documents_list');



INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)

SELECT gp.group_id, s_new.id, 1

FROM sys_screen s_new

INNER JOIN sys_screen s_old ON s_old.code = 'sales_documents_list'

INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1

WHERE s_new.code = 'sales_returns_documents_list';

