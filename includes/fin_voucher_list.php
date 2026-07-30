<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/list_pagination.php');

/**
 * @return array{rows:list<array<string,mixed>>, total:int, filtered_total:int, sum_amount:float, unposted:int, has_posted_col:bool, pager:array<string,mixed>}
 */
function fin_voucher_list_fetch(
    PDO $pdo,
    string $voucherType,
    string $filter,
    string $search,
    int $apiLimit = 0,
    ?int $salesRepId = null
): array {
    if (!in_array($filter, ['all', 'unposted', 'posted'], true)) {
        $filter = 'all';
    }

    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    $hasPayMethod = fin_voucher_has_column($pdo, 'pay_method');
    $hasLedger = crm_ledger_has_table($pdo);

    $txnType = $voucherType === 'receipt' ? 'cash_receipt' : 'cash_payment';

    if ($hasPostedCol) {
        $postedExpr = 'v.is_posted';
    } elseif ($hasLedger) {
        $postedExpr = "(EXISTS (
            SELECT 1 FROM crm_customer_ledger l
            WHERE l.txn_type = '{$txnType}' AND l.ref_id = v.id
              AND (v.party_type <> 'customer' OR l.customer_id = v.party_id)
        ))";
    } else {
        $postedExpr = '0';
    }

    $payCol = $hasPayMethod ? 'v.pay_method' : "'cash' AS pay_method";

    $fromWhere = ' FROM fin_voucher v
            LEFT JOIN crm_customer c ON v.party_type = \'customer\' AND c.id = v.party_id
            LEFT JOIN crm_supplier s ON v.party_type = \'supplier\' AND s.id = v.party_id
            WHERE v.voucher_type = ?';
    $params = [$voucherType];

    $hasCancelledCol = fin_voucher_has_column($pdo, 'is_cancelled');
    $cancelledExpr = $hasCancelledCol ? 'COALESCE(v.is_cancelled, 0)' : '0';

    if ($filter === 'unposted') {
        if ($hasPostedCol) {
            $fromWhere .= ' AND v.is_posted = 0';
            if ($hasCancelledCol) {
                $fromWhere .= ' AND v.is_cancelled = 0';
            }
        } elseif ($hasLedger) {
            $fromWhere .= " AND NOT EXISTS (
                SELECT 1 FROM crm_customer_ledger l
                WHERE l.txn_type = ? AND l.ref_id = v.id
            )";
            $params[] = $txnType;
        } else {
            $fromWhere .= ' AND 1=0';
        }
    } elseif ($filter === 'posted') {
        if ($hasPostedCol) {
            $fromWhere .= ' AND v.is_posted = 1';
            if ($hasCancelledCol) {
                $fromWhere .= ' AND v.is_cancelled = 0';
            }
        } elseif ($hasLedger) {
            $fromWhere .= " AND EXISTS (
                SELECT 1 FROM crm_customer_ledger l
                WHERE l.txn_type = ? AND l.ref_id = v.id
            )";
            $params[] = $txnType;
        } else {
            $fromWhere .= ' AND 1=0';
        }
    }

    if ($search !== '') {
        $searchParts = 'v.voucher_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?
                      OR s.name_ar LIKE ? OR s.code LIKE ?';
        $like = '%' . $search . '%';
        $searchParams = [$like, $like, $like, $like, $like];
        if (fin_voucher_has_column($pdo, 'description')) {
            $searchParts .= ' OR v.description LIKE ?';
            $searchParams[] = $like;
        }
        $fromWhere .= ' AND (' . $searchParts . ')';
        $params = array_merge($params, $searchParams);
    }

    if ($salesRepId !== null && $salesRepId > 0 && $voucherType === 'receipt') {
        require_once app_path('includes/crm_sales_rep_schema.php');
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $salesRepId);
        $fromWhere .= ' AND v.party_type = \'customer\' AND ' . $linkSql;
        $params = array_merge($params, $linkParams);
    }

    $sql = "SELECT v.id, v.voucher_no, v.voucher_date, v.amount, v.party_type, v.party_id,
                   {$payCol},
                   ({$postedExpr}) AS is_posted,
                   ({$cancelledExpr}) AS is_cancelled,
                   COALESCE(c.name_ar, s.name_ar, '—') AS party_name"
        . $fromWhere
        . ' ORDER BY v.id DESC';

    if ($apiLimit > 0) {
        $apiLimit = max(1, min(200, $apiLimit));
        $sql .= ' LIMIT ' . (int) $apiLimit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'total' => count($rows),
            'filtered_total' => count($rows),
            'sum_amount' => 0.0,
            'unposted' => 0,
            'has_posted_col' => $hasPostedCol,
            'pager' => [
                'page' => 1,
                'per_page' => $apiLimit,
                'limit' => $apiLimit,
                'offset' => 0,
                'total' => count($rows),
                'total_pages' => 1,
            ],
        ];
    }

    $stCount = $pdo->prepare('SELECT COUNT(*)' . $fromWhere);
    $stCount->execute($params);
    $filteredTotal = (int) $stCount->fetchColumn();

    $stSum = $pdo->prepare('SELECT COALESCE(SUM(v.amount), 0)' . $fromWhere);
    $stSum->execute($params);
    $sumAmount = round((float) $stSum->fetchColumn(), 6);

    $pager = list_pager_with_total(list_pager_from_request($pdo), $filteredTotal);

    $sql .= list_pager_sql_limit($pager);

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stTotal = $pdo->prepare('SELECT COUNT(*) FROM fin_voucher WHERE voucher_type = ?');
    $stTotal->execute([$voucherType]);
    $total = (int) $stTotal->fetchColumn();

    require_once app_path('includes/fin_voucher_post.php');
    $countKey = $voucherType === 'receipt' ? 'receipts' : 'payments';
    $counts = fin_voucher_count_unposted($pdo, $voucherType);
    $unposted = (int) ($counts[$countKey] ?? 0);

    return [
        'rows' => $rows,
        'total' => $total,
        'filtered_total' => $filteredTotal,
        'sum_amount' => $sumAmount,
        'unposted' => $unposted,
        'has_posted_col' => $hasPostedCol,
        'pager' => $pager,
    ];
}
