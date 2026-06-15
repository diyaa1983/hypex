CREATE TABLE IF NOT EXISTS hr_social_security_rate (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rate_code VARCHAR(40) NULL,
    employee_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    employer_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_ss_rate_code (rate_code),
    KEY idx_hr_ss_rate_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
