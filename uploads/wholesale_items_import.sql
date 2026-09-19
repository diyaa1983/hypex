-- Hypex wholesale items export — apply on SERVER MySQL
-- Generated: 2026-08-10T14:46:24.831Z
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Units PCS / BOX
INSERT INTO inv_unit (code, name_ar, is_active) VALUES ('PCS', 'قطعة', 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), is_active=VALUES(is_active);
INSERT INTO inv_unit (code, name_ar, is_active) VALUES ('BOX', 'كرتونة', 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), is_active=VALUES(is_active);

-- Categories by name (server may have different ids)
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '1', 'Air Freshener', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Air Freshener');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '2', 'Bleach', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Bleach');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '3', 'Carpet Cleaner', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Carpet Cleaner');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '4', 'Dishwashing', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Dishwashing');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '5', 'Drain Cleaner', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Drain Cleaner');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '6', 'Fabric Softner', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Fabric Softner');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '7', 'Gel Cleaner', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Gel Cleaner');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '8', 'General Disinfectant', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='General Disinfectant');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '9', 'General Freshener', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='General Freshener');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '10', 'Glass Cleaner', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Glass Cleaner');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '11', 'Hand Wash', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Hand Wash');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '12', 'Laundry Det', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Laundry Det');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '13', 'Multi Purpose Cleaner', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Multi Purpose Cleaner');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '15', 'Kitchen Cleaner', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Kitchen Cleaner');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '16', 'Powder Det.', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Powder Det.');
INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT '17', 'Stain Removers', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)='Stain Removers');

-- Items (match by sku)
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('400021', 'معطر بخاخ هايبكس 450 مل عود', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Air Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765090',
    i.name_en='Air Freshner 450 ML Oud',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='400021';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('400024', 'مزيل تكلس هايبكس 950 مل', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Multi Purpose Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500670125',
    i.name_en='Toilet Bowl Disinfectant 950 ML',
    i.default_wholesale='0.718391',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='400024';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('400022', 'منظف بخاخ هايبكس 950 مل متعدد الاستعمال', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Multi Purpose Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760408',
    i.name_en='Multi Purpose Cleaner 950 Ml',
    i.default_wholesale='1.099138',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='400022';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('400020', 'مسحوق غسيل هايبكس 500 غ', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Powder Det.'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='621102391127',
    i.name_en='Powder Washing 500 G',
    i.default_wholesale='0.603448',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='400020';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200001', 'مبيض غسيل هايبكس 950 مل', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760019',
    i.name_en='Bleach 950 ML',
    i.default_wholesale='0.646552',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200001';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200003', 'مبيض غسيل هايبكس 950 مل زهور', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760200',
    i.name_en='Bleach 950 ML Rose',
    i.default_wholesale='0.646552',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200003';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200002', 'مبيض غسيل هايبكس 950 مل ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760194',
    i.name_en='Bleach 950 ML Lemon',
    i.default_wholesale='0.646552',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200002';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200004', 'مبيض غسيل هايبكس 1.89 لتر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760026',
    i.name_en='Bleach1.89 L',
    i.default_wholesale='1.149425',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200004';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200006', 'مبيض غسيل هايبكس 1.89 لتر زهور', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760231',
    i.name_en='Bleach1.89 L Rose',
    i.default_wholesale='1.149425',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200006';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200005', 'مبيض غسيل هايبكس 1.89 لتر ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760255',
    i.name_en='Bleach1.89 L Lemon',
    i.default_wholesale='1.149425',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200005';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200007', 'مبيض غسيل هايبكس 3.78 لتر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760033',
    i.name_en='Bleach3.78 L',
    i.default_wholesale='2.015086',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200007';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200009', 'مبيض غسيل هايبكس 3.78 لتر زهور', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760248',
    i.name_en='Bleach3.78 L Rose',
    i.default_wholesale='2.015086',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200009';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200008', 'مبيض غسيل هايبكس 3.78 لتر ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760224',
    i.name_en='Bleach3.78 L Lemon',
    i.default_wholesale='2.015086',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200008';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200018', 'مزيل بقع هايبكس 950 مل ملابس ملونة زهري', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760712',
    i.name_en='Color Clothes Remover Stain 950  ML -Pink',
    i.default_wholesale='1.099138',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200018';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200011', 'مزيل بقع هايبكس 950 مل ملابس ملونة ازرق', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760705',
    i.name_en='Color Clothes Remover Stain 950  ML -Blue',
    i.default_wholesale='1.099138',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200011';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('130080', 'مستر هايبو كلور 950 مل', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761399',
    i.name_en='Bleach 950 ML',
    i.default_wholesale='0.262213',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='130080';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('130151', 'مستر هايبو كلور 950 مل زهور', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765571',
    i.name_en='Bleach 950 ML Rose',
    i.default_wholesale='0.262213',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='130151';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('130150', 'مستر هايبو كلور 950 مل  ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765564',
    i.name_en='Bleach 950 ML lemon',
    i.default_wholesale='0.262213',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='130150';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('130082', 'مستر هايبو كلور 3.78 لتر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763041',
    i.name_en='Bleach3.78 L',
    i.default_wholesale='1.077586',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='130082';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('130155', 'مستر هايبو كلور 3.78 لتر زهور', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765540',
    i.name_en='Bleach3.78 L Rose',
    i.default_wholesale='1.077586',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='130155';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('130154', 'مستر هايبو كلور 3.78 لتر ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Bleach'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765557',
    i.name_en='Bleach3.78 Lemon',
    i.default_wholesale='1.077586',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='130154';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('500003', 'منظف سجاد هايبكس 1 لتر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Carpet Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760095',
    i.name_en='Carpet Cleaner 1 L',
    i.default_wholesale='0.915948',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='500003';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300002', 'سائل جلي هايبكس 950 مل تفاح', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760064',
    i.name_en='Dish Washing 950 ML Apple',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300002';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300005', 'سائل جلي هايبكس 950 مل زهور', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761313',
    i.name_en='Dish Washing 950 ML Rose',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300005';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300004', 'سائل جلي هايبكس 950 مل فراولة', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760187',
    i.name_en='Dish Washing 950 ML strawberry',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300004';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300010', 'سائل جلي هايبكس 950 مل لافندر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765489',
    i.name_en='Dish Washing 950 ML Lavender',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300010';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300001', 'سائل جلي هايبكس 950 مل ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760057',
    i.name_en='Dish Washing 950 ML Lemon',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300001';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300021', 'سائل جلي هايبكس 1.8 لتر ازرق ليلك', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761511',
    i.name_en='Dish Washing 1.8 L Blue Rose',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300021';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300008', 'سائل جلي هايبكس 1.8 لتر برتقال', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761498',
    i.name_en='Dish Washing 1.8 L  Orange',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300008';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300007', 'سائل جلي هايبكس 1.8 لتر تفاح', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761467',
    i.name_en='Dish Washing 1.8 L  Apple',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300007';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300012', 'سائل جلي هايبكس 1.8 لتر زهري ورد', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761504',
    i.name_en='Dish Washing 1.8 L  Rose',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300012';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300009', 'سائل جلي هايبكس 1.8 لتر فراولة', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761474',
    i.name_en='Dish Washing 1.8 L  strawberry',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300009';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300006', 'سائل جلي هايبكس 1.8 لتر ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761450',
    i.name_en='Dish Washing 1.8 L  Lemon',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300006';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300011', 'سائل جلي هايبكس 1.8 لتر نهدي لافندر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761481',
    i.name_en='Dish Washing 1.8 L  Lavender',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300011';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('300024', 'سائل جلي هايبكس 3.78 لتر زهري ورد جالون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Dishwashing'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763607',
    i.name_en='Dish Washing 3.78 L  Rose Galone',
    i.default_wholesale='3.071121',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='300024';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('500001', 'مسلك روسور هايبكس 1 لتر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Drain Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760132',
    i.name_en='Drain opener 1 L',
    i.default_wholesale='1.099138',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='500001';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600045', 'ملين ملابس هايبكس 1 لتر اخضر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Fabric Softner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500762679',
    i.name_en='Fabric Softner 1 L Green',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600045';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600043', 'ملين ملابس هايبكس 1 لتر ازرق', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Fabric Softner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500762662',
    i.name_en='Fabric Softner 1 L Blue',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600043';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600044', 'ملين ملابس هايبكس 1 لتر زهري', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Fabric Softner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500762655',
    i.name_en='Fabric Softner 1 L Pink',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600044';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600031', 'ملين ملابس هايبكس 2 لتر اخضر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Fabric Softner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500762648',
    i.name_en='Fabric Softner 2 L Green',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600031';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600032', 'ملين ملابس هايبكس 2 لتر ازرق', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Fabric Softner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761429',
    i.name_en='Fabric Softner 2 L Blue',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600032';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600033', 'ملين ملابس هايبكس 2 لتر زهري', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Fabric Softner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500762914',
    i.name_en='Fabric Softner 2 L Pink',
    i.default_wholesale='1.508621',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600033';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600019', 'جل ارضيات هايبكس 1 كغم صنوبر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Gel Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760736',
    i.name_en='Gel Floor 1 KG - Green',
    i.default_wholesale='0.826149',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600019';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600023', 'جل ارضيات هايبكس 1 كغم لافندر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Gel Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763034',
    i.name_en='Gel Floor 1 KG - Lavender',
    i.default_wholesale='0.826149',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600023';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600020', 'جل ارضيات هايبكس 1 كغم ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Gel Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761184',
    i.name_en='Gel Floor 1 KG - Lemon',
    i.default_wholesale='0.826149',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600020';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600029', 'جل ارضيات هايبكس1800  كغم صنوبر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Gel Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761191',
    i.name_en='Gel Floor 1.8 KG - Green',
    i.default_wholesale='1.580460',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600029';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600030', 'جل ارضيات هايبكس1800  كغم لافيندر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Gel Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763195',
    i.name_en='Gel Floor 1.8 KG - Lavender',
    i.default_wholesale='1.580460',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600030';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600025', 'جل ارضيات هايبكس1800  كغم ليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Gel Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761221',
    i.name_en='Gel Floor 1.8 KG - Lemon',
    i.default_wholesale='1.580460',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600025';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600018', 'مطهر عام هايبكس 500 مل', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Disinfectant'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760156',
    i.name_en='Disinfectant 500 ML',
    i.default_wholesale='0.399425',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600018';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600040', 'مطهر عام هايبكس 1000 مل', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Disinfectant'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253011995245',
    i.name_en='Disinfectant 1 L',
    i.default_wholesale='0.711207',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600040';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600132', 'معطر ارضيات هايبكس 1 لتر ابيض /ياسمين', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500764949',
    i.name_en='Multi Purpose Freshener 1 L/ White',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600132';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600130', 'معطر ارضيات هايبكس 1 لتر اخضر/فواكة', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500764963',
    i.name_en='Multi Purpose Freshener 1 L/ Green',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600130';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600134', 'معطر ارضيات هايبكس 1 لتر ازرق/نسيم البحر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500764956',
    i.name_en='Multi Purpose Freshener 1 L/ Blue',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600134';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600131', 'معطر ارضيات هايبكس 1 لتر بنفسجي/لافندر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500764932',
    i.name_en='Multi Purpose Freshener 1 L/ Purple',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600131';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600133', 'معطر ارضيات هايبكس 1 لتر ورد/زهري', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500764925',
    i.name_en='Multi Purpose Freshener 1 L/ pink',
    i.default_wholesale='0.790230',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600133';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600140', 'معطر ارضيات هايبكس 2.1 لتر زهري غامق/ورد', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765045',
    i.name_en='Multi Purpose Freshener 2.1 L/ Rose',
    i.default_wholesale='1.364943',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600140';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600065', 'معطر ارضيات هايبكس 2.7 لتر ابيض/نقاء الطبيعة', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763256',
    i.name_en='Multi Purpose Freshener 2.7 L/ White',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600065';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600073', 'معطر ارضيات هايبكس 2.7 لتر اخضر/نسيم الربيع', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763270',
    i.name_en='Multi Purpose Freshener 2.7 L/ Green',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600073';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600064', 'معطر ارضيات هايبكس 2.7 لتر ازرق غامق/انتعاش الصيف', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763249',
    i.name_en='Multi Purpose Freshener 2.7 L/ Green',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600064';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600069', 'معطر ارضيات هايبكس 2.7 لتر ازرق فاتح/نسيم البحر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763300',
    i.name_en='Multi Purpose Freshener 2.7 L/ Breeze',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600069';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600067', 'معطر ارضيات هايبكس 2.7 لتر اصفر/برائحة الحمضيات', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763294',
    i.name_en='Multi Purpose Freshener 2.7 L/ Yellow - Citrus',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600067';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600068', 'معطر ارضيات هايبكس 2.7 لتر برتقال/فواكه الاستوائية', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763324',
    i.name_en='Multi Purpose Freshener 2.7 L/ Orange - Fruit',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600068';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600072', 'معطر ارضيات هايبكس 2.7 لتر بني/عطور شرقية', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763263',
    i.name_en='Multi Purpose Freshener 2.7 L/ Brown',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600072';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600070', 'معطر ارضيات هايبكس 2.7 لتر زهري غامق/ورد', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763317',
    i.name_en='Multi Purpose Freshener 2.7 L/ Rose',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600070';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600071', 'معطر ارضيات هايبكس 2.7 لتر زهري فاتح/سحر وردي', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763331',
    i.name_en='Multi Purpose Freshener 2.7 L/ Magic Rose',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600071';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600066', 'معطر ارضيات هايبكس 2.7 لتر نهدي/لافندر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='General Freshener'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763287',
    i.name_en='Multi Purpose Freshener 2.7 L/ Lavender',
    i.default_wholesale='1.778017',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600066';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('400001', 'منظف زجاج هايبكس 650 مل حمضيات', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Glass Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760361',
    i.name_en='Glass Cleaner 650 ML- Green',
    i.default_wholesale='0.610632',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='400001';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('400002', 'منظف زجاج هايبكس 650 مل محيطات', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Glass Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760279',
    i.name_en='Glass Cleaner 650 ML- Blue',
    i.default_wholesale='0.610632',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='400002';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600060', 'سائل ايدي هايبكس 500 مل جوز الهند', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761320',
    i.name_en='Hand Washing 500 ML Coconut',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600060';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600059', 'سائل ايدي هايبكس 500 مل حمضيات واعشاب', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763010',
    i.name_en='Hand Washing 500 ML Citrus',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600059';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600057', 'سائل ايدي هايبكس 500 مل ربيع', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761306',
    i.name_en='Hand Washing 500 ML Spring',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600057';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600015', 'سائل ايدي هايبكس 500 مل صنوبر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760910',
    i.name_en='Hand Washing 500 ML Pine',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600015';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600014', 'سائل ايدي هايبكس 500 مل عود', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760903',
    i.name_en='Hand Washing 500 ML Oud',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600014';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600058', 'سائل ايدي هايبكس 500 مل فايوليت', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761337',
    i.name_en='Hand Washing 500 ML Vaiolet',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600058';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600061', 'سائل ايدي هايبكس 500 مل فواكه', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253011995474',
    i.name_en='Hand Washing 500 ML Ftuit',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600061';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600009', 'سائل ايدي هايبكس 500 مل كيوي وليمون', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253011995436',
    i.name_en='Hand Washing 500 ML Lemon -Kiwi',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600009';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600056', 'سائل ايدي هايبكس 500 مل نسيم البحر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253011995450',
    i.name_en='Hand Washing 500 ML Lemon -Breeze',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600056';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600011', 'سائل ايدي هايبكس 500 مل ورد', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253011995467',
    i.name_en='Hand Washing 500 ML Lemon -Rose',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600011';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600092', 'فوم ايدي هايبكس 600 مل انتعاش', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763683',
    i.name_en='Hand Washing Foam 600 ML - Fresh',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600092';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600089', 'فوم ايدي هايبكس 600 مل زهري', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763454',
    i.name_en='Hand Washing Foam 600 ML - Rose',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600089';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600088', 'فوم ايدي هايبكس 600 مل عود', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500763652',
    i.name_en='Hand Washing Foam 600 ML - Oud',
    i.default_wholesale='0.664511',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600088';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600037', 'سائل ايدي هايبكس 500 مل عرض شرنك 3 حبات', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Hand Wash'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253011995528',
    i.name_en='Hand Washing - Shrink',
    i.default_wholesale='1.918103',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600037';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600110', 'سائل غسيل الملابس هايبكس 3 لتر لافيندر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Laundry Det'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761696',
    i.name_en='Liquid laundry 3 L - Lavender',
    i.default_wholesale='2.693966',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600110';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200028', 'هايبكس مسحوق مزيل بقع 400 غم *12 -اخضر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Stain Removers'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765526',
    i.name_en='Fabric Stain Remover Power - Green 500 G',
    i.default_wholesale='1.095546',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200028';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('400003', 'منظف بخاخ هايبكس 950 مل مطابخ', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Kitchen Cleaner'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500760378',
    i.name_en='Kitchen Cleaner 950 ML',
    i.default_wholesale='1.099138',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='400003';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600034', 'مسحوق غسيل هايبكس 1.5 كغم', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Powder Det.'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500761412',
    i.name_en='Powder Washing 1.5 KG',
    i.default_wholesale='1.670259',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600034';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600129', 'مسحوق غسيل هايبكس 3 كغم', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Powder Det.'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6211023913527',
    i.name_en='Powder Washing 3 KG',
    i.default_wholesale='2.909483',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600129';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600136', 'مسحوق غسيل هايبكس 5 كغم سطل اخضر', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Powder Det.'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765533',
    i.name_en='Powder Washing 5 KG',
    i.default_wholesale='5.387931',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600136';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('600127', 'مسحوق غسيل هايبكس كيس 10 كيلو', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Powder Det.'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765175',
    i.name_en='Powder Washing 10 KG',
    i.default_wholesale='9.267241',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='600127';
INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES ('200027', 'هايبكس مسحوق مزيل البقع 400 غم *12 ازرق', 'قطعة', '0.000000', '0.000000', 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);
UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)='Stain Removers'
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode='6253500765519',
    i.name_en='Fabric Stain Remover Power - Blue 500 G',
    i.default_wholesale='1.095546',
    i.default_sale='0.000000',
    i.default_cost='0.000000',
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku='200027';

-- Item units: base PCS + BOX pack if any
-- units for 400021
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='400021';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400021' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400021' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 400024
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='400024';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400024' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400024' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 400022
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='400022';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400022' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400022' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 400020
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='400020';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400020' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '24.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400020' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200001
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200001' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '18.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200001' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200003
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200003';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200003' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200003' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200002
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200002';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200002' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200002' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200004
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200004';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200004' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '9.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200004' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200006
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200006';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200006' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '9.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200006' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200005
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200005';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200005' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '9.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200005' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200007
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200007';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200007' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200007' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200009
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200009';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200009' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200009' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200008
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200008';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200008' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200008' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200018
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200018';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200018' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200018' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200011
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200011';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200011' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200011' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 130080
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='130080';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130080' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130080' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 130151
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='130151';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130151' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130151' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 130150
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='130150';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130150' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130150' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 130082
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='130082';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130082' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130082' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 130155
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='130155';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130155' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130155' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 130154
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='130154';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130154' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='130154' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 500003
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='500003';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='500003' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='500003' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300002
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300002';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300002' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300002' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300005
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300005';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300005' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300005' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300004
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300004';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300004' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300004' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300010
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300010';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300010' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300010' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300001
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300001' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300001' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300021
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300021';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300021' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300021' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300008
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300008';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300008' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300008' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300007
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300007';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300007' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300007' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300012
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300012';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300012' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300012' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300009
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300009';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300009' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300009' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300006
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300006';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300006' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300006' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300011
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300011';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300011' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300011' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 300024
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='300024';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300024' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='300024' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 500001
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='500001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='500001' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='500001' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600045
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600045';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600045' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600045' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600043
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600043';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600043' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600043' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600044
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600044';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600044' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600044' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600031
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600031';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600031' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600031' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600032
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600032';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600032' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600032' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600033
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600033';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600033' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600033' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600019
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600019';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600019' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600019' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600023
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600023';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600023' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600023' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600020
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600020';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600020' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600020' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600029
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600029';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600029' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600029' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600030
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600030';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600030' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600030' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600025
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600025';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600025' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600025' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600018
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600018';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600018' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600018' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600040
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600040';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600040' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600040' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600132
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600132';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600132' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600132' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600130
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600130';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600130' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600130' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600134
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600134';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600134' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600134' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600131
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600131';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600131' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600131' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600133
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600133';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600133' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600133' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600140
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600140';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600140' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '6.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600140' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600065
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600065';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600065' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600065' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600073
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600073';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600073' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600073' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600064
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600064';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600064' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600064' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600069
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600069';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600069' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600069' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600067
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600067';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600067' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600067' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600068
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600068';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600068' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600068' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600072
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600072';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600072' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600072' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600070
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600070';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600070' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600070' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600071
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600071';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600071' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600071' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600066
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600066';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600066' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600066' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 400001
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='400001';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400001' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400001' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 400002
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='400002';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400002' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400002' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600060
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600060';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600060' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600060' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600059
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600059';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600059' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600059' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600057
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600057';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600057' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600057' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600015
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600015';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600015' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600015' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600014
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600014';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600014' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600014' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600058
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600058';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600058' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600058' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600061
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600061';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600061' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600061' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600009
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600009';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600009' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600009' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600056
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600056';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600056' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600056' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600011
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600011';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600011' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600011' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600092
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600092';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600092' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600092' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600089
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600089';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600089' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600089' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600088
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600088';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600088' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600088' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600037
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600037';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600037' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600037' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600110
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600110';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600110' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600110' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 200028
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200028';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200028' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200028' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 400003
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='400003';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400003' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='400003' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600034
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600034';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600034' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '8.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600034' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600129
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600129';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600129' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '4.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600129' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;
-- units for 600136
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600136';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600136' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
-- units for 600127
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='600127';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='600127' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
-- units for 200027
DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku='200027';
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '1.000000', 1, 0
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200027' AND (u.code='PCS' OR u.name_ar='قطعة')
LIMIT 1;
INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, '12.000000', 0, 1
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku='200027' AND (u.code='BOX' OR u.name_ar='كرتونة')
LIMIT 1;

SET FOREIGN_KEY_CHECKS=1;
-- done