<?php
declare(strict_types=1);

/**
 * مبيعات المندوب من Oracle حسب المادة والفئة (قراءة فقط).
 * المصدر: MAS.DAILY (TYPE بيع) + اسم المادة من MASCARD + اسم الفئة من MAS.CODE.
 */

require_once app_path('includes/oracle_rep_net_sales.php');

/**
 * @return array{
 *   ok:bool, message:string,
 *   rows:list<array<string,mixed>>,
 *   totals:array{qty:float,bonus:float,amount:float,reps:int,items:int}
 * }
 */
function oracle_fetch_rep_item_sales(
    string $fromIso,
    string $toIso,
    ?int $store = null,
    ?int $repNo = null
): array {
    $empty = [
        'ok' => false,
        'message' => '',
        'rows' => [],
        'totals' => ['qty' => 0.0, 'bonus' => 0.0, 'amount' => 0.0, 'reps' => 0, 'items' => 0],
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

    $sql = "
SELECT d.MAN_NUM AS REP_NO,
       TRIM(TO_CHAR(d.ITEM)) AS ITEM_CODE,
       TRIM(TO_CHAR(d.CAT)) AS CAT_CODE,
       SUM(NVL(d.QTY, 0)) AS QTY,
       SUM(NVL(d.BONUS, 0)) AS BONUS,
       ROUND(SUM(NVL(d.QTY, 0) * ({$price}) - ({$lineDisc})), 3) AS AMOUNT
FROM {$fromQ} d
WHERE d.COMP_NUM = :comp_num
  AND d.TYPE = :sale_type
  AND d.STORE = :store_num
  AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
  AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
  AND d.MAN_NUM IS NOT NULL
  AND d.MAN_NUM > 0
  AND TRIM(TO_CHAR(d.ITEM)) IS NOT NULL
";
    $binds = [
        'comp_num' => (int) $c['comp_num'],
        'sale_type' => (int) $c['sale_type'],
        'store_num' => $storeNum,
        'd_from' => $fromIso,
        'd_to' => $toIso,
    ];
    if ($repNo !== null && $repNo > 0) {
        $sql .= "  AND d.MAN_NUM = :rep_no\n";
        $binds['rep_no'] = $repNo;
    }
    $sql .= "
GROUP BY d.MAN_NUM, TRIM(TO_CHAR(d.ITEM)), TRIM(TO_CHAR(d.CAT))
ORDER BY d.MAN_NUM, TRIM(TO_CHAR(d.CAT)), TRIM(TO_CHAR(d.ITEM))
";

    $conn = oracle_connect();
    if (empty($conn['ok'])) {
        $empty['message'] = (string) ($conn['message'] ?? 'تعذر الاتصال بـ Oracle.');

        return $empty;
    }

    try {
        $raw = oracle_query_all($conn, $sql, $binds);
    } catch (Throwable $e) {
        $empty['message'] = 'تعذر تنفيذ الاستعلام: ' . $e->getMessage();

        return $empty;
    }

    $itemCodes = [];
    $catCodes = [];
    foreach ($raw as $r) {
        $item = trim((string) oracle_statement_row_val($r, 'ITEM_CODE'));
        $cat = trim((string) oracle_statement_row_val($r, 'CAT_CODE'));
        if ($item !== '') {
            $itemCodes[$item] = true;
        }
        if ($cat !== '') {
            $catCodes[$cat] = true;
        }
    }

    $itemNames = oracle_sales_invoice_item_names($conn, array_keys($itemCodes));
    $catNames = oracle_rep_item_sales_category_names($conn, array_keys($catCodes));
    $hypex = oracle_rep_item_sales_hypex_map(array_keys($itemCodes));

    $rows = [];
    $totQty = 0.0;
    $totBonus = 0.0;
    $totAmt = 0.0;
    $reps = [];
    foreach ($raw as $r) {
        $rep = (int) oracle_statement_row_val($r, 'REP_NO');
        $item = trim((string) oracle_statement_row_val($r, 'ITEM_CODE'));
        $cat = trim((string) oracle_statement_row_val($r, 'CAT_CODE'));
        $qty = (float) oracle_statement_row_val($r, 'QTY');
        $bonus = (float) oracle_statement_row_val($r, 'BONUS');
        $amount = (float) oracle_statement_row_val($r, 'AMOUNT');
        if ($item === '' || ($qty == 0.0 && $bonus == 0.0 && $amount == 0.0)) {
            continue;
        }
        $hx = $hypex[$item] ?? null;
        $catName = $catNames[$cat] ?? '';
        if ($catName === '' && is_array($hx) && trim((string) ($hx['cat_name'] ?? '')) !== '') {
            $catName = (string) $hx['cat_name'];
        }
        if ($catName === '') {
            $catName = $cat !== '' ? $cat : 'بدون فئة';
        }
        $itemName = trim((string) ($itemNames[$item] ?? ''));
        if ($itemName === '' && is_array($hx)) {
            $itemName = trim((string) ($hx['name_ar'] ?? ''));
        }
        $rows[] = [
            'rep_no' => $rep,
            'rep_name' => 'مندوب ' . $rep,
            'cat_code' => $cat,
            'cat_name' => $catName,
            'item_code' => $item,
            'item_name' => $itemName !== '' ? $itemName : $item,
            'qty' => $qty,
            'bonus' => $bonus,
            'amount' => round($amount, 3),
        ];
        $totQty += $qty;
        $totBonus += $bonus;
        $totAmt += $amount;
        $reps[$rep] = true;
    }

    usort($rows, static function (array $a, array $b): int {
        $c = ($a['rep_no'] <=> $b['rep_no']);
        if ($c !== 0) {
            return $c;
        }
        $c = strcmp((string) $a['cat_name'], (string) $b['cat_name']);
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string) $a['item_code'], (string) $b['item_code']);
    });

    return [
        'ok' => true,
        'message' => $rows === [] ? 'لا توجد مبيعات في الفترة المحددة.' : '',
        'rows' => $rows,
        'totals' => [
            'qty' => round($totQty, 3),
            'bonus' => round($totBonus, 3),
            'amount' => round($totAmt, 3),
            'reps' => count($reps),
            'items' => count($rows),
        ],
    ];
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
 * @param list<string> $itemCodes
 * @return array<string,array{name_ar:string,cat_name:string}>
 */
function oracle_rep_item_sales_hypex_map(array $itemCodes): array
{
    $out = [];
    $codes = [];
    foreach ($itemCodes as $code) {
        $code = trim((string) $code);
        if ($code !== '') {
            $codes[$code] = true;
        }
    }
    if ($codes === []) {
        return $out;
    }
    try {
        $pdo = db();
        $rows = $pdo->query(
            'SELECT i.sku, i.barcode, i.name_ar, c.name_ar AS cat_name
             FROM inv_item i
             LEFT JOIN inv_item_category c ON c.id = i.category_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $out;
    }
    foreach ($rows as $r) {
        $name = trim((string) ($r['name_ar'] ?? ''));
        $cat = trim((string) ($r['cat_name'] ?? ''));
        $pack = ['name_ar' => $name, 'cat_name' => $cat];
        foreach (['sku', 'barcode'] as $col) {
            $key = trim((string) ($r[$col] ?? ''));
            if ($key !== '' && isset($codes[$key]) && !isset($out[$key])) {
                $out[$key] = $pack;
            }
        }
    }

    return $out;
}
