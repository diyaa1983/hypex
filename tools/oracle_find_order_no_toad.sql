-- ============================================================
-- اكتشاف عمود «رقم الطلبية» في INV00024
-- جداول: MAS.DAILY (بنود) + MAS.MASTER_D (رأس)
-- ============================================================

-- 1) كل الأعمدة المرشّحة (طلب / مستند / مرجع)
SELECT table_name, column_name, data_type, data_length, data_precision
FROM all_tab_columns
WHERE owner = 'MAS'
  AND table_name IN ('DAILY', 'MASTER_D')
  AND (
       UPPER(column_name) LIKE '%ORD%'
    OR UPPER(column_name) LIKE '%ORDER%'
    OR UPPER(column_name) LIKE '%REQ%'
    OR UPPER(column_name) LIKE '%DOC%'
    OR UPPER(column_name) LIKE '%REF%'
    OR UPPER(column_name) LIKE '%PO%'
  )
ORDER BY table_name, column_id;

-- 2) قارن فاتورة أُدخلت يدوياً في Forms وفيها رقم طلبية
--    مع فاتورة مرحّلة من Hypex (الحقل فارغ)
-- بدّل الأرقام:
--   :forms_v  = رقم فاتورة Forms فيها رقم طلبية ظاهر
--   :hypex_v  = رقم فاتورة Hypex والحقل فارغ

SELECT 'FORMS' AS src, d.*
FROM mas.daily d
WHERE d.type = 9 AND d.vyear = 2026 AND d.v_num = 2907 AND ROWNUM = 1
UNION ALL
SELECT 'HYPEX' AS src, d.*
FROM mas.daily d
WHERE d.type = 9 AND d.vyear = 2026 AND d.v_num = 2913 AND ROWNUM = 1;

-- 3) نفس المقارنة على الرأس
SELECT 'FORMS' AS src, m.*
FROM mas.master_d m
WHERE m.type = 9 AND m.vyear = 2026 AND m.v_num = 2907 AND ROWNUM = 1
UNION ALL
SELECT 'HYPEX' AS src, m.*
FROM mas.master_d m
WHERE m.type = 9 AND m.vyear = 2026 AND m.v_num = 2913 AND ROWNUM = 1;

-- 4) بعد معرفة العمود (مثال DOC_NO) — حدّث فاتورة مرحّلة للتحقق الفوري:
-- UPDATE mas.daily SET doc_no = '2026-1' WHERE type = 9 AND vyear = 2026 AND v_num = ??;
-- UPDATE mas.master_d SET doc_no = '2026-1' WHERE type = 9 AND vyear = 2026 AND v_num = ??;
-- COMMIT;
-- ثم F7 → الرقم → F8 في INV00024
