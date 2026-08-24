<?php
declare(strict_types=1);

/**
 * تحميل كتالوج الموبايل للعمل Offline:
 * عملاء المندوب + مستودعات + ضرائب + مواد (مع وحدات وأسعار) + أرصدة المستودعات.
 *
 * GET — جلسة كوكيز الموبايل.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/warehouse_access.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/inv_warehouse_items.php');
require_once app_path('includes/inv_item_units.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(180);
@ini_set('memory_limit', '512M');

try {
    $pdo = db();
    inv_item_units_ensure_schema($pdo);
    crm_customer_ensure_oracle_pending_columns($pdo);

    $uid = (int) (current_user()['id'] ?? 0);
    $scopedRepId = crm_mobile_scoped_sales_rep_id($pdo);
    if (user_is_system_admin()) {
        $scopedRepId = null;
    }

    // —— عملاء ——
    $custParams = [];
    $custSql = 'SELECT c.id, c.name_ar, c.code, c.phone, c.address_ar, c.latitude, c.longitude,
                       c.oracle_key, c.payment_period, c.oracle_pending
                FROM crm_customer c WHERE c.is_active = 1';
    if ($scopedRepId !== null) {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
        $custSql .= ' AND ' . $linkSql;
        $custParams = array_merge($custParams, $linkParams);
    }
    $custSql .= ' ORDER BY c.name_ar LIMIT 20000';
    $st = $pdo->prepare($custSql);
    $st->execute($custParams);
    $customers = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $rawCode = (string) ($r['code'] ?? '');
        $oracleKey = trim((string) ($r['oracle_key'] ?? ''));
        $oraclePending = (int) ($r['oracle_pending'] ?? 0) === 1;
        $pendingLink = $oracleKey === '' || $oraclePending || str_starts_with($rawCode, 'P-');
        $customers[] = [
            'id' => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['name_ar'] ?? ''),
            'code' => $pendingLink ? '' : $rawCode,
            'phone' => (string) ($r['phone'] ?? ''),
            'address' => (string) ($r['address_ar'] ?? ''),
            'latitude' => isset($r['latitude']) && $r['latitude'] !== null ? (float) $r['latitude'] : null,
            'longitude' => isset($r['longitude']) && $r['longitude'] !== null ? (float) $r['longitude'] : null,
            'payment_period' => (int) ($r['payment_period'] ?? 0),
            'use_wholesale_price' => 0,
        ];
    }

    // عمود اختياري — لا يفشل التحميل إن لم يُرحَّل بعد
    try {
        $pdo->query('SELECT use_wholesale_price FROM crm_customer LIMIT 1');
        $hasWholesale = true;
    } catch (Throwable $e) {
        $hasWholesale = false;
    }
    if ($hasWholesale) {
        $map = [];
        foreach ($pdo->query('SELECT id, use_wholesale_price FROM crm_customer') as $wr) {
            $map[(int) $wr['id']] = (int) ($wr['use_wholesale_price'] ?? 0);
        }
        foreach ($customers as &$c) {
            $c['use_wholesale_price'] = $map[(int) $c['id']] ?? 0;
        }
        unset($c);
    }

    // —— مستودعات ——
    $warehouses = array_map(static function (array $w): array {
        return [
            'id' => (int) ($w['id'] ?? 0),
            'name' => (string) ($w['name_ar'] ?? ''),
        ];
    }, wh_access_list_warehouses($pdo, 'issue'));

    $settings = company_settings($pdo);
    $defaultTaxPercent = (float) ($settings['tax_rate_percent'] ?? 15);
    $taxRates = [];
    try {
        $taxRates = $pdo->query(
            'SELECT id, name_ar, rate_percent
             FROM sys_tax_rate
             WHERE is_active = 1
             ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $taxRates = [];
    }
    if ($taxRates === []) {
        $taxRates = [[
            'id' => 0,
            'name_ar' => 'افتراضي',
            'rate_percent' => $defaultTaxPercent,
        ]];
    }
    $taxRatesOut = array_map(static function (array $rate): array {
        return [
            'id' => (int) ($rate['id'] ?? 0),
            'name' => (string) ($rate['name_ar'] ?? ''),
            'rate_percent' => (float) ($rate['rate_percent'] ?? 0),
        ];
    }, $taxRates);

    // —— مواد (كل النشطة) ——
    $hasBarcode = inv_item_has_barcode_column($pdo);
    $select = inv_item_price_select_sql($hasBarcode);
    $itemSql = 'SELECT ' . $select . ' FROM inv_item i WHERE i.is_active = 1 ORDER BY i.name_ar ASC LIMIT 50000';
    $itemRows = $pdo->query($itemSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($itemRows as &$row) {
        if (!isset($row['barcode']) || $row['barcode'] === '') {
            $row['barcode'] = $row['sku'] ?? '';
        }
    }
    unset($row);
    $itemRows = inv_item_units_attach_to_items($pdo, $itemRows);
    $items = [];
    foreach ($itemRows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $items[] = [
            'id' => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['name_ar'] ?? $r['name'] ?? ''),
            'sku' => (string) ($r['sku'] ?? ''),
            'barcode' => (string) ($r['barcode'] ?? ''),
            'sale_price' => (float) ($r['default_sale'] ?? $r['sale_price'] ?? $r['unit_price'] ?? 0),
            'wholesale_price' => (float) ($r['wholesale_price'] ?? $r['default_wholesale'] ?? 0),
            'units' => is_array($r['units'] ?? null) ? $r['units'] : [],
        ];
    }

    // —— أرصدة لكل مستودع مسموح ——
    $stock = [];
    foreach ($warehouses as $wh) {
        $wid = (int) ($wh['id'] ?? 0);
        if ($wid < 1) {
            continue;
        }
        $stStk = $pdo->prepare(
            'SELECT item_id, SUM(qty_delta) AS qty
             FROM inv_stock_move
             WHERE warehouse_id = ?
             GROUP BY item_id
             HAVING ABS(SUM(qty_delta)) > 0.000001'
        );
        $stStk->execute([$wid]);
        foreach ($stStk->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
            $stock[] = [
                'warehouse_id' => $wid,
                'item_id' => (int) ($s['item_id'] ?? 0),
                'qty' => company_round_amount((float) ($s['qty'] ?? 0)),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'synced_at' => date('c'),
        'user_id' => $uid,
        'sales_rep_id' => $scopedRepId,
        'meta' => [
            'default_warehouse_id' => wh_access_default_issue_warehouse_id($pdo),
            'decimal_places' => company_decimal_places($pdo),
            'default_tax_percent' => $defaultTaxPercent,
        ],
        'counts' => [
            'customers' => count($customers),
            'warehouses' => count($warehouses),
            'items' => count($items),
            'stock_rows' => count($stock),
            'tax_rates' => count($taxRatesOut),
        ],
        'customers' => $customers,
        'warehouses' => $warehouses,
        'tax_rates' => $taxRatesOut,
        'items' => $items,
        'stock' => $stock,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_sync_pull: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'تعذر تحميل بيانات Offline: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
