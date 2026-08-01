<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/header_check_notifications.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = header_check_notifications_collect_fresh(db());
    $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
    echo json_encode([
        'ok' => true,
        'enabled' => !empty($data['enabled']),
        'summary' => $summary,
        'alert_count' => (int) ($summary['alert_count'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server'], JSON_UNESCAPED_UNICODE);
}
