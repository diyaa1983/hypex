-- ============================================================
-- صافي صحيح: إجمالي - خصم الفاتورة مرة واحدة لكل فاتورة
-- (VOU_DISC لا يُجمع على كل سطر)
-- المسار: tools/oracle_find_rep_net_sales_toad_step5.sql
-- ============================================================

-- A) ملخص مندوب — من CUS_SALESMAN
WITH inv AS (
  SELECT d.VYEAR,
         d.V_NUM,
         c.CUS_SALESMAN AS REP_NO,
         SUM(NVL(d.QTY, 0) * NVL(d.SELL, 0)) AS GROSS,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM(NVL(d.QTY, 0) * NVL(d.JD_COST, 0)) AS COST_AMT
  FROM MAS.DAILY d
  JOIN ACCINV.CUSTOMER c
    ON TO_CHAR(c.CUS_NUM) = TO_CHAR(d.CUST_ACC)
  WHERE d.COMP_NUM = 1
    AND d.TYPE = 9
    AND d.STORE = 4
    AND d.VDATE >= DATE '2026-01-01'
    AND d.VDATE <  DATE '2026-09-30'
    AND c.CUS_SALESMAN BETWEEN 2 AND 20
  GROUP BY d.VYEAR, d.V_NUM, c.CUS_SALESMAN
)
SELECT i.REP_NO,
       e.EMP_NAME,
       COUNT(*) AS INV_CNT,
       ROUND(SUM(i.GROSS - i.VOU_DISC), 3) AS NET_AMT,
       ROUND(SUM(i.COST_AMT), 3) AS COST_AMT,
       ROUND(SUM(i.GROSS - i.VOU_DISC) - SUM(i.COST_AMT), 3) AS PROFIT_AMT,
       CASE
         WHEN SUM(i.GROSS - i.VOU_DISC) = 0 THEN 0
         ELSE ROUND(
           100 * (SUM(i.GROSS - i.VOU_DISC) - SUM(i.COST_AMT))
               / SUM(i.GROSS - i.VOU_DISC), 3)
       END AS PROFIT_PCT
FROM inv i
LEFT JOIN ACCINV.EMP_INFO e
  ON e.EMP_NO = i.REP_NO
GROUP BY i.REP_NO, e.EMP_NAME
ORDER BY i.REP_NO;


-- B) ملخص مندوب — من DAILY.MAN_NUM
WITH inv AS (
  SELECT d.VYEAR,
         d.V_NUM,
         d.MAN_NUM AS REP_NO,
         SUM(NVL(d.QTY, 0) * NVL(d.SELL, 0)) AS GROSS,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM(NVL(d.QTY, 0) * NVL(d.JD_COST, 0)) AS COST_AMT
  FROM MAS.DAILY d
  WHERE d.COMP_NUM = 1
    AND d.TYPE = 9
    AND d.STORE = 4
    AND d.VDATE >= DATE '2026-01-01'
    AND d.VDATE <  DATE '2026-09-30'
    AND d.MAN_NUM BETWEEN 2 AND 20
  GROUP BY d.VYEAR, d.V_NUM, d.MAN_NUM
)
SELECT i.REP_NO,
       e.EMP_NAME,
       COUNT(*) AS INV_CNT,
       ROUND(SUM(i.GROSS - i.VOU_DISC), 3) AS NET_AMT,
       ROUND(SUM(i.COST_AMT), 3) AS COST_AMT,
       ROUND(SUM(i.GROSS - i.VOU_DISC) - SUM(i.COST_AMT), 3) AS PROFIT_AMT
FROM inv i
LEFT JOIN ACCINV.EMP_INFO e
  ON e.EMP_NO = i.REP_NO
GROUP BY i.REP_NO, e.EMP_NAME
ORDER BY i.REP_NO;


-- C) عينة TYPE 10 و 11
SELECT TYPE, V_NUM, VYEAR, VDATE, CUST_ACC, QTY, SELL, JD_COST
FROM MAS.DAILY
WHERE COMP_NUM = 1 AND STORE = 4 AND TYPE IN (10, 11)
  AND VDATE >= DATE '2026-01-01'
  AND ROWNUM <= 20
ORDER BY TYPE, VDATE DESC;
