<?php
/**
 * CLI: استيراد Excel المناطق/المندوبين.
 * الاستخدام:
 *   php tools/import_region_excel.php
 *   php tools/import_region_excel.php "C:\path\to\file.xlsx"
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_region_excel_import.php');

$path = $argv[1] ?? null;
$pdo = db();
try {
    $result = crm_region_excel_import($pdo, $path, true);
    echo ($result['ok'] ? "OK: " : "FAIL: ") . $result['message'] . PHP_EOL;
    if (!empty($result['stats'])) {
        echo json_encode($result['stats'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }
    if (!empty($result['warnings'])) {
        echo "Warnings:\n- " . implode("\n- ", $result['warnings']) . PHP_EOL;
    }
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
