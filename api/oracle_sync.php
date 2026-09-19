<?php
declare(strict_types=1);

/**
 * API مزامنة Oracle المستمرة (بدون واجهة / مناسب للجدولة).
 *
 * أمثلة:
 *   GET  /api/oracle_sync.php?token=SECRET&entities=customers,item_groups,items
 *   GET  /api/oracle_sync.php?token=SECRET&entities=all
 *   POST /api/oracle_sync.php  Header: X-Oracle-Sync-Token: SECRET
 *
 * CLI:
 *   php api/oracle_sync.php --token=SECRET --entities=customers,item_groups,items
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/oracle_sync_service.php');

$isCli = (PHP_SAPI === 'cli');

$token = '';
$entitiesRaw = 'customers';

if ($isCli) {
    $opts = getopt('', ['token::', 'entities::']);
    $token = (string) ($opts['token'] ?? '');
    $entitiesRaw = (string) ($opts['entities'] ?? $entitiesRaw);
} else {
    $token = (string) (
        $_GET['token']
        ?? $_POST['token']
        ?? $_SERVER['HTTP_X_ORACLE_SYNC_TOKEN']
        ?? ''
    );
    $entitiesRaw = (string) ($_GET['entities'] ?? $_POST['entities'] ?? $entitiesRaw);
}

if (!oracle_sync_token_valid($token)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
        'hint' => 'اضبط sync_token في config/oracle.local.php وأرسله كـ token أو Header X-Oracle-Sync-Token',
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$entities = array_values(array_filter(array_map('trim', explode(',', $entitiesRaw))));
if ($entities === []) {
    $entities = ['customers'];
}

try {
    $pdo = db();
    oracle_customer_schema_ensure($pdo);
    oracle_customer_account_schema_ensure($pdo);
    oracle_item_schema_ensure($pdo);
    $result = oracle_run_continuous_sync($pdo, $entities);
    http_response_code(!empty($result['ok']) ? 200 : 422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
