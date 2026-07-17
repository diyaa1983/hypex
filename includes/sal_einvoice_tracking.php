<?php
declare(strict_types=1);

/**
 * تاريخ بدء متابعة إرسال الفوترة في هذا النظام (شامل).
 * الفواتير/المرتجعات الأقدم أُرسلت عبر النظام السابق ولا تُحسب «غير مرسلة».
 */
function sal_einvoice_tracking_cutoff_iso(): string
{
    return '2026-05-14';
}

/** هل يُطبَّق تتبّع الفوترة على مستند بهذا التاريخ؟ */
function sal_einvoice_doc_date_requires_tracking(?string $docDateIso): bool
{
    $date = trim((string) $docDateIso);
    if ($date === '') {
        return false;
    }

    return $date >= sal_einvoice_tracking_cutoff_iso();
}

/** شرط SQL: فاتورة بيع ضمن نطاق متابعة الفوترة. */
function sal_einvoice_sql_invoice_requires_tracking(string $alias = 'i'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'i';
    $cutoff = sal_einvoice_tracking_cutoff_iso();

    return "{$alias}.invoice_date >= '{$cutoff}'";
}

/** شرط SQL: مرتجع بيع ضمن نطاق متابعة الفوترة. */
function sal_einvoice_sql_return_requires_tracking(string $alias = 'r'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'r';
    $cutoff = sal_einvoice_tracking_cutoff_iso();

    return "{$alias}.return_date >= '{$cutoff}'";
}
