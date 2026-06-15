<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_return_invoice_lines.php');

/**
 * فواتير بيع للعميل — للاختيار في شاشة مرتجع المبيعات.
 * بشكل افتراضي: **مرحّلة فقط** (لا يُسمح بالإرجاع إلا بعد ترحيل فاتورة البيع).
 *
 * @return list<array<string, mixed>>
 */
function sal_return_invoices_for_customer(PDO $pdo, int $customerId, bool $onlyPosted = true): array
{
    if ($customerId < 1) {
        return [];
    }

    require_once app_path('includes/crm_customer_ledger.php');
    crm_ledger_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT i.id, i.invoice_no, i.invoice_date, i.total
         FROM sal_invoice i
         WHERE i.customer_id = ?
           AND i.status = 'confirmed'
         ORDER BY i.invoice_date DESC, i.id DESC
         LIMIT 200"
    );
    $st->execute([$customerId]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $posted = sal_invoice_is_posted($pdo, $id);
        $row['is_posted'] = $posted ? 1 : 0;
        if ((!$onlyPosted || $posted) && sal_return_invoice_has_returnable_lines($pdo, $id)) {
            $out[] = $row;
        }
    }

    return $out;
}

/** @return array{line_subtotal: float, tax_amount: float, line_gross: float} */
function sal_return_calc_line_amounts(float $qty, float $unitPrice, float $taxRatePercent): array
{
    $sub = company_round_amount($qty * $unitPrice);
    $tax = company_round_amount($sub * ($taxRatePercent / 100));
    $gross = company_round_amount($sub + $tax);

    return [
        'line_subtotal' => $sub,
        'tax_amount' => $tax,
        'line_gross' => $gross,
    ];
}
