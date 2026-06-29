<?php
declare(strict_types=1);

require_once app_path('includes/acc_journal.php');

/** الحد الأدنى لطول رقم الحساب المعروض (6 أصفار بادئة على الأقل). */
const ACC_ACCOUNT_CODE_MIN_LENGTH = 8;

/** أرقام فقط من رقم الحساب. */
function acc_account_code_digits(string $code): string
{
    return preg_replace('/\D/', '', $code) ?? '';
}

/** رقم حساب بصيغة رقمية أساسية (إزالة أصفار البداية مع إبقاء 0 عند الفراغ). */
function acc_account_code_canonical_digits(string $code): string
{
    $digits = acc_account_code_digits($code);
    if ($digits === '') {
        return '';
    }
    $trimmed = ltrim($digits, '0');

    return $trimmed === '' ? '0' : $trimmed;
}

/** تنسيق رقم الحساب للعرض بدون أصفار بادئة. */
function acc_account_format_code(string $code): string
{
    $digits = acc_account_code_canonical_digits($code);
    if ($digits === '') {
        return '';
    }
    return $digits;
}

/**
 * هل يصلح الحساب كهدف لربط الترحيل؟
 * يرفض الأكواد القديمة (11/12/13/15…) ويقبل الحسابات الهرمية والمعتمدة (مثل 23 رواتب مستحقة).
 */
function acc_account_is_valid_posting_mapping_target(PDO $pdo, int $accountId): bool
{
    if ($accountId < 1) {
        return false;
    }
    $acc = acc_account_get($pdo, $accountId);
    if (!$acc || (int) ($acc['is_active'] ?? 0) !== 1 || (int) ($acc['is_leaf'] ?? 0) !== 1) {
        return false;
    }

    $digits = acc_account_code_canonical_digits((string) ($acc['code'] ?? ''));
    if ($digits === '') {
        return false;
    }

    $legacyReject = ['11', '12', '13', '15', '112'];
    if (in_array($digits, $legacyReject, true)) {
        return false;
    }

    if (strlen($digits) > 3) {
        return true;
    }

    $parentId = (int) ($acc['parent_id'] ?? 0);
    if ($parentId < 1) {
        return false;
    }
    $parent = acc_account_get($pdo, $parentId);
    if (!$parent || (int) ($parent['is_active'] ?? 0) !== 1) {
        return false;
    }
    $parentDigits = acc_account_code_canonical_digits((string) ($parent['code'] ?? ''));
    if (in_array($parentDigits, $legacyReject, true)) {
        return false;
    }

    return in_array($parentDigits, ['1', '2', '3', '4', '5'], true);
}

/** @return array<string, string> */
function acc_account_type_labels(): array
{
    return [
        'asset' => 'أصول',
        'liability' => 'خصوم',
        'equity' => 'حقوق ملكية',
        'revenue' => 'إيرادات',
        'expense' => 'مصروفات',
    ];
}

function acc_account_type_label(string $type): string
{
    return acc_account_type_labels()[$type] ?? $type;
}

/** @return array<string, string> لألوان الشجرة حسب النوع */
function acc_account_type_tone_class(string $type): string
{
    $map = [
        'asset' => 'coa-tone--asset',
        'liability' => 'coa-tone--liability',
        'equity' => 'coa-tone--equity',
        'revenue' => 'coa-tone--revenue',
        'expense' => 'coa-tone--expense',
    ];

    return $map[$type] ?? 'coa-tone--default';
}

function acc_account_ensure_schema(PDO $pdo): bool
{
    return acc_journal_ensure_schema($pdo);
}

/** @return array<string, mixed>|null */
function acc_account_get(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM acc_account WHERE id = ? LIMIT 1');
    $st->execute([$id]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function acc_account_code_exists(PDO $pdo, string $code): bool
{
    $st = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? LIMIT 1');
    $st->execute([$code]);

    return (bool) $st->fetch();
}

function acc_account_next_code(PDO $pdo, ?int $parentId): string
{
    if ($parentId === null || $parentId < 1) {
        $max = 0;
        foreach (
            $pdo->query('SELECT code FROM acc_account WHERE parent_id IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $raw
        ) {
            $c = trim((string) $raw);
            if ($c !== '' && ctype_digit($c)) {
                $max = max($max, (int) $c);
            }
        }
        for ($n = $max + 1; $n <= $max + 999; $n++) {
            $code = (string) $n;
            if (!acc_account_code_exists($pdo, $code)) {
                return $code;
            }
        }

        throw new RuntimeException('تعذر توليد رقم حساب رئيسي فريد.');
    }

    $parent = acc_account_get($pdo, $parentId);
    if (!$parent) {
        throw new RuntimeException('الحساب الأب غير موجود.');
    }
    $parentCode = (string) $parent['code'];
    $parentDigits = acc_account_code_canonical_digits($parentCode);
    if ($parentDigits === '') {
        throw new RuntimeException('رقم الحساب الأب غير صالح.');
    }
    $st = $pdo->prepare('SELECT code FROM acc_account WHERE parent_id = ?');
    $st->execute([$parentId]);
    $maxSuffix = 0;
    $prefixLen = strlen($parentDigits);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $digits = acc_account_code_canonical_digits((string) $code);
        if ($prefixLen > 0 && str_starts_with($digits, $parentDigits)) {
            $suffix = substr($digits, $prefixLen);
            // ترقيم هرمي ثابت: كل مستوى يضيف 3 خانات (001، 002...).
            if (strlen($suffix) === 3 && ctype_digit($suffix)) {
                $maxSuffix = max($maxSuffix, (int) $suffix);
            }
        }
    }

    for ($s = $maxSuffix + 1; $s <= 999; $s++) {
        $candidate = $parentDigits . str_pad((string) $s, 3, '0', STR_PAD_LEFT);
        if (!acc_account_code_exists($pdo, $candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('تعذر توليد رقم حساب فرعي فريد — جرّب لاحقاً أو راجع الشجرة.');
}

function acc_account_next_sort_order(PDO $pdo, ?int $parentId): int
{
    if ($parentId === null || $parentId < 1) {
        $st = $pdo->query('SELECT IFNULL(MAX(sort_order), 0) FROM acc_account WHERE parent_id IS NULL');
    } else {
        $st = $pdo->prepare('SELECT IFNULL(MAX(sort_order), 0) FROM acc_account WHERE parent_id = ?');
        $st->execute([$parentId]);
    }

    return ((int) $st->fetchColumn()) + 10;
}

/**
 * إعادة ترقيم شجرة الحسابات هرمياً بدون تغيير المعرفات (id).
 *
 * النمط:
 * - المستوى الرئيسي: 1، 2، 3...
 * - كل مستوى فرعي يضيف 3 خانات: 001، 002...
 *   مثال: 1 -> 1001 -> 1001001 -> 1001001001
 *
 * @return int عدد الحسابات التي تم تغيير رقمها
 */
function acc_account_recode_hierarchy(PDO $pdo): int
{
    $rows = $pdo->query(
        'SELECT id, parent_id, sort_order, code
         FROM acc_account
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return 0;
    }

    $children = [];
    $currentCodes = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $pid = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        if (!isset($children[$pid])) {
            $children[$pid] = [];
        }
        $children[$pid][] = $id;
        $currentCodes[$id] = (string) ($row['code'] ?? '');
    }

    $newCodes = [];
    $walk = static function (int $parentId, string $parentCode) use (&$walk, &$children, &$newCodes): void {
        $ids = $children[$parentId] ?? [];
        $i = 0;
        foreach ($ids as $id) {
            $i++;
            if ($i > 999) {
                throw new RuntimeException('عدد الفروع تحت حساب واحد تجاوز 999 فرعاً.');
            }
            $code = $parentCode === '' ? (string) $i : ($parentCode . str_pad((string) $i, 3, '0', STR_PAD_LEFT));
            if (strlen($code) > 32) {
                throw new RuntimeException('رقم الحساب الناتج يتجاوز الحد الأقصى المسموح (32 خانة).');
            }
            $newCodes[$id] = $code;
            $walk($id, $code);
        }
    };
    $walk(0, '');

    $changed = [];
    foreach ($newCodes as $id => $code) {
        if (($currentCodes[$id] ?? '') !== $code) {
            $changed[$id] = $code;
        }
    }
    if ($changed === []) {
        return 0;
    }

    $pdo->beginTransaction();
    try {
        $tmpSt = $pdo->prepare('UPDATE acc_account SET code = ? WHERE id = ?');
        foreach (array_keys($changed) as $id) {
            $tmpSt->execute(['TMP' . $id, $id]);
        }
        $setSt = $pdo->prepare('UPDATE acc_account SET code = ? WHERE id = ?');
        foreach ($changed as $id => $code) {
            $setSt->execute([$code, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return count($changed);
}

/** @return list<array<string, mixed>> */
function acc_account_load_all(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT id, code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order
            FROM acc_account';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY code ASC, sort_order ASC, id ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function acc_account_index_by_id(array $rows): array
{
    $byId = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $row;
        }
    }

    return $byId;
}

/**
 * مفتاح الأب في الشجرة — إن كان الأب غير موجود (معطّل/محذوف) تُعرَض الحسابات اليتيمة تحت الجذر.
 *
 * @param array<string, mixed> $row
 * @param array<int, array<string, mixed>> $byId
 */
function acc_account_tree_parent_key(array $row, array $byId): int
{
    $pid = $row['parent_id'] === null || $row['parent_id'] === '' ? 0 : (int) $row['parent_id'];
    if ($pid > 0 && !isset($byId[$pid])) {
        return 0;
    }

    return $pid;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function acc_account_build_tree(array $rows): array
{
    $byId = acc_account_index_by_id($rows);
    $byParent = [];
    foreach ($rows as $row) {
        $pid = acc_account_tree_parent_key($row, $byId);
        $byParent[$pid][] = $row;
    }

    $walk = static function (int $parentId, int $depth) use (&$walk, &$byParent): array {
        $nodes = [];
        foreach ($byParent[$parentId] ?? [] as $row) {
            $id = (int) $row['id'];
            $children = $walk($id, $depth + 1);
            $nodes[] = array_merge($row, [
                'depth' => $depth,
                'children' => $children,
                'has_children' => $children !== [],
            ]);
        }

        return $nodes;
    };

    return $walk(0, 0);
}

/**
 * قائمة مسطّحة للطباعة: حسابات رئيسية وفرعية بترتيب الشجرة.
 *
 * @return list<array{
 *   depth: int,
 *   code: string,
 *   name_ar: string,
 *   level_label: string,
 *   account_type: string,
 *   is_leaf: bool,
 *   is_active: bool
 * }>
 */
function acc_account_flatten_tree_for_print(PDO $pdo, bool $activeOnly = false): array
{
    $rows = acc_account_load_all($pdo, $activeOnly);
    $tree = acc_account_build_tree($rows);
    $out = [];

    $walk = static function (array $nodes) use (&$walk, &$out): void {
        foreach ($nodes as $node) {
            $depth = (int) ($node['depth'] ?? 0);
            $hasChildren = !empty($node['children']);
            $out[] = [
                'depth' => $depth,
                'code' => acc_account_format_code((string) ($node['code'] ?? '')),
                'name_ar' => (string) ($node['name_ar'] ?? ''),
                'level_label' => $depth === 0 || $hasChildren ? 'رئيسي' : 'فرعي',
                'account_type' => acc_account_type_label((string) ($node['account_type'] ?? '')),
                'is_leaf' => (int) ($node['is_leaf'] ?? 0) === 1,
                'is_active' => (int) ($node['is_active'] ?? 0) === 1,
            ];
            if ($hasChildren) {
                $walk($node['children']);
            }
        }
    };

    $walk($tree);

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{nodes: array<int, array<string, mixed>>, childrenOf: array<int, list<int>>}
 */
function acc_account_client_map(array $rows): array
{
    $byId = acc_account_index_by_id($rows);
    $nodes = [];
    $childrenOf = [0 => []];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $pid = acc_account_tree_parent_key($row, $byId);
        $nodes[$id] = [
            'id' => $id,
            'code' => acc_account_format_code((string) $row['code']),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'parent_id' => $pid > 0 ? $pid : null,
            'account_type' => (string) $row['account_type'],
            'is_leaf' => (int) $row['is_leaf'],
            'is_active' => (int) $row['is_active'],
        ];
        if (!isset($childrenOf[$pid])) {
            $childrenOf[$pid] = [];
        }
        $childrenOf[$pid][] = $id;
        if (!isset($childrenOf[$id])) {
            $childrenOf[$id] = [];
        }
    }

    return ['nodes' => $nodes, 'childrenOf' => $childrenOf];
}

function acc_account_journal_line_count(PDO $pdo, int $id): int
{
    if ($id < 1) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM acc_journal_line WHERE account_id = ?');
        $st->execute([$id]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function acc_account_voucher_count(PDO $pdo, int $id): int
{
    if ($id < 1) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM fin_voucher WHERE cash_account_id = ?');
        $st->execute([$id]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * سياق مُجمَّع لتسريع فحص إمكانية حذف حسابات الشجرة (يُحمَّل مرة واحدة لكل صفحة).
 *
 * @return array{
 *   parents_with_children: array<int, true>,
 *   journal_account_ids: array<int, true>,
 *   voucher_cash_account_ids: array<int, true>,
 *   posting_account_ids: array<int, true>
 * }
 */
function acc_account_delete_context(PDO $pdo): array
{
    $ctx = [
        'parents_with_children' => [],
        'journal_account_ids' => [],
        'voucher_cash_account_ids' => [],
        'posting_account_ids' => [],
    ];

    try {
        foreach ($pdo->query(
            'SELECT DISTINCT parent_id FROM acc_account WHERE parent_id IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $ctx['parents_with_children'][$pid] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        foreach ($pdo->query(
            'SELECT DISTINCT account_id FROM acc_journal_line WHERE account_id IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $aid) {
            $aid = (int) $aid;
            if ($aid > 0) {
                $ctx['journal_account_ids'][$aid] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->query('SELECT id FROM fin_voucher LIMIT 1');
        foreach ($pdo->query(
            'SELECT DISTINCT cash_account_id FROM fin_voucher WHERE cash_account_id IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $aid) {
            $aid = (int) $aid;
            if ($aid > 0) {
                $ctx['voucher_cash_account_ids'][$aid] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->query('SELECT rule_code FROM acc_posting_setting LIMIT 1');
        foreach ($pdo->query(
            'SELECT DISTINCT account_id FROM acc_posting_setting WHERE account_id IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $aid) {
            $aid = (int) $aid;
            if ($aid > 0) {
                $ctx['posting_account_ids'][$aid] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $ctx;
}

/**
 * @param array{
 *   parents_with_children?: array<int, true>,
 *   journal_account_ids?: array<int, true>,
 *   voucher_cash_account_ids?: array<int, true>,
 *   posting_account_ids?: array<int, true>
 * }|null $ctx
 * @return array{can_delete:bool, message:string}
 */
function acc_account_delete_check(PDO $pdo, int $id, ?array $ctx = null): array
{
    if ($id < 1) {
        return ['can_delete' => false, 'message' => 'معرّف غير صالح.'];
    }

    if ($ctx === null) {
        $ctx = acc_account_delete_context($pdo);
    }

    if (!empty($ctx['parents_with_children'][$id])) {
        return ['can_delete' => false, 'message' => 'لا يمكن الحذف: للحساب حسابات فرعية. احذف الفروع أولاً.'];
    }

    $journalLines = acc_account_journal_line_count($pdo, $id);
    if ($journalLines > 0 || !empty($ctx['journal_account_ids'][$id])) {
        return [
            'can_delete' => false,
            'message' => 'لا يمكن حذف الحساب: يوجد عليه حركات. يمكنك إيقاف الحساب بدلاً من الحذف.',
        ];
    }

    $voucherCount = acc_account_voucher_count($pdo, $id);
    if ($voucherCount > 0 || !empty($ctx['voucher_cash_account_ids'][$id])) {
        return [
            'can_delete' => false,
            'message' => 'لا يمكن حذف الحساب: يوجد عليه حركات (سندات قبض أو صرف).',
        ];
    }

    if (!empty($ctx['posting_account_ids'][$id])) {
        return [
            'can_delete' => false,
            'message' => 'لا يمكن حذف الحساب لأنه مربوط في «ربط الحسابات» لترحيل المستندات. غيّر الربط أولاً أو أوقف الحساب.',
        ];
    }

    return ['can_delete' => true, 'message' => ''];
}

/**
 * @param array{nodes: array<int|string, array<string, mixed>>, childrenOf: array<int|string, list<int>>} $clientMap
 * @return array{nodes: array<int|string, array<string, mixed>>, childrenOf: array<int|string, list<int>>}
 */
function acc_account_client_map_with_delete_flags(PDO $pdo, array $clientMap): array
{
    $ctx = acc_account_delete_context($pdo);
    foreach ($clientMap['nodes'] as $key => $node) {
        $id = (int) ($node['id'] ?? $key);
        $chk = acc_account_delete_check($pdo, $id, $ctx);
        $clientMap['nodes'][$key]['can_delete'] = $chk['can_delete'];
        $clientMap['nodes'][$key]['delete_block_reason'] = $chk['can_delete'] ? '' : $chk['message'];
    }

    return $clientMap;
}

/** @param array<string, mixed> $data */
function acc_account_save(PDO $pdo, array $data): int
{
    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name_ar'] ?? ''));
    $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null
        ? (int) $data['parent_id']
        : null;
    if ($parentId !== null && $parentId < 1) {
        $parentId = null;
    }
    $isLeaf = !empty($data['is_leaf']) ? 1 : 0;
    $isActive = !array_key_exists('is_active', $data) || !empty($data['is_active']) ? 1 : 0;
    $accountType = trim((string) ($data['account_type'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('اسم الحساب مطلوب.');
    }

    $validTypes = array_keys(acc_account_type_labels());
    if (!in_array($accountType, $validTypes, true)) {
        throw new RuntimeException('نوع الحساب غير صالح.');
    }

    if ($id > 0) {
        $cur = acc_account_get($pdo, $id);
        if (!$cur) {
            throw new RuntimeException('الحساب غير موجود.');
        }

        $stKids = $pdo->prepare('SELECT id FROM acc_account WHERE parent_id = ? LIMIT 1');
        $stKids->execute([$id]);
        $hasKids = (bool) $stKids->fetch();
        if ($hasKids && $isLeaf) {
            throw new RuntimeException('لا يمكن جعل الحساب «نهائي» طالما له حسابات فرعية.');
        }

        $pdo->prepare(
            'UPDATE acc_account SET name_ar = ?, is_leaf = ?, is_active = ? WHERE id = ?'
        )->execute([$name, $isLeaf, $isActive, $id]);

        if ($isLeaf === 1 && $isActive === 1) {
            require_once app_path('includes/dashboard_accounts.php');
            dashboard_accounts_register($pdo, $id);
        }

        return $id;
    }

    if ($parentId !== null) {
        $parent = acc_account_get($pdo, $parentId);
        if (!$parent) {
            throw new RuntimeException('الحساب الأب غير موجود.');
        }
        $accountType = (string) $parent['account_type'];
        $pdo->prepare('UPDATE acc_account SET is_leaf = 0 WHERE id = ? AND is_leaf = 1')->execute([$parentId]);
    }

    $code = acc_account_next_code($pdo, $parentId);

    $sortOrder = acc_account_next_sort_order($pdo, $parentId);
    try {
        $pdo->prepare(
            'INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $code,
            $name,
            $parentId,
            $accountType,
            $isLeaf,
            $isActive,
            $sortOrder,
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
            throw new RuntimeException(
                'رقم الحساب «' . $code . '» مستخدم مسبقاً. حدّث الصفحة وحاول الإضافة مرة أخرى.',
                0,
                $e
            );
        }
        throw $e;
    }

    $newId = (int) $pdo->lastInsertId();
    require_once app_path('includes/dashboard_accounts.php');
    dashboard_accounts_register($pdo, $newId);

    return $newId;
}

function acc_account_delete(PDO $pdo, int $id): void
{
    $chk = acc_account_delete_check($pdo, $id);
    if (!$chk['can_delete']) {
        throw new RuntimeException($chk['message']);
    }

    $row = acc_account_get($pdo, $id);
    if (!$row) {
        throw new RuntimeException('الحساب غير موجود.');
    }

    $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    try {
        $st = $pdo->prepare('DELETE FROM acc_account WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() < 1) {
            throw new RuntimeException('لم يُحذف الحساب. قد يكون قد أُعيد إنشاؤه تلقائياً — حدّث الصفحة وحاول مرة أخرى.');
        }
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'foreign key') || str_contains($msg, '1451') || str_contains($msg, '23000')) {
            throw new RuntimeException(
                'لا يمكن حذف الحساب: يوجد عليه حركات أو سجلات مرتبطة.',
                0,
                $e
            );
        }
        throw $e;
    }

    if ($parentId !== null && $parentId > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM acc_account WHERE parent_id = ?');
        $st->execute([$parentId]);
        if ((int) $st->fetchColumn() === 0) {
            $pdo->prepare('UPDATE acc_account SET is_leaf = 1 WHERE id = ?')->execute([$parentId]);
        }
    }
}

/**
 * @param list<array<string, mixed>> $nodes
 */
function acc_account_render_ref_tree_html(array $nodes, bool $isRootLevel = true, string $listUrl = ''): void
{
    if ($nodes === []) {
        return;
    }

    $tag = $isRootLevel ? 'ul' : 'ul';
    echo '<' . $tag . ' class="' . ($isRootLevel ? 'coa-ref-tree' : 'coa-ref-branch') . '">';
    foreach ($nodes as $node) {
        $id = (int) $node['id'];
        $hasChildren = !empty($node['has_children']);
        $isLeaf = (int) ($node['is_leaf'] ?? 1) === 1 && !$hasChildren;
        $isActive = (int) ($node['is_active'] ?? 1) === 1;
        $type = (string) ($node['account_type'] ?? '');
        $tone = acc_account_type_tone_class($type);
        $label = esc((string) $node['name_ar']) . ' - ' . esc(acc_account_format_code((string) $node['code']));
        $parentIdRaw = $node['parent_id'] ?? null;
        $isRootAccount = $parentIdRaw === null || $parentIdRaw === '';

        echo '<li class="coa-ref-node' . ($hasChildren ? ' coa-ref-node--branch' : ' coa-ref-node--leaf') . ($isActive ? '' : ' is-inactive') . ($isRootAccount ? ' coa-ref-node--root' : '') . '"';
        echo ' data-id="' . $id . '" role="treeitem" aria-selected="false">';
        echo '<div class="coa-ref-line">';
        if ($hasChildren) {
            echo '<button type="button" class="coa-ref-exp" aria-expanded="false" aria-label="طي/توسيع"><span class="coa-ref-exp-ico">+</span></button>';
        } else {
            echo '<span class="coa-ref-exp coa-ref-exp--spacer" aria-hidden="true"></span>';
        }
        echo '<span class="coa-ref-icon' . ($isLeaf ? ' coa-ref-icon--doc' : ' coa-ref-icon--folder') . '" aria-hidden="true"></span>';
        echo '<button type="button" class="coa-ref-label ' . esc($tone) . '">' . $label . '</button>';
        if ($isRootAccount && $listUrl !== '') {
            $editUrl = $listUrl . (str_contains($listUrl, '?') ? '&' : '?') . 'action=edit&id=' . $id;
            echo '<a class="coa-ref-name-edit" href="' . esc($editUrl) . '" title="تعديل اسم الحساب الرئيسي">تعديل الاسم</a>';
        }
        echo '</div>';

        $children = $node['children'] ?? [];
        if (is_array($children) && $children !== []) {
            echo '<div class="coa-ref-children-wrap is-collapsed">';
            acc_account_render_ref_tree_html($children, false, $listUrl);
            echo '</div>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

/**
 * @param list<array<string, mixed>> $tree
 */
function acc_account_render_split_view(array $tree, array $clientMap, string $listUrl, bool $restoreTree = false): void
{
    $json = json_encode($clientMap, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"nodes":{},"childrenOf":{"0":[]}}';
    }
    ?>
<script type="application/json" id="coa-client-map"><?= $json ?></script>
<div class="coa-split" id="coa-split" data-list-url="<?= esc($listUrl) ?>" data-restore-tree="<?= $restoreTree ? '1' : '0' ?>">
    <aside class="coa-panel coa-panel--tree" role="tree" aria-label="شجرة الحسابات">
        <div class="coa-panel-head coa-panel-head--tree">
            <span class="coa-panel-head-label">شجرة الحسابات</span>
            <div class="coa-tree-search-wrap">
                <input type="search" class="input coa-tree-search" id="coa-tree-search"
                       placeholder="بحث: رقم الحساب أو الاسم" autocomplete="off" aria-label="بحث في شجرة الحسابات">
                <span class="coa-search-hint" id="coa-search-hint" hidden></span>
            </div>
        </div>
        <div class="coa-tree-scroll">
            <?php if ($tree === []): ?>
                <p class="coa-empty">لا توجد حسابات. أضف حساباً رئيسياً للبدء.</p>
            <?php else: ?>
                <?php acc_account_render_ref_tree_html($tree, true, $listUrl); ?>
            <?php endif; ?>
        </div>
    </aside>
    <section class="coa-panel coa-panel--detail">
        <div class="coa-panel-head coa-panel-head--detail">
            <h3 class="coa-detail-title" id="coa-detail-title">—</h3>
            <div class="coa-detail-actions">
                <a class="btn btn-primary btn-sm coa-act-add" id="coa-act-add" href="<?= esc($listUrl . '&action=add') ?>">+ إضافة حساب</a>
                <a class="btn btn-secondary btn-sm coa-act-edit" id="coa-act-edit" href="#">تعديل</a>
                <form method="post" action="<?= esc($listUrl) ?>" class="coa-act-del-form" id="coa-act-del-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="id" value="" id="coa-del-id">
                    <button type="submit" class="btn btn-danger btn-sm" id="coa-act-del" disabled>حذف</button>
                </form>
            </div>
        </div>
        <div class="coa-table-wrap">
            <table class="coa-detail-table">
                <thead>
                    <tr>
                        <th>اسم الحساب</th>
                        <th class="coa-th-code">رقم الحساب</th>
                    </tr>
                </thead>
                <tbody id="coa-detail-body">
                    <tr class="coa-detail-placeholder">
                        <td colspan="2">اختر حساباً من الشجرة لعرض الفروع</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>
    <?php
}

/**
 * توافق مع استدعاءات قديمة — يعرض العرض المنقسم (شجرة + جدول).
 *
 * @param list<array<string, mixed>> $tree
 * @param array{nodes: array<int, array<string, mixed>>, childrenOf: array<int, list<int>>}|null $clientMap
 */
function acc_account_render_tree_html(array $tree, string $listUrl, ?array $clientMap = null, bool $restoreTree = false): void
{
    if ($clientMap === null) {
        $flat = [];
        $walk = static function (array $nodes) use (&$walk, &$flat): void {
            foreach ($nodes as $n) {
                $flat[] = [
                    'id' => $n['id'],
                    'code' => $n['code'],
                    'name_ar' => $n['name_ar'],
                    'parent_id' => $n['parent_id'] ?? null,
                    'account_type' => $n['account_type'],
                    'is_leaf' => $n['is_leaf'] ?? 1,
                    'is_active' => $n['is_active'] ?? 1,
                    'sort_order' => $n['sort_order'] ?? 0,
                ];
                $children = $n['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $walk($children);
                }
            }
        };
        $walk($tree);
        $clientMap = acc_account_client_map($flat);
    }
    acc_account_render_split_view($tree, $clientMap, $listUrl, $restoreTree);
}
