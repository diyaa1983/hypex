-- تتبّع أجهزة المستخدم النشطة (تنبيه تعدد الأجهزة في تطبيق الهاتف)
CREATE TABLE IF NOT EXISTS sys_user_device_presence (
    user_id INT UNSIGNED NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    device_label VARCHAR(120) NULL DEFAULT NULL,
    last_seen_at DATETIME NOT NULL,
    last_latitude DECIMAL(10, 7) NULL DEFAULT NULL,
    last_longitude DECIMAL(10, 7) NULL DEFAULT NULL,
    PRIMARY KEY (user_id, device_id),
    KEY idx_device_last_seen (last_seen_at),
    CONSTRAINT fk_device_presence_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
