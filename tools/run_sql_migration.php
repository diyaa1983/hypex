<?php
declare(strict_types=1);

/**
 * تشغيل ملف migration واحد من سطر الأوامر (SSH).
 *
 * Usage:
 *   php tools/run_sql_migration.php database/migrations/157_sys_audit_log.sql
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/config/app.php';
require_once app_path('includes/db.php');
require_once app_path('includes/sql_migration.php');

$relativePath = trim((string) ($argv[1] ?? ''));
if ($relativePath === '') {
    fwrite(STDERR, "Usage: php tools/run_sql_migration.php database/migrations/157_sys_audit_log.sql\n");
    exit(1);
}

if (!str_starts_with($relativePath, 'database/migrations/')) {
    fwrite(STDERR, "Path must be under database/migrations/\n");
    exit(1);
}

$fullPath = app_path($relativePath);
if (!is_readable($fullPath)) {
    fwrite(STDERR, "File not found: {$relativePath}\n");
    exit(1);
}

try {
    $pdo = db();
    $err = sql_migration_run_file_once($pdo, $relativePath);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($err !== null && $err !== '') {
    fwrite(STDERR, "Migration finished with errors: {$err}\n");
    exit(1);
}

echo "OK: {$relativePath}\n";
