-- خانات عشرية لسعر الوحدة في فواتير البيع/الشراء (منفصلة عن إعداد النظام العام)
ALTER TABLE sys_company_settings
  ADD COLUMN invoice_unit_price_decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2
  AFTER decimal_places;

UPDATE sys_company_settings
SET invoice_unit_price_decimal_places = decimal_places
WHERE id = 1;
