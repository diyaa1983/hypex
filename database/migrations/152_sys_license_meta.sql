ALTER TABLE sys_license
  ADD COLUMN license_no VARCHAR(80) NULL AFTER issued_to;

ALTER TABLE sys_license
  ADD COLUMN max_users INT UNSIGNED NULL AFTER license_no;

ALTER TABLE sys_license
  ADD COLUMN activated_by_user_id INT UNSIGNED NULL AFTER payload_json;

ALTER TABLE sys_license
  ADD COLUMN activated_by_username VARCHAR(80) NULL AFTER activated_by_user_id;

ALTER TABLE sys_license
  ADD COLUMN activated_by_name VARCHAR(190) NULL AFTER activated_by_username;

CREATE TABLE IF NOT EXISTS sys_license_activation_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fingerprint_hash CHAR(64) NOT NULL,
  license_no VARCHAR(80) NULL,
  issued_to VARCHAR(190) NULL,
  max_users INT UNSIGNED NULL,
  active_users INT UNSIGNED NOT NULL DEFAULT 0,
  activated_by_user_id INT UNSIGNED NULL,
  activated_by_username VARCHAR(80) NULL,
  activated_by_name VARCHAR(190) NULL,
  activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sys_license_activation_log_fingerprint (fingerprint_hash),
  KEY idx_sys_license_activation_log_activated_at (activated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
