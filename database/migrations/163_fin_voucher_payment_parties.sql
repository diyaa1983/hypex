-- سند الصرف: موظف + حسابات أخرى + حساب مدين (التزام/مصروف)

ALTER TABLE fin_voucher
    MODIFY COLUMN party_type ENUM('customer','supplier','other','employee','account') NOT NULL DEFAULT 'other';

ALTER TABLE fin_voucher
    ADD COLUMN offset_account_id INT UNSIGNED NULL AFTER party_id,
    ADD KEY idx_fv_offset_account (offset_account_id);
