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
        if ($item['item'] === '') {
            return [
                'ok' => false,
                'message' => 'مادة بدون رمز Oracle/SKU: ' . trim((string) ($ln['item_name'] ?? $ln['id'] ?? '')),
            ];
        }
        $qty = (float) ($ln['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $taxPct = (float) ($ln['tax_rate_percent'] ?? 0);
        $mappedLines[] = [
            'item' => $item['item'],
            'cat' => $item['cat'],
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
            'BATCH' => '0',
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

        return ['ok' => false, 'message' => 'فشل إدراج الفاتورة في Oracle: ' . $e->getMessage()];
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
    $skip = ['ROWID' => true, 'ORA_ROWSCN' => true];
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
 * @return array{item:string,cat:string}
 */
function oracle_order_item_keys(PDO $pdo, array $ln): array
{
    $itemId = (int) ($ln['item_id'] ?? 0);
    $sku = trim((string) ($ln['sku'] ?? $ln['item_sku'] ?? $ln['item_code'] ?? ''));
    $item = $sku;
    $cat = '';
    if ($itemId > 0) {
        try {
            $st = $pdo->prepare(
                'SELECT COALESCE(NULLIF(TRIM(i.oracle_key), \'\'), NULLIF(TRIM(i.sku), \'\'), \'\') AS ikey,
                        COALESCE(NULLIF(TRIM(c.oracle_key), \'\'), NULLIF(TRIM(c.code), \'\'), \'\') AS ckey
                 FROM inv_item i
                 LEFT JOIN inv_item_category c ON c.id = i.category_id
                 WHERE i.id = ? LIMIT 1'
            );
            $st->execute([$itemId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $ik = trim((string) ($row['ikey'] ?? ''));
            if ($ik !== '') {
                $item = $ik;
            }
            $cat = trim((string) ($row['ckey'] ?? ''));
        } catch (Throwable $e) {
            try {
                $st = $pdo->prepare('SELECT sku FROM inv_item WHERE id = ? LIMIT 1');
                $st->execute([$itemId]);
                $ik = trim((string) ($st->fetchColumn() ?: ''));
                if ($ik !== '') {
                    $item = $ik;
                }
            } catch (Throwable $e2) {
                // keep sku
            }
        }
    }

    return ['item' => $item, 'cat' => $cat];
}
