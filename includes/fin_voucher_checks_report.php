<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher_checks.php');

/**
 * @return array{party_id:int,check_no:string}
 */
function fin_voucher_checks_report_parse_filters(array $input, string $partyKey = 'customer_id'): array
{
    $partyId = (int) ($input[$partyKey] ?? 0);
    if ($partyId < 0) {
        $partyId = 0;
    }

    return [
        'party_id' => $partyId,
        'check_no' => trim((string) ($input['check_no'] ?? '')),
    ];
}

/**
 * @param 'receipt'|'payment' $voucherType
 * @param 'customer'|'supplier'|null $partyTypeFilter
 *
 * @return list<array<string, mixed>>
 */
function fin_voucher_checks_report_fetch(
    PDO $pdo,
    string $voucherType,
    ?string $partyTypeFilter,
    string $fromIso,
    string $toIso,
    string $dateField = 'voucher',
    string $postedFilter = 'all',
    int $partyId = 0,
    string $checkNoFilter = ''
): array {
    if (!in_array($voucherType, ['receipt', 'payment'], true)) {
        return [];
    }
    if (!in_array($dateField, ['voucher', 'due'], true)) {
        $dateField = 'voucher';
    }
    if (!in_array($postedFilter, ['all', 'posted', 'unposted'], true)) {
        $postedFilter = 'all';
    }
    if ($partyId < 0) {
        $partyId = 0;
    }
    $checkNoFilter = trim($checkNoFilter);

    fin_voucher_ensure_schema_full($pdo);
    if (!fin_voucher_checks_ensure_table($pdo) || !fin_voucher_has_table($pdo)) {
        return [];
    }

    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    $postedExpr = $hasPostedCol ? 'v.is_posted' : '0';
    $dateCol = $dateField === 'due' ? 'c.due_date' : 'v.voucher_date';

    $partyJoin = '';
    $partyNameExpr = "'—'";
    if ($voucherType === 'receipt') {
        $partyJoin = "LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND cust.id = v.party_id";
        $partyNameExpr = "COALESCE(cust.name_ar, '—')";
    } else {
        $partyJoin = "LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND cust.id = v.party_id
                      LEFT JOIN crm_supplier sup ON v.party_type = 'supplier' AND sup.id = v.party_id";
        $partyNameExpr = "COALESCE(cust.name_ar, sup.name_ar, '—')";
    }

    $sql =
        "SELECT c.id AS check_id, c.check_no, c.bank_name, c.check_amount, c.due_date, c.notes,
                v.id AS voucher_id, v.voucher_no, v.voucher_date, v.party_id, v.party_type,
                ({$postedExpr}) AS is_posted,
                {$partyNameExpr} AS party_name
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = ?
         {$partyJoin}
         WHERE c.check_amount > 0.000001
           AND {$dateCol} IS NOT NULL
           AND {$dateCol} BETWEEN ? AND ?";

    $params = [$voucherType, $fromIso, $toIso];

    if ($partyTypeFilter !== null) {
        $sql .= ' AND v.party_type = ?';
        $params[] = $partyTypeFilter;
    }

    if ($partyId > 0) {
        $sql .= ' AND v.party_id = ?';
        $params[] = $partyId;
    }

    if ($checkNoFilter !== '') {
        $sql .= ' AND c.check_no LIKE ?';
        $params[] = '%' . $checkNoFilter . '%';
    }

    if ($hasPostedCol) {
        if ($postedFilter === 'posted') {
            $sql .= ' AND v.is_posted = 1';
        } elseif ($postedFilter === 'unposted') {
            $sql .= ' AND v.is_posted = 0';
        }
    }

    $sql .= ' ORDER BY v.voucher_date ASC, v.id ASC, c.sort_order ASC, c.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = fin_voucher_checks_report_normalize_row($row);
    }

    $legacy = fin_voucher_checks_report_fetch_legacy(
        $pdo,
        $voucherType,
        $partyTypeFilter,
        $fromIso,
        $toIso,
        $dateField,
        $postedFilter,
        $partyId,
        $checkNoFilter
    );
    foreach ($legacy as $row) {
        $out[] = $row;
    }

    usort($out, static function (array $a, array $b): int {
        $da = (string) ($a['check_date'] ?? '');
        $db = (string) ($b['check_date'] ?? '');
        if ($da !== $db) {
            return strcmp($da, $db);
        }
        $va = (int) ($a['voucher_id'] ?? 0);
        $vb = (int) ($b['voucher_id'] ?? 0);
        if ($va !== $vb) {
            return $va <=> $vb;
        }

        return (int) ($a['check_id'] ?? 0) <=> (int) ($b['check_id'] ?? 0);
    });

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function fin_voucher_checks_report_normalize_row(array $row): array
{
    return [
        'check_id' => (int) ($row['check_id'] ?? 0),
        'check_no' => trim((string) ($row['check_no'] ?? '')),
        'bank_name' => trim((string) ($row['bank_name'] ?? '')),
        'check_amount' => (float) ($row['check_amount'] ?? 0),
        'due_date' => trim((string) ($row['due_date'] ?? '')),
        'notes' => trim((string) ($row['notes'] ?? '')),
        'voucher_id' => (int) ($row['voucher_id'] ?? 0),
        'voucher_no' => (string) ($row['voucher_no'] ?? ''),
        'party_id' => (int) ($row['party_id'] ?? 0),
        'party_type' => (string) ($row['party_type'] ?? ''),
        'check_date' => (string) ($row['voucher_date'] ?? ''),
        'party_name' => (string) ($row['party_name'] ?? '—'),
        'is_posted' => (int) ($row['is_posted'] ?? 0) === 1,
    ];
}

/**
 * @param 'receipt'|'payment' $voucherType
 * @param 'customer'|'supplier'|null $partyTypeFilter
 *
 * @return list<array<string, mixed>>
 */
function fin_voucher_checks_report_fetch_legacy(
    PDO $pdo,
    string $voucherType,
    ?string $partyTypeFilter,
    string $fromIso,
    string $toIso,
    string $dateField,
    string $postedFilter,
    int $partyId = 0,
    string $checkNoFilter = ''
): array {
    if (!fin_voucher_has_column($pdo, 'pay_method') || $dateField === 'due') {
        return [];
    }

    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    $postedExpr = $hasPostedCol ? 'v.is_posted' : '0';

    if ($voucherType === 'receipt') {
        $partyJoin = 'LEFT JOIN crm_customer cust ON cust.id = v.party_id';
        $partyNameExpr = "COALESCE(cust.name_ar, '—')";
    } else {
        $partyJoin = 'LEFT JOIN crm_customer cust ON v.party_type = \'customer\' AND cust.id = v.party_id
                      LEFT JOIN crm_supplier sup ON v.party_type = \'supplier\' AND sup.id = v.party_id';
        $partyNameExpr = "COALESCE(cust.name_ar, sup.name_ar, '—')";
    }

    $sql =
        "SELECT v.id AS voucher_id, v.voucher_no, v.voucher_date, v.party_id, v.party_type,
                v.check_no, v.bank_name, v.check_amount, ({$postedExpr}) AS is_posted,
                {$partyNameExpr} AS party_name
         FROM fin_voucher v
         {$partyJoin}
         WHERE v.voucher_type = ?
           AND v.pay_method = 'check'
           AND v.check_amount > 0.000001
           AND v.voucher_date BETWEEN ? AND ?
           AND NOT EXISTS (SELECT 1 FROM fin_voucher_check c WHERE c.voucher_id = v.id)";

    $params = [$voucherType, $fromIso, $toIso];

    if ($partyTypeFilter !== null) {
        $sql .= ' AND v.party_type = ?';
        $params[] = $partyTypeFilter;
    }

    if ($partyId > 0) {
        $sql .= ' AND v.party_id = ?';
        $params[] = $partyId;
    }

    if ($checkNoFilter !== '') {
        $sql .= ' AND v.check_no LIKE ?';
        $params[] = '%' . $checkNoFilter . '%';
    }

    if ($hasPostedCol) {
        if ($postedFilter === 'posted') {
            $sql .= ' AND v.is_posted = 1';
        } elseif ($postedFilter === 'unposted') {
            $sql .= ' AND v.is_posted = 0';
        }
    }

    $sql .= ' ORDER BY v.voucher_date ASC, v.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = fin_voucher_checks_report_normalize_row([
            'check_id' => 0,
            'check_no' => $row['check_no'] ?? '',
            'bank_name' => $row['bank_name'] ?? '',
            'check_amount' => $row['check_amount'] ?? 0,
            'due_date' => '',
            'notes' => '',
            'voucher_id' => $row['voucher_id'] ?? 0,
            'voucher_no' => $row['voucher_no'] ?? '',
            'party_id' => $row['party_id'] ?? 0,
            'party_type' => $row['party_type'] ?? '',
            'voucher_date' => $row['voucher_date'] ?? '',
            'party_name' => $row['party_name'] ?? '—',
            'is_posted' => $row['is_posted'] ?? 0,
        ]);
    }

    return $out;
}

function fin_voucher_checks_report_posted_label(bool $isPosted): string
{
    return $isPosted ? 'مرحّل' : 'غير مرحّل';
}

function fin_voucher_checks_report_party_label(
    PDO $pdo,
    int $partyId,
    string $table,
    string $allLabel
): string {
    if ($partyId < 1) {
        return $allLabel;
    }
    if (!in_array($table, ['crm_customer', 'crm_supplier'], true)) {
        return '#' . $partyId;
    }
    try {
        $st = $pdo->prepare("SELECT name_ar FROM {$table} WHERE id = ? LIMIT 1");
        $st->execute([$partyId]);
        $name = trim((string) $st->fetchColumn());

        return $name !== '' ? $name : '#' . $partyId;
    } catch (Throwable $e) {
        return '#' . $partyId;
    }
}
