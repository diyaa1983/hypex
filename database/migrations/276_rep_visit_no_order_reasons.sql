-- أسباب عدم طلب العميل + ربط الطلب/الأسباب بزيارة المندوب.
CREATE TABLE IF NOT EXISTS sal_no_order_reason (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_ar VARCHAR(180) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_snor_active_sort (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sal_no_order_reason (name_ar, sort_order, is_active)
SELECT 'العميل لا يحتاج طلبية حالياً', 10, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sal_no_order_reason);
INSERT INTO sal_no_order_reason (name_ar, sort_order, is_active)
SELECT 'العميل مغلق', 20, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sal_no_order_reason WHERE name_ar='العميل مغلق');
INSERT INTO sal_no_order_reason (name_ar, sort_order, is_active)
SELECT 'المسؤول عن الطلب غير موجود', 30, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sal_no_order_reason WHERE name_ar='المسؤول عن الطلب غير موجود');
INSERT INTO sal_no_order_reason (name_ar, sort_order, is_active)
SELECT 'لدى العميل مخزون كافٍ', 40, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sal_no_order_reason WHERE name_ar='لدى العميل مخزون كافٍ');

SET @db := DATABASE();
SET @has_order_visit := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sal_customer_order'
    AND COLUMN_NAME='visit_route_line_id'
);
SET @sql_order_visit := IF(
  @has_order_visit = 0,
  'ALTER TABLE sal_customer_order
     ADD COLUMN visit_route_line_id INT UNSIGNED NULL DEFAULT NULL AFTER sales_rep_id,
     ADD KEY idx_sco_visit_route_line (visit_route_line_id)',
  'SELECT 1'
);
PREPARE stmt_order_visit FROM @sql_order_visit;
EXECUTE stmt_order_visit;
DEALLOCATE PREPARE stmt_order_visit;

CREATE TABLE IF NOT EXISTS sal_rep_visit_no_order_reason (
  route_line_id INT UNSIGNED NOT NULL,
  reason_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (route_line_id, reason_id),
  KEY idx_srvnor_reason (reason_id),
  CONSTRAINT fk_srvnor_line FOREIGN KEY (route_line_id)
    REFERENCES sal_rep_route_line(id) ON DELETE CASCADE,
  CONSTRAINT fk_srvnor_reason FOREIGN KEY (reason_id)
    REFERENCES sal_no_order_reason(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'no_order_reasons_settings', 'أسباب عدم طلب العميل', 'screen', 246
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM sys_screen WHERE code='no_order_reasons_settings'
);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, dst.id, gp.allowed
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id=gp.screen_id AND src.code='settings'
INNER JOIN sys_screen dst ON dst.code='no_order_reasons_settings';
