<?php
declare(strict_types=1);

/**
 * إيجاد معادلة تطابق Forms INVREP050 للمندوب 39.
 * الهدف: NET=10213.988 COST=4801.379
 *
 * php tools/oracle_rep_net_sales_gap_probe.php [from] [to] [store] [man]
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

$nl = PHP_EOL;
$out = static function (string $s) use ($nl): void {
    echo $s . $nl;
};

if (!oracle_is_enabled()) {
    $out('Oracle disabled');
    exit(1);
}
$conn = oracle_connect();
if (empty($conn['ok'])) {
    $out('connect FAIL: ' . (string) ($conn['message'] ?? ''));
    exit(1);
}
$out("OK from={$from} to={$to} store={$store} man={$man}");

$sql = "
WITH base AS (
  SELECT d.*,
         NVL(d.QTY,0) AS Q,
         NVL(d.BONUS,0) AS B,
         NVL(d.SELL,0) AS S,
         NVL(d.SELL_BTAX,0) AS SB,
         NVL(d.JD_COST,0) AS C,
         NVL(d.DISC,0) AS DC,
         NVL(d.VOU_TAX,0) AS TX,
         NVL(d.VOU_DISC,0) AS VD,
         NVL(d.PER_TAX,0) AS PT,
         NVL(d.PER_DISC,0) AS PD
  FROM MAS.DAILY d
  WHERE d.COMP_NUM = 1 AND d.TYPE = 9 AND d.STORE = :store
    AND d.VDATE >= TO_DATE(:d_from, 'YYYY-MM-DD')
    AND d.VDATE < TO_DATE(:d_to, 'YYYY-MM-DD') + 1
    AND d.MAN_NUM = :man
),
line AS (
  SELECT
    VYEAR, V_NUM,
    Q, B, S, SB, C, DC, TX, VD, PT, PD,
    CASE WHEN SB <> 0 THEN SB ELSE S END AS UP,
    CASE
      WHEN DC <= 1 THEN Q * (CASE WHEN SB <> 0 THEN SB ELSE S END) * DC
      ELSE DC
    END AS LINE_DISC_AMT,
    Q * (CASE WHEN SB <> 0 THEN SB ELSE S END) AS LINE_GROSS_UP,
    Q * S AS LINE_GROSS_S,
    Q * C AS LINE_COST,
    (Q + B) * C AS LINE_COST_QB,
    B * S AS BONUS_S,
    B * C AS BONUS_C
  FROM base
),
inv AS (
  SELECT VYEAR, V_NUM,
         SUM(LINE_GROSS_S) AS GROSS_S,
         SUM(LINE_GROSS_UP) AS GROSS_UP,
         SUM(LINE_GROSS_UP - LINE_DISC_AMT) AS GROSS_AFTER_DISC,
         SUM(LINE_DISC_AMT) AS DISC_AMT,
         MAX(VD) AS VOU_DISC,
         SUM(TX) AS TAX_SUM,
         MAX(PT) AS PER_TAX,
         SUM(LINE_COST) AS COST_Q,
         SUM(LINE_COST_QB) AS COST_QB,
         SUM(BONUS_S) AS BONUS_S,
         SUM(BONUS_C) AS BONUS_C,
         SUM(CASE WHEN Q = 0 AND B > 0 THEN BONUS_S ELSE 0 END) AS PURE_BONUS_S,
         SUM(CASE WHEN Q = 0 AND B > 0 THEN BONUS_C ELSE 0 END) AS PURE_BONUS_C
  FROM line
  GROUP BY VYEAR, V_NUM
)
SELECT
  COUNT(*) AS INV_CNT,
  ROUND(SUM(GROSS_S), 3) AS GROSS_S,
  ROUND(SUM(GROSS_UP), 3) AS GROSS_UP,
  ROUND(SUM(GROSS_AFTER_DISC), 3) AS GROSS_AFTER_DISC,
  ROUND(SUM(DISC_AMT), 3) AS DISC_AMT,
  ROUND(SUM(VOU_DISC), 3) AS VOU_DISC,
  ROUND(SUM(TAX_SUM), 3) AS TAX_SUM,
  ROUND(SUM(COST_Q), 3) AS COST_Q,
  ROUND(SUM(COST_QB), 3) AS COST_QB,
  ROUND(SUM(BONUS_S), 3) AS BONUS_S,
  ROUND(SUM(BONUS_C), 3) AS BONUS_C,
  ROUND(SUM(PURE_BONUS_S), 3) AS PURE_BONUS_S,
  ROUND(SUM(PURE_BONUS_C), 3) AS PURE_BONUS_C,
  ROUND(SUM(GROSS_S - VOU_DISC), 3) AS NET_S_VD,
  ROUND(SUM(GROSS_UP - VOU_DISC), 3) AS NET_UP_VD,
  ROUND(SUM(GROSS_AFTER_DISC - VOU_DISC), 3) AS NET_AD_VD,
  ROUND(SUM(GROSS_S - VOU_DISC - TAX_SUM), 3) AS NET_S_VD_TAX,
  ROUND(SUM(GROSS_UP - VOU_DISC - TAX_SUM), 3) AS NET_UP_VD_TAX,
  ROUND(SUM(GROSS_AFTER_DISC - VOU_DISC - TAX_SUM), 3) AS NET_AD_VD_TAX,
  ROUND(SUM(GROSS_AFTER_DISC - VOU_DISC - BONUS_S), 3) AS NET_AD_VD_BONUS,
  ROUND(SUM(GROSS_AFTER_DISC - VOU_DISC - PURE_BONUS_S), 3) AS NET_AD_VD_PBONUS,
  ROUND(SUM(GROSS_S - VOU_DISC - BONUS_S), 3) AS NET_S_VD_BONUS,
  ROUND(SUM(GROSS_UP - DISC_AMT - VOU_DISC), 3) AS NET_UP_DISC_VD,
  ROUND(SUM(GROSS_S * (1 - PER_TAX/100.0) - VOU_DISC), 3) AS NET_S_PERTAX_VD,
  ROUND(SUM(GROSS_UP / (1 + PER_TAX/100.0) - VOU_DISC), 3) AS NET_UP_DIVTAX_VD,
  ROUND(SUM(COST_Q + BONUS_C), 3) AS COST_Q_PLUS_BONUS,
  ROUND(SUM(COST_Q + PURE_BONUS_C), 3) AS COST_Q_PLUS_PBONUS
FROM inv
";

try {
    $rows = oracle_query_all($conn, $sql, [
        'store' => $store,
        'd_from' => $from,
        'd_to' => $to,
        'man' => $man,
    ]);
} catch (Throwable $e) {
    $out('SQL FAIL: ' . $e->getMessage());
    exit(1);
}

if ($rows === []) {
    $out('no rows');
    exit(0);
}
$r = $rows[0];
$vals = [];
foreach ($r as $k => $v) {
    if (is_object($v) && method_exists($v, 'load')) {
        $v = $v->load();
    }
    $vals[strtoupper((string) $k)] = is_numeric($v) ? (float) $v : $v;
    $out(strtoupper((string) $k) . '=' . (is_scalar($v) ? (string) $v : json_encode($v)));
}

$candidates = [];
foreach ($vals as $k => $v) {
    if (!is_float($v) && !is_int($v)) {
        continue;
    }
    if (str_starts_with($k, 'NET_') || str_starts_with($k, 'COST_') || str_starts_with($k, 'GROSS_')) {
        $candidates[$k] = (float) $v;
    }
}

$out('--- closest to Forms NET ' . $targetNet . ' ---');
$netRank = [];
foreach ($candidates as $k => $v) {
    if (!str_starts_with($k, 'NET_')) {
        continue;
    }
    $netRank[] = ['k' => $k, 'v' => $v, 'd' => abs($v - $targetNet)];
}
usort($netRank, static fn ($a, $b) => $a['d'] <=> $b['d']);
foreach (array_slice($netRank, 0, 8) as $x) {
    $out(sprintf('%s = %.3f  Δ=%.3f', $x['k'], $x['v'], $x['d']));
}

$out('--- closest to Forms COST ' . $targetCost . ' ---');
$costRank = [];
foreach ($candidates as $k => $v) {
    if (!str_starts_with($k, 'COST_')) {
        continue;
    }
    $costRank[] = ['k' => $k, 'v' => $v, 'd' => abs($v - $targetCost)];
}
usort($costRank, static fn ($a, $b) => $a['d'] <=> $b['d']);
foreach (array_slice($costRank, 0, 8) as $x) {
    $out(sprintf('%s = %.3f  Δ=%.3f', $x['k'], $x['v'], $x['d']));
}

// فترات بديلة شائعة (Forms قد يكون حتى 20 وليس 30)
foreach ([['2026-09-01', '2026-09-20'], ['2026-09-01', '2026-09-30']] as $rng) {
    if ($rng[0] === $from && $rng[1] === $to) {
        continue;
    }
    try {
        $alt = oracle_query_all($conn, $sql, [
            'store' => $store,
            'd_from' => $rng[0],
            'd_to' => $rng[1],
            'man' => $man,
        ]);
        if ($alt !== []) {
            $a = $alt[0];
            $netAd = (float) oracle_statement_row_val($a, 'NET_AD_VD');
            $netUp = (float) oracle_statement_row_val($a, 'NET_UP_VD');
            $costQ = (float) oracle_statement_row_val($a, 'COST_Q');
            $costQb = (float) oracle_statement_row_val($a, 'COST_QB');
            $out(sprintf(
                'ALT %s..%s NET_AD_VD=%.3f NET_UP_VD=%.3f COST_Q=%.3f COST_QB=%.3f',
                $rng[0],
                $rng[1],
                $netAd,
                $netUp,
                $costQ,
                $costQb
            ));
        }
    } catch (Throwable $e) {
        $out('ALT FAIL ' . $rng[0] . ': ' . $e->getMessage());
    }
}
