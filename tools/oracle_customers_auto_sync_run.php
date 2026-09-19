<?php
declare(strict_types=1);

/**
 * مشغّل المزامنة التلقائية لعملاء Oracle (Task Scheduler / Agent).
 *
 *   php tools/oracle_customers_auto_sync_run.php
 *   php tools/oracle_customers_auto_sync_run.php --force
 *
 * يحترم auto_sync.enabled و interval_minutes في config/oracle.local.php
 * --force يتجاهل الفاصل الزمني.
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_customers_auto_sync.php');
require_once app_path('includes/oracle_sync_service.php');

$force = in_array('--force', $argv ?? [], true) || in_array('force', $argv ?? [], true);
$settings = oracle_customers_auto_sync_settings();

$out = [
    'ok' => false,
    'skipped' => false,
    'at' => date('Y-m-d H:i:s'),
    'settings' => $settings,
    'message' => '',
];

if (!$settings['enabled'] && !$force) {
    $out['skipped'] = true;
    $out['ok'] = true;
    $out['message'] = 'المزامنة التلقائية متوقفة (auto_sync.enabled=false).';
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

if (!$force && $settings['last_run_at'] !== '') {
    $lastTs = strtotime($settings['last_run_at']);
    if ($lastTs !== false) {
        $elapsed = time() - $lastTs;
        $need = max(60, $settings['interval_minutes'] * 60);
        if ($elapsed < $need) {
            $out['skipped'] = true;
            $out['ok'] = true;
            $out['message'] = 'تخطّي: لم يمرّ الفاصل بعد (باقي '
                . max(0, $need - $elapsed) . ' ث).';
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
            exit(0);
        }
    }
}

try {
    $result = oracle_customers_run_saved_sync(db());
    $out['ok'] = !empty($result['ok']);
    $out['message'] = (string) ($result['message'] ?? '');
    $out['sync'] = $result;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($out['ok'] ? 0 : 1);
} catch (Throwable $e) {
    oracle_customers_auto_sync_mark_run(false, $e->getMessage());
    $out['ok'] = false;
    $out['message'] = $e->getMessage();
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
