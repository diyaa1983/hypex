<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/hr_attendance.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === [] && !empty($_POST)) {
    $payload = $_POST;
    if (isset($payload['punches']) && is_string($payload['punches'])) {
        $decodedPunches = json_decode($payload['punches'], true);
        if (is_array($decodedPunches)) {
            $payload['punches'] = $decodedPunches;
        }
    }
}

$token = trim((string) (
    $payload['token']
    ?? $_SERVER['HTTP_X_HR_ATT_TOKEN']
    ?? $_SERVER['HTTP_X_SYNC_TOKEN']
    ?? ''
));

$punches = $payload['punches'] ?? [];
if (!is_array($punches)) {
    $punches = [];
}

try {
    $pdo = db();
    hr_attendance_ensure_schema($pdo);

    if (!hr_attendance_verify_sync_token($pdo, $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'رمز المزامنة غير صالح.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($punches === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'لا توجد سجلات بصمة في الطلب.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (count($punches) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'الحد الأقصى 2000 سجل في الطلب الواحد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = hr_attendance_push_punches($pdo, $punches);
    echo json_encode([
        'ok' => true,
        'inserted' => (int) ($result['inserted'] ?? 0),
        'skipped' => (int) ($result['skipped'] ?? 0),
        'unlinked' => (int) ($result['unlinked'] ?? 0),
        'parse_failed' => (int) ($result['parse_failed'] ?? 0),
        'last_punch_time' => $result['last_punch_time'] ?? null,
        'message' => (string) ($result['message'] ?? ''),
        'source_keys_inserted' => $result['source_keys_inserted'] ?? [],
        'source_keys_processed' => $result['source_keys_processed'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage() ?: 'تعذر استلام البصمات.',
    ], JSON_UNESCAPED_UNICODE);
}
