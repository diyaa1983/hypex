<?php
declare(strict_types=1);

/**
 * فحص تطبيق ترحيلات الشيكات على قاعدة البيانات.
 * الاستخدام: php tools/verify_checks_schema.php
 * أو من المتصفح (مسؤول فقط): index.php?r=... — يُشغَّل من CLI فقط افتراضياً.
 */

require_once dirname(__DIR__) . '/config/app.php';

$cfg = require app_path('config/database.php');
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset']),
    $cfg['user'],
    $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/** @return list<string> */
function verify_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute([$table, $column]);

    return (bool) $st->fetchColumn();
}

/** @return string */
function verify_enum_contains(PDO $pdo, string $table, string $column, string $value): string
{
    $st = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    $type = (string) ($st->fetchColumn() ?: '');

    return $type;
}

$requiredFiles = [
    'database/migrations/162_fin_checks_manage.sql',
    'database/migrations/172_fin_check_endorse.sql',
    'database/migrations/173_fin_check_action_undo.sql',
    'database/migrations/175_fin_check_endorse_supplier_ledger.sql',
    'includes/fin_checks_manage.php',
    'includes/crm_party_statement.php',
    'includes/acc_journal_party.php',
    'modules/finance/checks.php',
];

$fileChecks = [];
foreach ($requiredFiles as $rel) {
    $path = app_path($rel);
    $fileChecks[$rel] = is_readable($path);
}

$dbChecks = [
    'fin_voucher_check.lifecycle_status يحتوي endorsed' => stripos(
        verify_enum_contains($pdo, 'fin_voucher_check', 'lifecycle_status', 'endorsed'),
        'endorsed'
    ) !== false,
    'fin_voucher_check.endorsed_party_type' => verify_column_exists($pdo, 'fin_voucher_check', 'endorsed_party_type'),
    'fin_voucher_check.endorsed_party_id' => verify_column_exists($pdo, 'fin_voucher_check', 'endorsed_party_id'),
    'fin_voucher_check.endorse_notes' => verify_column_exists($pdo, 'fin_voucher_check', 'endorse_notes'),
    'fin_voucher_check.action_undo_at' => verify_column_exists($pdo, 'fin_voucher_check', 'action_undo_at'),
    'fin_voucher_check.undone_action' => verify_column_exists($pdo, 'fin_voucher_check', 'undone_action'),
    'fin_voucher_check.lifecycle_status (أساسي)' => verify_column_exists($pdo, 'fin_voucher_check', 'lifecycle_status'),
    'crm_supplier_ledger.txn_type يحتوي check_endorse' => stripos(
        verify_enum_contains($pdo, 'crm_supplier_ledger', 'txn_type', 'check_endorse'),
        'check_endorse'
    ) !== false,
    'sys_screen.fin_checks' => (static function (PDO $pdo): bool {
        $st = $pdo->query("SELECT 1 FROM sys_screen WHERE code = 'fin_checks' LIMIT 1");

        return (bool) $st->fetchColumn();
    })($pdo),
];

$migrationPaths = [
    'database/migrations/162_fin_checks_manage.sql',
    'database/migrations/172_fin_check_endorse.sql',
    'database/migrations/173_fin_check_action_undo.sql',
    'database/migrations/175_fin_check_endorse_supplier_ledger.sql',
];

$registry = [];
try {
    $pdo->query('SELECT 1 FROM sys_sql_migration LIMIT 1');
    $placeholders = implode(',', array_fill(0, count($migrationPaths), '?'));
    $st = $pdo->prepare("SELECT path, applied_at FROM sys_sql_migration WHERE path IN ($placeholders)");
    $st->execute($migrationPaths);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $registry[(string) $row['path']] = (string) ($row['applied_at'] ?? '');
    }
} catch (Throwable $e) {
    $registry['_error'] = $e->getMessage();
}

$allFilesOk = !in_array(false, $fileChecks, true);
$allDbOk = !in_array(false, $dbChecks, true);

echo "=== فحص ملفات الترحيل والكود ===\n";
foreach ($fileChecks as $path => $ok) {
    echo ($ok ? '[OK] ' : '[MISSING] ') . $path . "\n";
}

echo "\n=== فحص قاعدة البيانات ===\n";
foreach ($dbChecks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
}

echo "\n=== سجل sys_sql_migration (اختياري — الترحيلات 172–175 تُطبَّق عند فتح شاشة الشيكات) ===\n";
foreach ($migrationPaths as $path) {
    if (isset($registry[$path])) {
        echo "[REGISTERED] $path @ {$registry[$path]}\n";
    } else {
        echo "[NOT IN REGISTRY] $path — طبيعي إن لم تُفتح شاشة الشيكات بعد\n";
    }
}

echo "\n=== النتيجة ===\n";
if ($allFilesOk && $allDbOk) {
    echo "كل شيء جاهز.\n";
    exit(0);
}

if (!$allFilesOk) {
    echo "ارفع الملفات الناقصة إلى السيرفر.\n";
}
if (!$allDbOk) {
    echo "افتح شاشة الشيكات مرة واحدة (fin_checks) لتطبيق الترحيلات التلقائية، أو نفّذ ملفات SQL يدوياً في phpMyAdmin.\n";
}
exit(1);
