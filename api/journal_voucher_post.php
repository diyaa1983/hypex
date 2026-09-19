<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/acc_journal.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('journal_voucher') || !user_can_action('action_post_journal_voucher')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_POST['entry_id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    echo json_encode(['ok' => false, 'message' => 'احفظ السند أولاً ثم اضغط ترحيل.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!acc_journal_ensure_schema($pdo)) {
    echo json_encode(['ok' => false, 'message' => 'جداول القيود غير موجودة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    acc_journal_assert_manual_voucher($pdo, $id);
    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ensure_schema($pdo);
    require_once app_path('includes/acc_gl.php');
    acc_gl_ensure_schema($pdo);
    $pdo->beginTransaction();
    acc_journal_post_by_id($pdo, $id);
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    $entry = acc_journal_api_entry($pdo, $id);
    echo json_encode([
        'ok' => true,
        'message' => 'تم ترحيل سند القيد.',
        'entry' => $entry,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = trim($e->getMessage());
    if ($msg === '' || stripos($msg, 'no active transaction') !== false) {
        $msg = 'تعذر الترحيل.';
    }
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
