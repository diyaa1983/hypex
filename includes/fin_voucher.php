<?php
declare(strict_types=1);

function fin_voucher_has_table(PDO $pdo): bool
{
    static $ok = false;
    static $checked = false;
    if ($checked) {
        return $ok;
    }
    $checked = true;
    try {
        $pdo->query('SELECT id FROM fin_voucher LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function fin_voucher_has_check_no(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!fin_voucher_has_table($pdo)) {
        $ok = false;

        return false;
    }
    try {
        $pdo->query('SELECT check_no FROM fin_voucher LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function fin_voucher_ensure_check_no_column(PDO $pdo): void
{
    if (!fin_voucher_has_table($pdo) || fin_voucher_has_check_no($pdo)) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE fin_voucher ADD COLUMN check_no VARCHAR(80) NULL AFTER description');
    } catch (Throwable $e) {
        // عمود موجود أو صلاحية ناقصة
    }
}

function fin_voucher_ensure_schema(PDO $pdo): bool
{
    if (fin_voucher_has_table($pdo)) {
        fin_voucher_ensure_check_no_column($pdo);
        require_once app_path('includes/fin_voucher_schema.php');
        fin_voucher_ensure_receipt_columns($pdo);
        require_once app_path('includes/acc_journal.php');
        acc_journal_ensure_schema($pdo);

        return true;
    }

    require_once app_path('includes/sql_migration.php');
    require_once app_path('includes/acc_journal.php');
    acc_journal_ensure_schema($pdo);
    sql_migration_run_file($pdo, 'database/migrations/027_fin_voucher.sql');
    fin_voucher_ensure_check_no_column($pdo);
    require_once app_path('includes/fin_voucher_schema.php');
    fin_voucher_ensure_receipt_columns($pdo);
    fin_voucher_ensure_cancel_columns($pdo);

    return fin_voucher_has_table($pdo);
}

function fin_voucher_type_valid(string $type): bool
{
    return $type === 'receipt' || $type === 'payment';
}

function fin_voucher_normalize_pay_method(string $method): string
{
    $method = strtolower(trim($method));
    if ($method === 'check') {
        return 'check';
    }
    if ($method === 'bank') {
        return 'bank';
    }

    return 'cash';
}

function fin_voucher_pay_method_label(string $method): string
{
    return match (fin_voucher_normalize_pay_method($method)) {
        'check' => 'شيك',
        'bank' => 'بنك',
        default => 'نقداً',
    };
}

function fin_voucher_next_no(PDO $pdo, string $type, string $voucherDate): string
{
    require_once app_path('includes/doc_sequence.php');
    require_once app_path('includes/doc_number_pool.php');

    return doc_seq_generate_next_no(
        $pdo,
        'fin_voucher',
        'voucher_no',
        $voucherDate,
        'voucher_type = ?',
        [$type],
        doc_number_pool_key_fin_voucher($type)
    );
}

/**
 * حسابات يمكن الصرف منها (نقدية، بنك، جاري شريك، حصة شريك…).
 *
 * @return list<array{id:int, code:string, name_ar:string, group_key:string, group_label:string}>
 */
function fin_voucher_load_cash_accounts(PDO $pdo): array
{
    require_once app_path('includes/acc_journal.php');
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    require_once app_path('includes/acc_gl.php');
    $settings = acc_gl_is_ready($pdo) ? acc_gl_load_settings($pdo) : [];
    $extraIds = [];
    foreach (['cash', 'bank'] as $rule) {
        $aid = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($aid > 0) {
            $extraIds[$aid] = true;
        }
    }

    $rows = $pdo->query(
        "SELECT a.id, a.code, a.name_ar, a.parent_id, p.name_ar AS parent_name_ar
         FROM acc_account a
         LEFT JOIN acc_account p ON p.id = a.parent_id
         WHERE a.is_active = 1 AND a.is_leaf = 1
         ORDER BY a.code ASC, a.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byId = [];
    foreach (
        $pdo->query('SELECT id, parent_id FROM acc_account WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r
    ) {
        $aid = (int) ($r['id'] ?? 0);
        if ($aid > 0) {
            $byId[$aid] = [
                'parent_id' => ($r['parent_id'] ?? null) !== null ? (int) $r['parent_id'] : null,
            ];
        }
    }

    require_once app_path('includes/acc_coa_bootstrap.php');
    $liquidityParentId = acc_coa_find_liquidity_parent_id($pdo);
    $isUnder = static function (int $accountId, ?int $ancestorId) use (&$byId): bool {
        if ($accountId < 1 || $ancestorId === null || $ancestorId < 1) {
            return false;
        }
        $cur = $accountId;
        $guard = 0;
        while ($cur > 0 && $guard < 2000) {
            if ($cur === $ancestorId) {
                return true;
            }
            $parent = $byId[$cur]['parent_id'] ?? null;
            $cur = $parent !== null ? (int) $parent : 0;
            $guard++;
        }

        return false;
    };

    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $name = (string) ($row['name_ar'] ?? '');
        $parentName = (string) ($row['parent_name_ar'] ?? '');
        $isLiquid = $isUnder($id, $liquidityParentId) || isset($extraIds[$id]);
        $isPartnerLike = str_contains($name, 'شريك')
            || str_contains($name, 'جاري')
            || str_contains($parentName, 'شريك')
            || str_contains($parentName, 'حصة');
        if (!$isLiquid && !$isPartnerLike && !isset($extraIds[$id])) {
            continue;
        }
        $gk = $isLiquid ? 'liquid' : 'partner';
        $out[] = [
            'id' => $id,
            'code' => (string) ($row['code'] ?? ''),
            'name_ar' => $name,
            'group_key' => $gk,
            'group_label' => $gk === 'partner' ? 'حسابات الشركاء / الجاري' : 'الصندوق والبنوك',
        ];
    }

    return $out;
}

/**
 * حسابات الصرف في سند الصرف: الصناديق، صندوق الشيكات، والبنوك (حسب شجرة الحسابات).
 *
 * @return list<array{id:int, code:string, name_ar:string, group_key:string, group_label:string}>
 */
function fin_voucher_load_cash_bank_accounts(PDO $pdo): array
{
    require_once app_path('includes/acc_journal.php');
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_coa_bootstrap.php');
    require_once app_path('includes/acc_account_tree.php');

    $settings = acc_gl_is_ready($pdo) ? acc_gl_load_settings($pdo) : [];
    $forceIds = [];
    foreach (['cash', 'bank', 'checks_fund'] as $rule) {
        $aid = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($aid > 0) {
            $forceIds[$aid] = true;
        }
    }
    $cashBoxId = acc_gl_cash_box_account_id($pdo);
    if ($cashBoxId > 0) {
        $forceIds[$cashBoxId] = true;
    }
    $checksFundId = acc_gl_checks_fund_account_id($pdo);
    if ($checksFundId > 0) {
        $forceIds[$checksFundId] = true;
    }

    $rows = $pdo->query(
        "SELECT a.id, a.code, a.name_ar, a.parent_id, p.name_ar AS parent_name_ar
         FROM acc_account a
         LEFT JOIN acc_account p ON p.id = a.parent_id
         WHERE a.is_active = 1 AND a.is_leaf = 1
         ORDER BY a.code ASC, a.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byId = [];
    foreach (
        $pdo->query('SELECT id, parent_id FROM acc_account WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r
    ) {
        $aid = (int) ($r['id'] ?? 0);
        if ($aid > 0) {
            $byId[$aid] = [
                'parent_id' => ($r['parent_id'] ?? null) !== null ? (int) $r['parent_id'] : null,
            ];
        }
    }

    $cashGroupIds = acc_coa_find_cash_group_parent_ids($pdo);
    $banksGroupIds = acc_coa_find_banks_group_parent_ids($pdo);
    $liquidityParentId = acc_coa_find_liquidity_parent_id($pdo);

    $isUnder = static function (int $accountId, ?int $ancestorId) use (&$byId): bool {
        if ($accountId < 1 || $ancestorId === null || $ancestorId < 1) {
            return false;
        }
        $cur = $accountId;
        $guard = 0;
        while ($cur > 0 && $guard < 2000) {
            if ($cur === $ancestorId) {
                return true;
            }
            $parent = $byId[$cur]['parent_id'] ?? null;
            $cur = $parent !== null ? (int) $parent : 0;
            $guard++;
        }

        return false;
    };

    $isUnderAny = static function (int $accountId, array $ancestorIds) use ($isUnder): bool {
        foreach ($ancestorIds as $ancestorId) {
            if ($isUnder($accountId, (int) $ancestorId)) {
                return true;
            }
        }

        return false;
    };

    $isChecksFundLeaf = static function (int $accountId, string $name, string $parentName, string $code) use ($checksFundId): bool {
        if ($checksFundId > 0 && $accountId === $checksFundId) {
            return true;
        }
        if (preg_match('/صندوق\s*الشيكات|شيكات\s*تحت/u', $name)) {
            return true;
        }
        if (preg_match('/\bشيكات\b/u', $parentName) && preg_match('/صندوق|شيك/u', $name)) {
            return true;
        }
        $digits = acc_account_code_digits($code);

        return $digits !== '' && in_array($digits, ['113', '1001001002', '1001002002'], true);
    };

    $resolveGroup = static function (
        int $accountId,
        string $code,
        string $name,
        string $parentName
    ) use (
        $isUnderAny,
        $cashGroupIds,
        $banksGroupIds,
        $liquidityParentId,
        $isUnder,
        $isChecksFundLeaf
    ): ?string {
        if ($isChecksFundLeaf($accountId, $name, $parentName, $code)) {
            return 'checks';
        }
        if ($isUnderAny($accountId, $banksGroupIds)) {
            return 'bank';
        }
        if ($isUnderAny($accountId, $cashGroupIds)) {
            return 'cash';
        }

        $digits = acc_account_code_digits($code);
        if ($digits !== '' && str_starts_with($digits, '1001003')) {
            return 'bank';
        }
        if ($digits !== '' && str_starts_with($digits, '1001002')) {
            return 'cash';
        }

        $legacyCash = in_array($digits, ['111', '1001001001', '1001002001'], true);
        $legacyBank = in_array($digits, ['112', '1001003001', '1001003004'], true);
        if ($legacyBank) {
            return 'bank';
        }
        if ($legacyCash) {
            return 'cash';
        }

        $hay = $name . ' ' . $parentName;
        if ($liquidityParentId !== null && $isUnder($accountId, $liquidityParentId)) {
            if (preg_match('/بنك|bank|مصرف/u', $hay)) {
                return 'bank';
            }
            if (preg_match('/صندوق|نقد|cash|خزينة/u', $hay)) {
                return 'cash';
            }
        }

        return null;
    };

    $groupLabels = [
        'cash' => 'الصناديق',
        'checks' => 'صندوق الشيكات',
        'bank' => 'البنوك',
    ];
    $groupSort = [
        'cash' => 10,
        'checks' => 15,
        'bank' => 20,
    ];

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }

        $name = (string) ($row['name_ar'] ?? '');
        $parentName = (string) ($row['parent_name_ar'] ?? '');
        $code = (string) ($row['code'] ?? '');

        if (preg_match('/شريك|جاري|حصة/u', $name . ' ' . $parentName)) {
            continue;
        }

        $groupKey = $resolveGroup($id, $code, $name, $parentName);
        if ($groupKey === null && !isset($forceIds[$id])) {
            continue;
        }
        if ($groupKey === null) {
            $groupKey = preg_match('/بنك|bank|مصرف/u', $name . ' ' . $parentName) ? 'bank' : 'cash';
            if ($isChecksFundLeaf($id, $name, $parentName, $code)) {
                $groupKey = 'checks';
            }
        }

        $seen[$id] = true;
        $out[] = [
            'id' => $id,
            'code' => $code,
            'name_ar' => $name,
            'group_key' => $groupKey,
            'group_label' => $groupLabels[$groupKey] ?? 'الصناديق',
            'sort_order' => $groupSort[$groupKey] ?? 99,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $sa = (int) ($a['sort_order'] ?? 0);
        $sb = (int) ($b['sort_order'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
    });

    return $out;
}

/**
 * @param list<array{id:int, code:string, name_ar:string, group_key?:string, group_label?:string}> $accounts
 * @return list<array{id:int, code:string, name_ar:string, group_key:string, group_label:string}>
 */
function fin_voucher_deduct_accounts_ensure_saved(PDO $pdo, array $accounts, int $savedAccountId = 0): array
{
    if ($savedAccountId < 1) {
        return $accounts;
    }
    foreach ($accounts as $acc) {
        if ((int) ($acc['id'] ?? 0) === $savedAccountId) {
            return $accounts;
        }
    }

    $st = $pdo->prepare(
        'SELECT id, code, name_ar FROM acc_account WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([$savedAccountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $accounts;
    }

    $accounts[] = [
        'id' => (int) ($row['id'] ?? 0),
        'code' => (string) ($row['code'] ?? ''),
        'name_ar' => (string) ($row['name_ar'] ?? ''),
        'group_key' => 'saved',
        'group_label' => 'حساب محفوظ سابقاً',
    ];

    return $accounts;
}

/** @return array<string, mixed>|null */
function fin_voucher_load(PDO $pdo, int $id, string $type): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM fin_voucher WHERE id = ? AND voucher_type = ? LIMIT 1');
    $st->execute([$id, $type]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fin_voucher_party_name(PDO $pdo, string $partyType, int $partyId): string
{
    if ($partyId < 1) {
        return '';
    }
    if ($partyType === 'customer') {
        $st = $pdo->prepare('SELECT name_ar FROM crm_customer WHERE id = ? LIMIT 1');
    } elseif ($partyType === 'supplier') {
        $st = $pdo->prepare('SELECT name_ar FROM crm_supplier WHERE id = ? LIMIT 1');
    } else {
        return '';
    }
    $st->execute([$partyId]);
    $name = $st->fetchColumn();

    return $name !== false ? (string) $name : '';
}

/**
 * @param list<array<string, mixed>>|null $checks قائمة شيكات اختيارية:
 *   - null: لا يُمَس جدول الشيكات الفرعي.
 *   - [] : يُحذف ما كان من شيكات للسند.
 *   - [...]: تُستبدل الشيكات بالقائمة الممرَّرة.
 */
function fin_voucher_save(
    PDO $pdo,
    string $type,
    int $id,
    string $voucherNo,
    string $voucherDate,
    float $amount,
    string $description,
    string $checkNo,
    string $partyType,
    int $partyId,
    int $cashAccountId,
    string $payMethod = 'cash',
    float $checkAmount = 0.0,
    string $bankName = '',
    ?array $checks = null,
    int $offsetAccountId = 0
): int {
    if (!fin_voucher_type_valid($type)) {
        throw new RuntimeException('نوع السند غير صالح.');
    }

    $payMethod = fin_voucher_normalize_pay_method($payMethod);

    require_once app_path('includes/fin_voucher_checks.php');
    if ($payMethod === 'check' && is_array($checks) && $checks !== []) {
        $totalChecks = fin_voucher_checks_total($checks);
        if ($totalChecks > 0) {
            $amount = $totalChecks;
            $checkAmount = $totalChecks;
            $firstChk = $checks[0] ?? [];
            if ($checkNo === '') {
                $checkNo = trim((string) ($firstChk['check_no'] ?? ''));
            }
            if ($bankName === '') {
                $bankName = trim((string) ($firstChk['bank_name'] ?? ''));
            }
        }
    } elseif ($payMethod === 'check' && $checkAmount > 0) {
        $amount = $checkAmount;
    }
    if ($amount <= 0) {
        throw new RuntimeException(
            $payMethod === 'check' ? 'أدخل قيمة الشيك.' : 'المبلغ يجب أن يكون أكبر من صفر.'
        );
    }
    if ($cashAccountId < 1) {
        throw new RuntimeException('اختر حساب الصندوق/البنك.');
    }

    $partyType = in_array($partyType, ['customer', 'supplier', 'employee', 'account', 'other'], true)
        ? $partyType
        : 'other';
    if ($partyType === 'account') {
        if ($offsetAccountId < 1) {
            throw new RuntimeException('اختر الحساب المُصروف إليه.');
        }
        $partyId = $offsetAccountId;
    } elseif ($partyType === 'employee') {
        if ($partyId < 1) {
            throw new RuntimeException('اختر الموظف.');
        }
        if ($offsetAccountId < 1) {
            throw new RuntimeException('اختر حساب الالتزام (رواتب مستحقة / سلف…).');
        }
    } elseif ($partyType === 'other') {
        $partyId = 0;
        $offsetAccountId = 0;
    } elseif ($partyId < 1) {
        throw new RuntimeException($partyType === 'customer' ? 'اختر العميل.' : 'اختر المورد.');
    } else {
        $offsetAccountId = 0;
    }

    if ($id > 0) {
        require_once app_path('includes/fin_voucher_schema.php');
        if (fin_voucher_is_cancelled($pdo, $id)) {
            throw new RuntimeException('لا يمكن تعديل سند ملغى.');
        }
        if (fin_voucher_is_posted($pdo, $id)) {
            throw new RuntimeException('لا يمكن تعديل سند مرحّل.');
        }
    }

    $userProvidedNo = trim($voucherNo) !== '';
    $typeLabel = $type === 'receipt' ? 'قبض' : 'صرف';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        if ($voucherNo === '') {
            $voucherNo = fin_voucher_next_no($pdo, $type, $voucherDate);
        }

        if ($id > 0) {
            $chk = $pdo->prepare(
                'SELECT id FROM fin_voucher WHERE voucher_no = ? AND voucher_type = ? AND id <> ? LIMIT 1'
            );
            $chk->execute([$voucherNo, $type, $id]);
        } else {
            $chk = $pdo->prepare(
                'SELECT id FROM fin_voucher WHERE voucher_no = ? AND voucher_type = ? LIMIT 1'
            );
            $chk->execute([$voucherNo, $type]);
        }
        if (!$chk->fetch()) {
            break;
        }
        if ($id > 0 || $userProvidedNo) {
            throw new RuntimeException(
                'رقم السند مستخدم مسبقاً لسند ' . $typeLabel . ' آخر. اترك الحقل فارغاً ليُولَّد رقم جديد، أو غيّر الرقم.'
            );
        }
        $voucherNo = '';
    }

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $hasCheck = fin_voucher_has_check_no($pdo);
    require_once app_path('includes/fin_voucher_schema.php');
    $hasExt = fin_voucher_has_column($pdo, 'pay_method');

    if ($id > 0) {
        if ($hasExt) {
            $pdo->prepare(
                'UPDATE fin_voucher SET voucher_no=?, voucher_date=?, amount=?, description=?, check_no=?,
                 party_type=?, party_id=?, cash_account_id=?, pay_method=?, check_amount=?, bank_name=?
                 WHERE id=? AND voucher_type=?'
            )->execute([
                $voucherNo,
                $voucherDate,
                round($amount, 6),
                $description !== '' ? $description : null,
                $checkNo !== '' ? $checkNo : null,
                $partyType,
                $partyId > 0 ? $partyId : null,
                $cashAccountId,
                $payMethod,
                $payMethod === 'check' && $checkAmount > 0 ? round($checkAmount, 6) : null,
                $bankName !== '' ? $bankName : null,
                $id,
                $type,
            ]);
        } elseif ($hasCheck) {
            $pdo->prepare(
                'UPDATE fin_voucher SET voucher_no=?, voucher_date=?, amount=?, description=?, check_no=?,
                 party_type=?, party_id=?, cash_account_id=? WHERE id=? AND voucher_type=?'
            )->execute([
                $voucherNo,
                $voucherDate,
                round($amount, 6),
                $description !== '' ? $description : null,
                $checkNo !== '' ? $checkNo : null,
                $partyType,
                $partyId > 0 ? $partyId : null,
                $cashAccountId,
                $id,
                $type,
            ]);
        } else {
            $pdo->prepare(
                'UPDATE fin_voucher SET voucher_no=?, voucher_date=?, amount=?, description=?,
                 party_type=?, party_id=?, cash_account_id=? WHERE id=? AND voucher_type=?'
            )->execute([
                $voucherNo,
                $voucherDate,
                round($amount, 6),
                $description !== '' ? $description : null,
                $partyType,
                $partyId > 0 ? $partyId : null,
                $cashAccountId,
                $id,
                $type,
            ]);
        }

        require_once app_path('includes/fin_voucher_checks.php');
        if (is_array($checks)) {
            if ($payMethod === 'check') {
                fin_voucher_checks_replace($pdo, $id, $checks);
            } else {
                fin_voucher_checks_replace($pdo, $id, []);
            }
        }

        fin_voucher_save_apply_offset_account($pdo, $id, $offsetAccountId);

        return $id;
    }

    if ($hasExt) {
        $pdo->prepare(
            'INSERT INTO fin_voucher (voucher_type, voucher_no, voucher_date, amount, description, check_no,
             party_type, party_id, cash_account_id, pay_method, check_amount, bank_name, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $type,
            $voucherNo,
            $voucherDate,
            round($amount, 6),
            $description !== '' ? $description : null,
            $checkNo !== '' ? $checkNo : null,
            $partyType,
            $partyId > 0 ? $partyId : null,
            $cashAccountId,
            $payMethod,
            $payMethod === 'check' && $checkAmount > 0 ? round($checkAmount, 6) : null,
            $bankName !== '' ? $bankName : null,
            $uid,
        ]);
    } elseif ($hasCheck) {
        $pdo->prepare(
            'INSERT INTO fin_voucher (voucher_type, voucher_no, voucher_date, amount, description, check_no,
             party_type, party_id, cash_account_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $type,
            $voucherNo,
            $voucherDate,
            round($amount, 6),
            $description !== '' ? $description : null,
            $checkNo !== '' ? $checkNo : null,
            $partyType,
            $partyId > 0 ? $partyId : null,
            $cashAccountId,
            $uid,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO fin_voucher (voucher_type, voucher_no, voucher_date, amount, description,
             party_type, party_id, cash_account_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $type,
            $voucherNo,
            $voucherDate,
            round($amount, 6),
            $description !== '' ? $description : null,
            $partyType,
            $partyId > 0 ? $partyId : null,
            $cashAccountId,
            $uid,
        ]);
    }

    $newId = (int) $pdo->lastInsertId();

    require_once app_path('includes/fin_voucher_checks.php');
    if (is_array($checks) && $payMethod === 'check' && $checks !== [] && $newId > 0) {
        fin_voucher_checks_replace($pdo, $newId, $checks);
    }

    if ($newId > 0) {
        fin_voucher_save_apply_offset_account($pdo, $newId, $offsetAccountId);
    }

    return $newId;
}

function fin_voucher_save_apply_offset_account(PDO $pdo, int $voucherId, int $offsetAccountId): void
{
    if ($voucherId < 1) {
        return;
    }
    require_once app_path('includes/fin_voucher_schema.php');
    if (!fin_voucher_has_column($pdo, 'offset_account_id')) {
        return;
    }
    $pdo->prepare('UPDATE fin_voucher SET offset_account_id = ? WHERE id = ?')->execute([
        $offsetAccountId > 0 ? $offsetAccountId : null,
        $voucherId,
    ]);
}

function fin_voucher_delete(PDO $pdo, int $id, string $type): void
{
    require_once app_path('includes/fin_voucher_schema.php');
    require_once app_path('includes/fin_voucher_unpost.php');
    require_once app_path('includes/doc_number_pool.php');
    if (fin_voucher_is_cancelled($pdo, $id)) {
        throw new RuntimeException('لا يمكن حذف سند ملغى. يبقى في السجل للحفاظ على التسلسل.');
    }
    if (fin_voucher_is_posted($pdo, $id) || fin_voucher_has_posting_artifacts($pdo, $id, $type)) {
        throw new RuntimeException('لا يمكن حذف سند مرحّل. استخدم «إلغاء السند» للحفاظ على رقم التسلسل.');
    }

    $row = fin_voucher_load($pdo, $id, $type);
    if (!$row) {
        throw new RuntimeException('السند غير موجود.');
    }
    $voucherNo = trim((string) ($row['voucher_no'] ?? ''));
    $voucherDate = (string) ($row['voucher_date'] ?? date('Y-m-d'));

    if ($type === 'receipt') {
        require_once app_path('includes/crm_customer_ledger.php');
        crm_ledger_unpost_cash_receipt($pdo, $id);
    } elseif ($type === 'payment') {
        require_once app_path('includes/crm_customer_ledger.php');
        require_once app_path('includes/crm_supplier_ledger.php');
        crm_ledger_unpost_cash_payment($pdo, $id);
        crm_supplier_ledger_unpost_cash_payment($pdo, $id);
    }
    require_once app_path('includes/fin_voucher_checks.php');
    if (fin_voucher_checks_has_table($pdo)) {
        try {
            $pdo->prepare('DELETE FROM fin_voucher_check WHERE voucher_id = ?')->execute([$id]);
        } catch (Throwable $e) {
            // تجاهل
        }
    }
    if ($type === 'payment') {
        require_once app_path('includes/hr_employee_advance.php');
        require_once app_path('includes/hr_salary.php');
        hr_employee_advance_clear_disbursement_by_voucher($pdo, $id);
        hr_salary_clear_disbursement_by_voucher($pdo, $id);
    }
    $st = $pdo->prepare('DELETE FROM fin_voucher WHERE id = ? AND voucher_type = ?');
    $st->execute([$id, $type]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('السند غير موجود.');
    }
    if ($voucherNo !== '') {
        doc_number_pool_release($pdo, doc_number_pool_key_fin_voucher($type), $voucherNo, $voucherDate);
    }
}
