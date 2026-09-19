-- تصحيح: السلف المرحّلة بدون سند صرف = مستحقة للصرف (لم تُصرف بعد)

UPDATE hr_employee_advance
SET is_disbursed = 0,
    disbursed_at = NULL
WHERE is_posted = 1
  AND (disbursement_voucher_id IS NULL OR disbursement_voucher_id = 0)
  AND COALESCE(is_disbursed, 0) = 1;
