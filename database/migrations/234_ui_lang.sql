-- لغة واجهة النظام: ar | en
ALTER TABLE sys_company_settings
  ADD COLUMN ui_lang VARCHAR(8) NOT NULL DEFAULT 'ar' AFTER ui_theme;
