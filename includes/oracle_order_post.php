<?php
declare(strict_types=1);

/**
 * ترحيل طلب شراء عميل معتمد → رأس فاتورة في MAS.MASTER_D ثم البنود في MAS.DAILY (INV00024).
 * الفاتورة تُدرج في أوراكل ويبقى على المستخدم فتح الشاشة ومراجعتها ثم الحفظ/الترحيل هناك.
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_sales_invoice.php');
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/inv_invoice_discount.php');

/**
 * نسبة Hypex (10 أو 16) → كسر أوراكل (0.10 / 0.16) مثل PER_TAX و PER_DISC في INV00024.
 */
function oracle_order_pct_to_fraction(float $pct): float
{
    if ($pct <= 0) {
        return 0.0;
    }

    return $pct > 1.5 ? round($pct / 100.0, 6) : $pct;
}

/**
 * خصم رأس الطلب: PER_DISC ككسر و VOU_DISC كمبلغ لنفس الخصم (لا يُطبَّقان مرتين).
 *
 * @return array{per_disc:float,vou_disc:float}
 */
function oracle_order_header_discount(array $order, float $merchAfterLineDisc): array
{
    $headerRaw = trim((string) ($order['invoice_discount_input'] ?? ''));
    $parsed = $headerRaw !== '' ? inv_discount_parse_header_input($headerRaw) : null;
    if ($parsed === null || $merchAfterLineDisc <= 0.0000001) {
        return ['per_disc' => 0.0, 'vou_disc' => 0.0];
    }
    if ($parsed['type'] === 'percent') {
        $perDisc = oracle_order_pct_to_fraction((float) $parsed['value']);

        return [
            'per_disc' => $perDisc,
            'vou_disc' => round($merchAfterLineDisc * $perDisc, 3),
        ];
    }
    $vouDisc = round(min($merchAfterLineDisc, (float) $parsed['value']), 3);

    return [
        'per_disc' => $vouDisc > 0 ? round($vouDisc / $merchAfterLineDisc, 6) : 0.0,
        'vou_disc' => $vouDisc,
    ];
}

/**
 * @return array{columns:list<string>,yes:mixed,no:mixed}
 */
function oracle_order_tax_subject_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $tax = is_array($s['tax_subject'] ?? null) ? $s['tax_subject'] : [];
    $columns = array_values(array_filter(array_map(
        static fn($c) => strtoupper(trim((string) $c)),
        (array) ($tax['columns'] ?? ['STAX', 'TAX_FLAG', 'ST_FLAG', 'CUS_TAX', 'TAXABLE'])
    )));

    return [
        'columns' => $columns !== [] ? $columns : ['STAX', 'TAX_FLAG', 'ST_FLAG', 'CUS_TAX', 'TAXABLE'],
        'yes' => $tax['yes'] ?? 1,
        'no' => $tax['no'] ?? 0,
    ];
}

/**
 * «خاضع لضريبة المبيعات» في INV00024 — يُفرض من الطلب عند وجود ضريبة.
 *
 * @param array<string,bool> $cols
 * @return array<string,mixed>
 */
function oracle_order_tax_header_fields(bool $taxable, array $cols, float $headerPerTax = 0.0): array
{
    $tc = oracle_order_tax_subject_cfg();
    $val = $taxable ? $tc['yes'] : $tc['no'];
    $out = [];
    foreach ($tc['columns'] as $col) {
        if (isset($cols[$col])) {
            $out[$col] = $val;
        }
    }
    if ($taxable && $headerPerTax > 0.000001 && isset($cols['PER_TAX'])) {
        $out['PER_TAX'] = $headerPerTax;
    }

    return $out;
}

/**
 * خصم السطر → DISC ككسر. مبلغ الرأس الموزَّع على البنود لا يُوضع في DISC.
 */
function oracle_order_line_disc_fraction(array $ln, bool $hasHeaderDiscount): float
{
    $pct = (float) ($ln['discount_pct'] ?? 0);
    if ($pct > 0.0000001) {
        return oracle_order_pct_to_fraction($pct);
    }
    if ($hasHeaderDiscount) {
        return 0.0;
    }
    $amt = (float) ($ln['discount_amount'] ?? 0);
    $qty = (float) ($ln['qty'] ?? 0);
    $price = (float) ($ln['unit_price'] ?? 0);
    $merch = $qty * $price;
    if ($amt > 0.0000001 && $merch > 0.0000001) {
        return round(min(1.0, $amt / $merch), 6);
    }

    return 0.0;
}

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
    $salesman = oracle_order_hypex_salesman($mysql, $order);
    $isCash = strtolower((string) ($order['payment_type'] ?? 'credit')) === 'cash';
    $cacr = $isCash ? 1 : 2;
    $cashFlag = $isCash ? 1 : 0;
    $orderDate = substr((string) ($order['order_date'] ?? date('Y-m-d')), 0, 10);
    $vyear = (int) substr($orderDate, 0, 4);
    if ($vyear < 2000) {
        $vyear = (int) date('Y');
    }

    $headerRaw = trim((string) ($order['invoice_discount_input'] ?? ''));
    $hasHeaderDiscount = inv_discount_parse_header_input($headerRaw) !== null;

    $mappedLines = [];
    $merchAfterLineDisc = 0.0;
    $undefinedNames = [];
    foreach ($lines as $ln) {
        $qty = (float) ($ln['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $item = oracle_order_item_keys($mysql, $ln);
        $card = [];
        if ($item['item'] !== '' || $item['barcode'] !== '') {
            $card = oracle_order_mascard_find($conn, $item['item'], $item['barcode']);
        }
        if ($card === []) {
            $undefinedNames[] = oracle_order_line_display_name($ln);
            continue;
        }
        $taxPct = (float) ($ln['tax_rate_percent'] ?? 0);
        $sell = (float) ($ln['unit_price'] ?? 0);
        $discFrac = oracle_order_line_disc_fraction($ln, $hasHeaderDiscount);
        $merchAfterLineDisc += max(0.0, ($qty * $sell) * (1.0 - $discFrac));
        // التشغيلة تُختار لاحقاً من رصيد المستودع (الأقدم أولاً) — لا نعتمد تشغيلة البطاقة.
        $mappedLines[] = [
            'item' => $card['item'],
            'cat' => $card['cat'],
            'batch' => '0',
            'qty' => $qty,
            'bonus' => (float) ($ln['qty_extra'] ?? 0),
            'sell' => $sell,
            'disc' => $discFrac,
            'vou_tax' => (float) ($ln['tax_amount'] ?? 0),
            'per_tax' => oracle_order_pct_to_fraction($taxPct),
            'tr_unit' => (string) ((float) ($ln['unit_factor'] ?? 1)),
            'name' => (string) ($ln['item_name'] ?? ''),
            'srl' => count($mappedLines) + 1,
        ];
    }
    if ($undefinedNames !== []) {
        return oracle_order_undefined_items_payload($undefinedNames);
    }
    if ($mappedLines === []) {
        return ['ok' => false, 'message' => 'لا كميات صالحة للترحيل.'];
    }

    $headerDisc = oracle_order_header_discount($order, $merchAfterLineDisc);

    $sumQty = 0.0;
    $sumTax = 0.0;
    $maxPerTax = 0.0;
    foreach ($mappedLines as $ml) {
        $sumQty += (float) $ml['qty'];
        $sumTax += (float) $ml['vou_tax'];
        $pt = (float) ($ml['per_tax'] ?? 0);
        if ($pt > $maxPerTax) {
            $maxPerTax = $pt;
        }
    }

    $subtotal = (float) ($order['subtotal'] ?? 0);
    $orderTax = (float) ($order['tax_amount'] ?? $sumTax);
    $orderTotal = (float) ($order['total'] ?? 0);
    $orderDisc = (float) ($order['discount_amount'] ?? 0);
    $isTaxable = $orderTax > 0.000001 || $maxPerTax > 0.000001;

    $sample = oracle_order_sample_daily_row($conn, $from, $stype, true, $isTaxable);
    $hdrSample = oracle_order_sample_daily_row($conn, $hdrFrom, $stype, false, $isTaxable);
    $compNum = oracle_order_comp_num($hdrSample !== [] ? $hdrSample : $sample);

    $stockCheck = oracle_order_check_stock($conn, $compNum, $store, $mappedLines);
    if (empty($stockCheck['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($stockCheck['message'] ?? 'رصيد Oracle غير كافٍ — لم يتم الترحيل.'),
            'stock_issues' => $stockCheck['issues'] ?? [],
        ];
    }
    if (is_array($stockCheck['lines'] ?? null)) {
        $mappedLines = $stockCheck['lines'];
    }

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

    $allCols = $cols + $hdrCols;
    $taxHeaderFields = oracle_order_tax_header_fields($isTaxable, $allCols, $maxPerTax);

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
        'per_disc' => $headerDisc['per_disc'],
        'vou_disc' => $headerDisc['vou_disc'],
        'taxable' => $isTaxable,
        'tax_header_fields' => $taxHeaderFields,
        'subtotal' => $subtotal,
        'tax_amount' => $orderTax,
        'total' => $orderTotal,
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
        'VOU_DISC' => $headerDisc['vou_disc'],
        'VOU_TAX' => $orderTax,
        'PER_DISC' => $headerDisc['per_disc'],
        'QTY' => $sumQty,
        'TOT_QTY' => $sumQty,
        'AMT' => $orderTotal,
        'TOTAL' => $orderTotal,
        'GROSS' => round(max(0.0, $subtotal + $orderDisc), 3),
        'NET' => $subtotal,
        'TOT_AMT' => $orderTotal,
        'TOT_TAX' => $orderTax,
        'TRAN_RATE' => 1,
        'CASH' => $cashFlag,
        'CACR' => $cacr,
        'VOU_FLAG' => 18,
        'UPD_FLAG' => 'SS',
        'DH_FLAGE' => 1,
        'REF_FLAG' => 1,
        'TDATE' => $orderDate,
        'DUE_DATE' => $orderDate,
        'TTIME' => date('H:i:s'),
        'USR_ID' => 'HYPX',
        'NOTE1' => 'Hypex ' . (string) ($order['order_no'] ?? ''),
        'NOTE' => 'Hypex ' . (string) ($order['order_no'] ?? ''),
        'NOTES' => 'Hypex ' . (string) ($order['order_no'] ?? ''),
        'REMARK' => 'Hypex ' . (string) ($order['order_no'] ?? ''),
    ];
    $sharedHeader = array_merge($sharedHeader, $taxHeaderFields);
    $smNo = (int) ($salesman['no'] ?? 0);
    if ($smNo < 1) {
        $smNo = 1;
    }
    $sharedHeader['SALESMAN'] = $smNo;
    $sharedHeader['SALES_MAN'] = $smNo;
    $sharedHeader['SMAN'] = $smNo;
    $sharedHeader['MAN_NUM'] = $smNo;

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
        $base['SRL'] = (int) ($line['srl'] ?? 1);
        $base['JD_COST'] = 0;

        return array_merge($base, $headerExtras);
    };

    // فحص أخير فوراً قبل الإدراج (لا ترحيل إن فشل الرصيد)
    $stockCheck2 = oracle_order_check_stock($conn, $compNum, $store, $mappedLines);
    if (empty($stockCheck2['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($stockCheck2['message'] ?? 'رصيد Oracle غير كافٍ — لم يتم الترحيل.'),
            'stock_issues' => $stockCheck2['issues'] ?? [],
            'stock_version' => $stockCheck2['version'] ?? 'STOCK-v3',
        ];
    }
    if (is_array($stockCheck2['lines'] ?? null)) {
        $mappedLines = $stockCheck2['lines'];
    }

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
            oracle_order_insert_row(
                $conn,
                $from,
                $colMeta,
                oracle_order_seed_header($sample, $rowValues($line), $cols),
                $sample
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
            . ' [STOCK-v11-TOAD · مستودع ' . $store . ']'
            . '. في INV00024: استعلام (F7) → رقم الفاتورة ' . $vNum . ' والسنة ' . $vyear . ' → تنفيذ (F8)، ثم راجع واحفظ.'
            . ' لا تفتح فاتورة قديمة من شاشة العرض في Hypex؛ الرقم الجديد هو ' . $vNum . '.',
        'v_num' => $vNum,
        'vyear' => $vyear,
        'store' => $store,
        'cust_acc' => $custAcc,
        'line_count' => count($mappedLines),
        'stock_version' => 'STOCK-v11-TOAD',
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
 * بائع أوراكل = رمز المندوب في Hypex إن كان رقماً (مثل 39)، وإلا 1.
 *
 * @return array{no:int,name:string}
 */
function oracle_order_hypex_salesman(PDO $mysql, array $order): array
{
    $fallback = ['no' => 1, 'name' => ''];
    $code = trim((string) ($order['sales_rep_code'] ?? ''));
    $name = trim((string) ($order['sales_rep_name'] ?? ''));
    $repId = (int) ($order['sales_rep_id'] ?? 0);

    if ($repId > 0 && ($code === '' || $name === '')) {
        try {
            $st = $mysql->prepare('SELECT code, name_ar FROM crm_sales_rep WHERE id = ? LIMIT 1');
            $st->execute([$repId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($code === '') {
                    $code = trim((string) ($row['code'] ?? ''));
                }
                if ($name === '') {
                    $name = trim((string) ($row['name_ar'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            // نكمل بالمتاح
        }
    }

    if ($code === '' && $repId < 1) {
        $customerId = (int) ($order['customer_id'] ?? 0);
        if ($customerId > 0) {
            try {
                require_once app_path('includes/crm_sales_rep_schema.php');
                $reps = crm_customer_sales_reps_for_customer($mysql, $customerId);
                if ($reps !== []) {
                    $rid = (int) ($reps[0]['id'] ?? 0);
                    $name = trim((string) ($reps[0]['name_ar'] ?? ''));
                    if ($rid > 0) {
                        $st = $mysql->prepare('SELECT code, name_ar FROM crm_sales_rep WHERE id = ? LIMIT 1');
                        $st->execute([$rid]);
                        $row = $st->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $code = trim((string) ($row['code'] ?? ''));
                            if ($name === '') {
                                $name = trim((string) ($row['name_ar'] ?? ''));
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // استخدم الافتراضي
            }
        }
    }

    $no = oracle_order_parse_salesman_code($code);
    if ($no > 0) {
        return ['no' => $no, 'name' => $name];
    }

    return $fallback;
}

function oracle_order_parse_salesman_code(string $code): int
{
    $code = trim($code);
    if ($code !== '' && preg_match('/^[1-9]\d*$/', $code)) {
        return (int) $code;
    }
    if (preg_match('/(\d+)/', $code, $m)) {
        $n = (int) $m[1];
        if ($n > 0 && $n < 100000) {
            return $n;
        }
    }

    return 0;
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
    $smCol = oracle_order_pick_col($cols, ['SALESMAN', 'SALES_MAN', 'SMAN', 'MAN_NUM', 'EMP_NO', 'SELLER', 'SALEMAN']);
    if ($smCol && (int) ($salesman['no'] ?? 0) > 0) {
        $extras[$smCol] = (int) $salesman['no'];
    }
    $payCol = oracle_order_pick_col($cols, ['CASH', 'CASH_CR', 'PAY_TYPE', 'CREDIT', 'CUS_PAY', 'PAID_TYPE']);
    if ($payCol) {
        $extras[$payCol] = strtolower((string) ($order['payment_type'] ?? 'credit')) === 'cash' ? 1 : 0;
    }
    $ordCol = oracle_order_pick_col($cols, ['ORDER_NO', 'ORD_NUM', 'ORD_NO', 'REQ_NO', 'V_ORDER', 'CUST_ORD', 'PO_NO']);
    if ($ordCol) {
        $extras[$ordCol] = (string) ($order['order_no'] ?? '');
    }
    $noteCol = oracle_order_pick_col($cols, ['NOTE', 'NOTES', 'REMARK', 'REMARKS', 'COMM', 'V_NOTE']);
    if ($noteCol) {
        $extras[$noteCol] = 'Hypex ' . (string) ($order['order_no'] ?? '');
    }
    $rateCol = oracle_order_pick_col($cols, ['TRAN_RATE']);
    if ($rateCol) {
        $extras[$rateCol] = 1;
    }
    $n1 = oracle_order_pick_col($cols, ['NOTE1', 'NOTE', 'NOTES', 'REMARK']);
    if ($n1 && !isset($extras[$n1])) {
        $extras[$n1] = 'Hypex ' . (string) ($order['order_no'] ?? '');
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
    $use = oracle_order_force_forms_fields($use, $cols);
    $names = array_keys($use);
    $sqlCols = implode(', ', $names);
    $parts = [];
    foreach ($names as $col) {
        $dt = $colTypes[$col] ?? '';
        if ($col === 'TTIME') {
            $parts[] = str_contains($dt, 'DATE')
                ? "TO_DATE(:TTIME, 'YYYY-MM-DD HH24:MI:SS')"
                : ':TTIME';
            continue;
        }
        $parts[] = (str_contains($dt, 'DATE') || $col === 'VDATE' || $col === 'TDATE' || $col === 'DUE_DATE')
            ? "TO_DATE(:{$col}, 'YYYY-MM-DD')"
            : ':' . $col;
    }
    $binds = [];
    foreach ($use as $col => $val) {
        $dt = $colTypes[$col] ?? '';
        if ($col === 'TTIME') {
            if (str_contains($dt, 'DATE')) {
                $d = oracle_order_bind_date($use['TDATE'] ?? $use['VDATE'] ?? date('Y-m-d'));
                $t = preg_match('/\d{1,2}:\d{2}:\d{2}/', (string) $val, $m) ? $m[0] : date('H:i:s');
                $binds[$col] = $d . ' ' . $t;
            } else {
                $binds[$col] = $val;
            }
            continue;
        }
        $binds[$col] = (str_contains($dt, 'DATE') || $col === 'VDATE' || $col === 'TDATE' || $col === 'DUE_DATE')
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
        'VOU_DISC' => true,
        'PER_DISC' => true,
        'VOU_TAX' => true,
        'PER_TAX' => true,
        'TR_UNIT' => true,
        'JD_COST' => true,
        'CUST_ACC' => true,
        'STORE' => true,
        'VDATE' => true,
        'FLAGE' => true,
        'FLAG' => true,
        'PRINT_FLAGE' => true,
        'STAX' => true,
        'TAX_FLAG' => true,
        'ST_FLAG' => true,
        'CUS_TAX' => true,
        'TAXABLE' => true,
        'SELL_BTAX' => true,
        'ROWID' => true,
        'SALESMAN' => true,
        'SALES_MAN' => true,
        'SMAN' => true,
        'MAN_NUM' => true,
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
        'CUST_ACC' => ['CUST_ACC', 'CUS_NUM', 'CUS_NO', 'CUS_ACC', 'CUSTOMER', 'ACC_NUM', 'ACC_NO', 'CUST_NO', 'CUSTNO', 'A_CODE'],
        'STORE' => ['STORE', 'STO_NUM', 'STO_NO', 'STORE_NO', 'WAREHOUSE', 'WH_NO'],
        'VDATE' => ['VDATE', 'V_DATE', 'FDATE', 'INV_DATE', 'BILL_DATE', 'TRN_DATE'],
        'SALESMAN' => ['SALESMAN', 'SALES_MAN', 'SMAN', 'MAN_NUM', 'EMP_NO', 'SELLER', 'SALEMAN'],
        'ORDER_NO' => ['ORDER_NO', 'ORD_NUM', 'ORD_NO', 'REQ_NO', 'ORDNUM', 'PO_NO', 'V_ORDER', 'CUST_ORD'],
        'NOTE' => ['NOTE', 'NOTES', 'REMARK', 'REMARKS', 'COMM', 'V_NOTE', 'NOTE1', 'NOTE_1'],
        'FLAG' => ['FLAG', 'FLAGE', 'V_FLAG'],
        'PRINT_FLAGE' => ['PRINT_FLAGE', 'PRINT_FLAG'],
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

function oracle_order_force_forms_fields(array $use, array $cols): array
{
    $orderDate = '';
    if (isset($use['VDATE'])) {
        $orderDate = oracle_order_bind_date($use['VDATE']);
    } elseif (isset($use['TDATE'])) {
        $orderDate = oracle_order_bind_date($use['TDATE']);
    } else {
        $orderDate = date('Y-m-d');
    }
    $force = [
        'VOU_FLAG' => 18,
        'UPD_FLAG' => 'SS',
        'USR_ID' => 'HYPX',
        'DH_FLAGE' => 1,
        'REF_FLAG' => 1,
        'TDATE' => $orderDate,
        'DUE_DATE' => $orderDate,
        'TRAN_RATE' => 1,
    ];
    foreach ($force as $k => $v) {
        if (isset($cols[$k])) {
            $use[$k] = $v;
        }
    }
    $smNo = (int) ($use['SALESMAN'] ?? $use['MAN_NUM'] ?? $use['SMAN'] ?? 0);
    if ($smNo > 0) {
        if (isset($cols['MAN_NUM'])) {
            $use['MAN_NUM'] = $smNo;
        }
        if (isset($cols['SALESMAN'])) {
            $use['SALESMAN'] = $smNo;
        }
    }
    if (isset($cols['SRL']) && (int) ($use['SRL'] ?? 0) < 1) {
        $use['SRL'] = 1;
    }
    if (isset($cols['TTIME']) && ($use['TTIME'] === null || $use['TTIME'] === '')) {
        $use['TTIME'] = date('H:i:s');
    }

    return $use;
}

/**
 * @return array<string,mixed>
 */
function oracle_order_sample_daily_row(
    array $conn,
    string $from,
    int $stype,
    bool $preferForms = false,
    bool $preferTaxable = false
): array {
    $extra = $preferForms ? ' AND VOU_FLAG = 18 ' : '';
    if ($preferTaxable) {
        $extra .= ' AND NVL(PER_TAX, 0) > 0 ';
    }
    try {
        $rows = oracle_query_all(
            $conn,
            "SELECT * FROM (
                SELECT * FROM {$from} WHERE TYPE = :stype {$extra} ORDER BY VYEAR DESC, V_NUM DESC
             ) WHERE ROWNUM <= 1",
            ['stype' => $stype]
        );
    } catch (Throwable $e) {
        if ($preferTaxable) {
            return oracle_order_sample_daily_row($conn, $from, $stype, $preferForms, false);
        }
        if ($preferForms) {
            return oracle_order_sample_daily_row($conn, $from, $stype, false, false);
        }

        return [];
    }
    if ($rows === [] && $preferTaxable) {
        return oracle_order_sample_daily_row($conn, $from, $stype, $preferForms, false);
    }
    if ($rows === [] && $preferForms) {
        return oracle_order_sample_daily_row($conn, $from, $stype, false, false);
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
        'VOU_DISC' => true,
        'PER_DISC' => true,
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

/**
 * إعداد جدول رصيد المخزون في Oracle (MAS.STOCK).
 *
 * @return array{owner:string,table:string,qty_col:string,enabled:bool,multiply_by_tr_unit:bool,use_man_qty:bool}
 */
function oracle_order_stock_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $st = is_array($s['stock'] ?? null) ? $s['stock'] : [];
    // افتراضي: مفعّل دائماً ما لم يُعطَّل صراحة
    $enabled = array_key_exists('enabled', $st) ? (bool) $st['enabled'] : true;
    $owner = strtoupper(trim((string) ($st['owner'] ?? $s['owner'] ?? 'MAS'))) ?: 'MAS';
    $table = strtoupper(trim((string) ($st['table'] ?? 'STOCK'))) ?: 'STOCK';
    $qtyCol = strtoupper(trim((string) ($st['qty_column'] ?? 'SYS_QTY'))) ?: 'SYS_QTY';

    return [
        'owner' => $owner,
        'table' => $table,
        'qty_col' => $qtyCol,
        'enabled' => $enabled,
        // true فقط إن كان SYS_QTY بالقطعة والبيع بوحدة أكبر (كرتونة)
        'multiply_by_tr_unit' => !empty($st['multiply_by_tr_unit']),
        // بعض الشاشات تعتمد MAN_QTY؛ نأخذ الأكبر بين SYS و MAN لكل تشغيلة
        'use_man_qty' => array_key_exists('use_man_qty', $st) ? (bool) $st['use_man_qty'] : true,
    ];
}

/**
 * تطبيع رقم Oracle (مادة/فئة/مستودع) للمقارنة.
 */
function oracle_order_oracle_num_norm(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    $stripped = ltrim($digits, '0');

    return $stripped !== '' ? $stripped : ($digits !== '' ? '0' : '');
}

/**
 * هل صف STOCK يطابق المادة والفئة المطلوبة؟
 */
function oracle_order_oracle_keys_match(string $rowItem, string $rowCat, string $item, string $cat): bool
{
    if ($item === '') {
        return false;
    }
    $itemOk = oracle_order_oracle_num_norm($rowItem) === oracle_order_oracle_num_norm($item)
        || trim($rowItem) === trim($item);
    if (!$itemOk) {
        return false;
    }
    if ($cat === '') {
        return true;
    }

    return oracle_order_oracle_num_norm($rowCat) === oracle_order_oracle_num_norm($cat)
        || trim($rowCat) === trim($cat);
}

/**
 * قراءة كمية من Oracle (يدعم الفاصلة العشرية 2,332).
 */
function oracle_order_parse_qty(mixed $v): float
{
    if ($v === null || $v === '') {
        return 0.0;
    }
    if (is_int($v) || is_float($v)) {
        $f = (float) $v;

        return is_finite($f) ? $f : 0.0;
    }
    if (is_bool($v)) {
        return $v ? 1.0 : 0.0;
    }
    if (is_object($v)) {
        if (method_exists($v, 'load')) {
            $v = $v->load();
        } elseif (method_exists($v, '__toString')) {
            $v = (string) $v;
        } else {
            return 0.0;
        }
    }
    $s = trim((string) $v);
    if ($s === '' || $s === '.' || $s === '-') {
        return 0.0;
    }
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    if (preg_match('/^\d+,\d+$/', $s)) {
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }

    return is_numeric($s) ? (float) $s : 0.0;
}

/**
 * قراءة أفضل كمية من صف STOCK (SYS_QTY / MAN_QTY / أعمدة أخرى).
 */
function oracle_order_read_row_qty(array $r, array $qtyCols = []): float
{
    $best = 0.0;
    $cols = array_values(array_unique(array_merge(
        ['STOCK_QTY', 'QTY', 'QTY_NUM', 'QTY_STR', 'SYS_QTY', 'MAN_QTY', 'AV_QTY', 'BAL_QTY', 'ONHAND', 'ON_HAND'],
        $qtyCols
    )));
    foreach ($cols as $col) {
        $v = oracle_statement_row_val($r, $col);
        if ($v === '') {
            continue;
        }
        $n = oracle_order_parse_qty($v);
        if ($n > $best) {
            $best = $n;
        }
    }
    foreach ($r as $k => $v) {
        if (is_int($v) || is_float($v)) {
            $uk = strtoupper((string) $k);
            if (preg_match('/^(QTY_X\d+|STOCK_QTY|SYS_QTY|MAN_QTY)$/', $uk)
                || (preg_match('/(QTY|QNT|BAL|ONHAND|AVAIL)/', $uk)
                    && !preg_match('/(COMP_NUM|ITEM_NUM|STORE_NUM|CAT_NUM|_NO$|_ID$|DATE|TIME|FLAG|CODE|NAME|DESC)/', $uk))) {
                $n = (float) $v;
                if ($n > $best) {
                    $best = $n;
                }
            }
            continue;
        }
        $uk = strtoupper((string) $k);
        if (!preg_match('/^(QTY_X\d+|STOCK_QTY|SYS_QTY|MAN_QTY)$/', $uk)
            && !preg_match('/(QTY|QNT|BAL|ONHAND|AVAIL)/', $uk)) {
            continue;
        }
        if (preg_match('/(COMP_NUM|ITEM_NUM|STORE_NUM|CAT_NUM|_NO$|_ID$|DATE|TIME|FLAG|CODE|NAME|DESC)/', $uk)) {
            continue;
        }
        $n = oracle_order_parse_qty(oracle_statement_row_val($r, (string) $k));
        if ($n > $best) {
            $best = $n;
        }
    }

    return $best;
}

/**
 * هل النص يشبه تاريخاً (يظهر أحياناً في عمود BATCH بالخطأ في Toad).
 */
function oracle_order_batch_looks_like_date(string $s): bool
{
    $s = trim($s);
    if ($s === '') {
        return false;
    }

    return (bool) preg_match('/^\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}$/', $s)
        || (bool) preg_match('/^\d{1,2}-[A-Za-z]{3}-\d{2,4}$/', $s);
}

/**
 * تحويل تاريخ Oracle/نص إلى Y-m-d.
 */
function oracle_order_parse_stock_date(mixed $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_object($v) && method_exists($v, 'format')) {
        return $v->format('Y-m-d');
    }
    if (is_object($v) && method_exists($v, 'load')) {
        $v = $v->load();
    }
    $s = trim((string) $v);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1];
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $s, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) {
            $y += $y >= 70 ? 1900 : 2000;
        }
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    $ts = strtotime($s);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

/**
 * مفتاح تجميع التشغيلة (يتجاهل الأصفار البادئة للمقارنة).
 */
function oracle_order_batch_norm_key(string $batch): string
{
    $batch = trim($batch);
    if ($batch === '' || $batch === '0' || oracle_order_batch_looks_like_date($batch)) {
        return '';
    }
    $stripped = ltrim($batch, '0');

    return $stripped !== '' ? $stripped : '0';
}

/**
 * يستخرج تاريخاً من رقم التشغيلة إن أمكن (مثل 0261115 أو 250101).
 */
function oracle_order_batch_date_guess(string $batch): ?string
{
    $digits = preg_replace('/\D+/', '', $batch) ?? '';
    if (strlen($digits) < 6) {
        return null;
    }
    // خذ آخر 6 أرقام كـ YYMMDD
    $tail = substr($digits, -6);
    $yy = (int) substr($tail, 0, 2);
    $mm = (int) substr($tail, 2, 2);
    $dd = (int) substr($tail, 4, 2);
    if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) {
        return null;
    }
    $year = $yy >= 70 ? (1900 + $yy) : (2000 + $yy);
    if (!checkdate($mm, $dd, $year)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
}

/**
 * ترتيب التشغيلات: الأقدم أولاً (تاريخ الصلاحية/التشغيلة ثم رقم التشغيلة).
 *
 * @param list<array{batch:string,qty:float,exp_date?:string,sort_date?:string}> $rows
 * @return list<array{batch:string,qty:float,exp_date?:string,sort_date?:string}>
 */
function oracle_order_sort_batches_oldest_first(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $da = (string) ($a['sort_date'] ?? $a['exp_date'] ?? '');
        $db = (string) ($b['sort_date'] ?? $b['exp_date'] ?? '');
        $aHas = $da !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $da);
        $bHas = $db !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $db);
        if ($aHas && $bHas) {
            $cmp = strcmp(substr($da, 0, 10), substr($db, 0, 10));
            if ($cmp !== 0) {
                return $cmp;
            }
        } elseif ($aHas !== $bHas) {
            return $aHas ? -1 : 1;
        }
        $ba = (string) ($a['batch'] ?? '');
        $bb = (string) ($b['batch'] ?? '');
        $cmpB = strcmp($ba, $bb);
        if ($cmpB !== 0) {
            return $cmpB;
        }

        return ((float) ($a['qty'] ?? 0) <=> (float) ($b['qty'] ?? 0));
    });

    return $rows;
}

/**
 * أعمدة الكمية في جدول STOCK (مكتشفة من Oracle).
 *
 * @return list<string>
 */
function oracle_order_stock_qty_columns(array $conn, string $owner, string $table): array
{
    static $cache = [];
    $key = strtoupper($owner) . '.' . strtoupper($table);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $sc = oracle_order_stock_cfg();
    $candidates = [
        strtoupper(trim((string) ($sc['qty_col'] ?? 'SYS_QTY'))),
        'SYS_QTY', 'MAN_QTY', 'AV_QTY', 'AVAIL_QTY', 'QTY', 'BAL_QTY', 'BALANCE',
        'CUR_QTY', 'R_QTY', 'STK_QTY', 'PHY_QTY', 'ONHAND', 'ON_HAND', 'STOCK_QTY',
    ];
    /** @var array<string,bool> $have */
    $have = [];
    try {
        foreach (oracle_describe_table($conn, $owner, $table) as $meta) {
            $n = strtoupper(trim((string) ($meta['column_name'] ?? '')));
            if ($n !== '') {
                $have[$n] = true;
            }
        }
    } catch (Throwable $e) {
        $have = ['SYS_QTY' => true, 'MAN_QTY' => true];
    }
    $out = [];
    foreach ($candidates as $c) {
        $c = strtoupper(trim($c));
        if ($c !== '' && isset($have[$c]) && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    foreach (array_keys($have) as $n) {
        if (preg_match('/(QTY|QNT|BAL|ONHAND|AVAIL)/', $n)
            && !preg_match('/(COMP_NUM|ITEM_NUM|STORE_NUM|CAT_NUM|_NO$|_ID$|DATE|TIME|FLAG|CODE|NAME|DESC)/', $n)
            && !in_array($n, $out, true)) {
            $out[] = $n;
        }
    }
    if ($out === []) {
        $out = ['SYS_QTY', 'MAN_QTY'];
    }
    $cache[$key] = $out;

    return $out;
}

/**
 * تعبير كمية STOCK في SQL — GREATEST لكل أعمدة كمية موجود.
 */
function oracle_order_stock_qty_expr(array $conn, string $owner, string $table): string
{
    $cols = oracle_order_stock_qty_columns($conn, $owner, $table);
    $sc = oracle_order_stock_cfg();
    if (!$sc['use_man_qty']) {
        $primary = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) ($sc['qty_col'] ?? 'SYS_QTY'))) ?: 'SYS_QTY';
        if (in_array($primary, $cols, true)) {
            return 'NVL(' . $primary . ', 0)';
        }
    }
    if (count($cols) === 1) {
        return 'NVL(' . $cols[0] . ', 0)';
    }
    $parts = [];
    foreach ($cols as $c) {
        $parts[] = 'NVL(' . $c . ', 0)';
    }

    return 'GREATEST(' . implode(', ', $parts) . ')';
}

/**
 * مطابقة صف STOCK — الفئة الفارغة في Oracle لا تستبعد الصف.
 */
function oracle_order_stock_keys_match(string $rowItem, string $rowCat, string $item, string $cat): bool
{
    if (!oracle_order_oracle_keys_match($rowItem, '', $item, '')) {
        return false;
    }
    if ($cat === '' || trim($rowCat) === '') {
        return true;
    }

    return oracle_order_oracle_keys_match($rowItem, $rowCat, $item, $cat);
}

/**
 * وصف اتصال Oracle الحالي (للمقارنة مع Toad).
 */
function oracle_order_oracle_conn_label(): string
{
    $c = oracle_config();
    $host = trim((string) ($c['host'] ?? ''));
    $port = (int) ($c['port'] ?? 1521);
    $sid = trim((string) ($c['sid'] ?? ''));
    $svc = trim((string) ($c['service_name'] ?? ''));
    $db = $sid !== '' ? $sid : ($svc !== '' ? $svc : '?');
    $user = trim((string) ($c['user'] ?? '?'));

    return $host . ':' . $port . '/' . strtoupper($db) . ' (' . $user . ')';
}

/**
 * نفس استعلام Toad حرفياً — CAT + ITEM + STORE + كمية موجبة.
 *
 * @return array{ok:bool,rows:list<array{batch:string,qty:float,exp_date:string,sort_date:string}>,total:float,raw_count:int,positive_batches:int,qty_cols:list<string>,other_stores:list<array{store:int,qty:float}>,cat_used:string,source:string}|null
 */
function oracle_order_stock_toad_exact_batches(
    array $conn,
    int $store,
    string $item,
    string $cat,
    string $owner,
    string $table
): ?array {
    $item = trim($item);
    $cat = trim($cat);
    if ($item === '' || $store < 1) {
        return null;
    }
    $from = oracle_order_quoted($owner, $table);
    $posFilter = '(NVL(SYS_QTY, 0) > 0.0000001 OR NVL(MAN_QTY, 0) > 0.0000001)';
    $select = 'SELECT TRIM(TO_CHAR(BATCH)) AS BATCH, NVL(SYS_QTY, 0) AS SYS_QTY, NVL(MAN_QTY, 0) AS MAN_QTY, EXP_DATE'
        . ' FROM ' . $from . ' WHERE ';

    /** @var list<array{sql:string,binds:array<string,mixed>}> $attempts */
    $attempts = [];
    if ($cat !== '' && is_numeric($cat)) {
        $attempts[] = [
            'sql' => $select . 'CAT = :cat AND TRIM(TO_CHAR(ITEM)) = :item AND STORE = :store AND ' . $posFilter,
            'binds' => ['cat' => (int) $cat, 'item' => $item, 'store' => $store],
        ];
        $attempts[] = [
            'sql' => $select . 'TRIM(TO_CHAR(CAT)) = :cat_txt AND TRIM(TO_CHAR(ITEM)) = :item AND STORE = :store AND ' . $posFilter,
            'binds' => ['cat_txt' => $cat, 'item' => $item, 'store' => $store],
        ];
    }
    $attempts[] = [
        'sql' => $select . 'TRIM(TO_CHAR(ITEM)) = :item AND STORE = :store AND ' . $posFilter,
        'binds' => ['item' => $item, 'store' => $store],
    ];
    $attempts[] = [
        'sql' => $select . 'TRIM(TO_CHAR(ITEM)) = :item'
            . ' AND TO_NUMBER(REGEXP_REPLACE(TO_CHAR(STORE), \'[^0-9]\', \'\')) = :store AND ' . $posFilter,
        'binds' => ['item' => $item, 'store' => $store],
    ];

    foreach ($attempts as $attempt) {
        try {
            $raw = oracle_query_all($conn, $attempt['sql'], $attempt['binds']);
        } catch (Throwable $e) {
            continue;
        }
        if (!is_array($raw) || $raw === []) {
            continue;
        }
        $rows = [];
        $total = 0.0;
        foreach ($raw as $r) {
            if (!is_array($r)) {
                continue;
            }
            $b = trim(oracle_statement_row_val($r, 'BATCH'));
            if ($b === '' || oracle_order_batch_looks_like_date($b)) {
                continue;
            }
            $sq = oracle_order_parse_qty($r['SYS_QTY'] ?? oracle_statement_row_val($r, 'SYS_QTY'));
            $mq = oracle_order_parse_qty($r['MAN_QTY'] ?? oracle_statement_row_val($r, 'MAN_QTY'));
            $q = max($sq, $mq);
            if ($q <= 0.0000001) {
                continue;
            }
            $expDate = oracle_order_parse_stock_date(oracle_statement_row_val($r, 'EXP_DATE')) ?? '';
            $guess = oracle_order_batch_date_guess($b);
            $rows[] = [
                'batch' => $b,
                'qty' => $q,
                'exp_date' => $expDate,
                'sort_date' => $expDate !== '' ? $expDate : (string) ($guess ?? ''),
            ];
            $total += $q;
        }
        if ($total > 0.0000001) {
            $rows = oracle_order_sort_batches_oldest_first($rows);

            return [
                'ok' => true,
                'rows' => $rows,
                'total' => $total,
                'raw_count' => count($raw),
                'positive_batches' => count($rows),
                'qty_cols' => ['SYS_QTY', 'MAN_QTY'],
                'other_stores' => [],
                'cat_used' => $cat,
                'source' => 'toad-exact',
            ];
        }
    }

    return null;
}

/**
 * @return array{ok:bool,rows:list<array{batch:string,qty:float,exp_date:string,sort_date:string}>,total:float,raw_count:int,positive_batches:int,qty_cols:list<string>,other_stores:list<array{store:int,qty:float}>,cat_used:string,source:string}|null
 */
function oracle_order_stock_sql_positive_batches(
    array $conn,
    int $store,
    string $item,
    string $cat,
    string $owner,
    string $table
): ?array {
    $item = trim($item);
    $cat = trim($cat);
    if ($item === '' || $store < 1) {
        return null;
    }
    $from = oracle_order_quoted($owner, $table);
    $qtyExpr = oracle_order_stock_qty_expr($conn, $owner, $table);
    $qtyCols = oracle_order_stock_qty_columns($conn, $owner, $table);
    $itemWhere = '(TRIM(TO_CHAR(ITEM)) = TRIM(:item)'
        . ' OR LTRIM(TRIM(TO_CHAR(ITEM)), \'0\') = LTRIM(TRIM(:item), \'0\'))';
    $baseBinds = ['item' => $item, 'store' => $store, 'store_txt' => (string) $store];
    $posFilter = '(NVL(SYS_QTY, 0) > 0.0000001 OR NVL(MAN_QTY, 0) > 0.0000001)';

    $storeWheres = [
        'TO_NUMBER(REGEXP_REPLACE(TO_CHAR(STORE), \'[^0-9]\', \'\')) = :store',
        'TRIM(TO_CHAR(STORE)) = TRIM(:store_txt)',
        'STORE = :store',
        'LTRIM(TRIM(TO_CHAR(STORE)), \'0\') = LTRIM(TRIM(:store_txt), \'0\')',
    ];
    $catWheres = [''];
    if ($cat !== '') {
        $baseBinds['cat'] = $cat;
        $catWheres[] = ' AND (TRIM(TO_CHAR(CAT)) = TRIM(:cat)'
            . ' OR LTRIM(TRIM(TO_CHAR(CAT)), \'0\') = LTRIM(TRIM(:cat), \'0\')'
            . ' OR CAT IS NULL)';
    }

    $select = 'SELECT TRIM(TO_CHAR(BATCH)) AS BATCH, (' . $qtyExpr . ') AS STOCK_QTY, EXP_DATE,'
        . ' TRIM(TO_CHAR(CAT)) AS CAT_X, TRIM(TO_CHAR(ITEM)) AS ITEM_X,'
        . ' TRIM(TO_CHAR(STORE)) AS STORE_X'
        . ' FROM ' . $from . ' WHERE ';

    foreach ($storeWheres as $storeWhere) {
        foreach ($catWheres as $catWhere) {
            $sql = $select . $storeWhere . ' AND ' . $itemWhere . $catWhere
                . ' AND ' . $posFilter . ' AND ROWNUM <= 500';
            try {
                $raw = oracle_query_all($conn, $sql, $baseBinds);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($raw) || $raw === []) {
                continue;
            }
            /** @var array<string,array{batch:string,qty:float,exp_date:string,sort_date:string}> $byBatch */
            $byBatch = [];
            foreach ($raw as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $rowItem = oracle_statement_row_val($r, 'ITEM_X');
                $rowCat = oracle_statement_row_val($r, 'CAT_X');
                if (!oracle_order_stock_keys_match($rowItem, $rowCat, $item, $cat)) {
                    continue;
                }
                $b = trim(oracle_statement_row_val($r, 'BATCH'));
                if ($b === '' || oracle_order_batch_looks_like_date($b)) {
                    continue;
                }
                $norm = oracle_order_batch_norm_key($b);
                if ($norm === '') {
                    continue;
                }
                $q = oracle_order_parse_qty($r['STOCK_QTY'] ?? oracle_statement_row_val($r, 'STOCK_QTY'));
                if ($q <= 0.0000001) {
                    continue;
                }
                $expDate = oracle_order_parse_stock_date(oracle_statement_row_val($r, 'EXP_DATE')) ?? '';
                $guess = oracle_order_batch_date_guess($b);
                $sortDate = $expDate !== '' ? $expDate : (string) ($guess ?? '');
                if (!isset($byBatch[$norm])) {
                    $byBatch[$norm] = [
                        'batch' => $b,
                        'qty' => 0.0,
                        'exp_date' => $expDate,
                        'sort_date' => $sortDate,
                    ];
                }
                $byBatch[$norm]['qty'] += $q;
            }
            $rows = [];
            $total = 0.0;
            foreach ($byBatch as $info) {
                $q = (float) $info['qty'];
                if ($q <= 0.0000001) {
                    continue;
                }
                $rows[] = [
                    'batch' => (string) $info['batch'],
                    'qty' => $q,
                    'exp_date' => (string) ($info['exp_date'] ?? ''),
                    'sort_date' => (string) ($info['sort_date'] ?? ''),
                ];
                $total += $q;
            }
            if ($total > 0.0000001) {
                $rows = oracle_order_sort_batches_oldest_first($rows);

                return [
                    'ok' => true,
                    'rows' => $rows,
                    'total' => $total,
                    'raw_count' => count($raw),
                    'positive_batches' => count($rows),
                    'qty_cols' => $qtyCols,
                    'other_stores' => [],
                    'cat_used' => $cat,
                    'source' => 'sql-positive',
                ];
            }
        }
    }

    return null;
}

/**
 * تشخيص سريع — أين رصيد المادة في STOCK؟ (قراءة فقط)
 *
 * @return list<string>
 */
function oracle_order_stock_diagnostic_lines(array $conn, string $item, int $store, string $owner, string $table): array
{
    $item = trim($item);
    if ($item === '') {
        return [];
    }
    $from = oracle_order_quoted($owner, $table);
    $qtyExpr = oracle_order_stock_qty_expr($conn, $owner, $table);
    $qtyCols = oracle_order_stock_qty_columns($conn, $owner, $table);
    $lines = [];
    $lines[] = 'اتصال Hypex → Oracle: ' . oracle_order_oracle_conn_label()
        . ' · جدول ' . $owner . '.' . $table;

    try {
        $exact = oracle_order_stock_toad_exact_batches($conn, $store, $item, trim((string) oracle_order_item_cat_resolve($conn, $item, '')), $owner, $table);
        if ($exact !== null && (float) ($exact['total'] ?? 0) > 0.0000001) {
            $lines[] = 'استعلام Toad المطابق: وُجد رصيد ' . (float) $exact['total']
                . ' (تشغيلة ' . (string) (($exact['rows'][0]['batch'] ?? '?')) . ')';
        } else {
            $lines[] = 'استعلام Toad المطابق: لا صف بكمية موجبة (تحقق أن Toad على نفس السيرفر/المستخدم).';
        }
    } catch (Throwable $e) {
        $lines[] = 'استعلام Toad: ' . $e->getMessage();
    }

    $qtySelect = [];
    foreach ($qtyCols as $qc) {
        $qtySelect[] = 'TRIM(TO_CHAR(' . $qc . ')) AS ' . $qc . '_S';
    }
    $qtySelectSql = $qtySelect !== [] ? (', ' . implode(', ', $qtySelect)) : '';

    try {
        $posCnt = oracle_query_all(
            $conn,
            'SELECT COUNT(*) AS CNT FROM ' . $from
            . ' WHERE (TRIM(TO_CHAR(ITEM)) = TRIM(:item)'
            . ' OR LTRIM(TRIM(TO_CHAR(ITEM)), \'0\') = LTRIM(TRIM(:item), \'0\'))'
            . ' AND (' . $qtyExpr . ') > 0.0000001',
            ['item' => $item]
        );
        $cnt = (int) preg_replace('/\D+/', '', oracle_statement_row_val($posCnt[0] ?? [], 'CNT'));
        $lines[] = 'صفوف STOCK بكمية موجبة (أي مستودع): ' . $cnt
            . ' · أعمدة الكمية: ' . implode(', ', $qtyCols);
    } catch (Throwable $e) {
        $lines[] = 'تعذر عدّ صفوف الرصيد الموجب: ' . $e->getMessage();
    }

    $listSql = 'SELECT TRIM(TO_CHAR(BATCH)) AS BATCH, TRIM(TO_CHAR(STORE)) AS STORE_X,'
        . ' TRIM(TO_CHAR(CAT)) AS CAT_X, (' . $qtyExpr . ') AS STOCK_QTY'
        . $qtySelectSql
        . ' FROM ' . $from
        . ' WHERE (TRIM(TO_CHAR(ITEM)) = TRIM(:item)'
        . ' OR LTRIM(TRIM(TO_CHAR(ITEM)), \'0\') = LTRIM(TRIM(:item), \'0\'))'
        . ' AND ROWNUM <= 12';
    try {
        $rows = oracle_query_all($conn, $listSql, ['item' => $item]);
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $extra = [];
            foreach ($qtyCols as $qc) {
                $extra[] = $qc . '=' . oracle_statement_row_val($r, $qc . '_S');
            }
            $lines[] = 'مستودع ' . oracle_statement_row_val($r, 'STORE_X')
                . ' · تشغيلة ' . oracle_statement_row_val($r, 'BATCH')
                . ' · CAT ' . oracle_statement_row_val($r, 'CAT_X')
                . ' · كمية ' . oracle_statement_row_val($r, 'STOCK_QTY')
                . ' (' . implode(' ', $extra) . ')';
        }
    } catch (Throwable $e) {
        $lines[] = 'تشخيص STOCK: ' . $e->getMessage();
    }

    if (count($lines) <= 1) {
        $lines[] = 'لا صفوف STOCK للمادة ' . $item . ' (حسب اتصال Hypex الحالي).';
    }
    if ($store > 0) {
        $lines[] = 'المستودع المطلوب من Hypex: ' . $store;
    }
    $lines[] = 'ملاحظة: إذا كل الكميات = 0 فالرصيد في STOCK فارغ — قائمة التشغيلات في Oracle Forms قد تكون تاريخية وليست رصيداً.';

    return $lines;
}

/**
 * مرشّحو CAT لفحص STOCK (من الطلب، MASCARD، STOCK نفسه).
 *
 * @return list<string>
 */
function oracle_order_stock_cat_candidates(array $conn, int $store, string $item, string $cat): array
{
    $out = [];
    $push = static function (string $c) use (&$out): void {
        $c = trim($c);
        if ($c !== '' && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    };
    $push($cat);
    if ($item !== '') {
        $push(oracle_order_item_cat_resolve($conn, $item, ''));
        $card = oracle_order_mascard_find($conn, $item, '');
        $push(trim((string) ($card['cat'] ?? '')));
    }
    $sc = oracle_order_stock_cfg();
    $from = oracle_order_quoted((string) $sc['owner'], (string) $sc['table']);
    if ($item !== '' && $store > 0) {
        try {
            $rows = oracle_query_all(
                $conn,
                'SELECT DISTINCT TRIM(TO_CHAR(CAT)) AS CAT FROM ' . $from
                . ' WHERE STORE = :store'
                . ' AND (TRIM(TO_CHAR(ITEM)) = TRIM(:item)'
                . ' OR LTRIM(TRIM(TO_CHAR(ITEM)), \'0\') = LTRIM(TRIM(:item), \'0\'))'
                . ' AND (NVL(SYS_QTY, 0) > 0.0000001 OR NVL(MAN_QTY, 0) > 0.0000001)'
                . ' AND ROWNUM <= 20',
                ['store' => $store, 'item' => $item]
            );
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $push(oracle_statement_row_val($r, 'CAT'));
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $out[] = '';

    return $out;
}

/**
 * @return array{ok:bool,rows?:list<array{batch:string,qty:float,exp_date:string,sort_date:string}>,total?:float,message?:string,raw_count?:int,cat_used?:string,source?:string}|null
 */
function oracle_order_stock_toad_positive_rows(
    array $conn,
    int $store,
    string $item,
    string $cat,
    string $owner,
    string $table
): ?array {
    $item = trim($item);
    $cat = trim($cat);
    if ($item === '' || $store < 1) {
        return null;
    }
    $from = oracle_order_quoted($owner, $table);
    $binds = ['store' => $store, 'item' => $item];
    $catSql = '';
    if ($cat !== '') {
        $catSql = ' AND (TRIM(TO_CHAR(CAT)) = TRIM(:cat)'
            . ' OR LTRIM(TRIM(TO_CHAR(CAT)), \'0\') = LTRIM(TRIM(:cat), \'0\'))';
        $binds['cat'] = $cat;
    }
    $raw = [];
    $binds['store_txt'] = (string) $store;
    $sqlBase = 'SELECT TRIM(TO_CHAR(BATCH)) AS BATCH,'
        . ' NVL(SYS_QTY, 0) AS SYS_QTY, NVL(MAN_QTY, 0) AS MAN_QTY,'
        . ' TRIM(TO_CHAR(NVL(SYS_QTY, 0))) AS SYS_QTY_TXT,'
        . ' TRIM(TO_CHAR(NVL(MAN_QTY, 0))) AS MAN_QTY_TXT,'
        . ' GREATEST(NVL(SYS_QTY, 0), NVL(MAN_QTY, 0)) AS STOCK_QTY,'
        . ' EXP_DATE'
        . ' FROM ' . $from
        . ' WHERE (TRIM(TO_CHAR(ITEM)) = TRIM(:item)'
        . ' OR LTRIM(TRIM(TO_CHAR(ITEM)), \'0\') = LTRIM(TRIM(:item), \'0\'))'
        . $catSql
        . ' AND (NVL(SYS_QTY, 0) > 0.0000001 OR NVL(MAN_QTY, 0) > 0.0000001)';
    $storeSqls = [
        ' AND TO_NUMBER(REGEXP_REPLACE(TO_CHAR(STORE), \'[^0-9]\', \'\')) = :store',
        ' AND TRIM(TO_CHAR(STORE)) = TRIM(:store_txt)',
        ' AND STORE = :store',
    ];
    foreach ($storeSqls as $storeSql) {
        try {
            $got = oracle_query_all($conn, $sqlBase . $storeSql, $binds);
            if (is_array($got) && $got !== []) {
                $raw = $got;
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    if ($raw === []) {
        return null;
    }

    /** @var array<string,array{batch:string,qty:float,exp_date:string,sort_date:string}> $byBatch */
    $byBatch = [];
    foreach ($raw as $r) {
        if (!is_array($r)) {
            continue;
        }
        $b = trim(oracle_statement_row_val($r, 'BATCH'));
        if ($b === '' || oracle_order_batch_looks_like_date($b)) {
            continue;
        }
        $norm = oracle_order_batch_norm_key($b);
        if ($norm === '') {
            continue;
        }
        $sq = oracle_order_parse_qty($r['SYS_QTY_TXT'] ?? oracle_statement_row_val($r, 'SYS_QTY_TXT'));
        if ($sq <= 0.0000001) {
            $sq = oracle_order_parse_qty($r['SYS_QTY'] ?? oracle_statement_row_val($r, 'SYS_QTY'));
        }
        $mq = oracle_order_parse_qty($r['MAN_QTY_TXT'] ?? oracle_statement_row_val($r, 'MAN_QTY_TXT'));
        if ($mq <= 0.0000001) {
            $mq = oracle_order_parse_qty($r['MAN_QTY'] ?? oracle_statement_row_val($r, 'MAN_QTY'));
        }
        $q = max($sq, $mq, oracle_order_parse_qty($r['STOCK_QTY'] ?? oracle_statement_row_val($r, 'STOCK_QTY')));
        if ($q <= 0.0000001) {
            continue;
        }
        $expDate = oracle_order_parse_stock_date(oracle_statement_row_val($r, 'EXP_DATE')) ?? '';
        $guess = oracle_order_batch_date_guess($b);
        $sortDate = $expDate !== '' ? $expDate : (string) ($guess ?? '');
        if (!isset($byBatch[$norm])) {
            $byBatch[$norm] = [
                'batch' => $b,
                'qty' => 0.0,
                'exp_date' => $expDate,
                'sort_date' => $sortDate,
            ];
        }
        $byBatch[$norm]['qty'] += $q;
    }

    $rows = [];
    $total = 0.0;
    foreach ($byBatch as $info) {
        $q = (float) $info['qty'];
        if ($q <= 0.0000001) {
            continue;
        }
        $rows[] = [
            'batch' => (string) $info['batch'],
            'qty' => $q,
            'exp_date' => (string) ($info['exp_date'] ?? ''),
            'sort_date' => (string) ($info['sort_date'] ?? ''),
        ];
        $total += $q;
    }
    if ($total <= 0.0000001) {
        return null;
    }
    $rows = oracle_order_sort_batches_oldest_first($rows);

    return [
        'ok' => true,
        'rows' => $rows,
        'total' => $total,
        'raw_count' => count($raw),
        'positive_batches' => count($rows),
        'qty_cols' => ['SYS_QTY', 'MAN_QTY'],
        'other_stores' => [],
        'cat_used' => $cat,
        'source' => 'toad-direct',
    ];
}

/**
 * أرصدة التشغيلات لمادة في مستودع — بدون GROUP BY (أضمن على كل إصدارات Oracle).
 * تُرتَّب التشغيلات من الأقدم إلى الأحدث (FIFO/FEFO حسب تاريخ الصلاحية أو رقم التشغيلة).
 *
 * @return array{ok:bool,rows?:list<array{batch:string,qty:float,exp_date:string,sort_date:string}>,total?:float,message?:string,raw_count?:int,cat_used?:string}
 */
function oracle_order_stock_batches(array $conn, int $compNum, int $store, string $item, string $cat = ''): array
{
    $sc = oracle_order_stock_cfg();
    $item = trim($item);
    $cat = trim($cat);
    if ($item === '' || $store < 1) {
        return ['ok' => false, 'message' => 'مادة أو مستودع غير صالح لفحص الرصيد.'];
    }
    if ($cat === '') {
        $cat = oracle_order_item_cat_resolve($conn, $item, '');
    }

    $owner = (string) $sc['owner'];
    $table = (string) $sc['table'];

    $toadExact = oracle_order_stock_toad_exact_batches($conn, $store, $item, $cat, $owner, $table);
    if ($toadExact !== null && (float) ($toadExact['total'] ?? 0) > 0.0000001) {
        return $toadExact;
    }

    $sqlPositive = oracle_order_stock_sql_positive_batches($conn, $store, $item, $cat, $owner, $table);
    if ($sqlPositive !== null && (float) ($sqlPositive['total'] ?? 0) > 0.0000001) {
        return $sqlPositive;
    }

    foreach (oracle_order_stock_cat_candidates($conn, $store, $item, $cat) as $tryCat) {
        $toadFirst = oracle_order_stock_toad_positive_rows($conn, $store, $item, $tryCat, $owner, $table);
        if ($toadFirst !== null && (float) ($toadFirst['total'] ?? 0) > 0.0000001) {
            if ($tryCat !== '') {
                $toadFirst['cat_used'] = $tryCat;
            }

            return $toadFirst;
        }
    }

    $from = oracle_order_quoted($owner, $table);

    $colNames = [];
    try {
        foreach (oracle_describe_table($conn, $owner, $table) as $meta) {
            $n = strtoupper(trim((string) ($meta['column_name'] ?? '')));
            if ($n !== '') {
                $colNames[$n] = true;
            }
        }
    } catch (Throwable $e) {
        $colNames = [];
    }

    $pickCol = static function (array $cols, array $candidates): string {
        foreach ($candidates as $cand) {
            if (isset($cols[$cand])) {
                return $cand;
            }
        }

        return '';
    };

    $storeCol = $pickCol($colNames, ['STORE', 'STO', 'STO_NO', 'STO_NUM', 'STORE_NO', 'WAREHOUSE', 'WH_NO', 'WH']);
    $itemCol = $pickCol($colNames, ['ITEM', 'ITEM_NO', 'ITEM_CODE', 'ITEMNO', 'ITM']);
    $catCol = $pickCol($colNames, ['CAT', 'CATE', 'CATEGORY', 'CAT_NO', 'CAT_NUM', 'GROUP_NO', 'GRP', 'GROUP_CODE']);
    $batchCol = $pickCol($colNames, ['BATCH', 'BATCH_NO', 'LOT', 'LOT_NO', 'RUN_NO', 'OP_NO']);
    $expCol = $pickCol($colNames, ['EXP_DATE', 'EXPIRE_DATE', 'EXPIRY', 'EX_DATE', 'EXPIRY_DATE', 'END_DATE']);
    $compCol = $pickCol($colNames, ['COMP_NUM', 'COMPANY', 'CO_NO', 'COMP']);

    $qtyCandidates = [
        strtoupper((string) ($sc['qty_col'] ?? 'SYS_QTY')),
        'SYS_QTY', 'MAN_QTY', 'QTY', 'AV_QTY', 'AVAIL_QTY', 'BAL_QTY', 'BALANCE',
        'CUR_QTY', 'R_QTY', 'STK_QTY', 'QTY1', 'QTY2', 'PHY_QTY', 'ONHAND', 'ON_HAND',
        'SYSQTY', 'MANQTY', 'STOCK_QTY', 'QTY_SYS', 'QTY_MAN',
    ];
    $qtyCols = [];
    foreach ($qtyCandidates as $qc) {
        $qc = strtoupper(trim($qc));
        if ($qc !== '' && isset($colNames[$qc]) && !in_array($qc, $qtyCols, true)) {
            $qtyCols[] = $qc;
        }
    }
    if ($storeCol === '') {
        $storeCol = 'STORE';
    }
    if ($itemCol === '') {
        $itemCol = 'ITEM';
    }
    if ($batchCol === '') {
        $batchCol = 'BATCH';
    }
    if ($qtyCols === []) {
        $qtyCols = ['SYS_QTY', 'MAN_QTY', 'QTY'];
    }

    $selectParts = [
        $storeCol . ' AS STORE_X',
        $itemCol . ' AS ITEM_X',
        $batchCol . ' AS BATCH_X',
    ];
    if ($catCol !== '') {
        $selectParts[] = $catCol . ' AS CAT_X';
    }
    if (isset($colNames['SYS_QTY'])) {
        $selectParts[] = 'NVL(SYS_QTY, 0) AS SYS_QTY';
    }
    if (isset($colNames['MAN_QTY'])) {
        $selectParts[] = 'NVL(MAN_QTY, 0) AS MAN_QTY';
    }
    if (isset($colNames['SYS_QTY']) && isset($colNames['MAN_QTY'])) {
        $selectParts[] = 'GREATEST(NVL(SYS_QTY, 0), NVL(MAN_QTY, 0)) AS STOCK_QTY';
    }
    foreach ($qtyCols as $i => $qc) {
        if ($qc === 'SYS_QTY' || $qc === 'MAN_QTY') {
            continue;
        }
        $selectParts[] = 'NVL(' . $qc . ', 0) AS QTY_X' . $i;
    }
    $selectParts[] = $expCol !== '' ? ($expCol . ' AS EXP_X') : 'NULL AS EXP_X';
    $selectList = implode(', ', $selectParts);

    $itemWhere = '(TRIM(TO_CHAR(' . $itemCol . ')) = TRIM(:item)'
        . ' OR LTRIM(TRIM(TO_CHAR(' . $itemCol . ')), \'0\') = LTRIM(TRIM(:item), \'0\'))';
    $catWhere = '';
    if ($cat !== '' && $catCol !== '') {
        $catWhere = ' AND (TRIM(TO_CHAR(' . $catCol . ')) = TRIM(:cat)'
            . ' OR LTRIM(TRIM(TO_CHAR(' . $catCol . ')), \'0\') = LTRIM(TRIM(:cat), \'0\'))';
    }
    $storeWhereNum = 'TO_NUMBER(REGEXP_REPLACE(TO_CHAR(' . $storeCol . '), \'[^0-9]\', \'\')) = :store';
    $storeWhereTxt = 'TRIM(TO_CHAR(' . $storeCol . ')) = TRIM(:store_txt)';

    $attempts = [];
    $pushAttempt = static function (string $where, array $binds) use (&$attempts): void {
        $attempts[] = ['where' => $where, 'binds' => $binds];
    };

    $withCat = static function (array $binds) use ($catWhere): array {
        if ($catWhere === '') {
            return $binds;
        }
        $binds['cat'] = $binds['cat'] ?? '';

        return $binds;
    };

    $directSelect = 'SELECT ' . $batchCol . ' AS BATCH_X, ' . $itemCol . ' AS ITEM_X'
        . ($catCol !== '' ? (', ' . $catCol . ' AS CAT_X') : '')
        . ', NVL(' . ($qtyCols[0] ?? 'SYS_QTY') . ', 0) AS SYS_QTY'
        . ', NVL(' . (in_array('MAN_QTY', $qtyCols, true) ? 'MAN_QTY' : '0') . ', 0) AS MAN_QTY'
        . ', GREATEST(NVL(' . ($qtyCols[0] ?? 'SYS_QTY') . ', 0), NVL('
        . (in_array('MAN_QTY', $qtyCols, true) ? 'MAN_QTY' : '0') . ', 0)) AS STOCK_QTY'
        . ', ' . ($expCol !== '' ? ($expCol . ' AS EXP_X') : 'NULL AS EXP_X')
        . ' FROM ' . $from;

    // أولاً: نفس استعلام Toad — صفوف بكمية موجبة فقط (مثل 0214001 / 132)
    if ($catWhere !== '') {
        $posBinds = $withCat(['item' => $item, 'store' => $store, 'cat' => $cat]);
        $posWhere = $storeWhereNum . ' AND ' . $itemWhere . $catWhere
            . ' AND (NVL(SYS_QTY, 0) > 0.0000001 OR NVL(MAN_QTY, 0) > 0.0000001)';
        $attempts[] = ['where' => $posWhere, 'binds' => $posBinds, 'select' => $directSelect];
        if ($compNum > 0 && $compCol !== '') {
            $posBindsC = $posBinds;
            $posBindsC['cnum'] = $compNum;
            $attempts[] = [
                'where' => $posWhere . ' AND ' . $compCol . ' = :cnum',
                'binds' => $posBindsC,
                'select' => $directSelect,
            ];
        }
    }

    // مع الفئة أولاً (MAS.STOCK يُفتَح بـ CAT+ITEM مثل شاشة INV00024)
    if ($catWhere !== '') {
        if ($compNum > 0 && $compCol !== '') {
            $pushAttempt(
                $storeWhereNum . ' AND ' . $itemWhere . $catWhere . ' AND ' . $compCol . ' = :cnum',
                $withCat(['item' => $item, 'store' => $store, 'cat' => $cat, 'cnum' => $compNum])
            );
        }
        $pushAttempt(
            $storeWhereNum . ' AND ' . $itemWhere . $catWhere,
            $withCat(['item' => $item, 'store' => $store, 'cat' => $cat])
        );
        $pushAttempt(
            $storeWhereTxt . ' AND ' . $itemWhere . $catWhere,
            $withCat(['item' => $item, 'store_txt' => (string) $store, 'cat' => $cat])
        );
    }

    if ($compNum > 0 && $compCol !== '') {
        $pushAttempt(
            $storeWhereNum . ' AND ' . $itemWhere . ' AND ' . $compCol . ' = :cnum',
            ['item' => $item, 'store' => $store, 'cnum' => $compNum]
        );
    }
    $pushAttempt(
        $storeWhereNum . ' AND ' . $itemWhere,
        ['item' => $item, 'store' => $store]
    );
    $pushAttempt(
        $storeWhereTxt . ' AND ' . $itemWhere,
        ['item' => $item, 'store_txt' => (string) $store]
    );

    $raw = [];
    $lastErr = '';
    $seenFp = [];
    $rowFingerprint = static function (array $r) use ($batchCol): string {
        $parts = [];
        foreach ($r as $k => $v) {
            if (is_object($v) && method_exists($v, 'load')) {
                $v = $v->load();
            }
            $parts[] = strtoupper((string) $k) . '=' . trim((string) $v);
        }
        sort($parts);

        return md5(implode('|', $parts));
    };
    foreach ($attempts as $attempt) {
        $selectSql = (string) ($attempt['select'] ?? ('SELECT ' . $selectList . ' FROM ' . $from));
        $sql = $selectSql . ' WHERE ' . $attempt['where'];
        $got = null;
        try {
            $got = oracle_query_all($conn, $sql, $attempt['binds']);
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
            try {
                $sql2 = 'SELECT * FROM ' . $from . ' WHERE ' . $attempt['where'];
                $got = oracle_query_all($conn, $sql2, $attempt['binds']);
            } catch (Throwable $e2) {
                $lastErr = $e2->getMessage();
                continue;
            }
        }
        if (!is_array($got)) {
            continue;
        }
        foreach ($got as $r) {
            if (!is_array($r)) {
                continue;
            }
            $fp = $rowFingerprint($r);
            if (isset($seenFp[$fp])) {
                continue;
            }
            $seenFp[$fp] = true;
            $raw[] = $r;
        }
    }

    // دمج صفوف إضافية: فلترة PHP (بدون COMP_NUM) — يُعثر على تشغيلات مثل 0263278
    $mergeStockRows = static function (array $candidates, bool $requireComp) use (
        &$raw,
        &$seenFp,
        $rowFingerprint,
        $itemCol,
        $catCol,
        $compCol,
        $compNum,
        $item,
        $cat
    ): int {
        $added = 0;
        foreach ($candidates as $r) {
            if (!is_array($r)) {
                continue;
            }
            $ri = trim(oracle_statement_row_val($r, 'ITEM_X'));
            if ($ri === '') {
                $ri = trim(oracle_statement_row_val($r, $itemCol));
            }
            $rc = trim(oracle_statement_row_val($r, 'CAT_X'));
            if ($rc === '' && $catCol !== '') {
                $rc = trim(oracle_statement_row_val($r, $catCol));
            }
            if (!oracle_order_stock_keys_match($ri, $rc, $item, $cat)) {
                continue;
            }
            if ($requireComp && $compNum > 0 && $compCol !== '') {
                $rowComp = (int) preg_replace('/\D+/', '', oracle_statement_row_val($r, $compCol));
                if ($rowComp > 0 && $rowComp !== $compNum) {
                    continue;
                }
            }
            $fp = $rowFingerprint($r);
            if (isset($seenFp[$fp])) {
                continue;
            }
            $seenFp[$fp] = true;
            $raw[] = $r;
            $added++;
        }

        return $added;
    };

    foreach ([
        [$storeWhereNum, ['store' => $store]],
        [$storeWhereTxt, ['store_txt' => (string) $store]],
    ] as [$bWhere, $bBinds]) {
        try {
            $broad = oracle_query_all(
                $conn,
                'SELECT * FROM ' . $from . ' WHERE ' . $bWhere . ' AND ROWNUM <= 8000',
                $bBinds
            );
            if (is_array($broad)) {
                $mergeStockRows($broad, false);
            }
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
    }

    $readQty = static function (array $r, array $qtyCols): float {
        return oracle_order_read_row_qty($r, $qtyCols);
    };

    if ($raw === []) {
        return [
            'ok' => true,
            'rows' => [],
            'total' => 0.0,
            'raw_count' => 0,
            'positive_batches' => 0,
            'message' => 'لا صفوف في ' . $owner . '.' . $table
                . ' للمادة ' . $item . ($cat !== '' ? (' / فئة ' . $cat) : '')
                . ' / مستودع ' . $store
                . ($lastErr !== '' ? (' — ' . $lastErr) : ''),
            'qty_cols' => $qtyCols,
            'other_stores' => [],
            'cat_used' => $cat,
            'broad_scan' => true,
        ];
    }

    $readBatch = static function (array $r, string $batchCol): string {
        foreach (['BATCH_X', $batchCol, 'BATCH', 'LOT', 'LOT_NO'] as $k) {
            if ($k === '') {
                continue;
            }
            $b = trim(oracle_statement_row_val($r, $k));
            if ($b !== '' && !oracle_order_batch_looks_like_date($b)) {
                return $b;
            }
        }

        return '0';
    };

    /** @var array<string,array{batch:string,qty:float,exp_date:string,sort_date:string}> $byBatch */
    $byBatch = [];
    $today = strtotime(date('Y-m-d'));
    $skippedExpired = 0;
    $positiveRaw = 0;

    $accumulate = static function (array $rawRows, bool $skipExpired) use (
        &$byBatch,
        &$skippedExpired,
        &$positiveRaw,
        $readQty,
        $readBatch,
        $qtyCols,
        $batchCol,
        $expCol,
        $today
    ): void {
        foreach ($rawRows as $r) {
            $b = $readBatch($r, $batchCol);
            $norm = oracle_order_batch_norm_key($b);
            if ($norm === '') {
                continue;
            }
            $expRaw = trim(oracle_statement_row_val($r, 'EXP_X'));
            if ($expRaw === '' && $expCol !== '') {
                $expRaw = trim(oracle_statement_row_val($r, $expCol));
            }
            $expDate = oracle_order_parse_stock_date($expRaw) ?? '';
            if ($expDate !== '' && $skipExpired && strtotime($expDate) < $today) {
                $skippedExpired++;
                continue;
            }
            $q = $readQty($r, $qtyCols);
            if ($q <= 0.0000001) {
                continue;
            }
            $positiveRaw++;
            $guess = oracle_order_batch_date_guess($b);
            $sortDate = $expDate !== '' ? $expDate : (string) ($guess ?? '');
            if (!isset($byBatch[$norm])) {
                $byBatch[$norm] = [
                    'batch' => $b,
                    'qty' => 0.0,
                    'exp_date' => $expDate,
                    'sort_date' => $sortDate,
                ];
            }
            $byBatch[$norm]['qty'] += $q;
            if ($expDate !== '' && ($byBatch[$norm]['exp_date'] === '' || $expDate < $byBatch[$norm]['exp_date'])) {
                $byBatch[$norm]['exp_date'] = $expDate;
                $byBatch[$norm]['sort_date'] = $expDate;
            } elseif ($byBatch[$norm]['sort_date'] === '' && $sortDate !== '') {
                $byBatch[$norm]['sort_date'] = $sortDate;
            }
        }
    };

    // لا نستبعد التشغيلات منتهية الصلاحية — شاشة Oracle تعرضها وتبيعها (مثل 28/10/2020)
    $accumulate($raw, false);

    $rows = [];
    $total = 0.0;
    foreach ($byBatch as $info) {
        $q = (float) $info['qty'];
        if ($q <= 0.0000001) {
            continue;
        }
        $rows[] = [
            'batch' => (string) ($info['batch'] ?? ''),
            'qty' => $q,
            'exp_date' => (string) ($info['exp_date'] ?? ''),
            'sort_date' => (string) ($info['sort_date'] ?? ''),
        ];
        $total += $q;
    }

    // إن وُجدت صفوف لكن بلا كمية — ابحث عن صفوف بكمية موجبة في المستودع
    if ($total <= 0.0000001) {
        $qtyFilter = '';
        foreach ($qtyCols as $qc) {
            $qtyFilter .= ($qtyFilter !== '' ? ' OR ' : '')
                . 'NVL(' . $qc . ', 0) > 0.0000001';
        }
        if ($qtyFilter === '') {
            $qtyFilter = 'NVL(SYS_QTY, 0) > 0.0000001 OR NVL(MAN_QTY, 0) > 0.0000001';
        }
        $reMerged = false;
        foreach ([
            [$storeWhereNum, ['store' => $store]],
            [$storeWhereTxt, ['store_txt' => (string) $store]],
        ] as [$bWhere, $bBinds]) {
            try {
                $pos = oracle_query_all(
                    $conn,
                    'SELECT * FROM ' . $from . ' WHERE ' . $bWhere . ' AND (' . $qtyFilter . ') AND ROWNUM <= 3000',
                    $bBinds
                );
                if (is_array($pos) && $mergeStockRows($pos, false) > 0) {
                    $reMerged = true;
                }
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        if ($reMerged) {
            $byBatch = [];
            $positiveRaw = 0;
            $skippedExpired = 0;
            $accumulate($raw, false);
            $rows = [];
            $total = 0.0;
            foreach ($byBatch as $info) {
                $q = (float) $info['qty'];
                if ($q <= 0.0000001) {
                    continue;
                }
                $rows[] = [
                    'batch' => (string) ($info['batch'] ?? ''),
                    'qty' => $q,
                    'exp_date' => (string) ($info['exp_date'] ?? ''),
                    'sort_date' => (string) ($info['sort_date'] ?? ''),
                ];
                $total += $q;
            }
        }
    }

    $rows = oracle_order_sort_batches_oldest_first($rows);

    $otherStores = [];
    if ($total <= 0.0000001) {
        try {
            $otherWhere = $itemWhere . ($catWhere !== '' ? $catWhere : '');
            $otherBinds = ['item' => $item];
            if ($catWhere !== '') {
                $otherBinds['cat'] = $cat;
            }
            $sqlOther = 'SELECT ' . $selectList . ' FROM ' . $from . ' WHERE ' . $otherWhere . ' AND ROWNUM <= 500';
            $otherRaw = oracle_query_all($conn, $sqlOther, $otherBinds);
            $byStore = [];
            foreach ($otherRaw as $r) {
                $stTxt = trim(oracle_statement_row_val($r, 'STORE_X'));
                if ($stTxt === '') {
                    $stTxt = trim(oracle_statement_row_val($r, $storeCol));
                }
                $stNo = (int) preg_replace('/\D+/', '', $stTxt);
                $q = $readQty($r, $qtyCols);
                if ($stNo < 1 || $q <= 0.0000001) {
                    continue;
                }
                $byStore[$stNo] = ($byStore[$stNo] ?? 0.0) + $q;
            }
            arsort($byStore);
            foreach ($byStore as $stNo => $q) {
                $otherStores[] = ['store' => (int) $stNo, 'qty' => (float) $q];
                if (count($otherStores) >= 5) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // تجاهل فشل التشخيص
        }
    }

    return [
        'ok' => true,
        'rows' => $rows,
        'total' => $total,
        'raw_count' => count($raw),
        'positive_batches' => count($rows),
        'qty_cols' => $qtyCols,
        'other_stores' => $otherStores,
        'cat_used' => $cat,
        'debug_sample' => $total <= 0.0000001 && $raw !== []
            ? array_slice(array_map(static function (array $r) use ($readQty, $readBatch, $batchCol, $qtyCols): array {
                return [
                    'batch' => $readBatch($r, $batchCol),
                    'qty' => $readQty($r, $qtyCols),
                    'sys' => oracle_statement_row_val($r, 'SYS_QTY'),
                    'man' => oracle_statement_row_val($r, 'MAN_QTY'),
                ];
            }, $raw), 0, 5)
            : [],
    ];
}

function oracle_order_stock_available(array $conn, int $compNum, int $store, string $item, string $batch = '', string $cat = ''): array
{
    $batches = oracle_order_stock_batches($conn, $compNum, $store, $item, $cat);
    if (empty($batches['ok'])) {
        return ['ok' => false, 'qty' => 0.0, 'message' => (string) ($batches['message'] ?? '')];
    }
    $batch = trim($batch);
    if ($batch === '' || $batch === '0') {
        return ['ok' => true, 'qty' => (float) ($batches['total'] ?? 0)];
    }
    foreach ($batches['rows'] ?? [] as $r) {
        $rb = trim((string) ($r['batch'] ?? ''));
        if ($rb === $batch || oracle_order_batch_norm_key($rb) === oracle_order_batch_norm_key($batch)) {
            return ['ok' => true, 'qty' => (float) ($r['qty'] ?? 0)];
        }
    }

    return ['ok' => true, 'qty' => 0.0];
}

/**
 * كميات بنود فواتير مبيعات معلّقة في DAILY (لم تُحفظ نهائياً في Forms) — تُخصم من الرصيد المتاح.
 *
 * @return array{ok:bool,qty:float,message?:string}
 */
function oracle_order_pending_daily_qty(array $conn, int $compNum, int $store, string $item, string $cat = ''): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['sales_invoice'] ?? null) ? $cfg['sales_invoice'] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? 'MAS'))) ?: 'MAS';
    $table = strtoupper(trim((string) ($s['table'] ?? 'DAILY'))) ?: 'DAILY';
    $from = oracle_order_quoted($owner, $table);
    $stype = (int) ($s['sale_type'] ?? 9);
    $item = trim($item);
    $cat = trim($cat);
    if ($item === '' || $store < 1) {
        return ['ok' => true, 'qty' => 0.0];
    }
    $binds = [
        'item' => $item,
        'store' => $store,
        'stype' => $stype,
    ];
    $where = 'TYPE = :stype AND STORE = :store AND TRIM(TO_CHAR(ITEM)) = TRIM(:item)';
    if ($cat !== '') {
        $where .= ' AND (TRIM(TO_CHAR(CAT)) = TRIM(:cat)'
            . ' OR LTRIM(TRIM(TO_CHAR(CAT)), \'0\') = LTRIM(TRIM(:cat), \'0\'))';
        $binds['cat'] = $cat;
    }
    if ($compNum > 0) {
        $where .= ' AND COMP_NUM = :cnum';
        $binds['cnum'] = $compNum;
    }
    // مسودات Hypex: VOU_FLAG=18 أو UPD_FLAG=SS
    $sqls = [
        "SELECT NVL(SUM(QTY + NVL(BONUS, 0)), 0) AS QTY FROM {$from}
         WHERE {$where} AND (VOU_FLAG = 18 OR UPD_FLAG = 'SS')",
        "SELECT NVL(SUM(QTY + NVL(BONUS, 0)), 0) AS QTY FROM {$from}
         WHERE {$where} AND VOU_FLAG = 18",
        "SELECT NVL(SUM(QTY), 0) AS QTY FROM {$from}
         WHERE {$where} AND UPD_FLAG = 'SS'",
    ];
    foreach ($sqls as $sql) {
        try {
            $rows = oracle_query_all($conn, $sql, $binds);

            return [
                'ok' => true,
                'qty' => (float) oracle_statement_row_val($rows[0] ?? [], 'QTY'),
            ];
        } catch (Throwable $e) {
            continue;
        }
    }

    // لا نوقف الترحيل إن لم توجد أعمدة VOU_FLAG — نعتبر المعلّق 0
    return ['ok' => true, 'qty' => 0.0];
}

/**
 * يمنع الترحيل إن لم توجد تشغيلة برصيد ≥ الكمية المطلوبة (نفس شرط INV00024).
 *
 * @param list<array<string,mixed>> $mappedLines
 * @return array{ok:bool,message?:string,issues?:list<array<string,mixed>>,lines?:list<array<string,mixed>>,version?:string}
 */
function oracle_order_check_stock(array $conn, int $compNum, int $store, array $mappedLines): array
{
    $version = 'STOCK-v15-EXACT';
    $sc = oracle_order_stock_cfg();
    if (!$sc['enabled']) {
        return [
            'ok' => false,
            'message' => 'فحص رصيد Oracle معطّل في الإعدادات — لن يتم الترحيل. فعّل sales_invoice.stock.enabled',
            'version' => $version,
        ];
    }
    if ($store < 1) {
        return [
            'ok' => false,
            'message' => 'لا يمكن فحص رصيد Oracle: رقم المستودع غير مضبوط (oracle_store).',
            'version' => $version,
        ];
    }

    $issues = [];
    $outLines = [];
    $fmt = static function (float $n): string {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    };

    foreach ($mappedLines as $ml) {
        $item = trim((string) ($ml['item'] ?? ''));
        $cat = trim((string) ($ml['cat'] ?? ''));
        if ($cat === '' && $item !== '') {
            $cat = oracle_order_item_cat_resolve($conn, $item, $cat);
        }
        $batch = trim((string) ($ml['batch'] ?? ''));
        $qty = (float) ($ml['qty'] ?? 0);
        $bonus = (float) ($ml['bonus'] ?? 0);
        $trUnit = (float) ($ml['tr_unit'] ?? 1);
        if ($trUnit <= 0) {
            $trUnit = 1.0;
        }
        $need = $qty + $bonus;
        // إن كان الرصيد بالقطعة والبيع بالكرتونة: فعّل multiply_by_tr_unit في oracle.local.php
        if (!empty($sc['multiply_by_tr_unit']) && $trUnit > 1.000001) {
            $need *= $trUnit;
        }
        if ($item === '' || $need <= 0.0000001) {
            $outLines[] = $ml;
            continue;
        }

        $batches = oracle_order_stock_batches($conn, $compNum, $store, $item, $cat);
        if (empty($batches['ok'])) {
            return [
                'ok' => false,
                'message' => (string) ($batches['message'] ?? 'تعذر فحص رصيد Oracle.'),
                'version' => $version,
            ];
        }
        $total = (float) ($batches['total'] ?? 0);
        $rows = is_array($batches['rows'] ?? null) ? $batches['rows'] : [];
        $rawCount = (int) ($batches['raw_count'] ?? 0);
        $catUsed = trim((string) ($batches['cat_used'] ?? $cat));

        // اطرح كميات فواتير Oracle غير المحفوظة/المعلقة لنفس المادة والمستودع (VOU_FLAG=18)
        $pending = oracle_order_pending_daily_qty($conn, $compNum, $store, $item, $catUsed);
        if (empty($pending['ok'])) {
            return [
                'ok' => false,
                'message' => (string) ($pending['message'] ?? 'تعذر قراءة الكميات المعلّقة في Oracle.'),
                'version' => $version,
            ];
        }
        $pendingQty = (float) ($pending['qty'] ?? 0);
        if ($pendingQty > 0.0000001) {
            $total = max(0.0, $total - $pendingQty);
            // خصم المعلّق من أحدث التشغيلات أولاً — لترك الأقدم متاحاً للبيع (FIFO)
            $rowsNewest = array_reverse($rows);
            $left = $pendingQty;
            foreach ($rowsNewest as &$rr) {
                if ($left <= 0) {
                    break;
                }
                $take = min((float) $rr['qty'], $left);
                $rr['qty'] = (float) $rr['qty'] - $take;
                $left -= $take;
            }
            unset($rr);
            $rows = array_values(array_filter($rowsNewest, static fn($r) => (float) ($r['qty'] ?? 0) > 0.0000001));
            $rows = oracle_order_sort_batches_oldest_first($rows);
        }

        // لا صفوف / لا رصيد في STOCK
        if ($rawCount < 1 || $total <= 0.0000001) {
            $name = trim((string) ($ml['name'] ?? ''));
            $label = $item . ($name !== '' ? ' — ' . $name : '');
            $hint = '';
            $other = is_array($batches['other_stores'] ?? null) ? $batches['other_stores'] : [];
            if ($other !== []) {
                $bits = [];
                foreach ($other as $os) {
                    $bits[] = 'مستودع ' . (int) ($os['store'] ?? 0) . ': ' . $fmt((float) ($os['qty'] ?? 0));
                }
                $hint = "\nوُجد رصيد للمادة في مستودعات أخرى: " . implode(' · ', $bits)
                    . "\nتحقق من ربط مستودع Hypex برقم Oracle الصحيح (oracle_store).";
            } elseif ($rawCount > 0) {
                $dbg = is_array($batches['debug_sample'] ?? null) ? $batches['debug_sample'] : [];
                $dbgTxt = '';
                if ($dbg !== []) {
                    $bits = [];
                    foreach ($dbg as $d) {
                        $bits[] = 'تشغيلة ' . ($d['batch'] ?? '?')
                            . ' SYS=' . ($d['sys'] ?? '?')
                            . ' MAN=' . ($d['man'] ?? '?')
                            . ' قراءة=' . ($d['qty'] ?? '0');
                    }
                    $dbgTxt = "\nعينة من STOCK: " . implode(' · ', $bits);
                }
                $diagTxt = '';
                $diag = oracle_order_stock_diagnostic_lines(
                    $conn,
                    $item,
                    $store,
                    (string) oracle_order_stock_cfg()['owner'],
                    (string) oracle_order_stock_cfg()['table']
                );
                if ($diag !== []) {
                    $diagTxt = "\nتشخيص STOCK (قراءة فقط):\n" . implode("\n", array_slice($diag, 0, 8));
                }
                $hint = "\nوُجدت " . $rawCount . " صفوف في STOCK لكن الكمية المقروءة = 0."
                    . "\nأعمدة الكمية: " . implode(', ', array_map('strval', (array) ($batches['qty_cols'] ?? [])))
                    . ($catUsed !== '' ? ("\nالفئة المستخدمة: " . $catUsed) : '')
                    . $dbgTxt
                    . $diagTxt
                    . "\n[" . $version . ']';
            } else {
                $hint = "\nلا صفوف في جدول المخزون لهذه المادة/المستودع."
                    . "\nتأكد أن كود المادة في Oracle هو نفسه وأن المستودع = " . $store
                    . ($catUsed !== '' ? (' والفئة = ' . $catUsed) : '')
                    . '.';
            }
            $issues[] = [
                'item' => $item,
                'name' => $name,
                'need' => $need,
                'available' => $total,
                'store' => $store,
                '_line' => $label
                    . "\nالمطلوب: " . $fmt($need)
                    . "\nرصيد Oracle (مستودع " . $store . "): " . $fmt($total)
                    . $hint,
            ];
            $outLines[] = $ml;
            continue;
        }

        // توزيع الكمية على التشغيلات من الأقدم → الأحدث (مثل النظام القديم)
        // وإن لزم تُقسَّم المادة على أكثر من سطر/تشغيلة.
        $needLeft = $need;
        $origQty = (float) ($ml['qty'] ?? 0);
        $origBonus = (float) ($ml['bonus'] ?? 0);
        $allocated = [];
        foreach ($rows as $r) {
            if ($needLeft <= 0.0000001) {
                break;
            }
            $bq = (float) ($r['qty'] ?? 0);
            if ($bq <= 0.0000001) {
                continue;
            }
            $take = min($bq, $needLeft);
            $bName = trim((string) ($r['batch'] ?? ''));
            if ($bName === '') {
                $bName = '0';
            }
            $allocated[] = ['batch' => $bName, 'take' => $take];
            $needLeft -= $take;
        }

        if ($needLeft > 0.0000001 || $allocated === []) {
            $name = trim((string) ($ml['name'] ?? ''));
            $label = $item . ($name !== '' ? ' — ' . $name : '');
            $issues[] = [
                'item' => $item,
                'name' => $name,
                'need' => $need,
                'available' => $total,
                'store' => $store,
                '_line' => $label
                    . "\nالمطلوب: " . $fmt($need)
                    . "\nرصيد Oracle (مستودع " . $store . "): " . $fmt($total)
                    . "\nلا يكفي رصيد التشغيلات المتاحة",
            ];
            $outLines[] = $ml;
            continue;
        }

        $parts = count($allocated);
        $qtyAssigned = 0.0;
        $bonusAssigned = 0.0;
        foreach ($allocated as $idx => $part) {
            $isLast = ($idx === $parts - 1);
            $ratio = $need > 0.0000001 ? ((float) $part['take'] / $need) : 0.0;
            if ($isLast) {
                $partQty = max(0.0, $origQty - $qtyAssigned);
                $partBonus = max(0.0, $origBonus - $bonusAssigned);
            } else {
                $partQty = round($origQty * $ratio, 6);
                $partBonus = round($origBonus * $ratio, 6);
                $qtyAssigned += $partQty;
                $bonusAssigned += $partBonus;
            }
            if ($partQty <= 0.0000001 && $partBonus <= 0.0000001) {
                continue;
            }
            $copy = $ml;
            $copy['batch'] = (string) $part['batch'];
            $copy['qty'] = $partQty;
            $copy['bonus'] = $partBonus;
            // توزيع ضريبة السطر إن وُجدت
            if (isset($ml['vou_tax'])) {
                $copy['vou_tax'] = $isLast
                    ? max(0.0, (float) $ml['vou_tax'] - array_sum(array_map(
                        static fn($x) => (float) ($x['vou_tax'] ?? 0),
                        array_slice($outLines, -$idx)
                    )))
                    : round(((float) $ml['vou_tax']) * $ratio, 6);
            }
            $outLines[] = $copy;
        }
    }

    if ($issues !== []) {
        $lines = array_map(static fn($i) => (string) ($i['_line'] ?? ''), $issues);
        $msg = "تعذر الترحيل إلى Oracle — الكمية المتوفرة أقل من الكمية المباعة:\n\n"
            . implode("\n\n", $lines);

        return [
            'ok' => false,
            'message' => $msg,
            'issues' => $issues,
            'stock_issues' => $issues,
            'version' => $version,
        ];
    }

    return ['ok' => true, 'lines' => $outLines, 'version' => $version];
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
 * اسم المادة للرسائل عند غيابها من بطاقة أوراكل.
 *
 * @param array<string,mixed> $ln
 */
function oracle_order_line_display_name(array $ln): string
{
    $name = trim((string) ($ln['item_name'] ?? $ln['name_ar'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $sku = trim((string) ($ln['item_code'] ?? $ln['sku'] ?? $ln['item_sku'] ?? ''));
    if ($sku !== '') {
        return $sku;
    }

    return 'مادة غير مسماة';
}

/**
 * @param list<string> $names
 * @return array{ok:false,code:string,message:string,error:string,items:list<string>}
 */
function oracle_order_undefined_items_payload(array $names): array
{
    $seen = [];
    $unique = [];
    foreach ($names as $n) {
        $n = trim((string) $n);
        if ($n === '' || isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $unique[] = $n;
    }
    $title = 'المادة غير معرفة على النظام';
    $message = $unique === [] ? $title : $title . "\n" . implode("\n", $unique);

    return [
        'ok' => false,
        'code' => 'item_undefined',
        'message' => $message,
        'error' => $message,
        'items' => $unique,
    ];
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
    $catCol = oracle_order_pick_col($cols, ['CAT', 'CATE', 'CATEGORY', 'CAT_NO', 'CAT_NUM', 'GROUP_NO', 'GRP', 'GROUP_CODE']) ?? '';
    $batchCol = isset($cols['BATCH']) ? 'BATCH' : '';
    $barCols = [];
    foreach (['BARCODE', 'IBARCODE', 'BAR_CODE', 'BCODE', 'ITEM_BARCODE', 'IBAR'] as $c) {
        if (isset($cols[$c])) {
            $barCols[] = $c;
        }
    }

    $row = oracle_order_first_row(
        $conn,
        "SELECT * FROM {$from} WHERE (TRIM(TO_CHAR({$itemCol})) = TRIM(:v)
            OR LTRIM(TRIM(TO_CHAR({$itemCol})), '0') = LTRIM(TRIM(:v), '0')) AND ROWNUM <= 1",
        $item
    );
    if ($row === [] && $barcode !== '' && $barcode !== $item) {
        $row = oracle_order_first_row(
            $conn,
            "SELECT * FROM {$from} WHERE (TRIM(TO_CHAR({$itemCol})) = TRIM(:v)
                OR LTRIM(TRIM(TO_CHAR({$itemCol})), '0') = LTRIM(TRIM(:v), '0')) AND ROWNUM <= 1",
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
 * فئة Oracle للمادة (من بطاقة MASCARD أو آخر حركة DAILY).
 */
function oracle_order_item_cat_resolve(array $conn, string $item, string $cat = ''): string
{
    $cat = trim($cat);
    if ($cat !== '') {
        return $cat;
    }
    $item = trim($item);
    if ($item === '') {
        return '';
    }
    $card = oracle_order_mascard_find($conn, $item, '');
    $fromCard = trim((string) ($card['cat'] ?? ''));
    if ($fromCard !== '') {
        return $fromCard;
    }
    $defaults = oracle_order_item_daily_defaults($conn, $item);

    return trim((string) ($defaults['cat'] ?? ''));
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
