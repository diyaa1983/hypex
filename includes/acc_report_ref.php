<?php
declare(strict_types=1);

/** @return array<string, string> */
function acc_report_ref_type_labels(): array
{
    return [
        'sale_invoice' => 'فاتورة بيع',
        'purchase_invoice' => 'فاتورة شراء',
        'sale_return' => 'مردود مبيعات',
        'purchase_return' => 'مردود مشتريات',
        'cash_receipt' => 'سند قبض',
        'cash_payment' => 'سند صرف',
        'debit_note' => 'إشعار مدين',
        'credit_note' => 'إشعار دائن',
        'warehouse_move' => 'حركة مستودع',
        'fin_check_clear' => 'صرف/تحصيل شيك',
        'fin_check_return' => 'إرجاع شيك',
        'fin_check_endorse' => 'تجيير شيك',
        'year_close' => 'إقفال سنة مالية',
    ];
}

function acc_report_ref_type_label(string $refType): string
{
    return acc_report_ref_type_labels()[$refType] ?? $refType;
}

/** @return array<string, array{r: string, param: string, permission: string}> */
function acc_report_ref_routes(): array
{
    return [
        'sale_invoice' => ['r' => 'sales_invoices', 'param' => 'id', 'permission' => 'sales_invoices'],
        'purchase_invoice' => ['r' => 'purchase_invoices', 'param' => 'id', 'permission' => 'purchase_invoices'],
        'sale_return' => ['r' => 'sales_returns', 'param' => 'id', 'permission' => 'sales_returns'],
        'purchase_return' => ['r' => 'purchase_returns', 'param' => 'id', 'permission' => 'purchase_returns'],
        'cash_receipt' => ['r' => 'cash_receipt', 'param' => 'id', 'permission' => 'cash_receipt'],
        'cash_payment' => ['r' => 'cash_payment', 'param' => 'id', 'permission' => 'cash_payment'],
        'debit_note' => ['r' => 'debit_notes', 'param' => 'id', 'permission' => 'debit_notes'],
        'credit_note' => ['r' => 'credit_notes', 'param' => 'id', 'permission' => 'credit_notes'],
        'warehouse_move' => ['r' => 'warehouse_moves', 'param' => 'id', 'permission' => 'warehouse_moves'],
        'fin_check_clear' => ['r' => 'fin_checks', 'param' => 'check_id', 'permission' => 'fin_checks'],
        'fin_check_return' => ['r' => 'fin_checks', 'param' => 'check_id', 'permission' => 'fin_checks'],
        'fin_check_endorse' => ['r' => 'fin_checks', 'param' => 'check_id', 'permission' => 'fin_checks'],
    ];
}

function acc_report_ref_permission(string $refType): ?string
{
    return acc_report_ref_routes()[$refType]['permission'] ?? null;
}

function acc_report_ref_url(string $refType, int $refId): ?string
{
    if ($refId < 1 || $refType === '') {
        return null;
    }

    $routes = acc_report_ref_routes();
    if (!isset($routes[$refType])) {
        return null;
    }

    $meta = $routes[$refType];

    return app_url('index.php?r=' . rawurlencode($meta['r']) . '&' . $meta['param'] . '=' . $refId);
}

/**
 * رابط فتح المستند المرتبط بقيد (سند قبض/صرف، فاتورة… أو سند قيد يدوي).
 *
 * @param array<string, mixed> $header صف acc_journal_entry
 * @return array{url: string, label: string}|null
 */
function acc_journal_entry_open_link(array $header, bool $withHub = true): ?array
{
    $journalId = (int) ($header['id'] ?? 0);
    if ($journalId < 1) {
        return null;
    }

    $refType = trim((string) ($header['ref_type'] ?? ''));
    $refId = (int) ($header['ref_id'] ?? 0);
    $source = (string) ($header['source'] ?? '');
    $hub = $withHub && function_exists('nav_hub_query_for_redirect') ? nav_hub_query_for_redirect() : '';

    if ($source === 'auto' && $refType !== '' && $refId > 0) {
        $perm = acc_report_ref_permission($refType);
        if ($perm !== null && user_can($perm)) {
            $url = acc_report_ref_url($refType, $refId);
            if ($url !== null) {
                return [
                    'url' => $url . $hub,
                    'label' => 'فتح ' . acc_report_ref_type_label($refType),
                ];
            }
        }

        return null;
    }

    if (!user_can('journal_voucher')) {
        return null;
    }

    return [
        'url' => app_url('index.php?r=journal_voucher&id=' . $journalId . $hub),
        'label' => 'فتح في شاشة السندات',
    ];
}

/**
 * @param array<string, mixed> $header
 * @return array{check_id: int, label: string}|null
 */
function acc_journal_entry_check_undo(array $header): ?array
{
    if (!user_can('fin_checks')) {
        return null;
    }

    $source = (string) ($header['source'] ?? '');
    $refType = trim((string) ($header['ref_type'] ?? ''));
    $refId = (int) ($header['ref_id'] ?? 0);
    if ($source !== 'auto' || $refId < 1) {
        return null;
    }

    if ($refType === 'fin_check_clear') {
        return ['check_id' => $refId, 'label' => 'إلغاء الصرف'];
    }
    if ($refType === 'fin_check_return') {
        return ['check_id' => $refId, 'label' => 'إلغاء الإرجاع'];
    }
    if ($refType === 'fin_check_endorse') {
        return ['check_id' => $refId, 'label' => 'إلغاء التجيير'];
    }

    return null;
}

function acc_report_journal_voucher_url(int $journalId, string $entryNo = '', string $returnUrl = ''): string
{
    if (str_starts_with(strtoupper($entryNo), 'SQ-')) {
        $url = app_url('index.php?r=journal_voucher&id=' . $journalId);
    } else {
        $url = app_url('index.php?r=journal_entries&action=view&id=' . $journalId);
    }

    $returnUrl = trim($returnUrl);
    if ($returnUrl !== '') {
        require_once app_path('includes/nav_helpers.php');
        if (nav_is_safe_back_url($returnUrl)) {
            $url .= '&return=' . rawurlencode($returnUrl);
        }
    }

    return $url;
}

function acc_report_account_statement_url(int $accountId, string $dateFrom, string $dateTo): string
{
    return app_url(
        'index.php?r=report_account_statement'
        . '&account_id=' . $accountId
        . '&date_from=' . rawurlencode(format_date_dmY($dateFrom))
        . '&date_to=' . rawurlencode(format_date_dmY($dateTo))
    );
}

function acc_report_general_ledger_url(int $accountId, string $dateFrom, string $dateTo): string
{
    return acc_report_account_statement_url($accountId, $dateFrom, $dateTo);
}
