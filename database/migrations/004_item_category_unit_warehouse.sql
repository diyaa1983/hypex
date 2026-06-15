-- فئة المادة، وحدة القياس، المستودع الافتراضي
USE namma_erp;

CREATE TABLE IF NOT EXISTS inv_item_category (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name_ar     VARCHAR(200) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_cat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_unit (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name_ar     VARCHAR(100) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_unit_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO inv_item_category (code, name_ar) VALUES
('GEN', 'عام'),
('FOOD', 'مواد غذائية'),
('BLD', 'مواد بناء');

INSERT IGNORE INTO inv_unit (code, name_ar) VALUES
('PCS', 'قطعة'),
('BOX', 'كرتون'),
('KG', 'كيلو'),
('L', 'لتر');

ALTER TABLE inv_item ADD COLUMN category_id INT UNSIGNED NULL AFTER name_ar;
ALTER TABLE inv_item ADD COLUMN unit_id INT UNSIGNED NULL AFTER category_id;
ALTER TABLE inv_item ADD COLUMN default_warehouse_id INT UNSIGNED NULL AFTER unit_id;

UPDATE inv_item i
LEFT JOIN inv_unit u ON u.name_ar = i.unit_name
SET i.unit_id = u.id
WHERE i.unit_id IS NULL AND u.id IS NOT NULL;

UPDATE inv_item SET unit_id = (SELECT id FROM inv_unit WHERE code = 'PCS' LIMIT 1)
WHERE unit_id IS NULL;
