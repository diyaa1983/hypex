-- إشعارات مدينة ودائنة

CREATE TABLE IF NOT EXISTS fin_debit_note (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_no       VARCHAR(40) NOT NULL,
  note_date     DATE NOT NULL,
  party_type    ENUM('customer','supplier') NOT NULL,
  party_id      INT UNSIGNED NOT NULL,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  reason        VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_dn_no (note_no),
  CONSTRAINT fk_fdn_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_debit_note_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id       INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NULL,
  description_ar VARCHAR(255) NULL,
  qty           DECIMAL(18,6) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_total    DECIMAL(18,6) NOT NULL,
  CONSTRAINT fk_fdnl_note FOREIGN KEY (note_id) REFERENCES fin_debit_note(id) ON DELETE CASCADE,
  CONSTRAINT fk_fdnl_item FOREIGN KEY (item_id) REFERENCES inv_item(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_credit_note (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_no       VARCHAR(40) NOT NULL,
  note_date     DATE NOT NULL,
  party_type    ENUM('customer','supplier') NOT NULL,
  party_id      INT UNSIGNED NOT NULL,
  total         DECIMAL(18,6) NOT NULL DEFAULT 0,
  reason        VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_cn_no (note_no),
  CONSTRAINT fk_fcn_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_credit_note_line (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id       INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NULL,
  description_ar VARCHAR(255) NULL,
  qty           DECIMAL(18,6) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(18,6) NOT NULL DEFAULT 0,
  line_total    DECIMAL(18,6) NOT NULL,
  CONSTRAINT fk_fcnl_note FOREIGN KEY (note_id) REFERENCES fin_credit_note(id) ON DELETE CASCADE,
  CONSTRAINT fk_fcnl_item FOREIGN KEY (item_id) REFERENCES inv_item(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'debit_notes', 'إشعارات مدينة', 'screen', 110
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'debit_notes');

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'credit_notes', 'إشعارات دائنة', 'screen', 120
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'credit_notes');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN ('debit_notes', 'credit_notes')
WHERE g.code IN ('ADMINS', 'administrators', 'admin');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s.id, 1
FROM sys_group_permission gp
INNER JOIN sys_screen src ON src.id = gp.screen_id AND src.code = 'cash_receipt'
INNER JOIN sys_screen s ON s.code IN ('debit_notes', 'credit_notes')
WHERE gp.allowed = 1;
