<?php
declare(strict_types=1);

/**
 * فواتير بيع Oracle Forms (INV00024) — قراءة من MAS.DAILY
 * المفتاح: V_NUM + VYEAR ، النوع TYPE = 9
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_statement.php');

/**
 * @return array{owner:string,table:string,sale_type:int}
 */
function oracle_sales_invoice_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? 'MAS')));
    $table = strtoupper(trim((string) ($s['table'] ?? 'DAILY')));

    return [
        'owner' => $owner !== '' ? $owner : 'MAS',
        'table' => $table !== '' ? $table : 'DAILY',
        'sale_type' => (int) ($s['sale_type'] ?? 9),
    ];
}

/**
 * @return array{
 *   ok:bool, message?:string, matches?:list<array<string,mixed>>,
 *   header?:array<string,mixed>|null, lines?:list<array<string,mixed>>
 * }
 */
function oracle_fetch_sales_invoice_by_no(int $invoiceNo, int $year = 0): array
{
    $empty = [
        'ok' => false,
        'message' => '',
        'matches' => [],
        'header' => null,
        'lines' => [],
    ];

    if ($invoiceNo < 1) {
        $empty['message'] = 'أدخل رقم الفاتورة.';

        return $empty;
    }
    if (!oracle_is_enabled()) {
        $empty['message'] = oracle_config_status_message();

        return $empty;
    }

    $conn = oracle_connect();
    if (empty($conn['ok'])) {
        $empty['message'] = (string) ($conn['message'] ?? 'تعذر الاتصال بـ Oracle.');

        return $empty;
    }

    $sc = oracle_sales_invoice_cfg();
    $from = oracle_stmt_q($sc['owner']) . '.' . oracle_stmt_q($sc['table']);
    $saleType = (int) $sc['sale_type'];

    $binds = [
        'vnum' => $invoiceNo,
        'stype' => $saleType,
    ];
    $yearSql = '';
    if ($year > 1900 && $year < 2100) {
        $yearSql = ' AND VYEAR = :vyear ';
        $binds['vyear'] = $year;
    }

    try {
        $matchSql = "SELECT V_NUM, VYEAR, MIN(VDATE) AS VDATE,
                            MAX(STORE) AS STORE,
                            MAX(CUST_ACC) AS CUST_ACC,
                            MAX(CUST_DEP) AS CUST_DEP,
                            COUNT(*) AS LINE_CNT,
                            SUM(QTY) AS QTY_SUM,
                            SUM(QTY * SELL) AS GROSS,
                            MAX(VOU_DISC) AS VOU_DISC,
                            SUM(VOU_TAX) AS TAX_SUM,
                            MAX(PER_DISC) AS PER_DISC,
                            MAX(PER_TAX) AS PER_TAX
                     FROM {$from}
                     WHERE TYPE = :stype
                       AND V_NUM = :vnum
                       {$yearSql}
                     GROUP BY V_NUM, VYEAR
                     ORDER BY VYEAR DESC, V_NUM DESC";
        $matchesRaw = oracle_query_all($conn, $matchSql, $binds);
    } catch (Throwable $e) {
        $empty['message'] = 'تعذر قراءة فواتير البيع من Oracle: ' . $e->getMessage();

        return $empty;
    }

    $matches = [];
    foreach ($matchesRaw as $r) {
        $matches[] = [
            'v_num' => (int) oracle_statement_row_val($r, 'V_NUM'),
            'vyear' => (int) oracle_statement_row_val($r, 'VYEAR'),
            'vdate' => oracle_sales_invoice_iso_date(oracle_statement_row_val($r, 'VDATE')),
            'store' => (int) oracle_statement_row_val($r, 'STORE'),
            'cust_acc' => trim(oracle_statement_row_val($r, 'CUST_ACC')),
            'cust_dep' => (int) oracle_statement_row_val($r, 'CUST_DEP'),
            'line_cnt' => (int) oracle_statement_row_val($r, 'LINE_CNT'),
            'qty_sum' => (float) oracle_statement_row_val($r, 'QTY_SUM'),
            'gross' => (float) oracle_statement_row_val($r, 'GROSS'),
            'vou_disc' => (float) oracle_statement_row_val($r, 'VOU_DISC'),
            'tax_sum' => (float) oracle_statement_row_val($r, 'TAX_SUM'),
            'per_disc' => (float) oracle_statement_row_val($r, 'PER_DISC'),
            'per_tax' => (float) oracle_statement_row_val($r, 'PER_TAX'),
        ];
    }

    if ($matches === []) {
        return [
            'ok' => true,
            'message' => 'لا توجد فاتورة بيع بهذا الرقم في Oracle.',
            'matches' => [],
            'header' => null,
            'lines' => [],
        ];
    }

    // إن وُجدت أكثر من سنة ولم يُحدَّد العام — نعيد القائمة فقط
    if ($year < 1 && count($matches) > 1) {
        return [
            'ok' => true,
            'message' => 'وُجدت عدة فواتير بنفس الرقم في سنوات مختلفة — اختر السنة.',
            'matches' => $matches,
            'header' => null,
            'lines' => [],
        ];
    }

    $pick = $matches[0];
    $pickYear = (int) $pick['vyear'];

    try {
        $lineSql = "SELECT ITEM, CAT, BATCH, QTY, BONUS, SELL, DISC, VOU_TAX,
                           STORE, CUST_ACC, CUST_DEP, VDATE, V_NUM, VYEAR,
                           PER_TAX, PER_DISC, VOU_DISC, JD_COST, TR_UNIT
                    FROM {$from}
                    WHERE TYPE = :stype
                      AND V_NUM = :vnum
                      AND VYEAR = :vyear
                    ORDER BY ITEM";
        $linesRaw = oracle_query_all($conn, $lineSql, [
            'stype' => $saleType,
            'vnum' => $invoiceNo,
            'vyear' => $pickYear,
        ]);
    } catch (Throwable $e) {
        $empty['message'] = 'تعذر قراءة بنود الفاتورة: ' . $e->getMessage();

        return $empty;
    }

    $lines = [];
    foreach ($linesRaw as $r) {
        $qty = (float) oracle_statement_row_val($r, 'QTY');
        $sell = (float) oracle_statement_row_val($r, 'SELL');
        $lines[] = [
            'item' => trim(oracle_statement_row_val($r, 'ITEM')),
            'cat' => trim(oracle_statement_row_val($r, 'CAT')),
            'batch' => trim(oracle_statement_row_val($r, 'BATCH')),
            'qty' => $qty,
            'bonus' => (float) oracle_statement_row_val($r, 'BONUS'),
            'sell' => $sell,
            'disc' => (float) oracle_statement_row_val($r, 'DISC'),
            'vou_tax' => (float) oracle_statement_row_val($r, 'VOU_TAX'),
            'line_gross' => $qty * $sell,
            'store' => (int) oracle_statement_row_val($r, 'STORE'),
            'tr_unit' => trim(oracle_statement_row_val($r, 'TR_UNIT')),
            'jd_cost' => (float) oracle_statement_row_val($r, 'JD_COST'),
        ];
    }

    $custAcc = (string) ($pick['cust_acc'] ?? '');
    $custName = oracle_sales_invoice_customer_name($custAcc);

    $net = (float) $pick['gross'] - (float) $pick['vou_disc'];
    $total = $net + (float) $pick['tax_sum'];

    $header = array_merge($pick, [
        'customer_name' => $custName,
        'net' => $net,
        'total' => $total,
    ]);

    return [
        'ok' => true,
        'message' => '',
        'matches' => $matches,
        'header' => $header,
        'lines' => $lines,
    ];
}

function oracle_sales_invoice_iso_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $raw, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    $ts = strtotime($raw);

    return $ts ? date('Y-m-d', $ts) : $raw;
}

function oracle_sales_invoice_customer_name(string $custAcc): string
{
    $acc = preg_replace('/\D+/', '', $custAcc) ?? '';
    if ($acc === '') {
        return '';
    }
    try {
        $pdo = db();
        $st = $pdo->prepare(
            "SELECT name_ar FROM crm_customer
             WHERE REPLACE(COALESCE(oracle_key,''), ' ', '') = ?
                OR REPLACE(COALESCE(code,''), ' ', '') = ?
             ORDER BY is_active DESC, id DESC
             LIMIT 1"
        );
        $st->execute([$acc, $acc]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (string) ($row['name_ar'] ?? '');
        }
    } catch (Throwable $e) {
        // الاسم اختياري
    }

    // من دليل Oracle إن وُجد
    try {
        if (!oracle_is_enabled()) {
            return '';
        }
        $conn = oracle_connect();
        if (empty($conn['ok'])) {
            return '';
        }
        $cfg = oracle_config();
        $gl = is_array($cfg['customers']['name_from_gl'] ?? null) ? $cfg['customers']['name_from_gl'] : [];
        $glOwn = strtoupper(trim((string) ($gl['owner'] ?? 'ACCINV'))) ?: 'ACCINV';
        $glTbl = strtoupper(trim((string) ($gl['table'] ?? 'GLACTMF'))) ?: 'GLACTMF';
        $glNum = strtoupper(trim((string) ($gl['acc_num'] ?? 'ACC_NUM'))) ?: 'ACC_NUM';
        $glDesc = strtoupper(trim((string) ($gl['acc_desc'] ?? 'ACC_DESC'))) ?: 'ACC_DESC';
        $sql = 'SELECT ' . oracle_stmt_q($glDesc) . ' AS N FROM '
            . oracle_stmt_q($glOwn) . '.' . oracle_stmt_q($glTbl)
            . ' WHERE ' . oracle_stmt_q($glNum) . ' = :acc AND ROWNUM <= 1';
        $nr = oracle_query_all($conn, $sql, ['acc' => $acc]);
        if ($nr !== []) {
            return oracle_statement_row_val($nr[0], 'N');
        }
    } catch (Throwable $e) {
        // اختياري
    }

    return '';
}
