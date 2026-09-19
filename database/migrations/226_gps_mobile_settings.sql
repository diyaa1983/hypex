-- إعدادات تتبّع موقع تطبيق الهاتف (يتحكم بها مدير النظام)
ALTER TABLE sys_company_settings
    ADD COLUMN gps_mobile_auto_enable TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN gps_mobile_interval_sec INT UNSIGNED NOT NULL DEFAULT 10,
    ADD COLUMN gps_mobile_min_distance_m INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN gps_mobile_user_can_disable TINYINT(1) NOT NULL DEFAULT 0;
