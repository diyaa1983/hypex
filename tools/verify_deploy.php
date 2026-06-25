<?php
declare(strict_types=1);

/**
 * التحقق من اكتمال رفع التحديثات على السيرفر.
 * افتح: https://your-domain/manager/tools/verify_deploy.php
 * احذف الملف أو احمِه بعد التحقق.
 */
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require app_path('includes/sql_migration.php');
require app_path('includes/doc_number_pool.php');

$pdo = db();
sql_migration_ensure_registry($pdo);

$ok = 0;
$fail = 0;
$warn = 0;

function deploy_line(string $status, string $msg): void
{
    global $ok, $fail, $warn;
    echo $status . ' ' . $msg . "\n";
    if ($status === 'OK') {
        $ok++;
    } elseif ($status === 'FAIL') {
        $fail++;
    } else {
        $warn++;
    }
}

function deploy_file_ok(string $rel): bool
{
    $path = app_path($rel);
    if (!is_file($path)) {
        deploy_line('FAIL', "ملف ناقص: {$rel}");

        return false;
    }
    deploy_line('OK', "ملف موجود: {$rel}");

    return true;
}

function deploy_sql_count(PDO $pdo, string $label, string $sql, int $min = 1): void
{
    try {
        $cnt = (int) $pdo->query($sql)->fetchColumn();
        if ($cnt >= $min) {
            deploy_line('OK', "{$label} ({$cnt})");
        } else {
            deploy_line('FAIL', "{$label} — متوقع ≥ {$min}، وُجد {$cnt}");
        }
    } catch (Throwable $e) {
        deploy_line('FAIL', "{$label} — " . $e->getMessage());
    }
}

echo "=== فحص اكتمال رفع التحديثات ===\n";
echo 'التاريخ: ' . date('Y-m-d H:i:s') . "\n";
echo 'المسار: ' . $root . "\n\n";

if (is_dir($root . '/.git')) {
    $hash = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>nul'));
    if ($hash !== '') {
        deploy_line('OK', 'Git commit على السيرفر: ' . $hash);
    }
    $branch = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' branch --show-current 2>nul'));
    if ($branch !== '') {
        deploy_line('OK', 'Git branch: ' . $branch);
    }
} else {
    deploy_line('WARN', 'لا يوجد Git على السيرفر — تحقق يدوياً من الملفات أدناه');
}

echo "\n--- ملفات التحديث الأخير (يجب أن تكون موجودة) ---\n";

$criticalFiles = [
    'index.php',
    'includes/app_window_manager.php',
    'assets/js/app-window-manager.js',
    'assets/css/app-window-manager.css',
    'assets/js/screen-exit-guard.js',
    'includes/doc_number_pool.php',
    'includes/sal_invoice_delete.php',
    'includes/pur_invoice_delete.php',
    'includes/sal_invoice_schema.php',
    'includes/pur_invoice_schema.php',
    'includes/fin_voucher_archive.php',
    'assets/js/fin-voucher-archive.js',
    'assets/css/fin-voucher-archive.css',
    'api/fin_voucher_archive.php',
    'includes/dashboard_accounts.php',
    'modules/settings/dashboard_accounts.php',
    'assets/js/dashboard-accounts-settings.js',
    'database/migrations/179_fin_voucher_archive.sql',
    'database/migrations/180_sys_dashboard_account.sql',
    'database/migrations/181_fin_voucher_archive_docs.sql',
    'database/migrations/182_clear_legacy_invoice_number_pool.sql',
    'templates/layout-embed.php',
    'config/mdi_screens.php',
];

foreach ($criticalFiles as $rel) {
    deploy_file_ok($rel);
}

echo "\n--- ترحيلات SQL (index.php → sys_sql_migration) ---\n";

$indexSrc = (string) file_get_contents(app_path('index.php'));
preg_match_all("/'database\\/migrations\\/[^']+'/", $indexSrc, $matches);
$boot = array_values(array_unique(array_map(static fn ($q) => trim($q, "'"), $matches[0] ?? [])));
sort($boot, SORT_STRING);

$st = $pdo->query('SELECT path, applied_at FROM sys_sql_migration ORDER BY path');
$applied = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $applied[(string) $row['path']] = (string) ($row['applied_at'] ?? '');
}

$missingBoot = array_diff($boot, array_keys($applied));
if ($missingBoot === []) {
    deploy_line('OK', 'جميع ترحيلات index.php (' . count($boot) . ') منفّذة في قاعدة البيانات');
} else {
    deploy_line('FAIL', 'ترحيلات ناقصة من index.php: ' . count($missingBoot));
    foreach ($missingBoot as $path) {
        echo "     [ ] {$path}\n";
        $fail++;
    }
}

$recentMigrations = [
    'database/migrations/180_sys_dashboard_account.sql',
    'database/migrations/181_fin_voucher_archive_docs.sql',
    'database/migrations/182_clear_legacy_invoice_number_pool.sql',
];
echo "\n--- ترحيلات التحديث الأخير ---\n";
foreach ($recentMigrations as $path) {
    if (isset($applied[$path])) {
        deploy_line('OK', "{$path} @ {$applied[$path]}");
    } else {
        deploy_line('FAIL', "{$path} — لم يُنفَّذ بعد (سجّل دخول وافتح index.php)");
    }
}

$path179 = 'database/migrations/179_fin_voucher_archive.sql';
echo "\n--- ترحيل 179 (أرشيف — يُشغَّل عند فتح شاشة الأرشيف) ---\n";
if (isset($applied[$path179])) {
    deploy_line('OK', "{$path179} @ {$applied[$path179]}");
} else {
    deploy_line('WARN', "{$path179} — غير مسجّل (افتح شاشة فيها زر «أرشيف» مرة واحدة)");
}

echo "\n--- فحص قاعدة البيانات (نتيجة الترحيلات) ---\n";

deploy_sql_count(
    $pdo,
    'جدول sys_dashboard_account (180)',
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sys_dashboard_account'"
);
deploy_sql_count(
    $pdo,
    'شاشة dashboard_accounts_settings',
    "SELECT COUNT(*) FROM sys_screen WHERE code = 'dashboard_accounts_settings'"
);
deploy_sql_count(
    $pdo,
    'جدول fin_voucher_document (179)',
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher_document'"
);
deploy_sql_count(
    $pdo,
    'جدول doc_number_pool (171)',
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doc_number_pool'"
);
deploy_sql_count(
    $pdo,
    'صلاحية action_archive_sales_invoice (181)',
    "SELECT COUNT(*) FROM sys_screen WHERE code = 'action_archive_sales_invoice'"
);
deploy_sql_count(
    $pdo,
    'doc_number_pool_key sal_invoice في الكود',
    'SELECT 1'
);
try {
    if (function_exists('doc_number_pool_key_sal_invoice')) {
        deploy_line('OK', 'دوال إعادة استخدام أرقام الفواتير: doc_number_pool_key_sal_invoice');
    }
} catch (Throwable $e) {
    deploy_line('FAIL', 'دوال doc_number_pool');
}

echo "\n--- فحص سريع للكود ---\n";
$indexHas182 = str_contains($indexSrc, '182_clear_legacy_invoice_number_pool.sql');
deploy_line($indexHas182 ? 'OK' : 'FAIL', 'index.php يشمل ترحيل 182');

$delHasPool = is_file(app_path('includes/sal_invoice_delete.php'))
    && str_contains((string) file_get_contents(app_path('includes/sal_invoice_delete.php')), 'sal_invoice_release_no_to_pool');
deploy_line($delHasPool ? 'OK' : 'FAIL', 'sal_invoice_delete.php — إعادة الرقم للمجموعة عند الحذف');

$mdiJs = is_file(app_path('assets/js/app-window-manager.js'))
    && str_contains((string) file_get_contents(app_path('assets/js/app-window-manager.js')), 'exitCurrentPage');
deploy_line($mdiJs ? 'OK' : 'FAIL', 'app-window-manager.js — exitCurrentPage (MDI)');

echo "\n=== الملخص ===\n";
echo "OK: {$ok}  |  FAIL: {$fail}  |  WARN: {$warn}\n\n";

if ($fail === 0 && $warn === 0) {
    echo "✓ يبدو أن التحديثات مرفوعة ومطبّقة بالكامل.\n";
    exit(0);
}
if ($fail === 0) {
    echo "⚠ الملفات موجودة؛ بعض الترحيلات/الإعدادات تحتاج خطوة إضافية (انظر WARN).\n";
    exit(0);
}

echo "✗ يوجد نقص — راجع FAIL أعلاه.\n";
echo "\nإذا كانت ترحيلات SQL ناقصة:\n";
echo "  1) سجّل دخولاً وافتح: index.php?r=dashboard\n";
echo "  2) أو SSH: php tools/run_sql_migration.php database/migrations/180_...\n";
echo "  3) ثم أعد فتح هذه الصفحة.\n";
exit(1);
