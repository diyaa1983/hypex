-- تقرير الجولات: أعمدة الزيارة (دخول/خروج + طريقة GPS) — تُعبَّأ لاحقاً من تطبيق المندوب
SET @db := DATABASE();

-- على بنود الجولة (خطة الزيارة)
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_rep_tour_line' AND COLUMN_NAME = 'visit_checkin_at'
);
SET @sql := IF(@has = 0,
  'ALTER TABLE sal_rep_tour_line
     ADD COLUMN visit_checkin_at DATETIME NULL DEFAULT NULL COMMENT ''وقت دخول المندوب عند العميل'',
     ADD COLUMN visit_checkout_at DATETIME NULL DEFAULT NULL COMMENT ''وقت الخروج من عند العميل'',
     ADD COLUMN checkin_method VARCHAR(20) NULL DEFAULT NULL COMMENT ''GPS عند الدخول ضمن حدود المنطقة'',
     ADD COLUMN checkout_method VARCHAR(20) NULL DEFAULT NULL COMMENT ''GPS عند الخروج ضمن حدود المنطقة''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- على خط السير اليومي (التنفيذ الفعلي من الآيباد لاحقاً)
SET @has2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_rep_route_line' AND COLUMN_NAME = 'visit_checkin_at'
);
SET @sql2 := IF(@has2 = 0,
  'ALTER TABLE sal_rep_route_line
     ADD COLUMN visit_checkin_at DATETIME NULL DEFAULT NULL,
     ADD COLUMN visit_checkout_at DATETIME NULL DEFAULT NULL,
     ADD COLUMN checkin_method VARCHAR(20) NULL DEFAULT NULL,
     ADD COLUMN checkout_method VARCHAR(20) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('report_sales_rep_tours', 'تقرير الجولات', 'report', 241);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT 1, s.id, 1 FROM sys_screen s WHERE s.code = 'report_sales_rep_tours';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code IN ('sales_rep_route', 'sales_reps')
INNER JOIN sys_screen s ON s.code = 'report_sales_rep_tours'
WHERE gp.allowed = 1;
