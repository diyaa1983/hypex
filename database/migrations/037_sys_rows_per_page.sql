-- عدد الأسطر في صفحات القوائم (إعدادات الشركة)
ALTER TABLE sys_company_settings
  ADD COLUMN rows_per_page TINYINT UNSIGNED NOT NULL DEFAULT 10
  AFTER decimal_places;
