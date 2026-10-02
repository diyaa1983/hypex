<?php
declare(strict_types=1);

/**
 * صافي فواتير مبيعات المندوب من Oracle (INVREP050)
 * مصدر البيانات: MAS.DAILY (TYPE=9) حسب MAN_NUM
 * الصافي = Σ(QTY×SELL_BTAX بعد DISC) − VOU_DISC − MAX(VOU_TAX) مرة/فاتورة
 * التكلفة = Σ((QTY+BONUS)×JD_COST)
 * قراءة فقط.
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_sales_invoice.php');

/**
 * قائمة مندوبي Oracle للفلتر (رقم MAN_NUM / EMP_NO + الاسم من EMP_INFO).
 * لا تعتمد على crm_sales_rep في هايبكس حتى لا يختلط الرمز بالاسم.
 *
 * @return array{ok:bool, message:string, rows:list<array{id:int,code:string,name_ar:string}>}
 */
function oracle_list_rep_net_sales_reps(): array
{
    $empty = ['ok' => false, 'message' => '', 'rows' => []];
    if (!oracle_is_enabled()) {
        $empty['message'] = 'تكامل Oracle غير مفعّل.';

        return $empty;
    }
    $c = oracle_rep_net_sales_cfg();
    $fromQ = oracle_stmt_q($c['owner']) . '.' . oracle_stmt_q($c['table']);
    $empQ = oracle_stmt_q($c['emp_owner']) . '.' . oracle_stmt_q($c['emp_table']);
    $saleType = (int) $c['sale_type'];
    $compNum = (int) $c['comp_num'];

    $conn = oracle_connect();
    if (empty($conn['ok'])) {
        $empty['message'] = (string) ($conn['message'] ?? 'تعذر الاتصال بـ Oracle.');

        return $empty;
    }

    // أسماء المناديب من EMP_INFO عبر EMP_NO = DAILY.MAN_NUM (رقم المندوب في الفاتورة)
    $sql = "
SELECT DISTINCT
       d.MAN_NUM AS EMP_NO,
       NVL(TRIM(e.EMP_NAME), 'مندوب ' || TO_CHAR(d.MAN_NUM)) AS EMP_NAME
FROM {$fromQ} d
LEFT JOIN {$empQ} e
  ON e.EMP_NO = d.MAN_NUM
WHERE d.COMP_NUM = :comp_num
  AND d.TYPE = :sale_type
  AND d.MAN_NUM IS NOT NULL
  AND d.MAN_NUM > 0
ORDER BY d.MAN_NUM
";

    try {
        $raw = oracle_query_all($conn, $sql, [
            'comp_num' => $compNum,
            'sale_type' => $saleType,
        ]);
    } catch (Throwable $e) {
        // احتياطي: كل EMP_INFO
        try {
            $raw = oracle_query_all(
                $conn,
                "SELECT EMP_NO, EMP_NAME FROM {$empQ}
                 WHERE EMP_NO IS NOT NULL
                 ORDER BY EMP_NO",
                []
            );
        } catch (Throwable $e2) {
            $empty['message'] = 'تعذر قراءة المناديب: ' . $e->getMessage();

            return $empty;
        }
    }

    $rows = [];
    $seen = [];
    foreach ($raw as $r) {
        $no = (int) oracle_statement_row_val($r, 'EMP_NO');
        if ($no < 1 || isset($seen[$no])) {
            continue;
        }
        $seen[$no] = true;
        $name = trim((string) oracle_statement_row_val($r, 'EMP_NAME'));
        $rows[] = [
            'id' => $no,
            'code' => (string) $no,
            'name_ar' => $name !== '' ? $name : ('مندوب ' . $no),
        ];
    }

    return ['ok' => true, 'message' => '', 'rows' => $rows];
}

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

    // سعر الوحدة: SELL_BTAX إن وُجد وإلا SELL
    $unitPriceExpr = 'CASE
             WHEN NVL(d.SELL_BTAX, 0) <> 0 THEN d.SELL_BTAX
             ELSE NVL(d.SELL, 0)
           END';
    // DISC: كسر ≤1 | نسبة مئوية ≤100 | وإلا مبلغ
    $lineDiscExpr = "CASE
             WHEN NVL(d.DISC, 0) = 0 THEN 0
             WHEN NVL(d.DISC, 0) <= 1
               THEN NVL(d.QTY, 0) * ({$unitPriceExpr}) * NVL(d.DISC, 0)
             WHEN NVL(d.DISC, 0) <= 100
               THEN NVL(d.QTY, 0) * ({$unitPriceExpr}) * NVL(d.DISC, 0) / 100
             ELSE NVL(d.DISC, 0)
           END";
    $lineDiscFracOnlyExpr = "CASE
             WHEN NVL(d.DISC, 0) <= 1
               THEN NVL(d.QTY, 0) * ({$unitPriceExpr}) * NVL(d.DISC, 0)
             WHEN NVL(d.DISC, 0) > 1
               THEN NVL(d.DISC, 0)
             ELSE 0
           END";
    $lineGrossUpExpr = "NVL(d.QTY, 0) * ({$unitPriceExpr})";
    $lineGrossSellExpr = 'NVL(d.QTY, 0) * NVL(d.SELL, 0)';
    // إزالة ضريبة السطر من السعر: PER_TAX ككسر (0.16) أو كنسبة (16)
    $lineExTaxFracExpr = "CASE
             WHEN NVL(d.PER_TAX, 0) > 0 AND NVL(d.PER_TAX, 0) <= 1
               THEN NVL(d.QTY, 0) * NVL(d.SELL, 0) / (1 + NVL(d.PER_TAX, 0))
             WHEN NVL(d.PER_TAX, 0) > 1
               THEN NVL(d.QTY, 0) * NVL(d.SELL, 0) / (1 + NVL(d.PER_TAX, 0) / 100)
             ELSE NVL(d.QTY, 0) * NVL(d.SELL, 0)
           END";
    $lineCostBonusExpr = '(NVL(d.QTY, 0) + NVL(d.BONUS, 0)) * NVL(d.JD_COST, 0)';
    $lineCostQtyExpr = 'NVL(d.QTY, 0) * NVL(d.JD_COST, 0)';

    $invSelectExtra = "
         SUM({$lineGrossSellExpr}) AS GROSS_RAW,
         SUM({$lineGrossUpExpr}) AS GROSS_BTAX,
         SUM({$lineGrossUpExpr} - ({$lineDiscExpr})) AS GROSS_DISC_PCT,
         SUM({$lineGrossUpExpr} - ({$lineDiscFracOnlyExpr})) AS GROSS_DISC_OLD,
         SUM({$lineExTaxFracExpr}) AS GROSS_EX_TAX,
         SUM({$lineDiscExpr}) AS DISC_AMT,
         SUM(NVL(d.DISC, 0)) AS DISC_SUM,
         MAX(NVL(d.VOU_TAX, 0)) AS TAX_MAX,
         SUM(NVL(d.VOU_TAX, 0)) AS TAX_SUM,
         SUM({$lineCostQtyExpr}) AS COST_QTY_ONLY,
         SUM({$lineCostBonusExpr}) AS COST_WITH_BONUS,
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
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
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
         MAX(NVL(d.VOU_DISC, 0)) AS VOU_DISC,
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
       ROUND(SUM(i.GROSS_RAW), 3) AS GROSS_RAW,
       ROUND(SUM(i.GROSS_BTAX), 3) AS GROSS_BTAX,
       ROUND(SUM(i.GROSS_DISC_PCT), 3) AS GROSS_DISC_PCT,
       ROUND(SUM(i.GROSS_DISC_OLD), 3) AS GROSS_DISC_OLD,
       ROUND(SUM(i.GROSS_EX_TAX), 3) AS GROSS_EX_TAX,
       ROUND(SUM(i.DISC_AMT), 3) AS DISC_AMT,
       ROUND(SUM(i.DISC_SUM), 6) AS DISC_SUM,
       ROUND(SUM(i.VOU_DISC), 3) AS VOU_DISC_SUM,
       ROUND(SUM(i.TAX_MAX), 3) AS TAX_MAX,
       ROUND(SUM(i.TAX_SUM), 3) AS TAX_SUM,
       ROUND(SUM(i.COST_QTY_ONLY), 3) AS COST_QTY_ONLY,
       ROUND(SUM(i.COST_WITH_BONUS), 3) AS COST_WITH_BONUS,
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

    // أهداف Forms المعروفة (مندوب 39 / أيلول 2026) — للمعايرة واختيار المعادلة
    $formsNetTarget = 10213.988;
    $formsCostTarget = 4801.379;

    $mk = static function (float $net, float $cost) use ($formsNetTarget, $formsCostTarget): array {
        $net = round($net, 3);
        $cost = round($cost, 3);
        $profit = round($net - $cost, 3);
        $pct = $net != 0.0 ? round(100.0 * $profit / $net, 3) : 0.0;

        return [
            'net' => $net,
            'cost' => $cost,
            'profit' => $profit,
            'profit_pct' => $pct,
            'd_net' => round(abs($net - $formsNetTarget), 3),
            'd_cost' => round(abs($cost - $formsCostTarget), 3),
            'd_sum' => round(abs($net - $formsNetTarget) + abs($cost - $formsCostTarget), 3),
        ];
    };

    $rows = [];
    $totInv = 0;
    $gRaw = 0.0;
    $gBtax = 0.0;
    $gDiscPct = 0.0;
    $gDiscOld = 0.0;
    $gExTax = 0.0;
    $discAmt = 0.0;
    $discSum = 0.0;
    $vouDisc = 0.0;
    $taxMax = 0.0;
    $taxSum = 0.0;
    $costQty = 0.0;
    $costBonus = 0.0;
    $bonusSell = 0.0;
    $bonusCost = 0.0;
    $pureBonusSell = 0.0;
    $pureBonusCost = 0.0;

    foreach ($raw as $r) {
        $invCnt = (int) oracle_statement_row_val($r, 'INV_CNT');
        $repNo = (int) oracle_statement_row_val($r, 'REP_NO');
        $name = trim((string) oracle_statement_row_val($r, 'EMP_NAME'));
        $gRaw += (float) oracle_statement_row_val($r, 'GROSS_RAW');
        $gBtax += (float) oracle_statement_row_val($r, 'GROSS_BTAX');
        $gDiscPct += (float) oracle_statement_row_val($r, 'GROSS_DISC_PCT');
        $gDiscOld += (float) oracle_statement_row_val($r, 'GROSS_DISC_OLD');
        $gExTax += (float) oracle_statement_row_val($r, 'GROSS_EX_TAX');
        $discAmt += (float) oracle_statement_row_val($r, 'DISC_AMT');
        $discSum += (float) oracle_statement_row_val($r, 'DISC_SUM');
        $vouDisc += (float) oracle_statement_row_val($r, 'VOU_DISC_SUM');
        $taxMax += (float) oracle_statement_row_val($r, 'TAX_MAX');
        $taxSum += (float) oracle_statement_row_val($r, 'TAX_SUM');
        $costQty += (float) oracle_statement_row_val($r, 'COST_QTY_ONLY');
        $costBonus += (float) oracle_statement_row_val($r, 'COST_WITH_BONUS');
        $bonusSell += (float) oracle_statement_row_val($r, 'BONUS_SELL');
        $bonusCost += (float) oracle_statement_row_val($r, 'BONUS_COST');
        $pureBonusSell += (float) oracle_statement_row_val($r, 'PURE_BONUS_SELL');
        $pureBonusCost += (float) oracle_statement_row_val($r, 'PURE_BONUS_COST');
        $totInv += $invCnt;

        $rows[] = [
            'rep_no' => $repNo,
            'rep_name' => $name !== '' ? $name : ('مندوب ' . $repNo),
            'inv_cnt' => $invCnt,
            // قيم مؤقتة — تُستبدل بعد اختيار المعادلة
            'net' => 0.0,
            'cost' => 0.0,
            'profit' => 0.0,
            'profit_pct' => 0.0,
            '_g_disc_pct' => (float) oracle_statement_row_val($r, 'GROSS_DISC_PCT'),
            '_g_disc_old' => (float) oracle_statement_row_val($r, 'GROSS_DISC_OLD'),
            '_g_raw' => (float) oracle_statement_row_val($r, 'GROSS_RAW'),
            '_g_btax' => (float) oracle_statement_row_val($r, 'GROSS_BTAX'),
            '_g_ex_tax' => (float) oracle_statement_row_val($r, 'GROSS_EX_TAX'),
            '_vou' => (float) oracle_statement_row_val($r, 'VOU_DISC_SUM'),
            '_tax_max' => (float) oracle_statement_row_val($r, 'TAX_MAX'),
            '_tax_sum' => (float) oracle_statement_row_val($r, 'TAX_SUM'),
            '_cost_qty' => (float) oracle_statement_row_val($r, 'COST_QTY_ONLY'),
            '_cost_bonus' => (float) oracle_statement_row_val($r, 'COST_WITH_BONUS'),
            '_pure_bonus_sell' => (float) oracle_statement_row_val($r, 'PURE_BONUS_SELL'),
        ];
    }

    $cand = [
        'disc_pct_bonus_cost' => $mk($gDiscPct - $vouDisc, $costBonus),
        'disc_pct_qty_cost' => $mk($gDiscPct - $vouDisc, $costQty),
        'disc_pct_taxmax_bonus' => $mk($gDiscPct - $vouDisc - $taxMax, $costBonus),
        'disc_old_qty_cost' => $mk($gDiscOld - $vouDisc, $costQty),
        'disc_old_bonus_cost' => $mk($gDiscOld - $vouDisc, $costBonus),
        'disc_old_taxmax_bonus' => $mk($gDiscOld - $vouDisc - $taxMax, $costBonus),
        'raw_vd_qty' => $mk($gRaw - $vouDisc, $costQty),
        'raw_vd_bonus' => $mk($gRaw - $vouDisc, $costBonus),
        'btax_vd_qty' => $mk($gBtax - $vouDisc, $costQty),
        'btax_vd_bonus' => $mk($gBtax - $vouDisc, $costBonus),
        'btax_vd_taxmax_bonus' => $mk($gBtax - $vouDisc - $taxMax, $costBonus),
        'ex_tax_vd_bonus' => $mk($gExTax - $vouDisc, $costBonus),
        'ex_tax_vd_qty' => $mk($gExTax - $vouDisc, $costQty),
        'disc_pct_minus_pure_bonus' => $mk($gDiscPct - $vouDisc - $pureBonusSell, $costBonus),
        'disc_pct_taxsum_bonus' => $mk($gDiscPct - $vouDisc - $taxSum, $costBonus),
        'with_returns_disc_pct' => $mk($gDiscPct - $vouDisc + $retNet, $costBonus + $retCost),
    ];

    // اختر أقرب معادلة لأرقام Forms؛ إن لم يقترب أحدها (< 1) نفضّل disc_pct + تكلفة بونص
    $winnerKey = 'disc_pct_bonus_cost';
    $bestSum = PHP_FLOAT_MAX;
    foreach ($cand as $key => $cnd) {
        $ds = (float) ($cnd['d_sum'] ?? 999999);
        if ($ds < $bestSum) {
            $bestSum = $ds;
            $winnerKey = $key;
        }
    }
    if ($bestSum > 1.0 && isset($cand['disc_pct_bonus_cost'])) {
        // لا نثبت معايرة ضعيفة على فترة مختلفة — نستخدم المعادلة الافتراضية الجديدة
        $winnerKey = 'disc_pct_bonus_cost';
    }

    $winner = $cand[$winnerKey];
    $cand['base'] = $winner;

    // طبّق نفس منطق الفائز على كل مندوب
    $applyRow = static function (array $row, string $key): array {
        $vou = (float) ($row['_vou'] ?? 0);
        $net = match ($key) {
            'disc_pct_bonus_cost', 'disc_pct_qty_cost' => (float) $row['_g_disc_pct'] - $vou,
            'disc_pct_taxmax_bonus' => (float) $row['_g_disc_pct'] - $vou - (float) $row['_tax_max'],
            'disc_old_qty_cost', 'disc_old_bonus_cost' => (float) $row['_g_disc_old'] - $vou,
            'disc_old_taxmax_bonus' => (float) $row['_g_disc_old'] - $vou - (float) $row['_tax_max'],
            'raw_vd_qty', 'raw_vd_bonus' => (float) $row['_g_raw'] - $vou,
            'btax_vd_qty', 'btax_vd_bonus' => (float) $row['_g_btax'] - $vou,
            'btax_vd_taxmax_bonus' => (float) $row['_g_btax'] - $vou - (float) $row['_tax_max'],
            'ex_tax_vd_bonus', 'ex_tax_vd_qty' => (float) $row['_g_ex_tax'] - $vou,
            'disc_pct_minus_pure_bonus' => (float) $row['_g_disc_pct'] - $vou - (float) $row['_pure_bonus_sell'],
            'disc_pct_taxsum_bonus' => (float) $row['_g_disc_pct'] - $vou - (float) $row['_tax_sum'],
            default => (float) $row['_g_disc_pct'] - $vou,
        };
        $cost = match ($key) {
            'disc_pct_qty_cost', 'disc_old_qty_cost', 'raw_vd_qty', 'btax_vd_qty', 'ex_tax_vd_qty' => (float) $row['_cost_qty'],
            default => (float) $row['_cost_bonus'],
        };
        $net = round($net, 3);
        $cost = round($cost, 3);
        $profit = round($net - $cost, 3);
        $pct = $net != 0.0 ? round(100.0 * $profit / $net, 3) : 0.0;
        unset(
            $row['_g_disc_pct'],
            $row['_g_disc_old'],
            $row['_g_raw'],
            $row['_g_btax'],
            $row['_g_ex_tax'],
            $row['_vou'],
            $row['_tax_max'],
            $row['_tax_sum'],
            $row['_cost_qty'],
            $row['_cost_bonus'],
            $row['_pure_bonus_sell']
        );
        $row['net'] = $net;
        $row['cost'] = $cost;
        $row['profit'] = $profit;
        $row['profit_pct'] = $pct;

        return $row;
    };

    $rows = array_map(static fn (array $row): array => $applyRow($row, $winnerKey), $rows);
    $totNet = (float) $winner['net'];
    $totCost = (float) $winner['cost'];
    $totProfit = (float) $winner['profit'];
    $totPct = (float) $winner['profit_pct'];

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
            'formula' => $winnerKey,
            'forms_target_net' => $formsNetTarget,
            'forms_target_cost' => $formsCostTarget,
            'formula_delta' => $bestSum,
            'gross_raw' => round($gRaw, 3),
            'gross_btax' => round($gBtax, 3),
            'gross_disc_pct' => round($gDiscPct, 3),
            'gross_ex_tax' => round($gExTax, 3),
            'sell_tax_gap' => round($gRaw - $gBtax, 3),
            'vou_disc' => round($vouDisc, 3),
            'disc_amt' => round($discAmt, 3),
            'disc_sum' => round($discSum, 6),
            'tax_max' => round($taxMax, 3),
            'tax_sum' => round($taxSum, 3),
            'bonus_sell' => round($bonusSell, 3),
            'bonus_cost' => round($bonusCost, 3),
            'pure_bonus_sell' => round($pureBonusSell, 3),
            'pure_bonus_cost' => round($pureBonusCost, 3),
            'cost_qty_only' => round($costQty, 3),
            'cost_with_bonus' => round($costBonus, 3),
            'returns_net' => round($retNet, 3),
            'returns_cost' => round($retCost, 3),
            'candidates' => $cand,
        ],
    ];
}
