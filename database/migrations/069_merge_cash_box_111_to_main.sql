-- دمج حساب الصندوق 111 (الصندوق) في صندوق رئيسي 1001001001
-- يُنفَّذ تلقائياً عبر PHP (acc_account_merge_default_cash_box) من index.php
-- أو يدوياً: استبدل @FROM_ID و @TO_ID بمعرّفات الحسابات من acc_account

-- UPDATE acc_journal_line SET account_id = @TO_ID WHERE account_id = @FROM_ID;
-- UPDATE fin_voucher SET cash_account_id = @TO_ID WHERE cash_account_id = @FROM_ID;
-- UPDATE acc_posting_setting SET account_id = @TO_ID WHERE account_id = @FROM_ID;
-- UPDATE acc_posting_setting SET account_id = @TO_ID WHERE rule_code = 'cash';
-- UPDATE acc_account SET is_active = 0 WHERE id = @FROM_ID;
