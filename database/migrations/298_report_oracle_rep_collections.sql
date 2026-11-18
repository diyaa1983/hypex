-- كشف تحصيلات المندوب خلال فترة (Oracle)
INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'report_oracle_rep_collections', 'كشف تحصيلات المندوب خلال فترة Oracle', 'report', 254
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_oracle_rep_collections');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_old
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
INNER JOIN sys_screen s_new ON s_new.code = 'report_oracle_rep_collections'
WHERE s_old.code IN ('report_oracle_rep_net_sales', 'report_sales_rep_customers', 'sales_reps');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'report_oracle_rep_collections'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
