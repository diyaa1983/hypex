CREATE TABLE IF NOT EXISTS sys_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT UNSIGNED NULL,
    domain_code VARCHAR(40) NOT NULL DEFAULT '',
    screen_code VARCHAR(64) NOT NULL,
    screen_label_ar VARCHAR(150) NOT NULL DEFAULT '',
    action_code VARCHAR(32) NOT NULL,
    action_label_ar VARCHAR(80) NOT NULL,
    entity_type VARCHAR(64) NOT NULL DEFAULT '',
    entity_id INT UNSIGNED NULL,
    entity_ref VARCHAR(80) NULL,
    doc_date DATE NULL,
    summary VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_audit_logged_at (logged_at),
    KEY idx_audit_domain (domain_code, logged_at),
    KEY idx_audit_screen (screen_code, logged_at),
    KEY idx_audit_user (user_id, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_audit_log', 'حركات التعديل', 'report', 275
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_audit_log');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS' AND s.code = 'report_audit_log';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'settings'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'report_audit_log';
