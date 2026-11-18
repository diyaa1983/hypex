-- مبيعات فواتير نقدية + قبض نقدي/شيك — مندوب 1
-- الفترة: 01-11-2026 .. 31-12-2026 | هدف Forms: 44944.244

ALTER SESSION SET NLS_NUMERIC_CHARACTERS = '.,';

-- 1) عيّنة «مبيعات فواتير نقدية» في الفترة
SELECT v.VOD_NUM, v.VOD_FLAG, v.VOD_DATE, v.VOD_ACC,
       g.ACC_DESC, ROUND(ABS(v.VOD_AMOUNT), 3) AMT
  FROM ACCINV.GLVODMF v
  LEFT JOIN ACCINV.GLACTMF g
    ON TRIM(TO_CHAR(g.ACC_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01'
   AND v.VOD_DATE <  DATE '2027-01-01'
   AND v.VOD_SIDE = 2
   AND v.VOD_NUM >= 10000
   AND INSTR(NVL(TRIM(g.ACC_DESC), ''), 'فواتير نقدية') > 0
 ORDER BY v.VOD_DATE, v.VOD_NUM;

-- 2) المجموع: نقدي (يشمل فواتير نقدية) + شيك
WITH cash AS (
  SELECT ROUND(SUM(ABS(v.VOD_AMOUNT)), 3) AS AMT
    FROM ACCINV.GLVODMF v
    LEFT JOIN ACCINV.CUSTOMER c
      ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
    LEFT JOIN ACCINV.GLACTMF g
      ON TRIM(TO_CHAR(g.ACC_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
   WHERE v.VOD_DATE >= DATE '2026-11-01'
     AND v.VOD_DATE <  DATE '2027-01-01'
     AND v.VOD_SIDE = 2
     AND v.VOD_NUM >= 10000
     AND (
           v.VOD_FLAG IS NULL
           OR v.VOD_FLAG NOT IN (12, 13, 14)
           OR (v.VOD_FLAG = 14 AND INSTR(NVL(TRIM(g.ACC_DESC), ''), 'فواتير نقدية') > 0)
         )
     AND (
           NVL(c.CUS_SALESMAN, 0) = 1
           OR (
                INSTR(NVL(TRIM(g.ACC_DESC), ''), 'فواتير نقدية') > 0
                AND EXISTS (
                      SELECT 1
                        FROM ACCINV.GLVODMF vx
                        JOIN ACCINV.CUSTOMER cx
                          ON TRIM(TO_CHAR(cx.CUS_NUM)) = TRIM(TO_CHAR(vx.VOD_ACC))
                       WHERE vx.VOD_NUM = v.VOD_NUM
                         AND NVL(cx.CUS_SALESMAN, 0) = 1
                    )
              )
         )
     AND NOT EXISTS (
           SELECT 1 FROM ACCINV.GLCHEQF ch
            WHERE ch.CHQ_V_NUM = v.VOD_NUM
              AND TRIM(TO_CHAR(ch.CHQ_CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
              AND ch.CHQ_NUM IS NOT NULL
         )
   GROUP BY v.VOD_NUM, TRIM(TO_CHAR(v.VOD_ACC))
),
chq AS (
  SELECT ROUND(ABS(ch.CHQ_AMOUNT), 3) AS AMT
    FROM ACCINV.GLCHEQF ch
    JOIN (
          SELECT VOD_NUM, TRIM(TO_CHAR(VOD_ACC)) VOD_ACC, TRUNC(MIN(VOD_DATE)) VOD_DATE
            FROM ACCINV.GLVODMF
           WHERE VOD_SIDE = 2
             AND (VOD_FLAG IS NULL OR VOD_FLAG NOT IN (12, 13, 14))
           GROUP BY VOD_NUM, TRIM(TO_CHAR(VOD_ACC))
         ) v ON v.VOD_NUM = ch.CHQ_V_NUM AND v.VOD_ACC = TRIM(TO_CHAR(ch.CHQ_CUS_NUM))
    JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(ch.CHQ_CUS_NUM))
   WHERE ch.CHQ_NUM IS NOT NULL AND ABS(ch.CHQ_AMOUNT) > 0
     AND v.VOD_NUM >= 10000
     AND v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
     AND NVL(c.CUS_SALESMAN, 0) = 1
)
SELECT
  (SELECT COUNT(*) FROM cash) AS CASH_CNT,
  (SELECT ROUND(NVL(SUM(AMT),0),3) FROM cash) AS CASH_AMT,
  (SELECT COUNT(*) FROM chq) AS CHQ_CNT,
  (SELECT ROUND(NVL(SUM(AMT),0),3) FROM chq) AS CHQ_AMT,
  ROUND((SELECT NVL(SUM(AMT),0) FROM cash)+(SELECT NVL(SUM(AMT),0) FROM chq),3) AS TOTAL_AMT,
  44944.244 AS FORMS_TOTAL,
  ROUND((SELECT NVL(SUM(AMT),0) FROM cash)+(SELECT NVL(SUM(AMT),0) FROM chq)-44944.244,3) AS DELTA
FROM DUAL;
