-- شاشة تعديل أسعار المواد (سعر البيع) + جدول الحركات
CREATE TABLE IF NOT EXISTS inv_item_sale_price_adj (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id           INT UNSIGNED NOT NULL,
  old_sale_price    DECIMAL(18,6) NOT NULL,
  new_sale_price    DECIMAL(18,6) NOT NULL,
  tax_rate_percent  DECIMAL(6,3) NOT NULL DEFAULT 0,
  status            ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  notes             VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at         DATETIME NULL,
  KEY idx_iispa_item (item_id),
  KEY idx_iispa_status (status),
  CONSTRAINT fk_iispa_item FOREIGN KEY (item_id) REFERENCES inv_item(id) ON DELETE CASCADE,
  CONSTRAINT fk_iispa_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('item_sale_price_adjust', 'تعديل أسعار المواد', 'screen', 152);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'item_sale_price_adjust';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen items ON items.id = gp.screen_id AND items.code = 'items'
INNER JOIN sys_screen s ON s.code = 'item_sale_price_adjust'
WHERE gp.allowed = 1;
