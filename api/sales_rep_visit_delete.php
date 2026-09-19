<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_rep_visit.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('report_sales_rep_visits') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can_action('action_delete_sales_rep_visit') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'لا تملك صلاحية حذف الزيارات.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawIds = $body['line_ids'] ?? $body['ids'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = [];
}
$lineIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $v): bool => $v > 0)));
if ($lineIds === []) {
    echo json_encode(['ok' => false, 'message' => 'لم يُحدَّد أي زيارة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$result = sal_rep_visit_delete_lines($pdo, $lineIds, (int) (current_user()['id'] ?? 0));
$deleted = (int) ($result['deleted'] ?? 0);
$skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];
$message = $deleted > 0
    ? 'تم حذف ' . $deleted . ' زيارة.'
    : 'لم يُحذف أي زيارة.';
if ($skipped !== []) {
    $message .= ' ' . count($skipped) . ' زيارة لم تُحذف (مرتبطة بطلب أو غير صالحة).';
}

echo json_encode([
    'ok' => $deleted > 0,
    'deleted' => $deleted,
    'skipped' => $skipped,
    'message' => $message,
], JSON_UNESCAPED_UNICODE);
