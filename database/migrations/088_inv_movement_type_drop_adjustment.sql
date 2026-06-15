-- إزالة نوع adjustment القديم (بعد فصل adjust_in / adjust_out)

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'adjust_in', 'تعديل مخزون (زيادة)', 'إدخال كميات إضافية إلى المستودع', 0, 1, 1, 10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjust_in')
  AND EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjustment');

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'adjust_out', 'تعديل مخزون (نقصان)', 'خصم كميات من المستودع', 0, 1, 1, 15
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjust_out')
  AND EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjustment');

DELETE FROM inv_movement_type WHERE code = 'adjustment';
