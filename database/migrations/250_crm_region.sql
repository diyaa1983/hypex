-- مناطق العملاء (الأردن) + ربط العميل بمنطقة
CREATE TABLE IF NOT EXISTS crm_region (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(20) NOT NULL,
  name_ar VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crm_region_code (code),
  KEY idx_crm_region_active (is_active, sort_order, name_ar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crm_customer' AND COLUMN_NAME = 'region_id'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE crm_customer ADD COLUMN region_id INT UNSIGNED NULL, ADD KEY idx_crm_customer_region (region_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crm_customer' AND CONSTRAINT_NAME = 'fk_crm_customer_region'
);
SET @sql2 := IF(
  @has_fk = 0,
  'ALTER TABLE crm_customer ADD CONSTRAINT fk_crm_customer_region FOREIGN KEY (region_id) REFERENCES crm_region(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

INSERT IGNORE INTO crm_region (code, name_ar, sort_order, is_active) VALUES
('AMM', 'عمّان', 10, 1),
('TAB', 'طبربور', 20, 1),
('MRK', 'ماركا', 30, 1),
('SHB', 'سحاب', 40, 1),
('NSR', 'أبو نصير', 50, 1),
('JIZ', 'الجيزة', 60, 1),
('NAO', 'ناعور', 70, 1),
('ZAR', 'الزرقاء', 80, 1),
('RSF', 'الرصيفة', 90, 1),
('IRB', 'إربد', 100, 1),
('AJL', 'عجلون', 110, 1),
('JER', 'جرش', 120, 1),
('MFR', 'المفرق', 130, 1),
('BAL', 'البلقاء / السلط', 140, 1),
('MAD', 'مادبا', 150, 1),
('KRK', 'الكرك', 160, 1),
('TAF', 'الطفيلة', 170, 1),
('MAA', 'معان', 180, 1),
('AQB', 'العقبة', 190, 1),
('OTH', 'أخرى', 900, 1);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'customer_regions', 'مناطق العملاء', 'screen', 142
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'customer_regions');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'customer_regions'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
