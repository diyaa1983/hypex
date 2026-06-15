-- توسيع سندات القبض/الصرف: ترحيل، نقد/شيك، بنك (يُطبَّق أيضاً من PHP عند الحاجة)

ALTER TABLE fin_voucher ADD COLUMN is_posted TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE fin_voucher ADD COLUMN posted_at DATETIME NULL;
ALTER TABLE fin_voucher ADD COLUMN pay_method ENUM('cash','check') NOT NULL DEFAULT 'cash';
ALTER TABLE fin_voucher ADD COLUMN check_amount DECIMAL(18,6) NULL;
ALTER TABLE fin_voucher ADD COLUMN bank_name VARCHAR(120) NULL;
