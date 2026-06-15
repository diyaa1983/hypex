<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher_browse.php');

/** @return array<string, mixed>|null */
function fin_voucher_fetch_by_id(PDO $pdo, int $id, string $type): ?array
{
    $row = fin_voucher_load($pdo, $id, $type);
    if (!$row) {
        return null;
    }

    return fin_voucher_enrich_row($pdo, $row);
}

/** @return array<string, mixed>|null */
function fin_voucher_fetch_by_no(PDO $pdo, string $voucherNo, string $type): ?array
{
    $voucherNo = trim($voucherNo);
    if ($voucherNo === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM fin_voucher WHERE voucher_no = ? AND voucher_type = ? LIMIT 1');
    $st->execute([$voucherNo, $type]);
    $id = $st->fetchColumn();

    return $id !== false ? fin_voucher_fetch_by_id($pdo, (int) $id, $type) : null;
}

/** @param array<string, mixed> $row */
function fin_voucher_enrich_row(PDO $pdo, array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $type = (string) ($row['voucher_type'] ?? '');
    $partyType = (string) ($row['party_type'] ?? 'other');
    $partyId = (int) ($row['party_id'] ?? 0);

    $row['is_posted'] = fin_voucher_is_posted($pdo, $id);
    $row['prev_id'] = fin_voucher_nav_neighbor_id($pdo, $id, $type, 'prev') ?? 0;
    $row['next_id'] = fin_voucher_nav_neighbor_id($pdo, $id, $type, 'next') ?? 0;
    $row['customer_name'] = '';
    $row['supplier_name'] = '';
    $row['party_name'] = '';
    $row['sales_rep_name'] = '';

    if ($partyType === 'customer' && $partyId > 0) {
        $st = $pdo->prepare(
            'SELECT c.name_ar, c.code, r.name_ar AS sales_rep_name
             FROM crm_customer c
             LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
             WHERE c.id = ? LIMIT 1'
        );
        $st->execute([$partyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $row['customer_name'] = (string) ($c['name_ar'] ?? '');
            $row['party_name'] = $row['customer_name'];
            $row['sales_rep_name'] = (string) ($c['sales_rep_name'] ?? '');
        }
    } elseif ($partyType === 'supplier' && $partyId > 0) {
        $st = $pdo->prepare('SELECT name_ar, code FROM crm_supplier WHERE id = ? LIMIT 1');
        $st->execute([$partyId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if ($s) {
            $row['supplier_name'] = (string) ($s['name_ar'] ?? '');
            $row['party_name'] = $row['supplier_name'];
        }
    }

    $row['pay_method'] = fin_voucher_normalize_pay_method((string) ($row['pay_method'] ?? 'cash'));

    return $row;
}
