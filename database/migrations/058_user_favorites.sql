-- شاشات/تقارير مفضّلة لكل مستخدم

CREATE TABLE IF NOT EXISTS sys_user_favorite (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    screen_code VARCHAR(64) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sys_user_favorite (user_id, screen_code),
    KEY ix_sys_user_favorite_user (user_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
