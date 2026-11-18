<?php
declare(strict_types=1);

/**
 * كشف تحصيلات المندوب خلال فترة — من Oracle (قراءة فقط)
 * مثل Forms: صفوف قبض نقدي + صفوف قبض شيك، مجموع المندوب = مجموع القيم الظاهرة.
 *
 * مهم:
 * - النقدي من GLVODMF (دائن عميل) بدون مضاعفة من الشيكات
 * - الشيك من GLCHEQF بمبلغ CHQ_AMOUNT (وليس تكرار VOD_AMOUNT لكل شيك)
 * - المندوب افتراضياً من CUSTOMER.CUS_SALESMAN (نطاق المندوب في Forms)
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_rep_net_sales.php');

/**
 * @return array{
 *   owner:string, table:string, cheque_table:string, cust_owner:string, cust_table:string,
 *   credit_side:int, comp_num:int, cash_flags:list<int>, check_flags:list<int>,
 *   rep_key:string
 * }
 */
function oracle_rep_collections_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['rep_collections'] ?? null) ? $cfg['rep_collections'] : [];
    $st = is_array($cfg['statement'] ?? null) ? $cfg['statement'] : [];
    $cu = is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? ($st['owner'] ?? 'ACCINV')))) ?: 'ACCINV';

    // أعلام ضيقة: نقدي شائع 1/15/16 — شيك 10/11 (كما في كشف الحساب)
    $cash = $s['cash_flags'] ?? [1, 15, 16];
    $check = $s['check_flags'] ?? [10, 11];
    if (!is_array($cash)) {
        $cash = [1, 15, 16];
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
    $cusNameCol = oracle_rep_collections_pick_col($cusCols, [
        'CUS_ANAME', 'CUS_A_NAME', 'CUS_TITLE', 'CUS_NAME', 'NAME_AR', 'ACC_DESC',
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

    // تعبير المندوب
    $repExprCash = '0';
    $repExprChq = '0';
    if ($cfg['rep_key'] === 'vod_man' && $vodManCol !== null) {
        $repExprCash = 'NVL(v.' . oracle_stmt_q($vodManCol) . ', 0)';
        $repExprChq = $vodManCol !== null
            ? 'NVL(v.' . oracle_stmt_q($vodManCol) . ', NVL(c.' . oracle_stmt_q((string) $cusManCol) . ', 0))'
            : 'NVL(c.' . oracle_stmt_q((string) $cusManCol) . ', 0)';
    } elseif ($cfg['rep_key'] === 'both' && $vodManCol !== null && $cusManCol !== null) {
        $repExprCash = 'NVL(NULLIF(v.' . oracle_stmt_q($vodManCol) . ', 0), NVL(c.' . oracle_stmt_q($cusManCol) . ', 0))';
        $repExprChq = $repExprCash;
    } else {
        // افتراضي Forms: مندوب بطاقة العميل
        $repExprCash = 'NVL(c.' . oracle_stmt_q((string) $cusManCol) . ', 0)';
        $repExprChq = $repExprCash;
    }

    $compExpr = $compCol !== null
        ? 'NVL(v.' . oracle_stmt_q($compCol) . ', 0)'
        : (string) max(1, (int) ($compNum ?? $cfg['comp_num']));

    $fromVod = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['table']) . ' v';
    $fromCus = oracle_stmt_q($cfg['cust_owner']) . '.' . oracle_stmt_q($cfg['cust_table']) . ' c';
    $joinCus = ' INNER JOIN ' . $fromCus
        . ' ON TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';

    $rf = $repFrom !== null && $repFrom > 0 ? $repFrom : null;
    $rt = $repTo !== null && $repTo > 0 ? $repTo : $rf;
    $useComp = (int) ($compNum ?? $cfg['comp_num']);

    $cashFlags = $cfg['cash_flags'];
    $checkFlags = $cfg['check_flags'];
    // لا تتداخل: النقدي لا يشمل أعلام الشيك
    $cashFlags = array_values(array_diff($cashFlags, $checkFlags));
    if ($cashFlags === []) {
        $cashFlags = [1];
    }

    $raw = [];
    $usedMode = [];

    // ─── 1) قبض نقدي: سطر واحد لكل سند+عميل (مجموع البنود) ───
    if ($flagCol !== null && $cashFlags !== []) {
        [$flagList, $flagBinds] = oracle_rep_collections_flag_binds($cashFlags, 'cf');
        $binds = array_merge(['df' => $dateFrom, 'dt' => $dateTo], $flagBinds);
        $where = [
            'v.' . oracle_stmt_q($dateCol) . " >= TO_DATE(:df, 'YYYY-MM-DD')",
            'v.' . oracle_stmt_q($dateCol) . " < TO_DATE(:dt, 'YYYY-MM-DD') + 1",
            'v.' . oracle_stmt_q($flagCol) . ' IN (' . implode(',', $flagList) . ')',
        ];
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
                           NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), TRIM(TO_CHAR(v." . oracle_stmt_q($accCol) . '))) AS CUSTOMER_NAME,
                           TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) AS CUSTOMER_ACC,
                           TRUNC(MIN(v.' . oracle_stmt_q($dateCol) . ')) AS RCV_DATE,
                           NULL AS CHQ_NUM,
                           NULL AS CHQ_DUE,
                           ROUND(SUM(ABS(v.' . oracle_stmt_q($amtCol) . ')), 3) AS AMOUNT
                    FROM ' . $fromVod . $joinCus . '
                    WHERE ' . implode("\n                      AND ", $where) . '
                    GROUP BY ' . $repExprCash . ',
                             ' . $compExpr . ',
                             v.' . oracle_stmt_q($numCol) . ',
                             NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), TRIM(TO_CHAR(v." . oracle_stmt_q($accCol) . '))),
                             TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
        try {
            $raw = array_merge($raw, oracle_query_all($conn, $sqlCash, $binds));
            $usedMode[] = 'cash_flags=' . implode(',', $cashFlags);
        } catch (Throwable $e) {
            $empty['message'] = 'فشل استعلام القبض النقدي: ' . $e->getMessage();

            return $empty;
        }
    }

    // ─── 2) قبض شيك: صف لكل شيك بمبلغ الشيك ───
    if ($hasCheque) {
        $fromChq = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['cheque_table']) . ' ch';
        $joinChqCus = ' INNER JOIN ' . $fromCus
            . ' ON TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . '))';
        // ربط اختياري بسند الحركة للتاريخ/العلم/المندوب على السند
        $joinChqVod = ' LEFT JOIN ' . $fromVod
            . ' ON v.' . oracle_stmt_q($numCol) . ' = ch.' . oracle_stmt_q((string) $chqVNumCol)
            . ' AND TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) = TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . '))';
        if ($sideCol !== null) {
            $joinChqVod .= ' AND v.' . oracle_stmt_q($sideCol) . ' = ' . (int) $cfg['credit_side'];
        }

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

        // إن وُجد علم على السند: اقبل شيك العلم أو سنداً فارغاً مرتبطاً بشيك
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
                          NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), TRIM(TO_CHAR(ch." . oracle_stmt_q($chqCusCol) . '))) AS CUSTOMER_NAME,
                          TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . ')) AS CUSTOMER_ACC,
                          ' . $rcvDateExpr . ' AS RCV_DATE,
                          TO_CHAR(ch.' . oracle_stmt_q((string) $chqNumCol) . ') AS CHQ_NUM,
                          ' . $dueExpr . ' AS CHQ_DUE,
                          ROUND(ABS(ch.' . oracle_stmt_q((string) $chqAmtCol) . '), 3) AS AMOUNT
                   FROM ' . $fromChq . $joinChqCus . $joinChqVod . '
                   WHERE ' . implode("\n                     AND ", $where);

        try {
            $raw = array_merge($raw, oracle_query_all($conn, $sqlChq, $binds));
            $usedMode[] = 'cheque_amount';
        } catch (Throwable $e) {
            // إن فشل مسار الشيكات نُكمل بالنقدي فقط
            $usedMode[] = 'cheque_fail:' . $e->getMessage();
        }
    }

    // أسماء المندوبين
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

    // تجميع نهائي لتفادي تكرار صف نقدي/شيك بنفس المفتاح
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
            'customer_name' => trim((string) oracle_statement_row_val($r, 'CUSTOMER_NAME')),
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
            'mode' => $usedMode,
            'vod_man_col' => $vodManCol,
            'cus_man_col' => $cusManCol,
            'cus_name_col' => $cusNameCol,
        ],
    ];
}
