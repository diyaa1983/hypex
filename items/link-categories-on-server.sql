USE hypex;
SET SQL_SAFE_UPDATES = 0;

-- نفّذ هذا الملف على اتصال السيرفر فقط (176.29.176.192).
-- Local instance MySQL80 يعدّل هذا الكمبيوتر، والمواد هنا مربوطة أصلاً.

INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'XL-1', 'Others', 1 FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others')
);

INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'XL-2', 'Bleach', 1 FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach')
);

INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'XL-3', 'Dishwashing', 1 FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing')
);

INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'XL-4', 'Deterganet', 1 FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet')
);

INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'XL-5', 'Hand Wash', 1 FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash')
);

DROP TEMPORARY TABLE IF EXISTS tmp_xl_item_cat;
CREATE TEMPORARY TABLE tmp_xl_item_cat (
  sku VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  barcode VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  cat_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (barcode),
  KEY (sku)
) ENGINE=Memory;

INSERT INTO tmp_xl_item_cat (sku, barcode, cat_name) VALUES
('400021','6253500765090','Others'),
('400024','6253500765076','Others'),
('400022','6253500765083','Others'),
('400020','6253500765113','Others'),
('200001','6253500760019','Bleach'),
('200003','6253500760200','Bleach'),
('200002','6253500760194','Bleach'),
('200004','6253500760026','Bleach'),
('200006','6253500760231','Bleach'),
('200005','6253500760255','Bleach'),
('200007','6253500760033','Bleach'),
('200009','6253500760248','Bleach'),
('200008','6253500760224','Bleach'),
('200018','6253500760712','Bleach'),
('200011','6253500760705','Bleach'),
('130080','6253500761399','Bleach'),
('130151','6253500765571','Bleach'),
('130150','6253500765564','Bleach'),
('130082','6253500763041','Bleach'),
('130155','6253500765540','Bleach'),
('130154','6253500765557','Bleach'),
('500003','6253500760095','Others'),
('300002','6253500760064','Dishwashing'),
('300005','6253500761313','Dishwashing'),
('300004','6253500760187','Dishwashing'),
('300010','6253500765489','Dishwashing'),
('300001','6253500760057','Dishwashing'),
('300021','6253500761511','Dishwashing'),
('300008','6253500761498','Dishwashing'),
('300007','6253500761467','Dishwashing'),
('300012','6253500761504','Dishwashing'),
('300009','6253500761474','Dishwashing'),
('300006','6253500761450','Dishwashing'),
('300011','6253500761481','Dishwashing'),
('300024','6253500763607','Dishwashing'),
('500001','6253500760132','Others'),
('600045','6253500762679','Deterganet'),
('600043','6253500762662','Deterganet'),
('600044','6253500762655','Deterganet'),
('600031','6253500762648','Deterganet'),
('600032','6253500761429','Deterganet'),
('600033','6253500762914','Deterganet'),
('600019','6253500760736','Others'),
('600023','6253500763034','Others'),
('600020','6253500761184','Others'),
('600029','6253500761191','Others'),
('600030','6253500763195','Others'),
('600025','6253500761221','Others'),
('600018','6253500760156','Others'),
('600040','6253011995245','Others'),
('600132','6253500764949','Others'),
('600130','6253500764963','Others'),
('600134','6253500764956','Others'),
('600131','6253500764932','Others'),
('600133','6253500764925','Others'),
('600140','6253500765052','Others'),
('600140','6253500765038','Others'),
('600140','6253500765069','Others'),
('600140','6253500765045','Others'),
('600065','6253500765021','Others'),
('600065','6253500763256','Others'),
('600073','6253500763270','Others'),
('600064','6253500763249','Others'),
('600069','6253500763300','Others'),
('600067','6253500763294','Others'),
('600068','6253500763324','Others'),
('600072','6253500763263','Others'),
('600070','6253500763317','Others'),
('600071','6253500763331','Others'),
('600066','6253500763287','Others'),
('400001','6253500760361','Others'),
('400002','6253500760279','Others'),
('600060','6253500761320','Hand Wash'),
('600059','6253500763010','Hand Wash'),
('600057','6253500761306','Hand Wash'),
('600015','6253500760910','Hand Wash'),
('600014','6253500760903','Hand Wash'),
('600058','6253500761337','Hand Wash'),
('600061','6253011995474','Hand Wash'),
('600009','6253011995436','Hand Wash'),
('600056','6253011995450','Hand Wash'),
('600011','6253011995467','Hand Wash'),
('600092','6253500763683','Hand Wash'),
('600089','6253500763454','Hand Wash'),
('600088','6253500763652','Hand Wash'),
('600037','6253011995528','Hand Wash'),
('600110','6253500764383','Deterganet'),
('600110','6253500761696','Deterganet'),
('400024','6253500670125','Others'),
('400022','6253500760408','Others'),
('200028','6253500760323','Others'),
('400003','6253500760378','Others'),
('400020','621102391127','Deterganet'),
('600034','6253500761412','Deterganet'),
('600129','6211023913527','Deterganet'),
('600136','6253500765533','Deterganet'),
('600127','6253500765175','Deterganet'),
('200027','6253500765519','Deterganet'),
('200028','6253500765526','Deterganet'),
('400025','6253500765106','Others');

-- أزل وسم Oracle عن فئات الاكسل إن وُسمت بالخطأ أثناء مزامنة سابقة
UPDATE inv_item_category
SET oracle_key = NULL, is_active = 1
WHERE code LIKE 'XL-%'
   OR LOWER(TRIM(name_ar)) IN (
        LOWER('Others'), LOWER('Bleach'), LOWER('Dishwashing'), LOWER('Deterganet'), LOWER('Hand Wash')
      );

-- اربط المواد بالفئة المحلية فقط (بدون oracle_key)، مع تفضيل رموز XL-* من الاكسل
UPDATE inv_item i
INNER JOIN tmp_xl_item_cat m ON TRIM(i.barcode) = m.barcode
INNER JOIN inv_item_category c ON c.id = (
  SELECT c2.id
  FROM inv_item_category c2
  WHERE LOWER(TRIM(c2.name_ar)) = LOWER(TRIM(m.cat_name))
    AND (c2.oracle_key IS NULL OR TRIM(IFNULL(c2.oracle_key, '')) = '')
  ORDER BY CASE WHEN c2.code LIKE 'XL-%' THEN 0 ELSE 1 END, c2.id
  LIMIT 1
)
SET i.category_id = c.id;

UPDATE inv_item i
INNER JOIN (
  SELECT sku, MIN(cat_name) AS cat_name
  FROM tmp_xl_item_cat
  GROUP BY sku
  HAVING COUNT(DISTINCT cat_name) = 1
) m ON TRIM(i.sku) = m.sku
INNER JOIN inv_item_category c ON c.id = (
  SELECT c2.id
  FROM inv_item_category c2
  WHERE LOWER(TRIM(c2.name_ar)) = LOWER(TRIM(m.cat_name))
    AND (c2.oracle_key IS NULL OR TRIM(IFNULL(c2.oracle_key, '')) = '')
  ORDER BY CASE WHEN c2.code LIKE 'XL-%' THEN 0 ELSE 1 END, c2.id
  LIMIT 1
)
SET i.category_id = c.id;

-- عطّل فئات Oracle وأي فئة ليست من كشف الاكسل
UPDATE inv_item_category
SET is_active = 0
WHERE (
  (oracle_key IS NOT NULL AND TRIM(IFNULL(oracle_key, '')) <> '')
  OR LOWER(TRIM(name_ar)) NOT IN (
    LOWER('Others'), LOWER('Bleach'), LOWER('Dishwashing'), LOWER('Deterganet'), LOWER('Hand Wash')
  )
);

-- انقل المواد المتبقية من فئات Oracle إلى التوأم المحلي بنفس الاسم إن وُجد
UPDATE inv_item i
INNER JOIN inv_item_category cold ON cold.id = i.category_id
INNER JOIN inv_item_category cnew
  ON LOWER(TRIM(cnew.name_ar)) = LOWER(TRIM(cold.name_ar))
 AND (cnew.oracle_key IS NULL OR TRIM(IFNULL(cnew.oracle_key, '')) = '')
 AND cnew.is_active = 1
SET i.category_id = cnew.id
WHERE cold.oracle_key IS NOT NULL AND TRIM(IFNULL(cold.oracle_key, '')) <> '';

SELECT c.name_ar, COUNT(i.id) AS items
FROM inv_item_category c
LEFT JOIN inv_item i ON i.category_id = c.id
GROUP BY c.id, c.name_ar
ORDER BY c.name_ar;

SELECT COUNT(*) AS unlinked FROM inv_item WHERE category_id IS NULL;

SET SQL_SAFE_UPDATES = 1;
