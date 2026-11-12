<?php
declare(strict_types=1);

/**
 * كشف حساب العميل من Oracle — قراءة فقط (ACCINV.GLVODMF + GLCHEQF)
 * نفس أعمدة وتفاصيل تقرير «كشف حساب تفصيلي» في نظام المحاسبة والذمم.
 */

require_once app_path('includes/oracle_pdo.php');

/**
 * @return array{
 *   owner:string, table:string, acc:string, date:string, side:string, amount:string,
 *   num:string, remark:string, type:string, flag:string, srl:string, dep:string,
 *   debit_side:int, credit_side:int, cheque_table:string, cheque_cus:string
 * }
 */
function oracle_statement_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['statement'] ?? null) ? $cfg['statement'] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? 'ACCINV')));

    return [
        'owner' => $owner !== '' ? $owner : 'ACCINV',
        'table' => strtoupper(trim((string) ($s['table'] ?? 'GLVODMF'))) ?: 'GLVODMF',
        'acc' => strtoupper(trim((string) ($s['acc'] ?? 'VOD_ACC'))) ?: 'VOD_ACC',
        'date' => strtoupper(trim((string) ($s['date'] ?? 'VOD_DATE'))) ?: 'VOD_DATE',
        'side' => strtoupper(trim((string) ($s['side'] ?? 'VOD_SIDE'))) ?: 'VOD_SIDE',
        'amount' => strtoupper(trim((string) ($s['amount'] ?? 'VOD_AMOUNT'))) ?: 'VOD_AMOUNT',
        'num' => strtoupper(trim((string) ($s['num'] ?? 'VOD_NUM'))) ?: 'VOD_NUM',
        'remark' => strtoupper(trim((string) ($s['remark'] ?? 'VOD_REMARK'))) ?: 'VOD_REMARK',
        'type' => strtoupper(trim((string) ($s['type'] ?? 'VOD_TYPE'))) ?: 'VOD_TYPE',
        'flag' => strtoupper(trim((string) ($s['flag'] ?? 'VOD_FLAG'))) ?: 'VOD_FLAG',
        'srl' => strtoupper(trim((string) ($s['srl'] ?? 'VOD_SR1'))) ?: 'VOD_SR1',
        'dep' => strtoupper(trim((string) ($s['dep'] ?? 'VOD_DEP'))) ?: 'VOD_DEP',
        'debit_side' => (int) ($s['debit_side'] ?? 1),
        'credit_side' => (int) ($s['credit_side'] ?? 2),
        'cheque_table' => strtoupper(trim((string) ($s['cheque_table'] ?? 'GLCHEQF'))) ?: 'GLCHEQF',
        'cheque_cus' => strtoupper(trim((string) ($s['cheque_cus'] ?? 'CHQ_CUS_NUM'))) ?: 'CHQ_CUS_NUM',
        // عمود تاريخ القبض في GLCHEQF (اختياري — يُكتشف تلقائياً إن تُرك فارغاً)
        'cheque_receipt' => strtoupper(trim((string) ($s['cheque_receipt'] ?? ''))),
    ];
}

function oracle_stmt_q(string $ident): string
{
    return '"' . str_replace('"', '""', strtoupper(trim($ident))) . '"';
}

/** نوع السند كما في الشاشة (من FLAG / TYPE / البيان). */
function oracle_statement_doc_type_label(int $flag, int $type, string $remark): string
{
    // عينات: 14 مبيعات | 13 مردود | 11 قبض شيك
    $map = [
        14 => 'مبيعات بضاعة',
        13 => 'قيد رديات مبيعات',
        11 => 'قبض شيك',
        10 => 'قبض شيك',
        12 => 'قيد عام',
    ];
    if (isset($map[$flag])) {
        return $map[$flag];
    }
    if (str_contains($remark, 'شيك')) {
        return 'قبض شيك';
    }
    if (str_contains($remark, 'مردود') || str_contains($remark, 'رديات')) {
        return 'قيد رديات مبيعات';
    }
    if (str_contains($remark, 'فاتورة') || str_contains($remark, 'مبيعات')) {
        return 'مبيعات بضاعة';
    }
    if (str_contains($remark, 'خصم') || str_contains($remark, 'قيد')) {
        return 'قيد عام';
    }
    $typeMap = [
        4 => 'مبيعات بضاعة',
        3 => 'قيد رديات مبيعات',
        1 => 'قبض شيك',
    ];

    return $typeMap[$type] ?? ($flag > 0 || $type > 0
        ? ('نوع ' . ($flag > 0 ? (string) $flag : (string) $type))
        : '');
}

/** إزالة «من المستودع رقم 4» من بيان الحركة — لا يُعرض في الكشف. */
function oracle_statement_clean_description(string $desc): string
{
    $s = trim($desc);
    if ($s === '') {
        return '';
    }
    $patterns = [
        '/\s*من\s+المستودع\s*(?:رقم\s*)?4\s*/u',
        '/\s*المستودع\s*(?:رقم\s*)?4\s*/u',
        '/\s*مستودع\s*(?:رقم\s*)?4\s*/u',
        '/\s*(?:WH|STORE|WAREHOUSE)\s*(?:NO\.?|NUM(?:BER)?)?\s*0*4\s*/iu',
    ];
    foreach ($patterns as $p) {
        $s = preg_replace($p, ' ', $s) ?? $s;
    }
    $s = preg_replace('/[ \t]{2,}/u', ' ', $s) ?? $s;
    $s = trim($s);
    $s = trim($s, " \t-/·|,");

    return $s;
}

/**
 * @param mixed $v
 */
function oracle_statement_cell_str(mixed $v): string
{
    if (is_object($v) && method_exists($v, 'load')) {
        $v = $v->load();
    }
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d');
    }

    return oracle_statement_to_utf8(trim((string) $v));
}

/**
 * قيود Oracle القديمة: إمّا Windows-1256، أو حروف عربية بشكلها المطبعي،
 * أو نص محفوظ بالاتجاه البصري (الحروف مقلوبة) فلا تتصل في المتصفح.
 */
function oracle_statement_to_utf8(string $s): string
{
    $s = oracle_statement_decode_legacy(trim($s));
    if ($s === '') {
        return '';
    }

    return oracle_statement_fix_arabic_order($s);
}

function oracle_statement_decode_legacy(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (preg_match('//u', $s) !== 1) {
        $asArabic = @iconv('Windows-1256', 'UTF-8//IGNORE', $s);
        if (is_string($asArabic) && $asArabic !== '' && preg_match('/\p{Arabic}/u', $asArabic) === 1) {
            return $asArabic;
        }
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return is_string($clean) ? $clean : '';
    }
    if (preg_match('/\p{Arabic}/u', $s) === 1) {
        return $s;
    }
    if (preg_match('/[\x{00C0}-\x{00FF}]/u', $s) !== 1) {
        return $s;
    }
    $bytes = @iconv('UTF-8', 'Windows-1252//IGNORE', $s);
    if (!is_string($bytes) || $bytes === '') {
        return $s;
    }
    $asArabic = @iconv('Windows-1256', 'UTF-8//IGNORE', $bytes);
    if (is_string($asArabic) && preg_match('/\p{Arabic}/u', $asArabic) === 1) {
        return $asArabic;
    }

    return $s;
}

function oracle_statement_mb_rev(string $s): string
{
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        return $s;
    }

    return implode('', array_reverse($chars));
}

/** أشكال الحروف المطبعية (المعزولة) → الحرف الأساسي حتى تتصل في المتصفح. */
function oracle_statement_nominalize_arabic(string $s): string
{
    static $map = null;
    if ($map === null) {
        $groups = [
            'ء' => ["\u{FE80}"],
            'آ' => ["\u{FE81}", "\u{FE82}"],
            'أ' => ["\u{FE83}", "\u{FE84}"],
            'ؤ' => ["\u{FE85}", "\u{FE86}"],
            'إ' => ["\u{FE87}", "\u{FE88}"],
            'ئ' => ["\u{FE89}", "\u{FE8A}", "\u{FE8B}", "\u{FE8C}"],
            'ا' => ["\u{FE8D}", "\u{FE8E}"],
            'ب' => ["\u{FE8F}", "\u{FE90}", "\u{FE91}", "\u{FE92}"],
            'ة' => ["\u{FE93}", "\u{FE94}"],
            'ت' => ["\u{FE95}", "\u{FE96}", "\u{FE97}", "\u{FE98}"],
            'ث' => ["\u{FE99}", "\u{FE9A}", "\u{FE9B}", "\u{FE9C}"],
            'ج' => ["\u{FE9D}", "\u{FE9E}", "\u{FE9F}", "\u{FEA0}"],
            'ح' => ["\u{FEA1}", "\u{FEA2}", "\u{FEA3}", "\u{FEA4}"],
            'خ' => ["\u{FEA5}", "\u{FEA6}", "\u{FEA7}", "\u{FEA8}"],
            'د' => ["\u{FEA9}", "\u{FEAA}"],
            'ذ' => ["\u{FEAB}", "\u{FEAC}"],
            'ر' => ["\u{FEAD}", "\u{FEAE}"],
            'ز' => ["\u{FEAF}", "\u{FEB0}"],
            'س' => ["\u{FEB1}", "\u{FEB2}", "\u{FEB3}", "\u{FEB4}"],
            'ش' => ["\u{FEB5}", "\u{FEB6}", "\u{FEB7}", "\u{FEB8}"],
            'ص' => ["\u{FEB9}", "\u{FEBA}", "\u{FEBB}", "\u{FEBC}"],
            'ض' => ["\u{FEBD}", "\u{FEBE}", "\u{FEBF}", "\u{FEC0}"],
            'ط' => ["\u{FEC1}", "\u{FEC2}", "\u{FEC3}", "\u{FEC4}"],
            'ظ' => ["\u{FEC5}", "\u{FEC6}", "\u{FEC7}", "\u{FEC8}"],
            'ع' => ["\u{FEC9}", "\u{FECA}", "\u{FECB}", "\u{FECC}"],
            'غ' => ["\u{FECD}", "\u{FECE}", "\u{FECF}", "\u{FED0}"],
            'ف' => ["\u{FED1}", "\u{FED2}", "\u{FED3}", "\u{FED4}"],
            'ق' => ["\u{FED5}", "\u{FED6}", "\u{FED7}", "\u{FED8}"],
            'ك' => ["\u{FED9}", "\u{FEDA}", "\u{FEDB}", "\u{FEDC}"],
            'ل' => ["\u{FEDD}", "\u{FEDE}", "\u{FEDF}", "\u{FEE0}"],
            'م' => ["\u{FEE1}", "\u{FEE2}", "\u{FEE3}", "\u{FEE4}"],
            'ن' => ["\u{FEE5}", "\u{FEE6}", "\u{FEE7}", "\u{FEE8}"],
            'ه' => ["\u{FEE9}", "\u{FEEA}", "\u{FEEB}", "\u{FEEC}"],
            'و' => ["\u{FEED}", "\u{FEEE}"],
            'ى' => ["\u{FEEF}", "\u{FEF0}"],
            'ي' => ["\u{FEF1}", "\u{FEF2}", "\u{FEF3}", "\u{FEF4}"],
            'لا' => ["\u{FEF5}", "\u{FEF6}", "\u{FEFB}", "\u{FEFC}"],
            'لأ' => ["\u{FEF7}", "\u{FEF8}"],
            'لإ' => ["\u{FEF9}", "\u{FEFA}"],
        ];
        $map = [];
        foreach ($groups as $base => $forms) {
            foreach ($forms as $ch) {
                if ($ch !== '') {
                    $map[$ch] = $base;
                }
            }
        }
    }
    if ($map === []) {
        return $s;
    }

    return strtr($s, $map);
}

function oracle_statement_arabic_score(string $s): int
{
    $words = [
        'فاتورة', 'مبيعات', 'مشتريات', 'مردودات', 'مردود', 'بضاعة', 'بتاريخ',
        'المورد', 'العميل', 'خصم', 'شيك', 'سند', 'قبض', 'ضريبة', 'مستودع',
        'تحويل', 'نقدي', 'شراء', 'مرتجع', 'كمبيالة', 'حوالة', 'ايداع', 'إيداع',
    ];
    $score = 0;
    foreach ($words as $w) {
        if (mb_strpos($s, $w) !== false) {
            $score += mb_strlen($w);
        }
    }

    return $score;
}

function oracle_statement_flip_arabic_tokens(string $s, bool $reverseOrder): string
{
    $parts = preg_split('/(\P{Arabic}+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return $s;
    }
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        if (preg_match('/\p{Arabic}/u', $p) === 1) {
            $p = oracle_statement_mb_rev($p);
        }
        $out[] = $p;
    }
    if ($reverseOrder) {
        $out = array_reverse($out);
    }

    return implode('', $out);
}

function oracle_statement_fix_arabic_order(string $s): string
{
    $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s) ?? $s;
    $s = oracle_statement_nominalize_arabic($s);
    if (preg_match('/\p{Arabic}/u', $s) !== 1) {
        return $s;
    }
    $rank = static function (string $text): array {
        preg_match_all(
            '/(?:19|20)\d{2}[\/\-]\d{1,2}[\/\-]\d{1,2}|\d{1,2}[\/\-]\d{1,2}[\/\-](?:19|20)\d{2}/u',
            $text,
            $m
        );

        return [oracle_statement_arabic_score($text), count($m[0] ?? [])];
    };
    $best = $s;
    $bestRank = $rank($s);
    foreach ([
        oracle_statement_flip_arabic_tokens($s, false),
        oracle_statement_flip_arabic_tokens($s, true),
        oracle_statement_mb_rev($s),
    ] as $cand) {
        $candRank = $rank($cand);
        if (
            $candRank[0] > $bestRank[0]
            || ($candRank[0] > 0 && $candRank[0] === $bestRank[0] && $candRank[1] > $bestRank[1])
        ) {
            $best = $cand;
            $bestRank = $candRank;
        }
    }

    return $best;
}

/**
 * @param array<string, mixed> $r
 */
function oracle_statement_row_val(array $r, string $key): string
{
    foreach ([$key, strtoupper($key), strtolower($key)] as $k) {
        if (array_key_exists($k, $r)) {
            return oracle_statement_cell_str($r[$k]);
        }
    }
    $want = strtoupper($key);
    foreach ($r as $k => $v) {
        if (!is_string($k)) {
            continue;
        }
        if (strtoupper($k) === $want) {
            return oracle_statement_cell_str($v);
        }
    }

    return '';
}

/**
 * أسماء أعمدة جدول Oracle (بأحرف كبيرة) — مع تخزين مؤقت.
 *
 * @return list<string>
 */
function oracle_statement_table_columns(array $conn, string $owner, string $table): array
{
    static $cache = [];
    $key = strtoupper($owner) . '.' . strtoupper($table);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $names = [];
    try {
        foreach (oracle_describe_table($conn, $owner, $table) as $c) {
            $n = strtoupper(trim((string) ($c['column_name'] ?? '')));
            if ($n !== '') {
                $names[] = $n;
            }
        }
    } catch (Throwable $e) {
        $names = [];
    }
    $cache[$key] = $names;

    return $names;
}

/**
 * مرشّحو عمود «تاريخ القبض» في جدول الشيكات (بالأولوية).
 *
 * @return list<string>
 */
function oracle_cheque_receipt_col_candidates(): array
{
    return [
        'CHQ_RDATE',
        'CHQ_R_DATE',
        'CHQ_RECDATE',
        'CHQ_REC_DATE',
        'CHQ_RECV_DATE',
        'CHQ_RECEIVE_DATE',
        'CHQ_IN_DATE',
        'CHQ_IDATE',
        'CHQ_GDATE',
        'CHQ_VDATE',
        'CHQ_CDATE',
        'CHQ_BDATE',
    ];
}

/** @param list<string> $have @param list<string> $candidates */
function oracle_first_existing_column(array $have, array $candidates): ?string
{
    $set = array_fill_keys($have, true);
    foreach ($candidates as $c) {
        $c = strtoupper(trim((string) $c));
        if ($c !== '' && isset($set[$c])) {
            return $c;
        }
    }

    return null;
}

/** تطبيع تاريخ Oracle إلى Y-m-d عند الإمكان. */
function oracle_statement_normalize_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})/', $raw, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $raw, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    // 08-AUG-26 / 08-AUG-2026 (+ وقت اختياري)
    if (preg_match('/^(\d{1,2})-([A-Za-z]{3})-(\d{2,4})(?:\s|$)/', $raw, $m)) {
        $ts = strtotime(substr($raw, 0, 20));
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }
    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return $raw;
}

/**
 * @return array{
 *   ok:bool, message:string, account:string, name:string, from:string, to:string,
 *   opening:float, total_debit:float, total_credit:float, balance:float,
 *   lines:list<array<string,mixed>>, cheques:list<array<string,mixed>>, cheque_total:float
 * }
 */
function oracle_fetch_customer_statement(string $accountNo, string $dateFrom, string $dateTo): array
{
    $empty = [
        'ok' => false,
        'message' => '',
        'account' => $accountNo,
        'name' => '',
        'from' => $dateFrom,
        'to' => $dateTo,
        'opening' => 0.0,
        'total_debit' => 0.0,
        'total_credit' => 0.0,
        'balance' => 0.0,
        'lines' => [],
        'cheques' => [],
        'cheque_total' => 0.0,
    ];

    $accountNo = trim($accountNo);
    if ($accountNo === '' || !preg_match('/^\d+$/', $accountNo)) {
        $empty['message'] = 'رقم حساب/عميل غير صالح.';

        return $empty;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $empty['message'] = 'صيغة التاريخ يجب أن تكون YYYY-MM-DD.';

        return $empty;
    }
    if ($dateFrom > $dateTo) {
        $empty['message'] = 'تاريخ البداية بعد تاريخ النهاية.';

        return $empty;
    }
    if (!oracle_is_enabled()) {
        $empty['message'] = oracle_config_status_message();

        return $empty;
    }

    $conn = oracle_connect();
    if (!$conn['ok']) {
        $empty['message'] = (string) $conn['message'];

        return $empty;
    }

    $sc = oracle_statement_cfg();
    $own = oracle_stmt_q($sc['owner']);
    $tbl = oracle_stmt_q($sc['table']);
    $acc = oracle_stmt_q($sc['acc']);
    $dt = oracle_stmt_q($sc['date']);
    $side = oracle_stmt_q($sc['side']);
    $amt = oracle_stmt_q($sc['amount']);
    $num = oracle_stmt_q($sc['num']);
    $rem = oracle_stmt_q($sc['remark']);
    $type = oracle_stmt_q($sc['type']);
    $flag = oracle_stmt_q($sc['flag']);
    $srl = oracle_stmt_q($sc['srl']);
    $dep = oracle_stmt_q($sc['dep']);
    $debitSide = (int) $sc['debit_side'];
    $creditSide = (int) $sc['credit_side'];
    $fromTbl = $own . '.' . $tbl;
    $accNum = $accountNo;

    // اسم الحساب من GLACTMF
    $name = '';
    try {
        $cfg = oracle_config();
        $gl = is_array($cfg['customers']['name_from_gl'] ?? null) ? $cfg['customers']['name_from_gl'] : [];
        $glOwn = strtoupper(trim((string) ($gl['owner'] ?? 'ACCINV'))) ?: 'ACCINV';
        $glTbl = strtoupper(trim((string) ($gl['table'] ?? 'GLACTMF'))) ?: 'GLACTMF';
        $glNum = strtoupper(trim((string) ($gl['acc_num'] ?? 'ACC_NUM'))) ?: 'ACC_NUM';
        $glDesc = strtoupper(trim((string) ($gl['acc_desc'] ?? 'ACC_DESC'))) ?: 'ACC_DESC';
        $nameSql = 'SELECT ' . oracle_stmt_q($glDesc) . ' AS N FROM '
            . oracle_stmt_q($glOwn) . '.' . oracle_stmt_q($glTbl)
            . ' WHERE ' . oracle_stmt_q($glNum) . ' = :acc AND ROWNUM <= 1';
        $nr = oracle_query_all($conn, $nameSql, ['acc' => $accNum]);
        if ($nr !== []) {
            $name = oracle_statement_row_val($nr[0], 'N');
        }
    } catch (Throwable $e) {
        // الاسم اختياري
    }

    // رصيد افتتاحي = صافي الحركات قبل من تاريخ
    $opening = 0.0;
    try {
        $openSql = 'SELECT SUM(CASE WHEN ' . $side . ' = :ds THEN ABS(' . $amt . ')
                                   WHEN ' . $side . ' = :cs THEN -ABS(' . $amt . ')
                                   ELSE 0 END) AS OB
                    FROM ' . $fromTbl . '
                    WHERE ' . $acc . ' = :acc
                      AND ' . $dt . ' < TO_DATE(:df, \'YYYY-MM-DD\')';
        $orows = oracle_query_all($conn, $openSql, [
            'ds' => $debitSide,
            'cs' => $creditSide,
            'acc' => $accNum,
            'df' => $dateFrom,
        ]);
        if ($orows !== []) {
            $opening = (float) oracle_statement_row_val($orows[0], 'OB');
        }
    } catch (Throwable $e) {
        $opening = 0.0;
    }

    $rows = [];
    $sql = 'SELECT ' . $num . ' AS DOC_NO,
                   ' . $dt . ' AS TRN_DATE,
                   ' . $rem . ' AS DESCRIPTION,
                   ' . $side . ' AS SIDE,
                   ' . $amt . ' AS AMOUNT,
                   ' . $type . ' AS DOC_TYPE,
                   ' . $flag . ' AS DOC_FLAG,
                   ' . $srl . ' AS SRL,
                   ' . $dep . ' AS DEP
            FROM ' . $fromTbl . '
            WHERE ' . $acc . ' = :acc
              AND ' . $dt . ' >= TO_DATE(:df, \'YYYY-MM-DD\')
              AND ' . $dt . ' < TO_DATE(:dt, \'YYYY-MM-DD\') + 1
            ORDER BY ' . $dt . ', ' . $num . ', NVL(' . $srl . ', 0)';

    try {
        $rows = oracle_query_all($conn, $sql, [
            'acc' => $accNum,
            'df' => $dateFrom,
            'dt' => $dateTo,
        ]);
    } catch (Throwable $e) {
        try {
            $sql2 = 'SELECT ' . $num . ' AS DOC_NO,
                            ' . $dt . ' AS TRN_DATE,
                            ' . $rem . ' AS DESCRIPTION,
                            ' . $side . ' AS SIDE,
                            ' . $amt . ' AS AMOUNT,
                            ' . $type . ' AS DOC_TYPE,
                            ' . $flag . ' AS DOC_FLAG
                     FROM ' . $fromTbl . '
                     WHERE ' . $acc . ' = :acc
                       AND ' . $dt . ' >= TO_DATE(:df, \'YYYY-MM-DD\')
                       AND ' . $dt . ' < TO_DATE(:dt, \'YYYY-MM-DD\') + 1
                     ORDER BY ' . $dt . ', ' . $num;
            $rows = oracle_query_all($conn, $sql2, [
                'acc' => $accNum,
                'df' => $dateFrom,
                'dt' => $dateTo,
            ]);
        } catch (Throwable $e2) {
            $empty['message'] = 'فشل قراءة الحركات: ' . $e2->getMessage();

            return $empty;
        }
    }

    $lines = [];
    $run = $opening;
    $totalDebit = 0.0;
    $totalCredit = 0.0;

    // سطر رصيد مدوّر (مثل تقرير Forms: مدين/دائن = 0، الرصيد = الافتتاحي)
    $lines[] = [
        'doc_no' => '',
        'doc_type' => '',
        'trn_date' => $dateFrom,
        'debit' => 0.0,
        'credit' => 0.0,
        'balance' => $opening,
        'description' => 'رصيد مدور',
        'is_opening' => true,
    ];

    foreach ($rows as $r) {
        $sideV = (int) (float) oracle_statement_row_val($r, 'SIDE');
        $signed = 0.0;
        foreach (['AMOUNT', 'Amount', 'amount'] as $ak) {
            if (array_key_exists($ak, $r)) {
                $signed = (float) oracle_statement_cell_str($r[$ak]);
                break;
            }
        }
        $amount = abs($signed);

        $debit = 0.0;
        $credit = 0.0;
        if ($sideV === $debitSide) {
            $debit = $amount;
            $run += $amount;
            $totalDebit += $amount;
        } elseif ($sideV === $creditSide) {
            $credit = $amount;
            $run -= $amount;
            $totalCredit += $amount;
        } elseif ($signed >= 0) {
            $debit = $amount;
            $run += $debit;
            $totalDebit += $debit;
        } else {
            $credit = $amount;
            $run -= $credit;
            $totalCredit += $credit;
        }

        $remark = oracle_statement_row_val($r, 'DESCRIPTION');
        $flagV = (int) (float) oracle_statement_row_val($r, 'DOC_FLAG');
        $typeV = (int) (float) oracle_statement_row_val($r, 'DOC_TYPE');
        $docNo = oracle_statement_row_val($r, 'DOC_NO');
        $trnDate = oracle_statement_normalize_date(oracle_statement_row_val($r, 'TRN_DATE'));

        $lines[] = [
            'doc_no' => $docNo,
            'doc_type' => oracle_statement_doc_type_label($flagV, $typeV, $remark),
            'trn_date' => $trnDate,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $run,
            'description' => oracle_statement_clean_description($remark),
            'is_opening' => false,
        ];
    }

    // الشيكات قيد التحصيل (GLCHEQF)
    // تاريخ القبض: عمود الشيك إن وُجد، وإلا تاريخ سند القبض من GLVODMF عبر CHQ_V_NUM
    $cheques = [];
    $chequeTotal = 0.0;
    try {
        $cq = oracle_stmt_q($sc['cheque_table']);
        $ccus = oracle_stmt_q($sc['cheque_cus']);
        $chCols = oracle_statement_table_columns($conn, $sc['owner'], $sc['cheque_table']);
        $configuredRecv = (string) ($sc['cheque_receipt'] ?? '');
        $recvCol = null;
        if ($configuredRecv !== '' && ($chCols === [] || in_array($configuredRecv, $chCols, true))) {
            $recvCol = $configuredRecv;
        } else {
            $recvCol = oracle_first_existing_column($chCols, oracle_cheque_receipt_col_candidates());
        }

        $hasVNum = $chCols === [] || in_array('CHQ_V_NUM', $chCols, true);
        $recvExpr = 'NULL';
        if ($recvCol !== null && $hasVNum) {
            $recvExpr = 'NVL(c.' . oracle_stmt_q($recvCol)
                . ', (SELECT MIN(d.' . $dt . ') FROM ' . $fromTbl . ' d WHERE d.' . $num
                . ' = c.' . oracle_stmt_q('CHQ_V_NUM') . '))';
        } elseif ($recvCol !== null) {
            $recvExpr = 'c.' . oracle_stmt_q($recvCol);
        } elseif ($hasVNum) {
            $recvExpr = '(SELECT MIN(d.' . $dt . ') FROM ' . $fromTbl . ' d WHERE d.' . $num
                . ' = c.' . oracle_stmt_q('CHQ_V_NUM') . ')';
        }

        $chqSql = 'SELECT c.' . oracle_stmt_q('CHQ_NUM') . ' AS CHQ_NUM,
                          c.' . oracle_stmt_q('CHQ_DATE') . ' AS CHQ_DATE,
                          c.' . oracle_stmt_q('CHQ_AMOUNT') . ' AS CHQ_AMOUNT,
                          c.' . oracle_stmt_q('CHQ_V_NUM') . ' AS CHQ_V_NUM,
                          c.' . oracle_stmt_q('CHQ_NAME') . ' AS CHQ_NAME,
                          c.' . oracle_stmt_q('CHQ_STATUS') . ' AS CHQ_STATUS,
                          ' . $recvExpr . ' AS RECEIPT_DATE
                   FROM ' . $own . '.' . $cq . ' c
                   WHERE c.' . $ccus . ' = :acc
                   ORDER BY c.' . oracle_stmt_q('CHQ_DATE') . ', c.' . oracle_stmt_q('CHQ_NUM');
        try {
            $chRows = oracle_query_all($conn, $chqSql, ['acc' => $accNum]);
        } catch (Throwable $eCols) {
            // استعلام مبسّط إن فشل تعبير تاريخ القبض أو أعمدة اختيارية
            $chqSql = 'SELECT c.' . oracle_stmt_q('CHQ_NUM') . ' AS CHQ_NUM,
                              c.' . oracle_stmt_q('CHQ_DATE') . ' AS CHQ_DATE,
                              c.' . oracle_stmt_q('CHQ_AMOUNT') . ' AS CHQ_AMOUNT,
                              c.' . oracle_stmt_q('CHQ_V_NUM') . ' AS CHQ_V_NUM,
                              c.' . oracle_stmt_q('CHQ_NAME') . ' AS CHQ_NAME,
                              c.' . oracle_stmt_q('CHQ_STATUS') . ' AS CHQ_STATUS
                       FROM ' . $own . '.' . $cq . ' c
                       WHERE c.' . $ccus . ' = :acc
                       ORDER BY c.' . oracle_stmt_q('CHQ_DATE') . ', c.' . oracle_stmt_q('CHQ_NUM');
            $chRows = oracle_query_all($conn, $chqSql, ['acc' => $accNum]);
        }

        foreach ($chRows as $ch) {
            $val = abs((float) oracle_statement_row_val($ch, 'CHQ_AMOUNT'));
            $status = (int) (float) oracle_statement_row_val($ch, 'CHQ_STATUS');
            // STATUS شائع: 0/1 قيد التحصيل، أرقام أعلى = محصّل/مرتجع
            if ($status > 1) {
                continue;
            }
            $receiptRaw = oracle_statement_row_val($ch, 'RECEIPT_DATE');
            if ($receiptRaw === '' && $recvCol !== null) {
                $receiptRaw = oracle_statement_row_val($ch, $recvCol);
            }
            if ($receiptRaw === '') {
                $receiptRaw = oracle_statement_row_val($ch, 'CHQ_RDATE');
            }
            if ($receiptRaw === '') {
                $receiptRaw = oracle_statement_row_val($ch, 'CHQ_BDATE');
            }
            // إن بقي فارغاً: تاريخ سند القبض من حركات نفس رقم السند (إن وُجدت في نتيجة مبسّطة لاحقاً)
            if ($receiptRaw === '') {
                $vNum = oracle_statement_row_val($ch, 'CHQ_V_NUM');
                if ($vNum !== '') {
                    try {
                        $vd = oracle_query_all(
                            $conn,
                            'SELECT MIN(' . $dt . ') AS RD FROM ' . $fromTbl
                            . ' WHERE ' . $num . ' = :vn',
                            ['vn' => $vNum]
                        );
                        if ($vd) {
                            $receiptRaw = oracle_statement_row_val($vd[0], 'RD');
                        }
                    } catch (Throwable $eV) {
                        // تجاهل
                    }
                }
            }
            $receiptDate = oracle_statement_normalize_date($receiptRaw);
            $cheques[] = [
                'chq_no' => oracle_statement_row_val($ch, 'CHQ_NUM'),
                'chq_date' => oracle_statement_normalize_date(oracle_statement_row_val($ch, 'CHQ_DATE')),
                'amount' => $val,
                'receipt_date' => $receiptDate,
                'receipt_ref' => oracle_statement_row_val($ch, 'CHQ_V_NUM'),
                'name' => oracle_statement_row_val($ch, 'CHQ_NAME'),
                'status' => $status,
            ];
            $chequeTotal += $val;
        }
    } catch (Throwable $e) {
        // الشيكات اختيارية إن فشل الجدول
    }

    return [
        'ok' => true,
        'message' => '',
        'account' => $accountNo,
        'name' => $name,
        'from' => $dateFrom,
        'to' => $dateTo,
        'opening' => $opening,
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
        'balance' => $run,
        'lines' => $lines,
        'cheques' => $cheques,
        'cheque_total' => $chequeTotal,
    ];
}

/**
 * كشف Oracle مختصر لعميل CRM (رصيد + شيكات قيد التحصيل).
 *
 * @return array{
 *   ok:bool,
 *   message:string,
 *   customer_id:int,
 *   account:string,
 *   name:string,
 *   from:string,
 *   to:string,
 *   balance:float,
 *   total_debit:float,
 *   total_credit:float,
 *   opening:float,
 *   cheques:list<array<string,mixed>>,
 *   cheque_total:float,
 *   cheque_count:int
 * }
 */
function oracle_customer_ar_summary(PDO $pdo, int $customerId, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $empty = [
        'ok' => false,
        'message' => '',
        'customer_id' => $customerId,
        'account' => '',
        'name' => '',
        'from' => '',
        'to' => '',
        'balance' => 0.0,
        'total_debit' => 0.0,
        'total_credit' => 0.0,
        'opening' => 0.0,
        'cheques' => [],
        'cheque_total' => 0.0,
        'cheque_count' => 0,
    ];

    if ($customerId < 1) {
        $empty['message'] = 'اختر العميل أولاً.';

        return $empty;
    }

    try {
        if (function_exists('oracle_customer_schema_ensure')) {
            require_once app_path('includes/oracle_customer_sync.php');
            oracle_customer_schema_ensure($pdo);
        }
    } catch (Throwable $e) {
        //
    }

    $st = $pdo->prepare(
        'SELECT id, code, name_ar, oracle_key FROM crm_customer WHERE id = ? LIMIT 1'
    );
    $st->execute([$customerId]);
    $party = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$party) {
        $empty['message'] = 'العميل غير موجود.';

        return $empty;
    }

    $accountNo = trim((string) ($party['oracle_key'] ?? ''));
    if ($accountNo === '') {
        $accountNo = preg_replace('/\D+/', '', (string) ($party['code'] ?? '')) ?? '';
    }
    if ($accountNo === '' || !preg_match('/^\d+$/', $accountNo)) {
        $empty['message'] = 'لا يوجد رقم حساب Oracle لهذا العميل.';
        $empty['name'] = (string) ($party['name_ar'] ?? '');

        return $empty;
    }

    $from = $dateFrom !== null && $dateFrom !== '' ? $dateFrom : '2020-01-01';
    $to = $dateTo !== null && $dateTo !== '' ? $dateTo : date('Y-m-d');
    $stmt = oracle_fetch_customer_statement($accountNo, $from, $to);
    if (!$stmt['ok']) {
        $empty['message'] = (string) ($stmt['message'] ?? 'تعذر جلب الكشف من Oracle.');
        $empty['account'] = $accountNo;
        $empty['name'] = (string) ($party['name_ar'] ?? '');

        return $empty;
    }

    $name = (string) ($stmt['name'] ?? '');
    if ($name === '') {
        $name = (string) ($party['name_ar'] ?? '');
    }
    $cheques = is_array($stmt['cheques'] ?? null) ? $stmt['cheques'] : [];

    require_once app_path('includes/sal_customer_order_statement.php');
    $summary = [
        'ok' => true,
        'message' => '',
        'customer_id' => $customerId,
        'account' => (string) ($stmt['account'] ?? $accountNo),
        'name' => $name,
        'from' => (string) ($stmt['from'] ?? $from),
        'to' => (string) ($stmt['to'] ?? $to),
        'balance' => (float) ($stmt['balance'] ?? 0),
        'total_debit' => (float) ($stmt['total_debit'] ?? 0),
        'total_credit' => (float) ($stmt['total_credit'] ?? 0),
        'opening' => (float) ($stmt['opening'] ?? 0),
        'cheques' => $cheques,
        'cheque_total' => (float) ($stmt['cheque_total'] ?? 0),
        'cheque_count' => count($cheques),
    ];

    return sal_customer_order_statement_merge_summary(
        $pdo,
        $customerId,
        $summary,
        (string) ($stmt['from'] ?? $from),
        (string) ($stmt['to'] ?? $to)
    );
}
