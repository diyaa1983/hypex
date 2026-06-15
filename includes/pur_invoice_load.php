<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/pur_invoice_browse.php');
require_once app_path('includes/crm_supplier_ledger.php');

/** @return array<string, mixed>|null */
function pur_invoice_fetch_by_id(PDO $pdo, int $id, string $browseFilter = 'all'): ?array
{
    if ($id < 1) {
        return null;
    }

    $hasPay = pur_invoice_has_payment_type($pdo);

    $cols = 'i.id, i.invoice_no, i.invoice_date, i.supplier_id, i.warehouse_id, i.subtotal, i.tax_amount, i.total, i.status, i.notes, s.name_ar AS supplier_name';
    if (sal_invoice_column_exists($pdo, 'pur_invoice', 'amount_decimals')) {
        $cols .= ', i.amount_decimals';
    }
    if (pur_invoice_has_supplier_invoice_no($pdo)) {
        $cols .= ', i.supplier_invoice_no';
    }
    if ($hasPay) {
        $cols .= ', i.payment_type';
    }
    if (sal_invoice_column_exists($pdo, 'pur_invoice', 'invoice_discount_input')) {
        $cols .= ', i.invoice_discount_input';
    }

    $st = $pdo->prepare("SELECT {$cols} FROM pur_invoice i INNER JOIN crm_supplier s ON s.id = i.supplier_id WHERE i.id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return pur_invoice_enrich_row($pdo, $row, $browseFilter);
}

/** @return array<string, mixed>|null */
function pur_invoice_fetch_by_no(PDO $pdo, string $invoiceNo, string $browseFilter = 'all'): ?array
{
    $invoiceNo = trim($invoiceNo);
    if ($invoiceNo === '') {
        return null;
    }

    $st = $pdo->prepare('SELECT id FROM pur_invoice WHERE invoice_no = ? LIMIT 1');
    $st->execute([$invoiceNo]);
    $id = $st->fetchColumn();

    return $id !== false ? pur_invoice_fetch_by_id($pdo, (int) $id, $browseFilter) : null;
}

/** @return array<string, mixed>|null */
function pur_invoice_fetch_by_supplier_invoice_no(PDO $pdo, string $supplierInvoiceNo, string $browseFilter = 'all'): ?array
{
    $supplierInvoiceNo = trim($supplierInvoiceNo);
    if ($supplierInvoiceNo === '') {
        return null;
    }

    require_once app_path('includes/pur_invoice_schema.php');
    if (!pur_invoice_has_supplier_invoice_no($pdo)) {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT id FROM pur_invoice WHERE supplier_invoice_no = ? ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$supplierInvoiceNo]);
    $id = $st->fetchColumn();

    return $id !== false ? pur_invoice_fetch_by_id($pdo, (int) $id, $browseFilter) : null;
}

/** @param array<string, mixed> $row */
function pur_invoice_enrich_row(PDO $pdo, array $row, string $browseFilter = 'all'): array
{
    $id = (int) $row['id'];
    require_once app_path('includes/invoice_amount_decimals.php');
    $displayDecimals = pur_invoice_amount_decimals($pdo, $id);
    $row['amount_decimals'] = $displayDecimals;
    $browseFilter = pur_invoice_normalize_browse_filter($browseFilter);

    $prevId = pur_invoice_nav_neighbor_id($pdo, $id, 'prev', $browseFilter);
    $nextId = pur_invoice_nav_neighbor_id($pdo, $id, 'next', $browseFilter);
    $row['prev_id'] = $prevId;
    $row['next_id'] = $nextId;
    $row['browse_filter'] = $browseFilter;
    $row['browse_count'] = pur_invoice_count_in_filter($pdo, $browseFilter);

    $hasLineTax = pur_invoice_line_has_tax_columns($pdo);
    $lineCols = 'il.id AS line_id, il.item_id, il.line_desc, il.qty, COALESCE(il.qty_extra, 0) AS qty_extra, il.unit_price, il.discount_pct, il.line_total';
    if (sal_invoice_column_exists($pdo, 'pur_invoice_line', 'discount_amount')) {
        $lineCols = 'il.id AS line_id, il.item_id, il.line_desc, il.qty, COALESCE(il.qty_extra, 0) AS qty_extra, il.unit_price, il.discount_pct, COALESCE(il.discount_amount, 0) AS discount_amount, il.line_total';
    }
    if ($hasLineTax) {
        $lineCols .= ', il.tax_rate_percent, il.tax_amount, il.line_gross';
    }

    $lineSt = $pdo->prepare(
        "SELECT {$lineCols}, i.name_ar, i.barcode, i.sku
         FROM pur_invoice_line il
         INNER JOIN inv_item i ON i.id = il.item_id
         WHERE il.invoice_id = ?
         ORDER BY il.id ASC"
    );
    $lineSt->execute([$id]);
    $lines = $lineSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($lines as &$ln) {
        if (empty($ln['barcode'])) {
            $ln['barcode'] = $ln['sku'] ?? '';
        }
        if (!$hasLineTax) {
            $ln['tax_rate_percent'] = 0;
            $ln['tax_amount'] = 0;
            $ln['line_gross'] = (float) ($ln['line_total'] ?? 0);
        }
        $ln['line_subtotal'] = (float) ($ln['line_total'] ?? 0);
        require_once app_path('includes/inv_invoice_discount.php');
        $ln['line_discount_input'] = inv_discount_format_input_for_ui(
            (float) ($ln['discount_pct'] ?? 0),
            (float) ($ln['discount_amount'] ?? 0),
            $displayDecimals
        );
        company_round_invoice_line_array($ln, $pdo, $displayDecimals);
    }
    unset($ln);

    $row['lines'] = $lines;
    company_round_invoice_header_array($row, $pdo, $displayDecimals);

    require_once app_path('includes/pur_invoice_post.php');
    $row['is_posted'] = pur_invoice_is_posted($pdo, $id);

    return $row;
}
