-- قفل جهاز واحد نشط لكل مستخدم (تطبيق الهاتف)
CREATE TABLE IF NOT EXISTS sys_user_mobile_device_lock (
    user_id INT UNSIGNED NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    device_label VARCHAR(120) NULL DEFAULT NULL,
    heartbeat_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    KEY idx_mobile_lock_heartbeat (heartbeat_at),
    CONSTRAINT fk_mobile_lock_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
