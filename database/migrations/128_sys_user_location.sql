-- آخر موقع GPS لكل مستخدم (سجل واحد — يُستبدل عند كل تحديث)

CREATE TABLE IF NOT EXISTS sys_user_location (
    user_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL,
    longitude DECIMAL(10, 7) NOT NULL,
    gps_accuracy DECIMAL(10, 2) NULL DEFAULT NULL,
    gps_source ENUM('mobile', 'desktop') NOT NULL DEFAULT 'desktop',
    captured_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_sys_user_location_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
