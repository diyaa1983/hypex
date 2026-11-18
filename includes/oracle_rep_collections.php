<?php
declare(strict_types=1);

/**
 * كشف تحصيلات المندوب خلال فترة — Oracle (قراءة فقط)
 * Forms: صف نقدي + صف شيك، الاسم من GLACTMF.ACC_DESC، المندوب من CUS_SALESMAN.
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_rep_net_sales.php');

/**
 * @return array{
 *   owner:string, table:string, cheque_table:string, cust_owner:string, cust_table:string,
 *   credit_side:int, comp_num:int, cash_flags:list<int>, check_flags:list<int>,
 *   rep_key:string, min_voucher_no:int,
 *   gl_owner:string, gl_table:string, gl_acc_num:string, gl_acc_desc:string
 * }
 */
function oracle_rep_collections_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['rep_collections'] ?? null) ? $cfg['rep_collections'] : [];
    $st = is_array($cfg['statement'] ?? null) ? $cfg['statement'] : [];
    $cu = is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [];
    $gl = is_array($cu['name_from_gl'] ?? null) ? $cu['name_from_gl'] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? ($st['owner'] ?? 'ACCINV')))) ?: 'ACCINV';

    // بدون العلم 1: غالباً سندات سلسلة 9xx وليست قبض Forms (24xxx)
    $cash = $s['cash_flags'] ?? [2, 3, 4, 5, 6, 7, 8, 9, 15, 16, 17, 18];
    $check = $s['check_flags'] ?? [10, 11];
    if (!is_array($cash)) {
        $cash = [2, 3, 4, 5, 6, 7, 8, 9, 15, 16, 17, 18];
    }
    if (!is_array($check)) {
        $check = [10, 11];
    }

    $repKey = strtolower(trim((string) ($s['rep_key'] ?? 'cus_salesman')));
    if (!in_array($repKey, ['cus_salesman', 'vod_man', 'both'], true)) {
        $repKey = 'cus_salesman';
    }

    return [
        'owner' => $owner,
        'table' => strtoupper(trim((string) ($s['table'] ?? ($st['table'] ?? 'GLVODMF')))) ?: 'GLVODMF',
        'cheque_table' => strtoupper(trim((string) ($s['cheque_table'] ?? ($st['cheque_table'] ?? 'GLCHEQF')))) ?: 'GLCHEQF',
        'cust_owner' => strtoupper(trim((string) ($s['cust_owner'] ?? ($cu['owner'] ?? 'ACCINV')))) ?: 'ACCINV',
        'cust_table' => strtoupper(trim((string) ($s['cust_table'] ?? ($cu['table'] ?? 'CUSTOMER')))) ?: 'CUSTOMER',
        'credit_side' => (int) ($s['credit_side'] ?? ($st['credit_side'] ?? 2)),
        'comp_num' => (int) ($s['comp_num'] ?? 1),
        'cash_flags' => array_values(array_filter(array_map('intval', $cash), static fn ($n) => $n > 0)),
        'check_flags' => array_values(array_filter(array_map('intval', $check), static fn ($n) => $n > 0)),
        'rep_key' => $repKey,
        // سندات القبض في Forms عندكم ~24xxx — استبعاد سلسلة 9xx
        'min_voucher_no' => (int) ($s['min_voucher_no'] ?? 10000),
        'gl_owner' => strtoupper(trim((string) ($s['gl_owner'] ?? ($gl['owner'] ?? 'ACCINV')))) ?: 'ACCINV',
        'gl_table' => strtoupper(trim((string) ($s['gl_table'] ?? ($gl['table'] ?? 'GLACTMF')))) ?: 'GLACTMF',
        'gl_acc_num' => strtoupper(trim((string) ($s['gl_acc_num'] ?? ($gl['acc_num'] ?? 'ACC_NUM')))) ?: 'ACC_NUM',
        'gl_acc_desc' => strtoupper(trim((string) ($s['gl_acc_desc'] ?? ($gl['acc_desc'] ?? 'ACC_DESC')))) ?: 'ACC_DESC',
    ];
}

/**
 * @param list<string> $have
 * @param list<string> $candidates
 */
function oracle_rep_collections_pick_col(array $have, array $candidates): ?string
{
    return oracle_first_existing_column($have, $candidates);
}

/**
 * @param list<int> $flags
 * @return array{0:list<string>,1:array<string,int>}
 */
function oracle_rep_collections_flag_binds(array $flags, string $prefix): array
{
    $list = [];
    $binds = [];
    foreach (array_values($flags) as $i => $f) {
        $k = $prefix . $i;
        $list[] = ':' . $k;
        $binds[$k] = (int) $f;
    }

    return [$list, $binds];
}

/**
 * أسماء الحسابات من GLACTMF.ACC_DESC (كما في مزامنة العملاء / Forms).
 *
 * @param list<string> $accs
 * @return array<string,string>
 */
function oracle_rep_collections_gl_names(array $conn, array $cfg, array $accs): array
{
    $want = [];
    foreach ($accs as $a) {
        $a = trim((string) $a);
        if ($a !== '') {
            $want[$a] = true;
            $digits = preg_replace('/\D+/', '', $a) ?? '';
            if ($digits !== '') {
                $want[$digits] = true;
            }
        }
    }
    if ($want === []) {
        return [];
    }

    $glOwn = $cfg['gl_owner'];
    $glTbl = $cfg['gl_table'];
    $glNum = $cfg['gl_acc_num'];
    $glDesc = $cfg['gl_acc_desc'];
    $out = [];
    $chunks = array_chunk(array_keys($want), 400);
    foreach ($chunks as $chunk) {
        $quoted = [];
        foreach ($chunk as $a) {
            $quoted[] = "'" . str_replace("'", "''", $a) . "'";
        }
        $list = implode(',', $quoted);
        $sql = 'SELECT TRIM(TO_CHAR(' . oracle_stmt_q($glNum) . ')) AS ACC,
                       TRIM(' . oracle_stmt_q($glDesc) . ') AS N
                FROM ' . oracle_stmt_q($glOwn) . '.' . oracle_stmt_q($glTbl) . '
                WHERE TRIM(TO_CHAR(' . oracle_stmt_q($glNum) . ')) IN (' . $list . ')';
        try {
            foreach (oracle_query_all($conn, $sql, []) as $r) {
                $acc = trim((string) oracle_statement_row_val($r, 'ACC'));
                $name = trim((string) oracle_statement_row_val($r, 'N'));
                if ($acc !== '' && $name !== '') {
                    $out[$acc] = $name;
                }
            }
        } catch (Throwable $e) {
            // يبقى الاسم من CUSTOMER
        }
    }

    return $out;
}

/**
 * @return array{
 *   ok:bool, message:string, rows:list<array<string,mixed>>, totals:array{count:int,amount:float},
 *   filters?:array<string,mixed>
 * }
 */
function oracle_fetch_rep_collections(
    string $dateFrom,
    string $dateTo,
    ?int $repFrom = null,
    ?int $repTo = null,
    ?int $compNum = null
): array {
    $empty = [
        'ok' => false,
        'message' => '',
        'rows' => [],
        'totals' => ['count' => 0, 'amount' => 0.0],
    ];
    if (!oracle_is_enabled()) {
        $empty['message'] = 'تكامل Oracle غير مفعّل.';

        return $empty;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $empty['message'] = 'تاريخ البداية/النهاية غير صالح.';

        return $empty;
    }
    if ($dateFrom > $dateTo) {
        $empty['message'] = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';

        return $empty;
    }

    $cfg = oracle_rep_collections_cfg();
    $conn = oracle_connect();
    if (empty($conn['ok'])) {
        $empty['message'] = (string) ($conn['message'] ?? 'تعذر الاتصال بـ Oracle.');

        return $empty;
    }

    $owner = $cfg['owner'];
    $vodCols = oracle_statement_table_columns($conn, $owner, $cfg['table']);
    $chqCols = oracle_statement_table_columns($conn, $owner, $cfg['cheque_table']);
    $cusCols = oracle_statement_table_columns($conn, $cfg['cust_owner'], $cfg['cust_table']);
    if ($vodCols === []) {
        $empty['message'] = 'تعذر قراءة أعمدة جدول الحركات ' . $owner . '.' . $cfg['table'];

        return $empty;
    }

    $accCol = oracle_rep_collections_pick_col($vodCols, ['VOD_ACC', 'ACC_NUM', 'CUS_NUM', 'VOD_ACCOUNT']) ?: 'VOD_ACC';
    $dateCol = oracle_rep_collections_pick_col($vodCols, ['VOD_DATE', 'TRN_DATE', 'DOC_DATE']) ?: 'VOD_DATE';
    $numCol = oracle_rep_collections_pick_col($vodCols, ['VOD_NUM', 'DOC_NO', 'V_NUM']) ?: 'VOD_NUM';
    $amtCol = oracle_rep_collections_pick_col($vodCols, ['VOD_AMOUNT', 'AMOUNT', 'VOD_AMT']) ?: 'VOD_AMOUNT';
    $sideCol = oracle_rep_collections_pick_col($vodCols, ['VOD_SIDE', 'SIDE']);
    $flagCol = oracle_rep_collections_pick_col($vodCols, ['VOD_FLAG', 'DOC_FLAG', 'FLAG']);
    $compCol = oracle_rep_collections_pick_col($vodCols, ['VOD_COMP', 'COMP_NUM', 'COMPANY', 'VOD_COMPANY']);
    $vodManCol = oracle_rep_collections_pick_col($vodCols, [
        'MAN_NUM', 'VOD_MAN', 'VOD_MAN_NUM', 'SAL_MAN', 'SALESMAN', 'EMP_NO', 'VOD_EMP', 'REP_NO',
    ]);

    $cusNumCol = oracle_rep_collections_pick_col($cusCols, ['CUS_NUM', 'ACC_NUM', 'CUSTOMER_NO']) ?: 'CUS_NUM';
    // العنوان العربي الكامل أولاً — ليس CUS_ANAME المختصر
    $cusNameCol = oracle_rep_collections_pick_col($cusCols, [
        'CUS_TITLE', 'CUS_NAME', 'CUS_ANAME', 'CUS_A_NAME', 'NAME_AR',
    ]) ?: 'CUS_TITLE';
    $cusManCol = oracle_rep_collections_pick_col($cusCols, ['CUS_SALESMAN', 'SALESMAN', 'MAN_NUM', 'SAL_MAN', 'EMP_NO']);

    if ($cfg['rep_key'] === 'vod_man' && $vodManCol === null) {
        $empty['message'] = 'لا يوجد عمود مندوب على الحركة (MAN_NUM).';

        return $empty;
    }
    if ($cfg['rep_key'] !== 'vod_man' && $cusManCol === null && $vodManCol === null) {
        $empty['message'] = 'لم يُعثر على عمود مندوب (CUS_SALESMAN / MAN_NUM).';

        return $empty;
    }

    $chqNumCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_NUM', 'CHEQUE_NO', 'CHECK_NO']);
    $chqAmtCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_AMOUNT', 'AMOUNT', 'CHQ_AMT']);
    $chqCusCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_CUS_NUM', 'CUS_NUM', 'CHQ_ACC']) ?: 'CHQ_CUS_NUM';
    $chqVNumCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_V_NUM', 'VOD_NUM', 'VOUCHER_NO', 'CHQ_VNUM']);
    $chqDueCol = oracle_rep_collections_pick_col($chqCols, [
        'CHQ_DATE', 'CHQ_DDATE', 'CHQ_DUE_DATE', 'CHQ_RIGHT_DATE', 'DUE_DATE',
    ]);
    $chqRecvCol = oracle_rep_collections_pick_col($chqCols, oracle_cheque_receipt_col_candidates());
    $chqDateCol = $chqRecvCol ?: oracle_rep_collections_pick_col($chqCols, ['CHQ_VDATE', 'CHQ_GDATE', 'CHQ_IDATE', 'CHQ_DATE']);
    $hasCheque = $chqCols !== [] && $chqNumCol !== null && $chqAmtCol !== null && $chqVNumCol !== null;

    if ($cfg['rep_key'] === 'vod_man' && $vodManCol !== null) {
        $repExprCash = 'NVL(v.' . oracle_stmt_q($vodManCol) . ', 0)';
        $repExprChq = $cusManCol
            ? 'NVL(v.' . oracle_stmt_q($vodManCol) . ', NVL(c.' . oracle_stmt_q($cusManCol) . ', 0))'
            : $repExprCash;
    } elseif ($cfg['rep_key'] === 'both' && $vodManCol !== null && $cusManCol !== null) {
        $repExprCash = 'NVL(NULLIF(v.' . oracle_stmt_q($vodManCol) . ', 0), NVL(c.' . oracle_stmt_q($cusManCol) . ', 0))';
        $repExprChq = $repExprCash;
    } else {
        $repExprCash = 'NVL(c.' . oracle_stmt_q((string) $cusManCol) . ', 0)';
        $repExprChq = $repExprCash;
    }

    $compExpr = $compCol !== null
        ? 'NVL(v.' . oracle_stmt_q($compCol) . ', 0)'
        : (string) max(1, (int) ($compNum ?? $cfg['comp_num']));

    // اسم العميل: GLACTMF.ACC_DESC ثم CUS_TITLE ثم رقم الحساب
    $glJoin = ' LEFT JOIN ' . oracle_stmt_q($cfg['gl_owner']) . '.' . oracle_stmt_q($cfg['gl_table']) . ' g
                ON TRIM(TO_CHAR(g.' . oracle_stmt_q($cfg['gl_acc_num']) . ')) = TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . '))';
    $nameExpr = 'NVL(NULLIF(TRIM(g.' . oracle_stmt_q($cfg['gl_acc_desc']) . "), ''),"
        . ' NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''),"
        . ' TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . '))))';

    $fromVod = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['table']) . ' v';
    $fromCus = oracle_stmt_q($cfg['cust_owner']) . '.' . oracle_stmt_q($cfg['cust_table']) . ' c';
    $joinCus = ' INNER JOIN ' . $fromCus
        . ' ON TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';

    $rf = $repFrom !== null && $repFrom > 0 ? $repFrom : null;
    $rt = $repTo !== null && $repTo > 0 ? $repTo : $rf;
    $useComp = (int) ($compNum ?? $cfg['comp_num']);
    $minVod = max(0, (int) $cfg['min_voucher_no']);

    $cashFlags = array_values(array_diff($cfg['cash_flags'], $cfg['check_flags']));
    if ($cashFlags === []) {
        $cashFlags = [9, 15, 16];
    }
    $checkFlags = $cfg['check_flags'];

    $raw = [];
    $usedMode = [];

    // ─── 1) قبض نقدي ───
    if ($flagCol !== null && $cashFlags !== []) {
        [$flagList, $flagBinds] = oracle_rep_collections_flag_binds($cashFlags, 'cf');
        $binds = array_merge(['df' => $dateFrom, 'dt' => $dateTo], $flagBinds);
        $where = [
            'v.' . oracle_stmt_q($dateCol) . " >= TO_DATE(:df, 'YYYY-MM-DD')",
            'v.' . oracle_stmt_q($dateCol) . " < TO_DATE(:dt, 'YYYY-MM-DD') + 1",
            'v.' . oracle_stmt_q($flagCol) . ' IN (' . implode(',', $flagList) . ')',
        ];
        if ($minVod > 0) {
            $where[] = 'v.' . oracle_stmt_q($numCol) . ' >= :min_vod';
            $binds['min_vod'] = $minVod;
        }
        if ($sideCol !== null) {
            $where[] = 'v.' . oracle_stmt_q($sideCol) . ' = :cs';
            $binds['cs'] = (int) $cfg['credit_side'];
        }
        if ($rf !== null) {
            $where[] = $repExprCash . ' >= :rep_from';
            $where[] = $repExprCash . ' <= :rep_to';
            $binds['rep_from'] = $rf;
            $binds['rep_to'] = $rt ?? $rf;
        } else {
            $where[] = $repExprCash . ' > 0';
        }
        if ($compCol !== null && $useComp > 0) {
            $where[] = 'NVL(v.' . oracle_stmt_q($compCol) . ', 0) IN (0, :comp_num)';
            $binds['comp_num'] = $useComp;
        }

        $sqlCash = 'SELECT ' . $repExprCash . ' AS REP_NO,
                           ' . $compExpr . ' AS COMP_NUM,
                           v.' . oracle_stmt_q($numCol) . ' AS RCV_NO,
                           \'قبض نقدي\' AS RCV_TYPE,
                           ' . $nameExpr . ' AS CUSTOMER_NAME,
                           TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) AS CUSTOMER_ACC,
                           TRUNC(MIN(v.' . oracle_stmt_q($dateCol) . ')) AS RCV_DATE,
                           NULL AS CHQ_NUM,
                           NULL AS CHQ_DUE,
                           ROUND(SUM(ABS(v.' . oracle_stmt_q($amtCol) . ')), 3) AS AMOUNT
                    FROM ' . $fromVod . $joinCus . $glJoin . '
                    WHERE ' . implode("\n                      AND ", $where) . '
                    GROUP BY ' . $repExprCash . ',
                             ' . $compExpr . ',
                             v.' . oracle_stmt_q($numCol) . ',
                             ' . $nameExpr . ',
                             TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
        try {
            $raw = array_merge($raw, oracle_query_all($conn, $sqlCash, $binds));
            $usedMode[] = 'cash';
        } catch (Throwable $e) {
            // بدون GLACTMF إن فشل الـ JOIN
            try {
                $sqlCash2 = str_replace($glJoin, '', $sqlCash);
                $sqlCash2 = str_replace(
                    $nameExpr,
                    'NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), TRIM(TO_CHAR(v." . oracle_stmt_q($accCol) . ')))',
                    $sqlCash2
                );
                $raw = array_merge($raw, oracle_query_all($conn, $sqlCash2, $binds));
                $usedMode[] = 'cash_no_gl';
            } catch (Throwable $e2) {
                $empty['message'] = 'فشل استعلام القبض النقدي: ' . $e2->getMessage();

                return $empty;
            }
        }
    }

    // ─── 2) قبض شيك بمبلغ الشيك ───
    if ($hasCheque) {
        $fromChq = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['cheque_table']) . ' ch';
        $joinChqCus = ' INNER JOIN ' . $fromCus
            . ' ON TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . '))';
        $joinChqVod = ' LEFT JOIN ' . $fromVod
            . ' ON v.' . oracle_stmt_q($numCol) . ' = ch.' . oracle_stmt_q((string) $chqVNumCol)
            . ' AND TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) = TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . '))';
        if ($sideCol !== null) {
            $joinChqVod .= ' AND v.' . oracle_stmt_q($sideCol) . ' = ' . (int) $cfg['credit_side'];
        }

        $nameExprChq = 'NVL(NULLIF(TRIM(g.' . oracle_stmt_q($cfg['gl_acc_desc']) . "), ''),"
            . ' NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''),"
            . ' TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . '))))';

        $rcvDateExpr = $chqDateCol !== null
            ? 'NVL(TRUNC(ch.' . oracle_stmt_q($chqDateCol) . '), TRUNC(v.' . oracle_stmt_q($dateCol) . '))'
            : 'TRUNC(v.' . oracle_stmt_q($dateCol) . ')';
        $dueExpr = $chqDueCol !== null ? 'TRUNC(ch.' . oracle_stmt_q($chqDueCol) . ')' : 'NULL';

        $binds = ['df' => $dateFrom, 'dt' => $dateTo];
        $where = [
            $rcvDateExpr . " >= TO_DATE(:df, 'YYYY-MM-DD')",
            $rcvDateExpr . " < TO_DATE(:dt, 'YYYY-MM-DD') + 1",
            'ch.' . oracle_stmt_q((string) $chqNumCol) . ' IS NOT NULL',
            'ABS(ch.' . oracle_stmt_q((string) $chqAmtCol) . ') > 0',
        ];
        if ($minVod > 0) {
            $where[] = 'NVL(v.' . oracle_stmt_q($numCol) . ', ch.' . oracle_stmt_q((string) $chqVNumCol) . ') >= :min_vod';
            $binds['min_vod'] = $minVod;
        }
        if ($flagCol !== null && $checkFlags !== []) {
            [$flagList, $flagBinds] = oracle_rep_collections_flag_binds($checkFlags, 'kf');
            $binds = array_merge($binds, $flagBinds);
            $where[] = '(v.' . oracle_stmt_q($flagCol) . ' IS NULL OR v.' . oracle_stmt_q($flagCol)
                . ' IN (' . implode(',', $flagList) . '))';
        }
        if ($rf !== null) {
            $where[] = $repExprChq . ' >= :rep_from';
            $where[] = $repExprChq . ' <= :rep_to';
            $binds['rep_from'] = $rf;
            $binds['rep_to'] = $rt ?? $rf;
        } else {
            $where[] = $repExprChq . ' > 0';
        }
        if ($compCol !== null && $useComp > 0) {
            $where[] = '(v.' . oracle_stmt_q($compCol) . ' IS NULL OR NVL(v.' . oracle_stmt_q($compCol) . ', 0) IN (0, :comp_num))';
            $binds['comp_num'] = $useComp;
        }

        $sqlChq = 'SELECT ' . $repExprChq . ' AS REP_NO,
                          NVL(' . $compExpr . ', ' . (int) max(1, $useComp) . ') AS COMP_NUM,
                          NVL(v.' . oracle_stmt_q($numCol) . ', ch.' . oracle_stmt_q((string) $chqVNumCol) . ') AS RCV_NO,
                          \'قبض شيك\' AS RCV_TYPE,
                          ' . $nameExprChq . ' AS CUSTOMER_NAME,
                          TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . ')) AS CUSTOMER_ACC,
                          ' . $rcvDateExpr . ' AS RCV_DATE,
                          TO_CHAR(ch.' . oracle_stmt_q((string) $chqNumCol) . ') AS CHQ_NUM,
                          ' . $dueExpr . ' AS CHQ_DUE,
                          ROUND(ABS(ch.' . oracle_stmt_q((string) $chqAmtCol) . '), 3) AS AMOUNT
                   FROM ' . $fromChq . $joinChqCus . $joinChqVod . $glJoin . '
                   WHERE ' . implode("\n                     AND ", $where);

        try {
            $raw = array_merge($raw, oracle_query_all($conn, $sqlChq, $binds));
            $usedMode[] = 'cheque';
        } catch (Throwable $e) {
            try {
                $sqlChq2 = str_replace($glJoin, '', $sqlChq);
                $sqlChq2 = str_replace(
                    $nameExprChq,
                    'NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), TRIM(TO_CHAR(ch." . oracle_stmt_q($chqCusCol) . ')))',
                    $sqlChq2
                );
                $raw = array_merge($raw, oracle_query_all($conn, $sqlChq2, $binds));
                $usedMode[] = 'cheque_no_gl';
            } catch (Throwable $e2) {
                $usedMode[] = 'cheque_fail';
            }
        }
    }

    // إكمال الأسماء من GLACTMF إن بقي رقم حساب
    $needNames = [];
    foreach ($raw as $r) {
        $nm = trim((string) oracle_statement_row_val($r, 'CUSTOMER_NAME'));
        $acc = trim((string) oracle_statement_row_val($r, 'CUSTOMER_ACC'));
        if ($acc !== '' && ($nm === '' || $nm === $acc || preg_match('/^\d+$/', $nm) || $nm === 'شركة')) {
            $needNames[] = $acc;
        }
    }
    $glNames = $needNames !== [] ? oracle_rep_collections_gl_names($conn, $cfg, $needNames) : [];

    $repNos = [];
    foreach ($raw as $r) {
        $n = (int) (float) oracle_statement_row_val($r, 'REP_NO');
        if ($n > 0) {
            $repNos[$n] = true;
        }
    }
    $repNames = [];
    if ($repNos !== []) {
        $list = oracle_list_rep_net_sales_reps();
        if (!empty($list['ok']) && is_array($list['rows'] ?? null)) {
            foreach ($list['rows'] as $rr) {
                $id = (int) ($rr['id'] ?? 0);
                if ($id > 0 && isset($repNos[$id])) {
                    $repNames[$id] = (string) ($rr['name_ar'] ?? ('مندوب ' . $id));
                }
            }
        }
    }

    $seen = [];
    $rows = [];
    $totalAmt = 0.0;
    foreach ($raw as $r) {
        $repNo = (int) (float) oracle_statement_row_val($r, 'REP_NO');
        $rcvNo = (int) (float) oracle_statement_row_val($r, 'RCV_NO');
        $rcvType = trim((string) oracle_statement_row_val($r, 'RCV_TYPE')) ?: 'قبض نقدي';
        $acc = trim((string) oracle_statement_row_val($r, 'CUSTOMER_ACC'));
        $chqNo = trim((string) oracle_statement_row_val($r, 'CHQ_NUM'));
        $amt = round((float) oracle_statement_row_val($r, 'AMOUNT'), 3);
        if ($amt == 0.0) {
            continue;
        }
        if ($minVod > 0 && $rcvNo > 0 && $rcvNo < $minVod) {
            continue;
        }
        $custName = trim((string) oracle_statement_row_val($r, 'CUSTOMER_NAME'));
        if ($acc !== '' && isset($glNames[$acc]) && $glNames[$acc] !== '') {
            $custName = $glNames[$acc];
        } elseif ($acc !== '' && ($custName === '' || $custName === $acc || preg_match('/^\d+$/', $custName))) {
            $digits = preg_replace('/\D+/', '', $acc) ?? '';
            if ($digits !== '' && isset($glNames[$digits])) {
                $custName = $glNames[$digits];
            }
        }
        if ($custName === '') {
            $custName = $acc;
        }

        $key = $repNo . '|' . $rcvNo . '|' . $acc . '|' . $rcvType . '|' . $chqNo;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $totalAmt += $amt;
        $rows[] = [
            'rep_no' => $repNo,
            'rep_name' => $repNames[$repNo] ?? ('مندوب ' . $repNo),
            'comp_num' => (int) (float) oracle_statement_row_val($r, 'COMP_NUM') ?: max(1, $useComp),
            'rcv_no' => $rcvNo,
            'rcv_type' => $rcvType,
            'customer_name' => $custName,
            'customer_acc' => $acc,
            'rcv_date' => oracle_statement_normalize_date(oracle_statement_row_val($r, 'RCV_DATE')),
            'chq_no' => $chqNo,
            'chq_due' => oracle_statement_normalize_date(oracle_statement_row_val($r, 'CHQ_DUE')),
            'amount' => $amt,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $c = ($a['rep_no'] <=> $b['rep_no']);
        if ($c) {
            return $c;
        }
        $c = strcmp((string) $a['rcv_date'], (string) $b['rcv_date']);
        if ($c) {
            return $c;
        }
        $c = ($a['rcv_no'] <=> $b['rcv_no']);
        if ($c) {
            return $c;
        }

        return strcmp((string) $a['chq_no'], (string) $b['chq_no']);
    });

    return [
        'ok' => true,
        'message' => $rows === [] ? 'لا توجد تحصيلات في الفترة المحددة.' : '',
        'rows' => $rows,
        'totals' => [
            'count' => count($rows),
            'amount' => round($totalAmt, 3),
        ],
        'filters' => [
            'from' => $dateFrom,
            'to' => $dateTo,
            'rep_from' => $rf,
            'rep_to' => $rt,
            'comp_num' => $useComp,
            'rep_key' => $cfg['rep_key'],
            'cash_flags' => $cashFlags,
            'check_flags' => $checkFlags,
            'min_voucher_no' => $minVod,
            'mode' => $usedMode,
            'cus_name_col' => $cusNameCol,
        ],
    ];
}
