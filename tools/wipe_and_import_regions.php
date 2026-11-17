<?php
/**
 * حذف كل المناطق/العناوين ثم استيرادها من Excel (أعمدة المنطقة + العنوان فقط).
 *
 * الاستخدام:
 *   php tools/wipe_and_import_regions.php --wipe-only
 *   php tools/wipe_and_import_regions.php "C:\path\to\file.xlsx"
 *   php tools/wipe_and_import_regions.php --no-wipe "C:\path\to\file.xlsx"
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_region_excel_import.php');

$args = array_slice($argv, 1);
$wipeOnly = in_array('--wipe-only', $args, true);
$noWipe = in_array('--no-wipe', $args, true);
$path = null;
foreach ($args as $a) {
    if ($a === '' || str_starts_with($a, '--')) {
        continue;
    }
    $path = $a;
    break;
}

$pdo = db();
try {
    if ($wipeOnly) {
        $result = crm_region_wipe_all($pdo);
        echo ($result['ok'] ? "OK: " : "FAIL: ") . $result['message'] . PHP_EOL;
        if (!empty($result['stats'])) {
            echo json_encode($result['stats'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        }
        exit($result['ok'] ? 0 : 1);
    }

    $result = crm_region_excel_import_regions_only($pdo, $path, !$noWipe);
    echo ($result['ok'] ? "OK: " : "FAIL: ") . $result['message'] . PHP_EOL;
    if (!empty($result['stats'])) {
        echo json_encode($result['stats'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }
    if (!empty($result['columns'])) {
        echo 'columns: ' . json_encode($result['columns'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
