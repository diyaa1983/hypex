-- إلزام المندوب بالتواجد ضمن نطاق موقع العميل عند الفاتورة/طلب الشراء (اختياري من الإعدادات)
ALTER TABLE sys_company_settings
    ADD COLUMN sales_rep_visit_geofence TINYINT(1) NOT NULL DEFAULT 0;
