-- استعادة كلمة المرور + reCAPTCHA لتسجيل الدخول

CREATE TABLE IF NOT EXISTS sys_user_password_reset (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_supr_token_hash (token_hash),
  KEY idx_supr_user (user_id),
  KEY idx_supr_expires (expires_at),
  CONSTRAINT fk_supr_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
