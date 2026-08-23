-- ============================================================
-- Toad: اكتشاف الجدول الذي يحتوي التشغيلة 0263278
-- نفّذ كل قسم على حدة في Toad (SYSTEM@TAQWA)
-- ============================================================

-- 1) كل الجداول التي فيها عمود BATCH (MAS + ACCINV)
SELECT OWNER, TABLE_NAME, COLUMN_NAME
FROM ALL_TAB_COLUMNS
WHERE UPPER(COLUMN_NAME) = 'BATCH'
  AND OWNER IN ('MAS', 'ACCINV')
ORDER BY OWNER, TABLE_NAME;

-- 2) البحث في STOCK (رصيد فعلي)
SELECT BATCH, SYS_QTY, MAN_QTY, EXP_DATE, CAT, ITEM, STORE, COMP_NUM
FROM MAS.STOCK
WHERE (TRIM(TO_CHAR(BATCH)) = '0263278'
    OR LTRIM(TRIM(TO_CHAR(BATCH)), '0') = '263278'
    OR TRIM(TO_CHAR(BATCH)) LIKE '%263278%')
  AND CAT = 6
  AND TRIM(TO_CHAR(ITEM)) = '600029'
  AND STORE = 4;

-- 3) رصيد موجب فقط (ما يستخدمه Hypex للترحيل)
SELECT BATCH, SYS_QTY, MAN_QTY, EXP_DATE
FROM MAS.STOCK
WHERE CAT = 6
  AND TRIM(TO_CHAR(ITEM)) = '600029'
  AND STORE = 4
  AND (NVL(SYS_QTY, 0) > 0 OR NVL(MAN_QTY, 0) > 0);

-- 4) DAILY — حركات/فواتير سابقة بالتشغيلة
SELECT TYPE, V_NUM, VYEAR, BATCH, QTY, CAT, ITEM, STORE, VDATE
FROM MAS.DAILY
WHERE (TRIM(TO_CHAR(BATCH)) = '0263278'
    OR LTRIM(TRIM(TO_CHAR(BATCH)), '0') = '263278'
    OR TRIM(TO_CHAR(BATCH)) LIKE '%263278%')
  AND TRIM(TO_CHAR(ITEM)) = '600029'
  AND ROWNUM <= 20
ORDER BY VYEAR DESC, V_NUM DESC;

-- 5) MASCARD — بطاقة المادة
SELECT ITEM, CAT, BATCH, IDESC
FROM MAS.MASCARD
WHERE TRIM(TO_CHAR(ITEM)) = '600029'
  AND ROWNUM <= 5;

-- 6) PRODD / PRODUCTION — تشغيلات الإنتاج (إن وُجد الجدول)
-- SELECT * FROM MAS.PRODD WHERE TRIM(TO_CHAR(BATCH)) LIKE '%263278%' AND ROWNUM <= 10;
-- SELECT * FROM MAS.PRODUCTION WHERE TRIM(TO_CHAR(BATCH)) LIKE '%263278%' AND ROWNUM <= 10;

-- 7) Views فيها BATCH
SELECT OWNER, VIEW_NAME
FROM ALL_VIEWS
WHERE OWNER IN ('MAS', 'ACCINV')
  AND VIEW_NAME IN (
    SELECT TABLE_NAME FROM ALL_TAB_COLUMNS
    WHERE OWNER = ALL_VIEWS.OWNER AND UPPER(COLUMN_NAME) = 'BATCH'
  )
ORDER BY OWNER, VIEW_NAME;

-- 8) بحث ديناميكي — يولّد SELECT لكل جدول (انسخ الناتج ونفّذه)
SELECT 'SELECT ''' || OWNER || '.' || TABLE_NAME || ''' AS SRC, COUNT(*) AS CNT FROM '
    || OWNER || '.' || TABLE_NAME
    || ' WHERE (TRIM(TO_CHAR(BATCH)) = ''0263278'' OR LTRIM(TRIM(TO_CHAR(BATCH)), ''0'') = ''263278'' OR TRIM(TO_CHAR(BATCH)) LIKE ''%263278%'') AND ROWNUM <= 1;'
    AS RUN_THIS
FROM ALL_TAB_COLUMNS
WHERE UPPER(COLUMN_NAME) = 'BATCH'
  AND OWNER IN ('MAS', 'ACCINV')
ORDER BY OWNER, TABLE_NAME;

-- ============================================================
-- 9) بحث تلقائي كامل (مثل Toad Search) — نفّذ هذا البلوك كاملاً
--    افتح: View → DBMS Output → Enable
--    غيّر p_batch / p_item / p_cat / p_store في أول DECLARE
-- ============================================================
SET SERVEROUTPUT ON SIZE UNLIMITED;

DECLARE
    p_batch   VARCHAR2(40) := '0263278';
    p_batch_n VARCHAR2(40) := '263278';
    p_item    VARCHAR2(40) := '600029';  -- NULL = بدون فلتر مادة
    p_cat     VARCHAR2(10) := '6';       -- NULL = بدون فلتر CAT
    p_store   NUMBER       := NULL;      -- NULL = بدون فلتر مستودع (4 = مستودع 4)
    v_cnt     NUMBER;
    v_sql     VARCHAR2(4000);
    v_sample  VARCHAR2(500);
    v_has_item  NUMBER;
    v_has_cat   NUMBER;
    v_has_store NUMBER;

    FUNCTION has_col(p_owner VARCHAR2, p_table VARCHAR2, p_names VARCHAR2) RETURN NUMBER IS
        n NUMBER;
    BEGIN
        SELECT COUNT(*) INTO n
        FROM ALL_TAB_COLUMNS
        WHERE OWNER = p_owner AND TABLE_NAME = p_table
          AND UPPER(COLUMN_NAME) IN (
              SELECT TRIM(REGEXP_SUBSTR(p_names, '[^,]+', 1, LEVEL))
              FROM DUAL
              CONNECT BY REGEXP_SUBSTR(p_names, '[^,]+', 1, LEVEL) IS NOT NULL
          );
        RETURN CASE WHEN n > 0 THEN 1 ELSE 0 END;
    END;
BEGIN
    DBMS_OUTPUT.PUT_LINE('=== Batch discover: ' || p_batch || ' item=' || NVL(p_item,'*') || ' cat=' || NVL(p_cat,'*') || ' store=' || NVL(TO_CHAR(p_store),'*') || ' ===');
    DBMS_OUTPUT.PUT_LINE(RPAD('TABLE', 50) || RPAD('ROWS', 8) || 'SAMPLE_BATCH');
    DBMS_OUTPUT.PUT_LINE(RPAD('-', 50, '-'));

    FOR rec IN (
        SELECT DISTINCT c.OWNER, c.TABLE_NAME, c.COLUMN_NAME
        FROM ALL_TAB_COLUMNS c
        WHERE UPPER(c.COLUMN_NAME) IN ('BATCH', 'BATCH_NO', 'LOT', 'LOT_NO', 'OP_NO', 'RUN_NO')
          AND c.OWNER IN ('MAS', 'ACCINV')
        ORDER BY c.OWNER, c.TABLE_NAME, c.COLUMN_NAME
    ) LOOP
        BEGIN
            v_has_item  := has_col(rec.OWNER, rec.TABLE_NAME, 'ITEM,ITEM_NO,ITEM_CODE');
            v_has_cat   := has_col(rec.OWNER, rec.TABLE_NAME, 'CAT,CATE,CATEGORY');
            v_has_store := has_col(rec.OWNER, rec.TABLE_NAME, 'STORE,STO,STO_NO,WAREHOUSE');

            v_sql :=
                'SELECT COUNT(*) FROM ' || rec.OWNER || '.' || rec.TABLE_NAME ||
                ' WHERE (TRIM(TO_CHAR(' || rec.COLUMN_NAME || ')) = :b1' ||
                ' OR LTRIM(TRIM(TO_CHAR(' || rec.COLUMN_NAME || ')), ''0'') = :b2' ||
                ' OR TRIM(TO_CHAR(' || rec.COLUMN_NAME || ')) LIKE :b3)';
            IF p_item IS NOT NULL AND v_has_item = 1 THEN
                v_sql := v_sql || ' AND TRIM(TO_CHAR(ITEM)) = ''' || REPLACE(p_item, '''', '''''') || '''';
            END IF;
            IF p_cat IS NOT NULL AND v_has_cat = 1 THEN
                v_sql := v_sql || ' AND TRIM(TO_CHAR(CAT)) = ''' || REPLACE(p_cat, '''', '''''') || '''';
            END IF;
            IF p_store IS NOT NULL AND p_store > 0 AND v_has_store = 1 THEN
                v_sql := v_sql || ' AND STORE = ' || TO_CHAR(p_store);
            END IF;

            EXECUTE IMMEDIATE v_sql INTO v_cnt
                USING p_batch, p_batch_n, '%' || p_batch_n || '%';

            IF v_cnt > 0 THEN
                v_sample := '';
                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT TRIM(TO_CHAR(' || rec.COLUMN_NAME || ')) FROM ' || rec.OWNER || '.' || rec.TABLE_NAME ||
                        ' WHERE (TRIM(TO_CHAR(' || rec.COLUMN_NAME || ')) = :b1 OR LTRIM(TRIM(TO_CHAR(' || rec.COLUMN_NAME || ')), ''0'') = :b2) AND ROWNUM = 1'
                        INTO v_sample USING p_batch, p_batch_n;
                EXCEPTION WHEN OTHERS THEN v_sample := '?';
                END;
                DBMS_OUTPUT.PUT_LINE(
                    RPAD(rec.OWNER || '.' || rec.TABLE_NAME || '(' || rec.COLUMN_NAME || ')', 50) ||
                    RPAD(TO_CHAR(v_cnt), 8) ||
                    v_sample
                );
            END IF;
        EXCEPTION
            WHEN OTHERS THEN NULL;
        END;
    END LOOP;
    DBMS_OUTPUT.PUT_LINE('=== Done — الجداول أعلاه فقط التي تحتوي التشغيلة ===');
END;
/

-- 10) بعد البلوك أعلاه: افتح تبويب DBMS Output في Toad لرؤية الجداول التي فيها التشغيلة

-- 11) مقارنة: رصيد STOCK الفعلي vs تشغيلات DAILY (تاريخية)
SELECT 'STOCK_POSITIVE' AS SRC, BATCH, SYS_QTY, MAN_QTY, EXP_DATE
FROM MAS.STOCK
WHERE CAT = 6 AND TRIM(TO_CHAR(ITEM)) = '600029' AND STORE = 4
  AND (NVL(SYS_QTY, 0) > 0 OR NVL(MAN_QTY, 0) > 0)
UNION ALL
SELECT 'DAILY_HISTORY' AS SRC, BATCH, QTY, NULL, VDATE
FROM MAS.DAILY
WHERE TRIM(TO_CHAR(ITEM)) = '600029'
  AND (TRIM(TO_CHAR(BATCH)) = '0263278'
    OR LTRIM(TRIM(TO_CHAR(BATCH)), '0') = '263278'
    OR TRIM(TO_CHAR(BATCH)) LIKE '%263278%')
  AND ROWNUM <= 5;

-- 12) كل تشغيلات المادة 600029 في DAILY (آخر 30 حركة) — لفهم قائمة Forms
SELECT TYPE, V_NUM, VYEAR, BATCH, QTY, STORE, VDATE
FROM MAS.DAILY
WHERE TRIM(TO_CHAR(ITEM)) = '600029'
  AND CAT = 6
  AND BATCH IS NOT NULL
  AND ROWNUM <= 30
ORDER BY VYEAR DESC, V_NUM DESC;
