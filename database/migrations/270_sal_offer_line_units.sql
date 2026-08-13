-- وحدات كمية العرض والكمية الإضافية في بنود العروض
SET NAMES utf8mb4;

ALTER TABLE sal_offer_line
  ADD COLUMN IF NOT EXISTS unit_id INT UNSIGNED NULL AFTER discount_pct,
  ADD COLUMN IF NOT EXISTS unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1 AFTER unit_id,
  ADD COLUMN IF NOT EXISTS bonus_unit_id INT UNSIGNED NULL AFTER unit_factor,
  ADD COLUMN IF NOT EXISTS bonus_unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1 AFTER bonus_unit_id;
