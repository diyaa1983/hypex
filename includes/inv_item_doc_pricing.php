<?php
declare(strict_types=1);

/**
 * تسعير بنود طلب الشراء / فاتورة البيع من بطاقة المادة فقط.
 * لا يُقبل سعر يدوي من الواجهة — التعديل عبر شاشة تعديل الأسعار.
 */

require_once app_path('includes/inv_item_units.php');
require_once app_path('includes/company_settings.php');

function inv_customer_uses_wholesale(PDO $pdo, int $customerId): bool
{
    if ($customerId < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT use_wholesale_price FROM crm_customer WHERE id = ? LIMIT 1');
        $st->execute([$customerId]);
        $v = $st->fetchColumn();

        return (int) $v === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{
 *   unit_price:float, unit_factor:float, unit_id:?int, unit_name:?string,
 *   base_sale:float, base_wholesale:float, base_price:float, price_mode:string
 * }
 */
function inv_item_resolve_doc_unit_price(
    PDO $pdo,
    int $itemId,
    ?int $unitId = null,
    bool $useWholesale = false
): array {
    $empty = [
        'unit_price' => 0.0,
        'unit_factor' => 1.0,
        'unit_id' => null,
        'unit_name' => null,
        'base_sale' => 0.0,
        'base_wholesale' => 0.0,
        'base_price' => 0.0,
        'price_mode' => $useWholesale ? 'wholesale' : 'sale',
    ];
    if ($itemId < 1) {
        return $empty;
    }

    $baseSale = 0.0;
    $baseWholesale = 0.0;
    try {
        $st = $pdo->prepare(
            'SELECT default_sale, default_wholesale FROM inv_item WHERE id = ? LIMIT 1'
        );
        $st->execute([$itemId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return $empty;
        }
        $baseSale = (float) ($row['default_sale'] ?? 0);
        $baseWholesale = (float) ($row['default_wholesale'] ?? 0);
    } catch (Throwable $e) {
        try {
            $st = $pdo->prepare('SELECT default_sale FROM inv_item WHERE id = ? LIMIT 1');
            $st->execute([$itemId]);
            $baseSale = (float) $st->fetchColumn();
        } catch (Throwable $e2) {
            return $empty;
        }
    }

    inv_item_units_ensure_schema($pdo);
    $resolved = inv_item_unit_resolve($pdo, $itemId, $unitId !== null && $unitId > 0 ? $unitId : null);
    $factor = $resolved ? max(0.000001, (float) $resolved['unit_factor']) : 1.0;
    $uid = $resolved ? (int) $resolved['unit_id'] : ($unitId && $unitId > 0 ? $unitId : null);
    $uname = $resolved ? (string) ($resolved['unit_name'] ?? '') : null;
    if ($uname === '') {
        $uname = null;
    }

    $base = $useWholesale ? $baseWholesale : $baseSale;
    // إن كان سعر الجملة صفراً نرجع لسعر البيع حتى لا تُحفظ بنود بلا سعر
    if ($useWholesale && $base <= 0 && $baseSale > 0) {
        $base = $baseSale;
    }
    $unitPrice = company_round_unit_price($base * $factor, $pdo);

    return [
        'unit_price' => $unitPrice,
        'unit_factor' => $factor,
        'unit_id' => $uid,
        'unit_name' => $uname,
        'base_sale' => $baseSale,
        'base_wholesale' => $baseWholesale,
        'base_price' => $base,
        'price_mode' => $useWholesale ? 'wholesale' : 'sale',
    ];
}

/**
 * يفرض أسعار البطاقة على بنود فاتورة/طلب قبل الحفظ.
 *
 * @param list<array<string,mixed>> $lines
 * @return list<array<string,mixed>>
 */
function inv_doc_lines_force_card_prices(PDO $pdo, array $lines, bool $useWholesale = false): array
{
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $itemId = (int) ($ln['item_id'] ?? 0);
        if ($itemId < 1) {
            $out[] = $ln;
            continue;
        }
        $unitId = (int) ($ln['unit_id'] ?? 0);
        $priced = inv_item_resolve_doc_unit_price(
            $pdo,
            $itemId,
            $unitId > 0 ? $unitId : null,
            $useWholesale
        );
        $ln['unit_price'] = $priced['unit_price'];
        $ln['unit_factor'] = $priced['unit_factor'];
        if ($priced['unit_id'] !== null) {
            $ln['unit_id'] = $priced['unit_id'];
        }
        if ($priced['unit_name'] !== null && $priced['unit_name'] !== '') {
            $ln['unit_name'] = $priced['unit_name'];
        }
        $ln['price_mode'] = $priced['price_mode'];
        $ln['base_price'] = $priced['base_price'];
        $out[] = $ln;
    }

    return $out;
}
