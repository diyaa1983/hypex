-- هاتف: كشف حساب عميل/مورد + سند قبض

INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('m_party_statement', 'هاتف — كشف حساب', 'screen', 9020),
('m_receipt', 'هاتف — سند قبض', 'screen', 9030);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('m_party_statement', 'm_receipt')
WHERE g.code = 'MOBILE';
