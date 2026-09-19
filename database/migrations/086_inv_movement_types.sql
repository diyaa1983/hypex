-- أنواع حركات المستودع — إعدادات الترحيل والتفعيل (شاشة inv_movement_types_settings)

CREATE TABLE IF NOT EXISTS inv_movement_type (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name_ar VARCHAR(120) NOT NULL,
  hint_ar VARCHAR(255) NULL,
  post_auto TINYINT(1) NOT NULL DEFAULT 0,
  post_manual TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_movement_type_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'adjust_in', 'تعديل مخزون (زيادة)', 'إدخال كميات إضافية إلى المستودع', 0, 1, 1, 10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjust_in');

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'adjust_out', 'تعديل مخزون (نقصان)', 'خصم كميات من المستودع', 0, 1, 1, 15
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjust_out');

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'transfer', 'نقل بين المستودعات', 'صرف من مستودع وإدخال إلى مستودع آخر', 0, 1, 1, 20
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'transfer');

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'disposal', 'إتلاف', 'إخراج مواد تالفة أو منتهية من المخزون', 0, 1, 1, 30
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'disposal');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'inv_movement_types_settings', 'إعداد أنواع حركات المستودع', 'screen', 118
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'inv_movement_types_settings');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'warehouses'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'inv_movement_types_settings';
