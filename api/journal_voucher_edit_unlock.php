<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/acc_journal.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('journal_voucher') || !user_can_action('action_edit_journal_voucher')) {
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

$password = (string) ($_POST['password'] ?? '');
if (!verify_current_user_password($password)) {
    echo json_encode(['ok' => false, 'message' => 'كلمة المرور غير صحيحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_POST['entry_id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    echo json_encode(['ok' => false, 'message' => 'حدد سند القيد أولاً.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!acc_journal_ensure_schema($pdo)) {
    echo json_encode(['ok' => false, 'message' => 'جداول القيود غير موجودة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    acc_journal_assert_manual_voucher($pdo, $id);
    $pdo->beginTransaction();
    acc_journal_unpost_by_id($pdo, $id);
    $pdo->commit();

    $entry = acc_journal_api_entry($pdo, $id);
    echo json_encode([
        'ok' => true,
        'message' => 'تم فك الترحيل. يمكنك تعديل الحركات ثم الحفظ وإعادة الترحيل.',
        'entry' => $entry,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'message' => 'تعذر بدء التعديل.'], JSON_UNESCAPED_UNICODE);
}
