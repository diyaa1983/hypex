-- رقم الشفت: أرقام فقط (تحويل A القديم إلى 1)

UPDATE hr_att_shift
SET shift_code = '1'
WHERE shift_code = 'A'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM hr_att_shift WHERE shift_code = '1') AS t);
