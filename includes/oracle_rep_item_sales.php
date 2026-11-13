<?php
declare(strict_types=1);

/**
 * مبيعات المندوب من Oracle حسب المادة والفئة (قراءة فقط).
 * المصدر: MAS.DAILY (TYPE بيع) + اسم المادة من MASCARD.
 * الفئة من بطاقة المادة في هايبكس (ربط inv_item.category_id)، لا من Oracle.
 */

require_once app_path('includes/oracle_rep_net_sales.php');

/**
 * أشهر محددة (YYYY-MM). فارغ = كل الفترة بين التاريخين.
 * إن حُددت سنة أو شهر تُوسَّع الفترة لتغطيها، والشهور غير المحددة تُستبعد.
 *
 * @param list<int|string> $years
 * @param list<int|string> $months
 * @return array{from:string,to:string,year_months:list<string>}
 */
function oracle_rep_item_sales_periods(string $fromIso, string $toIso, array $years, array $months): array
{
    $yearNums = [];
    foreach ($years as $y) {
        $y = (int) $y;
        if ($y >= 2000 && $y <= 2100) {
            $yearNums[$y] = $y;
        }
    }
    $monthNums = [];
    foreach ($months as $m) {
        $m = (int) $m;
        if ($m >= 1 && $m <= 12) {
            $monthNums[$m] = $m;
        }
    }
    $yearNums = array_values($yearNums);
    $monthNums = array_values($monthNums);
    sort($yearNums);
    sort($monthNums);
    if ($yearNums === [] && $monthNums === []) {
        return ['from' => $fromIso, 'to' => $toIso, 'year_months' => []];
    }
    if ($yearNums === []) {
        $y1 = (int) substr($fromIso, 0, 4);
        $y2 = (int) substr($toIso, 0, 4);
        if ($y1 < 2000) {
            $y1 = (int) date('Y');
        }
        if ($y2 < $y1) {
            $y2 = $y1;
        }
        for ($y = $y1; $y <= $y2; $y++) {
            $yearNums[] = $y;
        }
    }
    if ($monthNums === []) {
        $monthNums = range(1, 12);
    }
    $keys = [];
    $min = $fromIso;
    $max = $toIso;
    foreach ($yearNums as $y) {
        foreach ($monthNums as $m) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $keys[] = $ym;
            $start = $ym . '-01';
            $endTs = strtotime($start . ' +1 month -1 day');
            $end = $endTs ? date('Y-m-d', $endTs) : $start;
            if ($start < $min) {
                $min = $start;
            }
            if ($end > $max) {
                $max = $end;
            }
        }
    }

    return ['from' => $min, 'to' => $max, 'year_months' => $keys];
}

/**
 * @param bool $filterOracleRep true = تصفية بـ MAN_NUM في Oracle.
 *   false = تصفية بعملاء هايبكس فقط، وتُنسَب المبيعات لـ $repNo الحالي (يشمل مبيعات العميل القديمة تحت مندوب آخر).
 */
function oracle_fetch_rep_item_sales(
    string $fromIso,
    string $toIso,
    ?int $store = null,
    ?int $repNo = null,
    array $custAccs = [],
    array $catNames = [],
    array $yearMonths = [],
    bool $filterOracleRep = true
): array {
    $empty = [
        'ok' => false,
        'message' => '',
        'rows' => [],
        'return_rows' => [],
        'totals' => ['qty' => 0.0, 'bonus' => 0.0, 'amount' => 0.0, 'reps' => 0, 'items' => 0],
        'return_totals' => ['qty' => 0.0, 'bonus' => 0.0, 'amount' => 0.0, 'items' => 0],
        'net_totals' => ['qty' => 0.0, 'bonus' => 0.0, 'amount' => 0.0, 'reps' => 0, 'items' => 0],
    ];

    if (!oracle_is_enabled()) {
        $empty['message'] = 'تكامل Oracle غير مفعّل. راجع إعدادات الاتصال.';

        return $empty;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIso) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIso)) {
        $empty['message'] = 'تواريخ غير صالحة.';

        return $empty;
    }
    if ($fromIso > $toIso) {
        $empty['message'] = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';

        return $empty;
    }

    $c = oracle_rep_net_sales_cfg();
    $storeNum = $store !== null && $store > 0 ? $store : (int) $c['default_store'];
    $fromQ = oracle_stmt_q($c['owner']) . '.' . oracle_stmt_q($c['table']);
    $price = 'CASE WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX ELSE NVL(d.SELL, 0) END';
    $lineDisc = "CASE
        WHEN NVL(d.DISC, 0) = 0 THEN 0
        WHEN NVL(d.DISC, 0) <= 1 THEN NVL(d.QTY, 0) * ({$price}) * NVL(d.DISC, 0)
        WHEN NVL(d.DISC, 0) <= 100 THEN NVL(d.QTY, 0) * ({$price}) * NVL(d.DISC, 0) / 100
        ELSE NVL(d.DISC, 0)
      END";

    $byCurrentLink = !$filterOracleRep && $custAccs !== [];
    $saleType = (int) $c['sale_type'];
    // INV00025 مرتجع مبيعات في هذا التثبيت = TYPE=2 (من Toad: CUST_ACC+MAN_NUM+SELL على DAILY)
    $defaultReturnTypes = [2, 8, 10, 11, 12, 13, 14, 15];
    $returnTypeMap = [];
    foreach ($c['return_types'] ?? $defaultReturnTypes as $rt) {
        $rt = (int) $rt;
        if ($rt > 0 && $rt !== $saleType) {
            $returnTypeMap[$rt] = $rt;
        }
    }
    // ضمان TYPE=2 دائماً (مرتجع المبيعات الفعلي عندكم)
    $returnTypeMap[2] = 2;
    if ($returnTypeMap === []) {
        foreach ($defaultReturnTypes as $rt) {
            $returnTypeMap[$rt] = $rt;
        }
    }

    $custKeys = [];
    foreach ($custAccs as $acc) {
        $key = ltrim(preg_replace('/\D+/', '', (string) $acc) ?? '', '0');
        if ($key !== '') {
            $custKeys[$key] = true;
        }
    }
    $custKeys = array_keys($custKeys);
    $ymKeys = [];
    foreach ($yearMonths as $ym) {
        $ym = trim((string) $ym);
        if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ymKeys[$ym] = $ym;
        }
    }
    $ymKeys = array_values($ymKeys);

    $conn = oracle_connect();
    if (empty($conn['ok'])) {
        $empty['message'] = (string) ($conn['message'] ?? 'تعذر الاتصال بـ Oracle.');

        return $empty;
    }

    // اكتشاف أنواع مرتجع إضافية لها عميل وسعر (مثل TYPE=2)
    try {
        $discSql = "
SELECT DISTINCT d.TYPE AS DOC_TYPE
FROM {$fromQ} d
WHERE d.COMP_NUM = :comp_num
  AND d.TYPE <> :sale_type
  AND d.TYPE BETWEEN 1 AND 99
  AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
  AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
  AND TRIM(TO_CHAR(d.ITEM)) IS NOT NULL
  AND NVL(d.QTY, 0) <> 0
  AND NVL(d.CUST_ACC, 0) <> 0
  AND (NVL(d.SELL, 0) <> 0 OR NVL(d.SELL_BTAX, 0) <> 0)
";
        $discBinds = [
            'comp_num' => (int) $c['comp_num'],
            'sale_type' => $saleType,
            'd_from' => $fromIso,
            'd_to' => $toIso,
        ];
        if ($custKeys !== []) {
            $ph = [];
            foreach ($custKeys as $i => $key) {
                $name = 'dcacc' . $i;
                $ph[] = ':' . $name;
                $discBinds[$name] = $key;
            }
            $discSql .= '  AND LTRIM(TRIM(TO_CHAR(d.CUST_ACC)), \'0\') IN (' . implode(',', $ph) . ")\n";
        }
        if ($ymKeys !== []) {
            $ph = [];
            foreach ($ymKeys as $i => $ym) {
                $name = 'dym' . $i;
                $ph[] = ':' . $name;
                $discBinds[$name] = $ym;
            }
            $discSql .= "  AND TO_CHAR(d.VDATE, 'YYYY-MM') IN (" . implode(',', $ph) . ")\n";
        }
        $discRows = oracle_query_all($conn, $discSql, $discBinds);
        foreach ($discRows as $dr) {
            $t = (int) oracle_statement_row_val($dr, 'DOC_TYPE');
            // استبعاد حركات مخزون بلا مندوب عادةً (TYPE 1 و 3…)
            if ($t > 0 && $t !== $saleType && $t !== 1 && $t !== 3) {
                $returnTypeMap[$t] = $t;
            }
        }
    } catch (Throwable $e) {
        // الإبقاء على القائمة الافتراضية
    }

    $returnTypes = array_values($returnTypeMap);
    $retInList = implode(', ', array_map('intval', $returnTypes));
    $repSelectSale = $byCurrentLink ? '0 AS REP_NO' : 'd.MAN_NUM AS REP_NO';
    $repSelectRet = $byCurrentLink ? '0 AS REP_NO' : 'NVL(d.MAN_NUM, 0) AS REP_NO';

    $custSql = '';
    $ymSql = '';
    $bindsCommon = [
        'comp_num' => (int) $c['comp_num'],
        'd_from' => $fromIso,
        'd_to' => $toIso,
    ];
    if ($custKeys !== []) {
        $ph = [];
        foreach ($custKeys as $i => $key) {
            $name = 'cacc' . $i;
            $ph[] = ':' . $name;
            $bindsCommon[$name] = $key;
        }
        $custSql = '  AND LTRIM(TRIM(TO_CHAR(d.CUST_ACC)), \'0\') IN (' . implode(',', $ph) . ")\n";
    }
    if ($ymKeys !== []) {
        $ph = [];
        foreach ($ymKeys as $i => $ym) {
            $name = 'ym' . $i;
            $ph[] = ':' . $name;
            $bindsCommon[$name] = $ym;
        }
        $ymSql = "  AND TO_CHAR(d.VDATE, 'YYYY-MM') IN (" . implode(',', $ph) . ")\n";
    }

    $selectCols = "
       d.TYPE AS DOC_TYPE,
       TRIM(TO_CHAR(d.CUST_ACC)) AS CUST_ACC,
       TRIM(TO_CHAR(d.ITEM)) AS ITEM_CODE,
       TRIM(TO_CHAR(d.CAT)) AS CAT_CODE,
       NVL(d.VYEAR, 0) AS VYEAR,
       NVL(d.V_NUM, 0) AS V_NUM,
       TO_CHAR(TRUNC(d.VDATE), 'YYYY-MM-DD') AS SALE_DATE,
       SUM(NVL(d.QTY, 0)) AS QTY,
       SUM(NVL(d.BONUS, 0)) AS BONUS,
       ROUND(SUM(NVL(d.QTY, 0) * ({$price}) - ({$lineDisc})), 3) AS AMOUNT
";
    $groupSale = $byCurrentLink
        ? 'd.TYPE, TRIM(TO_CHAR(d.CUST_ACC)), TRIM(TO_CHAR(d.ITEM)), TRIM(TO_CHAR(d.CAT)), NVL(d.VYEAR, 0), NVL(d.V_NUM, 0), TRUNC(d.VDATE)'
        : 'd.TYPE, d.MAN_NUM, TRIM(TO_CHAR(d.CUST_ACC)), TRIM(TO_CHAR(d.ITEM)), TRIM(TO_CHAR(d.CAT)), NVL(d.VYEAR, 0), NVL(d.V_NUM, 0), TRUNC(d.VDATE)';
    $groupRet = $byCurrentLink
        ? 'd.TYPE, TRIM(TO_CHAR(d.CUST_ACC)), TRIM(TO_CHAR(d.ITEM)), TRIM(TO_CHAR(d.CAT)), NVL(d.VYEAR, 0), NVL(d.V_NUM, 0), TRUNC(d.VDATE)'
        : 'd.TYPE, NVL(d.MAN_NUM, 0), TRIM(TO_CHAR(d.CUST_ACC)), TRIM(TO_CHAR(d.ITEM)), TRIM(TO_CHAR(d.CAT)), NVL(d.VYEAR, 0), NVL(d.V_NUM, 0), TRUNC(d.VDATE)';

    $saleSql = "
SELECT {$repSelectSale},{$selectCols}
FROM {$fromQ} d
WHERE d.COMP_NUM = :comp_num
  AND d.TYPE = :sale_type
  AND d.STORE = :store_num
  AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
  AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
  AND TRIM(TO_CHAR(d.ITEM)) IS NOT NULL
{$custSql}{$ymSql}";
    $saleBinds = $bindsCommon;
    $saleBinds['sale_type'] = $saleType;
    $saleBinds['store_num'] = $storeNum;
    if (!$byCurrentLink) {
        if ($filterOracleRep && $repNo !== null && $repNo > 0) {
            $saleSql .= "  AND d.MAN_NUM = :rep_no\n";
            $saleBinds['rep_no'] = $repNo;
        } else {
            $saleSql .= "  AND d.MAN_NUM IS NOT NULL AND d.MAN_NUM > 0\n";
        }
    }
    $saleSql .= "GROUP BY {$groupSale}\n";

    // مرتجع مبيعات = TYPE 2 (وغيرها من القائمة) — العميل على DAILY.CUST_ACC مباشرة
    $retSql = "
SELECT {$repSelectRet},{$selectCols}
FROM {$fromQ} d
WHERE d.COMP_NUM = :comp_num
  AND d.TYPE IN ({$retInList})
  AND NVL(d.QTY, 0) <> 0
  AND (NVL(d.SELL, 0) <> 0 OR NVL(d.SELL_BTAX, 0) <> 0)
  AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
  AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
  AND TRIM(TO_CHAR(d.ITEM)) IS NOT NULL
{$custSql}{$ymSql}
GROUP BY {$groupRet}
";
    $retBinds = $bindsCommon;

    $raw = [];
    $debugProbe = [
        'return_types' => $returnTypes,
        'probe_146' => [],
        'ret_query_rows' => 0,
        'sale_query_rows' => 0,
    ];
    try {
        $saleRaw = oracle_query_all($conn, $saleSql, $saleBinds);
        $debugProbe['sale_query_rows'] = count($saleRaw);
        $raw = array_merge($raw, $saleRaw);
    } catch (Throwable $e) {
        $empty['message'] = 'تعذر تنفيذ استعلام المبيعات: ' . $e->getMessage();

        return $empty;
    }
    try {
        $retRaw = oracle_query_all($conn, $retSql, $retBinds);
        $debugProbe['ret_query_rows'] = count($retRaw);
        $raw = array_merge($raw, $retRaw);
    } catch (Throwable $e) {
        $debugProbe['ret_error'] = $e->getMessage();
    }
    // تشخيص مرتجع 146 TYPE=2
    try {
        $p146 = oracle_query_all($conn, "
SELECT d.TYPE AS DOC_TYPE, d.STORE, NVL(d.MAN_NUM,0) AS MAN_NUM,
       TRIM(TO_CHAR(d.CUST_ACC)) AS CUST_ACC,
       TRIM(TO_CHAR(d.ITEM)) AS ITEM_CODE,
       NVL(d.QTY,0) AS QTY, NVL(d.SELL,0) AS SELL,
       TO_CHAR(TRUNC(d.VDATE),'YYYY-MM-DD') AS SALE_DATE
FROM {$fromQ} d
WHERE d.COMP_NUM = :comp_num AND d.V_NUM = 146 AND NVL(d.VYEAR,0) = 2026
  AND d.TYPE = 2
", ['comp_num' => (int) $c['comp_num']]);
        foreach ($p146 as $pr) {
            $debugProbe['probe_146'][] = [
                'type' => (int) oracle_statement_row_val($pr, 'DOC_TYPE'),
                'store' => (int) oracle_statement_row_val($pr, 'STORE'),
                'man_num' => (int) oracle_statement_row_val($pr, 'MAN_NUM'),
                'cust_acc' => trim((string) oracle_statement_row_val($pr, 'CUST_ACC')),
                'item' => trim((string) oracle_statement_row_val($pr, 'ITEM_CODE')),
                'qty' => (float) oracle_statement_row_val($pr, 'QTY'),
                'sell' => (float) oracle_statement_row_val($pr, 'SELL'),
                'date' => trim((string) oracle_statement_row_val($pr, 'SALE_DATE')),
            ];
        }
    } catch (Throwable $e) {
        $debugProbe['probe_146_error'] = $e->getMessage();
    }

    $itemCodes = [];
    $custAccs = [];
    $repNos = [];
    foreach ($raw as $r) {
        $item = trim((string) oracle_statement_row_val($r, 'ITEM_CODE'));
        $cust = trim((string) oracle_statement_row_val($r, 'CUST_ACC'));
        $repNo = (int) oracle_statement_row_val($r, 'REP_NO');
        if ($item !== '') {
            $itemCodes[$item] = true;
        }
        if ($cust !== '') {
            $custAccs[$cust] = true;
        }
        if ($repNo > 0) {
            $repNos[$repNo] = true;
        }
    }

    $itemNames = oracle_sales_invoice_item_names($conn, array_keys($itemCodes));
    $hypex = oracle_rep_item_sales_hypex_map(array_keys($itemCodes));
    $customers = oracle_rep_item_sales_customer_names($conn, array_keys($custAccs));
    $repNames = oracle_rep_item_sales_rep_names($conn, array_keys($repNos));

    $rowsAll = [];
    $wantCats = [];
    foreach ($catNames as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $wantCats[$name] = true;
        }
    }
    foreach ($raw as $r) {
        $rep = (int) oracle_statement_row_val($r, 'REP_NO');
        $custAcc = trim((string) oracle_statement_row_val($r, 'CUST_ACC'));
        $item = trim((string) oracle_statement_row_val($r, 'ITEM_CODE'));
        $qty = (float) oracle_statement_row_val($r, 'QTY');
        $bonus = (float) oracle_statement_row_val($r, 'BONUS');
        $amount = (float) oracle_statement_row_val($r, 'AMOUNT');
        $docType = (int) oracle_statement_row_val($r, 'DOC_TYPE');
        $isReturn = isset($returnTypeMap[$docType])
            || ($docType !== $saleType && $docType >= 8)
            || ($docType === $saleType && ($qty < 0.0 || $amount < 0.0));
        if ($item === '' || ($qty == 0.0 && $bonus == 0.0 && $amount == 0.0)) {
            continue;
        }
        // الكميات/المبالغ السالبة لفاتورة بيع تُعامل كمرتجع بالقيمة المطلقة
        if ($isReturn && $qty < 0.0) {
            $qty = abs($qty);
        }
        if ($isReturn && $bonus < 0.0) {
            $bonus = abs($bonus);
        }
        if ($isReturn && $amount < 0.0) {
            $amount = abs($amount);
        }
        $hx = $hypex[$item] ?? null;
        $catName = is_array($hx) ? trim((string) ($hx['cat_name'] ?? '')) : '';
        if ($catName === '') {
            $catName = 'بدون فئة';
        }
        // المرتجعات تُخصم دائماً من الصافي حتى لو الفئة غير محددة في الفلتر
        if (!$isReturn && $wantCats !== [] && !isset($wantCats[$catName])) {
            continue;
        }
        $itemName = trim((string) ($itemNames[$item] ?? ''));
        if ($itemName === '' && is_array($hx)) {
            $itemName = trim((string) ($hx['name_ar'] ?? ''));
        }
        $repName = trim((string) ($repNames[$rep] ?? ''));
        if ($repName === '') {
            $repName = 'مندوب ' . $rep;
        }
        $saleDate = trim((string) oracle_statement_row_val($r, 'SALE_DATE'));
        if ($saleDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            $saleDate = '';
        }
        $invoiceYear = (int) oracle_statement_row_val($r, 'VYEAR');
        $invoiceNo = (int) oracle_statement_row_val($r, 'V_NUM');
        $rowsAll[] = [
            'doc_kind' => $isReturn ? 'R' : 'S',
            'rep_no' => $rep,
            'rep_name' => $repName,
            'customer_acc' => $custAcc,
            'customer_name' => $customers[$custAcc] ?? ($custAcc !== '' ? $custAcc : '—'),
            'cat_code' => '',
            'cat_name' => $catName,
            'item_code' => $item,
            'item_sku' => is_array($hx) ? trim((string) ($hx['sku'] ?? '')) : '',
            'item_barcode' => is_array($hx) ? trim((string) ($hx['barcode'] ?? '')) : '',
            'item_name' => $itemName !== '' ? $itemName : $item,
            'sale_date' => $saleDate,
            'invoice_year' => $invoiceYear > 0 ? $invoiceYear : 0,
            'invoice_no' => $invoiceNo > 0 ? $invoiceNo : 0,
            'qty' => $qty,
            'bonus' => $bonus,
            'amount' => round($amount, 3),
        ];
    }

    $merged = [];
    foreach ($rowsAll as $row) {
        $key = (string) ($row['doc_kind'] ?? 'S') . "\n" . (string) $row['rep_no'] . "\n" . (string) $row['customer_acc'] . "\n"
            . (string) ($row['invoice_year'] ?? 0) . "\n" . (string) ($row['invoice_no'] ?? 0) . "\n"
            . (string) $row['item_code'] . "\n" . (string) $row['cat_name'] . "\n" . (string) ($row['sale_date'] ?? '');
        if (!isset($merged[$key])) {
            $merged[$key] = $row;
            continue;
        }
        $merged[$key]['qty'] = (float) $merged[$key]['qty'] + (float) $row['qty'];
        $merged[$key]['bonus'] = (float) $merged[$key]['bonus'] + (float) $row['bonus'];
        $merged[$key]['amount'] = round((float) $merged[$key]['amount'] + (float) $row['amount'], 3);
    }
    $rowsAll = array_values($merged);

    usort($rowsAll, static function (array $a, array $b): int {
        $c = strcmp((string) ($a['doc_kind'] ?? 'S'), (string) ($b['doc_kind'] ?? 'S'));
        if ($c !== 0) {
            return $c;
        }
        $c = ($a['rep_no'] <=> $b['rep_no']);
        if ($c !== 0) {
            return $c;
        }
        $c = strcmp((string) $a['customer_name'], (string) $b['customer_name']);
        if ($c !== 0) {
            return $c;
        }
        $c = strcmp((string) $a['customer_acc'], (string) $b['customer_acc']);
        if ($c !== 0) {
            return $c;
        }
        $c = strcmp((string) ($a['sale_date'] ?? ''), (string) ($b['sale_date'] ?? ''));
        if ($c !== 0) {
            return $c;
        }
        $c = ((int) ($a['invoice_no'] ?? 0) <=> (int) ($b['invoice_no'] ?? 0));
        if ($c !== 0) {
            return $c;
        }
        $c = strcmp((string) $a['cat_name'], (string) $b['cat_name']);
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string) $a['item_code'], (string) $b['item_code']);
    });

    $rows = [];
    $returnRows = [];
    $totQty = 0.0;
    $totBonus = 0.0;
    $totAmt = 0.0;
    $retQty = 0.0;
    $retBonus = 0.0;
    $retAmt = 0.0;
    $reps = [];
    foreach ($rowsAll as $row) {
        if (($row['doc_kind'] ?? 'S') === 'R') {
            $returnRows[] = $row;
            $retQty += (float) $row['qty'];
            $retBonus += (float) $row['bonus'];
            $retAmt += (float) $row['amount'];
        } else {
            $rows[] = $row;
            $totQty += (float) $row['qty'];
            $totBonus += (float) $row['bonus'];
            $totAmt += (float) $row['amount'];
            $reps[(int) $row['rep_no']] = true;
        }
    }

    $debugProbe['return_rows'] = count($returnRows);
    $debugProbe['sale_rows'] = count($rows);

    return [
        'ok' => true,
        'message' => $rows === [] && $returnRows === [] ? 'لا توجد مبيعات في الفترة المحددة.' : '',
        'rows' => $rows,
        'return_rows' => $returnRows,
        'debug' => $debugProbe,
        'totals' => [
            'qty' => round($totQty, 3),
            'bonus' => round($totBonus, 3),
            'amount' => round($totAmt, 3),
            'reps' => count($reps),
            'items' => count($rows),
        ],
        'return_totals' => [
            'qty' => round($retQty, 3),
            'bonus' => round($retBonus, 3),
            'amount' => round($retAmt, 3),
            'items' => count($returnRows),
        ],
        'net_totals' => [
            'qty' => round($totQty - $retQty, 3),
            'bonus' => round($totBonus - $retBonus, 3),
            'amount' => round($totAmt - $retAmt, 3),
            'reps' => count($reps),
            'items' => count($rows) + count($returnRows),
        ],
    ];
}

/**
 * اسم المندوب: بطاقة هايبكس (الرمز = MAN_NUM) ثم EMP_INFO.
 *
 * @param list<int> $repNos
 * @return array<int,string>
 */
function oracle_rep_item_sales_rep_names(array $conn, array $repNos): array
{
    $ids = [];
    foreach ($repNos as $n) {
        $n = (int) $n;
        if ($n > 0) {
            $ids[$n] = true;
        }
    }
    if ($ids === []) {
        return [];
    }

    $out = [];
    try {
        $pdo = db();
        $rows = $pdo->query(
            'SELECT code, name_ar FROM crm_sales_rep WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['name_ar'] ?? ''));
            $code = trim((string) ($r['code'] ?? ''));
            if ($name === '' || $code === '') {
                continue;
            }
            $no = 0;
            if (preg_match('/^[1-9]\d*$/', $code)) {
                $no = (int) $code;
            } elseif (preg_match('/(\d+)/', $code, $m)) {
                $n = (int) $m[1];
                if ($n > 0 && $n < 100000) {
                    $no = $n;
                }
            }
            if ($no > 0 && isset($ids[$no]) && !isset($out[$no])) {
                $out[$no] = $name;
            }
        }
    } catch (Throwable $e) {
        // الاسم من Oracle يكفي
    }

    $missing = [];
    foreach (array_keys($ids) as $no) {
        if (!isset($out[$no])) {
            $missing[] = (int) $no;
        }
    }
    if ($missing === [] || empty($conn['ok'])) {
        return $out;
    }

    $c = oracle_rep_net_sales_cfg();
    $empQ = oracle_stmt_q($c['emp_owner']) . '.' . oracle_stmt_q($c['emp_table']);
    foreach (array_chunk($missing, 400) as $chunk) {
        $list = implode(',', $chunk);
        try {
            $raw = oracle_query_all(
                $conn,
                "SELECT EMP_NO, TRIM(EMP_NAME) AS EMP_NAME FROM {$empQ} WHERE EMP_NO IN ({$list})",
                []
            );
            foreach ($raw as $r) {
                $no = (int) oracle_statement_row_val($r, 'EMP_NO');
                $name = trim((string) oracle_statement_row_val($r, 'EMP_NAME'));
                if ($no > 0 && $name !== '' && !isset($out[$no])) {
                    $out[$no] = $name;
                }
            }
        } catch (Throwable $e) {
            // يبقى «مندوب + الرقم»
        }
    }

    return $out;
}

/**
 * اسم العميل: بطاقة هايبكس أولاً، ثم دليل Oracle.
 *
 * @param list<string> $accounts
 * @return array<string,string>
 */
function oracle_rep_item_sales_customer_names(array $conn, array $accounts): array
{
    $want = [];
    foreach ($accounts as $acc) {
        $acc = trim((string) $acc);
        if ($acc !== '') {
            $want[$acc] = true;
        }
    }
    if ($want === []) {
        return [];
    }

    $out = [];
    try {
        $pdo = db();
        $rows = $pdo->query(
            "SELECT name_ar, code, oracle_key FROM crm_customer WHERE is_active = 1"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['name_ar'] ?? ''));
            if ($name === '') {
                continue;
            }
            foreach (['oracle_key', 'code'] as $col) {
                $key = preg_replace('/\D+/', '', (string) ($r[$col] ?? '')) ?? '';
                if ($key !== '' && isset($want[$key]) && !isset($out[$key])) {
                    $out[$key] = $name;
                }
                $raw = trim((string) ($r[$col] ?? ''));
                if ($raw !== '' && isset($want[$raw]) && !isset($out[$raw])) {
                    $out[$raw] = $name;
                }
            }
        }
    } catch (Throwable $e) {
        // الاسم من Oracle يكفي
    }

    $missing = [];
    foreach (array_keys($want) as $acc) {
        if (!isset($out[$acc])) {
            $missing[] = $acc;
        }
    }
    if ($missing === [] || empty($conn['ok'])) {
        return $out;
    }

    $cfg = oracle_config();
    $gl = is_array($cfg['customers']['name_from_gl'] ?? null) ? $cfg['customers']['name_from_gl'] : [];
    $glOwn = strtoupper(trim((string) ($gl['owner'] ?? 'ACCINV'))) ?: 'ACCINV';
    $glTbl = strtoupper(trim((string) ($gl['table'] ?? 'GLACTMF'))) ?: 'GLACTMF';
    $glNum = strtoupper(trim((string) ($gl['acc_num'] ?? 'ACC_NUM'))) ?: 'ACC_NUM';
    $glDesc = strtoupper(trim((string) ($gl['acc_desc'] ?? 'ACC_DESC'))) ?: 'ACC_DESC';
    $in = [];
    foreach ($missing as $acc) {
        $digits = preg_replace('/\D+/', '', (string) $acc) ?? '';
        if ($digits !== '') {
            $in[$digits] = true;
        }
    }
    if ($in === []) {
        return $out;
    }
    $chunks = array_chunk(array_keys($in), 400);
    foreach ($chunks as $chunk) {
        $list = implode(',', $chunk);
        $sql = 'SELECT TRIM(TO_CHAR(' . oracle_stmt_q($glNum) . ')) AS ACC, TRIM('
            . oracle_stmt_q($glDesc) . ') AS N FROM '
            . oracle_stmt_q($glOwn) . '.' . oracle_stmt_q($glTbl)
            . ' WHERE TO_CHAR(' . oracle_stmt_q($glNum) . ') IN (' . $list . ')';
        try {
            $raw = oracle_query_all($conn, $sql, []);
            foreach ($raw as $r) {
                $acc = trim((string) oracle_statement_row_val($r, 'ACC'));
                $name = trim((string) oracle_statement_row_val($r, 'N'));
                if ($acc !== '' && $name !== '' && isset($want[$acc]) && !isset($out[$acc])) {
                    $out[$acc] = $name;
                }
            }
        } catch (Throwable $e) {
            // يبقى رقم الحساب
        }
    }

    return $out;
}

/**
 * @param list<string> $codes
 * @return array<string,string>
 */
function oracle_rep_item_sales_category_names(array $conn, array $codes): array
{
    $out = [];
    if ($codes === [] || empty($conn['ok'])) {
        return $out;
    }
    $c = oracle_rep_net_sales_cfg();
    $codeQ = oracle_stmt_q($c['owner']) . '.' . oracle_stmt_q('CODE');
    $raw = [];
    try {
        $raw = oracle_query_all(
            $conn,
            "SELECT TRIM(TO_CHAR(CODE)) AS CODE,
                    TRIM(CODE_NAME) AS CODE_NAME,
                    TRIM(CODE_DESC) AS CODE_DESC
             FROM {$codeQ}",
            []
        );
    } catch (Throwable $e) {
        try {
            $raw = oracle_query_all(
                $conn,
                "SELECT TRIM(TO_CHAR(CODE)) AS CODE,
                        TRIM(CODE_NAME) AS CODE_NAME
                 FROM {$codeQ}",
                []
            );
        } catch (Throwable $e2) {
            return $out;
        }
    }
    $want = [];
    foreach ($codes as $code) {
        $want[trim((string) $code)] = true;
    }
    foreach ($raw as $r) {
        $code = trim((string) oracle_statement_row_val($r, 'CODE'));
        if ($code === '' || !isset($want[$code])) {
            continue;
        }
        $name = trim((string) oracle_statement_row_val($r, 'CODE_NAME'));
        if ($name === '') {
            $name = trim((string) oracle_statement_row_val($r, 'CODE_DESC'));
        }
        if ($name !== '') {
            $out[$code] = $name;
        }
    }

    return $out;
}

/**
 * تطبيع رمز مادة Oracle للمقارنة (أرقام مع تجاهل الأصفار البادئة).
 */
function oracle_rep_item_sales_item_norm(string $code): string
{
    $code = trim($code);
    if ($code === '') {
        return '';
    }
    if (function_exists('oracle_order_oracle_num_norm')) {
        return oracle_order_oracle_num_norm($code);
    }
    $digits = preg_replace('/\D+/', '', $code) ?? '';
    $stripped = ltrim($digits, '0');

    return $stripped !== '' ? $stripped : ($digits !== '' ? '0' : '');
}

/**
 * ربط ITEM من Oracle ببطاقة المادة في هايبكس (فئة + اسم).
 * يطابق oracle_key ثم sku ثم barcode، مع تطبيع الأرقام/الأصفار،
 * ويُسجَّل تحت رمز Oracle الأصلي حتى يعمل $hypex[$item].
 *
 * @param list<string> $itemCodes
 * @return array<string,array{name_ar:string,cat_name:string,sku:string,barcode:string}>
 */
function oracle_rep_item_sales_hypex_map(array $itemCodes): array
{
    $out = [];
    $codes = [];
    /** @var array<string,list<string>> */
    $codesNorm = [];
    foreach ($itemCodes as $code) {
        $code = trim((string) $code);
        if ($code === '') {
            continue;
        }
        $codes[$code] = true;
        $norm = oracle_rep_item_sales_item_norm($code);
        if ($norm !== '') {
            $codesNorm[$norm][] = $code;
        }
    }
    if ($codes === []) {
        return $out;
    }

    $rows = [];
    try {
        $pdo = db();
        $rows = $pdo->query(
            'SELECT i.sku, i.barcode, i.oracle_key, i.name_ar, c.name_ar AS cat_name
             FROM inv_item i
             LEFT JOIN inv_item_category c ON c.id = i.category_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $pdo = db();
            $rows = $pdo->query(
                'SELECT i.sku, i.barcode, i.name_ar, c.name_ar AS cat_name
                 FROM inv_item i
                 LEFT JOIN inv_item_category c ON c.id = i.category_id'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return $out;
        }
    }

    $assign = static function (string $oracleCode, array $pack) use (&$out): void {
        if ($oracleCode === '' || isset($out[$oracleCode])) {
            return;
        }
        $out[$oracleCode] = $pack;
    };

    foreach ($rows as $r) {
        $pack = [
            'name_ar' => trim((string) ($r['name_ar'] ?? '')),
            'cat_name' => trim((string) ($r['cat_name'] ?? '')),
            'sku' => trim((string) ($r['sku'] ?? '')),
            'barcode' => trim((string) ($r['barcode'] ?? '')),
        ];
        foreach (['oracle_key', 'sku', 'barcode'] as $col) {
            if (!array_key_exists($col, $r)) {
                continue;
            }
            $key = trim((string) ($r[$col] ?? ''));
            if ($key === '') {
                continue;
            }
            if (isset($codes[$key])) {
                $assign($key, $pack);
            }
            $norm = oracle_rep_item_sales_item_norm($key);
            if ($norm !== '' && isset($codesNorm[$norm])) {
                foreach ($codesNorm[$norm] as $orig) {
                    $assign($orig, $pack);
                }
            }
        }
    }

    return $out;
}
