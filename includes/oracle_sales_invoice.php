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
        'nav' => ['first' => null, 'prev' => null, 'next' => null, 'last' => null],
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
    $itemCodes = [];
    foreach ($linesRaw as $r) {
        $qty = (float) oracle_statement_row_val($r, 'QTY');
        $sell = (float) oracle_statement_row_val($r, 'SELL');
        $trUnit = trim(oracle_statement_row_val($r, 'TR_UNIT'));
        $perTax = (float) oracle_statement_row_val($r, 'PER_TAX');
        $item = trim(oracle_statement_row_val($r, 'ITEM'));
        if ($item !== '') {
            $itemCodes[$item] = true;
        }
        $lines[] = [
            'item' => $item,
            'item_name' => '',
            'cat' => trim(oracle_statement_row_val($r, 'CAT')),
            'batch' => trim(oracle_statement_row_val($r, 'BATCH')),
            'qty' => $qty,
            'bonus' => (float) oracle_statement_row_val($r, 'BONUS'),
            'sell' => $sell,
            'disc' => (float) oracle_statement_row_val($r, 'DISC'),
            'vou_tax' => (float) oracle_statement_row_val($r, 'VOU_TAX'),
            'line_gross' => $qty * $sell,
            'store' => (int) oracle_statement_row_val($r, 'STORE'),
            'tr_unit' => $trUnit,
            'unit_label' => $trUnit !== '' ? ('1*' . $trUnit) : '—',
            'per_tax' => $perTax,
            'tax_pct' => round($perTax * 100, 2),
            'jd_cost' => (float) oracle_statement_row_val($r, 'JD_COST'),
        ];
    }

    $names = oracle_sales_invoice_item_names($conn, array_keys($itemCodes));
    foreach ($lines as &$ln) {
        $code = (string) ($ln['item'] ?? '');
        if ($code !== '' && isset($names[$code])) {
            $ln['item_name'] = $names[$code];
        }
    }
    unset($ln);

    $custAcc = (string) ($pick['cust_acc'] ?? '');
    $custName = oracle_sales_invoice_customer_name($custAcc);
    $salesman = oracle_sales_invoice_salesman($conn, $custAcc);

    $net = (float) $pick['gross'] - (float) $pick['vou_disc'];
    $total = $net + (float) $pick['tax_sum'];

    $header = array_merge($pick, [
        'customer_name' => $custName,
        'salesman_no' => $salesman['no'],
        'salesman_name' => $salesman['name'],
        'net' => $net,
        'total' => $total,
    ]);

    $nav = oracle_sales_invoice_neighbors((int) $pick['v_num'], (int) $pick['vyear'], $conn);

    return [
        'ok' => true,
        'message' => '',
        'matches' => $matches,
        'header' => $header,
        'lines' => $lines,
        'nav' => $nav,
    ];
}

/**
 * مفتاح جارٍ (رقم + سنة) أو فارغ.
 *
 * @return array{v_num:int,vyear:int}|null
 */
function oracle_sales_invoice_key_from_row(?array $r): ?array
{
    if ($r === null || $r === []) {
        return null;
    }
    $n = (int) oracle_statement_row_val($r, 'V_NUM');
    $y = (int) oracle_statement_row_val($r, 'VYEAR');
    if ($n < 1 || $y < 1) {
        return null;
    }

    return ['v_num' => $n, 'vyear' => $y];
}

/**
 * أول / آخر / سابق / تالي حسب (VYEAR, V_NUM).
 *
 * @param array<string,mixed>|null $conn اتصال مفتوح اختياري
 * @return array{
 *   first:?array{v_num:int,vyear:int},
 *   prev:?array{v_num:int,vyear:int},
 *   next:?array{v_num:int,vyear:int},
 *   last:?array{v_num:int,vyear:int}
 * }
 */
function oracle_sales_invoice_neighbors(int $vNum, int $vYear, ?array $conn = null): array
{
    $empty = ['first' => null, 'prev' => null, 'next' => null, 'last' => null];
    if ($vNum < 1 || $vYear < 1) {
        return $empty;
    }
    if ($conn === null) {
        if (!oracle_is_enabled()) {
            return $empty;
        }
        $conn = oracle_connect();
        if (empty($conn['ok'])) {
            return $empty;
        }
    }

    $sc = oracle_sales_invoice_cfg();
    $from = oracle_stmt_q($sc['owner']) . '.' . oracle_stmt_q($sc['table']);
    $stype = (int) $sc['sale_type'];
    $base = "SELECT V_NUM, VYEAR FROM {$from} WHERE TYPE = :stype";

    try {
        $firstSql = "SELECT * FROM ({$base} GROUP BY V_NUM, VYEAR ORDER BY VYEAR ASC, V_NUM ASC) WHERE ROWNUM <= 1";
        $lastSql = "SELECT * FROM ({$base} GROUP BY V_NUM, VYEAR ORDER BY VYEAR DESC, V_NUM DESC) WHERE ROWNUM <= 1";
        $prevSql = "SELECT * FROM (
                        {$base}
                          AND (VYEAR < :y OR (VYEAR = :y AND V_NUM < :n))
                        GROUP BY V_NUM, VYEAR
                        ORDER BY VYEAR DESC, V_NUM DESC
                    ) WHERE ROWNUM <= 1";
        $nextSql = "SELECT * FROM (
                        {$base}
                          AND (VYEAR > :y OR (VYEAR = :y AND V_NUM > :n))
                        GROUP BY V_NUM, VYEAR
                        ORDER BY VYEAR ASC, V_NUM ASC
                    ) WHERE ROWNUM <= 1";

        $first = oracle_query_all($conn, $firstSql, ['stype' => $stype]);
        $last = oracle_query_all($conn, $lastSql, ['stype' => $stype]);
        $prev = oracle_query_all($conn, $prevSql, ['stype' => $stype, 'y' => $vYear, 'n' => $vNum]);
        $next = oracle_query_all($conn, $nextSql, ['stype' => $stype, 'y' => $vYear, 'n' => $vNum]);

        return [
            'first' => oracle_sales_invoice_key_from_row($first[0] ?? null),
            'prev' => oracle_sales_invoice_key_from_row($prev[0] ?? null),
            'next' => oracle_sales_invoice_key_from_row($next[0] ?? null),
            'last' => oracle_sales_invoice_key_from_row($last[0] ?? null),
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

/**
 * حلّ فعل التنقّل: first | last | prev | next → مفتاح فاتورة.
 *
 * @return array{v_num:int,vyear:int}|null
 */
function oracle_sales_invoice_resolve_nav(string $nav, int $curNo = 0, int $curYear = 0): ?array
{
    $nav = strtolower(trim($nav));
    if (!in_array($nav, ['first', 'last', 'prev', 'next'], true)) {
        return null;
    }

    if (!oracle_is_enabled()) {
        return null;
    }
    $conn = oracle_connect();
    if (empty($conn['ok'])) {
        return null;
    }

    $sc = oracle_sales_invoice_cfg();
    $from = oracle_stmt_q($sc['owner']) . '.' . oracle_stmt_q($sc['table']);
    $stype = (int) $sc['sale_type'];
    $base = "SELECT V_NUM, VYEAR FROM {$from} WHERE TYPE = :stype";

    try {
        if ($nav === 'first') {
            $sql = "SELECT * FROM ({$base} GROUP BY V_NUM, VYEAR ORDER BY VYEAR ASC, V_NUM ASC) WHERE ROWNUM <= 1";
            $rows = oracle_query_all($conn, $sql, ['stype' => $stype]);

            return oracle_sales_invoice_key_from_row($rows[0] ?? null);
        }
        if ($nav === 'last') {
            $sql = "SELECT * FROM ({$base} GROUP BY V_NUM, VYEAR ORDER BY VYEAR DESC, V_NUM DESC) WHERE ROWNUM <= 1";
            $rows = oracle_query_all($conn, $sql, ['stype' => $stype]);

            return oracle_sales_invoice_key_from_row($rows[0] ?? null);
        }
        if ($curNo < 1 || $curYear < 1) {
            // بدون موضع حالي: prev → آخر، next → أول
            return oracle_sales_invoice_resolve_nav($nav === 'prev' ? 'last' : 'first');
        }
        $neighbors = oracle_sales_invoice_neighbors($curNo, $curYear, $conn);

        return $neighbors[$nav] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * أسماء المواد من MAS.MASCARD.IDESC
 *
 * @param array<string,mixed> $conn
 * @param list<string> $itemCodes
 * @return array<string,string>
 */
function oracle_sales_invoice_item_names(array $conn, array $itemCodes): array
{
    $out = [];
    $codes = [];
    foreach ($itemCodes as $c) {
        $c = trim((string) $c);
        if ($c !== '') {
            $codes[$c] = true;
        }
    }
    if ($codes === [] || empty($conn['ok'])) {
        return $out;
    }

    $cfg = oracle_config();
    $si = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $cardOwn = strtoupper(trim((string) ($si['item_card_owner'] ?? 'MAS'))) ?: 'MAS';
    $cardTbl = strtoupper(trim((string) ($si['item_card_table'] ?? 'MASCARD'))) ?: 'MASCARD';
    $from = oracle_stmt_q($cardOwn) . '.' . oracle_stmt_q($cardTbl);

    // استعلام فردي لتجنّب مشاكل IN مع oci binds على إصدارات قديمة
    foreach (array_keys($codes) as $code) {
        try {
            $rows = oracle_query_all(
                $conn,
                "SELECT IDESC FROM {$from} WHERE TO_CHAR(ITEM) = :itm AND ROWNUM <= 1",
                ['itm' => (string) $code]
            );
            if ($rows !== []) {
                $desc = trim(oracle_statement_row_val($rows[0], 'IDESC'));
                if ($desc !== '') {
                    $out[(string) $code] = $desc;
                }
            }
        } catch (Throwable $e) {
            // تجاهل مادة واحدة
        }
    }

    // احتياطي من MySQL إن وُجدت المادة
    try {
        $pdo = db();
        foreach (array_keys($codes) as $code) {
            if (isset($out[(string) $code])) {
                continue;
            }
            $st = $pdo->prepare(
                "SELECT name_ar FROM inv_item
                 WHERE sku = ? OR barcode = ? OR CAST(id AS CHAR) = ?
                 LIMIT 1"
            );
            $st->execute([(string) $code, (string) $code, (string) $code]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && trim((string) ($row['name_ar'] ?? '')) !== '') {
                $out[(string) $code] = (string) $row['name_ar'];
            }
        }
    } catch (Throwable $e) {
        // اختياري
    }

    return $out;
}

/**
 * البائع الافتراضي للعميل: CUSTOMER.CUS_SALESMAN → EMP_INFO.EMP_NAME
 *
 * @param array<string,mixed> $conn
 * @return array{no:int,name:string}
 */
function oracle_sales_invoice_salesman(array $conn, string $custAcc): array
{
    $empty = ['no' => 0, 'name' => ''];
    $acc = preg_replace('/\D+/', '', $custAcc) ?? '';
    if ($acc === '' || empty($conn['ok'])) {
        return $empty;
    }
    try {
        $rows = oracle_query_all(
            $conn,
            'SELECT CUS_SALESMAN FROM ACCINV.CUSTOMER WHERE TO_CHAR(CUS_NUM) = :acc AND ROWNUM <= 1',
            ['acc' => $acc]
        );
        if ($rows === []) {
            return $empty;
        }
        $no = (int) oracle_statement_row_val($rows[0], 'CUS_SALESMAN');
        if ($no < 1) {
            return $empty;
        }
        $name = '';
        try {
            $er = oracle_query_all(
                $conn,
                'SELECT EMP_NAME FROM ACCINV.EMP_INFO WHERE EMP_NO = :eno AND ROWNUM <= 1',
                ['eno' => $no]
            );
            if ($er !== []) {
                $name = trim(oracle_statement_row_val($er[0], 'EMP_NAME'));
            }
        } catch (Throwable $e) {
            // الاسم اختياري
        }

        return ['no' => $no, 'name' => $name];
    } catch (Throwable $e) {
        return $empty;
    }
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
