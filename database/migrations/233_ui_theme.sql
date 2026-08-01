-- واجهة النظام: classic (الحالية) | modern (الحديثة)
ALTER TABLE sys_company_settings
  ADD COLUMN ui_theme VARCHAR(16) NOT NULL DEFAULT 'classic' AFTER rows_per_page;
