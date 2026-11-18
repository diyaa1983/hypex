<?php
declare(strict_types=1);

/**
 * كشف تحصيلات المندوب خلال فترة — Oracle (قراءة فقط)
 *
 * منطق Forms «كشف تحصيلات المندوبين - فترة معينة»:
 * - الفترة على تاريخ القبض فقط (VOD_DATE)
 * - تاريخ حق الشيك للعرض فقط (قد يكون بعد الفترة)
 * - بلا صف في GLCHEQF ← قبض نقدي (حتى لو VOD_FLAG=11)
 * - وجود شيك ← قبض شيك بمبلغ CHQ_AMOUNT
 * - «مبيعات فواتير نقدية» (حساب GL / غالباً FLAG=14) تُدرج كقبض نقدي
 * - المندوب: CUS_SALESMAN أو عبر عميل على نفس رقم السند
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_rep_net_sales.php');

/**
 * @return array<string,mixed>
 */
function oracle_rep_collections_cfg(): array
{
    $cfg = oracle_config();
    $s = is_array($cfg['rep_collections'] ?? null) ? $cfg['rep_collections'] : [];
    $st = is_array($cfg['statement'] ?? null) ? $cfg['statement'] : [];
    $cu = is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [];
    $gl = is_array($cu['name_from_gl'] ?? null) ? $cu['name_from_gl'] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? ($st['owner'] ?? 'ACCINV')))) ?: 'ACCINV';

    $exclude = $s['exclude_flags'] ?? [12, 13, 14];
    if (!is_array($exclude)) {
        $exclude = [12, 13, 14];
    }

    // فارغ = أي حركة دائنة بلا شيك وليست مبيعات/مرتجع/قيد عام/شيك
    $cashFlags = $s['cash_flags'] ?? [];
    if (!is_array($cashFlags)) {
        $cashFlags = [];
    }

    $checkFlags = $s['check_flags'] ?? [10, 11];
    if (!is_array($checkFlags)) {
        $checkFlags = [10, 11];
    }

    // GLVODMF بلا MAN_NUM عندكم — المندوب من بطاقة العميل
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
        'exclude_flags' => array_values(array_filter(array_map('intval', $exclude), static fn ($n) => $n > 0)),
        'cash_flags' => array_values(array_filter(array_map('intval', $cashFlags), static fn ($n) => $n > 0)),
        'check_flags' => array_values(array_filter(array_map('intval', $checkFlags), static fn ($n) => $n > 0)),
        'rep_key' => $repKey,
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
 * @param list<string> $accs
 * @return array<string,string>
 */
function oracle_rep_collections_gl_names(array $conn, array $cfg, array $accs): array
{
    $want = [];
    foreach ($accs as $a) {
        $a = trim((string) $a);
        if ($a === '') {
            continue;
        }
        $want[$a] = true;
        $digits = preg_replace('/\D+/', '', $a) ?? '';
        if ($digits !== '') {
            $want[$digits] = true;
        }
    }
    if ($want === []) {
        return [];
    }

    $out = [];
    foreach (array_chunk(array_keys($want), 400) as $chunk) {
        $quoted = [];
        foreach ($chunk as $a) {
            $quoted[] = "'" . str_replace("'", "''", $a) . "'";
        }
        $sql = 'SELECT TRIM(TO_CHAR(' . oracle_stmt_q($cfg['gl_acc_num']) . ')) AS ACC,
                       TRIM(' . oracle_stmt_q($cfg['gl_acc_desc']) . ') AS N
                FROM ' . oracle_stmt_q($cfg['gl_owner']) . '.' . oracle_stmt_q($cfg['gl_table']) . '
                WHERE TRIM(TO_CHAR(' . oracle_stmt_q($cfg['gl_acc_num']) . ')) IN (' . implode(',', $quoted) . ')';
        try {
            foreach (oracle_query_all($conn, $sql, []) as $r) {
                $acc = trim((string) oracle_statement_row_val($r, 'ACC'));
                $name = trim((string) oracle_statement_row_val($r, 'N'));
                if ($acc !== '' && $name !== '') {
                    $out[$acc] = $name;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $out;
}

/**
 * تعبير رقم المندوب حسب الإعداد ووجود الأعمدة.
 */
function oracle_rep_collections_rep_expr(
    string $repKey,
    ?string $vodManCol,
    ?string $cusManCol,
    ?string $chqManCol = null,
    string $vodAlias = 'v',
    string $cusAlias = 'c',
    string $chqAlias = 'ch'
): string {
    $vod = $vodManCol !== null ? $vodAlias . '.' . oracle_stmt_q($vodManCol) : null;
    $cus = $cusManCol !== null ? $cusAlias . '.' . oracle_stmt_q($cusManCol) : null;
    $chq = $chqManCol !== null ? $chqAlias . '.' . oracle_stmt_q($chqManCol) : null;

    if ($repKey === 'cus_salesman' && $cus !== null) {
        return 'NVL(' . $cus . ', 0)';
    }
    if ($repKey === 'vod_man') {
        if ($vod !== null) {
            return 'NVL(' . $vod . ', 0)';
        }
        // لا عمود مندوب على السند — احتياطي بطاقة العميل
        if ($cus !== null) {
            return 'NVL(' . $cus . ', 0)';
        }

        return '0';
    }
    // both: سند ثم شيك ثم عميل
    if ($vod !== null && $chq !== null && $cus !== null) {
        return 'NVL(NULLIF(' . $vod . ', 0), NVL(NULLIF(' . $chq . ', 0), NVL(' . $cus . ', 0)))';
    }
    if ($vod !== null && $cus !== null) {
        return 'NVL(NULLIF(' . $vod . ', 0), NVL(' . $cus . ', 0))';
    }
    if ($vod !== null) {
        return 'NVL(' . $vod . ', 0)';
    }
    if ($chq !== null && $cus !== null) {
        return 'NVL(NULLIF(' . $chq . ', 0), NVL(' . $cus . ', 0))';
    }
    if ($cus !== null) {
        return 'NVL(' . $cus . ', 0)';
    }

    return '0';
}

/**
 * @return array{ok:bool,message:string,rows:list<array<string,mixed>>,totals:array{count:int,amount:float},filters?:array<string,mixed>}
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
        $empty['message'] = 'تعذر قراءة أعمدة ' . $owner . '.' . $cfg['table'];

        return $empty;
    }

    $accCol = oracle_rep_collections_pick_col($vodCols, ['VOD_ACC', 'ACC_NUM', 'CUS_NUM', 'VOD_ACCOUNT']) ?: 'VOD_ACC';
    $dateCol = oracle_rep_collections_pick_col($vodCols, ['VOD_DATE', 'TRN_DATE', 'DOC_DATE']) ?: 'VOD_DATE';
    $numCol = oracle_rep_collections_pick_col($vodCols, ['VOD_NUM', 'DOC_NO', 'V_NUM']) ?: 'VOD_NUM';
    $amtCol = oracle_rep_collections_pick_col($vodCols, ['VOD_AMOUNT', 'AMOUNT', 'VOD_AMT']) ?: 'VOD_AMOUNT';
    $sideCol = oracle_rep_collections_pick_col($vodCols, ['VOD_SIDE', 'SIDE']);
    $flagCol = oracle_rep_collections_pick_col($vodCols, ['VOD_FLAG', 'DOC_FLAG', 'FLAG']);
    $compCol = oracle_rep_collections_pick_col($vodCols, ['VOD_COMP', 'COMP_NUM', 'COMPANY', 'VOD_COMPANY']);
    // عميل مرتبط بالسند (حتى لو VOD_ACC = حساب مبيعات فواتير نقدية)
    $vodCusCol = oracle_rep_collections_pick_col($vodCols, [
        'VOD_CUS', 'VOD_CUS_NUM', 'CUS_NUM', 'VOD_CUSTOMER', 'CUST_ACC', 'VOD_CUST', 'BILL_CUS',
    ]);
    // لا تأخذ CUS_NUM إن كان هو نفس عمود الحساب
    if ($vodCusCol !== null && strtoupper($vodCusCol) === strtoupper($accCol)) {
        $vodCusCol = oracle_rep_collections_pick_col($vodCols, [
            'VOD_CUS', 'VOD_CUS_NUM', 'VOD_CUSTOMER', 'CUST_ACC', 'VOD_CUST', 'BILL_CUS',
        ]);
    }
    $vodManCol = oracle_rep_collections_pick_col($vodCols, [
        'MAN_NUM', 'VOD_MAN', 'VOD_MAN_NUM', 'SAL_MAN', 'SALESMAN', 'EMP_NO', 'VOD_EMP', 'REP_NO',
    ]);
    $chqManCol = oracle_rep_collections_pick_col($chqCols, [
        'CHQ_MAN', 'CHQ_MAN_NUM', 'MAN_NUM', 'SAL_MAN', 'SALESMAN', 'EMP_NO', 'REP_NO',
    ]);

    $cusNumCol = oracle_rep_collections_pick_col($cusCols, ['CUS_NUM', 'ACC_NUM', 'CUSTOMER_NO']) ?: 'CUS_NUM';
    $cusNameCol = oracle_rep_collections_pick_col($cusCols, [
        'CUS_TITLE', 'CUS_NAME', 'CUS_ANAME', 'CUS_A_NAME', 'NAME_AR',
    ]) ?: 'CUS_TITLE';
    $cusManCol = oracle_rep_collections_pick_col($cusCols, ['CUS_SALESMAN', 'SALESMAN', 'MAN_NUM', 'SAL_MAN', 'EMP_NO']);

    if ($vodManCol === null && $cusManCol === null) {
        $empty['message'] = 'لم يُعثر على عمود مندوب (MAN_NUM / CUS_SALESMAN).';

        return $empty;
    }

    // Forms يعتمد مندوب السند — إن طُلب vod_man ولا عمود، لا نكمل بصمت على العميل
    $effectiveRepKey = $cfg['rep_key'];
    if ($effectiveRepKey === 'vod_man' && $vodManCol === null) {
        $effectiveRepKey = 'cus_salesman';
    }

    $chqNumCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_NUM', 'CHEQUE_NO', 'CHECK_NO']);
    $chqAmtCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_AMOUNT', 'AMOUNT', 'CHQ_AMT']);
    $chqCusCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_CUS_NUM', 'CUS_NUM', 'CHQ_ACC']) ?: 'CHQ_CUS_NUM';
    $chqVNumCol = oracle_rep_collections_pick_col($chqCols, ['CHQ_V_NUM', 'VOD_NUM', 'VOUCHER_NO', 'CHQ_VNUM']);
    // تاريخ الحق/الاستحقاق — للعرض فقط (قد يكون بعد فترة التقرير مثل Forms)
    $chqDueCol = oracle_rep_collections_pick_col($chqCols, [
        'CHQ_DDATE', 'CHQ_DUE_DATE', 'CHQ_RIGHT_DATE', 'DUE_DATE', 'CHQ_DATE',
    ]);
    // تاريخ قبض/استلام الشيك — إن وُجد؛ وإلا تاريخ سند القبض
    $chqRecvCol = oracle_rep_collections_pick_col($chqCols, oracle_cheque_receipt_col_candidates());
    $hasCheque = $chqCols !== [] && $chqNumCol !== null && $chqAmtCol !== null && $chqVNumCol !== null;

    $repExpr = oracle_rep_collections_rep_expr($effectiveRepKey, $vodManCol, $cusManCol);

    $compExpr = $compCol !== null
        ? 'NVL(v.' . oracle_stmt_q($compCol) . ', 0)'
        : (string) max(1, (int) ($compNum ?? $cfg['comp_num']));

    // LEFT JOIN عميل + دليل أسماء — يظهر أيضاً حسابات مثل «مبيعات فواتير نقدية»
    $fromVod = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['table']) . ' v';
    $fromCusTable = oracle_stmt_q($cfg['cust_owner']) . '.' . oracle_stmt_q($cfg['cust_table']);
    $fromCus = $fromCusTable . ' c';
    $joinCus = ' LEFT JOIN ' . $fromCus
        . ' ON TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
    $glJoin = ' LEFT JOIN ' . oracle_stmt_q($cfg['gl_owner']) . '.' . oracle_stmt_q($cfg['gl_table']) . ' g
                ON TRIM(TO_CHAR(g.' . oracle_stmt_q($cfg['gl_acc_num']) . ')) = TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
    $nameExpr = 'NVL(NULLIF(TRIM(g.' . oracle_stmt_q($cfg['gl_acc_desc']) . "), ''),"
        . ' NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''),"
        . ' TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))))';

    $rf = $repFrom !== null && $repFrom > 0 ? $repFrom : null;
    $rt = $repTo !== null && $repTo > 0 ? $repTo : $rf;
    $useComp = (int) ($compNum ?? $cfg['comp_num']);
    $minVod = max(0, (int) $cfg['min_voucher_no']);

    $binds = ['df' => $dateFrom, 'dt' => $dateTo];
    $baseWhere = [
        'v.' . oracle_stmt_q($dateCol) . " >= TO_DATE(:df, 'YYYY-MM-DD')",
        'v.' . oracle_stmt_q($dateCol) . " < TO_DATE(:dt, 'YYYY-MM-DD') + 1",
    ];
    if ($sideCol !== null) {
        $baseWhere[] = 'v.' . oracle_stmt_q($sideCol) . ' = :cs';
        $binds['cs'] = (int) $cfg['credit_side'];
    }
    if ($minVod > 0) {
        $baseWhere[] = 'v.' . oracle_stmt_q($numCol) . ' >= :min_vod';
        $binds['min_vod'] = $minVod;
    }
    if ($compCol !== null && $useComp > 0) {
        $baseWhere[] = 'NVL(v.' . oracle_stmt_q($compCol) . ', 0) IN (0, :comp_num)';
        $binds['comp_num'] = $useComp;
    }

    $raw = [];
    $usedMode = [];

    $chqExistsSql = '';
    if ($hasCheque) {
        $fromChq = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['cheque_table']) . ' chx';
        $chqExistsSql = 'NOT EXISTS (
            SELECT 1 FROM ' . $fromChq . '
            WHERE chx.' . oracle_stmt_q((string) $chqVNumCol) . ' = v.' . oracle_stmt_q($numCol) . '
              AND TRIM(TO_CHAR(chx.' . oracle_stmt_q($chqCusCol) . ')) = TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))
              AND chx.' . oracle_stmt_q((string) $chqNumCol) . ' IS NOT NULL
        )';
    }

    // ─── قبض نقدي العادي (عملاء) — بلا شيك ───
    $whereCash = $baseWhere;
    $cashFlagMode = 'no_cheque';
    if ($flagCol !== null && $cfg['cash_flags'] !== []) {
        [$cfList, $cfBinds] = oracle_rep_collections_flag_binds($cfg['cash_flags'], 'cf');
        $binds = array_merge($binds, $cfBinds);
        $whereCash[] = 'v.' . oracle_stmt_q($flagCol) . ' IN (' . implode(',', $cfList) . ')';
        $cashFlagMode = 'include_list';
    } elseif ($flagCol !== null) {
        // استبعاد قيد عام/مرتجع فقط — العلم 14 يُسمح به إن وُجد عميل للمندوب (مبيعات نقدية)
        $whereCash[] = '(
            v.' . oracle_stmt_q($flagCol) . ' IS NULL
            OR v.' . oracle_stmt_q($flagCol) . ' NOT IN (12, 13)
        )';
        $cashFlagMode = 'exclude_12_13_allow_14';
    }
    if ($chqExistsSql !== '') {
        $whereCash[] = $chqExistsSql;
    }
    if ($rf !== null) {
        $binds['rep_from'] = $rf;
        $binds['rep_to'] = $rt ?? $rf;
        $whereCash[] = $repExpr . ' >= :rep_from';
        $whereCash[] = $repExpr . ' <= :rep_to';
    } else {
        $whereCash[] = $repExpr . ' > 0';
    }

    // إن كان الوصف «مبيعات فواتير نقدية» نعرضه كما في Forms
    $cashNameExpr = 'CASE
        WHEN INSTR(NVL(TRIM(g.' . oracle_stmt_q($cfg['gl_acc_desc']) . "), ''), 'فواتير نقدية') > 0
          OR INSTR(NVL(TRIM(g." . oracle_stmt_q($cfg['gl_acc_desc']) . "), ''), 'مبيعات فواتير') > 0
        THEN NVL(NULLIF(TRIM(g." . oracle_stmt_q($cfg['gl_acc_desc']) . "), ''), 'مبيعات فواتير نقدية')
        ELSE " . $nameExpr . '
      END';

    $sqlCash = 'SELECT ' . $repExpr . ' AS REP_NO,
                       ' . $compExpr . ' AS COMP_NUM,
                       v.' . oracle_stmt_q($numCol) . ' AS RCV_NO,
                       \'قبض نقدي\' AS RCV_TYPE,
                       ' . $cashNameExpr . ' AS CUSTOMER_NAME,
                       TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) AS CUSTOMER_ACC,
                       TRUNC(MIN(v.' . oracle_stmt_q($dateCol) . ')) AS RCV_DATE,
                       CAST(NULL AS VARCHAR2(40)) AS CHQ_NUM,
                       CAST(NULL AS DATE) AS CHQ_DUE,
                       ROUND(SUM(ABS(v.' . oracle_stmt_q($amtCol) . ')), 3) AS AMOUNT
                FROM ' . $fromVod . $joinCus . $glJoin . '
                WHERE ' . implode("\n                  AND ", $whereCash) . '
                GROUP BY ' . $repExpr . ',
                         ' . $compExpr . ',
                         v.' . oracle_stmt_q($numCol) . ',
                         ' . $cashNameExpr . ',
                         TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
    try {
        $raw = array_merge($raw, oracle_query_all($conn, $sqlCash, $binds));
        $usedMode[] = 'cash_' . $cashFlagMode;
    } catch (Throwable $e) {
        try {
            $sqlCash2 = str_replace($glJoin, '', $sqlCash);
            $sqlCash2 = str_replace(
                $nameExpr,
                'NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), TRIM(TO_CHAR(v." . oracle_stmt_q($accCol) . ')))',
                $sqlCash2
            );
            $sqlCash2 = str_replace('CAST(NULL AS VARCHAR2(40))', 'NULL', $sqlCash2);
            $sqlCash2 = str_replace('CAST(NULL AS DATE)', 'NULL', $sqlCash2);
            $raw = array_merge($raw, oracle_query_all($conn, $sqlCash2, $binds));
            $usedMode[] = 'cash_fallback';
        } catch (Throwable $e2) {
            $empty['message'] = 'فشل استعلام القبض النقدي: ' . $e2->getMessage();

            return $empty;
        }
    }

    // ─── مبيعات فواتير نقدية (مثال Forms: سند 24985 / 92.561 / مندوب 3) ───
    // بلا شرط شيك، وبلا استبعاد FLAG=14 — المندوب من العميل أو DAILY فقط
    $remarkCol = oracle_rep_collections_pick_col($vodCols, [
        'VOD_REMARK', 'VOD_DESC', 'VOD_NOTE', 'REMARK', 'DESCRIPTION', 'VOD_EXPLAIN',
    ]);
    $si = is_array(oracle_config()['sales_invoice'] ?? null) ? oracle_config()['sales_invoice'] : [];
    $dailyOwner = strtoupper(trim((string) ($si['owner'] ?? 'MAS'))) ?: 'MAS';
    $dailyTable = strtoupper(trim((string) ($si['table'] ?? 'DAILY'))) ?: 'DAILY';
    $dailyQ = oracle_stmt_q($dailyOwner) . '.' . oracle_stmt_q($dailyTable);
    $glDesc = 'g.' . oracle_stmt_q($cfg['gl_acc_desc']);

    $isCashInvSql = '('
        . "INSTR(NVL(TRIM({$glDesc}), ' '), 'فواتير نقدية') > 0"
        . " OR INSTR(NVL(TRIM({$glDesc}), ' '), 'مبيعات فواتير') > 0"
        . " OR (INSTR(NVL(TRIM({$glDesc}), ' '), 'مبيعات') > 0 AND INSTR(NVL(TRIM({$glDesc}), ' '), 'نقد') > 0)";
    if ($remarkCol !== null) {
        $rm = 'v.' . oracle_stmt_q($remarkCol);
        $isCashInvSql .= " OR INSTR(NVL(TRIM({$rm}), ' '), 'فواتير نقدية') > 0"
            . " OR INSTR(NVL(TRIM({$rm}), ' '), 'مبيعات فواتير') > 0";
    }
    $isCashInvSql .= ')';

    $repFromSameVod = '0';
    if ($cusManCol !== null) {
        $repFromSameVod = 'NVL((SELECT MAX(NVL(cx.' . oracle_stmt_q($cusManCol) . ',0))'
            . ' FROM ' . oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['table']) . ' vx'
            . ' JOIN ' . $fromCusTable . ' cx ON TRIM(TO_CHAR(cx.' . oracle_stmt_q($cusNumCol)
            . ')) = TRIM(TO_CHAR(vx.' . oracle_stmt_q($accCol) . '))'
            . ' WHERE vx.' . oracle_stmt_q($numCol) . ' = v.' . oracle_stmt_q($numCol)
            . ' AND NVL(cx.' . oracle_stmt_q($cusManCol) . ',0) > 0), 0)';
    }
    $repFromVodCus = '0';
    if ($vodCusCol !== null && $cusManCol !== null) {
        $repFromVodCus = 'NVL((SELECT MAX(NVL(cy.' . oracle_stmt_q($cusManCol) . ',0))'
            . ' FROM ' . $fromCusTable . ' cy'
            . ' WHERE TRIM(TO_CHAR(cy.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(v.'
            . oracle_stmt_q($vodCusCol) . '))'
            . ' AND NVL(cy.' . oracle_stmt_q($cusManCol) . ',0) > 0), 0)';
    }
    $repFromDaily = 'NVL((SELECT MAX(d.MAN_NUM) FROM ' . $dailyQ . ' d'
        . ' WHERE d.V_NUM = v.' . oracle_stmt_q($numCol) . ' AND NVL(d.MAN_NUM,0) > 0), 0)';

    // إن كان رقم الحساب نفسه = رقم المندوب/العميل (مثل حساب 3) نقبله
    $repFromAccNum = '0';
    if ($rf !== null) {
        $repFromAccNum = '(CASE WHEN TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) = TRIM(TO_CHAR(:rep_acc))'
            . ' THEN :rep_acc ELSE 0 END)';
    }

    $repInvExpr = 'NVL(NULLIF(' . $repExpr . ',0),'
        . 'NVL(NULLIF(' . $repFromVodCus . ',0),'
        . 'NVL(NULLIF(' . $repFromSameVod . ',0),'
        . 'NVL(NULLIF(' . $repFromDaily . ',0),'
        . ($repFromAccNum !== '0' ? 'NULLIF(' . $repFromAccNum . ',0)' : '0')
        . '))))';

    $nameInv = "NVL(NULLIF(TRIM({$glDesc}), ''), 'مبيعات فواتير نقدية')";

    $bindsInv = ['df' => $dateFrom, 'dt' => $dateTo];
    $whereInv = [
        'v.' . oracle_stmt_q($dateCol) . " >= TO_DATE(:df, 'YYYY-MM-DD')",
        'v.' . oracle_stmt_q($dateCol) . " < TO_DATE(:dt, 'YYYY-MM-DD') + 1",
        $isCashInvSql,
        'ABS(v.' . oracle_stmt_q($amtCol) . ') > 0',
    ];
    if ($minVod > 0) {
        $whereInv[] = 'v.' . oracle_stmt_q($numCol) . ' >= :min_vod';
        $bindsInv['min_vod'] = $minVod;
    }
    if ($compCol !== null && $useComp > 0) {
        $whereInv[] = 'NVL(v.' . oracle_stmt_q($compCol) . ', 0) IN (0, :comp_num)';
        $bindsInv['comp_num'] = $useComp;
    }
    // لا نفرض VOD_SIDE هنا أولاً — سند 24985 قد يكون على جهة مختلفة
    if ($rf !== null) {
        $bindsInv['rep_from'] = $rf;
        $bindsInv['rep_to'] = $rt ?? $rf;
        $bindsInv['rep_acc'] = $rf;
        $whereInv[] = '(' . $repInvExpr . ' >= :rep_from AND ' . $repInvExpr . ' <= :rep_to)';
    } else {
        $whereInv[] = 'NVL(' . $repInvExpr . ',0) > 0';
    }

    $sqlCashInv = 'SELECT ' . $repInvExpr . ' AS REP_NO,
                          ' . $compExpr . ' AS COMP_NUM,
                          v.' . oracle_stmt_q($numCol) . ' AS RCV_NO,
                          \'قبض نقدي\' AS RCV_TYPE,
                          ' . $nameInv . ' AS CUSTOMER_NAME,
                          TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) AS CUSTOMER_ACC,
                          TRUNC(MIN(v.' . oracle_stmt_q($dateCol) . ')) AS RCV_DATE,
                          CAST(NULL AS VARCHAR2(40)) AS CHQ_NUM,
                          CAST(NULL AS DATE) AS CHQ_DUE,
                          ROUND(SUM(ABS(v.' . oracle_stmt_q($amtCol) . ')), 3) AS AMOUNT
                   FROM ' . $fromVod . $joinCus . $glJoin . '
                   WHERE ' . implode("\n                     AND ", $whereInv) . '
                   GROUP BY ' . $repInvExpr . ',
                            ' . $compExpr . ',
                            v.' . oracle_stmt_q($numCol) . ',
                            ' . $nameInv . ',
                            TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
    try {
        $raw = array_merge($raw, oracle_query_all($conn, $sqlCashInv, $bindsInv));
        $usedMode[] = 'cash_invoice_24985_style';
    } catch (Throwable $e) {
        // مع جهة دائنة فقط
        try {
            $sqlCashInv2 = $sqlCashInv;
            if ($sideCol !== null) {
                $sqlCashInv2 = str_replace(
                    'WHERE ',
                    'WHERE v.' . oracle_stmt_q($sideCol) . ' = ' . (int) $cfg['credit_side'] . "\n                   AND ",
                    $sqlCashInv2
                );
            }
            $sqlCashInv2 = str_replace('CAST(NULL AS VARCHAR2(40))', 'NULL', $sqlCashInv2);
            $sqlCashInv2 = str_replace('CAST(NULL AS DATE)', 'NULL', $sqlCashInv2);
            $raw = array_merge($raw, oracle_query_all($conn, $sqlCashInv2, $bindsInv));
            $usedMode[] = 'cash_invoice_side2:' . $e->getMessage();
        } catch (Throwable $e2) {
            $usedMode[] = 'cash_invoice_fail:' . $e2->getMessage();
        }
    }

    // احتياطي مباشر للسندات النقدية المسجّلة على رقم الحساب = رقم المندوب (حساب 3…)
    if ($rf !== null && $rf === ($rt ?? $rf)) {
        $bindsAcc = ['df' => $dateFrom, 'dt' => $dateTo, 'acc' => (string) $rf, 'rep' => $rf];
        if ($minVod > 0) {
            $bindsAcc['min_vod'] = $minVod;
        }
        $sqlAcc = 'SELECT :rep AS REP_NO,
                          ' . $compExpr . ' AS COMP_NUM,
                          v.' . oracle_stmt_q($numCol) . ' AS RCV_NO,
                          \'قبض نقدي\' AS RCV_TYPE,
                          ' . $nameInv . ' AS CUSTOMER_NAME,
                          TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . ')) AS CUSTOMER_ACC,
                          TRUNC(MIN(v.' . oracle_stmt_q($dateCol) . ')) AS RCV_DATE,
                          NULL AS CHQ_NUM,
                          NULL AS CHQ_DUE,
                          ROUND(SUM(ABS(v.' . oracle_stmt_q($amtCol) . ')), 3) AS AMOUNT
                   FROM ' . $fromVod . $glJoin . '
                   WHERE v.' . oracle_stmt_q($dateCol) . " >= TO_DATE(:df, 'YYYY-MM-DD')
                     AND v." . oracle_stmt_q($dateCol) . " < TO_DATE(:dt, 'YYYY-MM-DD') + 1
                     AND TRIM(TO_CHAR(v." . oracle_stmt_q($accCol) . ')) = TRIM(:acc)
                     AND ABS(v.' . oracle_stmt_q($amtCol) . ') > 0'
            . ($minVod > 0 ? ' AND v.' . oracle_stmt_q($numCol) . ' >= :min_vod' : '')
            . ' GROUP BY ' . $compExpr . ', v.' . oracle_stmt_q($numCol) . ', ' . $nameInv
            . ', TRIM(TO_CHAR(v.' . oracle_stmt_q($accCol) . '))';
        try {
            $raw = array_merge($raw, oracle_query_all($conn, $sqlAcc, $bindsAcc));
            $usedMode[] = 'cash_invoice_acc_eq_rep';
        } catch (Throwable $e) {
            $usedMode[] = 'cash_invoice_acc_fail:' . $e->getMessage();
        }
    }

    // ─── قبض شيك: وجود صف في GLCHEQF = قبض شيك (تاريخ القبض في الفترة؛ الحق للعرض فقط) ───
    if ($hasCheque) {
        $fromChq = oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['cheque_table']) . ' ch';
        $joinChqCus = ' LEFT JOIN ' . $fromCus
            . ' ON TRIM(TO_CHAR(c.' . oracle_stmt_q($cusNumCol) . ')) = TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . '))';

        $vodAggSelect = 'SELECT v0.' . oracle_stmt_q($numCol) . ' AS VOD_NUM,
                                TRIM(TO_CHAR(v0.' . oracle_stmt_q($accCol) . ')) AS VOD_ACC,
                                TRUNC(MIN(v0.' . oracle_stmt_q($dateCol) . ')) AS VOD_DATE';
        if ($flagCol !== null) {
            $vodAggSelect .= ', MAX(v0.' . oracle_stmt_q($flagCol) . ') AS VOD_FLAG';
        }
        if ($vodManCol !== null) {
            $vodAggSelect .= ', MAX(v0.' . oracle_stmt_q($vodManCol) . ') AS VOD_MAN';
        }
        if ($compCol !== null) {
            $vodAggSelect .= ', MAX(v0.' . oracle_stmt_q($compCol) . ') AS VOD_COMP';
        }
        $vodAggFrom = ' FROM ' . oracle_stmt_q($owner) . '.' . oracle_stmt_q($cfg['table']) . ' v0 WHERE 1=1';
        if ($sideCol !== null) {
            $vodAggFrom .= ' AND v0.' . oracle_stmt_q($sideCol) . ' = ' . (int) $cfg['credit_side'];
        }
        if ($flagCol !== null && $cfg['exclude_flags'] !== []) {
            // استبعاد مبيعات/مرتجع/قيد عام حتى لو وُجد ربط شيك بالخطأ
            [$exChList, $exChBinds] = oracle_rep_collections_flag_binds($cfg['exclude_flags'], 'xe');
            // binds تُضاف لاحقاً مع bindsChq
            $vodAggFrom .= ' AND (v0.' . oracle_stmt_q($flagCol) . ' IS NULL OR v0.' . oracle_stmt_q($flagCol)
                . ' NOT IN (' . implode(',', $exChList) . '))';
        } else {
            $exChBinds = [];
        }
        $vodAggGroup = ' GROUP BY v0.' . oracle_stmt_q($numCol) . ', TRIM(TO_CHAR(v0.' . oracle_stmt_q($accCol) . '))';
        $vodAgg = '(' . $vodAggSelect . $vodAggFrom . $vodAggGroup . ') v';

        $joinChqVod = ' INNER JOIN ' . $vodAgg
            . ' ON v.VOD_NUM = ch.' . oracle_stmt_q((string) $chqVNumCol)
            . ' AND v.VOD_ACC = TRIM(TO_CHAR(ch.' . oracle_stmt_q($chqCusCol) . '))';

        $glJoinChq = ' LEFT JOIN ' . oracle_stmt_q($cfg['gl_owner']) . '.' . oracle_stmt_q($cfg['gl_table']) . ' g
                ON TRIM(TO_CHAR(g.' . oracle_stmt_q($cfg['gl_acc_num']) . ')) = v.VOD_ACC';

        $repExprChq = oracle_rep_collections_rep_expr(
            $effectiveRepKey,
            $vodManCol !== null ? 'VOD_MAN' : null,
            $cusManCol,
            $chqManCol
        );

        $nameExprChq = 'NVL(NULLIF(TRIM(g.' . oracle_stmt_q($cfg['gl_acc_desc']) . "), ''),"
            . ' NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''),"
            . ' v.VOD_ACC))';

        // عرض فقط — Forms يُظهر شيك مقبوض 11-2026 ولو تاريخ الحق 11-2027
        $dueExpr = $chqDueCol !== null ? 'TRUNC(ch.' . oracle_stmt_q($chqDueCol) . ')' : 'NULL';
        $compExprChq = $compCol !== null ? 'NVL(v.VOD_COMP, 0)' : (string) max(1, $useComp);

        // الفترة = تاريخ القبض فقط (وليس تاريخ الحق/التحصيل)
        if ($chqRecvCol !== null) {
            $rcvDateExprChq = 'TRUNC(NVL(ch.' . oracle_stmt_q($chqRecvCol) . ', v.VOD_DATE))';
        } else {
            $rcvDateExprChq = 'v.VOD_DATE';
        }

        $bindsChq = array_merge(['df' => $dateFrom, 'dt' => $dateTo], $exChBinds ?? []);

        $whereChq = [
            $rcvDateExprChq . ' >= TO_DATE(:df, \'YYYY-MM-DD\')',
            $rcvDateExprChq . ' < TO_DATE(:dt, \'YYYY-MM-DD\') + 1',
            'ch.' . oracle_stmt_q((string) $chqNumCol) . ' IS NOT NULL',
            'ABS(ch.' . oracle_stmt_q((string) $chqAmtCol) . ') > 0',
        ];
        // إن وُجدت أعلام شيك: فضّلها أو اسمح بأي سند مرتبط بشيك فعلي
        if ($flagCol !== null && $cfg['check_flags'] !== []) {
            [$ckList, $ckBinds] = oracle_rep_collections_flag_binds($cfg['check_flags'], 'ck');
            $bindsChq = array_merge($bindsChq, $ckBinds);
            $whereChq[] = '(v.VOD_FLAG IN (' . implode(',', $ckList) . ') OR v.VOD_FLAG IS NULL)';
        }
        if ($minVod > 0) {
            $whereChq[] = 'v.VOD_NUM >= :min_vod';
            $bindsChq['min_vod'] = $minVod;
        }
        if ($rf !== null) {
            $whereChq[] = $repExprChq . ' >= :rep_from';
            $whereChq[] = $repExprChq . ' <= :rep_to';
            $bindsChq['rep_from'] = $rf;
            $bindsChq['rep_to'] = $rt ?? $rf;
        } else {
            $whereChq[] = $repExprChq . ' > 0';
        }
        if ($compCol !== null && $useComp > 0) {
            $whereChq[] = 'NVL(v.VOD_COMP, 0) IN (0, :comp_num)';
            $bindsChq['comp_num'] = $useComp;
        }

        $sqlChq = 'SELECT ' . $repExprChq . ' AS REP_NO,
                          ' . $compExprChq . ' AS COMP_NUM,
                          v.VOD_NUM AS RCV_NO,
                          \'قبض شيك\' AS RCV_TYPE,
                          ' . $nameExprChq . ' AS CUSTOMER_NAME,
                          v.VOD_ACC AS CUSTOMER_ACC,
                          ' . $rcvDateExprChq . ' AS RCV_DATE,
                          TRIM(TO_CHAR(ch.' . oracle_stmt_q((string) $chqNumCol) . ')) AS CHQ_NUM,
                          ' . $dueExpr . ' AS CHQ_DUE,
                          ROUND(ABS(ch.' . oracle_stmt_q((string) $chqAmtCol) . '), 3) AS AMOUNT
                   FROM ' . $fromChq . $joinChqCus . $joinChqVod . $glJoinChq . '
                   WHERE ' . implode("\n                     AND ", $whereChq);
        try {
            $raw = array_merge($raw, oracle_query_all($conn, $sqlChq, $bindsChq));
            $usedMode[] = 'cheque_by_receipt_date';
        } catch (Throwable $e) {
            try {
                $sqlChq2 = str_replace($glJoinChq, '', $sqlChq);
                $sqlChq2 = str_replace(
                    $nameExprChq,
                    'NVL(NULLIF(TRIM(c.' . oracle_stmt_q($cusNameCol) . "), ''), v.VOD_ACC)",
                    $sqlChq2
                );
                $raw = array_merge($raw, oracle_query_all($conn, $sqlChq2, $bindsChq));
                $usedMode[] = 'cheque_fallback';
            } catch (Throwable $e2) {
                $usedMode[] = 'cheque_fail:' . $e2->getMessage();
            }
        }
    }

    $needNames = [];
    foreach ($raw as $r) {
        $nm = trim((string) oracle_statement_row_val($r, 'CUSTOMER_NAME'));
        $acc = trim((string) oracle_statement_row_val($r, 'CUSTOMER_ACC'));
        if ($acc !== '' && ($nm === '' || $nm === $acc || preg_match('/^\d+$/', $nm))) {
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
        $rcvType = trim((string) oracle_statement_row_val($r, 'RCV_TYPE'));
        if ($rcvType !== 'قبض شيك') {
            $rcvType = 'قبض نقدي';
        }
        $acc = trim((string) oracle_statement_row_val($r, 'CUSTOMER_ACC'));
        $chqNo = trim((string) oracle_statement_row_val($r, 'CHQ_NUM'));
        $amt = round((float) oracle_statement_row_val($r, 'amount'), 3);
        if ($amt == 0.0) {
            continue;
        }
        if ($minVod > 0 && $rcvNo > 0 && $rcvNo < $minVod) {
            continue;
        }

        $custName = trim((string) oracle_statement_row_val($r, 'CUSTOMER_NAME'));
        if ($acc !== '' && !empty($glNames[$acc])) {
            $custName = $glNames[$acc];
        } elseif ($acc !== '' && ($custName === '' || $custName === $acc || preg_match('/^\d+$/', $custName))) {
            $digits = preg_replace('/\D+/', '', $acc) ?? '';
            if ($digits !== '' && !empty($glNames[$digits])) {
                $custName = $glNames[$digits];
            }
        }
        if ($custName === '') {
            $custName = $acc !== '' ? $acc : '—';
        }

        $key = $repNo . '|' . $rcvNo . '|' . $acc . '|' . $rcvType . '|' . $chqNo . '|' . $amt;
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
            'effective_rep_key' => $effectiveRepKey,
            'vod_man_col' => $vodManCol,
            'cus_man_col' => $cusManCol,
            'min_voucher_no' => $minVod,
            'cash_flags' => $cfg['cash_flags'],
            'check_flags' => $cfg['check_flags'],
            'chq_recv_col' => $chqRecvCol,
            'chq_due_col' => $chqDueCol,
            'period_on' => 'receipt_date',
            'mode' => $usedMode,
        ],
    ];
}
