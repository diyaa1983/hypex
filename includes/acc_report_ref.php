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
    ];
}

function acc_report_ref_type_label(string $refType): string
{
    return acc_report_ref_type_labels()[$refType] ?? $refType;
}

function acc_report_ref_url(string $refType, int $refId): ?string
{
    if ($refId < 1 || $refType === '') {
        return null;
    }

    $routes = [
        'sale_invoice' => ['r' => 'sales_invoices', 'param' => 'id'],
        'purchase_invoice' => ['r' => 'purchase_invoices', 'param' => 'id'],
        'sale_return' => ['r' => 'sales_returns', 'param' => 'id'],
        'purchase_return' => ['r' => 'purchase_returns', 'param' => 'id'],
        'cash_receipt' => ['r' => 'cash_receipt', 'param' => 'id'],
        'cash_payment' => ['r' => 'cash_payment', 'param' => 'id'],
        'debit_note' => ['r' => 'debit_notes', 'param' => 'id'],
        'credit_note' => ['r' => 'credit_notes', 'param' => 'id'],
        'warehouse_move' => ['r' => 'warehouse_moves', 'param' => 'id'],
    ];

    if (!isset($routes[$refType])) {
        return null;
    }

    $meta = $routes[$refType];

    return app_url('index.php?r=' . rawurlencode($meta['r']) . '&' . $meta['param'] . '=' . $refId);
}

function acc_report_journal_voucher_url(int $journalId, string $entryNo = ''): string
{
    if (str_starts_with(strtoupper($entryNo), 'SQ-')) {
        return app_url('index.php?r=journal_voucher&id=' . $journalId);
    }

    return app_url('index.php?r=journal_entries&action=view&id=' . $journalId);
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
