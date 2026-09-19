-- مفتاح Google Maps لخرائط التتبّع (يُدار من إعدادات النظام)
ALTER TABLE sys_company_settings
    ADD COLUMN gps_google_maps_api_key VARCHAR(255) NOT NULL DEFAULT '';
