-- ============================================================
-- Toad خطوة تالية بعد اكتشاف INVREP050
-- ما عرفناه حتى الآن:
--   CUSTOMER.CUS_SALESMAN موجود
--   EMP_INFO (EMP_NO / EMP_NAME) موجود
--   ACCINV.REP موجود
--   MAS.DAILY موجود (62 عمود)
--   MAS.MASTER_D موجود لكن بدون عمود VDATE ← خطأ ORA-00904
-- نفّذ الأقسام بالترتيب والصق النتائج
-- ============================================================

-- ------------------------------------------------------------
-- A) كل أعمدة MASTER_D (مهم جداً — لمعرفة التاريخ والمبلغ والنوع)
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'MAS' AND TABLE_NAME = 'MASTER_D'
ORDER BY COLUMN_ID;


-- ------------------------------------------------------------
-- B) كل أعمدة DAILY (مرّر للأسفل في الشبكة — نحتاج TYPE/QTY/PRICE/COST/DATE)
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'MAS' AND TABLE_NAME = 'DAILY'
ORDER BY COLUMN_ID;


-- ------------------------------------------------------------
-- C) أعمدة جدول المندوبين ACCINV.REP
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'ACCINV' AND TABLE_NAME = 'REP'
ORDER BY COLUMN_ID;


-- ------------------------------------------------------------
-- D) أعمدة فيها DATE أو TYPE أو AMT أو PRICE أو COST أو CUS في MASTER_D و DAILY
-- ------------------------------------------------------------
SELECT OWNER, TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'MAS'
  AND TABLE_NAME IN ('MASTER_D', 'DAILY')
  AND (
       DATA_TYPE LIKE '%DATE%'
    OR UPPER(COLUMN_NAME) LIKE '%DATE%'
    OR UPPER(COLUMN_NAME) LIKE '%TYPE%'
    OR UPPER(COLUMN_NAME) LIKE '%AMT%'
    OR UPPER(COLUMN_NAME) LIKE '%AMOUNT%'
    OR UPPER(COLUMN_NAME) LIKE '%PRICE%'
    OR UPPER(COLUMN_NAME) LIKE '%COST%'
    OR UPPER(COLUMN_NAME) LIKE '%QTY%'
    OR UPPER(COLUMN_NAME) LIKE '%CUS%'
    OR UPPER(COLUMN_NAME) LIKE '%SALE%'
    OR UPPER(COLUMN_NAME) LIKE '%EMP%'
    OR UPPER(COLUMN_NAME) LIKE '%NET%'
    OR UPPER(COLUMN_NAME) LIKE '%TOT%'
    OR UPPER(COLUMN_NAME) LIKE '%STORE%'
    OR UPPER(COLUMN_NAME) LIKE '%NUM%'
    OR UPPER(COLUMN_NAME) LIKE '%FLAG%'
  )
ORDER BY TABLE_NAME, COLUMN_ID;


-- ------------------------------------------------------------
-- E) بعد نتيجة A: استبدل DOC_DATE باسم عمود التاريخ الحقيقي من MASTER_D
--    مثال شائع: DOC_DATE / INV_DATE / M_DATE / V_DATE / TRAN_DATE
-- ------------------------------------------------------------
/*
SELECT *
FROM MAS.MASTER_D
WHERE COMP_NUM = 1
  AND ROWNUM <= 5;
*/


-- ------------------------------------------------------------
-- F) عينة أسماء مندوبين
-- ------------------------------------------------------------
SELECT EMP_NO, EMP_NAME, JOB_TYPE
FROM ACCINV.EMP_INFO
WHERE ROWNUM <= 30
ORDER BY EMP_NO;

SELECT *
FROM ACCINV.REP
WHERE ROWNUM <= 30;
