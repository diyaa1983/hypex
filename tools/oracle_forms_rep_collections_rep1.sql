-- التحقق من «مبيعات فواتير نقدية» لمندوب واحد
-- غيّر رقم المندوب والتواريخ عند الحاجة

ALTER SESSION SET NLS_NUMERIC_CHARACTERS = '.,';

-- 1) كل حركات حساب فواتير نقدية في الفترة (بدون فلتر مندوب)
SELECT v.VOD_NUM,
       v.VOD_FLAG,
       TRUNC(v.VOD_DATE) VOD_DATE,
       TRIM(TO_CHAR(v.VOD_ACC)) VOD_ACC,
       g.ACC_DESC,
       ROUND(ABS(v.VOD_AMOUNT), 3) AMT,
       (SELECT MAX(d.MAN_NUM) FROM MAS.DAILY d WHERE d.V_NUM = v.VOD_NUM AND NVL(d.MAN_NUM,0) > 0) DAILY_MAN
  FROM ACCINV.GLVODMF v
  JOIN ACCINV.GLACTMF g
    ON TRIM(TO_CHAR(g.ACC_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01'
   AND v.VOD_DATE <  DATE '2027-01-01'
   AND v.VOD_SIDE = 2
   AND v.VOD_NUM >= 10000
   AND (INSTR(NVL(TRIM(g.ACC_DESC), ''), 'فواتير نقدية') > 0
        OR INSTR(NVL(TRIM(g.ACC_DESC), ''), 'مبيعات فواتير') > 0)
 ORDER BY v.VOD_DATE, v.VOD_NUM;

-- 2) مجموعها (يجب أن تظهر في التقرير كقبض نقدي)
SELECT COUNT(*) CNT,
       ROUND(SUM(ABS(v.VOD_AMOUNT)), 3) AMT
  FROM ACCINV.GLVODMF v
  JOIN ACCINV.GLACTMF g
    ON TRIM(TO_CHAR(g.ACC_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
 WHERE v.VOD_DATE >= DATE '2026-11-01'
   AND v.VOD_DATE <  DATE '2027-01-01'
   AND v.VOD_SIDE = 2
   AND v.VOD_NUM >= 10000
   AND (INSTR(NVL(TRIM(g.ACC_DESC), ''), 'فواتير نقدية') > 0
        OR INSTR(NVL(TRIM(g.ACC_DESC), ''), 'مبيعات فواتير') > 0);
