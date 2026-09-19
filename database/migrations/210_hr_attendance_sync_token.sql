-- مزامنة البصمة من جهاز ZKT المحلي إلى السيرفر (وكيل push)

ALTER TABLE hr_att_config
    ADD COLUMN sync_token VARCHAR(64) NULL DEFAULT NULL AFTER last_punch_time;
