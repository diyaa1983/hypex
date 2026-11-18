-- =============================================================================
-- مندوب 1 | من 01-11-2026 الى 31-12-2026 | هدف Forms = 44944.244
-- بدون متغيرات & (كانت تسبب FORMS_TOTAL=3 في Toad)
-- نفّذ الكل بـ F5 — صف واحد لكل طريقة؛ الأقرب لـ DELTA=0 هو الصحيح
-- =============================================================================

ALTER SESSION SET NLS_NUMERIC_CHARACTERS = '.,';

-- أ) كل الدائن ما عدا مبيعات/مرتجع/قيد
SELECT 'A_ALL_NOT_12_13_14' AS KIND,
       ROUND(SUM(ABS(v.VOD_AMOUNT)), 3) AS AMT,
       ROUND(SUM(ABS(v.VOD_AMOUNT)) - 44944.244, 3) AS DELTA
  FROM ACCINV.GLVODMF v
  JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
   AND v.VOD_SIDE = 2 AND v.VOD_NUM >= 10000
   AND NVL(c.CUS_SALESMAN, 0) = 1
   AND (v.VOD_FLAG IS NULL OR v.VOD_FLAG NOT IN (12, 13, 14))

UNION ALL

-- ب) علم 11 فقط (مبلغ السند)
SELECT 'B_FLAG_11_VOD',
       ROUND(SUM(ABS(v.VOD_AMOUNT)), 3),
       ROUND(SUM(ABS(v.VOD_AMOUNT)) - 44944.244, 3)
  FROM ACCINV.GLVODMF v
  JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
   AND v.VOD_SIDE = 2 AND v.VOD_NUM >= 10000
   AND NVL(c.CUS_SALESMAN, 0) = 1
   AND v.VOD_FLAG = 11

UNION ALL

-- ج) علم 10+11 (مبلغ السند)
SELECT 'C_FLAG_10_11_VOD',
       ROUND(SUM(ABS(v.VOD_AMOUNT)), 3),
       ROUND(SUM(ABS(v.VOD_AMOUNT)) - 44944.244, 3)
  FROM ACCINV.GLVODMF v
  JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
   AND v.VOD_SIDE = 2 AND v.VOD_NUM >= 10000
   AND NVL(c.CUS_SALESMAN, 0) = 1
   AND v.VOD_FLAG IN (10, 11)

UNION ALL

-- د) نقدي VOD (بلا شيك) + شيك CHQ_AMOUNT  ← منطقنا الحالي ≈ 30088
SELECT 'D_CASH_VOD_PLUS_CHQ_AMT',
       ROUND(x.CASH_AMT + x.CHQ_AMT, 3),
       ROUND(x.CASH_AMT + x.CHQ_AMT - 44944.244, 3)
  FROM (
    SELECT
      NVL((
        SELECT SUM(s.AMT) FROM (
          SELECT ROUND(SUM(ABS(v.VOD_AMOUNT)), 3) AMT
            FROM ACCINV.GLVODMF v
            JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
           WHERE v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
             AND v.VOD_SIDE = 2 AND v.VOD_NUM >= 10000
             AND NVL(c.CUS_SALESMAN, 0) = 1
             AND (v.VOD_FLAG IS NULL OR v.VOD_FLAG NOT IN (12, 13, 14))
             AND NOT EXISTS (
                   SELECT 1 FROM ACCINV.GLCHEQF ch
                    WHERE ch.CHQ_V_NUM = v.VOD_NUM
                      AND TRIM(TO_CHAR(ch.CHQ_CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
                      AND ch.CHQ_NUM IS NOT NULL
                 )
           GROUP BY v.VOD_NUM, TRIM(TO_CHAR(v.VOD_ACC))
        ) s
      ), 0) AS CASH_AMT,
      NVL((
        SELECT SUM(ROUND(ABS(ch.CHQ_AMOUNT), 3))
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
      ), 0) AS CHQ_AMT
    FROM DUAL
  ) x

UNION ALL

-- هـ) كل علم 11 بمبلغ السند + شيكات (CHQ) لسندات ليست علماً 11؟ نادر
--     بديل: مبلغ السند لكل ما له شيك + مبلغ السند بلا شيك = نفس A
SELECT 'E_SAME_AS_A_CHECK',
       ROUND(SUM(ABS(v.VOD_AMOUNT)), 3),
       ROUND(SUM(ABS(v.VOD_AMOUNT)) - 44944.244, 3)
  FROM ACCINV.GLVODMF v
  JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
   AND v.VOD_SIDE = 2 AND v.VOD_NUM >= 10000
   AND NVL(c.CUS_SALESMAN, 0) = 1
   AND (v.VOD_FLAG IS NULL OR v.VOD_FLAG NOT IN (12, 13, 14))

UNION ALL

-- و) شيكات فقط بـ CHQ_DATE داخل الفترة (حق الشيك) + نقدي بلا شيك
SELECT 'F_CASH_PLUS_CHQ_BY_DUE_DATE',
       ROUND(x.CASH_AMT + x.CHQ_AMT, 3),
       ROUND(x.CASH_AMT + x.CHQ_AMT - 44944.244, 3)
  FROM (
    SELECT
      NVL((
        SELECT SUM(s.AMT) FROM (
          SELECT ROUND(SUM(ABS(v.VOD_AMOUNT)), 3) AMT
            FROM ACCINV.GLVODMF v
            JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
           WHERE v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
             AND v.VOD_SIDE = 2 AND v.VOD_NUM >= 10000
             AND NVL(c.CUS_SALESMAN, 0) = 1
             AND (v.VOD_FLAG IS NULL OR v.VOD_FLAG NOT IN (12, 13, 14))
             AND NOT EXISTS (
                   SELECT 1 FROM ACCINV.GLCHEQF ch
                    WHERE ch.CHQ_V_NUM = v.VOD_NUM
                      AND TRIM(TO_CHAR(ch.CHQ_CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
                      AND ch.CHQ_NUM IS NOT NULL
                 )
           GROUP BY v.VOD_NUM, TRIM(TO_CHAR(v.VOD_ACC))
        ) s
      ), 0) AS CASH_AMT,
      NVL((
        SELECT SUM(ROUND(ABS(ch.CHQ_AMOUNT), 3))
          FROM ACCINV.GLCHEQF ch
          JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(ch.CHQ_CUS_NUM))
         WHERE ch.CHQ_NUM IS NOT NULL AND ABS(ch.CHQ_AMOUNT) > 0
           AND NVL(c.CUS_SALESMAN, 0) = 1
           AND ch.CHQ_DATE >= DATE '2026-11-01' AND ch.CHQ_DATE < DATE '2027-01-01'
      ), 0) AS CHQ_AMT
    FROM DUAL
  ) x

UNION ALL

-- ز) توزيع سريع: مجموع كل علم (للمقارنة اليدوية مع 44944)
SELECT 'Z_FLAG_' || NVL(TO_CHAR(v.VOD_FLAG), 'NULL'),
       ROUND(SUM(ABS(v.VOD_AMOUNT)), 3),
       ROUND(SUM(ABS(v.VOD_AMOUNT)) - 44944.244, 3)
  FROM ACCINV.GLVODMF v
  JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01' AND v.VOD_DATE < DATE '2027-01-01'
   AND v.VOD_SIDE = 2 AND v.VOD_NUM >= 10000
   AND NVL(c.CUS_SALESMAN, 0) = 1
 GROUP BY v.VOD_FLAG

ORDER BY 1;
