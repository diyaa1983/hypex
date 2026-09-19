CREATE TABLE IF NOT EXISTS hr_salary_bank (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_code VARCHAR(40) NULL,
    name_ar VARCHAR(160) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_salary_bank_code (bank_code),
    KEY idx_hr_salary_bank_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
