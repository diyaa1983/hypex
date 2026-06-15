-- إعدادات ضريبة الدخل على الرواتب
ALTER TABLE hr_employee
    ADD COLUMN subject_to_income_tax TINYINT(1) NOT NULL DEFAULT 0 AFTER subject_to_social_security;

CREATE TABLE IF NOT EXISTS hr_income_tax_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    single_exempt_monthly DECIMAL(14,3) NOT NULL DEFAULT 750.000,
    single_exempt_annual DECIMAL(14,3) NOT NULL DEFAULT 9000.000,
    married_exempt_monthly DECIMAL(14,3) NOT NULL DEFAULT 1500.000,
    married_exempt_annual DECIMAL(14,3) NOT NULL DEFAULT 18000.000,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_income_tax_config (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS hr_income_tax_bracket (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marital_status VARCHAR(10) NOT NULL,
    salary_from DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    salary_to DECIMAL(14,3) NULL,
    tax_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_hr_it_bracket_status (marital_status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES
(
    'hr_income_tax',
    'ضريبة الدخل — مستحقة',
    'دائن عند ترحيل الرواتب — اقتطاع ضريبة الدخل من الموظف',
    88
)
ON DUPLICATE KEY UPDATE
  label_ar = VALUES(label_ar),
  hint_ar = VALUES(hint_ar),
  sort_order = VALUES(sort_order);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'hr_income_tax_settings', 'إعدادات ضريبة الدخل', 'master', 248
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'hr_income_tax_settings');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_social_security_rates'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'hr_income_tax_settings';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'hr_income_tax_settings'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');

-- شرائح افتراضية (شهرية — قابلة للتعديل)
INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'single', 751.000, 1000.000, 5.000, 10
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'single' LIMIT 1);

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'single', 1001.000, 1500.000, 10.000, 20
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'single' AND salary_from = 1001.000 LIMIT 1);

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'single', 1501.000, 3000.000, 15.000, 30
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'single' AND salary_from = 1501.000 LIMIT 1);

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'single', 3001.000, NULL, 20.000, 40
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'single' AND salary_from = 3001.000 LIMIT 1);

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'married', 1501.000, 2000.000, 5.000, 10
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'married' LIMIT 1);

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'married', 2001.000, 3000.000, 10.000, 20
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'married' AND salary_from = 2001.000 LIMIT 1);

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'married', 3001.000, 5000.000, 15.000, 30
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'married' AND salary_from = 3001.000 LIMIT 1);

INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
SELECT 'married', 5001.000, NULL, 20.000, 40
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_income_tax_bracket WHERE marital_status = 'married' AND salary_from = 5001.000 LIMIT 1);
