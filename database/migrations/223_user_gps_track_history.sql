-- تاريخ نقاط GPS لكل مستخدم — لرسم خط السير اليومي للمندوب
-- بخلاف sys_user_location (تحفظ آخر موقع فقط) هذا الجدول يحتفظ بكل نقطة مقبولة.

CREATE TABLE IF NOT EXISTS sys_user_location_track (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL,
    longitude DECIMAL(10, 7) NOT NULL,
    gps_accuracy DECIMAL(10, 2) NULL DEFAULT NULL,
    gps_source ENUM('mobile', 'desktop') NOT NULL DEFAULT 'mobile',
    captured_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_track_user_time (user_id, captured_at),
    CONSTRAINT fk_sys_user_track_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
