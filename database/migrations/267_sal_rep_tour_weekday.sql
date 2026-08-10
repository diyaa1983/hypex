-- جولات المندوبين: يوم الأسبوع لكل عميل في الخطة (0=الأحد … 6=السبت — مطابق JS getDay)
SET @db := DATABASE();

SET @has_wd := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_rep_tour_line' AND COLUMN_NAME = 'weekday'
);
SET @sql_wd := IF(@has_wd = 0,
  'ALTER TABLE sal_rep_tour_line ADD COLUMN weekday TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''0=Sun..6=Sat'' AFTER sort_order',
  'SELECT 1');
PREPARE s_wd FROM @sql_wd; EXECUTE s_wd; DEALLOCATE PREPARE s_wd;

-- المفتاح الفريد القديم (tour_id, customer_id) يمنع نفس العميل في يومين
SET @has_uq := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_rep_tour_line' AND INDEX_NAME = 'uq_sal_rep_tour_line'
);
SET @sql_drop := IF(@has_uq > 0,
  'ALTER TABLE sal_rep_tour_line DROP INDEX uq_sal_rep_tour_line',
  'SELECT 1');
PREPARE s_drop FROM @sql_drop; EXECUTE s_drop; DEALLOCATE PREPARE s_drop;

SET @has_uq2 := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sal_rep_tour_line' AND INDEX_NAME = 'uq_sal_rep_tour_line_wd'
);
SET @sql_uq := IF(@has_uq2 = 0,
  'ALTER TABLE sal_rep_tour_line ADD UNIQUE KEY uq_sal_rep_tour_line_wd (tour_id, customer_id, weekday)',
  'SELECT 1');
PREPARE s_uq FROM @sql_uq; EXECUTE s_uq; DEALLOCATE PREPARE s_uq;
