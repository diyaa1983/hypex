CREATE TABLE IF NOT EXISTS hr_payroll_component (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    comp_code VARCHAR(40) NULL,
    name_ar VARCHAR(160) NOT NULL,
    comp_type ENUM('allowance', 'deduction') NOT NULL DEFAULT 'allowance',
    is_percent TINYINT(1) NOT NULL DEFAULT 0,
    default_amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_payroll_comp_type_code (comp_type, comp_code),
    KEY idx_hr_payroll_comp_type (comp_type),
    KEY idx_hr_payroll_comp_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
