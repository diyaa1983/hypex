-- إنشاء مجموعة ADMINS ومستخدم admin إذا كان التثبيت ناقصاً (جدول sys_user فارغ).
-- نفس هاش كلمة المرور admin123 المستخدم في schema.sql و migration 017.

INSERT IGNORE INTO sys_group (code, name_ar, description) VALUES
('ADMINS', 'مديرو النظام', 'صلاحيات كاملة');

INSERT INTO sys_user (username, password_hash, full_name_ar, email, is_active)
SELECT 'admin', '$2y$10$qI5ocJ2TWKNwF0o9JD54JOoJVbNrUFuQvF97YO5i1HExZfdas/fbi', 'مدير النظام', 'admin@local.test', 1
WHERE NOT EXISTS (SELECT 1 FROM sys_user WHERE username = 'admin' LIMIT 1);

INSERT IGNORE INTO sys_user_group (user_id, group_id)
SELECT u.id, g.id FROM sys_user u
INNER JOIN sys_group g ON g.code = 'ADMINS'
WHERE u.username = 'admin';
