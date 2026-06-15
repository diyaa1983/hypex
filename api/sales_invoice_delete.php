<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_invoice_delete.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/mobile_invoice.php');

header('Content-Type: application/json; charset=utf-8');

$mayDelete = is_logged_in() && (
    (user_can_sales_invoices() && user_can_action('action_delete_sales_invoice'))
    || mobile_can_delete_sales_invoice()
);

if (!$mayDelete) {
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
sal_invoice_ensure_schema($pdo);
crm_ledger_ensure_schema($pdo);

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
$unpostFirst = !empty($_POST['unpost_first']) || (string) ($_POST['unpost_first'] ?? '') === '1';

if ($ids === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد أي فاتورة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    if (mobile_is_context()) {
        foreach ($ids as $id) {
            if ($id < 1 || sal_invoice_is_fully_posted($pdo, $id)) {
                continue;
            }
            if (sal_invoice_line_count($pdo, $id) > 0) {
                $pdo->prepare('DELETE FROM sal_invoice_line WHERE invoice_id = ?')->execute([$id]);
            }
        }
    }
    $result = sal_invoice_delete_by_ids($pdo, $ids, $unpostFirst);
    if ($result['deleted'] === 0) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }

    $counts = crm_ledger_count_unposted($pdo);
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
        'remaining_invoices' => $counts['invoices'],
        'message' => $msg,
        'error' => $deleted > 0 ? null : $firstError,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'تعذر الحذف: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
