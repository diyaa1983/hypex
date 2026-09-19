-- أقرب معلم لموقع المستخدم (يُحدَّث مع كل تحديث للإحداثيات)

ALTER TABLE sys_user_location
    ADD COLUMN gps_landmark VARCHAR(300) NULL DEFAULT NULL AFTER gps_source;
