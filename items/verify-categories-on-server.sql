-- نفّذ على السيرفر بعد link-categories-on-server.sql للتحقق
USE hypex;

-- 1) الفئات الحالية
SELECT c.id, c.code, c.name_ar,
       IFNULL(c.oracle_key, '') AS oracle_key,
       c.is_active,
       COUNT(i.id) AS items
FROM inv_item_category c
LEFT JOIN inv_item i ON i.category_id = c.id
GROUP BY c.id, c.code, c.name_ar, c.oracle_key, c.is_active
ORDER BY c.is_active DESC, c.name_ar;

-- 2) الباركودات التي كانت تظهر «بدون فئة» في التقرير
SELECT i.id, i.sku, i.barcode, IFNULL(i.oracle_key, '') AS item_oracle_key,
       i.category_id, c.name_ar AS cat_name, IFNULL(c.oracle_key, '') AS cat_oracle_key,
       c.is_active, i.name_ar
FROM inv_item i
LEFT JOIN inv_item_category c ON c.id = i.category_id
WHERE i.barcode IN (
  '6253500760712',
  '6253500761399',
  '6253500765106',
  '6253500760019',
  '6253500760057',
  '6253500763195'
)
OR i.sku IN ('200018', '130080', '400025', '200001', '300001', '600030');

-- 3) مواد بلا فئة
SELECT COUNT(*) AS unlinked FROM inv_item WHERE category_id IS NULL;

-- 4) مواد فئتها من Oracle (سبب شائع لـ «بدون فئة» في التقرير القديم)
SELECT COUNT(*) AS items_on_oracle_cats
FROM inv_item i
INNER JOIN inv_item_category c ON c.id = i.category_id
WHERE c.oracle_key IS NOT NULL AND TRIM(c.oracle_key) <> '';
