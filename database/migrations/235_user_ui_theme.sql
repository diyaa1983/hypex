-- واجهة النظام على مستوى المستخدم
ALTER TABLE sys_user
  ADD COLUMN ui_theme VARCHAR(16) NOT NULL DEFAULT 'classic' AFTER is_active;
