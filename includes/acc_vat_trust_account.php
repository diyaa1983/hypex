<?php
declare(strict_types=1);

/** حساب أمانات ضريبة المبيعات — موحّد لمخرجات ومدخلات الضريبة. */
const ACC_VAT_TRUST_ACCOUNT_CODE = '3001002';
const ACC_VAT_TRUST_ACCOUNT_NAME = 'أمانات ضريبة مبيعات';
const ACC_VAT_TRUST_REPORT_TITLE = 'أمانات ضريبة مبيعات';
const ACC_VAT_TRUST_MIGRATION_PATH = 'database/migrations/160_acc_vat_trust_account.sql';
const ACC_VAT_TRUST_META_KEY = 'vat_trust_unified_v1';

/** @return list<string> */
function acc_vat_trust_legacy_account_codes(): array
{
    return [
        '2003',
        '1001004',
        '1001009',
        '22',
    ];
}

function acc_vat_trust_find_account_id(PDO $pdo): int
{
    require_once app_path('includes/acc_account_tree.php');
    $digits = acc_account_code_digits(ACC_VAT_TRUST_ACCOUNT_CODE);
    if ($digits === '') {
        return 0;
    }
    $st = $pdo->query('SELECT id, code FROM acc_account WHERE is_active = 1');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (acc_account_code_digits((string) ($row['code'] ?? '')) === $digits) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return 0;
}

function acc_vat_trust_resolve_parent_id(PDO $pdo): ?int
{
    require_once app_path('includes/acc_account_tree.php');
    foreach (['3001', '300', '2'] as $parentCode) {
        $digits = acc_account_code_digits($parentCode);
        $st = $pdo->query('SELECT id, code FROM acc_account WHERE is_active = 1');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (acc_account_code_digits((string) ($row['code'] ?? '')) === $digits) {
                return (int) ($row['id'] ?? 0);
            }
        }
    }

    return null;
}

function acc_vat_trust_ensure_account(PDO $pdo): int
{
    require_once app_path('includes/acc_account_tree.php');
    acc_account_ensure_schema($pdo);

    $existing = acc_vat_trust_find_account_id($pdo);
    if ($existing > 0) {
        $pdo->prepare(
            'UPDATE acc_account SET name_ar = ?, account_type = ?, is_leaf = 1, is_active = 1 WHERE id = ?'
        )->execute([ACC_VAT_TRUST_ACCOUNT_NAME, 'liability', $existing]);

        return $existing;
    }

    $parentId = acc_vat_trust_resolve_parent_id($pdo);
    $sortOrder = 20;
    if ($parentId !== null && $parentId > 0) {
        $sortOrder = acc_account_next_sort_order($pdo, $parentId);
    }

    $pdo->prepare(
        'INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
         VALUES (?,?,?,?,1,1,?)'
    )->execute([
        ACC_VAT_TRUST_ACCOUNT_CODE,
        ACC_VAT_TRUST_ACCOUNT_NAME,
        $parentId,
        'liability',
        $sortOrder,
    ]);

    return acc_vat_trust_find_account_id($pdo);
}

/** @return list<int> */
function acc_vat_trust_collect_legacy_account_ids(PDO $pdo, int $trustId): array
{
    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_account_tree.php');

    acc_gl_ensure_schema($pdo);
    $settings = acc_gl_load_settings($pdo);
    $ids = [];

    foreach (['vat_output', 'vat_input'] as $rule) {
        $id = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($id > 0 && $id !== $trustId) {
            $ids[$id] = $id;
        }
    }

    foreach (acc_vat_trust_legacy_account_codes() as $code) {
        $digits = acc_account_code_digits($code);
        if ($digits === '') {
            continue;
        }
        $st = $pdo->query('SELECT id, code FROM acc_account');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (acc_account_code_digits((string) ($row['code'] ?? '')) !== $digits) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && $id !== $trustId) {
                $ids[$id] = $id;
            }
        }
    }

    $st = $pdo->query('SELECT id, code, name_ar FROM acc_account WHERE is_active = 1');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || $id === $trustId || isset($ids[$id])) {
            continue;
        }
        $name = (string) ($row['name_ar'] ?? '');
        $code = (string) ($row['code'] ?? '');
        if ($name === '' || !preg_match('/ضريبة/u', $name)) {
            continue;
        }
        if (preg_match('/ضريبة\s*دخل/u', $name) || acc_account_code_digits($code) === acc_account_code_digits('2007')) {
            continue;
        }
        if (
            preg_match('/(مبيع|مشتري|مدخل|مخرج|vat|أمانات)/ui', $name)
            || preg_match('/^100100[49]$/', acc_account_code_digits($code))
        ) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function acc_vat_trust_remap_journal_lines(PDO $pdo, int $trustId): int
{
    if ($trustId < 1 || !acc_journal_has_tables($pdo)) {
        return 0;
    }

    $legacyIds = acc_vat_trust_collect_legacy_account_ids($pdo, $trustId);
    if ($legacyIds === []) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($legacyIds), '?'));
    $params = array_merge([$trustId], $legacyIds);
    $st = $pdo->prepare(
        "UPDATE acc_journal_line SET account_id = ?
         WHERE account_id IN ({$placeholders})"
    );
    $st->execute($params);

    return $st->rowCount();
}

function acc_vat_trust_update_posting_settings(PDO $pdo, int $trustId): void
{
    require_once app_path('includes/acc_gl.php');
    acc_gl_ensure_schema($pdo);

    if ($trustId < 1) {
        return;
    }

    $pdo->prepare(
        'UPDATE acc_posting_setting SET account_id = ?, label_ar = ?, hint_ar = ? WHERE rule_code = ?'
    )->execute([
        $trustId,
        'ضريبة مبيعات مستحقة (أمانات)',
        'دائن عند فاتورة البيع — حساب ' . ACC_VAT_TRUST_ACCOUNT_CODE,
        'vat_output',
    ]);

    $pdo->prepare(
        'UPDATE acc_posting_setting SET account_id = ?, label_ar = ?, hint_ar = ? WHERE rule_code = ?'
    )->execute([
        $trustId,
        'ضريبة مشتريات (أمانات)',
        'مدين عند فاتورة الشراء — حساب ' . ACC_VAT_TRUST_ACCOUNT_CODE,
        'vat_input',
    ]);
}

/** @return array{migrated_lines:int, trust_account_id:int} */
function acc_vat_trust_account_apply(PDO $pdo): array
{
    require_once app_path('includes/acc_journal.php');

    $trustId = acc_vat_trust_ensure_account($pdo);
    $migrated = acc_vat_trust_remap_journal_lines($pdo, $trustId);
    acc_vat_trust_update_posting_settings($pdo, $trustId);

    return [
        'migrated_lines' => $migrated,
        'trust_account_id' => $trustId,
    ];
}

function acc_vat_trust_account_apply_once(PDO $pdo): ?string
{
    require_once app_path('includes/acc_coa_bootstrap.php');
    if (acc_coa_meta_get($pdo, ACC_VAT_TRUST_META_KEY) === '1') {
        return null;
    }

    try {
        acc_vat_trust_account_apply($pdo);
        acc_coa_meta_set($pdo, ACC_VAT_TRUST_META_KEY, '1');
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return null;
}
