-- نوع الحركة: هل يُنشئ قيداً محاسبياً عند الترحيل (النقل بين المستودعات عادةً لا)

ALTER TABLE inv_movement_type
  ADD COLUMN affects_gl TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

UPDATE inv_movement_type SET affects_gl = 0 WHERE code = 'transfer';
UPDATE inv_movement_type SET affects_gl = 1 WHERE code IN ('adjust_in', 'adjust_out', 'disposal');
