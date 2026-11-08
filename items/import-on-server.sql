USE hypex;
SET SQL_SAFE_UPDATES = 0;

INSERT INTO inv_unit (code, name_ar, is_active)
SELECT 'PCS', 'قطعة', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة'));
INSERT INTO inv_unit (code, name_ar, is_active)
SELECT 'BOX', 'كرتونة', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_unit WHERE code = 'BOX' OR name_ar IN ('كرتونة','كرتون'));

INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'C1', 'Others', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others'));
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'C2', 'Bleach', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach'));
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'C3', 'Dishwashing', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing'));
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'C4', 'Deterganet', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet'));
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT 'C5', 'Hand Wash', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash'));

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400021', '6253500765090', 'معطر بخاخ هايبكس 450 مل عود', 'Air Freshner 450 ML Oud', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400021');
UPDATE inv_item SET
  barcode = '6253500765090',
  name_ar = 'معطر بخاخ هايبكس 450 مل عود',
  name_en = 'Air Freshner 450 ML Oud',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400021';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400021';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400021'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400021'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400024', '6253500765076', 'معطر بخاخ هايبكس 450 مل منجا', 'Air Freshner 450 ML Mango', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400024');
UPDATE inv_item SET
  barcode = '6253500765076',
  name_ar = 'معطر بخاخ هايبكس 450 مل منجا',
  name_en = 'Air Freshner 450 ML Mango',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400024';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400024';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400024'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400024'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400022', '6253500765083', 'معطر بخاخ هايبكس 450 مل نسيم البحر', 'Air Freshner 450 ML Breez', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400022');
UPDATE inv_item SET
  barcode = '6253500765083',
  name_ar = 'معطر بخاخ هايبكس 450 مل نسيم البحر',
  name_en = 'Air Freshner 450 ML Breez',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400022';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400022';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400022'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400022'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400020', '6253500765113', 'معطر بخاخ هايبكس 450 مل ورد', 'Air Freshner 450 ML Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400020');
UPDATE inv_item SET
  barcode = '6253500765113',
  name_ar = 'معطر بخاخ هايبكس 450 مل ورد',
  name_en = 'Air Freshner 450 ML Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400020';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400020';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400020'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400020'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200001', '6253500760019', 'مبيض غسيل هايبكس 950 مل', 'Bleach 950 ML', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.670000, 0.647000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200001');
UPDATE inv_item SET
  barcode = '6253500760019',
  name_ar = 'مبيض غسيل هايبكس 950 مل',
  name_en = 'Bleach 950 ML',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 0.670000,
  default_wholesale = 0.647000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200001';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200001'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 18, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200001'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200003', '6253500760200', 'مبيض غسيل هايبكس 950 مل زهور', 'Bleach 950 ML Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.670000, 0.647000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200003');
UPDATE inv_item SET
  barcode = '6253500760200',
  name_ar = 'مبيض غسيل هايبكس 950 مل زهور',
  name_en = 'Bleach 950 ML Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 0.670000,
  default_wholesale = 0.647000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200003';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200003';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200003'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 18, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200003'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200002', '6253500760194', 'مبيض غسيل هايبكس 950 مل ليمون', 'Bleach 950 ML Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.670000, 0.647000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200002');
UPDATE inv_item SET
  barcode = '6253500760194',
  name_ar = 'مبيض غسيل هايبكس 950 مل ليمون',
  name_en = 'Bleach 950 ML Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 0.670000,
  default_wholesale = 0.647000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200002';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200002';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200002'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 18, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200002'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200004', '6253500760026', 'مبيض غسيل هايبكس 1.89 لتر', 'Bleach1.89 L', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.230000, 1.149000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200004');
UPDATE inv_item SET
  barcode = '6253500760026',
  name_ar = 'مبيض غسيل هايبكس 1.89 لتر',
  name_en = 'Bleach1.89 L',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.230000,
  default_wholesale = 1.149000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200004';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200004';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200004'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 9, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200004'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200006', '6253500760231', 'مبيض غسيل هايبكس 1.89 لتر زهور', 'Bleach1.89 L Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.230000, 1.149000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200006');
UPDATE inv_item SET
  barcode = '6253500760231',
  name_ar = 'مبيض غسيل هايبكس 1.89 لتر زهور',
  name_en = 'Bleach1.89 L Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.230000,
  default_wholesale = 1.149000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200006';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200006';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200006'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 9, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200006'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200005', '6253500760255', 'مبيض غسيل هايبكس 1.89 لتر ليمون', 'Bleach1.89 L Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.230000, 1.149000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200005');
UPDATE inv_item SET
  barcode = '6253500760255',
  name_ar = 'مبيض غسيل هايبكس 1.89 لتر ليمون',
  name_en = 'Bleach1.89 L Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.230000,
  default_wholesale = 1.149000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200005';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200005';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200005'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 9, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200005'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200007', '6253500760033', 'مبيض غسيل هايبكس 3.78 لتر', 'Bleach3.78 L', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 2.156000, 2.015000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200007');
UPDATE inv_item SET
  barcode = '6253500760033',
  name_ar = 'مبيض غسيل هايبكس 3.78 لتر',
  name_en = 'Bleach3.78 L',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 2.156000,
  default_wholesale = 2.015000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200007';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200007';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200007'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200007'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200009', '6253500760248', 'مبيض غسيل هايبكس 3.78 لتر زهور', 'Bleach3.78 L Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 2.156000, 2.015000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200009');
UPDATE inv_item SET
  barcode = '6253500760248',
  name_ar = 'مبيض غسيل هايبكس 3.78 لتر زهور',
  name_en = 'Bleach3.78 L Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 2.156000,
  default_wholesale = 2.015000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200009';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200009';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200009'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200009'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200008', '6253500760224', 'مبيض غسيل هايبكس 3.78 لتر ليمون', 'Bleach3.78 L Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 2.156000, 2.015000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200008');
UPDATE inv_item SET
  barcode = '6253500760224',
  name_ar = 'مبيض غسيل هايبكس 3.78 لتر ليمون',
  name_en = 'Bleach3.78 L Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 2.156000,
  default_wholesale = 2.015000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200008';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200008';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200008'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200008'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200018', '6253500760712', 'مزيل بقع هايبكس 950 مل ملابس ملونة زهري', 'Color Clothes Remover Stain 950  ML -Pink', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.176000, 1.099000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200018');
UPDATE inv_item SET
  barcode = '6253500760712',
  name_ar = 'مزيل بقع هايبكس 950 مل ملابس ملونة زهري',
  name_en = 'Color Clothes Remover Stain 950  ML -Pink',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.176000,
  default_wholesale = 1.099000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200018';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200018';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200018'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200018'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200011', '6253500760705', 'مزيل بقع هايبكس 950 مل ملابس ملونة ازرق', 'Color Clothes Remover Stain 950  ML -Blue', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.176000, 1.099000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200011');
UPDATE inv_item SET
  barcode = '6253500760705',
  name_ar = 'مزيل بقع هايبكس 950 مل ملابس ملونة ازرق',
  name_en = 'Color Clothes Remover Stain 950  ML -Blue',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.176000,
  default_wholesale = 1.099000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200011';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200011';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200011'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200011'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '130080', '6253500761399', 'مستر هايبو كلور 950 مل', 'Bleach 950 ML', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.281000, 0.262000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '130080');
UPDATE inv_item SET
  barcode = '6253500761399',
  name_ar = 'مستر هايبو كلور 950 مل',
  name_en = 'Bleach 950 ML',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 0.281000,
  default_wholesale = 0.262000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '130080';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '130080';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '130080'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '130080'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '130151', '6253500765571', 'مستر هايبو كلور 950 مل زهور', 'Bleach 950 ML Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.281000, 0.262000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '130151');
UPDATE inv_item SET
  barcode = '6253500765571',
  name_ar = 'مستر هايبو كلور 950 مل زهور',
  name_en = 'Bleach 950 ML Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 0.281000,
  default_wholesale = 0.262000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '130151';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '130151';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '130151'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '130151'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '130150', '6253500765564', 'مستر هايبو كلور 950 مل  ليمون', 'Bleach 950 ML lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.281000, 0.262000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '130150');
UPDATE inv_item SET
  barcode = '6253500765564',
  name_ar = 'مستر هايبو كلور 950 مل  ليمون',
  name_en = 'Bleach 950 ML lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 0.281000,
  default_wholesale = 0.262000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '130150';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '130150';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '130150'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '130150'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '130082', '6253500763041', 'مستر هايبو كلور 3.78 لتر', 'Bleach3.78 L', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.153000, 1.078000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '130082');
UPDATE inv_item SET
  barcode = '6253500763041',
  name_ar = 'مستر هايبو كلور 3.78 لتر',
  name_en = 'Bleach3.78 L',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.153000,
  default_wholesale = 1.078000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '130082';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '130082';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '130082'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '130082'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '130155', '6253500765540', 'مستر هايبو كلور 3.78 لتر زهور', 'Bleach3.78 L Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.153000, 1.078000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '130155');
UPDATE inv_item SET
  barcode = '6253500765540',
  name_ar = 'مستر هايبو كلور 3.78 لتر زهور',
  name_en = 'Bleach3.78 L Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.153000,
  default_wholesale = 1.078000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '130155';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '130155';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '130155'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '130155'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '130154', '6253500765557', 'مستر هايبو كلور 3.78 لتر ليمون', 'Bleach3.78 Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.153000, 1.078000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '130154');
UPDATE inv_item SET
  barcode = '6253500765557',
  name_ar = 'مستر هايبو كلور 3.78 لتر ليمون',
  name_en = 'Bleach3.78 Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Bleach') LIMIT 1),
  default_sale = 1.153000,
  default_wholesale = 1.078000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '130154';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '130154';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '130154'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '130154'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '500003', '6253500760095', 'منظف سجاد هايبكس 1 لتر', 'Carpet Cleaner 1 L', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.980000, 0.916000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '500003');
UPDATE inv_item SET
  barcode = '6253500760095',
  name_ar = 'منظف سجاد هايبكس 1 لتر',
  name_en = 'Carpet Cleaner 1 L',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.980000,
  default_wholesale = 0.916000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '500003';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '500003';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '500003'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '500003'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300002', '6253500760064', 'سائل جلي هايبكس 950 مل تفاح', 'Dish Washing 950 ML Apple', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300002');
UPDATE inv_item SET
  barcode = '6253500760064',
  name_ar = 'سائل جلي هايبكس 950 مل تفاح',
  name_en = 'Dish Washing 950 ML Apple',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300002';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300002';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300002'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300002'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300005', '6253500761313', 'سائل جلي هايبكس 950 مل زهور', 'Dish Washing 950 ML Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300005');
UPDATE inv_item SET
  barcode = '6253500761313',
  name_ar = 'سائل جلي هايبكس 950 مل زهور',
  name_en = 'Dish Washing 950 ML Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300005';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300005';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300005'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300005'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300004', '6253500760187', 'سائل جلي هايبكس 950 مل فراولة', 'Dish Washing 950 ML strawberry', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300004');
UPDATE inv_item SET
  barcode = '6253500760187',
  name_ar = 'سائل جلي هايبكس 950 مل فراولة',
  name_en = 'Dish Washing 950 ML strawberry',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300004';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300004';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300004'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300004'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300010', '6253500765489', 'سائل جلي هايبكس 950 مل لافندر', 'Dish Washing 950 ML Lavender', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300010');
UPDATE inv_item SET
  barcode = '6253500765489',
  name_ar = 'سائل جلي هايبكس 950 مل لافندر',
  name_en = 'Dish Washing 950 ML Lavender',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300010';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300010';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300010'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300010'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300001', '6253500760057', 'سائل جلي هايبكس 950 مل ليمون', 'Dish Washing 950 ML Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300001');
UPDATE inv_item SET
  barcode = '6253500760057',
  name_ar = 'سائل جلي هايبكس 950 مل ليمون',
  name_en = 'Dish Washing 950 ML Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300001';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300001'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300001'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300021', '6253500761511', 'سائل جلي هايبكس 1.8 لتر ازرق ليلك', 'Dish Washing 1.8 L Blue Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300021');
UPDATE inv_item SET
  barcode = '6253500761511',
  name_ar = 'سائل جلي هايبكس 1.8 لتر ازرق ليلك',
  name_en = 'Dish Washing 1.8 L Blue Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300021';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300021';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300021'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300021'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300008', '6253500761498', 'سائل جلي هايبكس 1.8 لتر برتقال', 'Dish Washing 1.8 L  Orange', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300008');
UPDATE inv_item SET
  barcode = '6253500761498',
  name_ar = 'سائل جلي هايبكس 1.8 لتر برتقال',
  name_en = 'Dish Washing 1.8 L  Orange',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300008';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300008';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300008'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300008'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300007', '6253500761467', 'سائل جلي هايبكس 1.8 لتر تفاح', 'Dish Washing 1.8 L  Apple', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300007');
UPDATE inv_item SET
  barcode = '6253500761467',
  name_ar = 'سائل جلي هايبكس 1.8 لتر تفاح',
  name_en = 'Dish Washing 1.8 L  Apple',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300007';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300007';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300007'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300007'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300012', '6253500761504', 'سائل جلي هايبكس 1.8 لتر زهري ورد', 'Dish Washing 1.8 L  Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300012');
UPDATE inv_item SET
  barcode = '6253500761504',
  name_ar = 'سائل جلي هايبكس 1.8 لتر زهري ورد',
  name_en = 'Dish Washing 1.8 L  Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300012';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300012';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300012'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300012'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300009', '6253500761474', 'سائل جلي هايبكس 1.8 لتر فراولة', 'Dish Washing 1.8 L  strawberry', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300009');
UPDATE inv_item SET
  barcode = '6253500761474',
  name_ar = 'سائل جلي هايبكس 1.8 لتر فراولة',
  name_en = 'Dish Washing 1.8 L  strawberry',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300009';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300009';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300009'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300009'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300006', '6253500761450', 'سائل جلي هايبكس 1.8 لتر ليمون', 'Dish Washing 1.8 L  Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300006');
UPDATE inv_item SET
  barcode = '6253500761450',
  name_ar = 'سائل جلي هايبكس 1.8 لتر ليمون',
  name_en = 'Dish Washing 1.8 L  Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300006';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300006';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300006'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300006'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300011', '6253500761481', 'سائل جلي هايبكس 1.8 لتر نهدي لافندر', 'Dish Washing 1.8 L  Lavender', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300011');
UPDATE inv_item SET
  barcode = '6253500761481',
  name_ar = 'سائل جلي هايبكس 1.8 لتر نهدي لافندر',
  name_en = 'Dish Washing 1.8 L  Lavender',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300011';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300011';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300011'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300011'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '300024', '6253500763607', 'سائل جلي هايبكس 3.78 لتر زهري ورد جالون', 'Dish Washing 3.78 L  Rose Galone', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 3.880000, 3.071000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '300024');
UPDATE inv_item SET
  barcode = '6253500763607',
  name_ar = 'سائل جلي هايبكس 3.78 لتر زهري ورد جالون',
  name_en = 'Dish Washing 3.78 L  Rose Galone',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Dishwashing') LIMIT 1),
  default_sale = 3.880000,
  default_wholesale = 3.071000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '300024';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '300024';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '300024'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '300024'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '500001', '6253500760132', 'مسلك روسور هايبكس 1 لتر', 'Drain opener 1 L', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.176000, 1.099000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '500001');
UPDATE inv_item SET
  barcode = '6253500760132',
  name_ar = 'مسلك روسور هايبكس 1 لتر',
  name_en = 'Drain opener 1 L',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.176000,
  default_wholesale = 1.099000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '500001';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '500001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '500001'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '500001'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600045', '6253500762679', 'ملين ملابس هايبكس 1 لتر اخضر', 'Fabric Softner 1 L Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600045');
UPDATE inv_item SET
  barcode = '6253500762679',
  name_ar = 'ملين ملابس هايبكس 1 لتر اخضر',
  name_en = 'Fabric Softner 1 L Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600045';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600045';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600045'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600045'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600043', '6253500762662', 'ملين ملابس هايبكس 1 لتر ازرق', 'Fabric Softner 1 L Blue', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600043');
UPDATE inv_item SET
  barcode = '6253500762662',
  name_ar = 'ملين ملابس هايبكس 1 لتر ازرق',
  name_en = 'Fabric Softner 1 L Blue',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600043';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600043';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600043'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600043'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600044', '6253500762655', 'ملين ملابس هايبكس 1 لتر زهري', 'Fabric Softner 1 L Pink', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600044');
UPDATE inv_item SET
  barcode = '6253500762655',
  name_ar = 'ملين ملابس هايبكس 1 لتر زهري',
  name_en = 'Fabric Softner 1 L Pink',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600044';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600044';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600044'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600044'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600031', '6253500762648', 'ملين ملابس هايبكس 2 لتر اخضر', 'Fabric Softner 2 L Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600031');
UPDATE inv_item SET
  barcode = '6253500762648',
  name_ar = 'ملين ملابس هايبكس 2 لتر اخضر',
  name_en = 'Fabric Softner 2 L Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600031';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600031';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600031'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600031'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600032', '6253500761429', 'ملين ملابس هايبكس 2 لتر ازرق', 'Fabric Softner 2 L Blue', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600032');
UPDATE inv_item SET
  barcode = '6253500761429',
  name_ar = 'ملين ملابس هايبكس 2 لتر ازرق',
  name_en = 'Fabric Softner 2 L Blue',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600032';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600032';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600032'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600032'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600033', '6253500762914', 'ملين ملابس هايبكس 2 لتر زهري', 'Fabric Softner 2 L Pink', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.614000, 1.509000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600033');
UPDATE inv_item SET
  barcode = '6253500762914',
  name_ar = 'ملين ملابس هايبكس 2 لتر زهري',
  name_en = 'Fabric Softner 2 L Pink',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 1.614000,
  default_wholesale = 1.509000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600033';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600033';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600033'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600033'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600019', '6253500760736', 'جل ارضيات هايبكس 1 كغم صنوبر', 'Gel Floor 1 KG - Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.884000, 0.826000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600019');
UPDATE inv_item SET
  barcode = '6253500760736',
  name_ar = 'جل ارضيات هايبكس 1 كغم صنوبر',
  name_en = 'Gel Floor 1 KG - Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.884000,
  default_wholesale = 0.826000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600019';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600019';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600019'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600019'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600023', '6253500763034', 'جل ارضيات هايبكس 1 كغم لافندر', 'Gel Floor 1 KG - Lavender', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.884000, 0.826000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600023');
UPDATE inv_item SET
  barcode = '6253500763034',
  name_ar = 'جل ارضيات هايبكس 1 كغم لافندر',
  name_en = 'Gel Floor 1 KG - Lavender',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.884000,
  default_wholesale = 0.826000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600023';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600023';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600023'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600023'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600020', '6253500761184', 'جل ارضيات هايبكس 1 كغم ليمون', 'Gel Floor 1 KG - Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.884000, 0.826000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600020');
UPDATE inv_item SET
  barcode = '6253500761184',
  name_ar = 'جل ارضيات هايبكس 1 كغم ليمون',
  name_en = 'Gel Floor 1 KG - Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.884000,
  default_wholesale = 0.826000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600020';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600020';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600020'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600020'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600029', '6253500761191', 'جل ارضيات هايبكس1800  كغم صنوبر', 'Gel Floor 1.8 KG - Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.691000, 1.580000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600029');
UPDATE inv_item SET
  barcode = '6253500761191',
  name_ar = 'جل ارضيات هايبكس1800  كغم صنوبر',
  name_en = 'Gel Floor 1.8 KG - Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.691000,
  default_wholesale = 1.580000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600029';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600029';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600029'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600029'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600030', '6253500763195', 'جل ارضيات هايبكس1800  كغم لافيندر', 'Gel Floor 1.8 KG - Lavender', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.691000, 1.580000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600030');
UPDATE inv_item SET
  barcode = '6253500763195',
  name_ar = 'جل ارضيات هايبكس1800  كغم لافيندر',
  name_en = 'Gel Floor 1.8 KG - Lavender',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.691000,
  default_wholesale = 1.580000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600030';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600030';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600030'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600030'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600025', '6253500761221', 'جل ارضيات هايبكس1800  كغم ليمون', 'Gel Floor 1.8 KG - Lemon', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.691000, 1.580000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600025');
UPDATE inv_item SET
  barcode = '6253500761221',
  name_ar = 'جل ارضيات هايبكس1800  كغم ليمون',
  name_en = 'Gel Floor 1.8 KG - Lemon',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.691000,
  default_wholesale = 1.580000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600025';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600025';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600025'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600025'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600018', '6253500760156', 'مطهر عام هايبكس 500 مل', 'Disinfectant 500 ML', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.427000, 0.399000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600018');
UPDATE inv_item SET
  barcode = '6253500760156',
  name_ar = 'مطهر عام هايبكس 500 مل',
  name_en = 'Disinfectant 500 ML',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.427000,
  default_wholesale = 0.399000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600018';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600018';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600018'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600018'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600040', '6253011995245', 'مطهر عام هايبكس 1000 مل', 'Disinfectant 1 L', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.761000, 0.711000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600040');
UPDATE inv_item SET
  barcode = '6253011995245',
  name_ar = 'مطهر عام هايبكس 1000 مل',
  name_en = 'Disinfectant 1 L',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.761000,
  default_wholesale = 0.711000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600040';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600040';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600040'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600040'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600132', '6253500764949', 'معطر ارضيات هايبكس 1 لتر ابيض /ياسمين', 'Multi Purpose Freshener 1 L/ White', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600132');
UPDATE inv_item SET
  barcode = '6253500764949',
  name_ar = 'معطر ارضيات هايبكس 1 لتر ابيض /ياسمين',
  name_en = 'Multi Purpose Freshener 1 L/ White',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600132';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600132';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600132'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600132'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600130', '6253500764963', 'معطر ارضيات هايبكس 1 لتر اخضر/فواكة', 'Multi Purpose Freshener 1 L/ Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600130');
UPDATE inv_item SET
  barcode = '6253500764963',
  name_ar = 'معطر ارضيات هايبكس 1 لتر اخضر/فواكة',
  name_en = 'Multi Purpose Freshener 1 L/ Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600130';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600130';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600130'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600130'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600134', '6253500764956', 'معطر ارضيات هايبكس 1 لتر ازرق/نسيم البحر', 'Multi Purpose Freshener 1 L/ Blue', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600134');
UPDATE inv_item SET
  barcode = '6253500764956',
  name_ar = 'معطر ارضيات هايبكس 1 لتر ازرق/نسيم البحر',
  name_en = 'Multi Purpose Freshener 1 L/ Blue',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600134';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600134';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600134'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600134'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600131', '6253500764932', 'معطر ارضيات هايبكس 1 لتر بنفسجي/لافندر', 'Multi Purpose Freshener 1 L/ Purple', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600131');
UPDATE inv_item SET
  barcode = '6253500764932',
  name_ar = 'معطر ارضيات هايبكس 1 لتر بنفسجي/لافندر',
  name_en = 'Multi Purpose Freshener 1 L/ Purple',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600131';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600131';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600131'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600131'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600133', '6253500764925', 'معطر ارضيات هايبكس 1 لتر ورد/زهري', 'Multi Purpose Freshener 1 L/ pink', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.846000, 0.790000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600133');
UPDATE inv_item SET
  barcode = '6253500764925',
  name_ar = 'معطر ارضيات هايبكس 1 لتر ورد/زهري',
  name_en = 'Multi Purpose Freshener 1 L/ pink',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.846000,
  default_wholesale = 0.790000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600133';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600133';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600133'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600133'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600140', '6253500765052', 'معطر ارضيات هايبكس 2.1لتر ابيض/نقاء الطبيعة', 'Multi Purpose Freshener 2.1 L/ White', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.460000, 1.365000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600140');
UPDATE inv_item SET
  barcode = '6253500765052',
  name_ar = 'معطر ارضيات هايبكس 2.1لتر ابيض/نقاء الطبيعة',
  name_en = 'Multi Purpose Freshener 2.1 L/ White',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.460000,
  default_wholesale = 1.365000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600140';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600140';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600140'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600140'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600140-2', '6253500765038', 'معطر ارضيات هايبكس2.1 لتر اخضر/نسيم الربيع', 'Multi Purpose Freshener 2.1 L/ Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.460000, 1.365000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600140-2');
UPDATE inv_item SET
  barcode = '6253500765038',
  name_ar = 'معطر ارضيات هايبكس2.1 لتر اخضر/نسيم الربيع',
  name_en = 'Multi Purpose Freshener 2.1 L/ Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.460000,
  default_wholesale = 1.365000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600140-2';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600140-2';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600140-2'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600140-2'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600140-3', '6253500765069', 'معطر ارضيات هايبكس 2.1 لتر ازرق فاتح/نسيم البحر', 'Multi Purpose Freshener 2.1 L/ Blue/ Breeze', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.460000, 1.365000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600140-3');
UPDATE inv_item SET
  barcode = '6253500765069',
  name_ar = 'معطر ارضيات هايبكس 2.1 لتر ازرق فاتح/نسيم البحر',
  name_en = 'Multi Purpose Freshener 2.1 L/ Blue/ Breeze',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.460000,
  default_wholesale = 1.365000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600140-3';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600140-3';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600140-3'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600140-3'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600140-4', '6253500765045', 'معطر ارضيات هايبكس 2.1 لتر زهري غامق/ورد', 'Multi Purpose Freshener 2.1 L/ Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.460000, 1.365000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600140-4');
UPDATE inv_item SET
  barcode = '6253500765045',
  name_ar = 'معطر ارضيات هايبكس 2.1 لتر زهري غامق/ورد',
  name_en = 'Multi Purpose Freshener 2.1 L/ Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.460000,
  default_wholesale = 1.365000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600140-4';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600140-4';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600140-4'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600140-4'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600065', '6253500765021', 'معطر ارضيات هايبكس 2.1 لتر نهدي/لافندر', 'Multi Purpose Freshener 2.1 L/ Lavender', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.460000, 1.365000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600065');
UPDATE inv_item SET
  barcode = '6253500765021',
  name_ar = 'معطر ارضيات هايبكس 2.1 لتر نهدي/لافندر',
  name_en = 'Multi Purpose Freshener 2.1 L/ Lavender',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.460000,
  default_wholesale = 1.365000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600065';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600065';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600065'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 6, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600065'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600065-2', '6253500763256', 'معطر ارضيات هايبكس 2.7 لتر ابيض/نقاء الطبيعة', 'Multi Purpose Freshener 2.7 L/ White', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600065-2');
UPDATE inv_item SET
  barcode = '6253500763256',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر ابيض/نقاء الطبيعة',
  name_en = 'Multi Purpose Freshener 2.7 L/ White',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600065-2';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600065-2';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600065-2'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600065-2'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600073', '6253500763270', 'معطر ارضيات هايبكس 2.7 لتر اخضر/نسيم الربيع', 'Multi Purpose Freshener 2.7 L/ Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600073');
UPDATE inv_item SET
  barcode = '6253500763270',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر اخضر/نسيم الربيع',
  name_en = 'Multi Purpose Freshener 2.7 L/ Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600073';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600073';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600073'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600073'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600064', '6253500763249', 'معطر ارضيات هايبكس 2.7 لتر ازرق غامق/انتعاش الصيف', 'Multi Purpose Freshener 2.7 L/ Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600064');
UPDATE inv_item SET
  barcode = '6253500763249',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر ازرق غامق/انتعاش الصيف',
  name_en = 'Multi Purpose Freshener 2.7 L/ Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600064';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600064';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600064'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600064'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600069', '6253500763300', 'معطر ارضيات هايبكس 2.7 لتر ازرق فاتح/نسيم البحر', 'Multi Purpose Freshener 2.7 L/ Breeze', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600069');
UPDATE inv_item SET
  barcode = '6253500763300',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر ازرق فاتح/نسيم البحر',
  name_en = 'Multi Purpose Freshener 2.7 L/ Breeze',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600069';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600069';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600069'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600069'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600067', '6253500763294', 'معطر ارضيات هايبكس 2.7 لتر اصفر/برائحة الحمضيات', 'Multi Purpose Freshener 2.7 L/ Yellow - Citrus', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600067');
UPDATE inv_item SET
  barcode = '6253500763294',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر اصفر/برائحة الحمضيات',
  name_en = 'Multi Purpose Freshener 2.7 L/ Yellow - Citrus',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600067';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600067';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600067'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600067'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600068', '6253500763324', 'معطر ارضيات هايبكس 2.7 لتر برتقال/فواكه الاستوائية', 'Multi Purpose Freshener 2.7 L/ Orange - Fruit', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600068');
UPDATE inv_item SET
  barcode = '6253500763324',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر برتقال/فواكه الاستوائية',
  name_en = 'Multi Purpose Freshener 2.7 L/ Orange - Fruit',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600068';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600068';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600068'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600068'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600072', '6253500763263', 'معطر ارضيات هايبكس 2.7 لتر بني/عطور شرقية', 'Multi Purpose Freshener 2.7 L/ Brown', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600072');
UPDATE inv_item SET
  barcode = '6253500763263',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر بني/عطور شرقية',
  name_en = 'Multi Purpose Freshener 2.7 L/ Brown',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600072';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600072';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600072'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600072'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600070', '6253500763317', 'معطر ارضيات هايبكس 2.7 لتر زهري غامق/ورد', 'Multi Purpose Freshener 2.7 L/ Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600070');
UPDATE inv_item SET
  barcode = '6253500763317',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر زهري غامق/ورد',
  name_en = 'Multi Purpose Freshener 2.7 L/ Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600070';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600070';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600070'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600070'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600071', '6253500763331', 'معطر ارضيات هايبكس 2.7 لتر زهري فاتح/سحر وردي', 'Multi Purpose Freshener 2.7 L/ Magic Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600071');
UPDATE inv_item SET
  barcode = '6253500763331',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر زهري فاتح/سحر وردي',
  name_en = 'Multi Purpose Freshener 2.7 L/ Magic Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600071';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600071';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600071'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600071'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600066', '6253500763287', 'معطر ارضيات هايبكس 2.7 لتر نهدي/لافندر', 'Multi Purpose Freshener 2.7 L/ Lavender', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.902000, 1.778000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600066');
UPDATE inv_item SET
  barcode = '6253500763287',
  name_ar = 'معطر ارضيات هايبكس 2.7 لتر نهدي/لافندر',
  name_en = 'Multi Purpose Freshener 2.7 L/ Lavender',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.902000,
  default_wholesale = 1.778000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600066';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600066';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600066'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600066'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400001', '6253500760361', 'منظف زجاج هايبكس 650 مل حمضيات', 'Glass Cleaner 650 ML- Green', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.653000, 0.611000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400001');
UPDATE inv_item SET
  barcode = '6253500760361',
  name_ar = 'منظف زجاج هايبكس 650 مل حمضيات',
  name_en = 'Glass Cleaner 650 ML- Green',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.653000,
  default_wholesale = 0.611000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400001';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400001'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400001'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400002', '6253500760279', 'منظف زجاج هايبكس 650 مل محيطات', 'Glass Cleaner 650 ML- Blue', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.653000, 0.611000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400002');
UPDATE inv_item SET
  barcode = '6253500760279',
  name_ar = 'منظف زجاج هايبكس 650 مل محيطات',
  name_en = 'Glass Cleaner 650 ML- Blue',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.653000,
  default_wholesale = 0.611000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400002';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400002';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400002'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400002'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600060', '6253500761320', 'سائل ايدي هايبكس 500 مل جوز الهند', 'Hand Washing 500 ML Coconut', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600060');
UPDATE inv_item SET
  barcode = '6253500761320',
  name_ar = 'سائل ايدي هايبكس 500 مل جوز الهند',
  name_en = 'Hand Washing 500 ML Coconut',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600060';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600060';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600060'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600060'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600059', '6253500763010', 'سائل ايدي هايبكس 500 مل حمضيات واعشاب', 'Hand Washing 500 ML Citrus', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600059');
UPDATE inv_item SET
  barcode = '6253500763010',
  name_ar = 'سائل ايدي هايبكس 500 مل حمضيات واعشاب',
  name_en = 'Hand Washing 500 ML Citrus',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600059';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600059';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600059'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600059'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600057', '6253500761306', 'سائل ايدي هايبكس 500 مل ربيع', 'Hand Washing 500 ML Spring', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600057');
UPDATE inv_item SET
  barcode = '6253500761306',
  name_ar = 'سائل ايدي هايبكس 500 مل ربيع',
  name_en = 'Hand Washing 500 ML Spring',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600057';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600057';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600057'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600057'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600015', '6253500760910', 'سائل ايدي هايبكس 500 مل صنوبر', 'Hand Washing 500 ML Pine', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600015');
UPDATE inv_item SET
  barcode = '6253500760910',
  name_ar = 'سائل ايدي هايبكس 500 مل صنوبر',
  name_en = 'Hand Washing 500 ML Pine',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600015';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600015';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600015'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600015'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600014', '6253500760903', 'سائل ايدي هايبكس 500 مل عود', 'Hand Washing 500 ML Oud', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600014');
UPDATE inv_item SET
  barcode = '6253500760903',
  name_ar = 'سائل ايدي هايبكس 500 مل عود',
  name_en = 'Hand Washing 500 ML Oud',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600014';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600014';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600014'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600014'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600058', '6253500761337', 'سائل ايدي هايبكس 500 مل فايوليت', 'Hand Washing 500 ML Vaiolet', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600058');
UPDATE inv_item SET
  barcode = '6253500761337',
  name_ar = 'سائل ايدي هايبكس 500 مل فايوليت',
  name_en = 'Hand Washing 500 ML Vaiolet',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600058';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600058';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600058'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600058'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600061', '6253011995474', 'سائل ايدي هايبكس 500 مل فواكه', 'Hand Washing 500 ML Ftuit', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600061');
UPDATE inv_item SET
  barcode = '6253011995474',
  name_ar = 'سائل ايدي هايبكس 500 مل فواكه',
  name_en = 'Hand Washing 500 ML Ftuit',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600061';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600061';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600061'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600061'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600009', '6253011995436', 'سائل ايدي هايبكس 500 مل كيوي وليمون', 'Hand Washing 500 ML Lemon -Kiwi', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600009');
UPDATE inv_item SET
  barcode = '6253011995436',
  name_ar = 'سائل ايدي هايبكس 500 مل كيوي وليمون',
  name_en = 'Hand Washing 500 ML Lemon -Kiwi',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600009';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600009';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600009'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600009'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600056', '6253011995450', 'سائل ايدي هايبكس 500 مل نسيم البحر', 'Hand Washing 500 ML Lemon -Breeze', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600056');
UPDATE inv_item SET
  barcode = '6253011995450',
  name_ar = 'سائل ايدي هايبكس 500 مل نسيم البحر',
  name_en = 'Hand Washing 500 ML Lemon -Breeze',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600056';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600056';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600056'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600056'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600011', '6253011995467', 'سائل ايدي هايبكس 500 مل ورد', 'Hand Washing 500 ML Lemon -Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600011');
UPDATE inv_item SET
  barcode = '6253011995467',
  name_ar = 'سائل ايدي هايبكس 500 مل ورد',
  name_en = 'Hand Washing 500 ML Lemon -Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600011';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600011';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600011'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600011'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600092', '6253500763683', 'فوم ايدي هايبكس 600 مل انتعاش', 'Hand Washing Foam 600 ML - Fresh', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600092');
UPDATE inv_item SET
  barcode = '6253500763683',
  name_ar = 'فوم ايدي هايبكس 600 مل انتعاش',
  name_en = 'Hand Washing Foam 600 ML - Fresh',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600092';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600092';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600092'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600092'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600089', '6253500763454', 'فوم ايدي هايبكس 600 مل زهري', 'Hand Washing Foam 600 ML - Rose', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600089');
UPDATE inv_item SET
  barcode = '6253500763454',
  name_ar = 'فوم ايدي هايبكس 600 مل زهري',
  name_en = 'Hand Washing Foam 600 ML - Rose',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600089';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600089';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600089'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600089'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600088', '6253500763652', 'فوم ايدي هايبكس 600 مل عود', 'Hand Washing Foam 600 ML - Oud', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.711000, 0.665000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600088');
UPDATE inv_item SET
  barcode = '6253500763652',
  name_ar = 'فوم ايدي هايبكس 600 مل عود',
  name_en = 'Hand Washing Foam 600 ML - Oud',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 0.711000,
  default_wholesale = 0.665000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600088';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600088';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600088'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600088'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600037', '6253011995528', 'سائل ايدي هايبكس 500 مل عرض شرنك 3 حبات', 'Hand Washing - Shrink', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.975000, 1.918000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600037');
UPDATE inv_item SET
  barcode = '6253011995528',
  name_ar = 'سائل ايدي هايبكس 500 مل عرض شرنك 3 حبات',
  name_en = 'Hand Washing - Shrink',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Hand Wash') LIMIT 1),
  default_sale = 1.975000,
  default_wholesale = 1.918000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600037';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600037';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600037'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600037'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600110', '6253500764383', 'سائل غسيل الملابس هايبكس 3 لتر ازرق', 'Liquid laundry 3 L - Blue', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 2.883000, 2.694000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600110');
UPDATE inv_item SET
  barcode = '6253500764383',
  name_ar = 'سائل غسيل الملابس هايبكس 3 لتر ازرق',
  name_en = 'Liquid laundry 3 L - Blue',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 2.883000,
  default_wholesale = 2.694000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600110';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600110';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600110'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600110'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600110-2', '6253500761696', 'سائل غسيل الملابس هايبكس 3 لتر لافيندر', 'Liquid laundry 3 L - Lavender', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 2.883000, 2.694000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600110-2');
UPDATE inv_item SET
  barcode = '6253500761696',
  name_ar = 'سائل غسيل الملابس هايبكس 3 لتر لافيندر',
  name_en = 'Liquid laundry 3 L - Lavender',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 2.883000,
  default_wholesale = 2.694000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600110-2';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600110-2';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600110-2'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600110-2'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400024-2', '6253500670125', 'مزيل تكلس هايبكس 950 مل', 'Toilet Bowl Disinfectant 950 ML', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.634000, 0.718000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400024-2');
UPDATE inv_item SET
  barcode = '6253500670125',
  name_ar = 'مزيل تكلس هايبكس 950 مل',
  name_en = 'Toilet Bowl Disinfectant 950 ML',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 0.634000,
  default_wholesale = 0.718000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400024-2';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400024-2';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400024-2'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400024-2'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400022-2', '6253500760408', 'منظف بخاخ هايبكس 950 مل متعدد الاستعمال', 'Multi Purpose Cleaner 950 Ml', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.176000, 1.099000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400022-2');
UPDATE inv_item SET
  barcode = '6253500760408',
  name_ar = 'منظف بخاخ هايبكس 950 مل متعدد الاستعمال',
  name_en = 'Multi Purpose Cleaner 950 Ml',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.176000,
  default_wholesale = 1.099000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400022-2';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400022-2';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400022-2'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400022-2'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200028', '6253500760323', 'منظف بخاخ هايبكس 950 مل حمام فائق القوى', 'BathRoom Cleaner 950 Ml', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.176000, 1.099000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200028');
UPDATE inv_item SET
  barcode = '6253500760323',
  name_ar = 'منظف بخاخ هايبكس 950 مل حمام فائق القوى',
  name_en = 'BathRoom Cleaner 950 Ml',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.176000,
  default_wholesale = 1.099000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200028';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200028';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200028'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200028'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400003', '6253500760378', 'منظف بخاخ هايبكس 950 مل مطابخ', 'Kitchen Cleaner 950 ML', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.176000, 1.099000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400003');
UPDATE inv_item SET
  barcode = '6253500760378',
  name_ar = 'منظف بخاخ هايبكس 950 مل مطابخ',
  name_en = 'Kitchen Cleaner 950 ML',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Others') LIMIT 1),
  default_sale = 1.176000,
  default_wholesale = 1.099000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400003';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400003';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400003'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400003'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '400020-2', '621102391127', 'مسحوق غسيل هايبكس 500 غ', 'Powder Washing 500 G', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 0.646000, 0.603000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '400020-2');
UPDATE inv_item SET
  barcode = '621102391127',
  name_ar = 'مسحوق غسيل هايبكس 500 غ',
  name_en = 'Powder Washing 500 G',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 0.646000,
  default_wholesale = 0.603000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '400020-2';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '400020-2';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '400020-2'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 24, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '400020-2'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600034', '6253500761412', 'مسحوق غسيل هايبكس 1.5 كغم', 'Powder Washing 1.5 KG', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.787000, 1.670000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600034');
UPDATE inv_item SET
  barcode = '6253500761412',
  name_ar = 'مسحوق غسيل هايبكس 1.5 كغم',
  name_en = 'Powder Washing 1.5 KG',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 1.787000,
  default_wholesale = 1.670000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600034';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600034';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600034'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 8, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600034'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600129', '6211023913527', 'مسحوق غسيل هايبكس 3 كغم', 'Powder Washing 3 KG', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 3.113000, 2.909000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600129');
UPDATE inv_item SET
  barcode = '6211023913527',
  name_ar = 'مسحوق غسيل هايبكس 3 كغم',
  name_en = 'Powder Washing 3 KG',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 3.113000,
  default_wholesale = 2.909000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600129';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600129';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600129'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 4, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '600129'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600136', '6253500765533', 'مسحوق غسيل هايبكس 5 كغم سطل اخضر', 'Powder Washing 5 KG', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 5.765000, 5.388000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600136');
UPDATE inv_item SET
  barcode = '6253500765533',
  name_ar = 'مسحوق غسيل هايبكس 5 كغم سطل اخضر',
  name_en = 'Powder Washing 5 KG',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 5.765000,
  default_wholesale = 5.388000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600136';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600136';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600136'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '600127', '6253500765175', 'مسحوق غسيل هايبكس كيس 10 كيلو', 'Powder Washing 10 KG', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 9.916000, 9.267000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '600127');
UPDATE inv_item SET
  barcode = '6253500765175',
  name_ar = 'مسحوق غسيل هايبكس كيس 10 كيلو',
  name_en = 'Powder Washing 10 KG',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 9.916000,
  default_wholesale = 9.267000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '600127';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '600127';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '600127'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200027', '6253500765519', 'هايبكس مسحوق مزيل البقع 400 غم *12 ازرق', 'Fabric Stain Remover Power - Blue 500 G', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.172000, 1.096000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200027');
UPDATE inv_item SET
  barcode = '6253500765519',
  name_ar = 'هايبكس مسحوق مزيل البقع 400 غم *12 ازرق',
  name_en = 'Fabric Stain Remover Power - Blue 500 G',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 1.172000,
  default_wholesale = 1.096000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200027';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200027';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200027'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200027'
ORDER BY u.id
LIMIT 1;

INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT '200028-2', '6253500765526', 'هايبكس مسحوق مزيل بقع 400 غم *12 -اخضر', 'Fabric Stain Remover Power - Green 500 G', 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, 1.172000, 1.096000,
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = '200028-2');
UPDATE inv_item SET
  barcode = '6253500765526',
  name_ar = 'هايبكس مسحوق مزيل بقع 400 غم *12 -اخضر',
  name_en = 'Fabric Stain Remover Power - Green 500 G',
  category_id = (SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER('Deterganet') LIMIT 1),
  default_sale = 1.172000,
  default_wholesale = 1.096000,
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = '200028-2';
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = '200028-2';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, 0
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = '200028-2'
ORDER BY u.id
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 12, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = '200028-2'
ORDER BY u.id
LIMIT 1;

SET SQL_SAFE_UPDATES = 1;
