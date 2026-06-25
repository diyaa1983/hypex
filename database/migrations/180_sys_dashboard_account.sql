-- حسابات لوحة التحكم — اختيار الحسابات الظاهرة في الشاشة الرئيسية

CREATE TABLE IF NOT EXISTS sys_dashboard_account (
  account_id INT UNSIGNED NOT NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (account_id),
  CONSTRAINT fk_sys_dashboard_account_acc
    FOREIGN KEY (account_id) REFERENCES acc_account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
SELECT 'dashboard_accounts_settings', 'حسابات الشاشة الرئيسية', 'screen', 119
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'dashboard_accounts_settings');

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT gp.group_id, s_new.id, 1
FROM sys_screen s_new
INNER JOIN sys_screen s_old ON s_old.code = 'settings'
INNER JOIN sys_group_permission gp ON gp.screen_id = s_old.id AND gp.allowed = 1
WHERE s_new.code = 'dashboard_accounts_settings';
