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

@set_time_limit(300);
@ini_set('memory_limit', '512M');

try {
    $pdo = db();
    inv_item_units_ensure_schema($pdo);
    crm_customer_ensure_oracle_pending_columns($pdo);

    $uid = (int) (current_user()['id'] ?? 0);
    $scopedRepId = crm_mobile_scoped_sales_rep_id($pdo);

    // —— عملاء (كامل أو تزايدي منذ آخر تحديث) ——
    $sinceRaw = trim((string) ($_GET['since'] ?? ''));
    $sinceDt = null;
    if ($sinceRaw !== '') {
        $ts = strtotime($sinceRaw);
        if ($ts !== false) {
            $sinceDt = date('Y-m-d H:i:s', $ts);
        }
    }
    $hasUpdatedAt = false;
    try {
        $pdo->query('SELECT updated_at FROM crm_customer LIMIT 1');
        $hasUpdatedAt = true;
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'ALTER TABLE crm_customer ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'
            );
            $hasUpdatedAt = true;
        } catch (Throwable $e2) {
            $hasUpdatedAt = false;
        }
    }
    $customersDelta = $sinceDt !== null;
    $customersRemoved = [];

    $custParams = [];
    $custSql = 'SELECT c.id, c.name_ar, c.code, c.phone, c.address_ar, c.latitude, c.longitude,
                       c.oracle_key, c.payment_period, c.oracle_pending
                FROM crm_customer c WHERE c.is_active = 1';
    if ($scopedRepId !== null) {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
        $custSql .= ' AND ' . $linkSql;
        $custParams = array_merge($custParams, $linkParams);
    }
    if ($customersDelta) {
        if ($hasUpdatedAt) {
            $custSql .= ' AND (c.created_at >= ? OR IFNULL(c.updated_at, c.created_at) >= ?)';
            $custParams[] = $sinceDt;
            $custParams[] = $sinceDt;
        } else {
            $custSql .= ' AND c.created_at >= ?';
            $custParams[] = $sinceDt;
        }
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

    if ($customersDelta && $hasUpdatedAt && $sinceDt !== null) {
        try {
            $rmSql = 'SELECT c.id FROM crm_customer c WHERE c.is_active = 0 AND IFNULL(c.updated_at, c.created_at) >= ?';
            $rmParams = [$sinceDt];
            if ($scopedRepId !== null) {
                [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
                $rmSql .= ' AND ' . $linkSql;
                $rmParams = array_merge($rmParams, $linkParams);
            }
            $rmSql .= ' LIMIT 5000';
            $stRm = $pdo->prepare($rmSql);
            $stRm->execute($rmParams);
            foreach ($stRm->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) {
                $rid = (int) ($rr['id'] ?? 0);
                if ($rid > 0) {
                    $customersRemoved[] = $rid;
                }
            }
        } catch (Throwable $e) {
            $customersRemoved = [];
        }
    }

    $customerIds = [];
    try {
        $idSql = 'SELECT c.id FROM crm_customer c WHERE c.is_active = 1';
        $idParams = [];
        if ($scopedRepId !== null) {
            [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
            $idSql .= ' AND ' . $linkSql;
            $idParams = $linkParams;
        }
        $stIds = $pdo->prepare($idSql);
        $stIds->execute($idParams);
        foreach ($stIds->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ir) {
            $cid = (int) ($ir['id'] ?? 0);
            if ($cid > 0) {
                $customerIds[] = $cid;
            }
        }
    } catch (Throwable $e) {
        $customerIds = array_values(array_filter(array_map(
            static fn(array $c): int => (int) ($c['id'] ?? 0),
            $customers
        )));
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

    // —— زيارات: أسباب عدم الطلب + نصف القطر ——
    require_once app_path('includes/sal_rep_visit.php');
    require_once app_path('includes/sal_rep_route.php');
    sal_rep_visit_ensure_schema($pdo);

    // ضمان وجود أسباب افتراضية إن كان الجدول فارغاً
    try {
        $cntReasons = (int) $pdo->query('SELECT COUNT(*) FROM sal_no_order_reason WHERE is_active = 1')->fetchColumn();
        if ($cntReasons < 1) {
            $defaults = [
                'لا يحتاج طلبية حالياً',
                'العميل مغلق',
                'المسؤول عن الطلب غير موجود',
                'لدى العميل مخزون كافٍ',
                'أخرى',
            ];
            $ins = $pdo->prepare(
                'INSERT INTO sal_no_order_reason (name_ar, sort_order, is_active) VALUES (?,?,1)'
            );
            $i = 1;
            foreach ($defaults as $name) {
                $ins->execute([$name, $i++]);
            }
        }
    } catch (Throwable $e) {
        error_log('mobile_sync_pull seed no_order_reasons: ' . $e->getMessage());
    }

    $noOrderReasons = [];
    try {
        foreach (sal_rep_visit_no_order_reasons($pdo) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $noOrderReasons[] = [
                'id' => (int) ($r['id'] ?? 0),
                'name_ar' => (string) ($r['name_ar'] ?? $r['name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $noOrderReasons = [];
    }
    // إن بقي فارغاً — أسباب مؤقتة بتعريف سالب للموبايل Offline فقط
    if ($noOrderReasons === []) {
        $noOrderReasons = [
            ['id' => -1, 'name_ar' => 'لا يحتاج طلبية حالياً'],
            ['id' => -2, 'name_ar' => 'العميل مغلق'],
            ['id' => -3, 'name_ar' => 'أخرى'],
        ];
    }

    $visitRadius = 200;
    try {
        $visitRadius = (int) sal_rep_visit_radius_m($pdo);
    } catch (Throwable $e) {
        $visitRadius = 200;
    }
    if ($visitRadius < 1) {
        $visitRadius = 200;
    }

    // —— جولات / تقرير زيارات / طلبات (نافذة: أول الشهر السابق → اليوم) ——
    require_once app_path('includes/sal_customer_order.php');
    sal_customer_order_ensure_schema($pdo);

    $repId = crm_sales_rep_id_for_user($pdo, $uid);
    if (($repId === null || $repId < 1) && $scopedRepId !== null && $scopedRepId > 0) {
        $repId = $scopedRepId;
    }
    if ($repId !== null && $repId > 0) {
        sal_rep_clear_orphan_daily_routes($pdo, (int) $repId);
    }

    $cacheFrom = date('Y-m-01', strtotime('first day of last month'));
    $cacheTo = date('Y-m-d');
    $prevMonthYm = date('Y-m', strtotime('first day of last month'));
    $curMonthYm = date('Y-m');

    $routeMonths = [];
    $todayVisits = [];
    $visitReportRows = [];
    $ordersOut = [];

    if ($repId !== null && $repId > 0) {
        try {
            foreach ([$prevMonthYm, $curMonthYm] as $ym) {
                $agenda = sal_rep_visit_month_agenda_for_rep($pdo, (int) $repId, $ym);
                $routeMonths[] = [
                    'month' => (string) ($agenda['month'] ?? $ym),
                    'date_from' => (string) ($agenda['date_from'] ?? ($ym . '-01')),
                    'date_to' => (string) ($agenda['date_to'] ?? ''),
                    'days' => is_array($agenda['days'] ?? null) ? $agenda['days'] : [],
                ];
            }
        } catch (Throwable $e) {
            error_log('mobile_sync_pull route_months: ' . $e->getMessage());
            $routeMonths = [];
        }

        try {
            $todayVisits = sal_rep_visit_list_for_rep($pdo, (int) $repId, $cacheTo);
        } catch (Throwable $e) {
            $todayVisits = [];
        }

        try {
            $visitReportRows = sal_rep_visit_report_rows($pdo, [
                'from' => $cacheFrom,
                'to' => $cacheTo,
                'sales_rep_id' => (int) $repId,
                'customer_id' => 0,
                'method' => '',
                'status' => '',
                'limit' => 800,
            ]);
        } catch (Throwable $e) {
            error_log('mobile_sync_pull visit_report: ' . $e->getMessage());
            $visitReportRows = [];
        }

        try {
            $byId = [];
            $dated = sal_customer_order_list_fetch(
                $pdo,
                '',
                (int) $repId,
                null,
                null,
                3000,
                0,
                null,
                $cacheFrom,
                $cacheTo
            );
            foreach ($dated as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oid = (int) ($row['id'] ?? 0);
                if ($oid > 0) {
                    $byId[$oid] = $row;
                }
            }
            // كل غير المرسلة حتى خارج النافذة الزمنية
            $unsent = sal_customer_order_list_fetch(
                $pdo,
                '',
                (int) $repId,
                null,
                null,
                500,
                0,
                0,
                null,
                null
            );
            foreach ($unsent as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oid = (int) ($row['id'] ?? 0);
                if ($oid > 0) {
                    $byId[$oid] = $row;
                }
            }

            $orderIds = array_keys($byId);
            $linesByOrder = [];
            if ($orderIds !== []) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $hasBarcode = false;
                try {
                    require_once app_path('includes/inv_item_barcode.php');
                    $hasBarcode = inv_item_has_barcode_column($pdo);
                } catch (Throwable $e) {
                    $hasBarcode = false;
                }
                $itemSku = $hasBarcode
                    ? 'COALESCE(NULLIF(TRIM(i.barcode), \'\'), i.sku) AS item_sku, i.sku AS item_code'
                    : 'i.sku AS item_sku, i.sku AS item_code';
                $stLn = $pdo->prepare(
                    "SELECT l.*, {$itemSku}, COALESCE(i.name_ar, '') AS item_name
                     FROM sal_customer_order_line l
                     LEFT JOIN inv_item i ON i.id = l.item_id
                     WHERE l.order_id IN ($placeholders)
                     ORDER BY l.order_id, l.line_no, l.id"
                );
                $stLn->execute(array_values($orderIds));
                foreach ($stLn->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ln) {
                    $oid = (int) ($ln['order_id'] ?? 0);
                    if ($oid < 1) {
                        continue;
                    }
                    $sku = trim((string) ($ln['item_sku'] ?? ''));
                    if ($sku === '') {
                        $sku = trim((string) ($ln['item_code'] ?? ''));
                    }
                    $ln['sku'] = $sku;
                    $ln['barcode'] = $sku;
                    $ln['name'] = (string) ($ln['item_name'] ?? $ln['name'] ?? '');
                    $linesByOrder[$oid][] = $ln;
                }
            }

            foreach ($byId as $oid => $row) {
                $row['lines'] = $linesByOrder[$oid] ?? [];
                $row['id'] = (int) $oid;
                $row['customer_id'] = (int) ($row['customer_id'] ?? 0);
                $row['warehouse_id'] = (int) ($row['warehouse_id'] ?? 0);
                $row['is_sent'] = (int) ($row['is_sent'] ?? 1);
                $row['total'] = (float) ($row['total'] ?? 0);
                $row['line_count'] = (int) ($row['line_count'] ?? count($row['lines']));
                $row['total_qty'] = (float) ($row['total_qty'] ?? 0);
                $ordersOut[] = $row;
            }
        } catch (Throwable $e) {
            error_log('mobile_sync_pull orders: ' . $e->getMessage());
            $ordersOut = [];
        }
    }

    // —— كشوف حساب Oracle لعملاء جولة اليوم (حد أقصى لتجنب بطء التحديث) ——
    $oracleStatements = [];
    $stmtFrom = date('Y') . '-01-01';
    $stmtTo = $cacheTo;
    if ($repId !== null && $repId > 0 && is_array($todayVisits)) {
        try {
            require_once app_path('includes/oracle_mobile_statement_cache.php');
            $planIds = [];
            foreach ($todayVisits as $v) {
                if (!is_array($v) || empty($v['in_plan'])) {
                    continue;
                }
                $cid = (int) ($v['customer_id'] ?? 0);
                if ($cid > 0) {
                    $planIds[$cid] = true;
                }
            }
            foreach ($routeMonths as $rm) {
                if (!is_array($rm)) {
                    continue;
                }
                foreach ($rm['days'] ?? [] as $day) {
                    if (!is_array($day)) {
                        continue;
                    }
                    foreach ($day['visits'] ?? [] as $v) {
                        if (!is_array($v)) {
                            continue;
                        }
                        $cid = (int) ($v['customer_id'] ?? 0);
                        if ($cid > 0) {
                            $planIds[$cid] = true;
                        }
                    }
                }
            }
            foreach ($visitReportRows as $vr) {
                if (!is_array($vr)) {
                    continue;
                }
                $cid = (int) ($vr['customer_id'] ?? 0);
                if ($cid > 0) {
                    $planIds[$cid] = true;
                }
            }
            if ($planIds === []) {
                foreach ($todayVisits as $v) {
                    if (!is_array($v)) {
                        continue;
                    }
                    $cid = (int) ($v['customer_id'] ?? 0);
                    if ($cid > 0) {
                        $planIds[$cid] = true;
                    }
                    if (count($planIds) >= 80) {
                        break;
                    }
                }
            }
            $limit = 80;
            $n = 0;
            foreach (array_keys($planIds) as $cid) {
                if ($n >= $limit) {
                    break;
                }
                try {
                    $payload = oracle_mobile_customer_statement_payload(
                        $pdo,
                        (int) $cid,
                        $stmtFrom,
                        $stmtTo
                    );
                    if (!empty($payload['ok'])) {
                        $oracleStatements[] = $payload;
                        $n++;
                    }
                } catch (Throwable $e) {
                    error_log('mobile_sync_pull statement #' . $cid . ': ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('mobile_sync_pull oracle_statements: ' . $e->getMessage());
            $oracleStatements = [];
        }
    }

    $pendingOrders = 0;
    $sentOrders = 0;
    foreach ($ordersOut as $o) {
        if ((int) ($o['is_sent'] ?? 1) === 0) {
            $pendingOrders++;
        } else {
            $sentOrders++;
        }
    }

    echo json_encode([
        'ok' => true,
        'synced_at' => date('c'),
        'user_id' => $uid,
        'sales_rep_id' => $scopedRepId ?? $repId,
        'meta' => [
            'default_warehouse_id' => wh_access_default_issue_warehouse_id($pdo),
            'decimal_places' => company_decimal_places($pdo),
            'default_tax_percent' => $defaultTaxPercent,
            'auto_send_orders' => company_mobile_order_auto_send($pdo) ? 1 : 0,
            'visit_radius_m' => $visitRadius,
            'cache_from' => $cacheFrom,
            'cache_to' => $cacheTo,
            'statement_from' => $stmtFrom,
            'statement_to' => $stmtTo,
        ],
        'counts' => [
            'customers' => count($customers),
            'warehouses' => count($warehouses),
            'items' => count($items),
            'stock_rows' => count($stock),
            'tax_rates' => count($taxRatesOut),
            'no_order_reasons' => count($noOrderReasons),
            'route_days' => array_sum(array_map(
                static fn(array $m): int => count($m['days'] ?? []),
                $routeMonths
            )),
            'visit_report_rows' => count($visitReportRows),
            'orders' => count($ordersOut),
            'orders_pending' => $pendingOrders,
            'orders_sent' => $sentOrders,
            'oracle_statements' => count($oracleStatements),
            'customers_removed' => count($customersRemoved),
        ],
        'customers_delta' => $customersDelta,
        'customers_removed' => $customersRemoved,
        'customers_scope_complete' => true,
        'customer_ids' => $customerIds,
        'customers' => $customers,
        'warehouses' => $warehouses,
        'tax_rates' => $taxRatesOut,
        'items' => $items,
        'stock' => $stock,
        'no_order_reasons' => $noOrderReasons,
        'route_months' => $routeMonths,
        'today_visits' => $todayVisits,
        'visit_report' => [
            'from' => $cacheFrom,
            'to' => $cacheTo,
            'visits' => $visitReportRows,
        ],
        'orders' => $ordersOut,
        'oracle_statements' => $oracleStatements,
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
