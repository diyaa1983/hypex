<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_debit_note.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('debit_notes') || !user_can_action('action_delete_debit_note')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf($_POST['_csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!fin_debit_note_ensure_schema($pdo)) {
    echo json_encode(['ok' => false, 'message' => 'الجداول غير موجودة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_POST['note_id'] ?? 0);
if ($id < 1) {
    echo json_encode(['ok' => true, 'cleared' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    fin_debit_note_delete($pdo, $id);
    echo json_encode(['ok' => true, 'message' => 'تم حذف الإشعار.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'تعذر الحذف.'], JSON_UNESCAPED_UNICODE);
}
