<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/pur_invoice_schema.php');

function pur_order_has_table(PDO $pdo): bool
{
    static $ok = false;
    static $checked = false;
    if ($checked) {
        return $ok;
    }
    $checked = true;
    try {
        $pdo->query('SELECT id FROM pur_order LIMIT 1');
        $pdo->query('SELECT id FROM pur_order_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function pur_order_ensure_schema(PDO $pdo): bool
{
    if (!pur_order_has_table($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/169_pur_order.sql');
    }
    pur_invoice_ensure_order_id_column($pdo);

    return pur_order_has_table($pdo);
}

function pur_invoice_ensure_order_id_column(PDO $pdo): void
{
    if (!pur_invoice_has_invoice_table($pdo)) {
        return;
    }
    try {
        $pdo->query('SELECT order_id FROM pur_invoice LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE pur_invoice ADD COLUMN order_id INT UNSIGNED NULL AFTER supplier_id');
            $pdo->exec('ALTER TABLE pur_invoice ADD KEY idx_pur_inv_order (order_id)');
        } catch (Throwable $e2) {
            //
        }
    }
}

function pur_invoice_has_order_id(PDO $pdo): bool
{
    if (!pur_invoice_has_invoice_table($pdo)) {
        return false;
    }
    try {
        $pdo->query('SELECT order_id FROM pur_invoice LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<string> */
function pur_order_valid_statuses(): array
{
    return ['draft', 'submitted', 'approved', 'partial', 'closed', 'cancelled'];
}

function pur_order_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'مسودة',
        'submitted' => 'مُرسَل',
        'approved' => 'معتمد',
        'partial' => 'منفَّذ جزئياً',
        'closed' => 'مغلق',
        'cancelled' => 'ملغى',
        default => $status,
    };
}

function pur_order_is_editable_status(string $status): bool
{
    return in_array($status, ['draft', 'submitted'], true);
}

function pur_order_is_approved_status(string $status): bool
{
    return in_array($status, ['approved', 'partial', 'closed'], true);
}

function pur_order_generate_next_no(PDO $pdo, string $orderDate): string
{
    $year = (int) date('Y', strtotime($orderDate));
    $suffix = '-PO-' . $year;

    $st = $pdo->prepare('SELECT order_no FROM pur_order WHERE order_no LIKE ? FOR UPDATE');
    $st->execute(['%' . $suffix]);

    $maxSeq = 0;
    $suffixQuoted = preg_quote($suffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^(\d+)' . $suffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    return str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT) . $suffix;
}

/** @param list<array<string,mixed>> $lines */
function pur_order_replace_lines(PDO $pdo, int $orderId, array $lines, ?int $decimals = null): void
{
    require_once app_path('includes/invoice_amount_decimals.php');
    $dp = invoice_amount_decimals_clamp($decimals ?? company_decimal_places($pdo));

    $pdo->prepare('DELETE FROM pur_order_line WHERE order_id = ?')->execute([$orderId]);

    require_once app_path('includes/inv_item_units.php');
    inv_item_units_ensure_schema($pdo);
    $hasUnitCols = inv_item_units_column_exists($pdo, 'pur_order_line', 'unit_id');

    $st = $pdo->prepare(
        $hasUnitCols
            ? 'INSERT INTO pur_order_line
               (order_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount,
                line_total, tax_rate_percent, tax_amount, line_gross, sort_order,
                unit_id, unit_name, unit_factor, qty_base)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            : 'INSERT INTO pur_order_line
               (order_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount,
                line_total, tax_rate_percent, tax_amount, line_gross, sort_order)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $sort = 0;
    foreach ($lines as $ln) {
        $ln = invoice_normalize_line_array($ln, $dp);
        $itemId = (int) ($ln['item_id'] ?? 0);
        require_once app_path('includes/inv_invoice_line_qty.php');
        $qty = (float) ($ln['qty'] ?? 0);
        if ($itemId < 1 || inv_invoice_line_stock_qty_sum($qty, (float) ($ln['qty_extra'] ?? 0)) <= 0) {
            continue;
        }
        $unitId = isset($ln['unit_id']) ? (int) $ln['unit_id'] : 0;
        $unitName = trim((string) ($ln['unit_name'] ?? ''));
        $unitFactor = (float) ($ln['unit_factor'] ?? 0);
        $resolved = inv_item_unit_resolve($pdo, $itemId, $unitId > 0 ? $unitId : null);
        if ($resolved) {
            $unitId = (int) $resolved['unit_id'];
            $unitName = (string) $resolved['unit_name'];
            $unitFactor = (float) $resolved['unit_factor'];
        }
        if ($unitFactor <= 0) {
            $unitFactor = 1.0;
        }
        $qtyExtra = (float) ($ln['qty_extra'] ?? 0);
        $qtyBase = array_key_exists('qty_base', $ln) && $ln['qty_base'] !== null && $ln['qty_base'] !== ''
            ? (float) $ln['qty_base']
            : (($qty + $qtyExtra) * $unitFactor);
        $params = [
            $orderId,
            $itemId,
            ($ln['line_desc'] ?? '') !== '' ? (string) $ln['line_desc'] : null,
            $qty,
            $qtyExtra,
            (float) ($ln['unit_price'] ?? 0),
            (float) ($ln['discount_pct'] ?? 0),
            (float) ($ln['discount_amount'] ?? 0),
            (float) ($ln['line_total'] ?? 0),
            (float) ($ln['tax_rate_percent'] ?? 0),
            (float) ($ln['tax_amount'] ?? 0),
            (float) ($ln['line_gross'] ?? 0),
            $sort++,
        ];
        if ($hasUnitCols) {
            $params[] = $unitId > 0 ? $unitId : null;
            $params[] = $unitName !== '' ? $unitName : null;
            $params[] = $unitFactor;
            $params[] = $qtyBase;
        }
        $st->execute($params);
    }
}

function pur_order_update_totals(PDO $pdo, int $orderId, ?int $decimals = null): void
{
    require_once app_path('includes/invoice_amount_decimals.php');
    $dp = invoice_amount_decimals_clamp($decimals ?? company_decimal_places($pdo));

    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(line_total),0) AS sub, COALESCE(SUM(tax_amount),0) AS tax, COALESCE(SUM(line_gross),0) AS gross
         FROM pur_order_line WHERE order_id = ?'
    );
    $st->execute([$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $pdo->prepare(
        'UPDATE pur_order SET subtotal = ?, tax_amount = ?, total = ?, amount_decimals = ? WHERE id = ?'
    )->execute([
        company_round_amount((float) ($row['sub'] ?? 0), $pdo, $dp),
        company_round_amount((float) ($row['tax'] ?? 0), $pdo, $dp),
        company_round_amount((float) ($row['gross'] ?? 0), $pdo, $dp),
        $dp,
        $orderId,
    ]);
}

function pur_order_set_discount_input(PDO $pdo, int $orderId, ?string $input): void
{
    try {
        $pdo->prepare('UPDATE pur_order SET invoice_discount_input = ? WHERE id = ?')
            ->execute([$input, $orderId]);
    } catch (Throwable $e) {
        //
    }
}

function pur_order_fetch_status(PDO $pdo, int $orderId): string
{
    $st = $pdo->prepare('SELECT status FROM pur_order WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);

    return (string) ($st->fetchColumn() ?: 'draft');
}

function pur_order_recalc_fulfillment_status(PDO $pdo, int $orderId): void
{
    $st = $pdo->prepare(
        'SELECT
            COALESCE(SUM(qty + qty_extra), 0) AS ordered,
            COALESCE(SUM(qty_invoiced), 0) AS invoiced
         FROM pur_order_line WHERE order_id = ?'
    );
    $st->execute([$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $ordered = (float) ($row['ordered'] ?? 0);
    $invoiced = (float) ($row['invoiced'] ?? 0);

    $current = pur_order_fetch_status($pdo, $orderId);
    if (in_array($current, ['draft', 'submitted', 'cancelled'], true)) {
        return;
    }

    if ($ordered <= 0) {
        return;
    }

    $newStatus = 'approved';
    if ($invoiced >= $ordered - 0.000001) {
        $newStatus = 'closed';
    } elseif ($invoiced > 0) {
        $newStatus = 'partial';
    }

    if ($newStatus !== $current) {
        $pdo->prepare('UPDATE pur_order SET status = ? WHERE id = ?')->execute([$newStatus, $orderId]);
    }
}

function pur_order_linked_invoice_count(PDO $pdo, int $orderId): int
{
    if (!pur_invoice_has_order_id($pdo)) {
        return 0;
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM pur_invoice WHERE order_id = ? AND status <> 'cancelled'"
    );
    $st->execute([$orderId]);

    return (int) $st->fetchColumn();
}
