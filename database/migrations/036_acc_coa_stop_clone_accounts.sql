-- إيقاف نسخ الضبط التلقائي (1200، 1201، …) — التنفيذ الكامل عبر acc_coa_bootstrap.php v3

UPDATE acc_account a
INNER JOIN acc_account c12 ON c12.code = '12'
SET a.is_active = 0
WHERE a.is_active = 1
  AND a.is_leaf = 1
  AND a.parent_id = c12.parent_id
  AND a.code REGEXP '^12[0-9]+$'
  AND a.code <> '12'
  AND a.name_ar = c12.name_ar
  AND NOT EXISTS (SELECT 1 FROM acc_journal_line j WHERE j.account_id = a.id)
  AND NOT EXISTS (SELECT 1 FROM acc_posting_setting p WHERE p.account_id = a.id);

UPDATE acc_account SET is_leaf = 1, is_active = 1
WHERE code = '12'
  AND is_leaf = 0
  AND NOT EXISTS (SELECT 1 FROM acc_account ch WHERE ch.parent_id = acc_account.id AND ch.is_active = 1);

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '12' AND a.is_leaf = 1 AND a.is_active = 1
SET s.account_id = a.id
WHERE s.rule_code = 'ar_customers';
