-- شجرة حسابات شركة صغيرة + قواعد ربط إضافية (التنفيذ الكامل عبر acc_coa_bootstrap.php)

CREATE TABLE IF NOT EXISTS acc_system_meta (
  meta_key   VARCHAR(40) NOT NULL PRIMARY KEY,
  meta_value VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES
('salaries_expense', 'رواتب وأجور (مصروف)', 'إثبات الرواتب: مدين — أو عند الصرف من سند قيد', 82),
('salaries_payable', 'رواتب مستحقة (خصوم)', 'إثبات الرواتب قبل الدفع: دائن — ثم عند الدفع: مدين مع دائن الصندوق', 83),
('purchase_returns', 'مردودات المشتريات', 'دائن عند مردود شراء (إن وُجد حساب مخصص)', 56);
