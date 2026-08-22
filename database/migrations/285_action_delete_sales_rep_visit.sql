-- صلاحية حذف زيارة من تقرير الزيارات (إجراء — ليس شاشة قائمة)
INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('action_delete_sales_rep_visit', 'حذف زيارة من التقرير', 'screen', 244);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'action_delete_sales_rep_visit';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code = 'report_sales_rep_visits'
INNER JOIN sys_screen s ON s.code = 'action_delete_sales_rep_visit'
WHERE gp.allowed = 1;
