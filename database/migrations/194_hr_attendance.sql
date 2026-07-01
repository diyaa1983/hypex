-- بصمات الموظفين — مزامنة من ZKT att2000.mdb

CREATE TABLE IF NOT EXISTS hr_att_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    mdb_path VARCHAR(500) NOT NULL DEFAULT 'C:\\Program Files (x86)\\ZKTeco\\att2000.mdb',
    last_sync_at DATETIME NULL,
    last_punch_time DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_att_config (id, mdb_path) VALUES (1, 'C:\\Program Files (x86)\\ZKTeco\\att2000.mdb')
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS hr_att_employee_map (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    zk_user_id INT NOT NULL,
    badge_number VARCHAR(24) NULL,
    employee_id INT UNSIGNED NOT NULL,
    zk_name VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_att_map_zk_user (zk_user_id),
    UNIQUE KEY uk_hr_att_map_employee (employee_id),
    KEY idx_hr_att_map_badge (badge_number),
    CONSTRAINT fk_hr_att_map_emp FOREIGN KEY (employee_id)
        REFERENCES hr_employee(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_att_punch (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NULL,
    zk_user_id INT NOT NULL,
    badge_number VARCHAR(24) NULL,
    zk_name VARCHAR(80) NULL,
    punch_time DATETIME NOT NULL,
    punch_type VARCHAR(4) NULL,
    verify_code SMALLINT NULL,
    sensor_id VARCHAR(12) NULL,
    source_key VARCHAR(64) NOT NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_att_punch_source (source_key),
    KEY idx_hr_att_punch_time (punch_time),
    KEY idx_hr_att_punch_emp_time (employee_id, punch_time),
    KEY idx_hr_att_punch_zk_user (zk_user_id),
    CONSTRAINT fk_hr_att_punch_emp FOREIGN KEY (employee_id)
        REFERENCES hr_employee(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_employee_attendance', 'بصمات الموظفين', 'screen', 828
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_employee_attendance');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_employees'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_employee_attendance';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_employee_attendance'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
