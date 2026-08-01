<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_browse.php');
require_once app_path('includes/einvoice_settings.php');

/** @return array<string, mixed>|null */
function sal_invoice_fetch_by_id(PDO $pdo, int $id, string $browseFilter = 'all'): ?array
{
    if ($id < 1) {
        return null;
    }

    $hasPay = sal_invoice_column_exists($pdo, 'sal_invoice', 'payment_type');
    $hasRep = sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id');

    $cols = 'id, invoice_no, invoice_date, customer_id, warehouse_id, subtotal, tax_amount, total, status, notes';
    if (sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        $cols .= ', delivery_id';
    }
    if (sal_invoice_column_exists($pdo, 'sal_invoice', 'amount_decimals')) {
        $cols .= ', amount_decimals';
    }
    if ($hasRep) {
        $cols .= ', sales_rep_id';
    }
    if ($hasPay) {
        $cols .= ', payment_type';
    }
    if (sal_invoice_column_exists($pdo, 'sal_invoice', 'invoice_discount_input')) {
        $cols .= ', invoice_discount_input';
    }

    $st = $pdo->prepare("SELECT {$cols} FROM sal_invoice WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return sal_invoice_enrich_row($pdo, $row, $browseFilter);
}

/** @return array<string, mixed>|null */
function sal_invoice_fetch_by_no(PDO $pdo, string $invoiceNo, string $browseFilter = 'all'): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $invoiceNo,
        'SELECT id FROM sal_invoice WHERE invoice_no = ? LIMIT 1',
        [trim($invoiceNo)],
        static fn (string $frag) => sal_invoice_search_ids_by_no_fragment($pdo, $frag, $browseFilter),
        static fn (int $id) => sal_invoice_fetch_by_id($pdo, $id, $browseFilter)
    );
}

/** @param array<string, mixed> $row */
function sal_invoice_enrich_row(PDO $pdo, array $row, string $browseFilter = 'all'): array
{
    $id = (int) $row['id'];
    require_once app_path('includes/invoice_amount_decimals.php');
    $displayDecimals = sal_invoice_amount_decimals($pdo, $id);
    $row['amount_decimals'] = $displayDecimals;
    $browseFilter = sal_invoice_normalize_browse_filter($browseFilter);

    $prevId = sal_invoice_nav_neighbor_id($pdo, $id, 'prev', $browseFilter);
    $nextId = sal_invoice_nav_neighbor_id($pdo, $id, 'next', $browseFilter);
    $row['prev_id'] = $prevId;
    $row['next_id'] = $nextId;
    $row['browse_filter'] = $browseFilter;
    $row['browse_count'] = sal_invoice_count_in_filter($pdo, $browseFilter);

    $hasLineTax = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent');
    $lineCols = 'il.id AS line_id, il.item_id, il.line_desc, il.qty, COALESCE(il.qty_extra, 0) AS qty_extra, il.unit_price, il.discount_pct, il.line_total';
    if (sal_invoice_column_exists($pdo, 'sal_invoice_line', 'discount_amount')) {
        $lineCols = 'il.id AS line_id, il.item_id, il.line_desc, il.qty, COALESCE(il.qty_extra, 0) AS qty_extra, il.unit_price, il.discount_pct, COALESCE(il.discount_amount, 0) AS discount_amount, il.line_total';
    }
    if ($hasLineTax) {
        $lineCols .= ', il.tax_rate_percent, il.tax_amount, il.line_gross';
    }
    require_once app_path('includes/inv_item_units.php');
    inv_item_units_ensure_schema($pdo);
    if (inv_item_units_column_exists($pdo, 'sal_invoice_line', 'unit_id')) {
        $lineCols .= ', il.unit_id, il.unit_name, COALESCE(il.unit_factor, 1) AS unit_factor, il.qty_base';
    }

    require_once app_path('includes/inv_item_barcode.php');
    require_once app_path('includes/inv_item_display.php');
    $hasBarcodeCol = inv_item_has_barcode_column($pdo);
    $itemExtra = $hasBarcodeCol ? ', i.barcode, i.sku' : ', i.sku';

    $lineSt = $pdo->prepare(
        "SELECT {$lineCols}, i.name_ar{$itemExtra}
         FROM sal_invoice_line il
         INNER JOIN inv_item i ON i.id = il.item_id
         WHERE il.invoice_id = ?
         ORDER BY il.id ASC"
    );
    $lineSt->execute([$id]);
    $lines = $lineSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($lines as &$ln) {
        if ($hasBarcodeCol && empty($ln['barcode'])) {
            $ln['barcode'] = $ln['sku'] ?? '';
        }
        $ln['material_number'] = inv_item_material_number(
            $hasBarcodeCol ? ($ln['barcode'] ?? null) : null,
            $ln['sku'] ?? null
        );
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
        $ln['units'] = inv_item_units_for_item($pdo, (int) ($ln['item_id'] ?? 0));
    }
    unset($ln);

    $row['lines'] = $lines;
    company_round_invoice_header_array($row, $pdo, $displayDecimals);

    require_once app_path('includes/sal_invoice_post.php');
    $row['is_posted'] = sal_invoice_is_posted($pdo, $id);
    $row['is_cancelled'] = sal_invoice_is_cancelled((string) ($row['status'] ?? ''));

    $deliveryId = (int) ($row['delivery_id'] ?? 0);
    $row['delivery_no'] = '';
    if ($deliveryId > 0) {
        require_once app_path('includes/sal_delivery_schema.php');
        if (sal_delivery_has_table($pdo)) {
            $dn = $pdo->prepare('SELECT delivery_no FROM sal_delivery WHERE id = ? LIMIT 1');
            $dn->execute([$deliveryId]);
            $row['delivery_no'] = (string) ($dn->fetchColumn() ?: '');
        }
    }

    require_once app_path('includes/einvoice_schema.php');
    if (einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')) {
        $einv = einvoice_sale_status_row($pdo, $id);
        if ($einv !== null) {
            foreach ([
                'invoice_uuid', 'reference_status', 'return_id', 'einv_hash',
                'einv_status', 'einv_results', 'einv_signed_invoice', 'einv_qr',
                'einv_num', 'einv_inv_uuid', 'einv_sent_at',
            ] as $col) {
                if (array_key_exists($col, $einv)) {
                    $row[$col] = $einv[$col];
                }
            }
        }
        $row['einv_sent'] = einvoice_sale_is_sent($pdo, $id);
    } else {
        $row['einv_sent'] = false;
    }

    require_once app_path('includes/sal_einvoice_tracking.php');
    $row['einv_tracking_required'] = sal_einvoice_doc_date_requires_tracking((string) ($row['invoice_date'] ?? ''));

    if (!empty($row['sales_rep_id']) && empty($row['sales_rep_name'])) {
        try {
            $rn = $pdo->prepare('SELECT name_ar FROM crm_sales_rep WHERE id = ? LIMIT 1');
            $rn->execute([(int) $row['sales_rep_id']]);
            $name = $rn->fetchColumn();
            if (is_string($name) && $name !== '') {
                $row['sales_rep_name'] = $name;
            }
        } catch (Throwable $e) {
        }
    }

    return $row;
}
