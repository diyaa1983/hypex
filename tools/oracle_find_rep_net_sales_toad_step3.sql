-- ============================================================
-- Toad خطوة 3: بناء صافي مبيعات المندوب من MAS.DAILY
-- ما تأكدنا منه:
--   MASTER_D = رأس/أعلام فقط (بدون تاريخ/مبلغ/مندوب)
--   DAILY فيها VDATE, STORE, QTY, JD_COST, MAN_NUM, TYPE, V_NUM…
--   CUS_SALESMAN على CUSTOMER
--   ACCINV.REP فارغ ← ليس مصدر INVREP050
-- ============================================================

-- ------------------------------------------------------------
-- 1) كل أعمدة DAILY المتعلقة بالسعر/المبلغ/العميل/الخصم/البائع
-- ------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'MAS' AND TABLE_NAME = 'DAILY'
  AND (
       UPPER(COLUMN_NAME) LIKE '%PRICE%'
    OR UPPER(COLUMN_NAME) LIKE '%AMT%'
    OR UPPER(COLUMN_NAME) LIKE '%AMOUNT%'
    OR UPPER(COLUMN_NAME) LIKE '%COST%'
    OR UPPER(COLUMN_NAME) LIKE '%QTY%'
    OR UPPER(COLUMN_NAME) LIKE '%CUS%'
    OR UPPER(COLUMN_NAME) LIKE '%CUST%'
    OR UPPER(COLUMN_NAME) LIKE '%DISC%'
    OR UPPER(COLUMN_NAME) LIKE '%TAX%'
    OR UPPER(COLUMN_NAME) LIKE '%MAN%'
    OR UPPER(COLUMN_NAME) LIKE '%SALE%'
    OR UPPER(COLUMN_NAME) LIKE '%NET%'
    OR UPPER(COLUMN_NAME) LIKE '%TOT%'
    OR UPPER(COLUMN_NAME) LIKE '%UNIT%'
    OR UPPER(COLUMN_NAME) LIKE '%FLAG%'
  )
ORDER BY COLUMN_ID;


-- ------------------------------------------------------------
-- 2) عينة صفوف مبيعات (TYPE=9) — انظر الأعمدة في الشبكة
-- ------------------------------------------------------------
SELECT *
FROM MAS.DAILY
WHERE COMP_NUM = 1
  AND TYPE = 9
  AND STORE = 4
  AND VDATE >= DATE '2026-01-01'
  AND VDATE <  DATE '2026-09-30'
  AND ROWNUM <= 15;


-- ------------------------------------------------------------
-- 3) ما أنواع الحركات الموجودة؟ (لتمييز بيع / مرتجع)
-- ------------------------------------------------------------
SELECT TYPE, COUNT(*) AS CNT,
       MIN(VDATE) AS D_FROM, MAX(VDATE) AS D_TO
FROM MAS.DAILY
WHERE COMP_NUM = 1
  AND VDATE >= DATE '2026-01-01'
  AND VDATE <  DATE '2026-09-30'
GROUP BY TYPE
ORDER BY TYPE;


-- ------------------------------------------------------------
-- 4) تجميع تجريبي حسب المندوب من بطاقة العميل
--    (عدّل أعمدة الصافي بعد نتيجة الاستعلام 1)
--    مرشّحات شائعة للصافي: QTY*PRICE أو AMOUNT أو TOT_AMT
-- ------------------------------------------------------------
/*
SELECT c.CUS_SALESMAN AS REP_NO,
       e.EMP_NAME,
       COUNT(DISTINCT d.VYEAR || '-' || d.V_NUM) AS INV_CNT,
       SUM(NVL(d.QTY,0) * NVL(d.PRICE,0)) AS NET_AMT,   -- غيّر PRICE بعد معرفة الاسم
       SUM(NVL(d.QTY,0) * NVL(d.JD_COST,0)) AS COST_AMT
FROM MAS.DAILY d
JOIN ACCINV.CUSTOMER c
  ON TO_CHAR(c.CUS_NUM) = TO_CHAR(d.CUST_ACC)           -- غيّر CUST_ACC إن اختلف
LEFT JOIN ACCINV.EMP_INFO e
  ON e.EMP_NO = c.CUS_SALESMAN
WHERE d.COMP_NUM = 1
  AND d.TYPE = 9
  AND d.STORE = 4
  AND d.VDATE >= DATE '2026-01-01'
  AND d.VDATE <  DATE '2026-09-30'
GROUP BY c.CUS_SALESMAN, e.EMP_NAME
ORDER BY c.CUS_SALESMAN;
*/


-- ------------------------------------------------------------
-- 5) بديل: إن كان MAN_NUM في DAILY = رقم المندوب
-- ------------------------------------------------------------
SELECT d.MAN_NUM AS REP_NO,
       e.EMP_NAME,
       COUNT(DISTINCT d.VYEAR || '-' || d.V_NUM) AS INV_CNT,
       SUM(NVL(d.QTY,0) * NVL(d.JD_COST,0)) AS COST_ONLY
FROM MAS.DAILY d
LEFT JOIN ACCINV.EMP_INFO e
  ON e.EMP_NO = d.MAN_NUM
WHERE d.COMP_NUM = 1
  AND d.TYPE = 9
  AND d.STORE = 4
  AND d.VDATE >= DATE '2026-01-01'
  AND d.VDATE <  DATE '2026-09-30'
GROUP BY d.MAN_NUM, e.EMP_NAME
ORDER BY d.MAN_NUM;
