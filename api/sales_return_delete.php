<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_delete.php');

header('Content-Type: application/json; charset=utf-8');

require_once app_path('includes/mobile_return.php');
if (!is_logged_in() || !user_can_mobile_sales_returns() || !user_can_action('action_delete_sales_return')) {
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
sal_return_ensure_schema($pdo);
crm_ledger_ensure_schema($pdo);

$ids = [];
if (isset($_POST['return_id'])) {
    $ids[] = (int) $_POST['return_id'];
}
if (isset($_POST['return_ids']) && is_array($_POST['return_ids'])) {
    foreach ($_POST['return_ids'] as $raw) {
        $ids[] = (int) $raw;
    }
}
$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

if ($ids === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد أي مرتجع.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = sal_return_delete_by_ids($pdo, $ids);

    $counts = crm_ledger_count_unposted($pdo);
    $deleted = (int) $result['deleted'];

    if ($deleted > 0) {
        $msg =
            $deleted === 1
                ? 'تم حذف المرتجع غير المرحّل. المرتجع لم يُرحَّل على حساب العميل ولم يُسجَّل مخزون باسمه؛ الحذف يزيل السجل فقط ويمكنك إنشاء مرتجع جديد بنفس الفاتورة والكميات.'
                : ('تم حذف ' . $deleted . ' مرتجعات غير مرحّلة. لم يكن لها أثر مالي أو مخزني؛ يمكنك إنشاء مرتجعات جديدة عند الحاجة.');
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
        'remaining_returns' => $counts['returns'],
        'message' => $msg,
        'error' => $deleted > 0 ? null : $firstError,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'تعذر الحذف.'], JSON_UNESCAPED_UNICODE);
}
