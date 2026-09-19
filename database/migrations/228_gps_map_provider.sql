-- نوع الخريطة الافتراضية: esri (مجاني أوضح) | carto | google
ALTER TABLE sys_company_settings
    ADD COLUMN gps_map_provider VARCHAR(16) NOT NULL DEFAULT 'esri';
