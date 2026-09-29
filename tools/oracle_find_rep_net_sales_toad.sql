-- ============================================================
-- Toad: اكتشاف مصدر تقرير «صافي فواتير مبيعات المندوب» (INVREP050)
-- نفّذ كل قسم على حدة في Toad (نفس اتصال Oracle المستخدم مع Hypex)
-- أرسل نتيجة كل قسم قبل الانتقال للتالي
-- ============================================================

-- ------------------------------------------------------------
-- 1) كائنات بأسماء قريبة من التقرير / المندوب / صافي المبيعات
-- ------------------------------------------------------------
SELECT OWNER, OBJECT_NAME, OBJECT_TYPE
FROM ALL_OBJECTS
WHERE OWNER IN ('MAS', 'ACCINV', 'SYSTEM', 'APPS')
  AND (
       UPPER(OBJECT_NAME) LIKE '%INVREP%'
    OR UPPER(OBJECT_NAME) LIKE '%SALES%REP%'
    OR UPPER(OBJECT_NAME) LIKE '%REP%SALE%'
    OR UPPER(OBJECT_NAME) LIKE '%NET%SALE%'
    OR UPPER(OBJECT_NAME) LIKE '%SALESMAN%'
    OR UPPER(OBJECT_NAME) LIKE '%SMAN%'
    OR UPPER(OBJECT_NAME) LIKE '%MENDOB%'
    OR UPPER(OBJECT_NAME) LIKE '%PROFIT%'
  )
ORDER BY OWNER, OBJECT_TYPE, OBJECT_NAME;


-- ------------------------------------------------------------
-- 2) أعمدة تدل على مندوب / تكلفة / ربح / صافي
-- ------------------------------------------------------------
SELECT OWNER, TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM ALL_TAB_COLUMNS
WHERE OWNER IN ('MAS', 'ACCINV')
  AND (
       UPPER(COLUMN_NAME) LIKE '%SALESMAN%'
    OR UPPER(COLUMN_NAME) LIKE '%SMAN%'
    OR UPPER(COLUMN_NAME) LIKE '%REP%'
    OR UPPER(COLUMN_NAME) IN (
         'COST', 'COST_AMT', 'ITEM_COST', 'TOT_COST',
         'PROFIT', 'PROFIT_AMT', 'NET', 'NET_AMT',
         'TOT_NET', 'AMOUNT', 'TOT_AMT', 'GROSS'
       )
  )
ORDER BY OWNER, TABLE_NAME, COLUMN_NAME;


-- ------------------------------------------------------------
-- 3) أعمدة MASTER_D (رأس الفاتورة) — مرشّح قوي لـ INVREP050
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'MAS' AND TABLE_NAME = 'MASTER_D'
ORDER BY COLUMN_ID;


-- ------------------------------------------------------------
-- 4) أعمدة DAILY (بنود الفاتورة) — TYPE=9 مبيعات عادةً
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'MAS' AND TABLE_NAME = 'DAILY'
ORDER BY COLUMN_ID;


-- ------------------------------------------------------------
-- 5) عينة: فواتير مبيعات في فترة التقرير (عدّل التواريخ إن لزم)
--    من لقطة الشاشة: من 01-01-2026 إلى 29-09-2026 / مستودع 4
-- ------------------------------------------------------------
SELECT *
FROM MAS.MASTER_D
WHERE COMP_NUM = 1
  AND NVL(TYPE, 9) = 9
  AND VDATE >= DATE '2026-01-01'
  AND VDATE <  DATE '2026-09-30'
  AND ROWNUM <= 20;


-- ------------------------------------------------------------
-- 6) إن وُجد عمود مندوب في MASTER_D — جرّب أحد الأسماء الشائعة
--    (نفّذ فقط الأعمدة التي ظهرت في قسم 3)
-- مثال بعد معرفة الاسم الحقيقي، مثلاً SALESMAN أو EMP_NO:
-- ------------------------------------------------------------
/*
SELECT SALESMAN,
       COUNT(*) AS INV_CNT,
       SUM(NVL(TOT_AMT,0)) AS NET_SUM
FROM MAS.MASTER_D
WHERE COMP_NUM = 1
  AND TYPE = 9
  AND VDATE >= DATE '2026-01-01'
  AND VDATE <  DATE '2026-09-30'
  AND STORE = 4
GROUP BY SALESMAN
ORDER BY SALESMAN;
*/


-- ------------------------------------------------------------
-- 7) ربط العميل → المندوب من بطاقة العميل (إن لم يكن في رأس الفاتورة)
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'ACCINV' AND TABLE_NAME = 'CUSTOMER'
  AND (
       UPPER(COLUMN_NAME) LIKE '%SALE%'
    OR UPPER(COLUMN_NAME) LIKE '%SMAN%'
    OR UPPER(COLUMN_NAME) LIKE '%EMP%'
    OR UPPER(COLUMN_NAME) LIKE '%REP%'
  )
ORDER BY COLUMN_NAME;


-- ------------------------------------------------------------
-- 8) أسماء المندوبين (غالباً EMP_INFO)
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE
FROM ALL_TAB_COLUMNS
WHERE OWNER IN ('MAS', 'ACCINV')
  AND TABLE_NAME IN ('EMP_INFO', 'EMPLOYEE', 'SALESMAN', 'REP')
ORDER BY OWNER, TABLE_NAME, COLUMN_ID;

SELECT OWNER, TABLE_NAME
FROM ALL_TABLES
WHERE OWNER IN ('MAS', 'ACCINV')
  AND (
       UPPER(TABLE_NAME) LIKE '%EMP%'
    OR UPPER(TABLE_NAME) LIKE '%SALESMAN%'
    OR UPPER(TABLE_NAME) LIKE '%REP%'
  )
ORDER BY OWNER, TABLE_NAME;


-- ------------------------------------------------------------
-- 9) بحث في نصوص Forms عن INVREP050 (إن وُجدت جداول المصدر)
--    قد لا تعمل على كل البيئات — تجاهلها إن ظهر خطأ صلاحيات
-- ------------------------------------------------------------
/*
SELECT NAME, TYPE, LINE, TEXT
FROM ALL_SOURCE
WHERE UPPER(TEXT) LIKE '%INVREP050%'
  AND ROWNUM <= 50;
*/
