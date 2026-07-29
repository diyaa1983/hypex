<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_invoice_delete.php');
require_once app_path('includes/pur_invoice_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_invoices() || !user_can_action('action_delete_purchase_invoice')) {
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
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
pur_invoice_ensure_schema($pdo);

$ids = [];
if (isset($_POST['invoice_id'])) {
    $ids[] = (int) $_POST['invoice_id'];
}
if (isset($_POST['invoice_ids']) && is_array($_POST['invoice_ids'])) {
    foreach ($_POST['invoice_ids'] as $raw) {
        $ids[] = (int) $raw;
    }
}
$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

if ($ids === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد أي فاتورة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    $result = pur_invoice_delete_by_ids($pdo, $ids);
    if ($pdo->inTransaction()) {
        if ($result['deleted'] === 0) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    }

    $deleted = (int) $result['deleted'];
    if ($deleted > 0) {
        $msg = $deleted === 1 ? 'تم حذف الفاتورة.' : ('تم حذف ' . $deleted . ' فاتورة.');
    } elseif ($result['errors'] !== []) {
        $msg = implode('؛ ', array_slice($result['errors'], 0, 3));
    } else {
        $msg = 'لم يُحذف أي مستند.';
    }

    $firstError = $result['errors'][0] ?? null;

    echo json_encode([
        'ok' => $deleted > 0,
        'deleted' => $deleted,
        'errors' => $result['errors'],
        'message' => $msg,
        'error' => $deleted > 0 ? null : $firstError,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = $e->getMessage();
    if (stripos($msg, 'no active transaction') !== false) {
        error_log('purchase_invoice_delete no active transaction: ' . $msg);
        echo json_encode([
            'ok' => false,
            'error' => 'تعذر إتمام الحذف. حدّث الصفحة وتحقق من حالة الفاتورة ثم أعد المحاولة.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'تعذر الحذف: ' . $msg], JSON_UNESCAPED_UNICODE);
}
