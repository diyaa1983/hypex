-- السماح بأكثر من زيارة (دخول) لنفس العميل في نفس يوم خط السير
SET @db := DATABASE();

SET @has := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'sal_rep_route_line'
    AND INDEX_NAME = 'uq_sal_rep_route_line'
);
SET @sql := IF(@has > 0,
  'ALTER TABLE sal_rep_route_line DROP INDEX uq_sal_rep_route_line',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- فهرس غير فريد لتسريع البحث حسب العميل/اليوم
SET @has2 := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'sal_rep_route_line'
    AND INDEX_NAME = 'idx_sal_rep_route_line_route_cust'
);
SET @sql2 := IF(@has2 = 0,
  'ALTER TABLE sal_rep_route_line ADD KEY idx_sal_rep_route_line_route_cust (route_id, customer_id)',
  'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
