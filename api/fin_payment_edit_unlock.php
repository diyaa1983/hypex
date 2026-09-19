<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_voucher_unpost.php');
require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('cash_payment') || !user_can_action('action_edit_cash_payment')) {
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

$id = (int) ($_POST['voucher_id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    echo json_encode(['ok' => false, 'message' => 'حدد سند الصرف أولاً.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
fin_voucher_ensure_schema_full($pdo);

$row = fin_voucher_load($pdo, $id, 'payment');
if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'سند الصرف غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (fin_voucher_is_cancelled($pdo, $id)) {
    echo json_encode(['ok' => false, 'message' => 'لا يمكن تعديل سند ملغى.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!fin_voucher_is_posted($pdo, $id)) {
    echo json_encode(['ok' => false, 'message' => 'السند غير مرحّل — يمكنك التعديل مباشرة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    $result = fin_voucher_unpost_payments_by_ids($pdo, [$id]);
    if (!empty($result['errors'])) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => implode("\n", $result['errors'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int) ($result['unposted'] ?? 0) < 1) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => 'تعذر فك ترحيل السند.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'تم فك الترحيل. يمكنك تعديل السند ثم الحفظ وإعادة الترحيل.',
        'is_posted' => false,
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
