-- إعدادات تنبيهات استحقاق الشيكات الصادرة بالبريد
ALTER TABLE sys_company_settings
  ADD COLUMN out_check_email_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN out_check_email_days_before SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  ADD COLUMN out_check_email_on_due_day TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN out_check_email_recipients TEXT NULL;
