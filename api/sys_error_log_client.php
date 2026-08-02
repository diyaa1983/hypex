<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sys_error_log.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf(is_string($csrf) ? $csrf : null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = trim((string) ($_POST['message'] ?? ''));
if ($message === '') {
    echo json_encode(['ok' => false, 'error' => 'empty'], JSON_UNESCAPED_UNICODE);
    exit;
}

$detail = trim((string) ($_POST['detail'] ?? ''));
$screen = trim((string) ($_POST['screen_code'] ?? ''));
$uri = trim((string) ($_POST['request_uri'] ?? ''));
if ($uri === '') {
    $uri = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($uri !== '') {
        $parts = parse_url($uri);
        $uri = (($parts['path'] ?? '') !== '' ? (string) $parts['path'] : '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}

sys_error_log_write([
    'source' => 'ui',
    'level' => 'error',
    'message' => $message,
    'detail' => $detail !== '' ? $detail : null,
    'request_uri' => $uri !== '' ? $uri : null,
    'screen_code' => $screen !== '' ? $screen : null,
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
