<?php
declare(strict_types=1);

/**
 * ترحيل طلب شراء عميل معتمد → بنود فاتورة بيع في MAS.DAILY (شاشة INV00024).
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
    $stype = (int) $sc['sale_type'];
    $from = '"' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';

    try {
        $colMeta = oracle_describe_table($conn, $owner, $table);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر قراءة أعمدة ' . $owner . '.' . $table . ': ' . $e->getMessage()];
    }
    $cols = [];
    foreach ($colMeta as $c) {
        $n = strtoupper(trim((string) ($c['column_name'] ?? '')));
        if ($n !== '') {
            $cols[$n] = true;
        }
    }
    if ($cols === []) {
        return ['ok' => false, 'message' => 'جدول الفاتورة بلا أعمدة معروفة.'];
    }

    $pickCol = static function (array $names) use ($cols): ?string {
        foreach ($names as $n) {
            $u = strtoupper($n);
            if (isset($cols[$u])) {
                return $u;
            }
        }

        return null;
    };

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
    $compNum = oracle_order_comp_num($sample);

    try {
        $numBinds = ['stype' => $stype, 'y' => $vyear];
        $numWhere = 'TYPE = :stype AND VYEAR = :y';
        if (isset($cols['COMP_NUM']) && $compNum > 0) {
            $numWhere .= ' AND COMP_NUM = :cnum';
            $numBinds['cnum'] = $compNum;
        }
        $nextRows = oracle_query_all(
            $conn,
            "SELECT NVL(MAX(V_NUM), 0) AS MX FROM {$from} WHERE {$numWhere}",
            $numBinds
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر احتساب رقم الفاتورة التالي: ' . $e->getMessage()];
    }
    $vNum = (int) oracle_statement_row_val($nextRows[0] ?? [], 'MX') + 1;
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

    $headerExtras = [];
    $smCol = $pickCol(['SALESMAN', 'SALES_MAN', 'SMAN', 'EMP_NO', 'SELLER', 'SALEMAN']);
    if ($smCol && (int) $salesman['no'] > 0) {
        $headerExtras[$smCol] = (int) $salesman['no'];
    }
    $payCol = $pickCol(['CASH', 'CASH_CR', 'PAY_TYPE', 'CREDIT', 'CUS_PAY', 'PAID_TYPE']);
    if ($payCol) {
        $headerExtras[$payCol] = 1;
    }
    $ordCol = $pickCol(['ORDER_NO', 'ORD_NUM', 'ORD_NO', 'REQ_NO', 'V_ORDER', 'CUST_ORD', 'PO_NO']);
    if ($ordCol) {
        $headerExtras[$ordCol] = (string) ($order['order_no'] ?? '');
    }
    $noteCol = $pickCol(['NOTE', 'NOTES', 'REMARK', 'REMARKS', 'COMM', 'V_NOTE']);
    if ($noteCol) {
        $headerExtras[$noteCol] = 'Hypex ' . (string) ($order['order_no'] ?? '');
    }
    $flagCol = $pickCol(['FLAG', 'V_FLAG', 'POSTED', 'STAT', 'STATUS']);
    if ($flagCol) {
        $headerExtras[$flagCol] = 0;
    }
    $depCol = $pickCol(['CUST_DEP']);
    $custDep = 1;
    if ($depCol) {
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
        'lines' => $mappedLines,
        'extra_columns' => $headerExtras,
        'daily_columns' => array_keys($cols),
    ];

    if ($dryRun) {
        return [
            'ok' => true,
            'dry' => true,
            'message' => 'معاينة الترحيل — لم يُكتب شيء في Oracle.',
            'preview' => $preview,
        ];
    }

    $rowValues = static function (array $line) use (
        $stype,
        $vNum,
        $vyear,
        $orderDate,
        $store,
        $custAcc,
        $custDep,
        $compNum,
        $headerExtras,
        $order
    ): array {
        $perDisc = (float) ($order['discount_amount'] ?? 0);
        $vouDisc = $perDisc;
        $base = [
            'TYPE' => $stype,
            'V_NUM' => $vNum,
            'VYEAR' => $vyear,
            'VDATE' => $orderDate,
            'STORE' => $store,
            'CUST_ACC' => $custAcc,
            'CUST_DEP' => $custDep,
            'COMP_NUM' => $compNum,
            'ITEM' => $line['item'],
            'CAT' => $line['cat'],
            'BATCH' => $line['batch'] !== '' ? $line['batch'] : '0',
            'QTY' => $line['qty'],
            'BONUS' => $line['bonus'],
            'SELL' => $line['sell'],
            'DISC' => $line['disc'],
            'VOU_TAX' => $line['vou_tax'],
            'PER_TAX' => $line['per_tax'],
            'PER_DISC' => 0,
            'VOU_DISC' => $vouDisc,
            'JD_COST' => 0,
            'TR_UNIT' => $line['tr_unit'],
        ];

        return array_merge($base, $headerExtras);
    };

    $colTypes = [];
    foreach ($colMeta as $c) {
        $cn = strtoupper(trim((string) ($c['column_name'] ?? '')));
        if ($cn !== '') {
            $colTypes[$cn] = strtoupper((string) ($c['data_type'] ?? ''));
        }
    }

    try {
        oracle_try_begin($conn);
        foreach ($mappedLines as $line) {
            $vals = $rowValues($line);
            $use = [];
            foreach ($vals as $col => $val) {
                if (isset($cols[$col])) {
                    $use[$col] = $val;
                }
            }
            if (!isset($use['TYPE'], $use['V_NUM'], $use['ITEM'])) {
                throw new RuntimeException('أعمدة TYPE/V_NUM/ITEM غير موجودة في DAILY.');
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
            $sqlBinds = implode(', ', $parts);
            $binds = [];
            foreach ($use as $col => $val) {
                $dt = $colTypes[$col] ?? '';
                if (str_contains($dt, 'DATE') || $col === 'VDATE') {
                    $binds[$col] = oracle_order_bind_date($val);
                } else {
                    $binds[$col] = $val;
                }
            }
            oracle_execute(
                $conn,
                "INSERT INTO {$from} ({$sqlCols}) VALUES ({$sqlBinds})",
                $binds
            );
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
            . '. افتح شاشة المبيعات (INV00024) واستعلم عن الرقم ثم راجع واحفظ.',
        'v_num' => $vNum,
        'vyear' => $vyear,
        'store' => $store,
        'cust_acc' => $custAcc,
        'line_count' => count($mappedLines),
    ];
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
