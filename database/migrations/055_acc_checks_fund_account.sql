-- حساب صندوق الشيكات (صرف شيك: مدين الصندوق / دائن صندوق الشيكات)

INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
SELECT '113', 'صندوق الشيكات', p.id, 'asset', 1, 1, 13
FROM acc_account p
WHERE p.code = '11'
LIMIT 1;
