<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_checks_report.php');

/** @return array{customer_id:int,check_no:string} */
function fin_incoming_checks_report_parse_filters(array $input): array
{
    $parsed = fin_voucher_checks_report_parse_filters($input, 'customer_id');

    return [
        'customer_id' => $parsed['party_id'],
        'check_no' => $parsed['check_no'],
    ];
}

function fin_incoming_checks_report_fetch(
    PDO $pdo,
    string $fromIso,
    string $toIso,
    string $dateField = 'voucher',
    string $postedFilter = 'all',
    int $customerId = 0,
    string $checkNoFilter = ''
): array {
    return fin_voucher_checks_report_fetch(
        $pdo,
        'receipt',
        'customer',
        $fromIso,
        $toIso,
        $dateField,
        $postedFilter,
        $customerId,
        $checkNoFilter
    );
}

function fin_incoming_checks_report_posted_label(bool $isPosted): string
{
    return fin_voucher_checks_report_posted_label($isPosted);
}

function fin_incoming_checks_report_customer_label(PDO $pdo, int $customerId): string
{
    return fin_voucher_checks_report_party_label($pdo, $customerId, 'crm_customer', 'جميع العملاء');
}

/** @return array{supplier_id:int,check_no:string} */
function fin_outgoing_checks_report_parse_filters(array $input): array
{
    $parsed = fin_voucher_checks_report_parse_filters($input, 'supplier_id');

    return [
        'supplier_id' => $parsed['party_id'],
        'check_no' => $parsed['check_no'],
    ];
}

function fin_outgoing_checks_report_fetch(
    PDO $pdo,
    string $fromIso,
    string $toIso,
    string $dateField = 'voucher',
    string $postedFilter = 'all',
    int $supplierId = 0,
    string $checkNoFilter = '',
    string $checkScope = 'all'
): array {
    return fin_voucher_checks_report_fetch(
        $pdo,
        'payment',
        'supplier',
        $fromIso,
        $toIso,
        $dateField,
        $postedFilter,
        $supplierId,
        $checkNoFilter,
        $checkScope
    );
}

function fin_outgoing_checks_report_posted_label(bool $isPosted): string
{
    return fin_voucher_checks_report_posted_label($isPosted);
}

function fin_outgoing_checks_report_supplier_label(PDO $pdo, int $supplierId): string
{
    return fin_voucher_checks_report_party_label($pdo, $supplierId, 'crm_supplier', 'جميع الموردين');
}
