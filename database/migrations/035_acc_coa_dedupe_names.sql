-- توحيد أسماء الحسابات الأساسية (للقواعد القديمة من ترحيل 026) — لا يحذف حسابات

UPDATE acc_account SET name_ar = 'العملاء (ذمم مدينة)' WHERE code = '12' AND name_ar IN ('العملاء', 'عملاء');
UPDATE acc_account SET name_ar = 'الموردون (ذمم دائنة)' WHERE code = '21' AND name_ar IN ('الموردون', 'موردون');
UPDATE acc_account SET name_ar = 'إيرادات المبيعات' WHERE code = '41' AND name_ar IN ('مبيعات', 'مبيعات', 'إيراد مبيعات');
UPDATE acc_account SET name_ar = 'مشتريات وتوريدات' WHERE code = '51' AND name_ar IN ('تكلفة البضاعة المباعة', 'مشتريات', 'مشتريات وتوريدات');
UPDATE acc_account SET name_ar = 'مصروفات عمومية وإدارية', code = '52' WHERE code = '52' AND is_leaf = 0 AND name_ar LIKE '%عموم%';
