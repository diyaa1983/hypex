-- تجيير الشيك: حالة endorsed + الطرف المُجيَّر إليه

ALTER TABLE fin_voucher_check
    MODIFY lifecycle_status ENUM('pending','cleared','returned','endorsed') NOT NULL DEFAULT 'pending';

ALTER TABLE fin_voucher_check
    ADD COLUMN endorsed_party_type ENUM('customer','supplier') NULL AFTER action_by,
    ADD COLUMN endorsed_party_id INT UNSIGNED NULL AFTER endorsed_party_type,
    ADD COLUMN endorse_notes VARCHAR(500) NULL AFTER endorsed_party_id;
