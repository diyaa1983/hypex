<?php
declare(strict_types=1);

/**
 * كشف تحصيلات المندوب خلال فترة — من Oracle (قراءة فقط)
 * المصدر: ACCINV.GLVODMF + GLCHEQF (+ CUSTOMER للمندوب/الاسم)
 * يشبه تقرير Forms: كشف تحصيلات المندوبين — فترة معينة.
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_rep_net_sales.php');

/**
 * @return array{
 *   owner:string, table:string, cheque_table:string, cust_owner:string, cust_table:string,
 *   credit_side:int, comp_num:int, cash_flags:list<int>, check_flags:list<int>
 * }
 */
function oracle_rep_collections_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['rep_collections'] ?? null) ? $cfg['rep_collections'] : [];
    $st = is_array($cfg['statement'] ?? null) ? $cfg['statement'] : [];
    $cu = is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? ($st['owner'] ?? 'ACCINV')))) ?: 'ACCINV';
    $cash = $s['cash_flags'] ?? [1, 2, 3, 4, 5, 6, 7, 8, 9, 15, 16, 17, 18, 19, 20];
    $check = $s['check_flags'] ?? [10, 11];
    if (!is_array($cash)) {
        $cash = [1, 2, 3, 4, 5, 6, 7, 8, 9, 15, 16, 17, 18, 19, 20];
    }
    if (!is_array($check)) {
        $check = [10, 11];
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
 * جلب تحصيلات المندوبين خلال الفترة.
 *
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
    $typeCol = oracle_rep_collections_pick_col($vodCols, ['VOD_TYPE', 'DOC_TYPE', 'TYPE']);
    $remarkCol = oracle_rep_collections_pick_col($vodCols, ['VOD_REMARK', 'REMARK', 'DESCRIPTION', 'VOD_DESC']);
    $compCol = oracle_rep_collections_pick_col($vodCols, ['VOD_COMP', 'COMP_NUM', 'COMPANY', 'VOD_COMPANY']);
    $vodManCol = oracle_rep_collections_pick_col($vodCols, [
        'MAN_NUM', 'VOD_MAN', 'VOD_MAN_NUM', 'SAL_MAN', 'SALESMAN', 'EMP_NO', 'VOD_EMP', 'REP_NO',
    ]);

    $cusNumCol = oracle_rep_collections_pick_col($cusCols, ['CUS_NUM', 'ACC_NUM', 'CUSTOMER_NO']) ?: 'CUS_NUM';
    $cusNameCol = oracle_rep_collections_pick_col($cusCols, ['CUS_TITLE', 'CUS_NAME', 'NAME_AR', 'ACC_DESC']) ?: 'CUS_TITLE';
    $cusManCol = oracle_rep_collections_pick_col($cusCols, ['CUS_SALESMAN', 'SALESMAN', 'MAN_NUM', 'SAL_MAN', 'EMP_NO']);

    if ($vodManCol === null && $cusManCol === null) {
        $empty['message'] = 'لم يُعثر على عمود مندوب في الحركات أو بطاقة العميل (MAN_NUM / CUS_SALESMAN).';

        return $empty;
    }

    $chqNumCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_NUM', 'CHEQUE_NO', 'CHECK_NO']);
    $chqAmtCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_AMOUNT', 'AMOUNT', 'CHQ_AMT']);
    $chqCusCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_CUS_NUM', 'CUS_NUM', 'CHQ_ACC']) ?: 'CHQ_CUS_NUM';
    $chqVNumCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_V_NUM', 'VOD_NUM', 'VOUCHER_NO', 'CHQ_VNUM']);
    $chqDueCol = oracle_rep_collections_pick_col($chqCols, [
        'CHQ_DATE', 'CHQ_DDATE', 'CHQ_DUE_DATE', 'CHQ_RIGHT_DATE', 'CHQ_RDATE', 'DUE_DATE',
    ]);
    $hasCheque = $chqCols !== [] && $chqNumCol !== null && $chqVNumCol !== null;

    $repExpr = $vodManCol !== null
        ? 'NVL(v.' . oracle_stmt_q($vodManCol) . ', 0)'
        : 'NVL(c.' . oracle_stmt_q((string) $cusManCol) . ', 0)';
    if ($vodManCol !== null && $cusManCol !== null) {
        $repExpr = 'NVL(NULLIF(v.' . oracle_stmt_q($vodManCol) . ', 0), NVL(c.' . oracle_stmt_q($cusManCol) . ', 0))';
    }

    $compExpr = $compCol !== null
        ? 'NVL(v.' . oracle_stmt_q($compCol) . ', 0)'
        : (string) (int) ($compNum ?? $cfg['comp_num']);

    $fromVod = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['table']) . ' v';
    $fromCus = oracle_stmt_q($cfg['cust_owner']) . '.' . oracle_stmt_q($cfg['cust_table']) . ' c';
    $joinCus = ' INNER JOIN ' . $fromCus
        . ' ON TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';

    $chqSelectNum = $hasCheque ? 'ch.' . oracle_stmt_q((string) $chqNumCol) : 'NULL';
    $chqSelectDue = $hasCheque && $chqDueCol !== null ? 'ch.' . oracle_stmt_q($chqDueCol) : 'NULL';
    $chqJoin = '';
    if ($hasCheque) {
        $fromChq = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['cheque_table']) . ' ch';
        $chqJoin = ' LEFT JOIN ' . $fromChq
            . ' ON ch.' . oracle_stmt_q((string) $chqVNumCol) . ' = v.' . oracle_stmt_q($numCol)
            . ' AND TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . ')) = TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
    }

    $cashFlags = $cfg['cash_flags'];
    $checkFlags = $cfg['check_flags'];
    $allFlags = array_values(array_unique(array_merge($cashFlags, $checkFlags)));
    $flagBinds = [];
    $flagList = [];
    foreach ($allFlags as $i => $f) {
        $k = 'f' . $i;
        $flagList[] = ':' . $k;
        $flagBinds[$k] = (int) $f;
    }

    $where = [];
    $binds = [
        'df' => $dateFrom,
        'dt' => $dateTo,
    ];
    $where[] = 'v.' . oracle_stmt_q($dateCol) . " >= TO_DATE(:df, 'YYYY-MM-DD')";
    $where[] = 'v.' . oracle_stmt_q($dateCol) . " < TO_DATE(:dt, 'YYYY-MM-DD') + 1";

    if ($sideCol !== null) {
        $where[] = 'v.' . oracle_stmt_q($sideCol) . ' = :cs';
        $binds['cs'] = (int) $cfg['credit_side'];
    }

    $flagParts = [];
    if ($flagCol !== null && $flagList !== []) {
        $flagParts[] = 'v.' . oracle_stmt_q($flagCol) . ' IN (' . implode(',', $flagList) . ')';
        $binds = array_merge($binds, $flagBinds);
    }
    if ($hasCheque) {
        $flagParts[] = 'ch.' . oracle_stmt_q((string) $chqNumCol) . ' IS NOT NULL';
    }
    if ($remarkCol !== null) {
        $flagParts[] = 'v.' . oracle_stmt_q($remarkCol) . " LIKE '%قبض%'";
    }
    if ($flagParts !== []) {
        $where[] = '(' . implode(' OR ', $flagParts) . ')';
    }

    // استبعاد مبيعات/مرتجعات معروفة إن وُجد عمود FLAG
    if ($flagCol !== null) {
        $where[] = 'v.' . oracle_stmt_q($flagCol) . ' NOT IN (13, 14)';
    }

    $rf = $repFrom !== null && $repFrom > 0 ? $repFrom : null;
    $rt = $repTo !== null && $repTo > 0 ? $repTo : $rf;
    if ($rf !== null) {
        $where[] = $repExpr . ' >= :rep_from';
        $where[] = $repExpr . ' <= :rep_to';
        $binds['rep_from'] = $rf;
        $binds['rep_to'] = $rt ?? $rf;
    } else {
        $where[] = $repExpr . ' > 0';
    }

    $useComp = (int) ($compNum ?? $cfg['comp_num']);
    if ($compCol !== null && $useComp > 0) {
        $where[] = 'NVL(v.' . oracle_stmt_q($compCol) . ', 0) IN (0, :comp_num)';
        $binds['comp_num'] = $useComp;
    }

    $typeLabelSql = "'قبض نقدي'";
    if ($hasCheque || $flagCol !== null) {
        $checkIn = [];
        foreach ($checkFlags as $i => $f) {
            $k = 'cf' . $i;
            $checkIn[] = ':' . $k;
            $binds[$k] = (int) $f;
        }
        $conds = [];
        if ($hasCheque) {
            $conds[] = 'ch.' . oracle_stmt_q((string) $chqNumCol) . ' IS NOT NULL';
        }
        if ($flagCol !== null && $checkIn !== []) {
            $conds[] = 'v.' . oracle_stmt_q($flagCol) . ' IN (' . implode(',', $checkIn) . ')';
        }
        if ($remarkCol !== null) {
            $conds[] = 'v.' . oracle_stmt_q($remarkCol) . " LIKE '%شيك%'";
        }
        if ($conds !== []) {
            $typeLabelSql = 'CASE WHEN ' . implode(' OR ', $conds) . " THEN 'قبض شيك' ELSE 'قبض نقدي' END";
        }
    }

    $sql = 'SELECT ' . $repExpr . ' AS REP_NO,
                   ' . $compExpr . ' AS COMP_NUM,
                   v.' . oracle_stmt_q($numCol) . ' AS RCV_NO,
                   ' . $typeLabelSql . ' AS RCV_TYPE,
                   NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), TRIM(TO_CHAR(v." . oracle_stmt_q($accCol) . '))) AS CUSTOMER_NAME,
                   TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) AS CUSTOMER_ACC,
                   v.' . oracle_stmt_q($dateCol) . ' AS RCV_DATE,
                   ' . $chqSelectNum . ' AS CHQ_NUM,
                   ' . $chqSelectDue . ' AS CHQ_DUE,
                   ABS(v.' . oracle_stmt_q($amtCol) . ') AS AMOUNT
            FROM ' . $fromVod . $joinCus . $chqJoin . '
            WHERE ' . implode("\n              AND ", $where) . '
            ORDER BY ' . $repExpr . ',
                     v.' . oracle_stmt_q($dateCol) . ',
                     v.' . oracle_stmt_q($numCol) . ',
                     ' . ($hasCheque ? 'ch.' . oracle_stmt_q((string) $chqNumCol) : '1');

    try {
        $raw = oracle_query_all($conn, $sql, $binds);
    } catch (Throwable $e) {
        $empty['message'] = 'فشل استعلام التحصيلات: ' . $e->getMessage();

        return $empty;
    }

    // أسماء المندوبين من MANINFO
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

    $rows = [];
    $totalAmt = 0.0;
    foreach ($raw as $r) {
        $repNo = (int) (float) oracle_statement_row_val($r, 'REP_NO');
        $amt = (float) oracle_statement_row_val($r, 'AMOUNT');
        $rcvDate = oracle_statement_normalize_date(oracle_statement_row_val($r, 'RCV_DATE'));
        $chqDue = oracle_statement_normalize_date(oracle_statement_row_val($r, 'CHQ_DUE'));
        $rcvType = trim((string) oracle_statement_row_val($r, 'RCV_TYPE'));
        if ($rcvType === '') {
            $rcvType = 'قبض نقدي';
        }
        $totalAmt += $amt;
        $rows[] = [
            'rep_no' => $repNo,
            'rep_name' => $repNames[$repNo] ?? ('مندوب ' . $repNo),
            'comp_num' => (int) (float) oracle_statement_row_val($r, 'COMP_NUM'),
            'rcv_no' => (int) (float) oracle_statement_row_val($r, 'RCV_NO'),
            'rcv_type' => $rcvType,
            'customer_name' => trim((string) oracle_statement_row_val($r, 'CUSTOMER_NAME')),
            'customer_acc' => trim((string) oracle_statement_row_val($r, 'CUSTOMER_ACC')),
            'rcv_date' => $rcvDate,
            'chq_no' => trim((string) oracle_statement_row_val($r, 'CHQ_NUM')),
            'chq_due' => $chqDue,
            'amount' => round($amt, 3),
        ];
    }

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
            'vod_man_col' => $vodManCol,
            'cus_man_col' => $cusManCol,
            'has_cheque' => $hasCheque,
        ],
    ];
}
