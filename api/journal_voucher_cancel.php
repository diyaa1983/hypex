<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/acc_journal.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('journal_voucher') || !user_can_action('action_cancel_journal_voucher')) {
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

$entryId = (int) ($_POST['journal_id'] ?? $_POST['entry_id'] ?? $_POST['id'] ?? 0);
if ($entryId < 1) {
    echo json_encode(['ok' => false, 'message' => 'معرّف القيد غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
acc_journal_ensure_schema($pdo);

try {
    acc_journal_assert_manual_voucher($pdo, $entryId);
    $pdo->beginTransaction();
    acc_journal_cancel_by_id($pdo, $entryId);
    $pdo->commit();
    $entry = acc_journal_api_entry($pdo, $entryId);
    echo json_encode([
        'ok' => true,
        'message' => 'تم إلغاء سند القيد. يبقى في السجل برقم التسلسل.',
        'status' => 'cancelled',
        'is_posted' => false,
        'entry' => $entry,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'message' => $e->getMessage() ?: 'تعذر الإلغاء.'], JSON_UNESCAPED_UNICODE);
}
