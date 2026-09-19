-- إعادة تعيين كلمة مرور المستخدم admin إلى القيمة الافتراضية admin123
-- نفّذ هذا الملف مرة واحدة من phpMyAdmin إذا كان الدخول بـ admin / admin123 يرفض.
-- بعد النجاح غيّر كلمة المرور من الشاشة أو من UPDATE يدوي.

UPDATE sys_user
SET password_hash = '$2y$10$qI5ocJ2TWKNwF0o9JD54JOoJVbNrUFuQvF97YO5i1HExZfdas/fbi'
WHERE username = 'admin';
