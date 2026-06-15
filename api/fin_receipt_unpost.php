<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_voucher_unpost.php');
require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

require_once app_path('includes/mobile_receipt.php');

if (!is_logged_in() || !mobile_can_unpost_receipt()) {
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

$ids = [];
if (isset($_POST['voucher_id'])) {
    $ids[] = (int) $_POST['voucher_id'];
}
if (isset($_POST['voucher_ids']) && is_array($_POST['voucher_ids'])) {
    foreach ($_POST['voucher_ids'] as $raw) {
        $ids[] = (int) $raw;
    }
}
$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

if ($ids === []) {
    echo json_encode(['ok' => false, 'message' => 'لم يُحدَّد أي سند.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    $result = fin_voucher_unpost_receipts_by_ids($pdo, $ids);
    if (!empty($result['errors'])) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => implode("\n", $result['errors'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->commit();

    $unposted = (int) $result['unposted'];
    $skipped = (int) $result['skipped'];
    if ($unposted === 0 && $skipped > 0) {
        $msg = 'السند غير مرحّل أو لا توجد آثار ترحيل.';
    } elseif ($unposted > 0) {
        $msg = 'تم إلغاء ترحيل ' . $unposted . ' سند قبض.';
        if ($skipped > 0) {
            $msg .= ' (' . $skipped . ' لم يكن مرحّلاً)';
        }
    } else {
        $msg = 'لم يُلغَ ترحيل أي سند.';
    }

    echo json_encode([
        'ok' => true,
        'unposted' => $unposted,
        'skipped' => $skipped,
        'is_posted' => count($ids) === 1 ? fin_voucher_is_posted($pdo, $ids[0]) : null,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'message' => $e->getMessage() ?: 'تعذر إلغاء الترحيل.'], JSON_UNESCAPED_UNICODE);
}
