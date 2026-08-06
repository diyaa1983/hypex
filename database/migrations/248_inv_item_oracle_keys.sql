-- مفاتيح ربط مجموعات ومواد Oracle
SET @db := DATABASE();

SET @c1 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_item' AND COLUMN_NAME = 'oracle_key'
);
SET @sql1 := IF(@c1 = 0,
  'ALTER TABLE inv_item ADD COLUMN oracle_key VARCHAR(80) NULL, ADD UNIQUE KEY uq_inv_item_oracle_key (oracle_key)',
  'SELECT 1');
PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @c2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inv_item_category' AND COLUMN_NAME = 'oracle_key'
);
SET @sql2 := IF(@c2 = 0,
  'ALTER TABLE inv_item_category ADD COLUMN oracle_key VARCHAR(80) NULL, ADD UNIQUE KEY uq_inv_cat_oracle_key (oracle_key)',
  'SELECT 1');
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;
