-- صلاحية حذف مستخدم (تظهر في شاشة الصلاحيات تحت حذف المستندات)

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('action_delete_user', 'حذف مستخدم', 'screen', 9013);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code IN ('ADMINS', 'administrators', 'admin')
  AND s.code = 'action_delete_user';
