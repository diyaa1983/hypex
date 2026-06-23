<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/acc_journal.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('journal_voucher') || !user_can_action('action_delete_journal_voucher')) {
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
$id = (int) ($_POST['id'] ?? 0);

try {
    acc_journal_assert_manual_voucher($pdo, $id);
    $pdo->beginTransaction();
    acc_journal_delete_draft($pdo, $id);
    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => 'تم حذف سند القيد.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذر الحذف.'], JSON_UNESCAPED_UNICODE);
}
