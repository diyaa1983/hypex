-- نفّذ على السيرفر فقط (176.29.176.192) عبر Workbench.
-- مصدر: استيراد المناطق.xlsx محلياً ثم تصدير هذا الملف.
USE hypex;
SET SQL_SAFE_UPDATES = 0;
SET FOREIGN_KEY_CHECKS = 0;

-- 1) فك الربط ثم حذف المناطق/العناوين
UPDATE crm_customer SET region_address_id = NULL, region_id = NULL
 WHERE region_id IS NOT NULL OR region_address_id IS NOT NULL;
DELETE FROM crm_sales_rep_region_address;
DELETE FROM crm_sales_rep_region;
DELETE FROM crm_region_address;
DELETE FROM crm_region;

SET FOREIGN_KEY_CHECKS = 1;

-- 2) المناطق
INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('R0001', 'عمان الغربية', 10, 1);
INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('R0030', 'شمال عمان', 60, 1);
INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('R0031', 'وسط عمان', 130, 1);
INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('R0032', 'الشمال', 560, 1);
INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('R0033', 'الزرقاء', 580, 1);
INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('R0034', 'عمان الشرقية', 1570, 1);
INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('R0035', 'الجنوب', 1860, 1);

-- 3) العناوين
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الرابيه', 10, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الشميساني', 30, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'العبدلي', 40, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الجاردنز', 300, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'المدينة الرياضية', 390, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'المدينة المنورة', 410, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'النزهه', 430, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ابو نصير', 1540, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'البيادر', 1560, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'عمان', 1580, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الصويفيه', 2060, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'المهاجرين', 4160, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'تلاع العلي', 4590, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'خلدا', 4600, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'جبل الحسين', 5100, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'مرج الحمام', 5200, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'مكة مول', 5370, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ناعور', 5380, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الغربية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'شفا بدران', 60, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'صويلح', 120, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ابو نصير', 140, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'البقعة', 190, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الجبيهة', 310, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ضاحية الرشيد', 530, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'عين الباشا', 1630, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'السلط', 4630, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الفحيص', 4790, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'عمان', 4930, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'دابوق', 5170, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ماحص', 5190, 1
FROM crm_region r
WHERE r.name_ar = 'شمال عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'طبربور', 130, 1
FROM crm_region r
WHERE r.name_ar = 'وسط عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الهاشمي الشمالي', 2080, 1
FROM crm_region r
WHERE r.name_ar = 'وسط عمان'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الرمثا', 560, 1
FROM crm_region r
WHERE r.name_ar = 'الشمال'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'اربد', 630, 1
FROM crm_region r
WHERE r.name_ar = 'الشمال'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'جرش', 1140, 1
FROM crm_region r
WHERE r.name_ar = 'الشمال'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'عجلون', 1240, 1
FROM crm_region r
WHERE r.name_ar = 'الشمال'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الغور', 1490, 1
FROM crm_region r
WHERE r.name_ar = 'الشمال'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'عوجان', 580, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الرصيفة', 690, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الزرقاء', 800, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'المفرق', 840, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ماركا', 2150, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'المخيم', 2210, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الخالدية', 5900, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'بلعما', 5930, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الهاشمية', 5940, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الضليل', 5960, 1
FROM crm_region r
WHERE r.name_ar = 'الزرقاء'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'المقابلين', 1570, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'القسطل', 1650, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'حي نزال', 1670, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'سحاب', 1680, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الجمرك', 1700, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'دوار الجمرك', 1760, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'وادي الرمم', 1790, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الوحدات', 1830, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'عمان', 1850, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الاشرفية', 2310, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'النصر', 2320, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ماركا', 2540, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'خريبة السوق', 2610, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'جبل الزهور', 2770, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'جبل التاج', 2780, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'جاوا', 2800, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الياسمين', 2820, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'المستندة', 2970, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'القويسمة', 2990, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الزهور', 3030, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الحزام', 3040, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الجويدة', 3050, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ابو علندا', 3090, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'ضاحية الياسمين', 3200, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'اليادودة', 3260, 1
FROM crm_region r
WHERE r.name_ar = 'عمان الشرقية'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'مادبا', 1860, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الكرك', 3320, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الجيزة', 3870, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الشوبك', 3940, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'الطفيلة', 3950, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'العقبة', 4030, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'القطرانة', 4150, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'معان', 4410, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;
INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)
SELECT r.id, 'وادي موسى', 4500, 1
FROM crm_region r
WHERE r.name_ar = 'الجنوب'
LIMIT 1;

-- 4) ربط المندوب بالعنوان (بالاسم)
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 6
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 7
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 9
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'شفا بدران'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'صويلح'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 13
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ضاحية الرشيد'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 14
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 15
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عين الباشا'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 16
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 8
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجاردنز'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرابيه'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الشميساني'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العبدلي'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 10
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة الرياضية'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 11
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة المنورة'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 12
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النزهه'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 5
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 5
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
WHERE sr.name_ar = 'ركان'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
WHERE sr.name_ar = 'ركان'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الغور'
WHERE sr.name_ar = 'ركان'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
WHERE sr.name_ar = 'ركان'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
WHERE sr.name_ar = 'ركان'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
WHERE sr.name_ar = 'سامر ابو جاموس'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
WHERE sr.name_ar = 'سامر ابو جاموس'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
WHERE sr.name_ar = 'سامر ابو جاموس'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
WHERE sr.name_ar = 'سامر ابو جاموس'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 11
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 12
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عين الباشا'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 6
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجمرك'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القسطل'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 9
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 7
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دوار الجمرك'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 5
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 10
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 8
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 19
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الاشرفية'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 18
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجويدة'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 17
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الحزام'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 16
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزهور'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 15
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القويسمة'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 14
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المستندة'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 13
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النصر'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 12
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 22
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اليادودة'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 11
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 10
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جاوا'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 9
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل التاج'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 8
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل الزهور'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 7
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 6
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 21
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دوار الجمرك'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 20
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ضاحية الياسمين'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 5
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الشوبك'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 5
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 6
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القطرانة'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 8
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 9
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 10
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي موسى'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 7
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المهاجرين'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 5
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 9
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دابوق'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 11
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماحص'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الصويفيه'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 6
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة الرياضية'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 7
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة المنورة'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 8
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل الحسين'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خلدا'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 10
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 12
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 13
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مكة مول'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 14
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ناعور'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 6
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الخالدية'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 5
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 9
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الضليل'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المخيم'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 4
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 8
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمية'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 7
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'بلعما'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)
SELECT sr.id, a.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;

-- 5) ربط المندوب بالمنطقة (احتياطي)
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
WHERE sr.name_ar = 'إبراهيم'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
WHERE sr.name_ar = 'جاد الحاج'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
WHERE sr.name_ar = 'ركان'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
WHERE sr.name_ar = 'سامر ابو جاموس'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
WHERE sr.name_ar = 'سامر ابو جاموس'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 3
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
WHERE sr.name_ar = 'سامي عريقات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
WHERE sr.name_ar = 'شريف'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
WHERE sr.name_ar = 'علي حياصات'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 2
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 1
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
WHERE sr.name_ar = 'محمد حسن'
LIMIT 1;
INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT sr.id, r.id, 0
FROM crm_sales_rep sr
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
WHERE sr.name_ar = 'وجدي'
LIMIT 1;

SET SQL_SAFE_UPDATES = 1;
-- تحقق:
SELECT COUNT(*) AS regions FROM crm_region;
SELECT COUNT(*) AS addresses FROM crm_region_address;
SELECT COUNT(*) AS rep_addr FROM crm_sales_rep_region_address;
