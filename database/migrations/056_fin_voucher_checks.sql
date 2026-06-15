-- جدول الشيكات لسندات القبض/الصرف: شيك واحد أو أكثر للسند نفسه مع تاريخ استحقاق

CREATE TABLE IF NOT EXISTS fin_voucher_check (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    voucher_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    check_no VARCHAR(80) NULL,
    bank_name VARCHAR(120) NULL,
    check_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fvc_voucher (voucher_id),
    KEY idx_fvc_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
