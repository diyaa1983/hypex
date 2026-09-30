-- ============================================================
-- صافي صحيح: Σ(QTY×SELL×(1−DISC)) − VOU_DISC مرة واحدة لكل فاتورة
-- المندوب الافتراضي: MAN_NUM (مثل INVREP050)
-- المسار: tools/oracle_find_rep_net_sales_toad_step5.sql
-- ============================================================

-- A) ملخص مندوب — من DAILY.MAN_NUM (المسار المطابق لـ Forms)
WITH inv AS (
  SELECT d.VYEAR,
         d.V_NUM,
         d.MAN_NUM AS REP_NO,
         SUM(NVL(d.QTY, 0) * NVL(d.SELL, 0) * (1 - NVL(d.DISC, 0))) AS GROSS,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM(NVL(d.QTY, 0) * NVL(d.JD_COST, 0)) AS COST_AMT
  FROM MAS.DAILY d
  WHERE d.COMP_NUM = 1
    AND d.TYPE = 9
    AND d.STORE = 4
    AND d.VDATE >= DATE '2026-09-01'
    AND d.VDATE <  DATE '2026-10-01'
    AND d.MAN_NUM = 39
  GROUP BY d.VYEAR, d.V_NUM, d.MAN_NUM
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


-- B) ملخص مندوب — من CUS_SALESMAN (قديم — للمقارنة فقط)
WITH inv AS (
  SELECT d.VYEAR,
         d.V_NUM,
         c.CUS_SALESMAN AS REP_NO,
         SUM(NVL(d.QTY, 0) * NVL(d.SELL, 0) * (1 - NVL(d.DISC, 0))) AS GROSS,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM(NVL(d.QTY, 0) * NVL(d.JD_COST, 0)) AS COST_AMT
  FROM MAS.DAILY d
  JOIN ACCINV.CUSTOMER c
    ON TO_CHAR(c.CUS_NUM) = TO_CHAR(d.CUST_ACC)
  WHERE d.COMP_NUM = 1
    AND d.TYPE = 9
    AND d.STORE = 4
    AND d.VDATE >= DATE '2026-09-01'
    AND d.VDATE <  DATE '2026-10-01'
    AND c.CUS_SALESMAN = 39
  GROUP BY d.VYEAR, d.V_NUM, c.CUS_SALESMAN
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
