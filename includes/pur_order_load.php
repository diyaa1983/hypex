<?php
declare(strict_types=1);

require_once app_path('includes/pur_order_schema.php');
require_once app_path('includes/pur_order_browse.php');

/** @return array<string, mixed>|null */
function pur_order_fetch_by_id(PDO $pdo, int $id, string $browseFilter = 'all'): ?array
{
    if ($id < 1) {
        return null;
    }

    $cols = 'o.id, o.order_no, o.order_date, o.expected_date, o.supplier_id, o.warehouse_id,
             o.reference_no, o.payment_type, o.subtotal, o.tax_amount, o.total, o.status, o.notes,
             o.approved_by, o.approved_at, o.created_at, s.name_ar AS supplier_name';
    if (sal_invoice_column_exists($pdo, 'pur_order', 'invoice_discount_input')) {
        $cols .= ', o.invoice_discount_input';
    }
    if (sal_invoice_column_exists($pdo, 'pur_order', 'amount_decimals')) {
        $cols .= ', o.amount_decimals';
    }

    $st = $pdo->prepare(
        "SELECT {$cols} FROM pur_order o INNER JOIN crm_supplier s ON s.id = o.supplier_id WHERE o.id = ? LIMIT 1"
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return pur_order_enrich_row($pdo, $row, $browseFilter);
}

/** @return array<string, mixed>|null */
function pur_order_fetch_by_no(PDO $pdo, string $orderNo, string $browseFilter = 'all'): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $orderNo,
        'SELECT id FROM pur_order WHERE order_no = ? LIMIT 1',
        [trim($orderNo)],
        static fn (string $frag) => pur_order_search_ids_by_no_fragment($pdo, $frag, $browseFilter),
        static fn (int $id) => pur_order_fetch_by_id($pdo, $id, $browseFilter)
    );
}

/** @param array<string, mixed> $row */
function pur_order_enrich_row(PDO $pdo, array $row, string $browseFilter = 'all'): array
{
    $id = (int) $row['id'];
    require_once app_path('includes/invoice_amount_decimals.php');
    $displayDecimals = (int) ($row['amount_decimals'] ?? company_decimal_places($pdo));
    $row['amount_decimals'] = $displayDecimals;
    $browseFilter = pur_order_normalize_browse_filter($browseFilter);

    $row['prev_id'] = pur_order_nav_neighbor_id($pdo, $id, 'prev', $browseFilter);
    $row['next_id'] = pur_order_nav_neighbor_id($pdo, $id, 'next', $browseFilter);
    $row['browse_filter'] = $browseFilter;
    $row['browse_count'] = pur_order_count_in_filter($pdo, $browseFilter);
    $row['status_label'] = pur_order_status_label((string) ($row['status'] ?? 'draft'));
    $row['is_approved'] = pur_order_is_approved_status((string) ($row['status'] ?? 'draft'));
    $row['is_editable'] = pur_order_is_editable_status((string) ($row['status'] ?? 'draft'));
    $row['linked_invoice_count'] = pur_order_linked_invoice_count($pdo, $id);

    // أسماء متوافقة مع واجهة فاتورة الشراء (purchase-order.js)
    $row['invoice_no'] = (string) ($row['order_no'] ?? '');
    $row['invoice_date'] = (string) ($row['order_date'] ?? '');
    $row['is_posted'] = $row['is_approved'];
    $row['supplier_invoice_no'] = (string) ($row['reference_no'] ?? '');

    $lineCols = 'ol.id AS line_id, ol.item_id, ol.line_desc, ol.qty, COALESCE(ol.qty_extra, 0) AS qty_extra,
                 COALESCE(ol.qty_invoiced, 0) AS qty_invoiced, ol.unit_price, ol.discount_pct,
                 COALESCE(ol.discount_amount, 0) AS discount_amount, ol.line_total,
                 ol.tax_rate_percent, ol.tax_amount, ol.line_gross';

    $lineSt = $pdo->prepare(
        "SELECT {$lineCols}, i.name_ar, i.barcode, i.sku
         FROM pur_order_line ol
         INNER JOIN inv_item i ON i.id = ol.item_id
         WHERE ol.order_id = ?
         ORDER BY ol.sort_order ASC, ol.id ASC"
    );
    $lineSt->execute([$id]);
    $lines = $lineSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($lines as &$ln) {
        if (empty($ln['barcode'])) {
            $ln['barcode'] = $ln['sku'] ?? '';
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

    return $row;
}
