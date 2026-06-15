-- تقارير محاسبية: دفتر الأستاذ، قائمة الدخل، الميزانية

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_general_ledger', 'دفتر الأستاذ العام', 'report', 235
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_general_ledger');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_income_statement', 'قائمة الدخل', 'report', 245
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_income_statement');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_balance_sheet', 'الميزانية العمومية', 'report', 250
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_balance_sheet');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('report_journal', 'report_trial_balance', 'journal_entries')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('report_general_ledger', 'report_income_statement', 'report_balance_sheet');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('report_general_ledger', 'report_income_statement', 'report_balance_sheet')
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
