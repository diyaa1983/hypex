-- المنطقة/الموقع الرئيسي لكل مستخدم (مع المعلم)

ALTER TABLE sys_user_location
    ADD COLUMN gps_place VARCHAR(500) NULL DEFAULT NULL AFTER gps_source;
