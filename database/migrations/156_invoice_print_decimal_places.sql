-- خانات عشرية عند طباعة فواتير البيع/الشراء (منفصلة عن العرض على الشاشة)
ALTER TABLE sys_company_settings
  ADD COLUMN invoice_print_decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2
  AFTER invoice_unit_price_decimal_places;

ALTER TABLE sys_company_settings
  ADD COLUMN invoice_print_unit_price_decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2
  AFTER invoice_print_decimal_places;

UPDATE sys_company_settings
SET invoice_print_decimal_places = decimal_places,
    invoice_print_unit_price_decimal_places = invoice_unit_price_decimal_places
WHERE id = 1;
