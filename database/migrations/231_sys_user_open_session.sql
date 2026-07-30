-- جلسات المستخدمين المفتوحة (Windows / Mobile) + شاشة الإدارة

CREATE TABLE IF NOT EXISTS sys_user_open_session (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    session_token VARCHAR(128) NOT NULL,
    client_type ENUM('windows', 'mobile') NOT NULL,
    client_label VARCHAR(160) NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL DEFAULT NULL,
    user_agent VARCHAR(255) NULL DEFAULT NULL,
    latitude DECIMAL(10, 7) NULL DEFAULT NULL,
    longitude DECIMAL(10, 7) NULL DEFAULT NULL,
    location_text VARCHAR(255) NULL DEFAULT NULL,
    login_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    revoked_by INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_open_session_token (session_token),
    KEY idx_open_session_user (user_id),
    KEY idx_open_session_active (revoked_at, last_seen_at),
    KEY idx_open_session_type (client_type),
    CONSTRAINT fk_open_session_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('open_sessions', 'الجلسات المفتوحة', 'screen', 315);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_src ON s_src.code = 'users'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_src.id AND gp.allowed = 1
WHERE s_new.code = 'open_sessions';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS' AND s.code = 'open_sessions';
