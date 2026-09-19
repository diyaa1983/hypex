-- مجموعة «مستحقات الموظfين» تحت الخصوم — تجميع رواتb / ضمان / ضريبة

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '24', 'مستحقات الموظfين', p.id, 'liability', 0, 1, 25
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'liability'
  AND (p.code = '2' OR p.parent_id IS NULL)
  AND NOT EXISTS (
      SELECT 1 FROM acc_account x
      WHERE x.is_active = 1 AND x.account_type = 'liability'
        AND (x.code = '24' OR x.name_ar LIKE '%مستحقات%موظ%')
      LIMIT 1
  )
ORDER BY (p.code = '2') DESC, p.id ASC
LIMIT 1;

UPDATE acc_account c
INNER JOIN acc_account g ON g.is_active = 1 AND g.is_leaf = 0 AND g.account_type = 'liability'
  AND (g.code = '24' OR g.name_ar LIKE '%مستحقات%موظ%')
SET c.parent_id = g.id
WHERE c.is_active = 1 AND c.is_leaf = 1 AND c.account_type = 'liability'
  AND (
      c.code IN ('23', '2006', '2007')
      OR (c.name_ar LIKE '%رواتب%مستحق%' AND c.name_ar NOT LIKE '%ضمان%')
      OR c.name_ar LIKE '%ضمان%مستحق%'
      OR c.name_ar LIKE '%ضريبة%دخل%مستحق%'
  )
  AND (c.parent_id IS NULL OR c.parent_id <> g.id);

UPDATE acc_account SET is_leaf = 0
WHERE is_active = 1 AND account_type = 'liability'
  AND (code = '24' OR name_ar LIKE '%مستحقات%موظ%');
