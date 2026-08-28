-- ============================================================
-- فئات Oracle — الجدول الحقيقي: MAS.CODE
-- ============================================================

-- أسماء الفئات (CODE = رقم الفئة في MASCARD.CAT / BALANCE.CAT)
SELECT CODE, CODE_NAME, CODE_DESC
FROM MAS.CODE
ORDER BY TO_NUMBER(REGEXP_REPLACE(TRIM(TO_CHAR(CODE)), '[^0-9]', ''));

-- مثال: فئة مادتي الطلب
SELECT m.ITEM, m.CAT, c.CODE_NAME
FROM MAS.MASCARD m
LEFT JOIN MAS.CODE c ON TRIM(TO_CHAR(c.CODE)) = TRIM(TO_CHAR(m.CAT))
WHERE TRIM(TO_CHAR(m.ITEM)) IN ('600018','200008');
