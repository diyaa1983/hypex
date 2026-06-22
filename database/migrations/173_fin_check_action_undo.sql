-- تتبّع إلغاء صرف/إرجاع/تجيير الشيك

ALTER TABLE fin_voucher_check
    ADD COLUMN action_undo_at DATETIME NULL AFTER action_by,
    ADD COLUMN undone_action ENUM('cleared','returned','endorsed') NULL AFTER action_undo_at;
