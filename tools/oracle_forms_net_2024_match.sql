-- تأكيد نهائي: Forms 3718067.727 = مبيعات TYPE=9 − مرتجعات TYPE=2
-- (بعد خصم البند − VOU_DISC) بدون ضريبة — مستودع 4 / 2024
-- SELECT فقط

ALTER SESSION SET NLS_NUMERIC_CHARACTERS = '.,';

WITH sale_inv AS (
  SELECT d.VYEAR, d.V_NUM, d.MAN_NUM,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM(
           NVL(d.QTY, 0) * NVL(CASE WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX ELSE d.SELL END, 0)
           - CASE
               WHEN NVL(d.DISC, 0) = 0 THEN 0
               WHEN NVL(d.DISC, 0) <= 1 THEN
                 NVL(d.QTY, 0) * NVL(CASE WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX ELSE d.SELL END, 0) * NVL(d.DISC, 0)
               WHEN NVL(d.DISC, 0) <= 100 THEN
                 NVL(d.QTY, 0) * NVL(CASE WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX ELSE d.SELL END, 0) * NVL(d.DISC, 0) / 100
               ELSE NVL(d.DISC, 0)
             END
         ) AS GROSS_DISC,
         SUM((NVL(d.QTY, 0) + NVL(d.BONUS, 0)) * NVL(d.JD_COST, 0)) AS COST_BONUS
  FROM MAS.DAILY d
  WHERE d.COMP_NUM = 1 AND d.TYPE = 9 AND d.STORE = 4
    AND d.VDATE >= DATE '2024-01-01' AND d.VDATE < DATE '2025-01-01'
    AND d.MAN_NUM IS NOT NULL AND d.MAN_NUM > 0
  GROUP BY d.VYEAR, d.V_NUM, d.MAN_NUM
),
ret_inv AS (
  SELECT d.VYEAR, d.V_NUM, d.MAN_NUM,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM(
           NVL(d.QTY, 0) * NVL(CASE WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX ELSE d.SELL END, 0)
           - CASE
               WHEN NVL(d.DISC, 0) = 0 THEN 0
               WHEN NVL(d.DISC, 0) <= 1 THEN
                 NVL(d.QTY, 0) * NVL(CASE WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX ELSE d.SELL END, 0) * NVL(d.DISC, 0)
               WHEN NVL(d.DISC, 0) <= 100 THEN
                 NVL(d.QTY, 0) * NVL(CASE WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX ELSE d.SELL END, 0) * NVL(d.DISC, 0) / 100
               ELSE NVL(d.DISC, 0)
             END
         ) AS GROSS_DISC,
         SUM((NVL(d.QTY, 0) + NVL(d.BONUS, 0)) * NVL(d.JD_COST, 0)) AS COST_BONUS
  FROM MAS.DAILY d
  WHERE d.COMP_NUM = 1 AND d.TYPE = 2 AND d.STORE = 4
    AND d.VDATE >= DATE '2024-01-01' AND d.VDATE < DATE '2025-01-01'
    AND d.MAN_NUM IS NOT NULL AND d.MAN_NUM > 0
  GROUP BY d.VYEAR, d.V_NUM, d.MAN_NUM
),
agg AS (
  SELECT
    (SELECT ROUND(SUM(GROSS_DISC) - SUM(VOU_DISC), 3) FROM sale_inv) AS SALE_NET,
    (SELECT ROUND(SUM(GROSS_DISC), 3) FROM ret_inv) AS RET_LINE,
    (SELECT ROUND(SUM(VOU_DISC), 3) FROM ret_inv) AS RET_VOU,
    (SELECT ROUND(SUM(GROSS_DISC) - SUM(VOU_DISC), 3) FROM ret_inv) AS RET_NET,
    (SELECT ROUND(SUM(COST_BONUS), 3) FROM sale_inv) AS SALE_COST,
    (SELECT ROUND(SUM(COST_BONUS), 3) FROM ret_inv) AS RET_COST
  FROM DUAL
)
SELECT SALE_NET,
       RET_LINE,
       RET_VOU,
       RET_NET,
       ROUND(SALE_NET - RET_NET, 3) AS FORMS_NET,
       ROUND(ABS(SALE_NET - RET_NET - 3718067.727), 3) AS NET_DELTA,
       ROUND(SALE_COST - RET_COST, 3) AS FORMS_COST,
       ROUND(ABS(SALE_COST - RET_COST - 1910135.566), 3) AS COST_DELTA
FROM agg;
