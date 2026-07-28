-- شيكات صادرة خاصة: للتذكير فقط دون سندات صرف أو قيود محاسبية

CREATE TABLE IF NOT EXISTS fin_private_out_check (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_no VARCHAR(40) NULL,
  check_no VARCHAR(80) NULL,
  bank_name VARCHAR(120) NULL,
  check_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  beneficiary VARCHAR(255) NULL COMMENT 'المستفيد (نص حر)',
  notes TEXT NULL,
  status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
  done_at DATETIME NULL,
  done_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fpo_entry_no (entry_no),
  KEY idx_fpo_due_status (due_date, status),
  KEY idx_fpo_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'fin_private_out_checks', 'شيكات خاصة', 'screen', 1195
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'fin_private_out_checks');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code IN ('fin_outgoing_checks', 'cash_payment')
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'fin_private_out_checks';

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code = 'fin_private_out_checks'
WHERE g.code IN ('ADMINS', 'administrators', 'admin');
