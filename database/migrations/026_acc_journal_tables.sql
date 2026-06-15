-- قيود محاسبية + دليل حسابات (إن لم يكن موجوداً) — لا يحذف بيانات موجودة

CREATE TABLE IF NOT EXISTS acc_account (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(32) NOT NULL UNIQUE,
  name_ar        VARCHAR(200) NOT NULL,
  parent_id      INT UNSIGNED NULL,
  account_type   ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  is_leaf        TINYINT(1) NOT NULL DEFAULT 1,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  sort_order     INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_acc_parent FOREIGN KEY (parent_id) REFERENCES acc_account(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order) VALUES
('1', 'الأصول', NULL, 'asset', 0, 1),
('2', 'الخصوم', NULL, 'liability', 0, 2),
('3', 'حقوق الملكية', NULL, 'equity', 0, 3),
('4', 'الإيرادات', NULL, 'revenue', 0, 4),
('5', 'المصروفات', NULL, 'expense', 0, 5);

INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '11', 'النقدية والبنوك', p.id, 'asset', 0, 10 FROM acc_account p WHERE p.code = '1' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '12', 'العملاء (ذمم مدينة)', p.id, 'asset', 1, 20 FROM acc_account p WHERE p.code = '1' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '13', 'المخزون', p.id, 'asset', 1, 30 FROM acc_account p WHERE p.code = '1' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '21', 'الموردون (ذمم دائنة)', p.id, 'liability', 1, 10 FROM acc_account p WHERE p.code = '2' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '41', 'إيرادات المبيعات', p.id, 'revenue', 1, 10 FROM acc_account p WHERE p.code = '4' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '51', 'مشتريات وتوريدات', p.id, 'expense', 1, 10 FROM acc_account p WHERE p.code = '5' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '52', 'مصروفات عمومية وإدارية', p.id, 'expense', 0, 20 FROM acc_account p WHERE p.code = '5' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '54', 'تكلفة البضاعة المباعة', p.id, 'expense', 1, 40 FROM acc_account p WHERE p.code = '5' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '111', 'الصندوق', p.id, 'asset', 1, 11 FROM acc_account p WHERE p.code = '11' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '112', 'البنك', p.id, 'asset', 1, 12 FROM acc_account p WHERE p.code = '11' LIMIT 1;
INSERT IGNORE INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, sort_order)
SELECT '113', 'صندوق الشيكات', p.id, 'asset', 1, 13 FROM acc_account p WHERE p.code = '11' LIMIT 1;

CREATE TABLE IF NOT EXISTS acc_journal_entry (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no       VARCHAR(40) NOT NULL,
  entry_date     DATE NOT NULL,
  description_ar VARCHAR(500) NULL,
  status         ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acc_je_no (entry_no),
  CONSTRAINT fk_aje_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acc_journal_line (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id  INT UNSIGNED NOT NULL,
  account_id  INT UNSIGNED NOT NULL,
  debit       DECIMAL(18,6) NOT NULL DEFAULT 0,
  credit      DECIMAL(18,6) NOT NULL DEFAULT 0,
  memo        VARCHAR(255) NULL,
  CONSTRAINT fk_ajl_j FOREIGN KEY (journal_id) REFERENCES acc_journal_entry(id) ON DELETE CASCADE,
  CONSTRAINT fk_ajl_a FOREIGN KEY (account_id) REFERENCES acc_account(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
