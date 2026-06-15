-- سجل إرسال تنبيهات استحقاق الشيكات (مرة واحدة لكل شيك في كل تاريخ استحقاق)
CREATE TABLE IF NOT EXISTS fin_check_due_email_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  check_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  notify_type VARCHAR(20) NOT NULL DEFAULT 'due_today',
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fcdel_check_due_type (check_id, due_date, notify_type),
  KEY idx_fcdel_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
