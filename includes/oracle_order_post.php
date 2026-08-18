<?php
declare(strict_types=1);

/**
 * ترحيل طلب شراء عميل معتمد → رأس فاتورة في MAS.MASTER_D ثم البنود في MAS.DAILY (INV00024).
 * الفاتورة تُدرج في أوراكل ويبقى على المستخدم فتح الشاشة ومراجعتها ثم الحفظ/الترحيل هناك.
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_sales_invoice.php');
require_once app_path('includes/sal_customer_order.php');

function oracle_order_schema_ensure(PDO $pdo): void
{
    foreach ([
        'oracle_v_num INT UNSIGNED NULL DEFAULT NULL',
        'oracle_vyear SMALLINT UNSIGNED NULL DEFAULT NULL',
        'oracle_posted_at DATETIME NULL DEFAULT NULL',
        'oracle_post_status VARCHAR(20) NULL DEFAULT NULL',
        'oracle_post_message VARCHAR(500) NULL DEFAULT NULL',
    ] as $def) {
        $col = explode(' ', $def, 2)[0];
        try {
            $pdo->query('SELECT `' . $col . '` FROM sal_customer_order LIMIT 1');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE sal_customer_order ADD COLUMN `' . $col . '` ' . explode(' ', $def, 2)[1]);
            } catch (Throwable $e2) {
                // ignore
            }
        }
    }
    try {
        $pdo->query('SELECT oracle_store FROM inv_warehouse LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE inv_warehouse ADD COLUMN oracle_store INT UNSIGNED NULL DEFAULT NULL');
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

/**
 * @return array<string,mixed>
 */
function oracle_post_customer_order(PDO $mysql, int $orderId, int $userId, bool $dryRun = false): array
{
    oracle_order_schema_ensure($mysql);
    sal_customer_order_ensure_schema($mysql);

    $order = sal_customer_order_fetch($mysql, $orderId);
    if (!$order) {
        return ['ok' => false, 'message' => 'الطلب غير موجود.'];
    }
    if (strtolower((string) ($order['status'] ?? '')) !== 'approved') {
        return ['ok' => false, 'message' => 'اعتمد الطلب أولاً ثم رحّله إلى Oracle.'];
    }

    $existingNo = (int) ($order['oracle_v_num'] ?? 0);
    $existingYear = (int) ($order['oracle_vyear'] ?? 0);
    if ($existingNo > 0 && !$dryRun) {
        return [
            'ok' => true,
            'already' => true,
            'message' => 'هذا الطلب مرحّل مسبقاً إلى فاتورة Oracle رقم ' . $existingNo . ' / ' . $existingYear . '.',
            'v_num' => $existingNo,
            'vyear' => $existingYear,
        ];
    }

    $lines = is_array($order['lines'] ?? null) ? $order['lines'] : [];
    if ($lines === []) {
        return ['ok' => false, 'message' => 'لا بنود في الطلب.'];
    }

    if (!oracle_is_enabled()) {
        return ['ok' => false, 'message' => oracle_config_status_message()];
    }
    $conn = oracle_connect();
    if (empty($conn['ok'])) {
        return ['ok' => false, 'message' => (string) ($conn['message'] ?? 'تعذر الاتصال بـ Oracle.')];
    }

    $sc = oracle_sales_invoice_cfg();
    $owner = $sc['owner'];
    $table = $sc['table'];
    $hdrTable = $sc['header_table'];
    $stype = (int) $sc['sale_type'];
    $from = oracle_order_quoted($owner, $table);
    $hdrFrom = oracle_order_quoted($owner, $hdrTable);

    try {
        $colMeta = oracle_describe_table($conn, $owner, $table);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر قراءة أعمدة ' . $owner . '.' . $table . ': ' . $e->getMessage()];
    }
    $cols = oracle_order_cols_from_meta($colMeta);
    if ($cols === []) {
        return ['ok' => false, 'message' => 'جدول الفاتورة بلا أعمدة معروفة.'];
    }

    try {
        $hdrMeta = oracle_describe_table($conn, $owner, $hdrTable);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر قراءة رأس الفاتورة ' . $owner . '.' . $hdrTable . ': ' . $e->getMessage()];
    }
    $hdrCols = oracle_order_cols_from_meta($hdrMeta);
    if ($hdrCols === []) {
        return ['ok' => false, 'message' => 'جدول رأس الفاتورة بلا أعمدة معروفة: ' . $hdrTable];
    }

    $custAcc = preg_replace('/\D+/', '', (string) ($order['customer_code'] ?? '')) ?? '';
    if ($custAcc === '') {
        return ['ok' => false, 'message' => 'رمز العميل غير رقمي — يتعذر ربطه بحساب Oracle.'];
    }

    $store = oracle_order_store_no($mysql, (int) ($order['warehouse_id'] ?? 0));
    $salesman = oracle_sales_invoice_salesman($conn, $custAcc);
    $orderDate = substr((string) ($order['order_date'] ?? date('Y-m-d')), 0, 10);
    $vyear = (int) substr($orderDate, 0, 4);
    if ($vyear < 2000) {
        $vyear = (int) date('Y');
    }

    $sample = oracle_order_sample_daily_row($conn, $from, $stype);
    $hdrSample = oracle_order_sample_daily_row($conn, $hdrFrom, $stype);
    $compNum = oracle_order_comp_num($hdrSample !== [] ? $hdrSample : $sample);

    try {
        $mxHdr = oracle_order_max_vnum($conn, $hdrFrom, $stype, $vyear, $compNum, isset($hdrCols['COMP_NUM']));
        $mxDaily = oracle_order_max_vnum($conn, $from, $stype, $vyear, $compNum, isset($cols['COMP_NUM']));
        $vNum = max($mxHdr, $mxDaily) + 1;
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر احتساب رقم الفاتورة التالي: ' . $e->getMessage()];
    }
    if ($vNum < 1) {
        $vNum = 1;
    }

    $mappedLines = [];
    foreach ($lines as $ln) {
        $item = oracle_order_item_keys($mysql, $ln);
        if ($item['item'] === '' && $item['barcode'] === '') {
            return [
                'ok' => false,
                'message' => 'مادة بدون رمز Oracle/SKU: ' . trim((string) ($ln['item_name'] ?? $ln['id'] ?? '')),
            ];
        }
        $qty = (float) ($ln['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $card = oracle_order_mascard_find($conn, $item['item'], $item['barcode']);
        if ($card === []) {
            $tried = trim($item['item'] . ' / ' . $item['barcode'], ' /');

            return [
                'ok' => false,
                'message' => 'المادة غير موجودة في بطاقة أصناف أوراكل (MASCARD): '
                    . trim((string) ($ln['item_name'] ?? ''))
                    . ' — الرمز المحاوَل: ' . $tried
                    . '. اضبط رمز المادة (SKU / oracle_key) ليطابق رقم الصنف في أوراكل، وليس الباركود.',
            ];
        }
        $taxPct = (float) ($ln['tax_rate_percent'] ?? 0);
        $mappedLines[] = [
            'item' => $card['item'],
            'cat' => $card['cat'],
            'batch' => $card['batch'] !== '' ? $card['batch'] : '0',
            'qty' => $qty,
            'bonus' => (float) ($ln['qty_extra'] ?? 0),
            'sell' => (float) ($ln['unit_price'] ?? 0),
            'disc' => (float) ($ln['discount_pct'] ?? 0),
            'vou_tax' => (float) ($ln['tax_amount'] ?? 0),
            'per_tax' => $taxPct > 1.5 ? ($taxPct / 100) : $taxPct,
            'tr_unit' => (string) ((float) ($ln['unit_factor'] ?? 1)),
            'name' => (string) ($ln['item_name'] ?? ''),
        ];
    }
    if ($mappedLines === []) {
        return ['ok' => false, 'message' => 'لا كميات صالحة للترحيل.'];
    }

    $headerExtras = oracle_order_extras($cols, $salesman, $order);
    $hdrExtras = oracle_order_extras($hdrCols, $salesman, $order);
    $custDep = 1;
    if (isset($cols['CUST_DEP']) || isset($hdrCols['CUST_DEP'])) {
        try {
            $depRows = oracle_query_all(
                $conn,
                'SELECT CUS_DEP FROM ACCINV.CUSTOMER WHERE TO_CHAR(CUS_NUM) = :acc AND ROWNUM <= 1',
                ['acc' => $custAcc]
            );
            if ($depRows !== []) {
                $d = (int) oracle_statement_row_val($depRows[0], 'CUS_DEP');
                if ($d > 0) {
                    $custDep = $d;
                }
            }
        } catch (Throwable $e) {
            // افتراضي 1
        }
    }

    $sumQty = 0.0;
    $sumTax = 0.0;
    foreach ($mappedLines as $ml) {
        $sumQty += (float) $ml['qty'];
        $sumTax += (float) $ml['vou_tax'];
    }

    $preview = [
        'v_num' => $vNum,
        'vyear' => $vyear,
        'vdate' => $orderDate,
        'store' => $store,
        'cust_acc' => $custAcc,
        'cust_dep' => $custDep,
        'comp_num' => $compNum,
        'type' => $stype,
        'salesman' => $salesman,
        'order_no' => (string) ($order['order_no'] ?? ''),
        'header_table' => $hdrTable,
        'lines' => $mappedLines,
        'extra_columns' => $headerExtras,
        'daily_columns' => array_keys($cols),
        'master_columns' => array_keys($hdrCols),
    ];

    if ($dryRun) {
        return [
            'ok' => true,
            'dry' => true,
            'message' => 'معاينة الترحيل — لم يُكتب شيء في Oracle.',
            'preview' => $preview,
        ];
    }

    $sharedHeader = [
        'TYPE' => $stype,
        'V_NUM' => $vNum,
        'VYEAR' => $vyear,
        'VDATE' => $orderDate,
        'STORE' => $store,
        'CUST_ACC' => $custAcc,
        'CUST_DEP' => $custDep,
        'COMP_NUM' => $compNum,
        'VOU_DISC' => (float) ($order['discount_amount'] ?? 0),
        'VOU_TAX' => (float) ($order['tax_amount'] ?? $sumTax),
        'PER_DISC' => 0,
        'QTY' => $sumQty,
        'TOT_QTY' => $sumQty,
        'AMT' => (float) ($order['total'] ?? 0),
        'TOTAL' => (float) ($order['total'] ?? 0),
        'GROSS' => (float) ($order['subtotal'] ?? 0),
        'NET' => (float) ($order['total'] ?? 0),
        'TOT_AMT' => (float) ($order['total'] ?? 0),
        'TOT_TAX' => (float) ($order['tax_amount'] ?? $sumTax),
        'FLAG' => 0,
        'ORDER_NO' => (string) ($order['order_no'] ?? ''),
        'NOTE' => 'Hypex ' . (string) ($order['order_no'] ?? ''),
        'NOTES' => 'Hypex ' . (string) ($order['order_no'] ?? ''),
        'REMARK' => 'Hypex ' . (string) ($order['order_no'] ?? ''),
    ];
    if ((int) ($salesman['no'] ?? 0) > 0) {
        $sharedHeader['SALESMAN'] = (int) $salesman['no'];
        $sharedHeader['SALES_MAN'] = (int) $salesman['no'];
        $sharedHeader['SMAN'] = (int) $salesman['no'];
    }

    $rowValues = static function (array $line) use ($sharedHeader, $headerExtras): array {
        $base = $sharedHeader;
        $base['ITEM'] = $line['item'];
        $base['CAT'] = $line['cat'];
        $base['BATCH'] = $line['batch'] !== '' ? $line['batch'] : '0';
        $base['QTY'] = $line['qty'];
        $base['BONUS'] = $line['bonus'];
        $base['SELL'] = $line['sell'];
        $base['DISC'] = $line['disc'];
        $base['VOU_TAX'] = $line['vou_tax'];
        $base['PER_TAX'] = $line['per_tax'];
        $base['TR_UNIT'] = $line['tr_unit'];
        $base['JD_COST'] = 0;

        return array_merge($base, $headerExtras);
    };

    try {
        oracle_try_begin($conn);
        oracle_order_insert_row(
            $conn,
            $hdrFrom,
            $hdrMeta,
            oracle_order_seed_header($hdrSample, array_merge($sharedHeader, $hdrExtras), $hdrCols),
            $hdrSample
        );
        foreach ($mappedLines as $line) {
            oracle_order_insert_row($conn, $from, $colMeta, $rowValues($line), $sample);
        }
        oracle_try_commit($conn);
    } catch (Throwable $e) {
        oracle_try_rollback($conn);
        try {
            $mysql->prepare(
                'UPDATE sal_customer_order SET oracle_post_status=?, oracle_post_message=?, updated_by=? WHERE id=?'
            )->execute(['error', substr($e->getMessage(), 0, 500), $userId > 0 ? $userId : null, $orderId]);
        } catch (Throwable $e2) {
            // ignore
        }

        return ['ok' => false, 'message' => 'فشل إدراج الفاتورة في Oracle: ' . oracle_order_fk_message($conn, $e->getMessage())];
    }

    try {
        $mysql->prepare(
            'UPDATE sal_customer_order
             SET oracle_v_num=?, oracle_vyear=?, oracle_posted_at=NOW(),
                 oracle_post_status=?, oracle_post_message=?, updated_by=?
             WHERE id=?'
        )->execute([
            $vNum,
            $vyear,
            'posted',
            'فاتورة ' . $vNum . ' / ' . $vyear,
            $userId > 0 ? $userId : null,
            $orderId,
        ]);
    } catch (Throwable $e) {
        return [
            'ok' => true,
            'message' => 'أُدرجت الفاتورة في Oracle رقم ' . $vNum . ' لكن تعذر حفظ الرقم في Hypex.',
            'v_num' => $vNum,
            'vyear' => $vyear,
        ];
    }

    return [
        'ok' => true,
        'message' => 'تم إنشاء فاتورة بيع Oracle رقم ' . $vNum . ' لسنة ' . $vyear
            . '. في INV00024: استعلام (F7) → رقم الفاتورة ' . $vNum . ' والسنة ' . $vyear . ' → تنفيذ (F8)، ثم راجع واحفظ.'
            . ' لا تفتح فاتورة قديمة من شاشة العرض في Hypex؛ الرقم الجديد هو ' . $vNum . '.',
        'v_num' => $vNum,
        'vyear' => $vyear,
        'store' => $store,
        'cust_acc' => $custAcc,
        'line_count' => count($mappedLines),
    ];
}

function oracle_order_quoted(string $owner, string $table): string
{
    return '"' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';
}

/**
 * @param list<array{column_name:string,data_type?:string,nullable?:bool}> $colMeta
 * @return array<string,bool>
 */
function oracle_order_cols_from_meta(array $colMeta): array
{
    $cols = [];
    foreach ($colMeta as $c) {
        $n = strtoupper(trim((string) ($c['column_name'] ?? '')));
        if ($n !== '') {
            $cols[$n] = true;
        }
    }

    return $cols;
}

/**
 * @param array<string,bool> $cols
 * @param list<string> $names
 */
function oracle_order_pick_col(array $cols, array $names): ?string
{
    foreach ($names as $n) {
        $u = strtoupper($n);
        if (isset($cols[$u])) {
            return $u;
        }
    }

    return null;
}

function oracle_order_max_vnum(array $conn, string $from, int $stype, int $vyear, int $compNum, bool $hasComp): int
{
    $where = 'TYPE = :stype AND VYEAR = :y';
    $binds = ['stype' => $stype, 'y' => $vyear];
    if ($hasComp && $compNum > 0) {
        $where .= ' AND COMP_NUM = :cnum';
        $binds['cnum'] = $compNum;
    }
    $rows = oracle_query_all(
        $conn,
        "SELECT NVL(MAX(V_NUM), 0) AS MX FROM {$from} WHERE {$where}",
        $binds
    );

    return (int) oracle_statement_row_val($rows[0] ?? [], 'MX');
}

/**
 * @param array<string,bool> $cols
 * @param array{no:int,name?:string} $salesman
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function oracle_order_extras(array $cols, array $salesman, array $order): array
{
    $extras = [];
    $smCol = oracle_order_pick_col($cols, ['SALESMAN', 'SALES_MAN', 'SMAN', 'EMP_NO', 'SELLER', 'SALEMAN']);
    if ($smCol && (int) ($salesman['no'] ?? 0) > 0) {
        $extras[$smCol] = (int) $salesman['no'];
    }
    $payCol = oracle_order_pick_col($cols, ['CASH', 'CASH_CR', 'PAY_TYPE', 'CREDIT', 'CUS_PAY', 'PAID_TYPE']);
    if ($payCol) {
        $extras[$payCol] = 1;
    }
    $ordCol = oracle_order_pick_col($cols, ['ORDER_NO', 'ORD_NUM', 'ORD_NO', 'REQ_NO', 'V_ORDER', 'CUST_ORD', 'PO_NO']);
    if ($ordCol) {
        $extras[$ordCol] = (string) ($order['order_no'] ?? '');
    }
    $noteCol = oracle_order_pick_col($cols, ['NOTE', 'NOTES', 'REMARK', 'REMARKS', 'COMM', 'V_NOTE']);
    if ($noteCol) {
        $extras[$noteCol] = 'Hypex ' . (string) ($order['order_no'] ?? '');
    }
    $flagCol = oracle_order_pick_col($cols, ['FLAG', 'V_FLAG', 'POSTED', 'STAT', 'STATUS']);
    if ($flagCol) {
        $extras[$flagCol] = 0;
    }

    return $extras;
}

/**
 * @param list<array{column_name:string,data_type?:string,nullable?:bool}> $colMeta
 * @param array<string,mixed> $vals
 * @param array<string,mixed> $sample
 */
function oracle_order_insert_row(array $conn, string $from, array $colMeta, array $vals, array $sample): void
{
    $cols = oracle_order_cols_from_meta($colMeta);
    $colTypes = [];
    foreach ($colMeta as $c) {
        $cn = strtoupper(trim((string) ($c['column_name'] ?? '')));
        if ($cn !== '') {
            $colTypes[$cn] = strtoupper((string) ($c['data_type'] ?? ''));
        }
    }
    $use = [];
    foreach (oracle_order_apply_aliases($cols, $vals) as $col => $val) {
        $col = strtoupper((string) $col);
        if (isset($cols[$col])) {
            $use[$col] = $val;
        }
    }
    if (!isset($use['TYPE'], $use['V_NUM'])) {
        throw new RuntimeException('أعمدة TYPE/V_NUM غير موجودة في ' . $from . '.');
    }
    $use = oracle_order_fill_required($use, $colMeta, $sample);
    $names = array_keys($use);
    $sqlCols = implode(', ', $names);
    $parts = [];
    foreach ($names as $col) {
        $dt = $colTypes[$col] ?? '';
        $parts[] = (str_contains($dt, 'DATE') || $col === 'VDATE')
            ? "TO_DATE(:{$col}, 'YYYY-MM-DD')"
            : ':' . $col;
    }
    $binds = [];
    foreach ($use as $col => $val) {
        $dt = $colTypes[$col] ?? '';
        $binds[$col] = (str_contains($dt, 'DATE') || $col === 'VDATE')
            ? oracle_order_bind_date($val)
            : $val;
    }
    oracle_execute(
        $conn,
        "INSERT INTO {$from} ({$sqlCols}) VALUES (" . implode(', ', $parts) . ')',
        $binds
    );
}

/**
 * انسخ أعمدة الرأس من آخر فاتورة ثم اكتب قيم الطلب فوقها، مع مرادفات أسماء الحقول لشاشة INV00024.
 *
 * @param array<string,mixed> $sample
 * @param array<string,mixed> $ours
 * @param array<string,bool> $cols
 * @return array<string,mixed>
 */
function oracle_order_seed_header(array $sample, array $ours, array $cols): array
{
    $skipCopy = [
        'V_NUM' => true,
        'ITEM' => true,
        'CAT' => true,
        'BATCH' => true,
        'QTY' => true,
        'BONUS' => true,
        'SELL' => true,
        'DISC' => true,
        'VOU_TAX' => true,
        'PER_TAX' => true,
        'TR_UNIT' => true,
        'JD_COST' => true,
        'CUST_ACC' => true,
        'STORE' => true,
        'VDATE' => true,
        'ROWID' => true,
    ];
    $out = [];
    foreach ($sample as $k => $v) {
        $k = strtoupper((string) $k);
        if (isset($skipCopy[$k]) || !isset($cols[$k])) {
            continue;
        }
        if ($v === null || $v === '') {
            continue;
        }
        $out[$k] = $v;
    }
    foreach ($ours as $k => $v) {
        $k = strtoupper((string) $k);
        if (isset($cols[$k])) {
            $out[$k] = $v;
        }
    }

    return oracle_order_apply_aliases($cols, $out);
}

/**
 * @param array<string,bool> $cols
 * @param array<string,mixed> $vals
 * @return array<string,mixed>
 */
function oracle_order_apply_aliases(array $cols, array $vals): array
{
    $groups = [
        'CUST_ACC' => ['CUST_ACC', 'CUS_NUM', 'CUS_ACC', 'CUSTOMER', 'ACC_NUM', 'CUST_NO', 'CUSTNO'],
        'STORE' => ['STORE', 'STO_NUM', 'STO_NO', 'STORE_NO', 'WAREHOUSE', 'WH_NO'],
        'VDATE' => ['VDATE', 'V_DATE', 'FDATE', 'INV_DATE', 'BILL_DATE', 'TRN_DATE'],
        'SALESMAN' => ['SALESMAN', 'SALES_MAN', 'SMAN', 'EMP_NO', 'SELLER', 'SALEMAN'],
        'ORDER_NO' => ['ORDER_NO', 'ORD_NUM', 'ORD_NO', 'REQ_NO', 'ORDNUM', 'PO_NO', 'V_ORDER', 'CUST_ORD'],
        'NOTE' => ['NOTE', 'NOTES', 'REMARK', 'REMARKS', 'COMM', 'V_NOTE', 'NOTE1', 'NOTE_1'],
    ];
    foreach ($groups as $src => $names) {
        $val = $vals[$src] ?? null;
        if ($val === null || $val === '') {
            foreach ($names as $n) {
                if (isset($vals[$n]) && $vals[$n] !== null && $vals[$n] !== '') {
                    $val = $vals[$n];
                    break;
                }
            }
        }
        if ($val === null || $val === '') {
            continue;
        }
        foreach ($names as $n) {
            if (isset($cols[$n]) && (!isset($vals[$n]) || $vals[$n] === null || $vals[$n] === '')) {
                $vals[$n] = $val;
            }
        }
    }

    return $vals;
}

/**
 * @return array<string,mixed>
 */
function oracle_order_sample_daily_row(array $conn, string $from, int $stype): array
{
    try {
        $rows = oracle_query_all(
            $conn,
            "SELECT * FROM (
                SELECT * FROM {$from} WHERE TYPE = :stype ORDER BY VYEAR DESC, V_NUM DESC
             ) WHERE ROWNUM <= 1",
            ['stype' => $stype]
        );
    } catch (Throwable $e) {
        return [];
    }
    $row = $rows[0] ?? [];
    $out = [];
    foreach ($row as $k => $v) {
        if (is_object($v) && method_exists($v, 'load')) {
            $v = $v->load();
        }
        $out[strtoupper((string) $k)] = $v;
    }

    return $out;
}

function oracle_order_comp_num(array $sample): int
{
    $cfg = oracle_config();
    $s = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $n = (int) ($s['comp_num'] ?? 0);
    if ($n > 0) {
        return $n;
    }
    $fromSample = (int) oracle_statement_row_val($sample, 'COMP_NUM');
    if ($fromSample > 0) {
        return $fromSample;
    }

    return 1;
}

function oracle_order_bind_date(mixed $v): string
{
    if (is_object($v) && method_exists($v, 'format')) {
        return $v->format('Y-m-d');
    }
    if (is_object($v) && method_exists($v, 'load')) {
        $v = $v->load();
    }
    $s = trim((string) $v);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1];
    }
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})/', $s, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    $ts = strtotime($s);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return date('Y-m-d');
}

/**
 * املأ الأعمدة الإلزامية الناقصة من آخر فاتورة بيع حتى لا يحدث ORA-01400.
 *
 * @param array<string,mixed> $use
 * @param list<array{column_name:string,data_type:string,nullable?:bool}> $colMeta
 * @param array<string,mixed> $sample
 * @return array<string,mixed>
 */
function oracle_order_fill_required(array $use, array $colMeta, array $sample): array
{
    $skip = [
        'ROWID' => true,
        'ORA_ROWSCN' => true,
        'ITEM' => true,
        'CAT' => true,
        'BATCH' => true,
        'QTY' => true,
        'BONUS' => true,
        'SELL' => true,
        'DISC' => true,
        'VOU_TAX' => true,
        'PER_TAX' => true,
        'TR_UNIT' => true,
        'JD_COST' => true,
        'CUST_ACC' => true,
        'STORE' => true,
        'TYPE' => true,
        'V_NUM' => true,
        'VYEAR' => true,
        'VDATE' => true,
    ];
    foreach ($colMeta as $c) {
        $name = strtoupper(trim((string) ($c['column_name'] ?? '')));
        if ($name === '' || isset($skip[$name])) {
            continue;
        }
        $dt = strtoupper((string) ($c['data_type'] ?? ''));
        if (str_contains($dt, 'LOB') || $dt === 'LONG' || $dt === 'RAW' || $dt === 'BFILE') {
            continue;
        }
        $current = $use[$name] ?? null;
        $empty = $current === null || $current === '';
        if (!$empty) {
            continue;
        }
        $nullable = array_key_exists('nullable', $c) ? (bool) $c['nullable'] : true;
        if ($nullable) {
            continue;
        }
        if (array_key_exists($name, $sample) && $sample[$name] !== null && $sample[$name] !== '') {
            $v = $sample[$name];
            if (is_object($v) && method_exists($v, 'load')) {
                $v = $v->load();
            }
            $use[$name] = $v;
            continue;
        }
        if (!$nullable && $name === 'COMP_NUM') {
            $use[$name] = 1;
        }
    }

    return $use;
}

function oracle_order_store_no(PDO $pdo, int $warehouseId): int
{
    $cfg = oracle_config();
    $s = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $fallback = (int) ($s['default_store'] ?? 0);
    if ($warehouseId < 1) {
        return $fallback > 0 ? $fallback : 1;
    }
    try {
        $st = $pdo->prepare('SELECT code, oracle_store FROM inv_warehouse WHERE id = ? LIMIT 1');
        $st->execute([$warehouseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $ora = (int) ($row['oracle_store'] ?? 0);
        if ($ora > 0) {
            return $ora;
        }
        $code = trim((string) ($row['code'] ?? ''));
        if (preg_match('/^\d+$/', $code)) {
            return (int) $code;
        }
    } catch (Throwable $e) {
        try {
            $st = $pdo->prepare('SELECT code FROM inv_warehouse WHERE id = ? LIMIT 1');
            $st->execute([$warehouseId]);
            $code = trim((string) ($st->fetchColumn() ?: ''));
            if (preg_match('/^\d+$/', $code)) {
                return (int) $code;
            }
        } catch (Throwable $e2) {
            // ignore
        }
    }

    return $fallback > 0 ? $fallback : 1;
}

/**
 * @param array<string,mixed> $ln
 * @return array{item:string,cat:string,barcode:string}
 */
function oracle_order_item_keys(PDO $pdo, array $ln): array
{
    $itemId = (int) ($ln['item_id'] ?? 0);
    $barcode = trim((string) ($ln['barcode'] ?? ''));
    $sku = trim((string) ($ln['item_code'] ?? ''));
    $fallback = trim((string) ($ln['sku'] ?? $ln['item_sku'] ?? ''));
    $item = '';
    $cat = '';
    if ($itemId > 0) {
        try {
            $st = $pdo->prepare(
                'SELECT TRIM(i.oracle_key) AS okey, TRIM(i.sku) AS sku, TRIM(i.barcode) AS barcode
                 FROM inv_item i
                 WHERE i.id = ? LIMIT 1'
            );
            $st->execute([$itemId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $okey = trim((string) ($row['okey'] ?? ''));
            $skuRow = trim((string) ($row['sku'] ?? ''));
            $barRow = trim((string) ($row['barcode'] ?? ''));
            if ($skuRow !== '') {
                $sku = $skuRow;
            }
            if ($barRow !== '') {
                $barcode = $barRow;
            }
            if ($okey !== '') {
                $item = $okey;
            } elseif ($skuRow !== '') {
                $item = $skuRow;
            }
        } catch (Throwable $e) {
            try {
                $st = $pdo->prepare('SELECT sku FROM inv_item WHERE id = ? LIMIT 1');
                $st->execute([$itemId]);
                $ik = trim((string) ($st->fetchColumn() ?: ''));
                if ($ik !== '') {
                    $sku = $ik;
                    $item = $ik;
                }
            } catch (Throwable $e2) {
                // keep line values
            }
        }
    }
    if ($item === '') {
        $item = $sku !== '' ? $sku : $fallback;
    }
    if (oracle_order_looks_like_ean($item)) {
        if ($sku !== '' && !oracle_order_looks_like_ean($sku)) {
            $item = $sku;
        } elseif ($barcode === '' || oracle_order_looks_like_ean($barcode)) {
            $barcode = $item;
        }
    }

    return ['item' => $item, 'cat' => $cat, 'barcode' => $barcode];
}

function oracle_order_looks_like_ean(string $s): bool
{
    return (bool) preg_match('/^\d{12,14}$/', $s);
}

/**
 * @return array{item:string,cat:string,batch:string}|array{}
 */
function oracle_order_mascard_find(array $conn, string $item, string $barcode): array
{
    $cfg = oracle_config();
    $si = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $owner = strtoupper(trim((string) ($si['item_card_owner'] ?? 'MAS'))) ?: 'MAS';
    $table = strtoupper(trim((string) ($si['item_card_table'] ?? 'MASCARD'))) ?: 'MASCARD';
    $from = '"' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';

    try {
        $colsMeta = oracle_describe_table($conn, $owner, $table);
    } catch (Throwable $e) {
        $colsMeta = [];
    }
    $cols = [];
    foreach ($colsMeta as $c) {
        $n = strtoupper(trim((string) ($c['column_name'] ?? '')));
        if ($n !== '') {
            $cols[$n] = true;
        }
    }
    $itemCol = isset($cols['ITEM']) ? 'ITEM' : (isset($cols['ITEM_CODE']) ? 'ITEM_CODE' : 'ITEM');
    $catCol = isset($cols['CAT']) ? 'CAT' : (isset($cols['CATE']) ? 'CATE' : '');
    $batchCol = isset($cols['BATCH']) ? 'BATCH' : '';
    $barCols = [];
    foreach (['BARCODE', 'IBARCODE', 'BAR_CODE', 'BCODE', 'ITEM_BARCODE', 'IBAR'] as $c) {
        if (isset($cols[$c])) {
            $barCols[] = $c;
        }
    }

    $row = oracle_order_first_row(
        $conn,
        "SELECT * FROM {$from} WHERE TO_CHAR({$itemCol}) = :v AND ROWNUM <= 1",
        $item
    );
    if ($row === [] && $barcode !== '' && $barcode !== $item) {
        $row = oracle_order_first_row(
            $conn,
            "SELECT * FROM {$from} WHERE TO_CHAR({$itemCol}) = :v AND ROWNUM <= 1",
            $barcode
        );
    }
    if ($row === [] && $barcode !== '') {
        foreach ($barCols as $bc) {
            $row = oracle_order_first_row(
                $conn,
                "SELECT * FROM {$from} WHERE TO_CHAR({$bc}) = :v AND ROWNUM <= 1",
                $barcode
            );
            if ($row !== []) {
                break;
            }
        }
    }
    if ($row === []) {
        return [];
    }

    $outItem = trim(oracle_statement_row_val($row, $itemCol));
    $outCat = $catCol !== '' ? trim(oracle_statement_row_val($row, $catCol)) : '';
    $outBatch = $batchCol !== '' ? trim(oracle_statement_row_val($row, $batchCol)) : '';
    if ($outItem === '') {
        return [];
    }

    if ($outCat === '' || $outBatch === '') {
        $daily = oracle_order_item_daily_defaults($conn, $outItem);
        if ($outCat === '' && $daily['cat'] !== '') {
            $outCat = $daily['cat'];
        }
        if ($outBatch === '' && $daily['batch'] !== '') {
            $outBatch = $daily['batch'];
        }
    }

    return ['item' => $outItem, 'cat' => $outCat, 'batch' => $outBatch];
}

/**
 * @return array<string,mixed>
 */
function oracle_order_first_row(array $conn, string $sql, string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }
    try {
        $rows = oracle_query_all($conn, $sql, ['v' => $value]);
    } catch (Throwable $e) {
        return [];
    }

    return is_array($rows[0] ?? null) ? $rows[0] : [];
}

/**
 * @return array{cat:string,batch:string}
 */
function oracle_order_item_daily_defaults(array $conn, string $item): array
{
    $empty = ['cat' => '', 'batch' => ''];
    $item = trim($item);
    if ($item === '') {
        return $empty;
    }
    $sc = oracle_sales_invoice_cfg();
    $from = '"' . str_replace('"', '""', $sc['owner']) . '"."' . str_replace('"', '""', $sc['table']) . '"';
    try {
        $rows = oracle_query_all(
            $conn,
            "SELECT * FROM (
                SELECT CAT, BATCH FROM {$from}
                 WHERE TO_CHAR(ITEM) = :v
                 ORDER BY VYEAR DESC, V_NUM DESC
             ) WHERE ROWNUM <= 1",
            ['v' => $item]
        );
    } catch (Throwable $e) {
        return $empty;
    }
    $row = $rows[0] ?? [];
    if ($row === []) {
        return $empty;
    }

    return [
        'cat' => trim(oracle_statement_row_val($row, 'CAT')),
        'batch' => trim(oracle_statement_row_val($row, 'BATCH')),
    ];
}

function oracle_order_fk_message(array $conn, string $msg): string
{
    if (!preg_match('/ORA-02291.*?\\(([A-Z0-9_]+)\\.([A-Z0-9_]+)\\)/i', $msg, $m)) {
        return $msg;
    }
    $owner = strtoupper($m[1]);
    $cname = strtoupper($m[2]);
    try {
        $rows = oracle_query_all(
            $conn,
            "SELECT cc.column_name, r.owner AS r_owner, r.table_name AS r_table, rc.column_name AS r_column
             FROM all_constraints c
             JOIN all_cons_columns cc
               ON cc.owner = c.owner AND cc.constraint_name = c.constraint_name
             JOIN all_constraints r
               ON r.owner = c.r_owner AND r.constraint_name = c.r_constraint_name
             JOIN all_cons_columns rc
               ON rc.owner = r.owner AND rc.constraint_name = r.constraint_name
              AND rc.position = cc.position
             WHERE c.owner = :ow AND c.constraint_name = :cn
             ORDER BY cc.position",
            ['ow' => $owner, 'cn' => $cname]
        );
    } catch (Throwable $e) {
        return $msg;
    }
    if ($rows === []) {
        return $msg;
    }
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = oracle_statement_row_val($r, 'COLUMN_NAME')
            . ' → '
            . oracle_statement_row_val($r, 'R_OWNER')
            . '.'
            . oracle_statement_row_val($r, 'R_TABLE')
            . '.'
            . oracle_statement_row_val($r, 'R_COLUMN');
    }

    return $msg . ' — المرجع غير موجود: ' . implode(', ', $parts);
}
