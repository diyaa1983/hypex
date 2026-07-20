-- مؤشرات ولوحة القيود اليومية المحاسبية في الشاشة الرئيسية

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_kpi_journal_daily', 'مؤشرات القيود اليومية (لوحة التحكم)', 'dashboard', 20
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_kpi_journal_daily');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_panel_journal_daily', 'قيود اليومية المحاسبية (لوحة التحكم)', 'dashboard', 21
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_panel_journal_daily');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'journal_entries'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code IN ('dashboard_kpi_journal_daily', 'dashboard_panel_journal_daily');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('dashboard_kpi_journal_daily', 'dashboard_panel_journal_daily')
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
