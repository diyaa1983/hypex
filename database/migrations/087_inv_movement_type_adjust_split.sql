-- فصل نوع adjustment القديم إلى تعديل زيادة / تعديل نقصان

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'adjust_in', 'تعديل مخزون (زيادة)', 'إدخال كميات إضافية إلى المستودع',
       COALESCE((SELECT post_auto FROM inv_movement_type WHERE code = 'adjustment' LIMIT 1), 0),
       COALESCE((SELECT post_manual FROM inv_movement_type WHERE code = 'adjustment' LIMIT 1), 1),
       COALESCE((SELECT is_active FROM inv_movement_type WHERE code = 'adjustment' LIMIT 1), 1),
       10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjust_in');

INSERT INTO inv_movement_type (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
SELECT 'adjust_out', 'تعديل مخزون (نقصان)', 'خصم كميات من المستودع',
       COALESCE((SELECT post_auto FROM inv_movement_type WHERE code = 'adjustment' LIMIT 1), 0),
       COALESCE((SELECT post_manual FROM inv_movement_type WHERE code = 'adjustment' LIMIT 1), 1),
       COALESCE((SELECT is_active FROM inv_movement_type WHERE code = 'adjustment' LIMIT 1), 1),
       15
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_movement_type WHERE code = 'adjust_out');

DELETE FROM inv_movement_type WHERE code = 'adjustment';
