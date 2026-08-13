<?php
declare(strict_types=1);

/**
 * تشغيل مزامنة عملاء Oracle من CLI (يستدعيه Node بدون واجهة).
 * الاستخدام: php hypex-node/cli/oracle_customers_sync_run.php
 */
$root = dirname(__DIR__, 2);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_customer_sync.php');
require_once app_path('includes/oracle_sync_service.php');

header_remove();
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

$pdo = db();
oracle_customer_schema_ensure($pdo);

$out = [
    'ok' => false,
    'enabled' => oracle_is_enabled(),
    'has_driver' => oracle_php_has_oracle_driver(),
    'mapping' => null,
    'sync' => null,
    'message' => '',
];

$map = oracle_customers_saved_mapping();
if ($map) {
    $out['mapping'] = [
        'owner' => $map['owner'],
        'table' => $map['table'],
        'last_synced_at' => $map['last_synced_at'] ?? '',
    ];
}

$action = 'status';
if (PHP_SAPI === 'cli') {
    $opts = getopt('', ['action::']);
    $action = (string) ($opts['action'] ?? 'status');
} else {
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'status');
}

try {
    if ($action === 'test') {
        $conn = oracle_connect();
        $out['ok'] = !empty($conn['ok']);
        $out['message'] = (string) ($conn['message'] ?? '');
        $out['driver'] = (string) ($conn['driver'] ?? '');
    } elseif ($action === 'sync') {
        if ($map === null) {
            $out['message'] = 'لا يوجد تعيين محفوظ. افتح إعداد الربط أولاً واحفظ المزامنة الأولى مع ربط الأعمدة.';
        } else {
            $sync = oracle_sync_customers_from_saved_config($pdo);
            $out['sync'] = $sync;
            $out['ok'] = empty($sync['errors']);
            $out['message'] = 'مزامنة: جديد ' . (int) ($sync['inserted'] ?? 0)
                . ' | محدّث ' . (int) ($sync['updated'] ?? 0)
                . ' | متجاوز ' . (int) ($sync['skipped'] ?? 0);
            if (!empty($sync['errors'])) {
                $out['message'] .= "\n" . implode("\n", array_slice($sync['errors'], 0, 8));
            }
        }
    } else {
        $out['ok'] = true;
        $out['message'] = $map
            ? ('تعيين محفوظ: ' . $map['owner'] . '.' . $map['table'])
            : 'لا يوجد تعيين محفوظ بعد.';
    }
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['message'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo PHP_EOL;
