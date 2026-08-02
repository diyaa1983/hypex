-- سجل أخطاء النظام الظاهرة للمستخدمين (log file)

CREATE TABLE IF NOT EXISTS sys_error_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source VARCHAR(20) NOT NULL DEFAULT 'server',
  level VARCHAR(20) NOT NULL DEFAULT 'error',
  message VARCHAR(1000) NOT NULL,
  detail MEDIUMTEXT NULL,
  request_uri VARCHAR(500) NULL,
  http_method VARCHAR(10) NULL,
  screen_code VARCHAR(64) NULL,
  user_id INT UNSIGNED NULL,
  username VARCHAR(80) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_sys_error_logged (logged_at),
  KEY idx_sys_error_last (last_seen_at),
  KEY idx_sys_error_source (source, logged_at),
  KEY idx_sys_error_user (user_id, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'system_error_log', 'سجل أخطاء النظام', 'screen', 242
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'system_error_log');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS' AND s.code = 'system_error_log';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'settings'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'system_error_log';
