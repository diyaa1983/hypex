<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sys_user_location.php');
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_device_session.php');

header('Content-Type: application/json; charset=utf-8');

if (!app_gps_enabled()) {
    echo json_encode(['ok' => true, 'skipped' => true, 'disabled' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) (current_user()['id'] ?? 0);
if (
    $userId < 1
    || !isset($_POST['latitude'], $_POST['longitude'])
    || !is_numeric($_POST['latitude'])
    || !is_numeric($_POST['longitude'])
) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$missing = mobile_device_session_require_id();
if ($missing !== null) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $missing['error'],
        'message' => $missing['message'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$lat = (float) $_POST['latitude'];
$lng = (float) $_POST['longitude'];
$accuracy = isset($_POST['gps_accuracy']) && is_numeric($_POST['gps_accuracy'])
    ? (float) $_POST['gps_accuracy']
    : null;
$source = sal_invoice_gps_normalize_source(
    isset($_POST['gps_source']) ? (string) $_POST['gps_source'] : (mobile_is_context() ? 'mobile' : 'desktop')
);

try {
    $pdo = db();
    $deviceId = mobile_device_session_id_from_request();
    $block = mobile_device_session_blocking_conflict($pdo, $userId, $deviceId);
    if ($block !== null) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'device_in_use',
            'message' => $block['message'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = sys_user_location_save_ping($pdo, $userId, (float) $lat, (float) $lng, $accuracy, $source);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'ok' => true,
        'skipped' => !empty($result['skipped']),
    ];
    if ($deviceId !== '') {
        $deviceLabel = trim((string) ($_POST['device_label'] ?? ''));
        mobile_device_session_touch(
            $pdo,
            $userId,
            $deviceId,
            $deviceLabel !== '' ? $deviceLabel : null,
            $lat,
            $lng
        );
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('user_location_ping: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
