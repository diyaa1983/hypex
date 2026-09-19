-- سند قبض: إضافة طريقة دفع «بنك» (إيداع في حساب البنك)
ALTER TABLE fin_voucher
  MODIFY COLUMN pay_method ENUM('cash','check','bank') NOT NULL DEFAULT 'cash';
