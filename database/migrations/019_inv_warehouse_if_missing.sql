-- ترحيل اختياري: إنشاء جداول مخزون أساسية إذا كانت القاعدة ناقصة (بديل عن الإنشاء التلقائي من PHP).
-- الترتيب مهم بسبب المفاتيح الخارجية.

CREATE TABLE IF NOT EXISTS inv_warehouse (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name_ar VARCHAR(200) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO inv_warehouse (code, name_ar) VALUES ('MAIN', 'المستودع الرئيسي');

-- انظر أيضاً: migrations 004_item_category_unit_warehouse.sql ثم إنشاء inv_item كما في database/schema.sql
