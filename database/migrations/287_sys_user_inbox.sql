-- صندوق إشعارات المستخدم (الموبايل/التاب) — اعتماد موقع العميل وغيرها

CREATE TABLE IF NOT EXISTS sys_user_inbox (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(40) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body VARCHAR(500) NULL DEFAULT NULL,
  ref_type VARCHAR(40) NULL DEFAULT NULL,
  ref_id INT UNSIGNED NULL DEFAULT NULL,
  customer_id INT UNSIGNED NULL DEFAULT NULL,
  payload_json TEXT NULL DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sui_user_read (user_id, is_read, created_at),
  KEY idx_sui_ref (ref_type, ref_id, kind, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
