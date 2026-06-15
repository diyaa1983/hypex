<?php
declare(strict_types=1);

require_once app_path('includes/dashboard_stats.php');

const HEADER_UNPOSTED_PER_KIND = 8;
const HEADER_UNPOSTED_PANEL_MAX = 20;

/**
 * @return list<array<string,mixed>>
 */
function header_unposted_notifications_collect(PDO $pdo): array
{
    if (!header_unposted_notifications_user_can_see()) {
        return [];
    }

    $items = [];
    $perKind = HEADER_UNPOSTED_PER_KIND;

    if (header_unposted_user_can('sales_invoices', 'sales_invoices_list')
        && dashboard_table_exists($pdo, 'sal_invoice')) {
        require_once app_path('includes/sal_invoice_post.php');
        $notPosted = 'NOT ' . sal_invoice_sql_is_posted_expr('i');
        $items = array_merge($items, header_unposted_fetch_rows(
            $pdo,
            "SELECT i.id, i.invoice_no AS doc_no, i.invoice_date AS doc_date, i.total AS amount,
                    c.name_ar AS party_name
             FROM sal_invoice i
             INNER JOIN crm_customer c ON c.id = i.customer_id
             WHERE i.status = 'confirmed' AND {$notPosted}
             ORDER BY i.invoice_date ASC, i.id ASC
             LIMIT {$perKind}",
            [],
            'sale_invoice',
            'فاتورة بيع',
            app_url('index.php?r=sales_invoices&id=')
        ));
    }

    if (header_unposted_user_can('sales_returns', 'sales_returns_list')
        && dashboard_table_exists($pdo, 'sal_return')) {
        require_once app_path('includes/sal_return_post.php');
        $notPosted = 'NOT ' . sal_return_sql_is_posted_expr('r');
        $items = array_merge($items, header_unposted_fetch_rows(
            $pdo,
            "SELECT r.id, r.return_no AS doc_no, r.return_date AS doc_date, r.total AS amount,
                    c.name_ar AS party_name
             FROM sal_return r
             INNER JOIN crm_customer c ON c.id = r.customer_id
             WHERE r.status <> 'cancelled' AND {$notPosted}
             ORDER BY r.return_date ASC, r.id ASC
             LIMIT {$perKind}",
            [],
            'sale_return',
            'مردود مبيعات',
            app_url('index.php?r=sales_returns&id=')
        ));
    }

    if (header_unposted_user_can('purchase_invoices', 'purchase_invoices_list')
        && dashboard_table_exists($pdo, 'pur_invoice')) {
        require_once app_path('includes/pur_invoice_post.php');
        $notPosted = 'NOT ' . pur_invoice_sql_is_posted_expr('i');
        $items = array_merge($items, header_unposted_fetch_rows(
            $pdo,
            "SELECT i.id, i.invoice_no AS doc_no, i.invoice_date AS doc_date, i.total AS amount,
                    s.name_ar AS party_name
             FROM pur_invoice i
             INNER JOIN crm_supplier s ON s.id = i.supplier_id
             WHERE i.status = 'confirmed' AND {$notPosted}
             ORDER BY i.invoice_date ASC, i.id ASC
             LIMIT {$perKind}",
            [],
            'purchase_invoice',
            'فاتورة شراء',
            app_url('index.php?r=purchase_invoices&id=')
        ));
    }

    if (header_unposted_user_can('purchase_returns', 'purchase_returns_list')
        && dashboard_table_exists($pdo, 'pur_return')) {
        require_once app_path('includes/pur_return_post.php');
        $notPosted = 'NOT ' . pur_return_sql_is_posted_expr('r');
        $items = array_merge($items, header_unposted_fetch_rows(
            $pdo,
            "SELECT r.id, r.return_no AS doc_no, r.return_date AS doc_date, r.total AS amount,
                    s.name_ar AS party_name
             FROM pur_return r
             INNER JOIN crm_supplier s ON s.id = r.supplier_id
             WHERE r.status = 'confirmed' AND {$notPosted}
             ORDER BY r.return_date ASC, r.id ASC
             LIMIT {$perKind}",
            [],
            'purchase_return',
            'مردود مشتريات',
            app_url('index.php?r=purchase_returns&id=')
        ));
    }

    if (header_unposted_user_can('cash_receipt', 'cash_receipts_list')) {
        $items = array_merge($items, header_unposted_fin_vouchers($pdo, 'receipt', $perKind));
    }

    if (header_unposted_user_can('cash_payment', 'cash_payments_list')) {
        $items = array_merge($items, header_unposted_fin_vouchers($pdo, 'payment', $perKind));
    }

    if (user_can('journal_entries') && dashboard_table_exists($pdo, 'acc_journal_entry')) {
        $items = array_merge($items, header_unposted_fetch_rows(
            $pdo,
            "SELECT e.id, e.entry_no AS doc_no, e.entry_date AS doc_date, 0 AS amount,
                    COALESCE(e.description_ar, '') AS party_name
             FROM acc_journal_entry e
             WHERE e.status = 'draft'
             ORDER BY e.entry_date ASC, e.id ASC
             LIMIT {$perKind}",
            [],
            'journal_entry',
            'قيد يومية',
            app_url('index.php?r=journal_entries&action=edit&id=')
        ));
    }

    if (user_can('warehouse_moves') && dashboard_table_exists($pdo, 'inv_wh_move')) {
        $items = array_merge($items, header_unposted_fetch_rows(
            $pdo,
            "SELECT m.id, m.move_no AS doc_no, m.move_date AS doc_date, 0 AS amount,
                    COALESCE(w.name_ar, '') AS party_name
             FROM inv_wh_move m
             LEFT JOIN inv_warehouse w ON w.id = m.warehouse_id
             WHERE m.status = 'draft'
             ORDER BY m.move_date ASC, m.id ASC
             LIMIT {$perKind}",
            [],
            'warehouse_move',
            'حركة مستودع',
            app_url('index.php?r=warehouse_moves&id=')
        ));
    }

    if (user_can('inventory_stocktake') && dashboard_table_exists($pdo, 'inv_stocktake_doc')) {
        $items = array_merge($items, header_unposted_fetch_rows(
            $pdo,
            "SELECT d.id, d.take_no AS doc_no, d.take_date AS doc_date, 0 AS amount,
                    COALESCE(w.name_ar, '') AS party_name
             FROM inv_stocktake_doc d
             LEFT JOIN inv_warehouse w ON w.id = d.warehouse_id
             WHERE d.status = 'draft'
             ORDER BY d.take_date ASC, d.id ASC
             LIMIT {$perKind}",
            [],
            'stocktake',
            'جرد مخزون',
            app_url('index.php?r=inventory_stocktake&id=')
        ));
    }

    if (user_can('sales_delivery') && dashboard_table_exists($pdo, 'sal_delivery')) {
        require_once app_path('includes/sal_delivery_schema.php');
        if (sal_delivery_has_table($pdo)) {
            $items = array_merge($items, header_unposted_fetch_rows(
                $pdo,
                "SELECT d.id, d.delivery_no AS doc_no, d.delivery_date AS doc_date, 0 AS amount,
                        c.name_ar AS party_name
                 FROM sal_delivery d
                 INNER JOIN crm_customer c ON c.id = d.customer_id
                 WHERE d.is_posted = 0
                   AND EXISTS (SELECT 1 FROM sal_delivery_line dl WHERE dl.delivery_id = d.id)
                 ORDER BY d.delivery_date ASC, d.id ASC
                 LIMIT {$perKind}",
                [],
                'sales_delivery',
                'سند تسليم',
                app_url('index.php?r=sales_delivery&id=')
            ));
        }
    }

    usort($items, static function (array $a, array $b): int {
        $dateCmp = strcmp((string) ($a['doc_date'] ?? ''), (string) ($b['doc_date'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }

        return strcmp((string) ($a['kind'] ?? ''), (string) ($b['kind'] ?? ''));
    });

    return array_slice($items, 0, HEADER_UNPOSTED_PANEL_MAX);
}

function header_unposted_notifications_user_can_see(): bool
{
    $perms = [
        'sales_invoices',
        'sales_invoices_list',
        'sales_returns',
        'sales_returns_list',
        'purchase_invoices',
        'purchase_invoices_list',
        'purchase_returns',
        'purchase_returns_list',
        'cash_receipt',
        'cash_receipts_list',
        'cash_payment',
        'cash_payments_list',
        'journal_entries',
        'warehouse_moves',
        'inventory_stocktake',
        'sales_delivery',
    ];
    foreach ($perms as $perm) {
        if (user_can($perm)) {
            return true;
        }
    }

    return false;
}

function header_unposted_notifications_count(PDO $pdo): int
{
    if (!header_unposted_notifications_user_can_see()) {
        return 0;
    }

    $total = 0;

    if (header_unposted_user_can('sales_invoices', 'sales_invoices_list')
        || header_unposted_user_can('sales_returns', 'sales_returns_list')) {
        require_once app_path('includes/crm_customer_ledger.php');
        if (crm_ledger_ensure_schema($pdo)) {
            $counts = crm_ledger_count_unposted($pdo);
            if (header_unposted_user_can('sales_invoices', 'sales_invoices_list')) {
                $total += (int) ($counts['invoices'] ?? 0);
            }
            if (header_unposted_user_can('sales_returns', 'sales_returns_list')) {
                $total += (int) ($counts['returns'] ?? 0);
            }
        }
    }

    if (header_unposted_user_can('purchase_invoices', 'purchase_invoices_list')
        || header_unposted_user_can('purchase_returns', 'purchase_returns_list')) {
        require_once app_path('includes/crm_supplier_ledger.php');
        if (crm_supplier_ledger_ensure_schema($pdo)) {
            $counts = crm_supplier_ledger_count_unposted($pdo);
            if (header_unposted_user_can('purchase_invoices', 'purchase_invoices_list')) {
                $total += (int) ($counts['invoices'] ?? 0);
            }
            if (header_unposted_user_can('purchase_returns', 'purchase_returns_list')) {
                $total += (int) ($counts['returns'] ?? 0);
            }
        }
    }

    if (header_unposted_user_can('cash_receipt', 'cash_receipts_list')) {
        require_once app_path('includes/fin_voucher_post.php');
        $counts = fin_voucher_count_unposted($pdo, 'receipt');
        $total += (int) ($counts['receipts'] ?? 0);
    }

    if (header_unposted_user_can('cash_payment', 'cash_payments_list')) {
        require_once app_path('includes/fin_voucher_post.php');
        $counts = fin_voucher_count_unposted($pdo, 'payment');
        $total += (int) ($counts['payments'] ?? 0);
    }

    if (user_can('journal_entries') && dashboard_table_exists($pdo, 'acc_journal_entry')) {
        try {
            $total += (int) $pdo->query(
                "SELECT COUNT(*) FROM acc_journal_entry WHERE status = 'draft'"
            )->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (user_can('warehouse_moves') && dashboard_table_exists($pdo, 'inv_wh_move')) {
        try {
            $total += (int) $pdo->query(
                "SELECT COUNT(*) FROM inv_wh_move WHERE status = 'draft'"
            )->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (user_can('inventory_stocktake') && dashboard_table_exists($pdo, 'inv_stocktake_doc')) {
        try {
            $total += (int) $pdo->query(
                "SELECT COUNT(*) FROM inv_stocktake_doc WHERE status = 'draft'"
            )->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (user_can('sales_delivery') && dashboard_table_exists($pdo, 'sal_delivery')) {
        try {
            $total += (int) $pdo->query(
                'SELECT COUNT(*) FROM sal_delivery d
                 WHERE d.is_posted = 0
                   AND EXISTS (SELECT 1 FROM sal_delivery_line dl WHERE dl.delivery_id = d.id)'
            )->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $total;
}

function header_unposted_user_can(string ...$permissions): bool
{
    foreach ($permissions as $perm) {
        if (user_can($perm)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<mixed> $params
 * @return list<array<string,mixed>>
 */
function header_unposted_fetch_rows(
    PDO $pdo,
    string $sql,
    array $params,
    string $kind,
    string $typeLabel,
    string $urlBase
): array {
    try {
        if ($params === []) {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $out[] = [
            'kind' => $kind,
            'type_label' => $typeLabel,
            'doc_no' => (string) ($row['doc_no'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'doc_date' => (string) ($row['doc_date'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'url' => $urlBase . $id,
            'urgency' => 'unposted',
            'urgency_label' => 'بحاجة ترحيل',
        ];
    }

    return $out;
}

/** @return list<array<string,mixed>> */
function header_unposted_fin_vouchers(PDO $pdo, string $voucherType, int $limit): array
{
    require_once app_path('includes/fin_voucher_schema.php');
    if (!fin_voucher_has_table($pdo)) {
        return [];
    }

    $label = $voucherType === 'receipt' ? 'سند قبض' : 'سند صرف';
    $route = $voucherType === 'receipt' ? 'cash_receipt' : 'cash_payment';
    $urlBase = app_url('index.php?r=' . $route . '&id=');

    $where = header_unposted_fin_voucher_where_sql($pdo, $voucherType);
    if ($where === '') {
        return [];
    }

    $sql = "SELECT v.id, v.voucher_no AS doc_no, v.voucher_date AS doc_date, v.amount,
                   COALESCE(c.name_ar, s.name_ar, '') AS party_name
            FROM fin_voucher v
            LEFT JOIN crm_customer c ON v.party_type = 'customer' AND c.id = v.party_id
            LEFT JOIN crm_supplier s ON v.party_type = 'supplier' AND s.id = v.party_id
            WHERE v.voucher_type = ? AND {$where}
            ORDER BY v.voucher_date ASC, v.id ASC
            LIMIT {$limit}";

    return header_unposted_fetch_rows(
        $pdo,
        $sql,
        [$voucherType],
        $voucherType === 'receipt' ? 'cash_receipt' : 'cash_payment',
        $label,
        $urlBase
    );
}

function header_unposted_fin_voucher_where_sql(PDO $pdo, string $voucherType): string
{
    if (fin_voucher_has_column($pdo, 'is_posted')) {
        return 'v.is_posted = 0';
    }

    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');

    if ($voucherType === 'receipt') {
        if (!crm_ledger_has_table($pdo)) {
            return '';
        }

        return "NOT EXISTS (
            SELECT 1 FROM crm_customer_ledger l
            WHERE l.txn_type = 'cash_receipt' AND l.ref_id = v.id
        )";
    }

    if (!crm_ledger_has_table($pdo) && !crm_supplier_ledger_has_table($pdo)) {
        return '';
    }

    $parts = [];
    if (crm_ledger_has_table($pdo)) {
        $parts[] = "(v.party_type = 'customer' AND NOT EXISTS (
            SELECT 1 FROM crm_customer_ledger l
            WHERE l.txn_type = 'cash_payment' AND l.ref_id = v.id
        ))";
    }
    if (crm_supplier_ledger_has_table($pdo)) {
        $parts[] = "(v.party_type = 'supplier' AND NOT EXISTS (
            SELECT 1 FROM crm_supplier_ledger l
            WHERE l.txn_type = 'cash_payment' AND l.ref_id = v.id
        ))";
    }
    if ($parts === []) {
        return '';
    }

    return '(' . implode(' OR ', $parts) . ')';
}
