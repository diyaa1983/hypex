<?php
declare(strict_types=1);

require_once app_path('includes/acc_account_tree.php');

/** @return array<string, mixed>|null */
function acc_account_get_by_code(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM acc_account WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array{
 *   journal_lines:int,
 *   journal_debit:float,
 *   journal_credit:float,
 *   vouchers:int,
 *   posting_rules:list<string>
 * }
 */
function acc_account_usage_summary(PDO $pdo, int $accountId): array
{
    $out = [
        'journal_lines' => 0,
        'journal_debit' => 0.0,
        'journal_credit' => 0.0,
        'vouchers' => 0,
        'posting_rules' => [],
    ];
    if ($accountId < 1) {
        return $out;
    }

    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*), COALESCE(SUM(debit),0), COALESCE(SUM(credit),0)
             FROM acc_journal_line WHERE account_id = ?'
        );
        $st->execute([$accountId]);
        $r = $st->fetch(PDO::FETCH_NUM);
        if ($r) {
            $out['journal_lines'] = (int) $r[0];
            $out['journal_debit'] = (float) $r[1];
            $out['journal_credit'] = (float) $r[2];
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM fin_voucher WHERE cash_account_id = ?');
        $st->execute([$accountId]);
        $out['vouchers'] = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = $pdo->prepare('SELECT rule_code FROM acc_posting_setting WHERE account_id = ?');
        $st->execute([$accountId]);
        $out['posting_rules'] = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        // ignore
    }

    return $out;
}

/**
 * معاينة نقل كل المراجع من حساب إلى آخر.
 *
 * @return array{
 *   ok:bool,
 *   message:string,
 *   from:array<string,mixed>|null,
 *   to:array<string,mixed>|null,
 *   from_usage:array<string,mixed>,
 *   to_usage:array<string,mixed>
 * }
 */
function acc_account_reassign_preview(PDO $pdo, int $fromId, int $toId): array
{
    $from = acc_account_get($pdo, $fromId);
    $to = acc_account_get($pdo, $toId);
    if (!$from || !$to) {
        return [
            'ok' => false,
            'message' => 'أحد الحسابين غير موجود.',
            'from' => $from,
            'to' => $to,
            'from_usage' => [],
            'to_usage' => [],
        ];
    }
    if ($fromId === $toId) {
        return [
            'ok' => false,
            'message' => 'لا يمكن النقل إلى نفس الحساب.',
            'from' => $from,
            'to' => $to,
            'from_usage' => acc_account_usage_summary($pdo, $fromId),
            'to_usage' => acc_account_usage_summary($pdo, $toId),
        ];
    }
    if ((string) ($from['account_type'] ?? '') !== (string) ($to['account_type'] ?? '')) {
        return [
            'ok' => false,
            'message' => 'نوع الحسابين مختلف — يجب أن يكونا من نفس النوع (مثلاً أصول).',
            'from' => $from,
            'to' => $to,
            'from_usage' => acc_account_usage_summary($pdo, $fromId),
            'to_usage' => acc_account_usage_summary($pdo, $toId),
        ];
    }

    return [
        'ok' => true,
        'message' => 'جاهز للنقل.',
        'from' => $from,
        'to' => $to,
        'from_usage' => acc_account_usage_summary($pdo, $fromId),
        'to_usage' => acc_account_usage_summary($pdo, $toId),
    ];
}

/**
 * نقل قيود وسندات وربط الترحيل من حساب إلى آخر (معاملة واحدة).
 *
 * @param array{deactivate_source?:bool, delete_source?:bool, force_cash_rule?:bool} $options
 * @return array{
 *   ok:bool,
 *   message:string,
 *   journal_lines:int,
 *   vouchers:int,
 *   posting_rules:int,
 *   source_deactivated:bool,
 *   source_deleted:bool
 * }
 */
function acc_account_reassign_all(PDO $pdo, int $fromId, int $toId, array $options = []): array
{
    $preview = acc_account_reassign_preview($pdo, $fromId, $toId);
    $result = [
        'ok' => false,
        'message' => $preview['message'],
        'journal_lines' => 0,
        'vouchers' => 0,
        'posting_rules' => 0,
        'source_deactivated' => false,
        'source_deleted' => false,
    ];
    if (!$preview['ok']) {
        return $result;
    }

    $deactivate = !array_key_exists('deactivate_source', $options) || !empty($options['deactivate_source']);
    $tryDelete = !empty($options['delete_source']);
    $forceCash = !array_key_exists('force_cash_rule', $options) || !empty($options['force_cash_rule']);

    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        $st = $pdo->prepare('UPDATE acc_journal_line SET account_id = ? WHERE account_id = ?');
        $st->execute([$toId, $fromId]);
        $result['journal_lines'] = $st->rowCount();

        try {
            $st = $pdo->prepare('UPDATE fin_voucher SET cash_account_id = ? WHERE cash_account_id = ?');
            $st->execute([$toId, $fromId]);
            $result['vouchers'] = $st->rowCount();
        } catch (Throwable $e) {
            // fin_voucher may not exist
        }

        try {
            $st = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE account_id = ?');
            $st->execute([$toId, $fromId]);
            $result['posting_rules'] = $st->rowCount();
            if ($forceCash) {
                $stCash = $pdo->prepare(
                    "UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'cash'"
                );
                $stCash->execute([$toId]);
                if ($stCash->rowCount() > 0) {
                    $result['posting_rules'] += $stCash->rowCount();
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        if ($deactivate) {
            $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?')->execute([$fromId]);
            $result['source_deactivated'] = true;
        }

        if ($started) {
            $pdo->commit();
            $started = false;
        }

        if ($tryDelete) {
            $chk = acc_account_delete_check($pdo, $fromId);
            if ($chk['can_delete']) {
                try {
                    acc_account_delete($pdo, $fromId);
                    $result['source_deleted'] = true;
                } catch (Throwable $e) {
                    // يبقى معطّلاً
                }
            }
        }

        $result['ok'] = true;
        $result['message'] = sprintf(
            'تم النقل: %d سطر قيد، %d سند، %d ربط ترحيل.',
            $result['journal_lines'],
            $result['vouchers'],
            $result['posting_rules']
        );

        return $result;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['message'] = 'فشل النقل: ' . $e->getMessage();

        return $result;
    }
}

/**
 * دمج حساب الصندوق الافتراضي 111 إلى صندوق رئيسي (1001001001 أو بالاسم).
 *
 * @return array<string, mixed>
 */
function acc_account_merge_default_cash_box(PDO $pdo): array
{
    $to = acc_account_get_by_code($pdo, '1001001001');
    if (!$to) {
        $st = $pdo->query(
            "SELECT * FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1 AND name_ar LIKE '%صندوق رئيسي%'
             ORDER BY id ASC LIMIT 1"
        );
        $to = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }

    $from = acc_account_get_by_code($pdo, '111');
    if (!$from) {
        $st = $pdo->query(
            "SELECT * FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1
               AND (code = '111' OR (name_ar LIKE '%الصندوق%' AND name_ar NOT LIKE '%شيك%'))
             ORDER BY (code = '111') DESC, id ASC LIMIT 1"
        );
        $from = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }

    if (!$to || !$from) {
        return [
            'ok' => false,
            'message' => 'تعذر العثور على حسابي المصدر (111) والهدف (1001001001).',
        ];
    }

    $fromId = (int) $from['id'];
    $toId = (int) $to['id'];
    if ($fromId === $toId) {
        return ['ok' => true, 'message' => 'الحسابان هما نفس الحساب — لا حاجة للدمج.', 'skipped' => true];
    }

    $preview = acc_account_reassign_preview($pdo, $fromId, $toId);
    if (!$preview['ok']) {
        return array_merge(['ok' => false], $preview);
    }

    $merge = acc_account_reassign_all($pdo, $fromId, $toId, [
        'deactivate_source' => true,
        'delete_source' => true,
        'force_cash_rule' => true,
    ]);

    return array_merge($preview, $merge, [
        'from_id' => $fromId,
        'to_id' => $toId,
    ]);
}

/**
 * حساب المشتريات الجديد (6001 — المشتريات).
 *
 * @return array<string, mixed>|null
 */
function acc_account_find_purchases_target_6001(PDO $pdo): ?array
{
    $to = acc_account_get_by_code($pdo, '6001');
    if ($to && (int) ($to['is_leaf'] ?? 0) === 1) {
        return $to;
    }

    try {
        $st = $pdo->query(
            "SELECT * FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1
               AND (
                 code = '6001'
                 OR REPLACE(code, '.', '') = '6001'
                 OR name_ar = 'المشتريات'
               )
             ORDER BY (code = '6001') DESC, id ASC
             LIMIT 1"
        );
        $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * حسابات «مشتريات وتوريدات» القديمة (51 وغيرها) لدمجها في 6001.
 *
 * @return list<int>
 */
function acc_account_find_legacy_purchases_source_ids(PDO $pdo, int $targetId): array
{
    require_once app_path('includes/acc_coa_bootstrap.php');

    $legacyName = acc_coa_normalize_name('مشتريات وتوريدات');
    $ids = [];

    try {
        $rows = $pdo->query(
            'SELECT id, code, name_ar, account_type, is_leaf
             FROM acc_account
             WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || $id === $targetId) {
            continue;
        }
        if ((string) ($row['account_type'] ?? '') !== 'expense') {
            continue;
        }
        if ((int) ($row['is_leaf'] ?? 0) !== 1) {
            continue;
        }

        $nameNorm = acc_coa_normalize_name((string) ($row['name_ar'] ?? ''));
        $digits = acc_account_code_digits((string) ($row['code'] ?? ''));
        $isLegacy = false;

        if ($nameNorm === $legacyName || str_starts_with($nameNorm, $legacyName)) {
            $isLegacy = true;
        }
        if ($digits === '51') {
            $isLegacy = true;
        }
        if ($digits !== '' && str_starts_with($digits, '51') && str_contains($nameNorm, 'مشتريات')) {
            $isLegacy = true;
        }

        if ($isLegacy) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * نقل قيود وربط «مشتريات وتوريدات» إلى حساب 6001 — المشتريات.
 *
 * @return array<string, mixed>
 */
function acc_account_merge_purchases_to_6001(PDO $pdo): array
{
    $to = acc_account_find_purchases_target_6001($pdo);
    if (!$to) {
        return [
            'ok' => false,
            'skipped' => true,
            'message' => 'لم يُعثر على حساب 6001 — المشتريات. أنشئ الحساب ثم أعد تحميل الصفحة.',
        ];
    }

    $toId = (int) ($to['id'] ?? 0);
    if ($toId < 1) {
        return ['ok' => false, 'skipped' => true, 'message' => 'حساب 6001 غير صالح.'];
    }

    $sourceIds = acc_account_find_legacy_purchases_source_ids($pdo, $toId);
    if ($sourceIds === []) {
        try {
            $stPurch = $pdo->prepare(
                "UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'purchases'"
            );
            $stPurch->execute([$toId]);
        } catch (Throwable $e) {
            // ignore
        }

        return [
            'ok' => true,
            'skipped' => true,
            'message' => 'لا حسابات قديمة (مشتريات وتوريدات) للدمج — ربط المشتريات على 6001.',
            'to_id' => $toId,
            'journal_lines' => 0,
            'sources' => [],
        ];
    }

    $totalLines = 0;
    $totalVouchers = 0;
    $totalRules = 0;
    $mergedFrom = [];

    foreach ($sourceIds as $fromId) {
        $from = acc_account_get($pdo, $fromId);
        if (!$from) {
            continue;
        }
        $merge = acc_account_reassign_all($pdo, $fromId, $toId, [
            'deactivate_source' => true,
            'delete_source' => true,
            'force_cash_rule' => false,
        ]);
        if (!$merge['ok']) {
            return array_merge($merge, [
                'skipped' => false,
                'to_id' => $toId,
                'sources' => $mergedFrom,
            ]);
        }
        $totalLines += (int) ($merge['journal_lines'] ?? 0);
        $totalVouchers += (int) ($merge['vouchers'] ?? 0);
        $totalRules += (int) ($merge['posting_rules'] ?? 0);
        $mergedFrom[] = [
            'id' => $fromId,
            'code' => (string) ($from['code'] ?? ''),
            'name_ar' => (string) ($from['name_ar'] ?? ''),
            'journal_lines' => (int) ($merge['journal_lines'] ?? 0),
        ];
    }

    try {
        $stPurch = $pdo->prepare(
            "UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'purchases'"
        );
        $stPurch->execute([$toId]);
        if ($stPurch->rowCount() > 0) {
            $totalRules++;
        }
    } catch (Throwable $e) {
        // ignore
    }

    $fromLabels = [];
    foreach ($mergedFrom as $src) {
        $fromLabels[] = (string) ($src['code'] ?? '') . ' ' . (string) ($src['name_ar'] ?? '');
    }

    return [
        'ok' => true,
        'skipped' => false,
        'message' => sprintf(
            'تم نقل %d سطر قيد من [%s] إلى 6001 — المشتريات (%d سند، %d ربط).',
            $totalLines,
            implode('، ', $fromLabels),
            $totalVouchers,
            $totalRules
        ),
        'to_id' => $toId,
        'journal_lines' => $totalLines,
        'vouchers' => $totalVouchers,
        'posting_rules' => $totalRules,
        'sources' => $mergedFrom,
    ];
}

/**
 * @return list<int>
 */
function acc_account_find_cogs_duplicate_source_ids(PDO $pdo, int $targetId): array
{
    require_once app_path('includes/acc_coa_bootstrap.php');

    $ids = [];
    foreach (acc_coa_find_cogs_candidate_ids($pdo) as $id) {
        if ($id !== $targetId) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * دمج حسابات «تكلفة البضاعة المباعة» المكررة في حساب واحد (الأكثر استخداماً).
 *
 * @return array<string, mixed>
 */
function acc_account_merge_cogs_duplicates(PDO $pdo): array
{
    require_once app_path('includes/acc_coa_bootstrap.php');

    $candidates = acc_coa_find_cogs_candidate_ids($pdo);
    if ($candidates === []) {
        try {
            $index = acc_coa_index_accounts($pdo);
            $canonical = acc_coa_find_digits($index, '54');
            if ($canonical && (int) ($canonical['is_leaf'] ?? 0) === 1) {
                $cid = (int) ($canonical['id'] ?? 0);
                if ($cid > 0) {
                    $candidates = [$cid];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($candidates === []) {
        return [
            'ok' => false,
            'skipped' => true,
            'message' => 'لم يُعثر على حساب تكلفة البضاعة المباعة للدمج.',
        ];
    }

    $toId = acc_coa_pick_cogs_keep_account_id($pdo, $candidates);
    if ($toId < 1) {
        return [
            'ok' => false,
            'skipped' => true,
            'message' => 'لم يُحدَّد حساب تكلفة البضاعة المباعة للاحتفاظ به.',
        ];
    }

    $sourceIds = array_values(array_filter(
        $candidates,
        static fn (int $id): bool => $id !== $toId
    ));

    if ($sourceIds === []) {
        acc_coa_finalize_cogs_account($pdo, $toId);

        return [
            'ok' => true,
            'skipped' => true,
            'message' => 'لا حسابات مكررة لتكلفة البضاعة المباعة — الربط على حساب واحد.',
            'to_id' => $toId,
            'journal_lines' => 0,
            'sources' => [],
        ];
    }

    $totalLines = 0;
    $totalVouchers = 0;
    $totalRules = 0;
    $mergedFrom = [];

    foreach ($sourceIds as $fromId) {
        $from = acc_account_get($pdo, $fromId);
        if (!$from) {
            continue;
        }
        $merge = acc_account_reassign_all($pdo, $fromId, $toId, [
            'deactivate_source' => true,
            'delete_source' => true,
            'force_cash_rule' => false,
        ]);
        if (!$merge['ok']) {
            return array_merge($merge, [
                'message' => 'تعذر دمج حساب «' . (string) ($from['name_ar'] ?? '') . '»: ' . ($merge['message'] ?? ''),
            ]);
        }
        $totalLines += (int) ($merge['journal_lines'] ?? 0);
        $totalVouchers += (int) ($merge['vouchers'] ?? 0);
        $totalRules += (int) ($merge['posting_rules'] ?? 0);
        $mergedFrom[] = (string) ($from['code'] ?? '') . ' ' . (string) ($from['name_ar'] ?? '');
    }

    acc_coa_finalize_cogs_account($pdo, $toId);

    $keep = acc_account_get($pdo, $toId);

    return [
        'ok' => true,
        'skipped' => false,
        'message' => sprintf(
            'تم دمج %d حساباً مكرراً لتكلفة البضاعة المباعة في [%s %s] — %d سطر قيد، %d سند، %d ربط.',
            count($mergedFrom),
            (string) ($keep['code'] ?? ''),
            (string) ($keep['name_ar'] ?? 'تكلفة البضاعة المباعة'),
            $totalLines,
            $totalVouchers,
            $totalRules
        ),
        'to_id' => $toId,
        'journal_lines' => $totalLines,
        'vouchers' => $totalVouchers,
        'posting_rules' => $totalRules,
        'sources' => $mergedFrom,
    ];
}

/**
 * دمج حسابات «رواتب وأجور» المكررة في حساب واحد (الأكثر استخداماً).
 *
 * @return array<string, mixed>
 */
function acc_account_merge_salaries_expense_duplicates(PDO $pdo): array
{
    require_once app_path('includes/acc_coa_bootstrap.php');

    $candidates = acc_coa_find_salaries_expense_candidate_ids($pdo);
    if ($candidates === []) {
        try {
            $index = acc_coa_index_accounts($pdo);
            $canonical = acc_coa_find_digits($index, '52');
            if ($canonical && (int) ($canonical['is_leaf'] ?? 0) === 1) {
                $cid = (int) ($canonical['id'] ?? 0);
                if ($cid > 0) {
                    $candidates = [$cid];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($candidates === []) {
        return [
            'ok' => false,
            'skipped' => true,
            'message' => 'لم يُعثر على حساب رواتب وأجور للدمج.',
        ];
    }

    $toId = acc_coa_pick_salaries_expense_keep_account_id($pdo, $candidates);
    if ($toId < 1) {
        return [
            'ok' => false,
            'skipped' => true,
            'message' => 'لم يُحدَّد حساب رواتب وأجور للاحتفاظ به.',
        ];
    }

    $sourceIds = array_values(array_filter(
        $candidates,
        static fn (int $id): bool => $id !== $toId
    ));

    if ($sourceIds === []) {
        acc_coa_finalize_salaries_expense_account($pdo, $toId);

        return [
            'ok' => true,
            'skipped' => true,
            'message' => 'لا حسابات مكررة لرواتب وأجور — الربط على حساب واحد.',
            'to_id' => $toId,
            'journal_lines' => 0,
            'sources' => [],
        ];
    }

    $totalLines = 0;
    $totalVouchers = 0;
    $totalRules = 0;
    $mergedFrom = [];

    foreach ($sourceIds as $fromId) {
        $from = acc_account_get($pdo, $fromId);
        if (!$from) {
            continue;
        }
        $merge = acc_account_reassign_all($pdo, $fromId, $toId, [
            'deactivate_source' => true,
            'delete_source' => true,
            'force_cash_rule' => false,
        ]);
        if (!$merge['ok']) {
            return array_merge($merge, [
                'message' => 'تعذر دمج حساب «' . (string) ($from['name_ar'] ?? '') . '»: ' . ($merge['message'] ?? ''),
            ]);
        }
        $totalLines += (int) ($merge['journal_lines'] ?? 0);
        $totalVouchers += (int) ($merge['vouchers'] ?? 0);
        $totalRules += (int) ($merge['posting_rules'] ?? 0);
        $mergedFrom[] = (string) ($from['code'] ?? '') . ' ' . (string) ($from['name_ar'] ?? '');
    }

    acc_coa_finalize_salaries_expense_account($pdo, $toId);

    $keep = acc_account_get($pdo, $toId);

    return [
        'ok' => true,
        'skipped' => false,
        'message' => sprintf(
            'تم دمج %d حساباً مكرراً لرواتب وأجور في [%s %s] — %d سطر قيد، %d سند، %d ربط.',
            count($mergedFrom),
            (string) ($keep['code'] ?? ''),
            (string) ($keep['name_ar'] ?? 'رواتب وأجور'),
            $totalLines,
            $totalVouchers,
            $totalRules
        ),
        'to_id' => $toId,
        'journal_lines' => $totalLines,
        'vouchers' => $totalVouchers,
        'posting_rules' => $totalRules,
        'sources' => $mergedFrom,
    ];
}
