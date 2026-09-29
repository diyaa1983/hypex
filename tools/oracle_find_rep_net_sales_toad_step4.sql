-- ============================================================
-- ضبط صافي المبيعات ليطابق INVREP050 أقرب ما يمكن
-- الاستعلام الأساسي نجح — نختبر الخصم والمرتجع و MAN_NUM
-- ============================================================

-- 1) مع خصم البند + خصم الفاتورة (إن وُجدت الأعمدة)
SELECT c.CUS_SALESMAN AS REP_NO,
       e.EMP_NAME,
       COUNT(DISTINCT d.VYEAR || '-' || d.V_NUM) AS INV_CNT,
       ROUND(SUM(
         (NVL(d.QTY,0) * NVL(d.SELL,0))
         - NVL(d.DISC,0)
         - NVL(d.VOU_DISC,0)
       ), 3) AS NET_AMT,
       ROUND(SUM(NVL(d.QTY,0) * NVL(d.JD_COST,0)), 3) AS COST_AMT,
       ROUND(SUM(
         (NVL(d.QTY,0) * NVL(d.SELL,0))
         - NVL(d.DISC,0)
         - NVL(d.VOU_DISC,0)
       ) - SUM(NVL(d.QTY,0) * NVL(d.JD_COST,0)), 3) AS PROFIT_AMT
FROM MAS.DAILY d
JOIN ACCINV.CUSTOMER c
  ON TO_CHAR(c.CUS_NUM) = TO_CHAR(d.CUST_ACC)
LEFT JOIN ACCINV.EMP_INFO e
  ON e.EMP_NO = c.CUS_SALESMAN
WHERE d.COMP_NUM = 1
  AND d.TYPE = 9
  AND d.STORE = 4
  AND d.VDATE >= DATE '2026-01-01'
  AND d.VDATE <  DATE '2026-09-30'
  AND c.CUS_SALESMAN BETWEEN 2 AND 20
GROUP BY c.CUS_SALESMAN, e.EMP_NAME
ORDER BY c.CUS_SALESMAN;


-- 2) نفس التجميع لكن المندوب من DAILY.MAN_NUM (إن كان تقرير Forms يعتمد على البائع في الفاتورة)
SELECT d.MAN_NUM AS REP_NO,
       e.EMP_NAME,
       COUNT(DISTINCT d.VYEAR || '-' || d.V_NUM) AS INV_CNT,
       ROUND(SUM(NVL(d.QTY,0) * NVL(d.SELL,0)), 3) AS NET_AMT,
       ROUND(SUM(NVL(d.QTY,0) * NVL(d.JD_COST,0)), 3) AS COST_AMT,
       ROUND(SUM(NVL(d.QTY,0) * NVL(d.SELL,0))
           - SUM(NVL(d.QTY,0) * NVL(d.JD_COST,0)), 3) AS PROFIT_AMT
FROM MAS.DAILY d
LEFT JOIN ACCINV.EMP_INFO e
  ON e.EMP_NO = d.MAN_NUM
WHERE d.COMP_NUM = 1
  AND d.TYPE = 9
  AND d.STORE = 4
  AND d.VDATE >= DATE '2026-01-01'
  AND d.VDATE <  DATE '2026-09-30'
  AND d.MAN_NUM BETWEEN 2 AND 20
GROUP BY d.MAN_NUM, e.EMP_NAME
ORDER BY d.MAN_NUM;


-- 3) أنواع الحركات في الفترة (لفهم المرتجع إن وُجد TYPE آخر)
SELECT TYPE, COUNT(*) AS CNT
FROM MAS.DAILY
WHERE COMP_NUM = 1
  AND STORE = 4
  AND VDATE >= DATE '2026-01-01'
  AND VDATE <  DATE '2026-09-30'
GROUP BY TYPE
ORDER BY TYPE;
