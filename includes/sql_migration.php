<?php
declare(strict_types=1);

/** إزالة تعليقات SQL من بداية كل جملة (بعد التقسيم بـ ;). */
function sql_migration_clean_statement(string $stmt): string
{
    // لا تستخدم \R — يطابق 0x85 (جزء من UTF-8 لحرف «م») فيُقطع النص العربي.
    $lines = preg_split('/\r\n|\n|\r/', $stmt) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $out[] = $line;
    }

    return trim(implode("\n", $out));
}

function sql_migration_prepare_sql(string $sql): string
{
    return preg_replace('/^USE\s+[^;]+;\s*/mi', '', $sql) ?? $sql;
}

/** @return list<string> */
function sql_migration_split_statements(string $sql): array
{
    $sql = sql_migration_prepare_sql($sql);
    $stmts = [];
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $raw) {
        $clean = sql_migration_clean_statement($raw);
        if ($clean !== '') {
            $stmts[] = $clean;
        }
    }

    return $stmts;
}

/**
 * @param list<string> $stmts
 * @return string|null رسالة خطأ أخيرة (إن وُجدت)
 */
function sql_migration_drain_pdo(PDO $pdo): void
{
    try {
        while ($pdo->nextRowset()) {
            // استهلاك أي result sets متبقية (مثلاً بعد PREPARE/EXECUTE في ملفات SQL).
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function sql_migration_exec_statements(PDO $pdo, array $stmts): ?string
{
    $lastErr = null;
    foreach ($stmts as $stmt) {
        try {
            $pdo->exec($stmt);
            sql_migration_drain_pdo($pdo);
        } catch (Throwable $e) {
            sql_migration_drain_pdo($pdo);
            $msg = $e->getMessage();
            if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate')) {
                continue;
            }
            if (str_contains($msg, 'duplicate column') || str_contains($msg, 'Duplicate column')) {
                continue;
            }
            $lastErr = $msg;
        }
    }

    return $lastErr;
}

/** @return string|null null عند النجاح أو عدم وجود خطأ حرج */
function sql_migration_run_file(PDO $pdo, string $relativePath): ?string
{
    $path = app_path($relativePath);
    if (!is_readable($path)) {
        return 'ملف الترحيل غير موجود: ' . $relativePath;
    }

    $sql = (string) file_get_contents($path);

    return sql_migration_exec_statements($pdo, sql_migration_split_statements($sql));
}

function sql_migration_ensure_registry(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sys_sql_migration (
            path VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

/** تشغيل ملف ترحيل مرة واحدة فقط — يُسرّع التنقل بين الشاشات. */
function sql_migration_run_file_once(PDO $pdo, string $relativePath): ?string
{
    sql_migration_ensure_registry($pdo);
    $st = $pdo->prepare('SELECT 1 FROM sys_sql_migration WHERE path = ? LIMIT 1');
    $st->execute([$relativePath]);
    $already = $st->fetchColumn();
    $st->closeCursor();
    if ($already !== false) {
        return null;
    }

    $err = sql_migration_run_file($pdo, $relativePath);
    sql_migration_drain_pdo($pdo);
    try {
        $ins = $pdo->prepare('INSERT IGNORE INTO sys_sql_migration (path) VALUES (?)');
        $ins->execute([$relativePath]);
        $ins->closeCursor();
    } catch (Throwable $e) {
        error_log('[sql_migration] registry insert failed for ' . $relativePath . ': ' . $e->getMessage());
    }

    return $err;
}

/** @param list<string> $relativePaths */
function sql_migration_boot_fingerprint(array $relativePaths): string
{
    $paths = array_values(array_unique(array_map(static fn ($p): string => (string) $p, $relativePaths)));
    sort($paths, SORT_STRING);

    return 'v1:' . count($paths) . ':' . md5(implode("\0", $paths));
}

/** @param list<string> $relativePaths */
function sql_migration_run_files_once(PDO $pdo, array $relativePaths): void
{
    if ($relativePaths === []) {
        return;
    }

    sql_migration_ensure_registry($pdo);
    $paths = array_values(array_unique(array_map(static fn ($p): string => (string) $p, $relativePaths)));
    $fingerprint = sql_migration_boot_fingerprint($paths);

    require_once app_path('includes/acc_coa_bootstrap.php');
    if (acc_coa_meta_get($pdo, 'sql_boot_migrations_fp') === $fingerprint) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($paths), '?'));
    $st = $pdo->prepare('SELECT path FROM sys_sql_migration WHERE path IN (' . $placeholders . ')');
    $st->execute($paths);
    $applied = array_flip($st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $st->closeCursor();

    foreach ($paths as $relativePath) {
        if (isset($applied[$relativePath])) {
            continue;
        }
        sql_migration_run_file_once($pdo, $relativePath);
        $applied[$relativePath] = true;
    }

    if (count($applied) === count($paths)) {
        acc_coa_meta_set($pdo, 'sql_boot_migrations_fp', $fingerprint);
    }
}

/**
 * قاعدة موجودة مسبقاً: تسجيل الترحيلات دون إعادة تشغيلها (تسريع أول زيارة بعد التحديث).
 *
 * @param list<string> $relativePaths
 */
function sql_migration_bootstrap_registry(PDO $pdo, array $relativePaths): void
{
    sql_migration_ensure_registry($pdo);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM sys_sql_migration')->fetchColumn();
    if ($count > 0) {
        return;
    }

    try {
        $screens = (int) $pdo->query('SELECT COUNT(*) FROM sys_screen')->fetchColumn();
        if ($screens < 5) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $ins = $pdo->prepare('INSERT IGNORE INTO sys_sql_migration (path) VALUES (?)');
    foreach ($relativePaths as $relativePath) {
        $ins->execute([(string) $relativePath]);
    }
}
