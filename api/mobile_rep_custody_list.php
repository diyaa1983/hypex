<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_rep_custody_list()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'لا توجد صلاحية لعرض قائمة العهدات.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    inv_wh_move_ensure_schema($pdo);
    $ctx = mobile_rep_custody_context($pdo);
    if ($ctx === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'no_rep',
            'message' => 'حسابك غير مربوط بمندوب أو مستودع عهدة.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    $rows = mobile_rep_custody_list_rows($pdo, $ctx, $q, 100);

    echo json_encode([
        'ok' => true,
        'moves' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_rep_custody_list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل قائمة العهدات.',
    ], JSON_UNESCAPED_UNICODE);
}
