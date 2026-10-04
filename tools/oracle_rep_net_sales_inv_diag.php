<?php
declare(strict_types=1);

/**
 * تشخيص فاتورة بفاتورة + تجربة MASTER_D + فترات بديلة.
 * يكتب النتيجة إلى tmp/oracle_rep_diag.json
 *
 * php tools/oracle_rep_net_sales_inv_diag.php
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');

$from = $argv[1] ?? '2026-09-01';
$to = $argv[2] ?? '2026-09-30';
$store = (int) ($argv[3] ?? 4);
$man = (int) ($argv[4] ?? 39);
$targetNet = 10213.988;
$targetCost = 4801.379;

$outDir = $root . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}
$outFile = $outDir . DIRECTORY_SEPARATOR . 'oracle_rep_diag.json';

$fail = static function (string $msg) use ($outFile): void {
    file_put_contents($outFile, json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
};

if (!oracle_is_enabled()) {
    $fail('Oracle disabled');
}
$conn = oracle_connect();
if (empty($conn['ok'])) {
    $fail((string) ($conn['message'] ?? 'connect fail'));
}

$mk = static function (float $net, float $cost) use ($targetNet, $targetCost): array {
    $net = round($net, 3);
    $cost = round($cost, 3);

    return [
        'net' => $net,
        'cost' => $cost,
        'd_net' => round(abs($net - $targetNet), 3),
        'd_cost' => round(abs($cost - $targetCost), 3),
        'd_sum' => round(abs($net - $targetNet) + abs($cost - $targetCost), 3),
    ];
};

$ranges = [
    ['from' => $from, 'to' => $to],
    ['from' => '2026-09-01', 'to' => '2026-09-20'],
    ['from' => '2026-09-01', 'to' => '2026-09-30'],
];

$report = [
    'ok' => true,
    'targets' => ['net' => $targetNet, 'cost' => $targetCost],
    'ranges' => [],
];

foreach ($ranges as $rng) {
    $sql = "
SELECT d.VYEAR, d.V_NUM, TO_CHAR(MIN(d.VDATE),'YYYY-MM-DD') AS VDATE,
       COUNT(*) AS LINES,
       ROUND(SUM(NVL(d.QTY,0)*NVL(d.SELL,0)), 3) AS GROSS_SELL,
       ROUND(SUM(NVL(d.QTY,0)*NVL(CASE WHEN NVL(d.SELL_BTAX,0)<>0 THEN d.SELL_BTAX ELSE d.SELL END,0)), 3) AS GROSS_UP,
       ROUND(SUM(
         NVL(d.QTY,0)*NVL(CASE WHEN NVL(d.SELL_BTAX,0)<>0 THEN d.SELL_BTAX ELSE d.SELL END,0)
         - CASE
             WHEN NVL(d.DISC,0)=0 THEN 0
             WHEN NVL(d.DISC,0)<=1 THEN NVL(d.QTY,0)*NVL(CASE WHEN NVL(d.SELL_BTAX,0)<>0 THEN d.SELL_BTAX ELSE d.SELL END,0)*NVL(d.DISC,0)
             WHEN NVL(d.DISC,0)<=100 THEN NVL(d.QTY,0)*NVL(CASE WHEN NVL(d.SELL_BTAX,0)<>0 THEN d.SELL_BTAX ELSE d.SELL END,0)*NVL(d.DISC,0)/100
             ELSE NVL(d.DISC,0)
           END
       ), 3) AS GROSS_DISC_PCT,
       ROUND(SUM(
         CASE
           WHEN NVL(d.PER_TAX,0)>0 AND NVL(d.PER_TAX,0)<=1 THEN NVL(d.QTY,0)*NVL(d.SELL,0)/(1+NVL(d.PER_TAX,0))
           WHEN NVL(d.PER_TAX,0)>1 THEN NVL(d.QTY,0)*NVL(d.SELL,0)/(1+NVL(d.PER_TAX,0)/100)
           ELSE NVL(d.QTY,0)*NVL(d.SELL,0)
         END
       ), 3) AS GROSS_EX_TAX,
       ROUND(MAX(NVL(d.VOU_DISC,0)), 3) AS VOU_DISC,
       ROUND(MAX(NVL(d.VOU_TAX,0)), 3) AS TAX_MAX,
       ROUND(SUM(NVL(d.VOU_TAX,0)), 3) AS TAX_SUM,
       ROUND(SUM(NVL(d.QTY,0)*NVL(d.JD_COST,0)), 3) AS COST_QTY,
       ROUND(SUM((NVL(d.QTY,0)+NVL(d.BONUS,0))*NVL(d.JD_COST,0)), 3) AS COST_BONUS,
       ROUND(SUM(NVL(d.BONUS,0)*NVL(d.SELL,0)), 3) AS BONUS_SELL,
       ROUND(SUM(NVL(d.DISC,0)), 6) AS DISC_SUM,
       ROUND(MAX(NVL(d.PER_TAX,0)), 6) AS PER_TAX_MAX,
       ROUND(AVG(NVL(d.PER_TAX,0)), 6) AS PER_TAX_AVG
FROM MAS.DAILY d
WHERE d.COMP_NUM=1 AND d.TYPE=9 AND d.STORE=:store
  AND d.MAN_NUM=:man
  AND d.VDATE >= TO_DATE(:d_from,'YYYY-MM-DD')
  AND d.VDATE < TO_DATE(:d_to,'YYYY-MM-DD')+1
GROUP BY d.VYEAR, d.V_NUM
ORDER BY MIN(d.VDATE), d.V_NUM
";
    try {
        $invs = oracle_query_all($conn, $sql, [
            'store' => $store,
            'man' => $man,
            'd_from' => $rng['from'],
            'd_to' => $rng['to'],
        ]);
    } catch (Throwable $e) {
        $report['ranges'][] = ['from' => $rng['from'], 'to' => $rng['to'], 'error' => $e->getMessage()];
        continue;
    }

    $norm = [];
    $sum = [
        'gross_sell' => 0.0,
        'gross_up' => 0.0,
        'gross_disc_pct' => 0.0,
        'gross_ex_tax' => 0.0,
        'vou_disc' => 0.0,
        'tax_max' => 0.0,
        'tax_sum' => 0.0,
        'cost_qty' => 0.0,
        'cost_bonus' => 0.0,
        'bonus_sell' => 0.0,
    ];
    foreach ($invs as $r) {
        $row = [
            'vyear' => (int) oracle_statement_row_val($r, 'VYEAR'),
            'v_num' => (int) oracle_statement_row_val($r, 'V_NUM'),
            'vdate' => (string) oracle_statement_row_val($r, 'VDATE'),
            'lines' => (int) oracle_statement_row_val($r, 'LINES'),
            'gross_sell' => (float) oracle_statement_row_val($r, 'GROSS_SELL'),
            'gross_up' => (float) oracle_statement_row_val($r, 'GROSS_UP'),
            'gross_disc_pct' => (float) oracle_statement_row_val($r, 'GROSS_DISC_PCT'),
            'gross_ex_tax' => (float) oracle_statement_row_val($r, 'GROSS_EX_TAX'),
            'vou_disc' => (float) oracle_statement_row_val($r, 'VOU_DISC'),
            'tax_max' => (float) oracle_statement_row_val($r, 'TAX_MAX'),
            'tax_sum' => (float) oracle_statement_row_val($r, 'TAX_SUM'),
            'cost_qty' => (float) oracle_statement_row_val($r, 'COST_QTY'),
            'cost_bonus' => (float) oracle_statement_row_val($r, 'COST_BONUS'),
            'bonus_sell' => (float) oracle_statement_row_val($r, 'BONUS_SELL'),
            'disc_sum' => (float) oracle_statement_row_val($r, 'DISC_SUM'),
            'per_tax_max' => (float) oracle_statement_row_val($r, 'PER_TAX_MAX'),
            'per_tax_avg' => (float) oracle_statement_row_val($r, 'PER_TAX_AVG'),
        ];
        $norm[] = $row;
        foreach ($sum as $k => $_) {
            $sum[$k] += $row[$k];
        }
    }

    $cands = [
        'disc_pct_vd_bonus' => $mk($sum['gross_disc_pct'] - $sum['vou_disc'], $sum['cost_bonus']),
        'disc_pct_vd_qty' => $mk($sum['gross_disc_pct'] - $sum['vou_disc'], $sum['cost_qty']),
        'disc_pct_vd_taxmax_bonus' => $mk($sum['gross_disc_pct'] - $sum['vou_disc'] - $sum['tax_max'], $sum['cost_bonus']),
        'sell_vd_qty' => $mk($sum['gross_sell'] - $sum['vou_disc'], $sum['cost_qty']),
        'sell_vd_bonus' => $mk($sum['gross_sell'] - $sum['vou_disc'], $sum['cost_bonus']),
        'sell_vd_taxmax_bonus' => $mk($sum['gross_sell'] - $sum['vou_disc'] - $sum['tax_max'], $sum['cost_bonus']),
        'ex_tax_vd_bonus' => $mk($sum['gross_ex_tax'] - $sum['vou_disc'], $sum['cost_bonus']),
        'ex_tax_vd_qty' => $mk($sum['gross_ex_tax'] - $sum['vou_disc'], $sum['cost_qty']),
        'up_vd_bonus' => $mk($sum['gross_up'] - $sum['vou_disc'], $sum['cost_bonus']),
        'up_vd_taxmax_bonus' => $mk($sum['gross_up'] - $sum['vou_disc'] - $sum['tax_max'], $sum['cost_bonus']),
        'disc_pct_minus_bonus_sell' => $mk($sum['gross_disc_pct'] - $sum['vou_disc'] - $sum['bonus_sell'], $sum['cost_bonus']),
    ];
    uasort($cands, static fn ($a, $b) => $a['d_sum'] <=> $b['d_sum']);

    // MASTER_D إن وُجد MAN_NUM / AMT
    $master = null;
    foreach ([
        "SELECT COUNT(*) CNT, ROUND(SUM(NVL(AMT,0)),3) AMT, ROUND(SUM(NVL(TOT_AMT,0)),3) TOT_AMT,
                ROUND(SUM(NVL(TOTAL,0)),3) TOTAL, ROUND(SUM(NVL(TOT_TAX,0)),3) TOT_TAX
         FROM MAS.MASTER_D
         WHERE COMP_NUM=1 AND TYPE=9 AND STORE=:store AND MAN_NUM=:man
           AND VDATE >= TO_DATE(:d_from,'YYYY-MM-DD')
           AND VDATE < TO_DATE(:d_to,'YYYY-MM-DD')+1",
        "SELECT COUNT(*) CNT, ROUND(SUM(NVL(AMT,0)),3) AMT, ROUND(SUM(NVL(TOT_AMT,0)),3) TOT_AMT,
                ROUND(SUM(NVL(TOTAL,0)),3) TOTAL, ROUND(SUM(NVL(TOT_TAX,0)),3) TOT_TAX
         FROM MAS.MASTER_D m
         WHERE m.COMP_NUM=1 AND m.TYPE=9 AND m.STORE=:store
           AND m.VDATE >= TO_DATE(:d_from,'YYYY-MM-DD')
           AND m.VDATE < TO_DATE(:d_to,'YYYY-MM-DD')+1
           AND EXISTS (
             SELECT 1 FROM MAS.DAILY d
             WHERE d.COMP_NUM=m.COMP_NUM AND d.TYPE=m.TYPE AND d.VYEAR=m.VYEAR AND d.V_NUM=m.V_NUM
               AND d.STORE=m.STORE AND d.MAN_NUM=:man AND ROWNUM<=1
           )",
    ] as $msql) {
        try {
            $mr = oracle_query_all($conn, $msql, [
                'store' => $store,
                'man' => $man,
                'd_from' => $rng['from'],
                'd_to' => $rng['to'],
            ]);
            if ($mr !== []) {
                $master = [
                    'cnt' => (int) oracle_statement_row_val($mr[0], 'CNT'),
                    'amt' => (float) oracle_statement_row_val($mr[0], 'AMT'),
                    'tot_amt' => (float) oracle_statement_row_val($mr[0], 'TOT_AMT'),
                    'total' => (float) oracle_statement_row_val($mr[0], 'TOTAL'),
                    'tot_tax' => (float) oracle_statement_row_val($mr[0], 'TOT_TAX'),
                    'sql_ok' => true,
                ];
                break;
            }
        } catch (Throwable $e) {
            $master = ['error' => $e->getMessage()];
        }
    }

    $bestKey = array_key_first($cands);
    $report['ranges'][] = [
        'from' => $rng['from'],
        'to' => $rng['to'],
        'inv_cnt' => count($norm),
        'sums' => array_map(static fn ($v) => round((float) $v, 3), $sum),
        'best' => $bestKey,
        'best_vals' => $cands[$bestKey] ?? null,
        'candidates' => $cands,
        'master_d' => $master,
        'invoices' => $norm,
    ];
}

file_put_contents($outFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Wrote {$outFile}" . PHP_EOL;
foreach ($report['ranges'] as $rng) {
    if (!empty($rng['error'])) {
        echo "{$rng['from']}..{$rng['to']} ERROR {$rng['error']}" . PHP_EOL;
        continue;
    }
    $b = $rng['best_vals'] ?? [];
    echo sprintf(
        "%s..%s inv=%d best=%s net=%.3f cost=%.3f d_sum=%.3f master_amt=%s\n",
        $rng['from'],
        $rng['to'],
        (int) $rng['inv_cnt'],
        (string) $rng['best'],
        (float) ($b['net'] ?? 0),
        (float) ($b['cost'] ?? 0),
        (float) ($b['d_sum'] ?? 0),
        isset($rng['master_d']['amt']) ? (string) $rng['master_d']['amt'] : json_encode($rng['master_d'], JSON_UNESCAPED_UNICODE)
    );
}
