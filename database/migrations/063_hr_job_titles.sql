CREATE TABLE IF NOT EXISTS hr_job_title (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title_code VARCHAR(40) NULL,
    name_ar VARCHAR(160) NOT NULL,
    department_id INT UNSIGNED NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_job_title_code (title_code),
    KEY idx_hr_job_title_dept (department_id),
    KEY idx_hr_job_title_active (is_active),
    CONSTRAINT fk_hr_job_title_dept FOREIGN KEY (department_id) REFERENCES hr_department(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
