<?php
declare(strict_types=1);

function pur_return_has_tables(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM pur_return LIMIT 1');
        $pdo->query('SELECT id FROM pur_return_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function pur_return_schema_last_error(): ?string
{
    return $GLOBALS['_pur_return_schema_error'] ?? null;
}

function pur_return_ensure_schema(PDO $pdo): bool
{
    require_once app_path('includes/crm_supplier_ledger.php');
    crm_supplier_ledger_ensure_schema($pdo);

    if (pur_return_has_tables($pdo, true)) {
        $GLOBALS['_pur_return_schema_error'] = null;
        pur_return_apply_prefix_migration($pdo);

        return true;
    }

    require_once app_path('includes/sql_migration.php');
    $err = sql_migration_run_file($pdo, 'database/migrations/015_purchase_returns_supplier_ledger.sql');
    $GLOBALS['_pur_return_schema_error'] = $err;

    if (pur_return_has_tables($pdo, true)) {
        $GLOBALS['_pur_return_schema_error'] = null;
        pur_return_apply_prefix_migration($pdo);

        return true;
    }

    return false;
}

/**
 * تَطبيق بادئة PR على أرقام مرتجعات المشتريات القديمة (idempotent).
 * يُحَوِّل MR إلى PR ويُضيف PR للأرقام بدون بادئة.
 */
function pur_return_apply_prefix_migration(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/066_returns_renumber_prefix.sql');
    } catch (Throwable $e) {
        // ignore — لا نُريد كَسر تَحميل المرتجعات بسبب فَشل migration ثانوي.
    }
}

/**
 * رقم مرتجع تسلسلي للمشتريات: PR001-2026 (بادئة PR + 3 أرقام + سنة).
 * نَستخدم بادئة PR (Purchase Return) لِتَمييز المُرتَجَع عن فاتورة الشراء.
 *
 * عند حِساب أعلى تسلسل سنوي، نَدعم:
 *   - الأرقام القديمة بدون بادئة (مَوروثة)
 *   - الأرقام ببادئة MR (موروثة من نسخة سابقة)
 *   - الأرقام الجديدة ببادئة PR
 * لِضمان الاستمرار بدون تَكرار.
 */
function pur_return_generate_next_no(PDO $pdo, string $returnDate): string
{
    $year = (int) date('Y', strtotime($returnDate));
    $suffix = '-' . $year;

    $st = $pdo->prepare('SELECT return_no FROM pur_return WHERE return_no LIKE ? FOR UPDATE');
    $st->execute(['%' . $suffix]);

    $maxSeq = 0;
    $suffixQuoted = preg_quote($suffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^(?:MR|PR)?(\d+)' . $suffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    $next = $maxSeq + 1;

    return 'PR' . str_pad((string) $next, 3, '0', STR_PAD_LEFT) . $suffix;
}
