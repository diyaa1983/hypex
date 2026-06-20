-- ربط صرف الرواتب بسندات الصرف

ALTER TABLE hr_salary
    ADD COLUMN disbursement_voucher_id INT UNSIGNED NULL AFTER is_posted,
    ADD KEY idx_hr_salary_disb_voucher (disbursement_voucher_id);

ALTER TABLE fin_voucher
    ADD COLUMN hr_salary_id INT UNSIGNED NULL AFTER hr_advance_id,
    ADD KEY idx_fv_hr_salary (hr_salary_id);
