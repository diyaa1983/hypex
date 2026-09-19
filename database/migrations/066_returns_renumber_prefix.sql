-- إعادة ترقيم المُرتَجَعات بِبادئة مُمَيِّزة:
--   - مرتجع المبيعات (sal_return): NNN-YYYY  ⇒ SRNNN-YYYY
--   - مرتجع المشتريات (pur_return): MRNNN-YYYY أو NNN-YYYY ⇒ PRNNN-YYYY
-- المُحَدِّثات أدناه idempotent (يُمكن تَشغيلها مرارًا بدون آثار جانبية).
-- نَستخدم LIKE فقط (بدون REGEXP) لِضمان التَوافق مع كل إِصدارات MySQL/MariaDB.

-- مرتجع المبيعات: إضافة بادئة SR لأَي رَقم لا يَبدَأ بـ SR.
-- (سَجِلات sal_return لا تَحوي بَادئات أُخرى).
UPDATE sal_return
SET return_no = CONCAT('SR', return_no)
WHERE return_no NOT LIKE 'SR%';

-- مرتجع المشتريات: تَحويل البادئة القديمة MR إلى PR.
UPDATE pur_return
SET return_no = CONCAT('PR', SUBSTRING(return_no, 3))
WHERE return_no LIKE 'MR%';

-- مرتجع المشتريات: إضافة بادئة PR لأَي رَقم لا يَبدَأ بـ PR.
UPDATE pur_return
SET return_no = CONCAT('PR', return_no)
WHERE return_no NOT LIKE 'PR%';
