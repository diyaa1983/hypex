-- ربط الحسابات + ترحيل الأستاذ العام التلقائي

ALTER TABLE acc_journal_entry ADD COLUMN ref_type VARCHAR(40) NULL;
ALTER TABLE acc_journal_entry ADD COLUMN ref_id INT UNSIGNED NULL;
ALTER TABLE acc_journal_entry ADD COLUMN source ENUM('manual','auto') NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS acc_posting_setting (
  rule_code   VARCHAR(40) NOT NULL PRIMARY KEY,
  label_ar    VARCHAR(200) NOT NULL,
  hint_ar     VARCHAR(500) NULL,
  account_id  INT UNSIGNED NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_aps_acc FOREIGN KEY (account_id) REFERENCES acc_account(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES
('ar_customers', 'ذمم العملاء (أصول)', 'فواتير مبيعات آجلة وسندات قبض', 10),
('ap_suppliers', 'ذمم الموردين (خصوم)', 'فواتير شراء آجلة وسندات صرف للمورد', 20),
('cash', 'الصندوق / النقدية', 'مبيعات ومشتريات نقدية وسندات نقدية', 30),
('bank', 'البنك', 'شيكات وسندات على البنك (اختياري)', 35),
('sales_revenue', 'إيرادات المبيعات', 'دائن عند فاتورة البيع', 40),
('sales_returns', 'مردودات المبيعات', 'مدين عند مردود البيع', 45),
('purchases', 'المشتريات / مصروف', 'مدين عند فاتورة الشراء (أو استخدم المخزون)', 50),
('purchase_returns', 'مردودات المشتريات', 'دائن عند مردود الشراء', 55),
('inventory', 'المخزون', 'مدين عند شراء بضاعة للمخزون', 60),
('cogs', 'تكلفة البضاعة المباعة', 'للاستخدام لاحقاً مع تكلفة المخزون', 65),
('vat_output', 'ضريبة مبيعات مستحقة', 'دائن ضريبة فواتير البيع', 70),
('vat_input', 'ضريبة مشتريات', 'مدين ضريبة فواتير الشراء', 75),
('misc_expense', 'مصروفات عامة', 'سند صرف لطرف «أخرى»', 80);

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '12'
SET s.account_id = a.id
WHERE s.rule_code = 'ar_customers' AND s.account_id IS NULL;

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '21'
SET s.account_id = a.id
WHERE s.rule_code = 'ap_suppliers' AND s.account_id IS NULL;

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '111'
SET s.account_id = a.id
WHERE s.rule_code = 'cash' AND s.account_id IS NULL;

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '112'
SET s.account_id = a.id
WHERE s.rule_code = 'bank' AND s.account_id IS NULL;

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '41'
SET s.account_id = a.id
WHERE s.rule_code = 'sales_revenue' AND s.account_id IS NULL;

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '51'
SET s.account_id = a.id
WHERE s.rule_code IN ('purchases', 'cogs') AND s.account_id IS NULL;

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '13'
SET s.account_id = a.id
WHERE s.rule_code = 'inventory' AND s.account_id IS NULL;

UPDATE acc_posting_setting s
INNER JOIN acc_account a ON a.code = '52'
SET s.account_id = a.id
WHERE s.rule_code = 'misc_expense' AND s.account_id IS NULL;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'account_mapping', 'ربط الحسابات المحاسبية', 'screen', 116
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'account_mapping');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'account_mapping'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code IN ('chart_of_accounts', 'journal_entries', 'journal_voucher')
INNER JOIN sys_screen s ON s.code = 'account_mapping'
WHERE gp.allowed = 1;
