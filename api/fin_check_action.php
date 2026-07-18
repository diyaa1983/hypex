<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_checks_manage.php');
require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !(user_can('fin_checks') || user_can('fin_outgoing_checks'))) {
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
// تهيئة الربط المحاسبي قبل المعاملة — MySQL يُنهي المعاملة تلقائياً عند أي DDL
require_once app_path('includes/acc_gl.php');
require_once app_path('includes/fin_voucher_post.php');
acc_gl_ensure_schema($pdo);
fin_voucher_prepare_payment_post_schemas($pdo);

$action = trim((string) ($_POST['action'] ?? ''));
$checkId = (int) ($_POST['check_id'] ?? 0);
$actionDate = trim((string) ($_POST['action_date'] ?? ''));

if ($checkId < 1) {
    echo json_encode(['ok' => false, 'message' => 'معرّف الشيك غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$canIncoming = user_can('fin_checks');
$canOutgoing = user_can('fin_outgoing_checks');

try {
    $pdo->beginTransaction();

    $checkPreview = fin_checks_manage_load_check($pdo, $checkId);
    if (!$checkPreview) {
        throw new RuntimeException('الشيك غير موجود.');
    }
    $voucherType = (string) ($checkPreview['voucher_type'] ?? '');
    if ($voucherType === 'payment' && !$canOutgoing && !$canIncoming) {
        throw new RuntimeException('لا صلاحية على الشيكات الصادرة.');
    }
    if ($voucherType === 'receipt' && !$canIncoming) {
        throw new RuntimeException('لا صلاحية على الشيكات الواردة.');
    }
    // من صلاحية الشيكات الصادرة فقط: صرف / إلغاء صرف فقط.
    if (!$canIncoming && $canOutgoing && !in_array($action, ['clear', 'undo'], true)) {
        throw new RuntimeException('الإجراء غير مسموح من صلاحية الشيكات الصادرة.');
    }

    if ($action === 'clear') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $result = fin_checks_manage_clear($pdo, $checkId, $accountId, $actionDate);
    } elseif ($action === 'return') {
        $reason = trim((string) ($_POST['return_reason'] ?? ''));
        $result = fin_checks_manage_return($pdo, $checkId, $reason, $actionDate);
    } elseif ($action === 'undo') {
        $result = fin_checks_manage_undo($pdo, $checkId);
    } elseif ($action === 'endorse') {
        $partyType = trim((string) ($_POST['party_type'] ?? ''));
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $notes = trim((string) ($_POST['endorse_notes'] ?? ''));
        $result = fin_checks_manage_endorse($pdo, $checkId, $partyType, $partyId, $actionDate, $notes);
    } else {
        throw new RuntimeException('إجراء غير معروف.');
    }
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();
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
