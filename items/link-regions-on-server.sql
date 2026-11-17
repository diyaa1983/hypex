-- نفّذ على السيرفر فقط (176.29.176.192) عبر Workbench.
-- مصدر: استيراد المناطق.xlsx محلياً ثم تصدير هذا الملف (مناطق + عناوين + مندوبون + عملاء).
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

-- 6) ربط العميل بالمنطقة والعنوان والمندوب (حسب رمز العميل من Excel)
-- عدد عملاء الملف الفريدين: 597
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرابيه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرابيه')
WHERE c.code = '11200612'
   OR c.oracle_key = '11200612'
   OR c.oracle_key LIKE '%/11200612'
   OR c.oracle_key LIKE '%11200612';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200612'
   OR c.oracle_key = '11200612'
   OR c.oracle_key LIKE '%/11200612'
   OR c.oracle_key LIKE '%11200612';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرابيه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرابيه')
WHERE c.code = '11200685'
   OR c.oracle_key = '11200685'
   OR c.oracle_key LIKE '%/11200685'
   OR c.oracle_key LIKE '%11200685';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200685'
   OR c.oracle_key = '11200685'
   OR c.oracle_key LIKE '%/11200685'
   OR c.oracle_key LIKE '%11200685';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الشميساني'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الشميساني')
WHERE c.code = '11200397'
   OR c.oracle_key = '11200397'
   OR c.oracle_key LIKE '%/11200397'
   OR c.oracle_key LIKE '%11200397';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200397'
   OR c.oracle_key = '11200397'
   OR c.oracle_key LIKE '%/11200397'
   OR c.oracle_key LIKE '%11200397';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العبدلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العبدلي')
WHERE c.code = '11200813'
   OR c.oracle_key = '11200813'
   OR c.oracle_key LIKE '%/11200813'
   OR c.oracle_key LIKE '%11200813';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200813'
   OR c.oracle_key = '11200813'
   OR c.oracle_key LIKE '%/11200813'
   OR c.oracle_key LIKE '%11200813';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العبدلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العبدلي')
WHERE c.code = '11200611'
   OR c.oracle_key = '11200611'
   OR c.oracle_key LIKE '%/11200611'
   OR c.oracle_key LIKE '%11200611';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200611'
   OR c.oracle_key = '11200611'
   OR c.oracle_key LIKE '%/11200611'
   OR c.oracle_key LIKE '%11200611';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'شفا بدران'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'شفا بدران')
WHERE c.code = '11200484'
   OR c.oracle_key = '11200484'
   OR c.oracle_key LIKE '%/11200484'
   OR c.oracle_key LIKE '%11200484';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200484'
   OR c.oracle_key = '11200484'
   OR c.oracle_key LIKE '%/11200484'
   OR c.oracle_key LIKE '%11200484';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'شفا بدران'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'شفا بدران')
WHERE c.code = '11200640'
   OR c.oracle_key = '11200640'
   OR c.oracle_key LIKE '%/11200640'
   OR c.oracle_key LIKE '%11200640';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200640'
   OR c.oracle_key = '11200640'
   OR c.oracle_key LIKE '%/11200640'
   OR c.oracle_key LIKE '%11200640';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'شفا بدران'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'شفا بدران')
WHERE c.code = '11200543'
   OR c.oracle_key = '11200543'
   OR c.oracle_key LIKE '%/11200543'
   OR c.oracle_key LIKE '%11200543';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200543'
   OR c.oracle_key = '11200543'
   OR c.oracle_key LIKE '%/11200543'
   OR c.oracle_key LIKE '%11200543';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'شفا بدران'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'شفا بدران')
WHERE c.code = '11200209'
   OR c.oracle_key = '11200209'
   OR c.oracle_key LIKE '%/11200209'
   OR c.oracle_key LIKE '%11200209';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200209'
   OR c.oracle_key = '11200209'
   OR c.oracle_key LIKE '%/11200209'
   OR c.oracle_key LIKE '%11200209';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'شفا بدران'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'شفا بدران')
WHERE c.code = '11200103'
   OR c.oracle_key = '11200103'
   OR c.oracle_key LIKE '%/11200103'
   OR c.oracle_key LIKE '%11200103';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200103'
   OR c.oracle_key = '11200103'
   OR c.oracle_key LIKE '%/11200103'
   OR c.oracle_key LIKE '%11200103';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'شفا بدران'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'شفا بدران')
WHERE c.code = '11200831'
   OR c.oracle_key = '11200831'
   OR c.oracle_key LIKE '%/11200831'
   OR c.oracle_key LIKE '%11200831';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200831'
   OR c.oracle_key = '11200831'
   OR c.oracle_key LIKE '%/11200831'
   OR c.oracle_key LIKE '%11200831';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'صويلح'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'صويلح')
WHERE c.code = '11200560'
   OR c.oracle_key = '11200560'
   OR c.oracle_key LIKE '%/11200560'
   OR c.oracle_key LIKE '%11200560';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200560'
   OR c.oracle_key = '11200560'
   OR c.oracle_key LIKE '%/11200560'
   OR c.oracle_key LIKE '%11200560';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200637'
   OR c.oracle_key = '11200637'
   OR c.oracle_key LIKE '%/11200637'
   OR c.oracle_key LIKE '%11200637';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200637'
   OR c.oracle_key = '11200637'
   OR c.oracle_key LIKE '%/11200637'
   OR c.oracle_key LIKE '%11200637';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو نصير')
WHERE c.code = '11200420'
   OR c.oracle_key = '11200420'
   OR c.oracle_key LIKE '%/11200420'
   OR c.oracle_key LIKE '%11200420';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200420'
   OR c.oracle_key = '11200420'
   OR c.oracle_key LIKE '%/11200420'
   OR c.oracle_key LIKE '%11200420';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو نصير')
WHERE c.code = '11200781'
   OR c.oracle_key = '11200781'
   OR c.oracle_key LIKE '%/11200781'
   OR c.oracle_key LIKE '%11200781';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200781'
   OR c.oracle_key = '11200781'
   OR c.oracle_key LIKE '%/11200781'
   OR c.oracle_key LIKE '%11200781';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو نصير')
WHERE c.code = '11200605'
   OR c.oracle_key = '11200605'
   OR c.oracle_key LIKE '%/11200605'
   OR c.oracle_key LIKE '%11200605';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200605'
   OR c.oracle_key = '11200605'
   OR c.oracle_key LIKE '%/11200605'
   OR c.oracle_key LIKE '%11200605';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو نصير')
WHERE c.code = '11200721'
   OR c.oracle_key = '11200721'
   OR c.oracle_key LIKE '%/11200721'
   OR c.oracle_key LIKE '%11200721';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200721'
   OR c.oracle_key = '11200721'
   OR c.oracle_key LIKE '%/11200721'
   OR c.oracle_key LIKE '%11200721';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو نصير')
WHERE c.code = '11200811'
   OR c.oracle_key = '11200811'
   OR c.oracle_key LIKE '%/11200811'
   OR c.oracle_key LIKE '%11200811';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200811'
   OR c.oracle_key = '11200811'
   OR c.oracle_key LIKE '%/11200811'
   OR c.oracle_key LIKE '%11200811';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200644'
   OR c.oracle_key = '11200644'
   OR c.oracle_key LIKE '%/11200644'
   OR c.oracle_key LIKE '%11200644';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200644'
   OR c.oracle_key = '11200644'
   OR c.oracle_key LIKE '%/11200644'
   OR c.oracle_key LIKE '%11200644';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200466'
   OR c.oracle_key = '11200466'
   OR c.oracle_key LIKE '%/11200466'
   OR c.oracle_key LIKE '%11200466';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200466'
   OR c.oracle_key = '11200466'
   OR c.oracle_key LIKE '%/11200466'
   OR c.oracle_key LIKE '%11200466';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200626'
   OR c.oracle_key = '11200626'
   OR c.oracle_key LIKE '%/11200626'
   OR c.oracle_key LIKE '%11200626';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200626'
   OR c.oracle_key = '11200626'
   OR c.oracle_key LIKE '%/11200626'
   OR c.oracle_key LIKE '%11200626';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200099'
   OR c.oracle_key = '11200099'
   OR c.oracle_key LIKE '%/11200099'
   OR c.oracle_key LIKE '%11200099';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200099'
   OR c.oracle_key = '11200099'
   OR c.oracle_key LIKE '%/11200099'
   OR c.oracle_key LIKE '%11200099';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200807'
   OR c.oracle_key = '11200807'
   OR c.oracle_key LIKE '%/11200807'
   OR c.oracle_key LIKE '%11200807';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200807'
   OR c.oracle_key = '11200807'
   OR c.oracle_key LIKE '%/11200807'
   OR c.oracle_key LIKE '%11200807';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200509'
   OR c.oracle_key = '11200509'
   OR c.oracle_key LIKE '%/11200509'
   OR c.oracle_key LIKE '%11200509';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200509'
   OR c.oracle_key = '11200509'
   OR c.oracle_key LIKE '%/11200509'
   OR c.oracle_key LIKE '%11200509';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200775'
   OR c.oracle_key = '11200775'
   OR c.oracle_key LIKE '%/11200775'
   OR c.oracle_key LIKE '%11200775';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200775'
   OR c.oracle_key = '11200775'
   OR c.oracle_key LIKE '%/11200775'
   OR c.oracle_key LIKE '%11200775';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200098'
   OR c.oracle_key = '11200098'
   OR c.oracle_key LIKE '%/11200098'
   OR c.oracle_key LIKE '%11200098';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200098'
   OR c.oracle_key = '11200098'
   OR c.oracle_key LIKE '%/11200098'
   OR c.oracle_key LIKE '%11200098';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200836'
   OR c.oracle_key = '11200836'
   OR c.oracle_key LIKE '%/11200836'
   OR c.oracle_key LIKE '%11200836';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200836'
   OR c.oracle_key = '11200836'
   OR c.oracle_key LIKE '%/11200836'
   OR c.oracle_key LIKE '%11200836';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200806'
   OR c.oracle_key = '11200806'
   OR c.oracle_key LIKE '%/11200806'
   OR c.oracle_key LIKE '%11200806';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200806'
   OR c.oracle_key = '11200806'
   OR c.oracle_key LIKE '%/11200806'
   OR c.oracle_key LIKE '%11200806';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200085'
   OR c.oracle_key = '11200085'
   OR c.oracle_key LIKE '%/11200085'
   OR c.oracle_key LIKE '%11200085';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200085'
   OR c.oracle_key = '11200085'
   OR c.oracle_key LIKE '%/11200085'
   OR c.oracle_key LIKE '%11200085';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجاردنز'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجاردنز')
WHERE c.code = '11200683'
   OR c.oracle_key = '11200683'
   OR c.oracle_key LIKE '%/11200683'
   OR c.oracle_key LIKE '%11200683';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200683'
   OR c.oracle_key = '11200683'
   OR c.oracle_key LIKE '%/11200683'
   OR c.oracle_key LIKE '%11200683';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجبيهة')
WHERE c.code = '11200517'
   OR c.oracle_key = '11200517'
   OR c.oracle_key LIKE '%/11200517'
   OR c.oracle_key LIKE '%11200517';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200517'
   OR c.oracle_key = '11200517'
   OR c.oracle_key LIKE '%/11200517'
   OR c.oracle_key LIKE '%11200517';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجبيهة')
WHERE c.code = '11200238'
   OR c.oracle_key = '11200238'
   OR c.oracle_key LIKE '%/11200238'
   OR c.oracle_key LIKE '%11200238';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200238'
   OR c.oracle_key = '11200238'
   OR c.oracle_key LIKE '%/11200238'
   OR c.oracle_key LIKE '%11200238';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجبيهة')
WHERE c.code = '11200635'
   OR c.oracle_key = '11200635'
   OR c.oracle_key LIKE '%/11200635'
   OR c.oracle_key LIKE '%11200635';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200635'
   OR c.oracle_key = '11200635'
   OR c.oracle_key LIKE '%/11200635'
   OR c.oracle_key LIKE '%11200635';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجبيهة')
WHERE c.code = '11200483'
   OR c.oracle_key = '11200483'
   OR c.oracle_key LIKE '%/11200483'
   OR c.oracle_key LIKE '%11200483';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200483'
   OR c.oracle_key = '11200483'
   OR c.oracle_key LIKE '%/11200483'
   OR c.oracle_key LIKE '%11200483';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجبيهة')
WHERE c.code = '11200561'
   OR c.oracle_key = '11200561'
   OR c.oracle_key LIKE '%/11200561'
   OR c.oracle_key LIKE '%11200561';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200561'
   OR c.oracle_key = '11200561'
   OR c.oracle_key LIKE '%/11200561'
   OR c.oracle_key LIKE '%11200561';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجبيهة')
WHERE c.code = '11200860'
   OR c.oracle_key = '11200860'
   OR c.oracle_key LIKE '%/11200860'
   OR c.oracle_key LIKE '%11200860';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200860'
   OR c.oracle_key = '11200860'
   OR c.oracle_key LIKE '%/11200860'
   OR c.oracle_key LIKE '%11200860';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجبيهة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجبيهة')
WHERE c.code = '11200864'
   OR c.oracle_key = '11200864'
   OR c.oracle_key LIKE '%/11200864'
   OR c.oracle_key LIKE '%11200864';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200864'
   OR c.oracle_key = '11200864'
   OR c.oracle_key LIKE '%/11200864'
   OR c.oracle_key LIKE '%11200864';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العبدلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العبدلي')
WHERE c.code = '11200178'
   OR c.oracle_key = '11200178'
   OR c.oracle_key LIKE '%/11200178'
   OR c.oracle_key LIKE '%11200178';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200178'
   OR c.oracle_key = '11200178'
   OR c.oracle_key LIKE '%/11200178'
   OR c.oracle_key LIKE '%11200178';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة الرياضية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المدينة الرياضية')
WHERE c.code = '11200632'
   OR c.oracle_key = '11200632'
   OR c.oracle_key LIKE '%/11200632'
   OR c.oracle_key LIKE '%11200632';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200632'
   OR c.oracle_key = '11200632'
   OR c.oracle_key LIKE '%/11200632'
   OR c.oracle_key LIKE '%11200632';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة الرياضية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المدينة الرياضية')
WHERE c.code = '11200633'
   OR c.oracle_key = '11200633'
   OR c.oracle_key LIKE '%/11200633'
   OR c.oracle_key LIKE '%11200633';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200633'
   OR c.oracle_key = '11200633'
   OR c.oracle_key LIKE '%/11200633'
   OR c.oracle_key LIKE '%11200633';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة المنورة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المدينة المنورة')
WHERE c.code = '11200814'
   OR c.oracle_key = '11200814'
   OR c.oracle_key LIKE '%/11200814'
   OR c.oracle_key LIKE '%11200814';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200814'
   OR c.oracle_key = '11200814'
   OR c.oracle_key LIKE '%/11200814'
   OR c.oracle_key LIKE '%11200814';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة المنورة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المدينة المنورة')
WHERE c.code = '11200827'
   OR c.oracle_key = '11200827'
   OR c.oracle_key LIKE '%/11200827'
   OR c.oracle_key LIKE '%11200827';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200827'
   OR c.oracle_key = '11200827'
   OR c.oracle_key LIKE '%/11200827'
   OR c.oracle_key LIKE '%11200827';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النزهه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النزهه')
WHERE c.code = '11200288'
   OR c.oracle_key = '11200288'
   OR c.oracle_key LIKE '%/11200288'
   OR c.oracle_key LIKE '%11200288';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200288'
   OR c.oracle_key = '11200288'
   OR c.oracle_key LIKE '%/11200288'
   OR c.oracle_key LIKE '%11200288';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النزهه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النزهه')
WHERE c.code = '11200121'
   OR c.oracle_key = '11200121'
   OR c.oracle_key LIKE '%/11200121'
   OR c.oracle_key LIKE '%11200121';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200121'
   OR c.oracle_key = '11200121'
   OR c.oracle_key LIKE '%/11200121'
   OR c.oracle_key LIKE '%11200121';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النزهه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النزهه')
WHERE c.code = '11200606'
   OR c.oracle_key = '11200606'
   OR c.oracle_key LIKE '%/11200606'
   OR c.oracle_key LIKE '%11200606';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200606'
   OR c.oracle_key = '11200606'
   OR c.oracle_key LIKE '%/11200606'
   OR c.oracle_key LIKE '%11200606';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النزهه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النزهه')
WHERE c.code = '11200634'
   OR c.oracle_key = '11200634'
   OR c.oracle_key LIKE '%/11200634'
   OR c.oracle_key LIKE '%11200634';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200634'
   OR c.oracle_key = '11200634'
   OR c.oracle_key LIKE '%/11200634'
   OR c.oracle_key LIKE '%11200634';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجاردنز'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجاردنز')
WHERE c.code = '11200092'
   OR c.oracle_key = '11200092'
   OR c.oracle_key LIKE '%/11200092'
   OR c.oracle_key LIKE '%11200092';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200092'
   OR c.oracle_key = '11200092'
   OR c.oracle_key LIKE '%/11200092'
   OR c.oracle_key LIKE '%11200092';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرابيه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرابيه')
WHERE c.code = '11200871'
   OR c.oracle_key = '11200871'
   OR c.oracle_key LIKE '%/11200871'
   OR c.oracle_key LIKE '%11200871';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200871'
   OR c.oracle_key = '11200871'
   OR c.oracle_key LIKE '%/11200871'
   OR c.oracle_key LIKE '%11200871';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'صويلح'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'صويلح')
WHERE c.code = '11200763'
   OR c.oracle_key = '11200763'
   OR c.oracle_key LIKE '%/11200763'
   OR c.oracle_key LIKE '%11200763';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200763'
   OR c.oracle_key = '11200763'
   OR c.oracle_key LIKE '%/11200763'
   OR c.oracle_key LIKE '%11200763';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'صويلح'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'صويلح')
WHERE c.code = '11200338'
   OR c.oracle_key = '11200338'
   OR c.oracle_key LIKE '%/11200338'
   OR c.oracle_key LIKE '%11200338';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200338'
   OR c.oracle_key = '11200338'
   OR c.oracle_key LIKE '%/11200338'
   OR c.oracle_key LIKE '%11200338';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'صويلح'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'صويلح')
WHERE c.code = '11200842'
   OR c.oracle_key = '11200842'
   OR c.oracle_key LIKE '%/11200842'
   OR c.oracle_key LIKE '%11200842';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200842'
   OR c.oracle_key = '11200842'
   OR c.oracle_key LIKE '%/11200842'
   OR c.oracle_key LIKE '%11200842';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'صويلح'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'صويلح')
WHERE c.code = '11200408'
   OR c.oracle_key = '11200408'
   OR c.oracle_key LIKE '%/11200408'
   OR c.oracle_key LIKE '%11200408';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200408'
   OR c.oracle_key = '11200408'
   OR c.oracle_key LIKE '%/11200408'
   OR c.oracle_key LIKE '%11200408';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ضاحية الرشيد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ضاحية الرشيد')
WHERE c.code = '11200419'
   OR c.oracle_key = '11200419'
   OR c.oracle_key LIKE '%/11200419'
   OR c.oracle_key LIKE '%11200419';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200419'
   OR c.oracle_key = '11200419'
   OR c.oracle_key LIKE '%/11200419'
   OR c.oracle_key LIKE '%11200419';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200487'
   OR c.oracle_key = '11200487'
   OR c.oracle_key LIKE '%/11200487'
   OR c.oracle_key LIKE '%11200487';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200487'
   OR c.oracle_key = '11200487'
   OR c.oracle_key LIKE '%/11200487'
   OR c.oracle_key LIKE '%11200487';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200722'
   OR c.oracle_key = '11200722'
   OR c.oracle_key LIKE '%/11200722'
   OR c.oracle_key LIKE '%11200722';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200722'
   OR c.oracle_key = '11200722'
   OR c.oracle_key LIKE '%/11200722'
   OR c.oracle_key LIKE '%11200722';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200649'
   OR c.oracle_key = '11200649'
   OR c.oracle_key LIKE '%/11200649'
   OR c.oracle_key LIKE '%11200649';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200649'
   OR c.oracle_key = '11200649'
   OR c.oracle_key LIKE '%/11200649'
   OR c.oracle_key LIKE '%11200649';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200533'
   OR c.oracle_key = '11200533'
   OR c.oracle_key LIKE '%/11200533'
   OR c.oracle_key LIKE '%11200533';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200533'
   OR c.oracle_key = '11200533'
   OR c.oracle_key LIKE '%/11200533'
   OR c.oracle_key LIKE '%11200533';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عوجان')
WHERE c.code = '11200286'
   OR c.oracle_key = '11200286'
   OR c.oracle_key LIKE '%/11200286'
   OR c.oracle_key LIKE '%11200286';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200286'
   OR c.oracle_key = '11200286'
   OR c.oracle_key LIKE '%/11200286'
   OR c.oracle_key LIKE '%11200286';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عوجان')
WHERE c.code = '11200279'
   OR c.oracle_key = '11200279'
   OR c.oracle_key LIKE '%/11200279'
   OR c.oracle_key LIKE '%11200279';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200279'
   OR c.oracle_key = '11200279'
   OR c.oracle_key LIKE '%/11200279'
   OR c.oracle_key LIKE '%11200279';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عوجان')
WHERE c.code = '11200224'
   OR c.oracle_key = '11200224'
   OR c.oracle_key LIKE '%/11200224'
   OR c.oracle_key LIKE '%11200224';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200224'
   OR c.oracle_key = '11200224'
   OR c.oracle_key LIKE '%/11200224'
   OR c.oracle_key LIKE '%11200224';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عوجان')
WHERE c.code = '11200151'
   OR c.oracle_key = '11200151'
   OR c.oracle_key LIKE '%/11200151'
   OR c.oracle_key LIKE '%11200151';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200151'
   OR c.oracle_key = '11200151'
   OR c.oracle_key LIKE '%/11200151'
   OR c.oracle_key LIKE '%11200151';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عوجان')
WHERE c.code = '11200335'
   OR c.oracle_key = '11200335'
   OR c.oracle_key LIKE '%/11200335'
   OR c.oracle_key LIKE '%11200335';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200335'
   OR c.oracle_key = '11200335'
   OR c.oracle_key LIKE '%/11200335'
   OR c.oracle_key LIKE '%11200335';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200534'
   OR c.oracle_key = '11200534'
   OR c.oracle_key LIKE '%/11200534'
   OR c.oracle_key LIKE '%11200534';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200534'
   OR c.oracle_key = '11200534'
   OR c.oracle_key LIKE '%/11200534'
   OR c.oracle_key LIKE '%11200534';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200380'
   OR c.oracle_key = '11200380'
   OR c.oracle_key LIKE '%/11200380'
   OR c.oracle_key LIKE '%11200380';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200380'
   OR c.oracle_key = '11200380'
   OR c.oracle_key LIKE '%/11200380'
   OR c.oracle_key LIKE '%11200380';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200614'
   OR c.oracle_key = '11200614'
   OR c.oracle_key LIKE '%/11200614'
   OR c.oracle_key LIKE '%11200614';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200614'
   OR c.oracle_key = '11200614'
   OR c.oracle_key LIKE '%/11200614'
   OR c.oracle_key LIKE '%11200614';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200523'
   OR c.oracle_key = '11200523'
   OR c.oracle_key LIKE '%/11200523'
   OR c.oracle_key LIKE '%11200523';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200523'
   OR c.oracle_key = '11200523'
   OR c.oracle_key LIKE '%/11200523'
   OR c.oracle_key LIKE '%11200523';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200446'
   OR c.oracle_key = '11200446'
   OR c.oracle_key LIKE '%/11200446'
   OR c.oracle_key LIKE '%11200446';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200446'
   OR c.oracle_key = '11200446'
   OR c.oracle_key LIKE '%/11200446'
   OR c.oracle_key LIKE '%11200446';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200576'
   OR c.oracle_key = '11200576'
   OR c.oracle_key LIKE '%/11200576'
   OR c.oracle_key LIKE '%11200576';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200576'
   OR c.oracle_key = '11200576'
   OR c.oracle_key LIKE '%/11200576'
   OR c.oracle_key LIKE '%11200576';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200573'
   OR c.oracle_key = '11200573'
   OR c.oracle_key LIKE '%/11200573'
   OR c.oracle_key LIKE '%11200573';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200573'
   OR c.oracle_key = '11200573'
   OR c.oracle_key LIKE '%/11200573'
   OR c.oracle_key LIKE '%11200573';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200352'
   OR c.oracle_key = '11200352'
   OR c.oracle_key LIKE '%/11200352'
   OR c.oracle_key LIKE '%11200352';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200352'
   OR c.oracle_key = '11200352'
   OR c.oracle_key LIKE '%/11200352'
   OR c.oracle_key LIKE '%11200352';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200088'
   OR c.oracle_key = '11200088'
   OR c.oracle_key LIKE '%/11200088'
   OR c.oracle_key LIKE '%11200088';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200088'
   OR c.oracle_key = '11200088'
   OR c.oracle_key LIKE '%/11200088'
   OR c.oracle_key LIKE '%11200088';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200460'
   OR c.oracle_key = '11200460'
   OR c.oracle_key LIKE '%/11200460'
   OR c.oracle_key LIKE '%11200460';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200460'
   OR c.oracle_key = '11200460'
   OR c.oracle_key LIKE '%/11200460'
   OR c.oracle_key LIKE '%11200460';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200425'
   OR c.oracle_key = '11200425'
   OR c.oracle_key LIKE '%/11200425'
   OR c.oracle_key LIKE '%11200425';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200425'
   OR c.oracle_key = '11200425'
   OR c.oracle_key LIKE '%/11200425'
   OR c.oracle_key LIKE '%11200425';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200478'
   OR c.oracle_key = '11200478'
   OR c.oracle_key LIKE '%/11200478'
   OR c.oracle_key LIKE '%11200478';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200478'
   OR c.oracle_key = '11200478'
   OR c.oracle_key LIKE '%/11200478'
   OR c.oracle_key LIKE '%11200478';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200354'
   OR c.oracle_key = '11200354'
   OR c.oracle_key LIKE '%/11200354'
   OR c.oracle_key LIKE '%11200354';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200354'
   OR c.oracle_key = '11200354'
   OR c.oracle_key LIKE '%/11200354'
   OR c.oracle_key LIKE '%11200354';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200642'
   OR c.oracle_key = '11200642'
   OR c.oracle_key LIKE '%/11200642'
   OR c.oracle_key LIKE '%11200642';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200642'
   OR c.oracle_key = '11200642'
   OR c.oracle_key LIKE '%/11200642'
   OR c.oracle_key LIKE '%11200642';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200616'
   OR c.oracle_key = '11200616'
   OR c.oracle_key LIKE '%/11200616'
   OR c.oracle_key LIKE '%11200616';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200616'
   OR c.oracle_key = '11200616'
   OR c.oracle_key LIKE '%/11200616'
   OR c.oracle_key LIKE '%11200616';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200146'
   OR c.oracle_key = '11200146'
   OR c.oracle_key LIKE '%/11200146'
   OR c.oracle_key LIKE '%11200146';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200146'
   OR c.oracle_key = '11200146'
   OR c.oracle_key LIKE '%/11200146'
   OR c.oracle_key LIKE '%11200146';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200600'
   OR c.oracle_key = '11200600'
   OR c.oracle_key LIKE '%/11200600'
   OR c.oracle_key LIKE '%11200600';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200600'
   OR c.oracle_key = '11200600'
   OR c.oracle_key LIKE '%/11200600'
   OR c.oracle_key LIKE '%11200600';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200170'
   OR c.oracle_key = '11200170'
   OR c.oracle_key LIKE '%/11200170'
   OR c.oracle_key LIKE '%11200170';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200170'
   OR c.oracle_key = '11200170'
   OR c.oracle_key LIKE '%/11200170'
   OR c.oracle_key LIKE '%11200170';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200772'
   OR c.oracle_key = '11200772'
   OR c.oracle_key LIKE '%/11200772'
   OR c.oracle_key LIKE '%11200772';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200772'
   OR c.oracle_key = '11200772'
   OR c.oracle_key LIKE '%/11200772'
   OR c.oracle_key LIKE '%11200772';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200120'
   OR c.oracle_key = '11200120'
   OR c.oracle_key LIKE '%/11200120'
   OR c.oracle_key LIKE '%11200120';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200120'
   OR c.oracle_key = '11200120'
   OR c.oracle_key LIKE '%/11200120'
   OR c.oracle_key LIKE '%11200120';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200377'
   OR c.oracle_key = '11200377'
   OR c.oracle_key LIKE '%/11200377'
   OR c.oracle_key LIKE '%11200377';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200377'
   OR c.oracle_key = '11200377'
   OR c.oracle_key LIKE '%/11200377'
   OR c.oracle_key LIKE '%11200377';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200283'
   OR c.oracle_key = '11200283'
   OR c.oracle_key LIKE '%/11200283'
   OR c.oracle_key LIKE '%11200283';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200283'
   OR c.oracle_key = '11200283'
   OR c.oracle_key LIKE '%/11200283'
   OR c.oracle_key LIKE '%11200283';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200625'
   OR c.oracle_key = '11200625'
   OR c.oracle_key LIKE '%/11200625'
   OR c.oracle_key LIKE '%11200625';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200625'
   OR c.oracle_key = '11200625'
   OR c.oracle_key LIKE '%/11200625'
   OR c.oracle_key LIKE '%11200625';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200570'
   OR c.oracle_key = '11200570'
   OR c.oracle_key LIKE '%/11200570'
   OR c.oracle_key LIKE '%11200570';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'جاد الحاج'
WHERE c.code = '11200570'
   OR c.oracle_key = '11200570'
   OR c.oracle_key LIKE '%/11200570'
   OR c.oracle_key LIKE '%11200570';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200769'
   OR c.oracle_key = '11200769'
   OR c.oracle_key LIKE '%/11200769'
   OR c.oracle_key LIKE '%11200769';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200769'
   OR c.oracle_key = '11200769'
   OR c.oracle_key LIKE '%/11200769'
   OR c.oracle_key LIKE '%11200769';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200516'
   OR c.oracle_key = '11200516'
   OR c.oracle_key LIKE '%/11200516'
   OR c.oracle_key LIKE '%11200516';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200516'
   OR c.oracle_key = '11200516'
   OR c.oracle_key LIKE '%/11200516'
   OR c.oracle_key LIKE '%11200516';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200477'
   OR c.oracle_key = '11200477'
   OR c.oracle_key LIKE '%/11200477'
   OR c.oracle_key LIKE '%11200477';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200477'
   OR c.oracle_key = '11200477'
   OR c.oracle_key LIKE '%/11200477'
   OR c.oracle_key LIKE '%11200477';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200636'
   OR c.oracle_key = '11200636'
   OR c.oracle_key LIKE '%/11200636'
   OR c.oracle_key LIKE '%11200636';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200636'
   OR c.oracle_key = '11200636'
   OR c.oracle_key LIKE '%/11200636'
   OR c.oracle_key LIKE '%11200636';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200481'
   OR c.oracle_key = '11200481'
   OR c.oracle_key LIKE '%/11200481'
   OR c.oracle_key LIKE '%11200481';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200481'
   OR c.oracle_key = '11200481'
   OR c.oracle_key LIKE '%/11200481'
   OR c.oracle_key LIKE '%11200481';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200295'
   OR c.oracle_key = '11200295'
   OR c.oracle_key LIKE '%/11200295'
   OR c.oracle_key LIKE '%11200295';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200295'
   OR c.oracle_key = '11200295'
   OR c.oracle_key LIKE '%/11200295'
   OR c.oracle_key LIKE '%11200295';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200549'
   OR c.oracle_key = '11200549'
   OR c.oracle_key LIKE '%/11200549'
   OR c.oracle_key LIKE '%11200549';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200549'
   OR c.oracle_key = '11200549'
   OR c.oracle_key LIKE '%/11200549'
   OR c.oracle_key LIKE '%11200549';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200551'
   OR c.oracle_key = '11200551'
   OR c.oracle_key LIKE '%/11200551'
   OR c.oracle_key LIKE '%11200551';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200551'
   OR c.oracle_key = '11200551'
   OR c.oracle_key LIKE '%/11200551'
   OR c.oracle_key LIKE '%11200551';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200736'
   OR c.oracle_key = '11200736'
   OR c.oracle_key LIKE '%/11200736'
   OR c.oracle_key LIKE '%11200736';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200736'
   OR c.oracle_key = '11200736'
   OR c.oracle_key LIKE '%/11200736'
   OR c.oracle_key LIKE '%11200736';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200550'
   OR c.oracle_key = '11200550'
   OR c.oracle_key LIKE '%/11200550'
   OR c.oracle_key LIKE '%11200550';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200550'
   OR c.oracle_key = '11200550'
   OR c.oracle_key LIKE '%/11200550'
   OR c.oracle_key LIKE '%11200550';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200384'
   OR c.oracle_key = '11200384'
   OR c.oracle_key LIKE '%/11200384'
   OR c.oracle_key LIKE '%11200384';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200384'
   OR c.oracle_key = '11200384'
   OR c.oracle_key LIKE '%/11200384'
   OR c.oracle_key LIKE '%11200384';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200393'
   OR c.oracle_key = '11200393'
   OR c.oracle_key LIKE '%/11200393'
   OR c.oracle_key LIKE '%11200393';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200393'
   OR c.oracle_key = '11200393'
   OR c.oracle_key LIKE '%/11200393'
   OR c.oracle_key LIKE '%11200393';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200603'
   OR c.oracle_key = '11200603'
   OR c.oracle_key LIKE '%/11200603'
   OR c.oracle_key LIKE '%11200603';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200603'
   OR c.oracle_key = '11200603'
   OR c.oracle_key LIKE '%/11200603'
   OR c.oracle_key LIKE '%11200603';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200687'
   OR c.oracle_key = '11200687'
   OR c.oracle_key LIKE '%/11200687'
   OR c.oracle_key LIKE '%11200687';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200687'
   OR c.oracle_key = '11200687'
   OR c.oracle_key LIKE '%/11200687'
   OR c.oracle_key LIKE '%11200687';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200489'
   OR c.oracle_key = '11200489'
   OR c.oracle_key LIKE '%/11200489'
   OR c.oracle_key LIKE '%11200489';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200489'
   OR c.oracle_key = '11200489'
   OR c.oracle_key LIKE '%/11200489'
   OR c.oracle_key LIKE '%11200489';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200462'
   OR c.oracle_key = '11200462'
   OR c.oracle_key LIKE '%/11200462'
   OR c.oracle_key LIKE '%11200462';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200462'
   OR c.oracle_key = '11200462'
   OR c.oracle_key LIKE '%/11200462'
   OR c.oracle_key LIKE '%11200462';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200521'
   OR c.oracle_key = '11200521'
   OR c.oracle_key LIKE '%/11200521'
   OR c.oracle_key LIKE '%11200521';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200521'
   OR c.oracle_key = '11200521'
   OR c.oracle_key LIKE '%/11200521'
   OR c.oracle_key LIKE '%11200521';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200583'
   OR c.oracle_key = '11200583'
   OR c.oracle_key LIKE '%/11200583'
   OR c.oracle_key LIKE '%11200583';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200583'
   OR c.oracle_key = '11200583'
   OR c.oracle_key LIKE '%/11200583'
   OR c.oracle_key LIKE '%11200583';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200337'
   OR c.oracle_key = '11200337'
   OR c.oracle_key LIKE '%/11200337'
   OR c.oracle_key LIKE '%11200337';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200337'
   OR c.oracle_key = '11200337'
   OR c.oracle_key LIKE '%/11200337'
   OR c.oracle_key LIKE '%11200337';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرمثا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرمثا')
WHERE c.code = '11200671'
   OR c.oracle_key = '11200671'
   OR c.oracle_key LIKE '%/11200671'
   OR c.oracle_key LIKE '%11200671';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200671'
   OR c.oracle_key = '11200671'
   OR c.oracle_key LIKE '%/11200671'
   OR c.oracle_key LIKE '%11200671';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200476'
   OR c.oracle_key = '11200476'
   OR c.oracle_key LIKE '%/11200476'
   OR c.oracle_key LIKE '%11200476';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200476'
   OR c.oracle_key = '11200476'
   OR c.oracle_key LIKE '%/11200476'
   OR c.oracle_key LIKE '%11200476';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200548'
   OR c.oracle_key = '11200548'
   OR c.oracle_key LIKE '%/11200548'
   OR c.oracle_key LIKE '%11200548';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200548'
   OR c.oracle_key = '11200548'
   OR c.oracle_key LIKE '%/11200548'
   OR c.oracle_key LIKE '%11200548';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200575'
   OR c.oracle_key = '11200575'
   OR c.oracle_key LIKE '%/11200575'
   OR c.oracle_key LIKE '%11200575';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200575'
   OR c.oracle_key = '11200575'
   OR c.oracle_key LIKE '%/11200575'
   OR c.oracle_key LIKE '%11200575';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200592'
   OR c.oracle_key = '11200592'
   OR c.oracle_key LIKE '%/11200592'
   OR c.oracle_key LIKE '%11200592';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200592'
   OR c.oracle_key = '11200592'
   OR c.oracle_key LIKE '%/11200592'
   OR c.oracle_key LIKE '%11200592';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200674'
   OR c.oracle_key = '11200674'
   OR c.oracle_key LIKE '%/11200674'
   OR c.oracle_key LIKE '%11200674';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200674'
   OR c.oracle_key = '11200674'
   OR c.oracle_key LIKE '%/11200674'
   OR c.oracle_key LIKE '%11200674';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200744'
   OR c.oracle_key = '11200744'
   OR c.oracle_key LIKE '%/11200744'
   OR c.oracle_key LIKE '%11200744';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200744'
   OR c.oracle_key = '11200744'
   OR c.oracle_key LIKE '%/11200744'
   OR c.oracle_key LIKE '%11200744';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200740'
   OR c.oracle_key = '11200740'
   OR c.oracle_key LIKE '%/11200740'
   OR c.oracle_key LIKE '%11200740';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200740'
   OR c.oracle_key = '11200740'
   OR c.oracle_key LIKE '%/11200740'
   OR c.oracle_key LIKE '%11200740';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200480'
   OR c.oracle_key = '11200480'
   OR c.oracle_key LIKE '%/11200480'
   OR c.oracle_key LIKE '%11200480';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200480'
   OR c.oracle_key = '11200480'
   OR c.oracle_key LIKE '%/11200480'
   OR c.oracle_key LIKE '%11200480';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200552'
   OR c.oracle_key = '11200552'
   OR c.oracle_key LIKE '%/11200552'
   OR c.oracle_key LIKE '%11200552';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200552'
   OR c.oracle_key = '11200552'
   OR c.oracle_key LIKE '%/11200552'
   OR c.oracle_key LIKE '%11200552';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200315'
   OR c.oracle_key = '11200315'
   OR c.oracle_key LIKE '%/11200315'
   OR c.oracle_key LIKE '%11200315';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200315'
   OR c.oracle_key = '11200315'
   OR c.oracle_key LIKE '%/11200315'
   OR c.oracle_key LIKE '%11200315';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200556'
   OR c.oracle_key = '11200556'
   OR c.oracle_key LIKE '%/11200556'
   OR c.oracle_key LIKE '%11200556';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200556'
   OR c.oracle_key = '11200556'
   OR c.oracle_key LIKE '%/11200556'
   OR c.oracle_key LIKE '%11200556';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200647'
   OR c.oracle_key = '11200647'
   OR c.oracle_key LIKE '%/11200647'
   OR c.oracle_key LIKE '%11200647';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200647'
   OR c.oracle_key = '11200647'
   OR c.oracle_key LIKE '%/11200647'
   OR c.oracle_key LIKE '%11200647';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200747'
   OR c.oracle_key = '11200747'
   OR c.oracle_key LIKE '%/11200747'
   OR c.oracle_key LIKE '%11200747';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200747'
   OR c.oracle_key = '11200747'
   OR c.oracle_key LIKE '%/11200747'
   OR c.oracle_key LIKE '%11200747';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200553'
   OR c.oracle_key = '11200553'
   OR c.oracle_key LIKE '%/11200553'
   OR c.oracle_key LIKE '%11200553';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200553'
   OR c.oracle_key = '11200553'
   OR c.oracle_key LIKE '%/11200553'
   OR c.oracle_key LIKE '%11200553';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200621'
   OR c.oracle_key = '11200621'
   OR c.oracle_key LIKE '%/11200621'
   OR c.oracle_key LIKE '%11200621';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200621'
   OR c.oracle_key = '11200621'
   OR c.oracle_key LIKE '%/11200621'
   OR c.oracle_key LIKE '%11200621';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200554'
   OR c.oracle_key = '11200554'
   OR c.oracle_key LIKE '%/11200554'
   OR c.oracle_key LIKE '%11200554';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200554'
   OR c.oracle_key = '11200554'
   OR c.oracle_key LIKE '%/11200554'
   OR c.oracle_key LIKE '%11200554';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جرش'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جرش')
WHERE c.code = '11200712'
   OR c.oracle_key = '11200712'
   OR c.oracle_key LIKE '%/11200712'
   OR c.oracle_key LIKE '%11200712';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200712'
   OR c.oracle_key = '11200712'
   OR c.oracle_key LIKE '%/11200712'
   OR c.oracle_key LIKE '%11200712';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200201'
   OR c.oracle_key = '11200201'
   OR c.oracle_key LIKE '%/11200201'
   OR c.oracle_key LIKE '%11200201';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200201'
   OR c.oracle_key = '11200201'
   OR c.oracle_key LIKE '%/11200201'
   OR c.oracle_key LIKE '%11200201';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200406'
   OR c.oracle_key = '11200406'
   OR c.oracle_key LIKE '%/11200406'
   OR c.oracle_key LIKE '%11200406';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200406'
   OR c.oracle_key = '11200406'
   OR c.oracle_key LIKE '%/11200406'
   OR c.oracle_key LIKE '%11200406';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200622'
   OR c.oracle_key = '11200622'
   OR c.oracle_key LIKE '%/11200622'
   OR c.oracle_key LIKE '%11200622';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200622'
   OR c.oracle_key = '11200622'
   OR c.oracle_key LIKE '%/11200622'
   OR c.oracle_key LIKE '%11200622';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200297'
   OR c.oracle_key = '11200297'
   OR c.oracle_key LIKE '%/11200297'
   OR c.oracle_key LIKE '%11200297';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200297'
   OR c.oracle_key = '11200297'
   OR c.oracle_key LIKE '%/11200297'
   OR c.oracle_key LIKE '%11200297';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200737'
   OR c.oracle_key = '11200737'
   OR c.oracle_key LIKE '%/11200737'
   OR c.oracle_key LIKE '%11200737';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200737'
   OR c.oracle_key = '11200737'
   OR c.oracle_key LIKE '%/11200737'
   OR c.oracle_key LIKE '%11200737';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200726'
   OR c.oracle_key = '11200726'
   OR c.oracle_key LIKE '%/11200726'
   OR c.oracle_key LIKE '%11200726';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200726'
   OR c.oracle_key = '11200726'
   OR c.oracle_key LIKE '%/11200726'
   OR c.oracle_key LIKE '%11200726';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200296'
   OR c.oracle_key = '11200296'
   OR c.oracle_key LIKE '%/11200296'
   OR c.oracle_key LIKE '%11200296';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200296'
   OR c.oracle_key = '11200296'
   OR c.oracle_key LIKE '%/11200296'
   OR c.oracle_key LIKE '%11200296';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عجلون'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عجلون')
WHERE c.code = '11200746'
   OR c.oracle_key = '11200746'
   OR c.oracle_key LIKE '%/11200746'
   OR c.oracle_key LIKE '%11200746';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200746'
   OR c.oracle_key = '11200746'
   OR c.oracle_key LIKE '%/11200746'
   OR c.oracle_key LIKE '%11200746';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200353'
   OR c.oracle_key = '11200353'
   OR c.oracle_key LIKE '%/11200353'
   OR c.oracle_key LIKE '%11200353';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200353'
   OR c.oracle_key = '11200353'
   OR c.oracle_key LIKE '%/11200353'
   OR c.oracle_key LIKE '%11200353';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200527'
   OR c.oracle_key = '11200527'
   OR c.oracle_key LIKE '%/11200527'
   OR c.oracle_key LIKE '%11200527';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200527'
   OR c.oracle_key = '11200527'
   OR c.oracle_key LIKE '%/11200527'
   OR c.oracle_key LIKE '%11200527';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200355'
   OR c.oracle_key = '11200355'
   OR c.oracle_key LIKE '%/11200355'
   OR c.oracle_key LIKE '%11200355';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200355'
   OR c.oracle_key = '11200355'
   OR c.oracle_key LIKE '%/11200355'
   OR c.oracle_key LIKE '%11200355';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200588'
   OR c.oracle_key = '11200588'
   OR c.oracle_key LIKE '%/11200588'
   OR c.oracle_key LIKE '%11200588';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200588'
   OR c.oracle_key = '11200588'
   OR c.oracle_key LIKE '%/11200588'
   OR c.oracle_key LIKE '%11200588';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200546'
   OR c.oracle_key = '11200546'
   OR c.oracle_key LIKE '%/11200546'
   OR c.oracle_key LIKE '%11200546';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200546'
   OR c.oracle_key = '11200546'
   OR c.oracle_key LIKE '%/11200546'
   OR c.oracle_key LIKE '%11200546';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200589'
   OR c.oracle_key = '11200589'
   OR c.oracle_key LIKE '%/11200589'
   OR c.oracle_key LIKE '%11200589';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200589'
   OR c.oracle_key = '11200589'
   OR c.oracle_key LIKE '%/11200589'
   OR c.oracle_key LIKE '%11200589';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200761'
   OR c.oracle_key = '11200761'
   OR c.oracle_key LIKE '%/11200761'
   OR c.oracle_key LIKE '%11200761';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200761'
   OR c.oracle_key = '11200761'
   OR c.oracle_key LIKE '%/11200761'
   OR c.oracle_key LIKE '%11200761';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200344'
   OR c.oracle_key = '11200344'
   OR c.oracle_key LIKE '%/11200344'
   OR c.oracle_key LIKE '%11200344';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200344'
   OR c.oracle_key = '11200344'
   OR c.oracle_key LIKE '%/11200344'
   OR c.oracle_key LIKE '%11200344';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200114'
   OR c.oracle_key = '11200114'
   OR c.oracle_key LIKE '%/11200114'
   OR c.oracle_key LIKE '%11200114';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200114'
   OR c.oracle_key = '11200114'
   OR c.oracle_key LIKE '%/11200114'
   OR c.oracle_key LIKE '%11200114';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200154'
   OR c.oracle_key = '11200154'
   OR c.oracle_key LIKE '%/11200154'
   OR c.oracle_key LIKE '%11200154';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200154'
   OR c.oracle_key = '11200154'
   OR c.oracle_key LIKE '%/11200154'
   OR c.oracle_key LIKE '%11200154';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200247'
   OR c.oracle_key = '11200247'
   OR c.oracle_key LIKE '%/11200247'
   OR c.oracle_key LIKE '%11200247';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200247'
   OR c.oracle_key = '11200247'
   OR c.oracle_key LIKE '%/11200247'
   OR c.oracle_key LIKE '%11200247';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200394'
   OR c.oracle_key = '11200394'
   OR c.oracle_key LIKE '%/11200394'
   OR c.oracle_key LIKE '%11200394';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200394'
   OR c.oracle_key = '11200394'
   OR c.oracle_key LIKE '%/11200394'
   OR c.oracle_key LIKE '%11200394';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200505'
   OR c.oracle_key = '11200505'
   OR c.oracle_key LIKE '%/11200505'
   OR c.oracle_key LIKE '%11200505';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200505'
   OR c.oracle_key = '11200505'
   OR c.oracle_key LIKE '%/11200505'
   OR c.oracle_key LIKE '%11200505';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200648'
   OR c.oracle_key = '11200648'
   OR c.oracle_key LIKE '%/11200648'
   OR c.oracle_key LIKE '%11200648';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200648'
   OR c.oracle_key = '11200648'
   OR c.oracle_key LIKE '%/11200648'
   OR c.oracle_key LIKE '%11200648';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200618'
   OR c.oracle_key = '11200618'
   OR c.oracle_key LIKE '%/11200618'
   OR c.oracle_key LIKE '%11200618';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200618'
   OR c.oracle_key = '11200618'
   OR c.oracle_key LIKE '%/11200618'
   OR c.oracle_key LIKE '%11200618';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200602'
   OR c.oracle_key = '11200602'
   OR c.oracle_key LIKE '%/11200602'
   OR c.oracle_key LIKE '%11200602';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200602'
   OR c.oracle_key = '11200602'
   OR c.oracle_key LIKE '%/11200602'
   OR c.oracle_key LIKE '%11200602';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اربد'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اربد')
WHERE c.code = '11200760'
   OR c.oracle_key = '11200760'
   OR c.oracle_key LIKE '%/11200760'
   OR c.oracle_key LIKE '%11200760';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200760'
   OR c.oracle_key = '11200760'
   OR c.oracle_key LIKE '%/11200760'
   OR c.oracle_key LIKE '%11200760';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الغور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الغور')
WHERE c.code = '11200095'
   OR c.oracle_key = '11200095'
   OR c.oracle_key LIKE '%/11200095'
   OR c.oracle_key LIKE '%11200095';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200095'
   OR c.oracle_key = '11200095'
   OR c.oracle_key LIKE '%/11200095'
   OR c.oracle_key LIKE '%11200095';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الغور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الغور')
WHERE c.code = '11200357'
   OR c.oracle_key = '11200357'
   OR c.oracle_key LIKE '%/11200357'
   OR c.oracle_key LIKE '%11200357';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200357'
   OR c.oracle_key = '11200357'
   OR c.oracle_key LIKE '%/11200357'
   OR c.oracle_key LIKE '%11200357';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الغور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الغور')
WHERE c.code = '11200706'
   OR c.oracle_key = '11200706'
   OR c.oracle_key LIKE '%/11200706'
   OR c.oracle_key LIKE '%11200706';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200706'
   OR c.oracle_key = '11200706'
   OR c.oracle_key LIKE '%/11200706'
   OR c.oracle_key LIKE '%11200706';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الغور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الغور')
WHERE c.code = '11200439'
   OR c.oracle_key = '11200439'
   OR c.oracle_key LIKE '%/11200439'
   OR c.oracle_key LIKE '%11200439';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200439'
   OR c.oracle_key = '11200439'
   OR c.oracle_key LIKE '%/11200439'
   OR c.oracle_key LIKE '%11200439';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الشمال'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الغور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الغور')
WHERE c.code = '11200676'
   OR c.oracle_key = '11200676'
   OR c.oracle_key LIKE '%/11200676'
   OR c.oracle_key LIKE '%11200676';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'ركان'
WHERE c.code = '11200676'
   OR c.oracle_key = '11200676'
   OR c.oracle_key LIKE '%/11200676'
   OR c.oracle_key LIKE '%11200676';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو نصير')
WHERE c.code = '11200084'
   OR c.oracle_key = '11200084'
   OR c.oracle_key LIKE '%/11200084'
   OR c.oracle_key LIKE '%11200084';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
WHERE c.code = '11200084'
   OR c.oracle_key = '11200084'
   OR c.oracle_key LIKE '%/11200084'
   OR c.oracle_key LIKE '%11200084';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو نصير'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو نصير')
WHERE c.code = '11200083'
   OR c.oracle_key = '11200083'
   OR c.oracle_key LIKE '%/11200083'
   OR c.oracle_key LIKE '%11200083';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
WHERE c.code = '11200083'
   OR c.oracle_key = '11200083'
   OR c.oracle_key LIKE '%/11200083'
   OR c.oracle_key LIKE '%11200083';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200147'
   OR c.oracle_key = '11200147'
   OR c.oracle_key LIKE '%/11200147'
   OR c.oracle_key LIKE '%11200147';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
WHERE c.code = '11200147'
   OR c.oracle_key = '11200147'
   OR c.oracle_key LIKE '%/11200147'
   OR c.oracle_key LIKE '%11200147';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200732'
   OR c.oracle_key = '11200732'
   OR c.oracle_key LIKE '%/11200732'
   OR c.oracle_key LIKE '%11200732';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
WHERE c.code = '11200732'
   OR c.oracle_key = '11200732'
   OR c.oracle_key LIKE '%/11200732'
   OR c.oracle_key LIKE '%11200732';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200274'
   OR c.oracle_key = '11200274'
   OR c.oracle_key LIKE '%/11200274'
   OR c.oracle_key LIKE '%11200274';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
WHERE c.code = '11200274'
   OR c.oracle_key = '11200274'
   OR c.oracle_key LIKE '%/11200274'
   OR c.oracle_key LIKE '%11200274';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200587'
   OR c.oracle_key = '11200587'
   OR c.oracle_key LIKE '%/11200587'
   OR c.oracle_key LIKE '%11200587';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
WHERE c.code = '11200587'
   OR c.oracle_key = '11200587'
   OR c.oracle_key LIKE '%/11200587'
   OR c.oracle_key LIKE '%11200587';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200870'
   OR c.oracle_key = '11200870'
   OR c.oracle_key LIKE '%/11200870'
   OR c.oracle_key LIKE '%11200870';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامر ابو جاموس'
WHERE c.code = '11200870'
   OR c.oracle_key = '11200870'
   OR c.oracle_key LIKE '%/11200870'
   OR c.oracle_key LIKE '%11200870';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200185'
   OR c.oracle_key = '11200185'
   OR c.oracle_key LIKE '%/11200185'
   OR c.oracle_key LIKE '%11200185';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200185'
   OR c.oracle_key = '11200185'
   OR c.oracle_key LIKE '%/11200185'
   OR c.oracle_key LIKE '%11200185';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البقعة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البقعة')
WHERE c.code = '11200115'
   OR c.oracle_key = '11200115'
   OR c.oracle_key LIKE '%/11200115'
   OR c.oracle_key LIKE '%11200115';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200115'
   OR c.oracle_key = '11200115'
   OR c.oracle_key LIKE '%/11200115'
   OR c.oracle_key LIKE '%11200115';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عين الباشا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عين الباشا')
WHERE c.code = '11200287'
   OR c.oracle_key = '11200287'
   OR c.oracle_key LIKE '%/11200287'
   OR c.oracle_key LIKE '%11200287';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200287'
   OR c.oracle_key = '11200287'
   OR c.oracle_key LIKE '%/11200287'
   OR c.oracle_key LIKE '%11200287';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عين الباشا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عين الباشا')
WHERE c.code = '11200502'
   OR c.oracle_key = '11200502'
   OR c.oracle_key LIKE '%/11200502'
   OR c.oracle_key LIKE '%11200502';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200502'
   OR c.oracle_key = '11200502'
   OR c.oracle_key LIKE '%/11200502'
   OR c.oracle_key LIKE '%11200502';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القسطل'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'القسطل')
WHERE c.code = '11200267'
   OR c.oracle_key = '11200267'
   OR c.oracle_key LIKE '%/11200267'
   OR c.oracle_key LIKE '%11200267';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200267'
   OR c.oracle_key = '11200267'
   OR c.oracle_key LIKE '%/11200267'
   OR c.oracle_key LIKE '%11200267';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200522'
   OR c.oracle_key = '11200522'
   OR c.oracle_key LIKE '%/11200522'
   OR c.oracle_key LIKE '%11200522';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200522'
   OR c.oracle_key = '11200522'
   OR c.oracle_key LIKE '%/11200522'
   OR c.oracle_key LIKE '%11200522';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200555'
   OR c.oracle_key = '11200555'
   OR c.oracle_key LIKE '%/11200555'
   OR c.oracle_key LIKE '%11200555';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200555'
   OR c.oracle_key = '11200555'
   OR c.oracle_key LIKE '%/11200555'
   OR c.oracle_key LIKE '%11200555';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200204'
   OR c.oracle_key = '11200204'
   OR c.oracle_key LIKE '%/11200204'
   OR c.oracle_key LIKE '%11200204';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200204'
   OR c.oracle_key = '11200204'
   OR c.oracle_key LIKE '%/11200204'
   OR c.oracle_key LIKE '%11200204';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200415'
   OR c.oracle_key = '11200415'
   OR c.oracle_key LIKE '%/11200415'
   OR c.oracle_key LIKE '%11200415';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200415'
   OR c.oracle_key = '11200415'
   OR c.oracle_key LIKE '%/11200415'
   OR c.oracle_key LIKE '%11200415';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجمرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجمرك')
WHERE c.code = '11200100'
   OR c.oracle_key = '11200100'
   OR c.oracle_key LIKE '%/11200100'
   OR c.oracle_key LIKE '%11200100';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200100'
   OR c.oracle_key = '11200100'
   OR c.oracle_key LIKE '%/11200100'
   OR c.oracle_key LIKE '%11200100';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200197'
   OR c.oracle_key = '11200197'
   OR c.oracle_key LIKE '%/11200197'
   OR c.oracle_key LIKE '%11200197';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200197'
   OR c.oracle_key = '11200197'
   OR c.oracle_key LIKE '%/11200197'
   OR c.oracle_key LIKE '%11200197';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200615'
   OR c.oracle_key = '11200615'
   OR c.oracle_key LIKE '%/11200615'
   OR c.oracle_key LIKE '%11200615';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200615'
   OR c.oracle_key = '11200615'
   OR c.oracle_key LIKE '%/11200615'
   OR c.oracle_key LIKE '%11200615';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200211'
   OR c.oracle_key = '11200211'
   OR c.oracle_key LIKE '%/11200211'
   OR c.oracle_key LIKE '%11200211';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200211'
   OR c.oracle_key = '11200211'
   OR c.oracle_key LIKE '%/11200211'
   OR c.oracle_key LIKE '%11200211';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200299'
   OR c.oracle_key = '11200299'
   OR c.oracle_key LIKE '%/11200299'
   OR c.oracle_key LIKE '%11200299';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200299'
   OR c.oracle_key = '11200299'
   OR c.oracle_key LIKE '%/11200299'
   OR c.oracle_key LIKE '%11200299';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200216'
   OR c.oracle_key = '11200216'
   OR c.oracle_key LIKE '%/11200216'
   OR c.oracle_key LIKE '%11200216';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200216'
   OR c.oracle_key = '11200216'
   OR c.oracle_key LIKE '%/11200216'
   OR c.oracle_key LIKE '%11200216';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دوار الجمرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'دوار الجمرك')
WHERE c.code = '11200298'
   OR c.oracle_key = '11200298'
   OR c.oracle_key LIKE '%/11200298'
   OR c.oracle_key LIKE '%11200298';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200298'
   OR c.oracle_key = '11200298'
   OR c.oracle_key LIKE '%/11200298'
   OR c.oracle_key LIKE '%11200298';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دوار الجمرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'دوار الجمرك')
WHERE c.code = '11200494'
   OR c.oracle_key = '11200494'
   OR c.oracle_key LIKE '%/11200494'
   OR c.oracle_key LIKE '%11200494';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200494'
   OR c.oracle_key = '11200494'
   OR c.oracle_key LIKE '%/11200494'
   OR c.oracle_key LIKE '%11200494';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دوار الجمرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'دوار الجمرك')
WHERE c.code = '11200334'
   OR c.oracle_key = '11200334'
   OR c.oracle_key LIKE '%/11200334'
   OR c.oracle_key LIKE '%11200334';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200334'
   OR c.oracle_key = '11200334'
   OR c.oracle_key LIKE '%/11200334'
   OR c.oracle_key LIKE '%11200334';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200669'
   OR c.oracle_key = '11200669'
   OR c.oracle_key LIKE '%/11200669'
   OR c.oracle_key LIKE '%11200669';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200669'
   OR c.oracle_key = '11200669'
   OR c.oracle_key LIKE '%/11200669'
   OR c.oracle_key LIKE '%11200669';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200350'
   OR c.oracle_key = '11200350'
   OR c.oracle_key LIKE '%/11200350'
   OR c.oracle_key LIKE '%11200350';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200350'
   OR c.oracle_key = '11200350'
   OR c.oracle_key LIKE '%/11200350'
   OR c.oracle_key LIKE '%11200350';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200679'
   OR c.oracle_key = '11200679'
   OR c.oracle_key LIKE '%/11200679'
   OR c.oracle_key LIKE '%11200679';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200679'
   OR c.oracle_key = '11200679'
   OR c.oracle_key LIKE '%/11200679'
   OR c.oracle_key LIKE '%11200679';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200753'
   OR c.oracle_key = '11200753'
   OR c.oracle_key LIKE '%/11200753'
   OR c.oracle_key LIKE '%11200753';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200753'
   OR c.oracle_key = '11200753'
   OR c.oracle_key LIKE '%/11200753'
   OR c.oracle_key LIKE '%11200753';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الوحدات')
WHERE c.code = '11200160'
   OR c.oracle_key = '11200160'
   OR c.oracle_key LIKE '%/11200160'
   OR c.oracle_key LIKE '%11200160';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200160'
   OR c.oracle_key = '11200160'
   OR c.oracle_key LIKE '%/11200160'
   OR c.oracle_key LIKE '%11200160';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الوحدات')
WHERE c.code = '11200368'
   OR c.oracle_key = '11200368'
   OR c.oracle_key LIKE '%/11200368'
   OR c.oracle_key LIKE '%11200368';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200368'
   OR c.oracle_key = '11200368'
   OR c.oracle_key LIKE '%/11200368'
   OR c.oracle_key LIKE '%11200368';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200311'
   OR c.oracle_key = '11200311'
   OR c.oracle_key LIKE '%/11200311'
   OR c.oracle_key LIKE '%11200311';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200311'
   OR c.oracle_key = '11200311'
   OR c.oracle_key LIKE '%/11200311'
   OR c.oracle_key LIKE '%11200311';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200192'
   OR c.oracle_key = '11200192'
   OR c.oracle_key LIKE '%/11200192'
   OR c.oracle_key LIKE '%11200192';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200192'
   OR c.oracle_key = '11200192'
   OR c.oracle_key LIKE '%/11200192'
   OR c.oracle_key LIKE '%11200192';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200762'
   OR c.oracle_key = '11200762'
   OR c.oracle_key LIKE '%/11200762'
   OR c.oracle_key LIKE '%11200762';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200762'
   OR c.oracle_key = '11200762'
   OR c.oracle_key LIKE '%/11200762'
   OR c.oracle_key LIKE '%11200762';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200604'
   OR c.oracle_key = '11200604'
   OR c.oracle_key LIKE '%/11200604'
   OR c.oracle_key LIKE '%11200604';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200604'
   OR c.oracle_key = '11200604'
   OR c.oracle_key LIKE '%/11200604'
   OR c.oracle_key LIKE '%11200604';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200414'
   OR c.oracle_key = '11200414'
   OR c.oracle_key LIKE '%/11200414'
   OR c.oracle_key LIKE '%11200414';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200414'
   OR c.oracle_key = '11200414'
   OR c.oracle_key LIKE '%/11200414'
   OR c.oracle_key LIKE '%11200414';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200161'
   OR c.oracle_key = '11200161'
   OR c.oracle_key LIKE '%/11200161'
   OR c.oracle_key LIKE '%11200161';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200161'
   OR c.oracle_key = '11200161'
   OR c.oracle_key LIKE '%/11200161'
   OR c.oracle_key LIKE '%11200161';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200571'
   OR c.oracle_key = '11200571'
   OR c.oracle_key LIKE '%/11200571'
   OR c.oracle_key LIKE '%11200571';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200571'
   OR c.oracle_key = '11200571'
   OR c.oracle_key LIKE '%/11200571'
   OR c.oracle_key LIKE '%11200571';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200214'
   OR c.oracle_key = '11200214'
   OR c.oracle_key LIKE '%/11200214'
   OR c.oracle_key LIKE '%11200214';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200214'
   OR c.oracle_key = '11200214'
   OR c.oracle_key LIKE '%/11200214'
   OR c.oracle_key LIKE '%11200214';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200427'
   OR c.oracle_key = '11200427'
   OR c.oracle_key LIKE '%/11200427'
   OR c.oracle_key LIKE '%11200427';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200427'
   OR c.oracle_key = '11200427'
   OR c.oracle_key LIKE '%/11200427'
   OR c.oracle_key LIKE '%11200427';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200158'
   OR c.oracle_key = '11200158'
   OR c.oracle_key LIKE '%/11200158'
   OR c.oracle_key LIKE '%11200158';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200158'
   OR c.oracle_key = '11200158'
   OR c.oracle_key LIKE '%/11200158'
   OR c.oracle_key LIKE '%11200158';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200087'
   OR c.oracle_key = '11200087'
   OR c.oracle_key LIKE '%/11200087'
   OR c.oracle_key LIKE '%11200087';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200087'
   OR c.oracle_key = '11200087'
   OR c.oracle_key LIKE '%/11200087'
   OR c.oracle_key LIKE '%11200087';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200177'
   OR c.oracle_key = '11200177'
   OR c.oracle_key LIKE '%/11200177'
   OR c.oracle_key LIKE '%11200177';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200177'
   OR c.oracle_key = '11200177'
   OR c.oracle_key LIKE '%/11200177'
   OR c.oracle_key LIKE '%11200177';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200208'
   OR c.oracle_key = '11200208'
   OR c.oracle_key LIKE '%/11200208'
   OR c.oracle_key LIKE '%11200208';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200208'
   OR c.oracle_key = '11200208'
   OR c.oracle_key LIKE '%/11200208'
   OR c.oracle_key LIKE '%11200208';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200096'
   OR c.oracle_key = '11200096'
   OR c.oracle_key LIKE '%/11200096'
   OR c.oracle_key LIKE '%11200096';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200096'
   OR c.oracle_key = '11200096'
   OR c.oracle_key LIKE '%/11200096'
   OR c.oracle_key LIKE '%11200096';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200373'
   OR c.oracle_key = '11200373'
   OR c.oracle_key LIKE '%/11200373'
   OR c.oracle_key LIKE '%11200373';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200373'
   OR c.oracle_key = '11200373'
   OR c.oracle_key LIKE '%/11200373'
   OR c.oracle_key LIKE '%11200373';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200469'
   OR c.oracle_key = '11200469'
   OR c.oracle_key LIKE '%/11200469'
   OR c.oracle_key LIKE '%11200469';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200469'
   OR c.oracle_key = '11200469'
   OR c.oracle_key LIKE '%/11200469'
   OR c.oracle_key LIKE '%11200469';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200097'
   OR c.oracle_key = '11200097'
   OR c.oracle_key LIKE '%/11200097'
   OR c.oracle_key LIKE '%11200097';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200097'
   OR c.oracle_key = '11200097'
   OR c.oracle_key LIKE '%/11200097'
   OR c.oracle_key LIKE '%11200097';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200540'
   OR c.oracle_key = '11200540'
   OR c.oracle_key LIKE '%/11200540'
   OR c.oracle_key LIKE '%11200540';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200540'
   OR c.oracle_key = '11200540'
   OR c.oracle_key LIKE '%/11200540'
   OR c.oracle_key LIKE '%11200540';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الوحدات')
WHERE c.code = '11200263'
   OR c.oracle_key = '11200263'
   OR c.oracle_key LIKE '%/11200263'
   OR c.oracle_key LIKE '%11200263';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200263'
   OR c.oracle_key = '11200263'
   OR c.oracle_key LIKE '%/11200263'
   OR c.oracle_key LIKE '%11200263';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200799'
   OR c.oracle_key = '11200799'
   OR c.oracle_key LIKE '%/11200799'
   OR c.oracle_key LIKE '%11200799';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200799'
   OR c.oracle_key = '11200799'
   OR c.oracle_key LIKE '%/11200799'
   OR c.oracle_key LIKE '%11200799';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200200'
   OR c.oracle_key = '11200200'
   OR c.oracle_key LIKE '%/11200200'
   OR c.oracle_key LIKE '%11200200';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200200'
   OR c.oracle_key = '11200200'
   OR c.oracle_key LIKE '%/11200200'
   OR c.oracle_key LIKE '%11200200';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الصويفيه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الصويفيه')
WHERE c.code = '11200395'
   OR c.oracle_key = '11200395'
   OR c.oracle_key LIKE '%/11200395'
   OR c.oracle_key LIKE '%11200395';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200395'
   OR c.oracle_key = '11200395'
   OR c.oracle_key LIKE '%/11200395'
   OR c.oracle_key LIKE '%11200395';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الصويفيه'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الصويفيه')
WHERE c.code = '11200846'
   OR c.oracle_key = '11200846'
   OR c.oracle_key LIKE '%/11200846'
   OR c.oracle_key LIKE '%11200846';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200846'
   OR c.oracle_key = '11200846'
   OR c.oracle_key LIKE '%/11200846'
   OR c.oracle_key LIKE '%11200846';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200225'
   OR c.oracle_key = '11200225'
   OR c.oracle_key LIKE '%/11200225'
   OR c.oracle_key LIKE '%11200225';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200225'
   OR c.oracle_key = '11200225'
   OR c.oracle_key LIKE '%/11200225'
   OR c.oracle_key LIKE '%11200225';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200709'
   OR c.oracle_key = '11200709'
   OR c.oracle_key LIKE '%/11200709'
   OR c.oracle_key LIKE '%11200709';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200709'
   OR c.oracle_key = '11200709'
   OR c.oracle_key LIKE '%/11200709'
   OR c.oracle_key LIKE '%11200709';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200826'
   OR c.oracle_key = '11200826'
   OR c.oracle_key LIKE '%/11200826'
   OR c.oracle_key LIKE '%11200826';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200826'
   OR c.oracle_key = '11200826'
   OR c.oracle_key LIKE '%/11200826'
   OR c.oracle_key LIKE '%11200826';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200774'
   OR c.oracle_key = '11200774'
   OR c.oracle_key LIKE '%/11200774'
   OR c.oracle_key LIKE '%11200774';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200774'
   OR c.oracle_key = '11200774'
   OR c.oracle_key LIKE '%/11200774'
   OR c.oracle_key LIKE '%11200774';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200608'
   OR c.oracle_key = '11200608'
   OR c.oracle_key LIKE '%/11200608'
   OR c.oracle_key LIKE '%11200608';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200608'
   OR c.oracle_key = '11200608'
   OR c.oracle_key LIKE '%/11200608'
   OR c.oracle_key LIKE '%11200608';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200341'
   OR c.oracle_key = '11200341'
   OR c.oracle_key LIKE '%/11200341'
   OR c.oracle_key LIKE '%11200341';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200341'
   OR c.oracle_key = '11200341'
   OR c.oracle_key LIKE '%/11200341'
   OR c.oracle_key LIKE '%11200341';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200708'
   OR c.oracle_key = '11200708'
   OR c.oracle_key LIKE '%/11200708'
   OR c.oracle_key LIKE '%11200708';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200708'
   OR c.oracle_key = '11200708'
   OR c.oracle_key LIKE '%/11200708'
   OR c.oracle_key LIKE '%11200708';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200105'
   OR c.oracle_key = '11200105'
   OR c.oracle_key LIKE '%/11200105'
   OR c.oracle_key LIKE '%11200105';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200105'
   OR c.oracle_key = '11200105'
   OR c.oracle_key LIKE '%/11200105'
   OR c.oracle_key LIKE '%11200105';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200152'
   OR c.oracle_key = '11200152'
   OR c.oracle_key LIKE '%/11200152'
   OR c.oracle_key LIKE '%11200152';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'سامي عريقات'
WHERE c.code = '11200152'
   OR c.oracle_key = '11200152'
   OR c.oracle_key LIKE '%/11200152'
   OR c.oracle_key LIKE '%11200152';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200285'
   OR c.oracle_key = '11200285'
   OR c.oracle_key LIKE '%/11200285'
   OR c.oracle_key LIKE '%11200285';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200285'
   OR c.oracle_key = '11200285'
   OR c.oracle_key LIKE '%/11200285'
   OR c.oracle_key LIKE '%11200285';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200104'
   OR c.oracle_key = '11200104'
   OR c.oracle_key LIKE '%/11200104'
   OR c.oracle_key LIKE '%11200104';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200104'
   OR c.oracle_key = '11200104'
   OR c.oracle_key LIKE '%/11200104'
   OR c.oracle_key LIKE '%11200104';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200369'
   OR c.oracle_key = '11200369'
   OR c.oracle_key LIKE '%/11200369'
   OR c.oracle_key LIKE '%11200369';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200369'
   OR c.oracle_key = '11200369'
   OR c.oracle_key LIKE '%/11200369'
   OR c.oracle_key LIKE '%11200369';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200820'
   OR c.oracle_key = '11200820'
   OR c.oracle_key LIKE '%/11200820'
   OR c.oracle_key LIKE '%11200820';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200820'
   OR c.oracle_key = '11200820'
   OR c.oracle_key LIKE '%/11200820'
   OR c.oracle_key LIKE '%11200820';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المخيم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المخيم')
WHERE c.code = '11200443'
   OR c.oracle_key = '11200443'
   OR c.oracle_key LIKE '%/11200443'
   OR c.oracle_key LIKE '%11200443';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200443'
   OR c.oracle_key = '11200443'
   OR c.oracle_key LIKE '%/11200443'
   OR c.oracle_key LIKE '%11200443';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200282'
   OR c.oracle_key = '11200282'
   OR c.oracle_key LIKE '%/11200282'
   OR c.oracle_key LIKE '%11200282';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200282'
   OR c.oracle_key = '11200282'
   OR c.oracle_key LIKE '%/11200282'
   OR c.oracle_key LIKE '%11200282';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200410'
   OR c.oracle_key = '11200410'
   OR c.oracle_key LIKE '%/11200410'
   OR c.oracle_key LIKE '%11200410';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200410'
   OR c.oracle_key = '11200410'
   OR c.oracle_key LIKE '%/11200410'
   OR c.oracle_key LIKE '%11200410';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200365'
   OR c.oracle_key = '11200365'
   OR c.oracle_key LIKE '%/11200365'
   OR c.oracle_key LIKE '%11200365';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200365'
   OR c.oracle_key = '11200365'
   OR c.oracle_key LIKE '%/11200365'
   OR c.oracle_key LIKE '%11200365';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200210'
   OR c.oracle_key = '11200210'
   OR c.oracle_key LIKE '%/11200210'
   OR c.oracle_key LIKE '%11200210';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200210'
   OR c.oracle_key = '11200210'
   OR c.oracle_key LIKE '%/11200210'
   OR c.oracle_key LIKE '%11200210';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200542'
   OR c.oracle_key = '11200542'
   OR c.oracle_key LIKE '%/11200542'
   OR c.oracle_key LIKE '%11200542';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200542'
   OR c.oracle_key = '11200542'
   OR c.oracle_key LIKE '%/11200542'
   OR c.oracle_key LIKE '%11200542';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200144'
   OR c.oracle_key = '11200144'
   OR c.oracle_key LIKE '%/11200144'
   OR c.oracle_key LIKE '%11200144';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200144'
   OR c.oracle_key = '11200144'
   OR c.oracle_key LIKE '%/11200144'
   OR c.oracle_key LIKE '%11200144';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الرصيفة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الرصيفة')
WHERE c.code = '11200770'
   OR c.oracle_key = '11200770'
   OR c.oracle_key LIKE '%/11200770'
   OR c.oracle_key LIKE '%11200770';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200770'
   OR c.oracle_key = '11200770'
   OR c.oracle_key LIKE '%/11200770'
   OR c.oracle_key LIKE '%11200770';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200812'
   OR c.oracle_key = '11200812'
   OR c.oracle_key LIKE '%/11200812'
   OR c.oracle_key LIKE '%11200812';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200812'
   OR c.oracle_key = '11200812'
   OR c.oracle_key LIKE '%/11200812'
   OR c.oracle_key LIKE '%11200812';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200837'
   OR c.oracle_key = '11200837'
   OR c.oracle_key LIKE '%/11200837'
   OR c.oracle_key LIKE '%11200837';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200837'
   OR c.oracle_key = '11200837'
   OR c.oracle_key LIKE '%/11200837'
   OR c.oracle_key LIKE '%11200837';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الاشرفية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الاشرفية')
WHERE c.code = '11200752'
   OR c.oracle_key = '11200752'
   OR c.oracle_key LIKE '%/11200752'
   OR c.oracle_key LIKE '%11200752';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200752'
   OR c.oracle_key = '11200752'
   OR c.oracle_key LIKE '%/11200752'
   OR c.oracle_key LIKE '%11200752';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النصر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النصر')
WHERE c.code = '11200193'
   OR c.oracle_key = '11200193'
   OR c.oracle_key LIKE '%/11200193'
   OR c.oracle_key LIKE '%11200193';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200193'
   OR c.oracle_key = '11200193'
   OR c.oracle_key LIKE '%/11200193'
   OR c.oracle_key LIKE '%11200193';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النصر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النصر')
WHERE c.code = '11200751'
   OR c.oracle_key = '11200751'
   OR c.oracle_key LIKE '%/11200751'
   OR c.oracle_key LIKE '%11200751';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200751'
   OR c.oracle_key = '11200751'
   OR c.oracle_key LIKE '%/11200751'
   OR c.oracle_key LIKE '%11200751';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النصر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النصر')
WHERE c.code = '11200290'
   OR c.oracle_key = '11200290'
   OR c.oracle_key LIKE '%/11200290'
   OR c.oracle_key LIKE '%11200290';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200290'
   OR c.oracle_key = '11200290'
   OR c.oracle_key LIKE '%/11200290'
   OR c.oracle_key LIKE '%11200290';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النصر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النصر')
WHERE c.code = '11200728'
   OR c.oracle_key = '11200728'
   OR c.oracle_key LIKE '%/11200728'
   OR c.oracle_key LIKE '%11200728';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200728'
   OR c.oracle_key = '11200728'
   OR c.oracle_key LIKE '%/11200728'
   OR c.oracle_key LIKE '%11200728';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النصر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النصر')
WHERE c.code = '11200375'
   OR c.oracle_key = '11200375'
   OR c.oracle_key LIKE '%/11200375'
   OR c.oracle_key LIKE '%11200375';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200375'
   OR c.oracle_key = '11200375'
   OR c.oracle_key LIKE '%/11200375'
   OR c.oracle_key LIKE '%11200375';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'النصر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'النصر')
WHERE c.code = '11200779'
   OR c.oracle_key = '11200779'
   OR c.oracle_key LIKE '%/11200779'
   OR c.oracle_key LIKE '%11200779';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200779'
   OR c.oracle_key = '11200779'
   OR c.oracle_key LIKE '%/11200779'
   OR c.oracle_key LIKE '%11200779';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200271'
   OR c.oracle_key = '11200271'
   OR c.oracle_key LIKE '%/11200271'
   OR c.oracle_key LIKE '%11200271';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200271'
   OR c.oracle_key = '11200271'
   OR c.oracle_key LIKE '%/11200271'
   OR c.oracle_key LIKE '%11200271';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200503'
   OR c.oracle_key = '11200503'
   OR c.oracle_key LIKE '%/11200503'
   OR c.oracle_key LIKE '%11200503';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200503'
   OR c.oracle_key = '11200503'
   OR c.oracle_key LIKE '%/11200503'
   OR c.oracle_key LIKE '%11200503';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200241'
   OR c.oracle_key = '11200241'
   OR c.oracle_key LIKE '%/11200241'
   OR c.oracle_key LIKE '%11200241';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200241'
   OR c.oracle_key = '11200241'
   OR c.oracle_key LIKE '%/11200241'
   OR c.oracle_key LIKE '%11200241';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200719'
   OR c.oracle_key = '11200719'
   OR c.oracle_key LIKE '%/11200719'
   OR c.oracle_key LIKE '%11200719';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200719'
   OR c.oracle_key = '11200719'
   OR c.oracle_key LIKE '%/11200719'
   OR c.oracle_key LIKE '%11200719';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200582'
   OR c.oracle_key = '11200582'
   OR c.oracle_key LIKE '%/11200582'
   OR c.oracle_key LIKE '%11200582';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200582'
   OR c.oracle_key = '11200582'
   OR c.oracle_key LIKE '%/11200582'
   OR c.oracle_key LIKE '%11200582';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200734'
   OR c.oracle_key = '11200734'
   OR c.oracle_key LIKE '%/11200734'
   OR c.oracle_key LIKE '%11200734';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200734'
   OR c.oracle_key = '11200734'
   OR c.oracle_key LIKE '%/11200734'
   OR c.oracle_key LIKE '%11200734';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200718'
   OR c.oracle_key = '11200718'
   OR c.oracle_key LIKE '%/11200718'
   OR c.oracle_key LIKE '%11200718';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200718'
   OR c.oracle_key = '11200718'
   OR c.oracle_key LIKE '%/11200718'
   OR c.oracle_key LIKE '%11200718';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200508'
   OR c.oracle_key = '11200508'
   OR c.oracle_key LIKE '%/11200508'
   OR c.oracle_key LIKE '%11200508';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200508'
   OR c.oracle_key = '11200508'
   OR c.oracle_key LIKE '%/11200508'
   OR c.oracle_key LIKE '%11200508';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200688'
   OR c.oracle_key = '11200688'
   OR c.oracle_key LIKE '%/11200688'
   OR c.oracle_key LIKE '%11200688';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200688'
   OR c.oracle_key = '11200688'
   OR c.oracle_key LIKE '%/11200688'
   OR c.oracle_key LIKE '%11200688';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200739'
   OR c.oracle_key = '11200739'
   OR c.oracle_key LIKE '%/11200739'
   OR c.oracle_key LIKE '%11200739';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200739'
   OR c.oracle_key = '11200739'
   OR c.oracle_key LIKE '%/11200739'
   OR c.oracle_key LIKE '%11200739';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200733'
   OR c.oracle_key = '11200733'
   OR c.oracle_key LIKE '%/11200733'
   OR c.oracle_key LIKE '%11200733';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200733'
   OR c.oracle_key = '11200733'
   OR c.oracle_key LIKE '%/11200733'
   OR c.oracle_key LIKE '%11200733';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200272'
   OR c.oracle_key = '11200272'
   OR c.oracle_key LIKE '%/11200272'
   OR c.oracle_key LIKE '%11200272';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200272'
   OR c.oracle_key = '11200272'
   OR c.oracle_key LIKE '%/11200272'
   OR c.oracle_key LIKE '%11200272';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'سحاب'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'سحاب')
WHERE c.code = '11200693'
   OR c.oracle_key = '11200693'
   OR c.oracle_key LIKE '%/11200693'
   OR c.oracle_key LIKE '%11200693';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200693'
   OR c.oracle_key = '11200693'
   OR c.oracle_key LIKE '%/11200693'
   OR c.oracle_key LIKE '%11200693';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200180'
   OR c.oracle_key = '11200180'
   OR c.oracle_key LIKE '%/11200180'
   OR c.oracle_key LIKE '%11200180';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200180'
   OR c.oracle_key = '11200180'
   OR c.oracle_key LIKE '%/11200180'
   OR c.oracle_key LIKE '%11200180';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200666'
   OR c.oracle_key = '11200666'
   OR c.oracle_key LIKE '%/11200666'
   OR c.oracle_key LIKE '%11200666';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200666'
   OR c.oracle_key = '11200666'
   OR c.oracle_key LIKE '%/11200666'
   OR c.oracle_key LIKE '%11200666';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200437'
   OR c.oracle_key = '11200437'
   OR c.oracle_key LIKE '%/11200437'
   OR c.oracle_key LIKE '%11200437';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200437'
   OR c.oracle_key = '11200437'
   OR c.oracle_key LIKE '%/11200437'
   OR c.oracle_key LIKE '%11200437';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200520'
   OR c.oracle_key = '11200520'
   OR c.oracle_key LIKE '%/11200520'
   OR c.oracle_key LIKE '%11200520';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200520'
   OR c.oracle_key = '11200520'
   OR c.oracle_key LIKE '%/11200520'
   OR c.oracle_key LIKE '%11200520';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200822'
   OR c.oracle_key = '11200822'
   OR c.oracle_key LIKE '%/11200822'
   OR c.oracle_key LIKE '%11200822';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200822'
   OR c.oracle_key = '11200822'
   OR c.oracle_key LIKE '%/11200822'
   OR c.oracle_key LIKE '%11200822';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200750'
   OR c.oracle_key = '11200750'
   OR c.oracle_key LIKE '%/11200750'
   OR c.oracle_key LIKE '%11200750';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200750'
   OR c.oracle_key = '11200750'
   OR c.oracle_key LIKE '%/11200750'
   OR c.oracle_key LIKE '%11200750';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماركا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماركا')
WHERE c.code = '11200519'
   OR c.oracle_key = '11200519'
   OR c.oracle_key LIKE '%/11200519'
   OR c.oracle_key LIKE '%11200519';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200519'
   OR c.oracle_key = '11200519'
   OR c.oracle_key LIKE '%/11200519'
   OR c.oracle_key LIKE '%11200519';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200186'
   OR c.oracle_key = '11200186'
   OR c.oracle_key LIKE '%/11200186'
   OR c.oracle_key LIKE '%11200186';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200186'
   OR c.oracle_key = '11200186'
   OR c.oracle_key LIKE '%/11200186'
   OR c.oracle_key LIKE '%11200186';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200284'
   OR c.oracle_key = '11200284'
   OR c.oracle_key LIKE '%/11200284'
   OR c.oracle_key LIKE '%11200284';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200284'
   OR c.oracle_key = '11200284'
   OR c.oracle_key LIKE '%/11200284'
   OR c.oracle_key LIKE '%11200284';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200291'
   OR c.oracle_key = '11200291'
   OR c.oracle_key LIKE '%/11200291'
   OR c.oracle_key LIKE '%11200291';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200291'
   OR c.oracle_key = '11200291'
   OR c.oracle_key LIKE '%/11200291'
   OR c.oracle_key LIKE '%11200291';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خريبة السوق')
WHERE c.code = '11200696'
   OR c.oracle_key = '11200696'
   OR c.oracle_key LIKE '%/11200696'
   OR c.oracle_key LIKE '%11200696';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200696'
   OR c.oracle_key = '11200696'
   OR c.oracle_key LIKE '%/11200696'
   OR c.oracle_key LIKE '%11200696';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خريبة السوق')
WHERE c.code = '11200411'
   OR c.oracle_key = '11200411'
   OR c.oracle_key LIKE '%/11200411'
   OR c.oracle_key LIKE '%11200411';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200411'
   OR c.oracle_key = '11200411'
   OR c.oracle_key LIKE '%/11200411'
   OR c.oracle_key LIKE '%11200411';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خريبة السوق')
WHERE c.code = '11200086'
   OR c.oracle_key = '11200086'
   OR c.oracle_key LIKE '%/11200086'
   OR c.oracle_key LIKE '%11200086';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200086'
   OR c.oracle_key = '11200086'
   OR c.oracle_key LIKE '%/11200086'
   OR c.oracle_key LIKE '%11200086';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خريبة السوق')
WHERE c.code = '11200755'
   OR c.oracle_key = '11200755'
   OR c.oracle_key LIKE '%/11200755'
   OR c.oracle_key LIKE '%11200755';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200755'
   OR c.oracle_key = '11200755'
   OR c.oracle_key LIKE '%/11200755'
   OR c.oracle_key LIKE '%11200755';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خريبة السوق')
WHERE c.code = '11200727'
   OR c.oracle_key = '11200727'
   OR c.oracle_key LIKE '%/11200727'
   OR c.oracle_key LIKE '%11200727';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200727'
   OR c.oracle_key = '11200727'
   OR c.oracle_key LIKE '%/11200727'
   OR c.oracle_key LIKE '%11200727';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خريبة السوق')
WHERE c.code = '11200107'
   OR c.oracle_key = '11200107'
   OR c.oracle_key LIKE '%/11200107'
   OR c.oracle_key LIKE '%11200107';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200107'
   OR c.oracle_key = '11200107'
   OR c.oracle_key LIKE '%/11200107'
   OR c.oracle_key LIKE '%11200107';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خريبة السوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خريبة السوق')
WHERE c.code = '11200808'
   OR c.oracle_key = '11200808'
   OR c.oracle_key LIKE '%/11200808'
   OR c.oracle_key LIKE '%11200808';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200808'
   OR c.oracle_key = '11200808'
   OR c.oracle_key LIKE '%/11200808'
   OR c.oracle_key LIKE '%11200808';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200496'
   OR c.oracle_key = '11200496'
   OR c.oracle_key LIKE '%/11200496'
   OR c.oracle_key LIKE '%11200496';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200496'
   OR c.oracle_key = '11200496'
   OR c.oracle_key LIKE '%/11200496'
   OR c.oracle_key LIKE '%11200496';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200716'
   OR c.oracle_key = '11200716'
   OR c.oracle_key LIKE '%/11200716'
   OR c.oracle_key LIKE '%11200716';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200716'
   OR c.oracle_key = '11200716'
   OR c.oracle_key LIKE '%/11200716'
   OR c.oracle_key LIKE '%11200716';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200702'
   OR c.oracle_key = '11200702'
   OR c.oracle_key LIKE '%/11200702'
   OR c.oracle_key LIKE '%11200702';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200702'
   OR c.oracle_key = '11200702'
   OR c.oracle_key LIKE '%/11200702'
   OR c.oracle_key LIKE '%11200702';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200690'
   OR c.oracle_key = '11200690'
   OR c.oracle_key LIKE '%/11200690'
   OR c.oracle_key LIKE '%11200690';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200690'
   OR c.oracle_key = '11200690'
   OR c.oracle_key LIKE '%/11200690'
   OR c.oracle_key LIKE '%11200690';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200226'
   OR c.oracle_key = '11200226'
   OR c.oracle_key LIKE '%/11200226'
   OR c.oracle_key LIKE '%11200226';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200226'
   OR c.oracle_key = '11200226'
   OR c.oracle_key LIKE '%/11200226'
   OR c.oracle_key LIKE '%11200226';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200703'
   OR c.oracle_key = '11200703'
   OR c.oracle_key LIKE '%/11200703'
   OR c.oracle_key LIKE '%11200703';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200703'
   OR c.oracle_key = '11200703'
   OR c.oracle_key LIKE '%/11200703'
   OR c.oracle_key LIKE '%11200703';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200507'
   OR c.oracle_key = '11200507'
   OR c.oracle_key LIKE '%/11200507'
   OR c.oracle_key LIKE '%11200507';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200507'
   OR c.oracle_key = '11200507'
   OR c.oracle_key LIKE '%/11200507'
   OR c.oracle_key LIKE '%11200507';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200456'
   OR c.oracle_key = '11200456'
   OR c.oracle_key LIKE '%/11200456'
   OR c.oracle_key LIKE '%11200456';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200456'
   OR c.oracle_key = '11200456'
   OR c.oracle_key LIKE '%/11200456'
   OR c.oracle_key LIKE '%11200456';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'حي نزال'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'حي نزال')
WHERE c.code = '11200715'
   OR c.oracle_key = '11200715'
   OR c.oracle_key LIKE '%/11200715'
   OR c.oracle_key LIKE '%11200715';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200715'
   OR c.oracle_key = '11200715'
   OR c.oracle_key LIKE '%/11200715'
   OR c.oracle_key LIKE '%11200715';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل الزهور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جبل الزهور')
WHERE c.code = '11200707'
   OR c.oracle_key = '11200707'
   OR c.oracle_key LIKE '%/11200707'
   OR c.oracle_key LIKE '%11200707';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200707'
   OR c.oracle_key = '11200707'
   OR c.oracle_key LIKE '%/11200707'
   OR c.oracle_key LIKE '%11200707';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل التاج'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جبل التاج')
WHERE c.code = '11200786'
   OR c.oracle_key = '11200786'
   OR c.oracle_key LIKE '%/11200786'
   OR c.oracle_key LIKE '%11200786';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200786'
   OR c.oracle_key = '11200786'
   OR c.oracle_key LIKE '%/11200786'
   OR c.oracle_key LIKE '%11200786';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل التاج'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جبل التاج')
WHERE c.code = '11200809'
   OR c.oracle_key = '11200809'
   OR c.oracle_key LIKE '%/11200809'
   OR c.oracle_key LIKE '%11200809';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200809'
   OR c.oracle_key = '11200809'
   OR c.oracle_key LIKE '%/11200809'
   OR c.oracle_key LIKE '%11200809';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جاوا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جاوا')
WHERE c.code = '11200269'
   OR c.oracle_key = '11200269'
   OR c.oracle_key LIKE '%/11200269'
   OR c.oracle_key LIKE '%11200269';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200269'
   OR c.oracle_key = '11200269'
   OR c.oracle_key LIKE '%/11200269'
   OR c.oracle_key LIKE '%11200269';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جاوا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جاوا')
WHERE c.code = '11200695'
   OR c.oracle_key = '11200695'
   OR c.oracle_key LIKE '%/11200695'
   OR c.oracle_key LIKE '%11200695';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200695'
   OR c.oracle_key = '11200695'
   OR c.oracle_key LIKE '%/11200695'
   OR c.oracle_key LIKE '%11200695';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200501'
   OR c.oracle_key = '11200501'
   OR c.oracle_key LIKE '%/11200501'
   OR c.oracle_key LIKE '%11200501';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200501'
   OR c.oracle_key = '11200501'
   OR c.oracle_key LIKE '%/11200501'
   OR c.oracle_key LIKE '%11200501';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200699'
   OR c.oracle_key = '11200699'
   OR c.oracle_key LIKE '%/11200699'
   OR c.oracle_key LIKE '%11200699';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200699'
   OR c.oracle_key = '11200699'
   OR c.oracle_key LIKE '%/11200699'
   OR c.oracle_key LIKE '%11200699';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200442'
   OR c.oracle_key = '11200442'
   OR c.oracle_key LIKE '%/11200442'
   OR c.oracle_key LIKE '%11200442';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200442'
   OR c.oracle_key = '11200442'
   OR c.oracle_key LIKE '%/11200442'
   OR c.oracle_key LIKE '%11200442';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200512'
   OR c.oracle_key = '11200512'
   OR c.oracle_key LIKE '%/11200512'
   OR c.oracle_key LIKE '%11200512';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200512'
   OR c.oracle_key = '11200512'
   OR c.oracle_key LIKE '%/11200512'
   OR c.oracle_key LIKE '%11200512';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200327'
   OR c.oracle_key = '11200327'
   OR c.oracle_key LIKE '%/11200327'
   OR c.oracle_key LIKE '%11200327';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200327'
   OR c.oracle_key = '11200327'
   OR c.oracle_key LIKE '%/11200327'
   OR c.oracle_key LIKE '%11200327';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الوحدات')
WHERE c.code = '11200841'
   OR c.oracle_key = '11200841'
   OR c.oracle_key LIKE '%/11200841'
   OR c.oracle_key LIKE '%11200841';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200841'
   OR c.oracle_key = '11200841'
   OR c.oracle_key LIKE '%/11200841'
   OR c.oracle_key LIKE '%11200841';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الوحدات')
WHERE c.code = '11200451'
   OR c.oracle_key = '11200451'
   OR c.oracle_key LIKE '%/11200451'
   OR c.oracle_key LIKE '%11200451';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200451'
   OR c.oracle_key = '11200451'
   OR c.oracle_key LIKE '%/11200451'
   OR c.oracle_key LIKE '%11200451';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الوحدات'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الوحدات')
WHERE c.code = '11200262'
   OR c.oracle_key = '11200262'
   OR c.oracle_key LIKE '%/11200262'
   OR c.oracle_key LIKE '%11200262';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200262'
   OR c.oracle_key = '11200262'
   OR c.oracle_key LIKE '%/11200262'
   OR c.oracle_key LIKE '%11200262';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200108'
   OR c.oracle_key = '11200108'
   OR c.oracle_key LIKE '%/11200108'
   OR c.oracle_key LIKE '%11200108';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200108'
   OR c.oracle_key = '11200108'
   OR c.oracle_key LIKE '%/11200108'
   OR c.oracle_key LIKE '%11200108';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200678'
   OR c.oracle_key = '11200678'
   OR c.oracle_key LIKE '%/11200678'
   OR c.oracle_key LIKE '%11200678';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200678'
   OR c.oracle_key = '11200678'
   OR c.oracle_key LIKE '%/11200678'
   OR c.oracle_key LIKE '%11200678';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200729'
   OR c.oracle_key = '11200729'
   OR c.oracle_key LIKE '%/11200729'
   OR c.oracle_key LIKE '%11200729';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200729'
   OR c.oracle_key = '11200729'
   OR c.oracle_key LIKE '%/11200729'
   OR c.oracle_key LIKE '%11200729';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200717'
   OR c.oracle_key = '11200717'
   OR c.oracle_key LIKE '%/11200717'
   OR c.oracle_key LIKE '%11200717';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200717'
   OR c.oracle_key = '11200717'
   OR c.oracle_key LIKE '%/11200717'
   OR c.oracle_key LIKE '%11200717';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200713'
   OR c.oracle_key = '11200713'
   OR c.oracle_key LIKE '%/11200713'
   OR c.oracle_key LIKE '%11200713';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200713'
   OR c.oracle_key = '11200713'
   OR c.oracle_key LIKE '%/11200713'
   OR c.oracle_key LIKE '%11200713';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200620'
   OR c.oracle_key = '11200620'
   OR c.oracle_key LIKE '%/11200620'
   OR c.oracle_key LIKE '%11200620';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200620'
   OR c.oracle_key = '11200620'
   OR c.oracle_key LIKE '%/11200620'
   OR c.oracle_key LIKE '%11200620';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200867'
   OR c.oracle_key = '11200867'
   OR c.oracle_key LIKE '%/11200867'
   OR c.oracle_key LIKE '%11200867';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200867'
   OR c.oracle_key = '11200867'
   OR c.oracle_key LIKE '%/11200867'
   OR c.oracle_key LIKE '%11200867';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المستندة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المستندة')
WHERE c.code = '11200697'
   OR c.oracle_key = '11200697'
   OR c.oracle_key LIKE '%/11200697'
   OR c.oracle_key LIKE '%11200697';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200697'
   OR c.oracle_key = '11200697'
   OR c.oracle_key LIKE '%/11200697'
   OR c.oracle_key LIKE '%11200697';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المستندة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المستندة')
WHERE c.code = '11200810'
   OR c.oracle_key = '11200810'
   OR c.oracle_key LIKE '%/11200810'
   OR c.oracle_key LIKE '%11200810';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200810'
   OR c.oracle_key = '11200810'
   OR c.oracle_key LIKE '%/11200810'
   OR c.oracle_key LIKE '%11200810';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القويسمة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'القويسمة')
WHERE c.code = '11200526'
   OR c.oracle_key = '11200526'
   OR c.oracle_key LIKE '%/11200526'
   OR c.oracle_key LIKE '%11200526';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200526'
   OR c.oracle_key = '11200526'
   OR c.oracle_key LIKE '%/11200526'
   OR c.oracle_key LIKE '%11200526';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القويسمة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'القويسمة')
WHERE c.code = '11200361'
   OR c.oracle_key = '11200361'
   OR c.oracle_key LIKE '%/11200361'
   OR c.oracle_key LIKE '%11200361';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200361'
   OR c.oracle_key = '11200361'
   OR c.oracle_key LIKE '%/11200361'
   OR c.oracle_key LIKE '%11200361';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القويسمة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'القويسمة')
WHERE c.code = '11200360'
   OR c.oracle_key = '11200360'
   OR c.oracle_key LIKE '%/11200360'
   OR c.oracle_key LIKE '%11200360';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200360'
   OR c.oracle_key = '11200360'
   OR c.oracle_key LIKE '%/11200360'
   OR c.oracle_key LIKE '%11200360';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القويسمة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'القويسمة')
WHERE c.code = '11200874'
   OR c.oracle_key = '11200874'
   OR c.oracle_key LIKE '%/11200874'
   OR c.oracle_key LIKE '%11200874';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200874'
   OR c.oracle_key = '11200874'
   OR c.oracle_key LIKE '%/11200874'
   OR c.oracle_key LIKE '%11200874';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزهور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزهور')
WHERE c.code = '11200804'
   OR c.oracle_key = '11200804'
   OR c.oracle_key LIKE '%/11200804'
   OR c.oracle_key LIKE '%11200804';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200804'
   OR c.oracle_key = '11200804'
   OR c.oracle_key LIKE '%/11200804'
   OR c.oracle_key LIKE '%11200804';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الحزام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الحزام')
WHERE c.code = '11200156'
   OR c.oracle_key = '11200156'
   OR c.oracle_key LIKE '%/11200156'
   OR c.oracle_key LIKE '%11200156';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200156'
   OR c.oracle_key = '11200156'
   OR c.oracle_key LIKE '%/11200156'
   OR c.oracle_key LIKE '%11200156';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجويدة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجويدة')
WHERE c.code = '11200106'
   OR c.oracle_key = '11200106'
   OR c.oracle_key LIKE '%/11200106'
   OR c.oracle_key LIKE '%11200106';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200106'
   OR c.oracle_key = '11200106'
   OR c.oracle_key LIKE '%/11200106'
   OR c.oracle_key LIKE '%11200106';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجويدة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجويدة')
WHERE c.code = '11200742'
   OR c.oracle_key = '11200742'
   OR c.oracle_key LIKE '%/11200742'
   OR c.oracle_key LIKE '%11200742';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200742'
   OR c.oracle_key = '11200742'
   OR c.oracle_key LIKE '%/11200742'
   OR c.oracle_key LIKE '%11200742';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجويدة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجويدة')
WHERE c.code = '11200777'
   OR c.oracle_key = '11200777'
   OR c.oracle_key LIKE '%/11200777'
   OR c.oracle_key LIKE '%11200777';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200777'
   OR c.oracle_key = '11200777'
   OR c.oracle_key LIKE '%/11200777'
   OR c.oracle_key LIKE '%11200777';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجويدة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجويدة')
WHERE c.code = '11200724'
   OR c.oracle_key = '11200724'
   OR c.oracle_key LIKE '%/11200724'
   OR c.oracle_key LIKE '%11200724';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200724'
   OR c.oracle_key = '11200724'
   OR c.oracle_key LIKE '%/11200724'
   OR c.oracle_key LIKE '%11200724';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200449'
   OR c.oracle_key = '11200449'
   OR c.oracle_key LIKE '%/11200449'
   OR c.oracle_key LIKE '%11200449';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200449'
   OR c.oracle_key = '11200449'
   OR c.oracle_key LIKE '%/11200449'
   OR c.oracle_key LIKE '%11200449';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200511'
   OR c.oracle_key = '11200511'
   OR c.oracle_key LIKE '%/11200511'
   OR c.oracle_key LIKE '%11200511';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200511'
   OR c.oracle_key = '11200511'
   OR c.oracle_key LIKE '%/11200511'
   OR c.oracle_key LIKE '%11200511';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200359'
   OR c.oracle_key = '11200359'
   OR c.oracle_key LIKE '%/11200359'
   OR c.oracle_key LIKE '%11200359';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200359'
   OR c.oracle_key = '11200359'
   OR c.oracle_key LIKE '%/11200359'
   OR c.oracle_key LIKE '%11200359';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200289'
   OR c.oracle_key = '11200289'
   OR c.oracle_key LIKE '%/11200289'
   OR c.oracle_key LIKE '%11200289';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200289'
   OR c.oracle_key = '11200289'
   OR c.oracle_key LIKE '%/11200289'
   OR c.oracle_key LIKE '%11200289';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200691'
   OR c.oracle_key = '11200691'
   OR c.oracle_key LIKE '%/11200691'
   OR c.oracle_key LIKE '%11200691';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200691'
   OR c.oracle_key = '11200691'
   OR c.oracle_key LIKE '%/11200691'
   OR c.oracle_key LIKE '%11200691';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200863'
   OR c.oracle_key = '11200863'
   OR c.oracle_key LIKE '%/11200863'
   OR c.oracle_key LIKE '%11200863';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200863'
   OR c.oracle_key = '11200863'
   OR c.oracle_key LIKE '%/11200863'
   OR c.oracle_key LIKE '%11200863';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200343'
   OR c.oracle_key = '11200343'
   OR c.oracle_key LIKE '%/11200343'
   OR c.oracle_key LIKE '%11200343';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200343'
   OR c.oracle_key = '11200343'
   OR c.oracle_key LIKE '%/11200343'
   OR c.oracle_key LIKE '%11200343';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200704'
   OR c.oracle_key = '11200704'
   OR c.oracle_key LIKE '%/11200704'
   OR c.oracle_key LIKE '%11200704';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200704'
   OR c.oracle_key = '11200704'
   OR c.oracle_key LIKE '%/11200704'
   OR c.oracle_key LIKE '%11200704';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200692'
   OR c.oracle_key = '11200692'
   OR c.oracle_key LIKE '%/11200692'
   OR c.oracle_key LIKE '%11200692';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200692'
   OR c.oracle_key = '11200692'
   OR c.oracle_key LIKE '%/11200692'
   OR c.oracle_key LIKE '%11200692';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200869'
   OR c.oracle_key = '11200869'
   OR c.oracle_key LIKE '%/11200869'
   OR c.oracle_key LIKE '%11200869';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200869'
   OR c.oracle_key = '11200869'
   OR c.oracle_key LIKE '%/11200869'
   OR c.oracle_key LIKE '%11200869';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ابو علندا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ابو علندا')
WHERE c.code = '11200830'
   OR c.oracle_key = '11200830'
   OR c.oracle_key LIKE '%/11200830'
   OR c.oracle_key LIKE '%11200830';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200830'
   OR c.oracle_key = '11200830'
   OR c.oracle_key LIKE '%/11200830'
   OR c.oracle_key LIKE '%11200830';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ضاحية الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ضاحية الياسمين')
WHERE c.code = '11200798'
   OR c.oracle_key = '11200798'
   OR c.oracle_key LIKE '%/11200798'
   OR c.oracle_key LIKE '%11200798';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200798'
   OR c.oracle_key = '11200798'
   OR c.oracle_key LIKE '%/11200798'
   OR c.oracle_key LIKE '%11200798';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دوار الجمرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'دوار الجمرك')
WHERE c.code = '11200379'
   OR c.oracle_key = '11200379'
   OR c.oracle_key LIKE '%/11200379'
   OR c.oracle_key LIKE '%11200379';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200379'
   OR c.oracle_key = '11200379'
   OR c.oracle_key LIKE '%/11200379'
   OR c.oracle_key LIKE '%11200379';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جاوا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جاوا')
WHERE c.code = '11200421'
   OR c.oracle_key = '11200421'
   OR c.oracle_key LIKE '%/11200421'
   OR c.oracle_key LIKE '%11200421';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200421'
   OR c.oracle_key = '11200421'
   OR c.oracle_key LIKE '%/11200421'
   OR c.oracle_key LIKE '%11200421';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200392'
   OR c.oracle_key = '11200392'
   OR c.oracle_key LIKE '%/11200392'
   OR c.oracle_key LIKE '%11200392';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200392'
   OR c.oracle_key = '11200392'
   OR c.oracle_key LIKE '%/11200392'
   OR c.oracle_key LIKE '%11200392';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200402'
   OR c.oracle_key = '11200402'
   OR c.oracle_key LIKE '%/11200402'
   OR c.oracle_key LIKE '%11200402';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200402'
   OR c.oracle_key = '11200402'
   OR c.oracle_key LIKE '%/11200402'
   OR c.oracle_key LIKE '%11200402';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الياسمين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الياسمين')
WHERE c.code = '11200861'
   OR c.oracle_key = '11200861'
   OR c.oracle_key LIKE '%/11200861'
   OR c.oracle_key LIKE '%11200861';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200861'
   OR c.oracle_key = '11200861'
   OR c.oracle_key LIKE '%/11200861'
   OR c.oracle_key LIKE '%11200861';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اليادودة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اليادودة')
WHERE c.code = '11200227'
   OR c.oracle_key = '11200227'
   OR c.oracle_key LIKE '%/11200227'
   OR c.oracle_key LIKE '%11200227';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200227'
   OR c.oracle_key = '11200227'
   OR c.oracle_key LIKE '%/11200227'
   OR c.oracle_key LIKE '%11200227';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اليادودة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اليادودة')
WHERE c.code = '11200240'
   OR c.oracle_key = '11200240'
   OR c.oracle_key LIKE '%/11200240'
   OR c.oracle_key LIKE '%11200240';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200240'
   OR c.oracle_key = '11200240'
   OR c.oracle_key LIKE '%/11200240'
   OR c.oracle_key LIKE '%11200240';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اليادودة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اليادودة')
WHERE c.code = '11200829'
   OR c.oracle_key = '11200829'
   OR c.oracle_key LIKE '%/11200829'
   OR c.oracle_key LIKE '%11200829';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200829'
   OR c.oracle_key = '11200829'
   OR c.oracle_key LIKE '%/11200829'
   OR c.oracle_key LIKE '%11200829';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'اليادودة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'اليادودة')
WHERE c.code = '11200749'
   OR c.oracle_key = '11200749'
   OR c.oracle_key LIKE '%/11200749'
   OR c.oracle_key LIKE '%11200749';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200749'
   OR c.oracle_key = '11200749'
   OR c.oracle_key LIKE '%/11200749'
   OR c.oracle_key LIKE '%11200749';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المقابلين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المقابلين')
WHERE c.code = '11200658'
   OR c.oracle_key = '11200658'
   OR c.oracle_key LIKE '%/11200658'
   OR c.oracle_key LIKE '%11200658';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200658'
   OR c.oracle_key = '11200658'
   OR c.oracle_key LIKE '%/11200658'
   OR c.oracle_key LIKE '%11200658';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القويسمة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'القويسمة')
WHERE c.code = '11200825'
   OR c.oracle_key = '11200825'
   OR c.oracle_key LIKE '%/11200825'
   OR c.oracle_key LIKE '%11200825';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'شريف'
WHERE c.code = '11200825'
   OR c.oracle_key = '11200825'
   OR c.oracle_key LIKE '%/11200825'
   OR c.oracle_key LIKE '%11200825';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200396'
   OR c.oracle_key = '11200396'
   OR c.oracle_key LIKE '%/11200396'
   OR c.oracle_key LIKE '%11200396';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200396'
   OR c.oracle_key = '11200396'
   OR c.oracle_key LIKE '%/11200396'
   OR c.oracle_key LIKE '%11200396';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200198'
   OR c.oracle_key = '11200198'
   OR c.oracle_key LIKE '%/11200198'
   OR c.oracle_key LIKE '%11200198';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200198'
   OR c.oracle_key = '11200198'
   OR c.oracle_key LIKE '%/11200198'
   OR c.oracle_key LIKE '%11200198';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200319'
   OR c.oracle_key = '11200319'
   OR c.oracle_key LIKE '%/11200319'
   OR c.oracle_key LIKE '%11200319';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200319'
   OR c.oracle_key = '11200319'
   OR c.oracle_key LIKE '%/11200319'
   OR c.oracle_key LIKE '%11200319';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200132'
   OR c.oracle_key = '11200132'
   OR c.oracle_key LIKE '%/11200132'
   OR c.oracle_key LIKE '%11200132';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200132'
   OR c.oracle_key = '11200132'
   OR c.oracle_key LIKE '%/11200132'
   OR c.oracle_key LIKE '%11200132';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200133'
   OR c.oracle_key = '11200133'
   OR c.oracle_key LIKE '%/11200133'
   OR c.oracle_key LIKE '%11200133';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200133'
   OR c.oracle_key = '11200133'
   OR c.oracle_key LIKE '%/11200133'
   OR c.oracle_key LIKE '%11200133';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200128'
   OR c.oracle_key = '11200128'
   OR c.oracle_key LIKE '%/11200128'
   OR c.oracle_key LIKE '%11200128';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200128'
   OR c.oracle_key = '11200128'
   OR c.oracle_key LIKE '%/11200128'
   OR c.oracle_key LIKE '%11200128';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200134'
   OR c.oracle_key = '11200134'
   OR c.oracle_key LIKE '%/11200134'
   OR c.oracle_key LIKE '%11200134';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200134'
   OR c.oracle_key = '11200134'
   OR c.oracle_key LIKE '%/11200134'
   OR c.oracle_key LIKE '%11200134';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200391'
   OR c.oracle_key = '11200391'
   OR c.oracle_key LIKE '%/11200391'
   OR c.oracle_key LIKE '%11200391';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200391'
   OR c.oracle_key = '11200391'
   OR c.oracle_key LIKE '%/11200391'
   OR c.oracle_key LIKE '%11200391';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200399'
   OR c.oracle_key = '11200399'
   OR c.oracle_key LIKE '%/11200399'
   OR c.oracle_key LIKE '%11200399';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200399'
   OR c.oracle_key = '11200399'
   OR c.oracle_key LIKE '%/11200399'
   OR c.oracle_key LIKE '%11200399';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200317'
   OR c.oracle_key = '11200317'
   OR c.oracle_key LIKE '%/11200317'
   OR c.oracle_key LIKE '%11200317';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200317'
   OR c.oracle_key = '11200317'
   OR c.oracle_key LIKE '%/11200317'
   OR c.oracle_key LIKE '%11200317';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200255'
   OR c.oracle_key = '11200255'
   OR c.oracle_key LIKE '%/11200255'
   OR c.oracle_key LIKE '%11200255';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200255'
   OR c.oracle_key = '11200255'
   OR c.oracle_key LIKE '%/11200255'
   OR c.oracle_key LIKE '%11200255';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200766'
   OR c.oracle_key = '11200766'
   OR c.oracle_key LIKE '%/11200766'
   OR c.oracle_key LIKE '%11200766';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200766'
   OR c.oracle_key = '11200766'
   OR c.oracle_key LIKE '%/11200766'
   OR c.oracle_key LIKE '%11200766';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200398'
   OR c.oracle_key = '11200398'
   OR c.oracle_key LIKE '%/11200398'
   OR c.oracle_key LIKE '%11200398';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200398'
   OR c.oracle_key = '11200398'
   OR c.oracle_key LIKE '%/11200398'
   OR c.oracle_key LIKE '%11200398';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200199'
   OR c.oracle_key = '11200199'
   OR c.oracle_key LIKE '%/11200199'
   OR c.oracle_key LIKE '%11200199';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200199'
   OR c.oracle_key = '11200199'
   OR c.oracle_key LIKE '%/11200199'
   OR c.oracle_key LIKE '%11200199';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200326'
   OR c.oracle_key = '11200326'
   OR c.oracle_key LIKE '%/11200326'
   OR c.oracle_key LIKE '%11200326';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200326'
   OR c.oracle_key = '11200326'
   OR c.oracle_key LIKE '%/11200326'
   OR c.oracle_key LIKE '%11200326';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200325'
   OR c.oracle_key = '11200325'
   OR c.oracle_key LIKE '%/11200325'
   OR c.oracle_key LIKE '%11200325';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200325'
   OR c.oracle_key = '11200325'
   OR c.oracle_key LIKE '%/11200325'
   OR c.oracle_key LIKE '%11200325';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200423'
   OR c.oracle_key = '11200423'
   OR c.oracle_key LIKE '%/11200423'
   OR c.oracle_key LIKE '%11200423';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200423'
   OR c.oracle_key = '11200423'
   OR c.oracle_key LIKE '%/11200423'
   OR c.oracle_key LIKE '%11200423';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200256'
   OR c.oracle_key = '11200256'
   OR c.oracle_key LIKE '%/11200256'
   OR c.oracle_key LIKE '%11200256';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200256'
   OR c.oracle_key = '11200256'
   OR c.oracle_key LIKE '%/11200256'
   OR c.oracle_key LIKE '%11200256';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200136'
   OR c.oracle_key = '11200136'
   OR c.oracle_key LIKE '%/11200136'
   OR c.oracle_key LIKE '%11200136';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200136'
   OR c.oracle_key = '11200136'
   OR c.oracle_key LIKE '%/11200136'
   OR c.oracle_key LIKE '%11200136';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200130'
   OR c.oracle_key = '11200130'
   OR c.oracle_key LIKE '%/11200130'
   OR c.oracle_key LIKE '%11200130';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200130'
   OR c.oracle_key = '11200130'
   OR c.oracle_key LIKE '%/11200130'
   OR c.oracle_key LIKE '%11200130';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200323'
   OR c.oracle_key = '11200323'
   OR c.oracle_key LIKE '%/11200323'
   OR c.oracle_key LIKE '%11200323';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200323'
   OR c.oracle_key = '11200323'
   OR c.oracle_key LIKE '%/11200323'
   OR c.oracle_key LIKE '%11200323';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200318'
   OR c.oracle_key = '11200318'
   OR c.oracle_key LIKE '%/11200318'
   OR c.oracle_key LIKE '%11200318';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200318'
   OR c.oracle_key = '11200318'
   OR c.oracle_key LIKE '%/11200318'
   OR c.oracle_key LIKE '%11200318';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200400'
   OR c.oracle_key = '11200400'
   OR c.oracle_key LIKE '%/11200400'
   OR c.oracle_key LIKE '%11200400';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200400'
   OR c.oracle_key = '11200400'
   OR c.oracle_key LIKE '%/11200400'
   OR c.oracle_key LIKE '%11200400';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200137'
   OR c.oracle_key = '11200137'
   OR c.oracle_key LIKE '%/11200137'
   OR c.oracle_key LIKE '%11200137';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200137'
   OR c.oracle_key = '11200137'
   OR c.oracle_key LIKE '%/11200137'
   OR c.oracle_key LIKE '%11200137';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200623'
   OR c.oracle_key = '11200623'
   OR c.oracle_key LIKE '%/11200623'
   OR c.oracle_key LIKE '%11200623';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200623'
   OR c.oracle_key = '11200623'
   OR c.oracle_key LIKE '%/11200623'
   OR c.oracle_key LIKE '%11200623';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200424'
   OR c.oracle_key = '11200424'
   OR c.oracle_key LIKE '%/11200424'
   OR c.oracle_key LIKE '%11200424';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200424'
   OR c.oracle_key = '11200424'
   OR c.oracle_key LIKE '%/11200424'
   OR c.oracle_key LIKE '%11200424';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200131'
   OR c.oracle_key = '11200131'
   OR c.oracle_key LIKE '%/11200131'
   OR c.oracle_key LIKE '%11200131';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200131'
   OR c.oracle_key = '11200131'
   OR c.oracle_key LIKE '%/11200131'
   OR c.oracle_key LIKE '%11200131';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200765'
   OR c.oracle_key = '11200765'
   OR c.oracle_key LIKE '%/11200765'
   OR c.oracle_key LIKE '%11200765';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200765'
   OR c.oracle_key = '11200765'
   OR c.oracle_key LIKE '%/11200765'
   OR c.oracle_key LIKE '%11200765';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200422'
   OR c.oracle_key = '11200422'
   OR c.oracle_key LIKE '%/11200422'
   OR c.oracle_key LIKE '%11200422';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200422'
   OR c.oracle_key = '11200422'
   OR c.oracle_key LIKE '%/11200422'
   OR c.oracle_key LIKE '%11200422';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الكرك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الكرك')
WHERE c.code = '11200135'
   OR c.oracle_key = '11200135'
   OR c.oracle_key LIKE '%/11200135'
   OR c.oracle_key LIKE '%11200135';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200135'
   OR c.oracle_key = '11200135'
   OR c.oracle_key LIKE '%/11200135'
   OR c.oracle_key LIKE '%11200135';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200148'
   OR c.oracle_key = '11200148'
   OR c.oracle_key LIKE '%/11200148'
   OR c.oracle_key LIKE '%11200148';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200148'
   OR c.oracle_key = '11200148'
   OR c.oracle_key LIKE '%/11200148'
   OR c.oracle_key LIKE '%11200148';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200223'
   OR c.oracle_key = '11200223'
   OR c.oracle_key LIKE '%/11200223'
   OR c.oracle_key LIKE '%11200223';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200223'
   OR c.oracle_key = '11200223'
   OR c.oracle_key LIKE '%/11200223'
   OR c.oracle_key LIKE '%11200223';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200525'
   OR c.oracle_key = '11200525'
   OR c.oracle_key LIKE '%/11200525'
   OR c.oracle_key LIKE '%11200525';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200525'
   OR c.oracle_key = '11200525'
   OR c.oracle_key LIKE '%/11200525'
   OR c.oracle_key LIKE '%11200525';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200565'
   OR c.oracle_key = '11200565'
   OR c.oracle_key LIKE '%/11200565'
   OR c.oracle_key LIKE '%11200565';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200565'
   OR c.oracle_key = '11200565'
   OR c.oracle_key LIKE '%/11200565'
   OR c.oracle_key LIKE '%11200565';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200541'
   OR c.oracle_key = '11200541'
   OR c.oracle_key LIKE '%/11200541'
   OR c.oracle_key LIKE '%11200541';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200541'
   OR c.oracle_key = '11200541'
   OR c.oracle_key LIKE '%/11200541'
   OR c.oracle_key LIKE '%11200541';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200145'
   OR c.oracle_key = '11200145'
   OR c.oracle_key LIKE '%/11200145'
   OR c.oracle_key LIKE '%11200145';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200145'
   OR c.oracle_key = '11200145'
   OR c.oracle_key LIKE '%/11200145'
   OR c.oracle_key LIKE '%11200145';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200150'
   OR c.oracle_key = '11200150'
   OR c.oracle_key LIKE '%/11200150'
   OR c.oracle_key LIKE '%11200150';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200150'
   OR c.oracle_key = '11200150'
   OR c.oracle_key LIKE '%/11200150'
   OR c.oracle_key LIKE '%11200150';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200506'
   OR c.oracle_key = '11200506'
   OR c.oracle_key LIKE '%/11200506'
   OR c.oracle_key LIKE '%11200506';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200506'
   OR c.oracle_key = '11200506'
   OR c.oracle_key LIKE '%/11200506'
   OR c.oracle_key LIKE '%11200506';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200630'
   OR c.oracle_key = '11200630'
   OR c.oracle_key LIKE '%/11200630'
   OR c.oracle_key LIKE '%11200630';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200630'
   OR c.oracle_key = '11200630'
   OR c.oracle_key LIKE '%/11200630'
   OR c.oracle_key LIKE '%11200630';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200149'
   OR c.oracle_key = '11200149'
   OR c.oracle_key LIKE '%/11200149'
   OR c.oracle_key LIKE '%11200149';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200149'
   OR c.oracle_key = '11200149'
   OR c.oracle_key LIKE '%/11200149'
   OR c.oracle_key LIKE '%11200149';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200407'
   OR c.oracle_key = '11200407'
   OR c.oracle_key LIKE '%/11200407'
   OR c.oracle_key LIKE '%11200407';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200407'
   OR c.oracle_key = '11200407'
   OR c.oracle_key LIKE '%/11200407'
   OR c.oracle_key LIKE '%11200407';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200572'
   OR c.oracle_key = '11200572'
   OR c.oracle_key LIKE '%/11200572'
   OR c.oracle_key LIKE '%11200572';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200572'
   OR c.oracle_key = '11200572'
   OR c.oracle_key LIKE '%/11200572'
   OR c.oracle_key LIKE '%11200572';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200513'
   OR c.oracle_key = '11200513'
   OR c.oracle_key LIKE '%/11200513'
   OR c.oracle_key LIKE '%11200513';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200513'
   OR c.oracle_key = '11200513'
   OR c.oracle_key LIKE '%/11200513'
   OR c.oracle_key LIKE '%11200513';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200153'
   OR c.oracle_key = '11200153'
   OR c.oracle_key LIKE '%/11200153'
   OR c.oracle_key LIKE '%11200153';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200153'
   OR c.oracle_key = '11200153'
   OR c.oracle_key LIKE '%/11200153'
   OR c.oracle_key LIKE '%11200153';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200235'
   OR c.oracle_key = '11200235'
   OR c.oracle_key LIKE '%/11200235'
   OR c.oracle_key LIKE '%11200235';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200235'
   OR c.oracle_key = '11200235'
   OR c.oracle_key LIKE '%/11200235'
   OR c.oracle_key LIKE '%11200235';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200217'
   OR c.oracle_key = '11200217'
   OR c.oracle_key LIKE '%/11200217'
   OR c.oracle_key LIKE '%11200217';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200217'
   OR c.oracle_key = '11200217'
   OR c.oracle_key LIKE '%/11200217'
   OR c.oracle_key LIKE '%11200217';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200351'
   OR c.oracle_key = '11200351'
   OR c.oracle_key LIKE '%/11200351'
   OR c.oracle_key LIKE '%11200351';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200351'
   OR c.oracle_key = '11200351'
   OR c.oracle_key LIKE '%/11200351'
   OR c.oracle_key LIKE '%11200351';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200436'
   OR c.oracle_key = '11200436'
   OR c.oracle_key LIKE '%/11200436'
   OR c.oracle_key LIKE '%11200436';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200436'
   OR c.oracle_key = '11200436'
   OR c.oracle_key LIKE '%/11200436'
   OR c.oracle_key LIKE '%11200436';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200759'
   OR c.oracle_key = '11200759'
   OR c.oracle_key LIKE '%/11200759'
   OR c.oracle_key LIKE '%11200759';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200759'
   OR c.oracle_key = '11200759'
   OR c.oracle_key LIKE '%/11200759'
   OR c.oracle_key LIKE '%11200759';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200342'
   OR c.oracle_key = '11200342'
   OR c.oracle_key LIKE '%/11200342'
   OR c.oracle_key LIKE '%11200342';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200342'
   OR c.oracle_key = '11200342'
   OR c.oracle_key LIKE '%/11200342'
   OR c.oracle_key LIKE '%11200342';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200454'
   OR c.oracle_key = '11200454'
   OR c.oracle_key LIKE '%/11200454'
   OR c.oracle_key LIKE '%11200454';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200454'
   OR c.oracle_key = '11200454'
   OR c.oracle_key LIKE '%/11200454'
   OR c.oracle_key LIKE '%11200454';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200815'
   OR c.oracle_key = '11200815'
   OR c.oracle_key LIKE '%/11200815'
   OR c.oracle_key LIKE '%11200815';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200815'
   OR c.oracle_key = '11200815'
   OR c.oracle_key LIKE '%/11200815'
   OR c.oracle_key LIKE '%11200815';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200222'
   OR c.oracle_key = '11200222'
   OR c.oracle_key LIKE '%/11200222'
   OR c.oracle_key LIKE '%11200222';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200222'
   OR c.oracle_key = '11200222'
   OR c.oracle_key LIKE '%/11200222'
   OR c.oracle_key LIKE '%11200222';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200793'
   OR c.oracle_key = '11200793'
   OR c.oracle_key LIKE '%/11200793'
   OR c.oracle_key LIKE '%11200793';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200793'
   OR c.oracle_key = '11200793'
   OR c.oracle_key LIKE '%/11200793'
   OR c.oracle_key LIKE '%11200793';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'البيادر'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'البيادر')
WHERE c.code = '11200430'
   OR c.oracle_key = '11200430'
   OR c.oracle_key LIKE '%/11200430'
   OR c.oracle_key LIKE '%11200430';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200430'
   OR c.oracle_key = '11200430'
   OR c.oracle_key LIKE '%/11200430'
   OR c.oracle_key LIKE '%11200430';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجيزة')
WHERE c.code = '11200182'
   OR c.oracle_key = '11200182'
   OR c.oracle_key LIKE '%/11200182'
   OR c.oracle_key LIKE '%11200182';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200182'
   OR c.oracle_key = '11200182'
   OR c.oracle_key LIKE '%/11200182'
   OR c.oracle_key LIKE '%11200182';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجيزة')
WHERE c.code = '11200091'
   OR c.oracle_key = '11200091'
   OR c.oracle_key LIKE '%/11200091'
   OR c.oracle_key LIKE '%11200091';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200091'
   OR c.oracle_key = '11200091'
   OR c.oracle_key LIKE '%/11200091'
   OR c.oracle_key LIKE '%11200091';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجيزة')
WHERE c.code = '11200181'
   OR c.oracle_key = '11200181'
   OR c.oracle_key LIKE '%/11200181'
   OR c.oracle_key LIKE '%11200181';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200181'
   OR c.oracle_key = '11200181'
   OR c.oracle_key LIKE '%/11200181'
   OR c.oracle_key LIKE '%11200181';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجيزة')
WHERE c.code = '11200183'
   OR c.oracle_key = '11200183'
   OR c.oracle_key LIKE '%/11200183'
   OR c.oracle_key LIKE '%11200183';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200183'
   OR c.oracle_key = '11200183'
   OR c.oracle_key LIKE '%/11200183'
   OR c.oracle_key LIKE '%11200183';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجيزة')
WHERE c.code = '11200371'
   OR c.oracle_key = '11200371'
   OR c.oracle_key LIKE '%/11200371'
   OR c.oracle_key LIKE '%11200371';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200371'
   OR c.oracle_key = '11200371'
   OR c.oracle_key LIKE '%/11200371'
   OR c.oracle_key LIKE '%11200371';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجيزة')
WHERE c.code = '11200563'
   OR c.oracle_key = '11200563'
   OR c.oracle_key LIKE '%/11200563'
   OR c.oracle_key LIKE '%11200563';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200563'
   OR c.oracle_key = '11200563'
   OR c.oracle_key LIKE '%/11200563'
   OR c.oracle_key LIKE '%11200563';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الجيزة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الجيزة')
WHERE c.code = '11200564'
   OR c.oracle_key = '11200564'
   OR c.oracle_key LIKE '%/11200564'
   OR c.oracle_key LIKE '%11200564';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200564'
   OR c.oracle_key = '11200564'
   OR c.oracle_key LIKE '%/11200564'
   OR c.oracle_key LIKE '%11200564';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الشوبك'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الشوبك')
WHERE c.code = '11200758'
   OR c.oracle_key = '11200758'
   OR c.oracle_key LIKE '%/11200758'
   OR c.oracle_key LIKE '%11200758';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200758'
   OR c.oracle_key = '11200758'
   OR c.oracle_key LIKE '%/11200758'
   OR c.oracle_key LIKE '%11200758';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200500'
   OR c.oracle_key = '11200500'
   OR c.oracle_key LIKE '%/11200500'
   OR c.oracle_key LIKE '%11200500';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200500'
   OR c.oracle_key = '11200500'
   OR c.oracle_key LIKE '%/11200500'
   OR c.oracle_key LIKE '%11200500';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200258'
   OR c.oracle_key = '11200258'
   OR c.oracle_key LIKE '%/11200258'
   OR c.oracle_key LIKE '%11200258';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200258'
   OR c.oracle_key = '11200258'
   OR c.oracle_key LIKE '%/11200258'
   OR c.oracle_key LIKE '%11200258';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200162'
   OR c.oracle_key = '11200162'
   OR c.oracle_key LIKE '%/11200162'
   OR c.oracle_key LIKE '%11200162';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200162'
   OR c.oracle_key = '11200162'
   OR c.oracle_key LIKE '%/11200162'
   OR c.oracle_key LIKE '%11200162';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200738'
   OR c.oracle_key = '11200738'
   OR c.oracle_key LIKE '%/11200738'
   OR c.oracle_key LIKE '%11200738';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200738'
   OR c.oracle_key = '11200738'
   OR c.oracle_key LIKE '%/11200738'
   OR c.oracle_key LIKE '%11200738';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200428'
   OR c.oracle_key = '11200428'
   OR c.oracle_key LIKE '%/11200428'
   OR c.oracle_key LIKE '%11200428';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200428'
   OR c.oracle_key = '11200428'
   OR c.oracle_key LIKE '%/11200428'
   OR c.oracle_key LIKE '%11200428';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200142'
   OR c.oracle_key = '11200142'
   OR c.oracle_key LIKE '%/11200142'
   OR c.oracle_key LIKE '%11200142';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200142'
   OR c.oracle_key = '11200142'
   OR c.oracle_key LIKE '%/11200142'
   OR c.oracle_key LIKE '%11200142';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200139'
   OR c.oracle_key = '11200139'
   OR c.oracle_key LIKE '%/11200139'
   OR c.oracle_key LIKE '%11200139';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200139'
   OR c.oracle_key = '11200139'
   OR c.oracle_key LIKE '%/11200139'
   OR c.oracle_key LIKE '%11200139';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الطفيلة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الطفيلة')
WHERE c.code = '11200138'
   OR c.oracle_key = '11200138'
   OR c.oracle_key LIKE '%/11200138'
   OR c.oracle_key LIKE '%11200138';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200138'
   OR c.oracle_key = '11200138'
   OR c.oracle_key LIKE '%/11200138'
   OR c.oracle_key LIKE '%11200138';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200303'
   OR c.oracle_key = '11200303'
   OR c.oracle_key LIKE '%/11200303'
   OR c.oracle_key LIKE '%11200303';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200303'
   OR c.oracle_key = '11200303'
   OR c.oracle_key LIKE '%/11200303'
   OR c.oracle_key LIKE '%11200303';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200482'
   OR c.oracle_key = '11200482'
   OR c.oracle_key LIKE '%/11200482'
   OR c.oracle_key LIKE '%11200482';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200482'
   OR c.oracle_key = '11200482'
   OR c.oracle_key LIKE '%/11200482'
   OR c.oracle_key LIKE '%11200482';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200629'
   OR c.oracle_key = '11200629'
   OR c.oracle_key LIKE '%/11200629'
   OR c.oracle_key LIKE '%11200629';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200629'
   OR c.oracle_key = '11200629'
   OR c.oracle_key LIKE '%/11200629'
   OR c.oracle_key LIKE '%11200629';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200585'
   OR c.oracle_key = '11200585'
   OR c.oracle_key LIKE '%/11200585'
   OR c.oracle_key LIKE '%11200585';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200585'
   OR c.oracle_key = '11200585'
   OR c.oracle_key LIKE '%/11200585'
   OR c.oracle_key LIKE '%11200585';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200599'
   OR c.oracle_key = '11200599'
   OR c.oracle_key LIKE '%/11200599'
   OR c.oracle_key LIKE '%11200599';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200599'
   OR c.oracle_key = '11200599'
   OR c.oracle_key LIKE '%/11200599'
   OR c.oracle_key LIKE '%11200599';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200558'
   OR c.oracle_key = '11200558'
   OR c.oracle_key LIKE '%/11200558'
   OR c.oracle_key LIKE '%11200558';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200558'
   OR c.oracle_key = '11200558'
   OR c.oracle_key LIKE '%/11200558'
   OR c.oracle_key LIKE '%11200558';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200498'
   OR c.oracle_key = '11200498'
   OR c.oracle_key LIKE '%/11200498'
   OR c.oracle_key LIKE '%11200498';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200498'
   OR c.oracle_key = '11200498'
   OR c.oracle_key LIKE '%/11200498'
   OR c.oracle_key LIKE '%11200498';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200586'
   OR c.oracle_key = '11200586'
   OR c.oracle_key LIKE '%/11200586'
   OR c.oracle_key LIKE '%11200586';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200586'
   OR c.oracle_key = '11200586'
   OR c.oracle_key LIKE '%/11200586'
   OR c.oracle_key LIKE '%11200586';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200305'
   OR c.oracle_key = '11200305'
   OR c.oracle_key LIKE '%/11200305'
   OR c.oracle_key LIKE '%11200305';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200305'
   OR c.oracle_key = '11200305'
   OR c.oracle_key LIKE '%/11200305'
   OR c.oracle_key LIKE '%11200305';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200307'
   OR c.oracle_key = '11200307'
   OR c.oracle_key LIKE '%/11200307'
   OR c.oracle_key LIKE '%11200307';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200307'
   OR c.oracle_key = '11200307'
   OR c.oracle_key LIKE '%/11200307'
   OR c.oracle_key LIKE '%11200307';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200304'
   OR c.oracle_key = '11200304'
   OR c.oracle_key LIKE '%/11200304'
   OR c.oracle_key LIKE '%11200304';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200304'
   OR c.oracle_key = '11200304'
   OR c.oracle_key LIKE '%/11200304'
   OR c.oracle_key LIKE '%11200304';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'العقبة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'العقبة')
WHERE c.code = '11200474'
   OR c.oracle_key = '11200474'
   OR c.oracle_key LIKE '%/11200474'
   OR c.oracle_key LIKE '%11200474';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200474'
   OR c.oracle_key = '11200474'
   OR c.oracle_key LIKE '%/11200474'
   OR c.oracle_key LIKE '%11200474';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'القطرانة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'القطرانة')
WHERE c.code = '11200459'
   OR c.oracle_key = '11200459'
   OR c.oracle_key LIKE '%/11200459'
   OR c.oracle_key LIKE '%11200459';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200459'
   OR c.oracle_key = '11200459'
   OR c.oracle_key LIKE '%/11200459'
   OR c.oracle_key LIKE '%11200459';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المهاجرين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المهاجرين')
WHERE c.code = '11200595'
   OR c.oracle_key = '11200595'
   OR c.oracle_key LIKE '%/11200595'
   OR c.oracle_key LIKE '%11200595';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200595'
   OR c.oracle_key = '11200595'
   OR c.oracle_key LIKE '%/11200595'
   OR c.oracle_key LIKE '%11200595';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200843'
   OR c.oracle_key = '11200843'
   OR c.oracle_key LIKE '%/11200843'
   OR c.oracle_key LIKE '%11200843';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200843'
   OR c.oracle_key = '11200843'
   OR c.oracle_key LIKE '%/11200843'
   OR c.oracle_key LIKE '%11200843';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200789'
   OR c.oracle_key = '11200789'
   OR c.oracle_key LIKE '%/11200789'
   OR c.oracle_key LIKE '%11200789';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200789'
   OR c.oracle_key = '11200789'
   OR c.oracle_key LIKE '%/11200789'
   OR c.oracle_key LIKE '%11200789';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200123'
   OR c.oracle_key = '11200123'
   OR c.oracle_key LIKE '%/11200123'
   OR c.oracle_key LIKE '%11200123';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200123'
   OR c.oracle_key = '11200123'
   OR c.oracle_key LIKE '%/11200123'
   OR c.oracle_key LIKE '%11200123';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200125'
   OR c.oracle_key = '11200125'
   OR c.oracle_key LIKE '%/11200125'
   OR c.oracle_key LIKE '%11200125';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200125'
   OR c.oracle_key = '11200125'
   OR c.oracle_key LIKE '%/11200125'
   OR c.oracle_key LIKE '%11200125';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200124'
   OR c.oracle_key = '11200124'
   OR c.oracle_key LIKE '%/11200124'
   OR c.oracle_key LIKE '%11200124';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200124'
   OR c.oracle_key = '11200124'
   OR c.oracle_key LIKE '%/11200124'
   OR c.oracle_key LIKE '%11200124';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200126'
   OR c.oracle_key = '11200126'
   OR c.oracle_key LIKE '%/11200126'
   OR c.oracle_key LIKE '%11200126';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200126'
   OR c.oracle_key = '11200126'
   OR c.oracle_key LIKE '%/11200126'
   OR c.oracle_key LIKE '%11200126';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200122'
   OR c.oracle_key = '11200122'
   OR c.oracle_key LIKE '%/11200122'
   OR c.oracle_key LIKE '%11200122';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200122'
   OR c.oracle_key = '11200122'
   OR c.oracle_key LIKE '%/11200122'
   OR c.oracle_key LIKE '%11200122';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200372'
   OR c.oracle_key = '11200372'
   OR c.oracle_key LIKE '%/11200372'
   OR c.oracle_key LIKE '%11200372';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200372'
   OR c.oracle_key = '11200372'
   OR c.oracle_key LIKE '%/11200372'
   OR c.oracle_key LIKE '%11200372';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200189'
   OR c.oracle_key = '11200189'
   OR c.oracle_key LIKE '%/11200189'
   OR c.oracle_key LIKE '%11200189';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200189'
   OR c.oracle_key = '11200189'
   OR c.oracle_key LIKE '%/11200189'
   OR c.oracle_key LIKE '%11200189';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200250'
   OR c.oracle_key = '11200250'
   OR c.oracle_key LIKE '%/11200250'
   OR c.oracle_key LIKE '%11200250';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200250'
   OR c.oracle_key = '11200250'
   OR c.oracle_key LIKE '%/11200250'
   OR c.oracle_key LIKE '%11200250';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200486'
   OR c.oracle_key = '11200486'
   OR c.oracle_key LIKE '%/11200486'
   OR c.oracle_key LIKE '%11200486';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200486'
   OR c.oracle_key = '11200486'
   OR c.oracle_key LIKE '%/11200486'
   OR c.oracle_key LIKE '%11200486';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200457'
   OR c.oracle_key = '11200457'
   OR c.oracle_key LIKE '%/11200457'
   OR c.oracle_key LIKE '%11200457';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200457'
   OR c.oracle_key = '11200457'
   OR c.oracle_key LIKE '%/11200457'
   OR c.oracle_key LIKE '%11200457';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200127'
   OR c.oracle_key = '11200127'
   OR c.oracle_key LIKE '%/11200127'
   OR c.oracle_key LIKE '%11200127';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200127'
   OR c.oracle_key = '11200127'
   OR c.oracle_key LIKE '%/11200127'
   OR c.oracle_key LIKE '%11200127';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200187'
   OR c.oracle_key = '11200187'
   OR c.oracle_key LIKE '%/11200187'
   OR c.oracle_key LIKE '%11200187';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200187'
   OR c.oracle_key = '11200187'
   OR c.oracle_key LIKE '%/11200187'
   OR c.oracle_key LIKE '%11200187';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200790'
   OR c.oracle_key = '11200790'
   OR c.oracle_key LIKE '%/11200790'
   OR c.oracle_key LIKE '%11200790';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200790'
   OR c.oracle_key = '11200790'
   OR c.oracle_key LIKE '%/11200790'
   OR c.oracle_key LIKE '%11200790';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200188'
   OR c.oracle_key = '11200188'
   OR c.oracle_key LIKE '%/11200188'
   OR c.oracle_key LIKE '%11200188';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200188'
   OR c.oracle_key = '11200188'
   OR c.oracle_key LIKE '%/11200188'
   OR c.oracle_key LIKE '%11200188';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200389'
   OR c.oracle_key = '11200389'
   OR c.oracle_key LIKE '%/11200389'
   OR c.oracle_key LIKE '%11200389';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200389'
   OR c.oracle_key = '11200389'
   OR c.oracle_key LIKE '%/11200389'
   OR c.oracle_key LIKE '%11200389';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200190'
   OR c.oracle_key = '11200190'
   OR c.oracle_key LIKE '%/11200190'
   OR c.oracle_key LIKE '%11200190';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200190'
   OR c.oracle_key = '11200190'
   OR c.oracle_key LIKE '%/11200190'
   OR c.oracle_key LIKE '%11200190';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200416'
   OR c.oracle_key = '11200416'
   OR c.oracle_key LIKE '%/11200416'
   OR c.oracle_key LIKE '%11200416';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200416'
   OR c.oracle_key = '11200416'
   OR c.oracle_key LIKE '%/11200416'
   OR c.oracle_key LIKE '%11200416';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200390'
   OR c.oracle_key = '11200390'
   OR c.oracle_key LIKE '%/11200390'
   OR c.oracle_key LIKE '%11200390';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200390'
   OR c.oracle_key = '11200390'
   OR c.oracle_key LIKE '%/11200390'
   OR c.oracle_key LIKE '%11200390';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200320'
   OR c.oracle_key = '11200320'
   OR c.oracle_key LIKE '%/11200320'
   OR c.oracle_key LIKE '%11200320';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200320'
   OR c.oracle_key = '11200320'
   OR c.oracle_key LIKE '%/11200320'
   OR c.oracle_key LIKE '%11200320';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200660'
   OR c.oracle_key = '11200660'
   OR c.oracle_key LIKE '%/11200660'
   OR c.oracle_key LIKE '%11200660';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200660'
   OR c.oracle_key = '11200660'
   OR c.oracle_key LIKE '%/11200660'
   OR c.oracle_key LIKE '%11200660';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200723'
   OR c.oracle_key = '11200723'
   OR c.oracle_key LIKE '%/11200723'
   OR c.oracle_key LIKE '%11200723';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200723'
   OR c.oracle_key = '11200723'
   OR c.oracle_key LIKE '%/11200723'
   OR c.oracle_key LIKE '%11200723';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مادبا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مادبا')
WHERE c.code = '11200662'
   OR c.oracle_key = '11200662'
   OR c.oracle_key LIKE '%/11200662'
   OR c.oracle_key LIKE '%11200662';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200662'
   OR c.oracle_key = '11200662'
   OR c.oracle_key LIKE '%/11200662'
   OR c.oracle_key LIKE '%11200662';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200254'
   OR c.oracle_key = '11200254'
   OR c.oracle_key LIKE '%/11200254'
   OR c.oracle_key LIKE '%11200254';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200254'
   OR c.oracle_key = '11200254'
   OR c.oracle_key LIKE '%/11200254'
   OR c.oracle_key LIKE '%11200254';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200302'
   OR c.oracle_key = '11200302'
   OR c.oracle_key LIKE '%/11200302'
   OR c.oracle_key LIKE '%11200302';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200302'
   OR c.oracle_key = '11200302'
   OR c.oracle_key LIKE '%/11200302'
   OR c.oracle_key LIKE '%11200302';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200401'
   OR c.oracle_key = '11200401'
   OR c.oracle_key LIKE '%/11200401'
   OR c.oracle_key LIKE '%11200401';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200401'
   OR c.oracle_key = '11200401'
   OR c.oracle_key LIKE '%/11200401'
   OR c.oracle_key LIKE '%11200401';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200473'
   OR c.oracle_key = '11200473'
   OR c.oracle_key LIKE '%/11200473'
   OR c.oracle_key LIKE '%11200473';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200473'
   OR c.oracle_key = '11200473'
   OR c.oracle_key LIKE '%/11200473'
   OR c.oracle_key LIKE '%11200473';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200253'
   OR c.oracle_key = '11200253'
   OR c.oracle_key LIKE '%/11200253'
   OR c.oracle_key LIKE '%11200253';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200253'
   OR c.oracle_key = '11200253'
   OR c.oracle_key LIKE '%/11200253'
   OR c.oracle_key LIKE '%11200253';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200301'
   OR c.oracle_key = '11200301'
   OR c.oracle_key LIKE '%/11200301'
   OR c.oracle_key LIKE '%11200301';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200301'
   OR c.oracle_key = '11200301'
   OR c.oracle_key LIKE '%/11200301'
   OR c.oracle_key LIKE '%11200301';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200251'
   OR c.oracle_key = '11200251'
   OR c.oracle_key LIKE '%/11200251'
   OR c.oracle_key LIKE '%11200251';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200251'
   OR c.oracle_key = '11200251'
   OR c.oracle_key LIKE '%/11200251'
   OR c.oracle_key LIKE '%11200251';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200475'
   OR c.oracle_key = '11200475'
   OR c.oracle_key LIKE '%/11200475'
   OR c.oracle_key LIKE '%11200475';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200475'
   OR c.oracle_key = '11200475'
   OR c.oracle_key LIKE '%/11200475'
   OR c.oracle_key LIKE '%11200475';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'معان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'معان')
WHERE c.code = '11200472'
   OR c.oracle_key = '11200472'
   OR c.oracle_key LIKE '%/11200472'
   OR c.oracle_key LIKE '%11200472';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200472'
   OR c.oracle_key = '11200472'
   OR c.oracle_key LIKE '%/11200472'
   OR c.oracle_key LIKE '%11200472';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الجنوب'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي موسى'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي موسى')
WHERE c.code = '11200731'
   OR c.oracle_key = '11200731'
   OR c.oracle_key LIKE '%/11200731'
   OR c.oracle_key LIKE '%11200731';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'علي حياصات'
WHERE c.code = '11200731'
   OR c.oracle_key = '11200731'
   OR c.oracle_key LIKE '%/11200731'
   OR c.oracle_key LIKE '%11200731';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200184'
   OR c.oracle_key = '11200184'
   OR c.oracle_key LIKE '%/11200184'
   OR c.oracle_key LIKE '%11200184';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200184'
   OR c.oracle_key = '11200184'
   OR c.oracle_key LIKE '%/11200184'
   OR c.oracle_key LIKE '%11200184';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200655'
   OR c.oracle_key = '11200655'
   OR c.oracle_key LIKE '%/11200655'
   OR c.oracle_key LIKE '%11200655';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200655'
   OR c.oracle_key = '11200655'
   OR c.oracle_key LIKE '%/11200655'
   OR c.oracle_key LIKE '%11200655';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200689'
   OR c.oracle_key = '11200689'
   OR c.oracle_key LIKE '%/11200689'
   OR c.oracle_key LIKE '%11200689';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200689'
   OR c.oracle_key = '11200689'
   OR c.oracle_key LIKE '%/11200689'
   OR c.oracle_key LIKE '%11200689';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200817'
   OR c.oracle_key = '11200817'
   OR c.oracle_key LIKE '%/11200817'
   OR c.oracle_key LIKE '%11200817';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200817'
   OR c.oracle_key = '11200817'
   OR c.oracle_key LIKE '%/11200817'
   OR c.oracle_key LIKE '%11200817';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200278'
   OR c.oracle_key = '11200278'
   OR c.oracle_key LIKE '%/11200278'
   OR c.oracle_key LIKE '%11200278';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200278'
   OR c.oracle_key = '11200278'
   OR c.oracle_key LIKE '%/11200278'
   OR c.oracle_key LIKE '%11200278';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200242'
   OR c.oracle_key = '11200242'
   OR c.oracle_key LIKE '%/11200242'
   OR c.oracle_key LIKE '%11200242';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200242'
   OR c.oracle_key = '11200242'
   OR c.oracle_key LIKE '%/11200242'
   OR c.oracle_key LIKE '%11200242';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200385'
   OR c.oracle_key = '11200385'
   OR c.oracle_key LIKE '%/11200385'
   OR c.oracle_key LIKE '%11200385';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200385'
   OR c.oracle_key = '11200385'
   OR c.oracle_key LIKE '%/11200385'
   OR c.oracle_key LIKE '%11200385';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمي الشمالي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمي الشمالي')
WHERE c.code = '11200529'
   OR c.oracle_key = '11200529'
   OR c.oracle_key LIKE '%/11200529'
   OR c.oracle_key LIKE '%11200529';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200529'
   OR c.oracle_key = '11200529'
   OR c.oracle_key LIKE '%/11200529'
   OR c.oracle_key LIKE '%11200529';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200404'
   OR c.oracle_key = '11200404'
   OR c.oracle_key LIKE '%/11200404'
   OR c.oracle_key LIKE '%11200404';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200404'
   OR c.oracle_key = '11200404'
   OR c.oracle_key LIKE '%/11200404'
   OR c.oracle_key LIKE '%11200404';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خلدا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خلدا')
WHERE c.code = '11200802'
   OR c.oracle_key = '11200802'
   OR c.oracle_key LIKE '%/11200802'
   OR c.oracle_key LIKE '%11200802';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200802'
   OR c.oracle_key = '11200802'
   OR c.oracle_key LIKE '%/11200802'
   OR c.oracle_key LIKE '%11200802';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200580'
   OR c.oracle_key = '11200580'
   OR c.oracle_key LIKE '%/11200580'
   OR c.oracle_key LIKE '%11200580';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200580'
   OR c.oracle_key = '11200580'
   OR c.oracle_key LIKE '%/11200580'
   OR c.oracle_key LIKE '%11200580';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200544'
   OR c.oracle_key = '11200544'
   OR c.oracle_key LIKE '%/11200544'
   OR c.oracle_key LIKE '%11200544';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200544'
   OR c.oracle_key = '11200544'
   OR c.oracle_key LIKE '%/11200544'
   OR c.oracle_key LIKE '%11200544';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200176'
   OR c.oracle_key = '11200176'
   OR c.oracle_key LIKE '%/11200176'
   OR c.oracle_key LIKE '%11200176';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200176'
   OR c.oracle_key = '11200176'
   OR c.oracle_key LIKE '%/11200176'
   OR c.oracle_key LIKE '%11200176';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200567'
   OR c.oracle_key = '11200567'
   OR c.oracle_key LIKE '%/11200567'
   OR c.oracle_key LIKE '%11200567';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200567'
   OR c.oracle_key = '11200567'
   OR c.oracle_key LIKE '%/11200567'
   OR c.oracle_key LIKE '%11200567';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200245'
   OR c.oracle_key = '11200245'
   OR c.oracle_key LIKE '%/11200245'
   OR c.oracle_key LIKE '%11200245';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200245'
   OR c.oracle_key = '11200245'
   OR c.oracle_key LIKE '%/11200245'
   OR c.oracle_key LIKE '%11200245';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200367'
   OR c.oracle_key = '11200367'
   OR c.oracle_key LIKE '%/11200367'
   OR c.oracle_key LIKE '%11200367';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200367'
   OR c.oracle_key = '11200367'
   OR c.oracle_key LIKE '%/11200367'
   OR c.oracle_key LIKE '%11200367';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200495'
   OR c.oracle_key = '11200495'
   OR c.oracle_key LIKE '%/11200495'
   OR c.oracle_key LIKE '%11200495';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200495'
   OR c.oracle_key = '11200495'
   OR c.oracle_key LIKE '%/11200495'
   OR c.oracle_key LIKE '%11200495';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200673'
   OR c.oracle_key = '11200673'
   OR c.oracle_key LIKE '%/11200673'
   OR c.oracle_key LIKE '%11200673';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200673'
   OR c.oracle_key = '11200673'
   OR c.oracle_key LIKE '%/11200673'
   OR c.oracle_key LIKE '%11200673';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200246'
   OR c.oracle_key = '11200246'
   OR c.oracle_key LIKE '%/11200246'
   OR c.oracle_key LIKE '%11200246';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200246'
   OR c.oracle_key = '11200246'
   OR c.oracle_key LIKE '%/11200246'
   OR c.oracle_key LIKE '%11200246';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200174'
   OR c.oracle_key = '11200174'
   OR c.oracle_key LIKE '%/11200174'
   OR c.oracle_key LIKE '%11200174';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200174'
   OR c.oracle_key = '11200174'
   OR c.oracle_key LIKE '%/11200174'
   OR c.oracle_key LIKE '%11200174';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200119'
   OR c.oracle_key = '11200119'
   OR c.oracle_key LIKE '%/11200119'
   OR c.oracle_key LIKE '%11200119';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200119'
   OR c.oracle_key = '11200119'
   OR c.oracle_key LIKE '%/11200119'
   OR c.oracle_key LIKE '%11200119';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200175'
   OR c.oracle_key = '11200175'
   OR c.oracle_key LIKE '%/11200175'
   OR c.oracle_key LIKE '%11200175';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200175'
   OR c.oracle_key = '11200175'
   OR c.oracle_key LIKE '%/11200175'
   OR c.oracle_key LIKE '%11200175';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200300'
   OR c.oracle_key = '11200300'
   OR c.oracle_key LIKE '%/11200300'
   OR c.oracle_key LIKE '%11200300';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200300'
   OR c.oracle_key = '11200300'
   OR c.oracle_key LIKE '%/11200300'
   OR c.oracle_key LIKE '%11200300';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200859'
   OR c.oracle_key = '11200859'
   OR c.oracle_key LIKE '%/11200859'
   OR c.oracle_key LIKE '%11200859';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200859'
   OR c.oracle_key = '11200859'
   OR c.oracle_key LIKE '%/11200859'
   OR c.oracle_key LIKE '%11200859';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200875'
   OR c.oracle_key = '11200875'
   OR c.oracle_key LIKE '%/11200875'
   OR c.oracle_key LIKE '%11200875';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200875'
   OR c.oracle_key = '11200875'
   OR c.oracle_key LIKE '%/11200875'
   OR c.oracle_key LIKE '%11200875';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200694'
   OR c.oracle_key = '11200694'
   OR c.oracle_key LIKE '%/11200694'
   OR c.oracle_key LIKE '%11200694';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200694'
   OR c.oracle_key = '11200694'
   OR c.oracle_key LIKE '%/11200694'
   OR c.oracle_key LIKE '%11200694';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'السلط'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'السلط')
WHERE c.code = '11200773'
   OR c.oracle_key = '11200773'
   OR c.oracle_key LIKE '%/11200773'
   OR c.oracle_key LIKE '%11200773';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200773'
   OR c.oracle_key = '11200773'
   OR c.oracle_key LIKE '%/11200773'
   OR c.oracle_key LIKE '%11200773';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200579'
   OR c.oracle_key = '11200579'
   OR c.oracle_key LIKE '%/11200579'
   OR c.oracle_key LIKE '%11200579';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200579'
   OR c.oracle_key = '11200579'
   OR c.oracle_key LIKE '%/11200579'
   OR c.oracle_key LIKE '%11200579';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200597'
   OR c.oracle_key = '11200597'
   OR c.oracle_key LIKE '%/11200597'
   OR c.oracle_key LIKE '%11200597';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200597'
   OR c.oracle_key = '11200597'
   OR c.oracle_key LIKE '%/11200597'
   OR c.oracle_key LIKE '%11200597';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200409'
   OR c.oracle_key = '11200409'
   OR c.oracle_key LIKE '%/11200409'
   OR c.oracle_key LIKE '%11200409';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200409'
   OR c.oracle_key = '11200409'
   OR c.oracle_key LIKE '%/11200409'
   OR c.oracle_key LIKE '%11200409';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200093'
   OR c.oracle_key = '11200093'
   OR c.oracle_key LIKE '%/11200093'
   OR c.oracle_key LIKE '%11200093';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200093'
   OR c.oracle_key = '11200093'
   OR c.oracle_key LIKE '%/11200093'
   OR c.oracle_key LIKE '%11200093';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200168'
   OR c.oracle_key = '11200168'
   OR c.oracle_key LIKE '%/11200168'
   OR c.oracle_key LIKE '%11200168';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200168'
   OR c.oracle_key = '11200168'
   OR c.oracle_key LIKE '%/11200168'
   OR c.oracle_key LIKE '%11200168';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200166'
   OR c.oracle_key = '11200166'
   OR c.oracle_key LIKE '%/11200166'
   OR c.oracle_key LIKE '%11200166';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200166'
   OR c.oracle_key = '11200166'
   OR c.oracle_key LIKE '%/11200166'
   OR c.oracle_key LIKE '%11200166';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200167'
   OR c.oracle_key = '11200167'
   OR c.oracle_key LIKE '%/11200167'
   OR c.oracle_key LIKE '%11200167';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200167'
   OR c.oracle_key = '11200167'
   OR c.oracle_key LIKE '%/11200167'
   OR c.oracle_key LIKE '%11200167';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200169'
   OR c.oracle_key = '11200169'
   OR c.oracle_key LIKE '%/11200169'
   OR c.oracle_key LIKE '%11200169';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200169'
   OR c.oracle_key = '11200169'
   OR c.oracle_key LIKE '%/11200169'
   OR c.oracle_key LIKE '%11200169';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الفحيص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الفحيص')
WHERE c.code = '11200788'
   OR c.oracle_key = '11200788'
   OR c.oracle_key LIKE '%/11200788'
   OR c.oracle_key LIKE '%11200788';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200788'
   OR c.oracle_key = '11200788'
   OR c.oracle_key LIKE '%/11200788'
   OR c.oracle_key LIKE '%11200788';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة الرياضية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المدينة الرياضية')
WHERE c.code = '11200835'
   OR c.oracle_key = '11200835'
   OR c.oracle_key LIKE '%/11200835'
   OR c.oracle_key LIKE '%11200835';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200835'
   OR c.oracle_key = '11200835'
   OR c.oracle_key LIKE '%/11200835'
   OR c.oracle_key LIKE '%11200835';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200581'
   OR c.oracle_key = '11200581'
   OR c.oracle_key LIKE '%/11200581'
   OR c.oracle_key LIKE '%11200581';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200581'
   OR c.oracle_key = '11200581'
   OR c.oracle_key LIKE '%/11200581'
   OR c.oracle_key LIKE '%11200581';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200686'
   OR c.oracle_key = '11200686'
   OR c.oracle_key LIKE '%/11200686'
   OR c.oracle_key LIKE '%11200686';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200686'
   OR c.oracle_key = '11200686'
   OR c.oracle_key LIKE '%/11200686'
   OR c.oracle_key LIKE '%11200686';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المدينة المنورة'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المدينة المنورة')
WHERE c.code = '11200165'
   OR c.oracle_key = '11200165'
   OR c.oracle_key LIKE '%/11200165'
   OR c.oracle_key LIKE '%11200165';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200165'
   OR c.oracle_key = '11200165'
   OR c.oracle_key LIKE '%/11200165'
   OR c.oracle_key LIKE '%11200165';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200782'
   OR c.oracle_key = '11200782'
   OR c.oracle_key LIKE '%/11200782'
   OR c.oracle_key LIKE '%11200782';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200782'
   OR c.oracle_key = '11200782'
   OR c.oracle_key LIKE '%/11200782'
   OR c.oracle_key LIKE '%11200782';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200488'
   OR c.oracle_key = '11200488'
   OR c.oracle_key LIKE '%/11200488'
   OR c.oracle_key LIKE '%11200488';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200488'
   OR c.oracle_key = '11200488'
   OR c.oracle_key LIKE '%/11200488'
   OR c.oracle_key LIKE '%11200488';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200643'
   OR c.oracle_key = '11200643'
   OR c.oracle_key LIKE '%/11200643'
   OR c.oracle_key LIKE '%11200643';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200643'
   OR c.oracle_key = '11200643'
   OR c.oracle_key LIKE '%/11200643'
   OR c.oracle_key LIKE '%11200643';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'وسط عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'طبربور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'طبربور')
WHERE c.code = '11200720'
   OR c.oracle_key = '11200720'
   OR c.oracle_key LIKE '%/11200720'
   OR c.oracle_key LIKE '%11200720';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200720'
   OR c.oracle_key = '11200720'
   OR c.oracle_key LIKE '%/11200720'
   OR c.oracle_key LIKE '%11200720';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عين الباشا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عين الباشا')
WHERE c.code = '11200435'
   OR c.oracle_key = '11200435'
   OR c.oracle_key LIKE '%/11200435'
   OR c.oracle_key LIKE '%11200435';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200435'
   OR c.oracle_key = '11200435'
   OR c.oracle_key LIKE '%/11200435'
   OR c.oracle_key LIKE '%11200435';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عين الباشا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عين الباشا')
WHERE c.code = '11200562'
   OR c.oracle_key = '11200562'
   OR c.oracle_key LIKE '%/11200562'
   OR c.oracle_key LIKE '%11200562';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200562'
   OR c.oracle_key = '11200562'
   OR c.oracle_key LIKE '%/11200562'
   OR c.oracle_key LIKE '%11200562';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200767'
   OR c.oracle_key = '11200767'
   OR c.oracle_key LIKE '%/11200767'
   OR c.oracle_key LIKE '%11200767';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200767'
   OR c.oracle_key = '11200767'
   OR c.oracle_key LIKE '%/11200767'
   OR c.oracle_key LIKE '%11200767';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200677'
   OR c.oracle_key = '11200677'
   OR c.oracle_key LIKE '%/11200677'
   OR c.oracle_key LIKE '%11200677';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200677'
   OR c.oracle_key = '11200677'
   OR c.oracle_key LIKE '%/11200677'
   OR c.oracle_key LIKE '%11200677';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200270'
   OR c.oracle_key = '11200270'
   OR c.oracle_key LIKE '%/11200270'
   OR c.oracle_key LIKE '%11200270';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200270'
   OR c.oracle_key = '11200270'
   OR c.oracle_key LIKE '%/11200270'
   OR c.oracle_key LIKE '%11200270';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200730'
   OR c.oracle_key = '11200730'
   OR c.oracle_key LIKE '%/11200730'
   OR c.oracle_key LIKE '%11200730';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200730'
   OR c.oracle_key = '11200730'
   OR c.oracle_key LIKE '%/11200730'
   OR c.oracle_key LIKE '%11200730';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200468'
   OR c.oracle_key = '11200468'
   OR c.oracle_key LIKE '%/11200468'
   OR c.oracle_key LIKE '%11200468';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200468'
   OR c.oracle_key = '11200468'
   OR c.oracle_key LIKE '%/11200468'
   OR c.oracle_key LIKE '%11200468';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200801'
   OR c.oracle_key = '11200801'
   OR c.oracle_key LIKE '%/11200801'
   OR c.oracle_key LIKE '%11200801';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200801'
   OR c.oracle_key = '11200801'
   OR c.oracle_key LIKE '%/11200801'
   OR c.oracle_key LIKE '%11200801';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200463'
   OR c.oracle_key = '11200463'
   OR c.oracle_key LIKE '%/11200463'
   OR c.oracle_key LIKE '%11200463';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200463'
   OR c.oracle_key = '11200463'
   OR c.oracle_key LIKE '%/11200463'
   OR c.oracle_key LIKE '%11200463';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200800'
   OR c.oracle_key = '11200800'
   OR c.oracle_key LIKE '%/11200800'
   OR c.oracle_key LIKE '%11200800';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200800'
   OR c.oracle_key = '11200800'
   OR c.oracle_key LIKE '%/11200800'
   OR c.oracle_key LIKE '%11200800';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200403'
   OR c.oracle_key = '11200403'
   OR c.oracle_key LIKE '%/11200403'
   OR c.oracle_key LIKE '%11200403';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200403'
   OR c.oracle_key = '11200403'
   OR c.oracle_key LIKE '%/11200403'
   OR c.oracle_key LIKE '%11200403';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200340'
   OR c.oracle_key = '11200340'
   OR c.oracle_key LIKE '%/11200340'
   OR c.oracle_key LIKE '%11200340';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200340'
   OR c.oracle_key = '11200340'
   OR c.oracle_key LIKE '%/11200340'
   OR c.oracle_key LIKE '%11200340';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200818'
   OR c.oracle_key = '11200818'
   OR c.oracle_key LIKE '%/11200818'
   OR c.oracle_key LIKE '%11200818';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200818'
   OR c.oracle_key = '11200818'
   OR c.oracle_key LIKE '%/11200818'
   OR c.oracle_key LIKE '%11200818';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'تلاع العلي'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'تلاع العلي')
WHERE c.code = '11200803'
   OR c.oracle_key = '11200803'
   OR c.oracle_key LIKE '%/11200803'
   OR c.oracle_key LIKE '%11200803';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200803'
   OR c.oracle_key = '11200803'
   OR c.oracle_key LIKE '%/11200803'
   OR c.oracle_key LIKE '%11200803';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عين الباشا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عين الباشا')
WHERE c.code = '11200627'
   OR c.oracle_key = '11200627'
   OR c.oracle_key LIKE '%/11200627'
   OR c.oracle_key LIKE '%11200627';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200627'
   OR c.oracle_key = '11200627'
   OR c.oracle_key LIKE '%/11200627'
   OR c.oracle_key LIKE '%11200627';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل الحسين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جبل الحسين')
WHERE c.code = '11200179'
   OR c.oracle_key = '11200179'
   OR c.oracle_key LIKE '%/11200179'
   OR c.oracle_key LIKE '%11200179';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200179'
   OR c.oracle_key = '11200179'
   OR c.oracle_key LIKE '%/11200179'
   OR c.oracle_key LIKE '%11200179';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل الحسين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جبل الحسين')
WHERE c.code = '11200312'
   OR c.oracle_key = '11200312'
   OR c.oracle_key LIKE '%/11200312'
   OR c.oracle_key LIKE '%11200312';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200312'
   OR c.oracle_key = '11200312'
   OR c.oracle_key LIKE '%/11200312'
   OR c.oracle_key LIKE '%11200312';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل الحسين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جبل الحسين')
WHERE c.code = '11200239'
   OR c.oracle_key = '11200239'
   OR c.oracle_key LIKE '%/11200239'
   OR c.oracle_key LIKE '%11200239';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200239'
   OR c.oracle_key = '11200239'
   OR c.oracle_key LIKE '%/11200239'
   OR c.oracle_key LIKE '%11200239';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'جبل الحسين'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'جبل الحسين')
WHERE c.code = '11200839'
   OR c.oracle_key = '11200839'
   OR c.oracle_key LIKE '%/11200839'
   OR c.oracle_key LIKE '%11200839';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200839'
   OR c.oracle_key = '11200839'
   OR c.oracle_key LIKE '%/11200839'
   OR c.oracle_key LIKE '%11200839';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الشرقية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'وادي الرمم'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'وادي الرمم')
WHERE c.code = '11200607'
   OR c.oracle_key = '11200607'
   OR c.oracle_key LIKE '%/11200607'
   OR c.oracle_key LIKE '%11200607';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'إبراهيم'
WHERE c.code = '11200607'
   OR c.oracle_key = '11200607'
   OR c.oracle_key LIKE '%/11200607'
   OR c.oracle_key LIKE '%11200607';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خلدا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خلدا')
WHERE c.code = '11200452'
   OR c.oracle_key = '11200452'
   OR c.oracle_key LIKE '%/11200452'
   OR c.oracle_key LIKE '%11200452';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200452'
   OR c.oracle_key = '11200452'
   OR c.oracle_key LIKE '%/11200452'
   OR c.oracle_key LIKE '%11200452';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'خلدا'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'خلدا')
WHERE c.code = '11200672'
   OR c.oracle_key = '11200672'
   OR c.oracle_key LIKE '%/11200672'
   OR c.oracle_key LIKE '%11200672';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200672'
   OR c.oracle_key = '11200672'
   OR c.oracle_key LIKE '%/11200672'
   OR c.oracle_key LIKE '%11200672';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'دابوق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'دابوق')
WHERE c.code = '11200203'
   OR c.oracle_key = '11200203'
   OR c.oracle_key LIKE '%/11200203'
   OR c.oracle_key LIKE '%11200203';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200203'
   OR c.oracle_key = '11200203'
   OR c.oracle_key LIKE '%/11200203'
   OR c.oracle_key LIKE '%11200203';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عمان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عمان')
WHERE c.code = '11200532'
   OR c.oracle_key = '11200532'
   OR c.oracle_key LIKE '%/11200532'
   OR c.oracle_key LIKE '%11200532';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200532'
   OR c.oracle_key = '11200532'
   OR c.oracle_key LIKE '%/11200532'
   OR c.oracle_key LIKE '%11200532';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'شمال عمان'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ماحص'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ماحص')
WHERE c.code = '11200244'
   OR c.oracle_key = '11200244'
   OR c.oracle_key LIKE '%/11200244'
   OR c.oracle_key LIKE '%11200244';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200244'
   OR c.oracle_key = '11200244'
   OR c.oracle_key LIKE '%/11200244'
   OR c.oracle_key LIKE '%11200244';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200109'
   OR c.oracle_key = '11200109'
   OR c.oracle_key LIKE '%/11200109'
   OR c.oracle_key LIKE '%11200109';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200109'
   OR c.oracle_key = '11200109'
   OR c.oracle_key LIKE '%/11200109'
   OR c.oracle_key LIKE '%11200109';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200195'
   OR c.oracle_key = '11200195'
   OR c.oracle_key LIKE '%/11200195'
   OR c.oracle_key LIKE '%11200195';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200195'
   OR c.oracle_key = '11200195'
   OR c.oracle_key LIKE '%/11200195'
   OR c.oracle_key LIKE '%11200195';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200110'
   OR c.oracle_key = '11200110'
   OR c.oracle_key LIKE '%/11200110'
   OR c.oracle_key LIKE '%11200110';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200110'
   OR c.oracle_key = '11200110'
   OR c.oracle_key LIKE '%/11200110'
   OR c.oracle_key LIKE '%11200110';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200261'
   OR c.oracle_key = '11200261'
   OR c.oracle_key LIKE '%/11200261'
   OR c.oracle_key LIKE '%11200261';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200261'
   OR c.oracle_key = '11200261'
   OR c.oracle_key LIKE '%/11200261'
   OR c.oracle_key LIKE '%11200261';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200448'
   OR c.oracle_key = '11200448'
   OR c.oracle_key LIKE '%/11200448'
   OR c.oracle_key LIKE '%11200448';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200448'
   OR c.oracle_key = '11200448'
   OR c.oracle_key LIKE '%/11200448'
   OR c.oracle_key LIKE '%11200448';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200458'
   OR c.oracle_key = '11200458'
   OR c.oracle_key LIKE '%/11200458'
   OR c.oracle_key LIKE '%11200458';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200458'
   OR c.oracle_key = '11200458'
   OR c.oracle_key LIKE '%/11200458'
   OR c.oracle_key LIKE '%11200458';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200229'
   OR c.oracle_key = '11200229'
   OR c.oracle_key LIKE '%/11200229'
   OR c.oracle_key LIKE '%11200229';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200229'
   OR c.oracle_key = '11200229'
   OR c.oracle_key LIKE '%/11200229'
   OR c.oracle_key LIKE '%11200229';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200383'
   OR c.oracle_key = '11200383'
   OR c.oracle_key LIKE '%/11200383'
   OR c.oracle_key LIKE '%11200383';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200383'
   OR c.oracle_key = '11200383'
   OR c.oracle_key LIKE '%/11200383'
   OR c.oracle_key LIKE '%11200383';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200382'
   OR c.oracle_key = '11200382'
   OR c.oracle_key LIKE '%/11200382'
   OR c.oracle_key LIKE '%11200382';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200382'
   OR c.oracle_key = '11200382'
   OR c.oracle_key LIKE '%/11200382'
   OR c.oracle_key LIKE '%11200382';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200260'
   OR c.oracle_key = '11200260'
   OR c.oracle_key LIKE '%/11200260'
   OR c.oracle_key LIKE '%11200260';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200260'
   OR c.oracle_key = '11200260'
   OR c.oracle_key LIKE '%/11200260'
   OR c.oracle_key LIKE '%11200260';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200219'
   OR c.oracle_key = '11200219'
   OR c.oracle_key LIKE '%/11200219'
   OR c.oracle_key LIKE '%11200219';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200219'
   OR c.oracle_key = '11200219'
   OR c.oracle_key LIKE '%/11200219'
   OR c.oracle_key LIKE '%11200219';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200711'
   OR c.oracle_key = '11200711'
   OR c.oracle_key LIKE '%/11200711'
   OR c.oracle_key LIKE '%11200711';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200711'
   OR c.oracle_key = '11200711'
   OR c.oracle_key LIKE '%/11200711'
   OR c.oracle_key LIKE '%11200711';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200330'
   OR c.oracle_key = '11200330'
   OR c.oracle_key LIKE '%/11200330'
   OR c.oracle_key LIKE '%11200330';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200330'
   OR c.oracle_key = '11200330'
   OR c.oracle_key LIKE '%/11200330'
   OR c.oracle_key LIKE '%11200330';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200796'
   OR c.oracle_key = '11200796'
   OR c.oracle_key LIKE '%/11200796'
   OR c.oracle_key LIKE '%11200796';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200796'
   OR c.oracle_key = '11200796'
   OR c.oracle_key LIKE '%/11200796'
   OR c.oracle_key LIKE '%11200796';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200536'
   OR c.oracle_key = '11200536'
   OR c.oracle_key LIKE '%/11200536'
   OR c.oracle_key LIKE '%11200536';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200536'
   OR c.oracle_key = '11200536'
   OR c.oracle_key LIKE '%/11200536'
   OR c.oracle_key LIKE '%11200536';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200705'
   OR c.oracle_key = '11200705'
   OR c.oracle_key LIKE '%/11200705'
   OR c.oracle_key LIKE '%11200705';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200705'
   OR c.oracle_key = '11200705'
   OR c.oracle_key LIKE '%/11200705'
   OR c.oracle_key LIKE '%11200705';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مرج الحمام'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مرج الحمام')
WHERE c.code = '11200857'
   OR c.oracle_key = '11200857'
   OR c.oracle_key LIKE '%/11200857'
   OR c.oracle_key LIKE '%11200857';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200857'
   OR c.oracle_key = '11200857'
   OR c.oracle_key LIKE '%/11200857'
   OR c.oracle_key LIKE '%11200857';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'مكة مول'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'مكة مول')
WHERE c.code = '11200453'
   OR c.oracle_key = '11200453'
   OR c.oracle_key LIKE '%/11200453'
   OR c.oracle_key LIKE '%11200453';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200453'
   OR c.oracle_key = '11200453'
   OR c.oracle_key LIKE '%/11200453'
   OR c.oracle_key LIKE '%11200453';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ناعور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ناعور')
WHERE c.code = '11200447'
   OR c.oracle_key = '11200447'
   OR c.oracle_key LIKE '%/11200447'
   OR c.oracle_key LIKE '%11200447';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200447'
   OR c.oracle_key = '11200447'
   OR c.oracle_key LIKE '%/11200447'
   OR c.oracle_key LIKE '%11200447';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ناعور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ناعور')
WHERE c.code = '11200598'
   OR c.oracle_key = '11200598'
   OR c.oracle_key LIKE '%/11200598'
   OR c.oracle_key LIKE '%11200598';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200598'
   OR c.oracle_key = '11200598'
   OR c.oracle_key LIKE '%/11200598'
   OR c.oracle_key LIKE '%11200598';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'عمان الغربية'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'ناعور'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'ناعور')
WHERE c.code = '11200845'
   OR c.oracle_key = '11200845'
   OR c.oracle_key LIKE '%/11200845'
   OR c.oracle_key LIKE '%11200845';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'محمد حسن'
WHERE c.code = '11200845'
   OR c.oracle_key = '11200845'
   OR c.oracle_key LIKE '%/11200845'
   OR c.oracle_key LIKE '%11200845';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عوجان')
WHERE c.code = '11200094'
   OR c.oracle_key = '11200094'
   OR c.oracle_key LIKE '%/11200094'
   OR c.oracle_key LIKE '%11200094';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200094'
   OR c.oracle_key = '11200094'
   OR c.oracle_key LIKE '%/11200094'
   OR c.oracle_key LIKE '%11200094';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200432'
   OR c.oracle_key = '11200432'
   OR c.oracle_key LIKE '%/11200432'
   OR c.oracle_key LIKE '%11200432';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200432'
   OR c.oracle_key = '11200432'
   OR c.oracle_key LIKE '%/11200432'
   OR c.oracle_key LIKE '%11200432';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200220'
   OR c.oracle_key = '11200220'
   OR c.oracle_key LIKE '%/11200220'
   OR c.oracle_key LIKE '%11200220';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200220'
   OR c.oracle_key = '11200220'
   OR c.oracle_key LIKE '%/11200220'
   OR c.oracle_key LIKE '%11200220';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200418'
   OR c.oracle_key = '11200418'
   OR c.oracle_key LIKE '%/11200418'
   OR c.oracle_key LIKE '%11200418';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200418'
   OR c.oracle_key = '11200418'
   OR c.oracle_key LIKE '%/11200418'
   OR c.oracle_key LIKE '%11200418';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200231'
   OR c.oracle_key = '11200231'
   OR c.oracle_key LIKE '%/11200231'
   OR c.oracle_key LIKE '%11200231';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200231'
   OR c.oracle_key = '11200231'
   OR c.oracle_key LIKE '%/11200231'
   OR c.oracle_key LIKE '%11200231';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200479'
   OR c.oracle_key = '11200479'
   OR c.oracle_key LIKE '%/11200479'
   OR c.oracle_key LIKE '%11200479';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200479'
   OR c.oracle_key = '11200479'
   OR c.oracle_key LIKE '%/11200479'
   OR c.oracle_key LIKE '%11200479';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200221'
   OR c.oracle_key = '11200221'
   OR c.oracle_key LIKE '%/11200221'
   OR c.oracle_key LIKE '%11200221';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200221'
   OR c.oracle_key = '11200221'
   OR c.oracle_key LIKE '%/11200221'
   OR c.oracle_key LIKE '%11200221';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200700'
   OR c.oracle_key = '11200700'
   OR c.oracle_key LIKE '%/11200700'
   OR c.oracle_key LIKE '%11200700';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200700'
   OR c.oracle_key = '11200700'
   OR c.oracle_key LIKE '%/11200700'
   OR c.oracle_key LIKE '%11200700';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200771'
   OR c.oracle_key = '11200771'
   OR c.oracle_key LIKE '%/11200771'
   OR c.oracle_key LIKE '%11200771';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200771'
   OR c.oracle_key = '11200771'
   OR c.oracle_key LIKE '%/11200771'
   OR c.oracle_key LIKE '%11200771';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200663'
   OR c.oracle_key = '11200663'
   OR c.oracle_key LIKE '%/11200663'
   OR c.oracle_key LIKE '%11200663';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200663'
   OR c.oracle_key = '11200663'
   OR c.oracle_key LIKE '%/11200663'
   OR c.oracle_key LIKE '%11200663';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200748'
   OR c.oracle_key = '11200748'
   OR c.oracle_key LIKE '%/11200748'
   OR c.oracle_key LIKE '%11200748';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200748'
   OR c.oracle_key = '11200748'
   OR c.oracle_key LIKE '%/11200748'
   OR c.oracle_key LIKE '%11200748';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200433'
   OR c.oracle_key = '11200433'
   OR c.oracle_key LIKE '%/11200433'
   OR c.oracle_key LIKE '%11200433';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200433'
   OR c.oracle_key = '11200433'
   OR c.oracle_key LIKE '%/11200433'
   OR c.oracle_key LIKE '%11200433';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'المفرق'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'المفرق')
WHERE c.code = '11200465'
   OR c.oracle_key = '11200465'
   OR c.oracle_key LIKE '%/11200465'
   OR c.oracle_key LIKE '%11200465';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200465'
   OR c.oracle_key = '11200465'
   OR c.oracle_key LIKE '%/11200465'
   OR c.oracle_key LIKE '%11200465';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200082'
   OR c.oracle_key = '11200082'
   OR c.oracle_key LIKE '%/11200082'
   OR c.oracle_key LIKE '%11200082';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200082'
   OR c.oracle_key = '11200082'
   OR c.oracle_key LIKE '%/11200082'
   OR c.oracle_key LIKE '%11200082';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200081'
   OR c.oracle_key = '11200081'
   OR c.oracle_key LIKE '%/11200081'
   OR c.oracle_key LIKE '%11200081';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200081'
   OR c.oracle_key = '11200081'
   OR c.oracle_key LIKE '%/11200081'
   OR c.oracle_key LIKE '%11200081';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200232'
   OR c.oracle_key = '11200232'
   OR c.oracle_key LIKE '%/11200232'
   OR c.oracle_key LIKE '%11200232';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200232'
   OR c.oracle_key = '11200232'
   OR c.oracle_key LIKE '%/11200232'
   OR c.oracle_key LIKE '%11200232';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200117'
   OR c.oracle_key = '11200117'
   OR c.oracle_key LIKE '%/11200117'
   OR c.oracle_key LIKE '%11200117';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200117'
   OR c.oracle_key = '11200117'
   OR c.oracle_key LIKE '%/11200117'
   OR c.oracle_key LIKE '%11200117';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200080'
   OR c.oracle_key = '11200080'
   OR c.oracle_key LIKE '%/11200080'
   OR c.oracle_key LIKE '%11200080';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200080'
   OR c.oracle_key = '11200080'
   OR c.oracle_key LIKE '%/11200080'
   OR c.oracle_key LIKE '%11200080';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200628'
   OR c.oracle_key = '11200628'
   OR c.oracle_key LIKE '%/11200628'
   OR c.oracle_key LIKE '%11200628';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200628'
   OR c.oracle_key = '11200628'
   OR c.oracle_key LIKE '%/11200628'
   OR c.oracle_key LIKE '%11200628';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200314'
   OR c.oracle_key = '11200314'
   OR c.oracle_key LIKE '%/11200314'
   OR c.oracle_key LIKE '%11200314';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200314'
   OR c.oracle_key = '11200314'
   OR c.oracle_key LIKE '%/11200314'
   OR c.oracle_key LIKE '%11200314';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200230'
   OR c.oracle_key = '11200230'
   OR c.oracle_key LIKE '%/11200230'
   OR c.oracle_key LIKE '%11200230';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200230'
   OR c.oracle_key = '11200230'
   OR c.oracle_key LIKE '%/11200230'
   OR c.oracle_key LIKE '%11200230';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200823'
   OR c.oracle_key = '11200823'
   OR c.oracle_key LIKE '%/11200823'
   OR c.oracle_key LIKE '%11200823';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200823'
   OR c.oracle_key = '11200823'
   OR c.oracle_key LIKE '%/11200823'
   OR c.oracle_key LIKE '%11200823';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200363'
   OR c.oracle_key = '11200363'
   OR c.oracle_key LIKE '%/11200363'
   OR c.oracle_key LIKE '%11200363';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200363'
   OR c.oracle_key = '11200363'
   OR c.oracle_key LIKE '%/11200363'
   OR c.oracle_key LIKE '%11200363';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200450'
   OR c.oracle_key = '11200450'
   OR c.oracle_key LIKE '%/11200450'
   OR c.oracle_key LIKE '%11200450';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200450'
   OR c.oracle_key = '11200450'
   OR c.oracle_key LIKE '%/11200450'
   OR c.oracle_key LIKE '%11200450';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200853'
   OR c.oracle_key = '11200853'
   OR c.oracle_key LIKE '%/11200853'
   OR c.oracle_key LIKE '%11200853';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200853'
   OR c.oracle_key = '11200853'
   OR c.oracle_key LIKE '%/11200853'
   OR c.oracle_key LIKE '%11200853';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200873'
   OR c.oracle_key = '11200873'
   OR c.oracle_key LIKE '%/11200873'
   OR c.oracle_key LIKE '%11200873';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200873'
   OR c.oracle_key = '11200873'
   OR c.oracle_key LIKE '%/11200873'
   OR c.oracle_key LIKE '%11200873';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200112'
   OR c.oracle_key = '11200112'
   OR c.oracle_key LIKE '%/11200112'
   OR c.oracle_key LIKE '%11200112';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200112'
   OR c.oracle_key = '11200112'
   OR c.oracle_key LIKE '%/11200112'
   OR c.oracle_key LIKE '%11200112';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200386'
   OR c.oracle_key = '11200386'
   OR c.oracle_key LIKE '%/11200386'
   OR c.oracle_key LIKE '%11200386';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200386'
   OR c.oracle_key = '11200386'
   OR c.oracle_key LIKE '%/11200386'
   OR c.oracle_key LIKE '%11200386';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200101'
   OR c.oracle_key = '11200101'
   OR c.oracle_key LIKE '%/11200101'
   OR c.oracle_key LIKE '%11200101';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200101'
   OR c.oracle_key = '11200101'
   OR c.oracle_key LIKE '%/11200101'
   OR c.oracle_key LIKE '%11200101';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200535'
   OR c.oracle_key = '11200535'
   OR c.oracle_key LIKE '%/11200535'
   OR c.oracle_key LIKE '%11200535';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200535'
   OR c.oracle_key = '11200535'
   OR c.oracle_key LIKE '%/11200535'
   OR c.oracle_key LIKE '%11200535';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200362'
   OR c.oracle_key = '11200362'
   OR c.oracle_key LIKE '%/11200362'
   OR c.oracle_key LIKE '%11200362';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200362'
   OR c.oracle_key = '11200362'
   OR c.oracle_key LIKE '%/11200362'
   OR c.oracle_key LIKE '%11200362';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200111'
   OR c.oracle_key = '11200111'
   OR c.oracle_key LIKE '%/11200111'
   OR c.oracle_key LIKE '%11200111';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200111'
   OR c.oracle_key = '11200111'
   OR c.oracle_key LIKE '%/11200111'
   OR c.oracle_key LIKE '%11200111';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200276'
   OR c.oracle_key = '11200276'
   OR c.oracle_key LIKE '%/11200276'
   OR c.oracle_key LIKE '%11200276';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200276'
   OR c.oracle_key = '11200276'
   OR c.oracle_key LIKE '%/11200276'
   OR c.oracle_key LIKE '%11200276';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200309'
   OR c.oracle_key = '11200309'
   OR c.oracle_key LIKE '%/11200309'
   OR c.oracle_key LIKE '%11200309';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200309'
   OR c.oracle_key = '11200309'
   OR c.oracle_key LIKE '%/11200309'
   OR c.oracle_key LIKE '%11200309';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200485'
   OR c.oracle_key = '11200485'
   OR c.oracle_key LIKE '%/11200485'
   OR c.oracle_key LIKE '%11200485';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200485'
   OR c.oracle_key = '11200485'
   OR c.oracle_key LIKE '%/11200485'
   OR c.oracle_key LIKE '%11200485';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200783'
   OR c.oracle_key = '11200783'
   OR c.oracle_key LIKE '%/11200783'
   OR c.oracle_key LIKE '%11200783';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200783'
   OR c.oracle_key = '11200783'
   OR c.oracle_key LIKE '%/11200783'
   OR c.oracle_key LIKE '%11200783';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200275'
   OR c.oracle_key = '11200275'
   OR c.oracle_key LIKE '%/11200275'
   OR c.oracle_key LIKE '%11200275';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200275'
   OR c.oracle_key = '11200275'
   OR c.oracle_key LIKE '%/11200275'
   OR c.oracle_key LIKE '%11200275';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200568'
   OR c.oracle_key = '11200568'
   OR c.oracle_key LIKE '%/11200568'
   OR c.oracle_key LIKE '%11200568';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200568'
   OR c.oracle_key = '11200568'
   OR c.oracle_key LIKE '%/11200568'
   OR c.oracle_key LIKE '%11200568';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200328'
   OR c.oracle_key = '11200328'
   OR c.oracle_key LIKE '%/11200328'
   OR c.oracle_key LIKE '%11200328';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200328'
   OR c.oracle_key = '11200328'
   OR c.oracle_key LIKE '%/11200328'
   OR c.oracle_key LIKE '%11200328';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200171'
   OR c.oracle_key = '11200171'
   OR c.oracle_key LIKE '%/11200171'
   OR c.oracle_key LIKE '%11200171';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200171'
   OR c.oracle_key = '11200171'
   OR c.oracle_key LIKE '%/11200171'
   OR c.oracle_key LIKE '%11200171';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200461'
   OR c.oracle_key = '11200461'
   OR c.oracle_key LIKE '%/11200461'
   OR c.oracle_key LIKE '%11200461';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200461'
   OR c.oracle_key = '11200461'
   OR c.oracle_key LIKE '%/11200461'
   OR c.oracle_key LIKE '%11200461';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200364'
   OR c.oracle_key = '11200364'
   OR c.oracle_key LIKE '%/11200364'
   OR c.oracle_key LIKE '%11200364';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200364'
   OR c.oracle_key = '11200364'
   OR c.oracle_key LIKE '%/11200364'
   OR c.oracle_key LIKE '%11200364';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200492'
   OR c.oracle_key = '11200492'
   OR c.oracle_key LIKE '%/11200492'
   OR c.oracle_key LIKE '%11200492';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200492'
   OR c.oracle_key = '11200492'
   OR c.oracle_key LIKE '%/11200492'
   OR c.oracle_key LIKE '%11200492';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200264'
   OR c.oracle_key = '11200264'
   OR c.oracle_key LIKE '%/11200264'
   OR c.oracle_key LIKE '%11200264';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200264'
   OR c.oracle_key = '11200264'
   OR c.oracle_key LIKE '%/11200264'
   OR c.oracle_key LIKE '%11200264';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200412'
   OR c.oracle_key = '11200412'
   OR c.oracle_key LIKE '%/11200412'
   OR c.oracle_key LIKE '%11200412';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200412'
   OR c.oracle_key = '11200412'
   OR c.oracle_key LIKE '%/11200412'
   OR c.oracle_key LIKE '%11200412';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200557'
   OR c.oracle_key = '11200557'
   OR c.oracle_key LIKE '%/11200557'
   OR c.oracle_key LIKE '%11200557';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200557'
   OR c.oracle_key = '11200557'
   OR c.oracle_key LIKE '%/11200557'
   OR c.oracle_key LIKE '%11200557';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200652'
   OR c.oracle_key = '11200652'
   OR c.oracle_key LIKE '%/11200652'
   OR c.oracle_key LIKE '%11200652';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200652'
   OR c.oracle_key = '11200652'
   OR c.oracle_key LIKE '%/11200652'
   OR c.oracle_key LIKE '%11200652';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200852'
   OR c.oracle_key = '11200852'
   OR c.oracle_key LIKE '%/11200852'
   OR c.oracle_key LIKE '%11200852';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200852'
   OR c.oracle_key = '11200852'
   OR c.oracle_key LIKE '%/11200852'
   OR c.oracle_key LIKE '%11200852';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الزرقاء'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الزرقاء')
WHERE c.code = '11200667'
   OR c.oracle_key = '11200667'
   OR c.oracle_key LIKE '%/11200667'
   OR c.oracle_key LIKE '%11200667';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200667'
   OR c.oracle_key = '11200667'
   OR c.oracle_key LIKE '%/11200667'
   OR c.oracle_key LIKE '%11200667';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الخالدية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الخالدية')
WHERE c.code = '11200464'
   OR c.oracle_key = '11200464'
   OR c.oracle_key LIKE '%/11200464'
   OR c.oracle_key LIKE '%11200464';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200464'
   OR c.oracle_key = '11200464'
   OR c.oracle_key LIKE '%/11200464'
   OR c.oracle_key LIKE '%11200464';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الخالدية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الخالدية')
WHERE c.code = '11200413'
   OR c.oracle_key = '11200413'
   OR c.oracle_key LIKE '%/11200413'
   OR c.oracle_key LIKE '%11200413';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200413'
   OR c.oracle_key = '11200413'
   OR c.oracle_key LIKE '%/11200413'
   OR c.oracle_key LIKE '%11200413';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'عوجان'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'عوجان')
WHERE c.code = '11200366'
   OR c.oracle_key = '11200366'
   OR c.oracle_key LIKE '%/11200366'
   OR c.oracle_key LIKE '%11200366';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200366'
   OR c.oracle_key = '11200366'
   OR c.oracle_key LIKE '%/11200366'
   OR c.oracle_key LIKE '%11200366';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'بلعما'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'بلعما')
WHERE c.code = '11200865'
   OR c.oracle_key = '11200865'
   OR c.oracle_key LIKE '%/11200865'
   OR c.oracle_key LIKE '%11200865';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200865'
   OR c.oracle_key = '11200865'
   OR c.oracle_key LIKE '%/11200865'
   OR c.oracle_key LIKE '%11200865';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمية')
WHERE c.code = '11200233'
   OR c.oracle_key = '11200233'
   OR c.oracle_key LIKE '%/11200233'
   OR c.oracle_key LIKE '%11200233';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200233'
   OR c.oracle_key = '11200233'
   OR c.oracle_key LIKE '%/11200233'
   OR c.oracle_key LIKE '%11200233';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الهاشمية'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الهاشمية')
WHERE c.code = '11200653'
   OR c.oracle_key = '11200653'
   OR c.oracle_key LIKE '%/11200653'
   OR c.oracle_key LIKE '%11200653';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200653'
   OR c.oracle_key = '11200653'
   OR c.oracle_key LIKE '%/11200653'
   OR c.oracle_key LIKE '%11200653';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الضليل'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الضليل')
WHERE c.code = '11200345'
   OR c.oracle_key = '11200345'
   OR c.oracle_key LIKE '%/11200345'
   OR c.oracle_key LIKE '%11200345';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200345'
   OR c.oracle_key = '11200345'
   OR c.oracle_key LIKE '%/11200345'
   OR c.oracle_key LIKE '%11200345';
UPDATE crm_customer c
INNER JOIN crm_region r ON r.name_ar = 'الزرقاء'
LEFT JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = 'الضليل'
LEFT JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
SET
  c.region_id = r.id,
  c.region_address_id = a.id,
  c.sales_rep_id = COALESCE(sr.id, c.sales_rep_id),
  c.address_ar = COALESCE(NULLIF(TRIM(c.address_ar), ''), 'الضليل')
WHERE c.code = '11200346'
   OR c.oracle_key = '11200346'
   OR c.oracle_key LIKE '%/11200346'
   OR c.oracle_key LIKE '%11200346';
INSERT IGNORE INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, sr.id, 0
FROM crm_customer c
INNER JOIN crm_sales_rep sr ON sr.name_ar = 'وجدي'
WHERE c.code = '11200346'
   OR c.oracle_key = '11200346'
   OR c.oracle_key LIKE '%/11200346'
   OR c.oracle_key LIKE '%11200346';

SET SQL_SAFE_UPDATES = 1;
-- تحقق:
SELECT COUNT(*) AS regions FROM crm_region;
SELECT COUNT(*) AS addresses FROM crm_region_address;
SELECT COUNT(*) AS rep_addr FROM crm_sales_rep_region_address;
SELECT COUNT(*) AS customers_with_region FROM crm_customer WHERE region_id IS NOT NULL AND region_id > 0;
SELECT COUNT(*) AS customers_with_address FROM crm_customer WHERE region_address_id IS NOT NULL AND region_address_id > 0;
