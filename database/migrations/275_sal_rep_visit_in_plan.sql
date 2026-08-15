-- تثبيت مصدر الزيارة: داخل خطة الجولة أو زيارة اختيارية خارجها.
SET @db := DATABASE();
SET @has := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'sal_rep_route_line'
    AND COLUMN_NAME = 'in_plan'
);
SET @sql := IF(
  @has = 0,
  'ALTER TABLE sal_rep_route_line
     ADD COLUMN in_plan TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order,
     ADD KEY idx_srrl_in_plan (in_plan)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- تصنيف السجلات السابقة مرة واحدة: يعتبر داخل الجولة فقط إذا كان العميل
-- موجوداً فعلاً في جولة تغطي تاريخ خط السير ويطابق يوم الأسبوع.
UPDATE sal_rep_route_line l
INNER JOIN sal_rep_route r ON r.id = l.route_id
SET l.in_plan = CASE
  WHEN EXISTS (
    SELECT 1
    FROM sal_rep_tour t
    INNER JOIN sal_rep_tour_line tl ON tl.tour_id = t.id
    WHERE t.sales_rep_id = r.sales_rep_id
      AND t.is_active = 1
      AND t.date_from <= r.route_date
      AND t.date_to >= r.route_date
      AND tl.customer_id = l.customer_id
      AND tl.weekday = DAYOFWEEK(r.route_date) - 1
  ) THEN 1
  ELSE 0
END;
