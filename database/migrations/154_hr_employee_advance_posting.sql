-- ترحيل سلف الموظفين: حالة الترحيل + حساب الذمة + صلاحيات
-- (بدون IF NOT EXISTS — متوافق مع MySQL 5.7 / MariaDB القديمة)

ALTER TABLE hr_employee_advance
    ADD COLUMN is_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN posted_at DATETIME NULL AFTER is_posted,
    ADD COLUMN posted_by INT UNSIGNED NULL AFTER posted_at;

-- السلف المقتطعة سابقاً من الراتب تُعتبر مرحّلة
UPDATE hr_employee_advance a
SET a.is_posted = 1,
    a.posted_at = COALESCE(a.updated_at, a.created_at, NOW())
WHERE a.is_posted = 0
  AND EXISTS (
      SELECT 1 FROM hr_salary_advance_deduction sad WHERE sad.advance_id = a.id LIMIT 1
  );

INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '1215', 'سلف موظفين (ذمة)', p.id, 'asset', 1, 1, 45
FROM acc_account p
WHERE p.is_active = 1 AND p.is_leaf = 0 AND p.account_type = 'asset'
  AND (p.code = '1' OR p.parent_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM acc_account x WHERE x.is_active = 1 AND x.code = '1215' LIMIT 1)
ORDER BY (p.code = '1') DESC, p.id ASC
LIMIT 1;

INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order)
SELECT 'hr_employee_advance_receivable', 'ذمة سلف الموظفين', 'مدين عند ترحيل السلفة — مستحق على الموظف. دائن عند اقتطاعها من الراتب', 88
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM acc_posting_setting WHERE rule_code = 'hr_employee_advance_receivable');

UPDATE acc_posting_setting ps
INNER JOIN acc_account a ON a.is_active = 1 AND a.is_leaf = 1 AND a.code = '1215'
SET ps.account_id = a.id
WHERE ps.rule_code = 'hr_employee_advance_receivable'
  AND (ps.account_id IS NULL OR ps.account_id = 0);

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_post_employee_advance', 'ترحيل سلفة موظف', 'screen', 9112
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_post_employee_advance');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'action_unpost_employee_advance', 'فك ترحيل سلفة موظف', 'screen', 9113
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'action_unpost_employee_advance');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
CROSS JOIN sys_screen s
WHERE g.code = 'ADMINS'
  AND s.code IN ('action_post_employee_advance', 'action_unpost_employee_advance');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'hr_salaries'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('action_post_employee_advance', 'action_unpost_employee_advance');
