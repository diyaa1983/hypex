<?php
declare(strict_types=1);

/**
 * مقارنة ترحيلات index.php مع جدول sys_sql_migration.
 * التشغيل: php tools/check_migrations.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sql_migration.php');

$pdo = db();
sql_migration_ensure_registry($pdo);

$indexPath = app_path('index.php');
$indexSrc = (string) file_get_contents($indexPath);
if (!preg_match_all("/'database\\/migrations\\/[^']+'/", $indexSrc, $matches)) {
    fwrite(STDERR, "تعذر قراءة قائمة الترحيلات من index.php\n");
    exit(1);
}

$boot = [];
foreach ($matches[0] as $quoted) {
    $boot[] = trim($quoted, "'");
}
$boot = array_values(array_unique($boot));
sort($boot, SORT_STRING);

$st = $pdo->query('SELECT path, applied_at FROM sys_sql_migration ORDER BY path');
$rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
$applied = [];
foreach ($rows as $row) {
    $applied[(string) ($row['path'] ?? '')] = (string) ($row['applied_at'] ?? '');
}

$missing = array_values(array_diff($boot, array_keys($applied)));
$extra = array_values(array_diff(array_keys($applied), $boot));

echo "=== فحص ترحيلات قاعدة البيانات ===\n";
echo 'ترحيلات مسجّلة في index.php: ' . count($boot) . "\n";
echo 'سجلات في sys_sql_migration: ' . count($applied) . "\n";
echo 'غير منفّذة (ناقصة): ' . count($missing) . "\n\n";

if ($missing !== []) {
    echo "--- ناقصة — افتح الموقع مرة واحدة أو نفّذ يدوياً ---\n";
    foreach ($missing as $path) {
        echo "  [ ] {$path}\n";
    }
    echo "\n";
} else {
    echo "✓ جميع ترحيلات index.php مسجّلة في sys_sql_migration.\n\n";
}

if ($extra !== []) {
    echo "--- مسجّلة في DB لكن ليست في index.php (قد تكون قديمة/يدوية) ---\n";
    foreach ($extra as $path) {
        echo "  {$path} @ " . ($applied[$path] ?? '') . "\n";
    }
    echo "\n";
}

echo "--- آخر 10 ترحيلات (حسب اسم الملف) ---\n";
$recent = array_slice($boot, -10);
foreach ($recent as $path) {
    $ok = isset($applied[$path]);
    $mark = $ok ? '[x]' : '[ ]';
    $at = $ok ? (' @ ' . $applied[$path]) : '';
    echo "  {$mark} {$path}{$at}\n";
}

echo "\n--- فحوصات سريعة للتحديثات الأخيرة ---\n";
$checks = [
    'شاشة السلف (167)' => "SELECT COUNT(*) FROM sys_screen WHERE code = 'fin_employee_advances'",
    'عمود hr_advance_id في fin_voucher (164)' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher' AND COLUMN_NAME = 'hr_advance_id'",
    'عمود party_type في fin_voucher (163)' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher' AND COLUMN_NAME = 'party_type'",
    'حساب 2009 سلف موظفين (164)' => "SELECT COUNT(*) FROM acc_account WHERE code = '2009' AND is_active = 1",
    'عمود disbursement_voucher_id (164)' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hr_employee_advance' AND COLUMN_NAME = 'disbursement_voucher_id'",
    'عمود hr_salary_id في fin_voucher (166)' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher' AND COLUMN_NAME = 'hr_salary_id'",
];

foreach ($checks as $label => $sql) {
    try {
        $cnt = (int) $pdo->query($sql)->fetchColumn();
        $mark = $cnt > 0 ? 'OK' : 'MISSING';
        echo "  [{$mark}] {$label}\n";
    } catch (Throwable $e) {
        echo "  [ERR] {$label}: {$e->getMessage()}\n";
    }
}

exit($missing === [] ? 0 : 2);
