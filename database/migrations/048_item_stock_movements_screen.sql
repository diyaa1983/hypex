-- تحويل «كشف حركات مادة» من تقرير إلى شاشة مستودعات
UPDATE sys_screen
SET code = 'item_stock_movements',
    name_ar = 'كشف حركات مادة',
    screen_type = 'screen'
WHERE code = 'report_inventory';
