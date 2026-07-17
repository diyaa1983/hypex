<?php
declare(strict_types=1);

/**
 * تاريخ بدء متابعة إرسال الفوترة في هذا النظام (شامل).
 * الفواتير/المرتجعات الأقدم أُرسلت عبر النظام السابق ولا تُحسب «غير مرسلة».
 */
function sal_einvoice_tracking_cutoff_iso(): string
{
    return '2026-06-01';
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

/**
 * فاتورة بيع أقدم من تاريخ المتابعة = أُرسلت عبر النظام السابق.
 * تُستخدم للسماح بإرسال مرتجعاتها للفوترة دون وجود einv_qr في هذا النظام.
 */
function sal_einvoice_invoice_is_legacy_pre_tracking(?string $invoiceDateIso): bool
{
    $date = trim((string) $invoiceDateIso);
    if ($date === '') {
        return false;
    }

    return $date < sal_einvoice_tracking_cutoff_iso();
}

/** شرط SQL: الفاتورة الأصلية تُعدّ مؤهّلة لإرسال المرتجع (مُرسلة هنا أو قبل نطاق المتابعة). */
function sal_einvoice_sql_invoice_eligible_for_return_send(PDO $pdo, string $invoiceAlias = 'i'): string
{
    require_once app_path('includes/sal_documents_list.php');
    $invoiceAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $invoiceAlias) ?: 'i';
    $sent = sal_documents_list_einv_sent_expr_invoice($pdo, $invoiceAlias);
    $cutoff = sal_einvoice_tracking_cutoff_iso();

    return "(({$sent}) OR {$invoiceAlias}.invoice_date < '{$cutoff}')";
}

/**
 * هل الفاتورة الأصلية تسمح بإرسال المرتجع للفوترة؟
 * — مُرسلة في هذا النظام، أو تاريخها قبل بدء المتابعة (نظام قديم).
 */
function sal_einvoice_invoice_allows_return_send(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    require_once app_path('includes/einvoice_settings.php');
    if (einvoice_sale_is_sent($pdo, $invoiceId)) {
        return true;
    }
    $st = $pdo->prepare('SELECT invoice_date FROM sal_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $date = $st->fetchColumn();

    return is_string($date) && sal_einvoice_invoice_is_legacy_pre_tracking($date);
}
