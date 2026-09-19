-- إعادة تسمية تقرير المخزون إلى كشف حركات مادة (نفس كود الشاشة report_inventory)
UPDATE sys_screen
SET name_ar = 'كشف حركات مادة'
WHERE code = 'report_inventory';
