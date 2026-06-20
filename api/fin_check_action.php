<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_checks_manage.php');
require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('fin_checks')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
fin_voucher_ensure_schema_full($pdo);
fin_checks_manage_ensure_schema($pdo);

$action = trim((string) ($_POST['action'] ?? ''));
$checkId = (int) ($_POST['check_id'] ?? 0);
$actionDate = trim((string) ($_POST['action_date'] ?? ''));

if ($checkId < 1) {
    echo json_encode(['ok' => false, 'message' => 'معرّف الشيك غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    if ($action === 'clear') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $result = fin_checks_manage_clear($pdo, $checkId, $accountId, $actionDate);
    } elseif ($action === 'return') {
        $reason = trim((string) ($_POST['return_reason'] ?? ''));
        $result = fin_checks_manage_return($pdo, $checkId, $reason, $actionDate);
    } else {
        throw new RuntimeException('إجراء غير معروف.');
    }
    $pdo->commit();
    flash_set('success', (string) ($result['message'] ?? 'تم الترحيل.'));
    echo json_encode([
        'ok' => true,
        'message' => (string) ($result['message'] ?? 'تمت العملية.'),
        'journal_id' => (int) ($result['journal_id'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
