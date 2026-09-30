<?php
declare(strict_types=1);

/**
 * صافي فواتير مبيعات المندوب من Oracle (INVREP050)
 * مصدر البيانات: MAS.DAILY (TYPE=9) حسب MAN_NUM + EMP_INFO
 * الصافي = Σ(QTY×SELL بعد DISC) − VOU_DISC مرة/فاتورة
 * التكلفة = Σ(QTY×JD_COST)
 * قراءة فقط.
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_sales_invoice.php');

/**
 * @return array{
 *   owner:string, table:string, customer_owner:string, customer_table:string,
 *   emp_owner:string, emp_table:string, sale_type:int, comp_num:int, default_store:int,
 *   rep_key:string
 * }
 */
function oracle_rep_net_sales_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['rep_net_sales'] ?? null) ? $cfg['rep_net_sales'] : [];
    $inv = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];

    $owner = strtoupper(trim((string) ($s['owner'] ?? ($inv['owner'] ?? 'MAS'))));
    $table = strtoupper(trim((string) ($s['table'] ?? ($inv['table'] ?? 'DAILY'))));
    $repKey = strtolower(trim((string) ($s['rep_key'] ?? 'man_num')));
    if ($repKey !== 'cus_salesman' && $repKey !== 'man_num') {
        $repKey = 'man_num';
    }

    return [
        'owner' => $owner !== '' ? $owner : 'MAS',
        'table' => $table !== '' ? $table : 'DAILY',
        'customer_owner' => strtoupper(trim((string) ($s['customer_owner'] ?? 'ACCINV'))) ?: 'ACCINV',
        'customer_table' => strtoupper(trim((string) ($s['customer_table'] ?? 'CUSTOMER'))) ?: 'CUSTOMER',
        'emp_owner' => strtoupper(trim((string) ($s['emp_owner'] ?? 'ACCINV'))) ?: 'ACCINV',
        'emp_table' => strtoupper(trim((string) ($s['emp_table'] ?? 'EMP_INFO'))) ?: 'EMP_INFO',
        'sale_type' => (int) ($s['sale_type'] ?? ($inv['sale_type'] ?? 9)),
        'comp_num' => (int) ($s['comp_num'] ?? ($inv['comp_num'] ?? 1)),
        'default_store' => (int) ($s['default_store'] ?? ($inv['default_store'] ?? 4)),
        'rep_key' => $repKey,
    ];
}

/**
 * ملخص صافي مبيعات المندوبين من Oracle.
 *
 * @return array{
 *   ok:bool, message:string, rows:list<array<string,mixed>>,
 *   totals:array{inv_cnt:int, net:float, cost:float, profit:float},
 *   filters:array<string,mixed>
 * }
 */
function oracle_fetch_rep_net_sales(
    string $fromIso,
    string $toIso,
    ?int $store = null,
    ?int $repFrom = null,
    ?int $repTo = null
): array {
    $empty = [
        'ok' => false,
        'message' => '',
        'rows' => [],
        'totals' => ['inv_cnt' => 0, 'net' => 0.0, 'cost' => 0.0, 'profit' => 0.0],
        'filters' => [],
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
    $saleType = (int) $c['sale_type'];
    $compNum = (int) $c['comp_num'];
    $useManNum = ($c['rep_key'] ?? 'man_num') === 'man_num';

    $fromQ = oracle_stmt_q($c['owner']) . '.' . oracle_stmt_q($c['table']);
    $cusQ = oracle_stmt_q($c['customer_owner']) . '.' . oracle_stmt_q($c['customer_table']);
    $empQ = oracle_stmt_q($c['emp_owner']) . '.' . oracle_stmt_q($c['emp_table']);

    // Forms INVREP050: الصافي من SELL_BTAX (قبل الضريبة) وليس SELL
    // إن كان SELL_BTAX فارغاً/صفراً نرجع لـ SELL
    $unitPriceExpr = 'CASE
             WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX
             ELSE NVL(d.SELL, 0)
           END';
    $lineNetExpr = "NVL(d.QTY, 0) * ({$unitPriceExpr})
         - CASE
             WHEN NVL(d.DISC, 0) <= 1
             THEN NVL(d.QTY, 0) * ({$unitPriceExpr}) * NVL(d.DISC, 0)
             ELSE NVL(d.DISC, 0)
           END";
    $lineCostExpr = 'NVL(d.QTY, 0) * NVL(d.JD_COST, 0)';

    $invSelectExtra = "
         SUM(NVL(d.DISC, 0)) AS DISC_SUM,
         SUM(NVL(d.VOU_TAX, 0)) AS TAX_SUM,
         SUM(NVL(d.QTY, 0) * NVL(d.SELL, 0)) AS GROSS_RAW,
         SUM(NVL(d.QTY, 0) * ({$unitPriceExpr})) AS GROSS_BTAX,
         SUM(NVL(d.BONUS, 0) * NVL(d.SELL, 0)) AS BONUS_SELL,
         SUM(NVL(d.BONUS, 0) * NVL(d.JD_COST, 0)) AS BONUS_COST,
         SUM(CASE WHEN NVL(d.QTY, 0) = 0 AND NVL(d.BONUS, 0) > 0
                  THEN NVL(d.BONUS, 0) * NVL(d.SELL, 0) ELSE 0 END) AS PURE_BONUS_SELL,
         SUM(CASE WHEN NVL(d.QTY, 0) = 0 AND NVL(d.BONUS, 0) > 0
                  THEN NVL(d.BONUS, 0) * NVL(d.JD_COST, 0) ELSE 0 END) AS PURE_BONUS_COST";

    if ($useManNum) {
        $sql = "
WITH inv AS (
  SELECT d.VYEAR,
         d.V_NUM,
         d.MAN_NUM AS REP_NO,
         SUM({$lineNetExpr}) AS GROSS,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM({$lineCostExpr}) AS COST_AMT,
         {$invSelectExtra}
  FROM {$fromQ} d
  WHERE d.COMP_NUM = :comp_num
    AND d.TYPE = :sale_type
    AND d.STORE = :store_num
    AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
    AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
    AND d.MAN_NUM IS NOT NULL
";
    } else {
        $sql = "
WITH inv AS (
  SELECT d.VYEAR,
         d.V_NUM,
         c.CUS_SALESMAN AS REP_NO,
         SUM({$lineNetExpr}) AS GROSS,
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
         SUM({$lineCostExpr}) AS COST_AMT,
         {$invSelectExtra}
  FROM {$fromQ} d
  JOIN {$cusQ} c
    ON TO_CHAR(c.CUS_NUM) = TO_CHAR(d.CUST_ACC)
  WHERE d.COMP_NUM = :comp_num
    AND d.TYPE = :sale_type
    AND d.STORE = :store_num
    AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
    AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
";
    }

    $binds = [
        'comp_num' => $compNum,
        'sale_type' => $saleType,
        'store_num' => $storeNum,
        'd_from' => $fromIso,
        'd_to' => $toIso,
    ];

    if ($repFrom !== null && $repFrom > 0) {
        if ($useManNum) {
            $sql .= "    AND d.MAN_NUM >= :rep_from\n";
        } else {
            $sql .= "    AND c.CUS_SALESMAN >= :rep_from\n";
        }
        $binds['rep_from'] = $repFrom;
    }
    if ($repTo !== null && $repTo > 0) {
        if ($useManNum) {
            $sql .= "    AND d.MAN_NUM <= :rep_to\n";
        } else {
            $sql .= "    AND c.CUS_SALESMAN <= :rep_to\n";
        }
        $binds['rep_to'] = $repTo;
    }

    if ($useManNum) {
        $sql .= "  GROUP BY d.VYEAR, d.V_NUM, d.MAN_NUM\n";
    } else {
        $sql .= "  GROUP BY d.VYEAR, d.V_NUM, c.CUS_SALESMAN\n";
    }

    $sql .= ")
SELECT i.REP_NO,
       e.EMP_NAME,
       COUNT(*) AS INV_CNT,
       ROUND(SUM(i.GROSS - i.VOU_DISC), 3) AS NET_AMT,
       ROUND(SUM(i.COST_AMT), 3) AS COST_AMT,
       ROUND(SUM(i.GROSS - i.VOU_DISC) - SUM(i.COST_AMT), 3) AS PROFIT_AMT,
       CASE
         WHEN SUM(i.GROSS - i.VOU_DISC) = 0 THEN 0
         ELSE ROUND(
           100 * (SUM(i.GROSS - i.VOU_DISC) - SUM(i.COST_AMT))
               / SUM(i.GROSS - i.VOU_DISC), 3)
       END AS PROFIT_PCT,
       ROUND(SUM(i.GROSS_RAW), 3) AS GROSS_RAW,
       ROUND(SUM(i.GROSS_BTAX), 3) AS GROSS_BTAX,
       ROUND(SUM(i.VOU_DISC), 3) AS VOU_DISC_SUM,
       ROUND(SUM(i.DISC_SUM), 6) AS DISC_SUM,
       ROUND(SUM(i.TAX_SUM), 3) AS TAX_SUM,
       ROUND(SUM(i.BONUS_SELL), 3) AS BONUS_SELL,
       ROUND(SUM(i.BONUS_COST), 3) AS BONUS_COST,
       ROUND(SUM(i.PURE_BONUS_SELL), 3) AS PURE_BONUS_SELL,
       ROUND(SUM(i.PURE_BONUS_COST), 3) AS PURE_BONUS_COST
FROM inv i
LEFT JOIN {$empQ} e
  ON e.EMP_NO = i.REP_NO
GROUP BY i.REP_NO, e.EMP_NAME
ORDER BY i.REP_NO
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

    // مرتجعات TYPE 10/11 — لتشخيص الفرق مع Forms
    $retNet = 0.0;
    $retCost = 0.0;
    try {
        $retSql = "
SELECT ROUND(SUM(NVL(d.QTY, 0) * NVL(d.SELL, 0)), 3) AS RET_GROSS,
       ROUND(SUM(NVL(d.QTY, 0) * NVL(d.JD_COST, 0)), 3) AS RET_COST
FROM {$fromQ} d
WHERE d.COMP_NUM = :comp_num
  AND d.TYPE IN (10, 11)
  AND d.STORE = :store_num
  AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
  AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
";
        $retBinds = [
            'comp_num' => $compNum,
            'store_num' => $storeNum,
            'd_from' => $fromIso,
            'd_to' => $toIso,
        ];
        if ($useManNum && $repFrom !== null && $repFrom > 0) {
            $retSql .= "  AND d.MAN_NUM >= :rep_from AND d.MAN_NUM <= :rep_to\n";
            $retBinds['rep_from'] = $repFrom;
            $retBinds['rep_to'] = $repTo !== null && $repTo > 0 ? $repTo : $repFrom;
        }
        $retRaw = oracle_query_all($conn, $retSql, $retBinds);
        if ($retRaw !== []) {
            $retNet = (float) oracle_statement_row_val($retRaw[0], 'RET_GROSS');
            $retCost = (float) oracle_statement_row_val($retRaw[0], 'RET_COST');
        }
    } catch (Throwable $e) {
        // تشخيص اختياري
    }

    $rows = [];
    $totInv = 0;
    $totNet = 0.0;
    $totCost = 0.0;
    $totProfit = 0.0;
    $diagGrossRaw = 0.0;
    $diagGrossBtax = 0.0;
    $diagVouDisc = 0.0;
    $diagDisc = 0.0;
    $diagTax = 0.0;
    $diagBonusSell = 0.0;
    $diagBonusCost = 0.0;
    $diagPureBonusSell = 0.0;
    $diagPureBonusCost = 0.0;

    foreach ($raw as $r) {
        $net = (float) oracle_statement_row_val($r, 'NET_AMT');
        $cost = (float) oracle_statement_row_val($r, 'COST_AMT');
        $profit = (float) oracle_statement_row_val($r, 'PROFIT_AMT');
        $pct = (float) oracle_statement_row_val($r, 'PROFIT_PCT');
        $invCnt = (int) oracle_statement_row_val($r, 'INV_CNT');
        $repNo = (int) oracle_statement_row_val($r, 'REP_NO');
        $name = trim((string) oracle_statement_row_val($r, 'EMP_NAME'));
        $diagGrossRaw += (float) oracle_statement_row_val($r, 'GROSS_RAW');
        $diagGrossBtax += (float) oracle_statement_row_val($r, 'GROSS_BTAX');
        $diagVouDisc += (float) oracle_statement_row_val($r, 'VOU_DISC_SUM');
        $diagDisc += (float) oracle_statement_row_val($r, 'DISC_SUM');
        $diagTax += (float) oracle_statement_row_val($r, 'TAX_SUM');
        $diagBonusSell += (float) oracle_statement_row_val($r, 'BONUS_SELL');
        $diagBonusCost += (float) oracle_statement_row_val($r, 'BONUS_COST');
        $diagPureBonusSell += (float) oracle_statement_row_val($r, 'PURE_BONUS_SELL');
        $diagPureBonusCost += (float) oracle_statement_row_val($r, 'PURE_BONUS_COST');

        $rows[] = [
            'rep_no' => $repNo,
            'rep_name' => $name !== '' ? $name : ('مندوب ' . $repNo),
            'inv_cnt' => $invCnt,
            'net' => $net,
            'cost' => $cost,
            'profit' => $profit,
            'profit_pct' => $pct,
        ];
        $totInv += $invCnt;
        $totNet += $net;
        $totCost += $cost;
        $totProfit += $profit;
    }

    $totPct = $totNet != 0.0 ? round(100.0 * $totProfit / $totNet, 3) : 0.0;

    $cand = [
        'base' => ['net' => round($totNet, 3), 'cost' => round($totCost, 3)],
        'sell_not_btax' => [
            'net' => round($diagGrossRaw - $diagVouDisc, 3),
            'cost' => round($totCost, 3),
        ],
        'minus_all_bonus' => [
            'net' => round($totNet - $diagBonusSell, 3),
            'cost' => round($totCost + $diagBonusCost, 3),
        ],
        'with_returns' => [
            'net' => round($totNet + $retNet, 3),
            'cost' => round($totCost + $retCost, 3),
        ],
    ];

    return [
        'ok' => true,
        'message' => $rows === [] ? 'لا توجد حركات مبيعات في الفترة المحددة.' : '',
        'rows' => $rows,
        'totals' => [
            'inv_cnt' => $totInv,
            'net' => round($totNet, 3),
            'cost' => round($totCost, 3),
            'profit' => round($totProfit, 3),
            'profit_pct' => $totPct,
        ],
        'filters' => [
            'from' => $fromIso,
            'to' => $toIso,
            'store' => $storeNum,
            'rep_from' => $repFrom,
            'rep_to' => $repTo,
            'sale_type' => $saleType,
            'comp_num' => $compNum,
            'rep_key' => $useManNum ? 'man_num' : 'cus_salesman',
            'formula' => 'man_num+sell_btax_v6',
            'gross_raw' => round($diagGrossRaw, 3),
            'gross_btax' => round($diagGrossBtax, 3),
            'sell_tax_gap' => round($diagGrossRaw - $diagGrossBtax, 3),
            'vou_disc' => round($diagVouDisc, 3),
            'disc_sum' => round($diagDisc, 6),
            'tax_sum' => round($diagTax, 3),
            'bonus_sell' => round($diagBonusSell, 3),
            'bonus_cost' => round($diagBonusCost, 3),
            'pure_bonus_sell' => round($diagPureBonusSell, 3),
            'pure_bonus_cost' => round($diagPureBonusCost, 3),
            'returns_net' => round($retNet, 3),
            'returns_cost' => round($retCost, 3),
            'candidates' => $cand,
        ],
    ];
}
