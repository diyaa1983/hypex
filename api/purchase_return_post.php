<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_return_post.php');
require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/crm_supplier_ledger.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_returns() || !user_can_action('action_post_purchase_return')) {
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
pur_return_ensure_schema($pdo);
crm_supplier_ledger_ensure_schema($pdo);

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
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد أي مردود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    $result = pur_return_post_by_ids($pdo, $ids);
    if ($result['errors'] !== []) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }

    $posted = (int) $result['posted'];
    $skipped = (int) $result['skipped'];

    if ($posted === 0 && $result['errors'] === [] && $skipped > 0) {
        $msg = 'المردود غير مرحّل أو كان مرحّلاً مسبقًا.';
    } elseif ($posted > 0) {
        $msg = 'تم ترحيل ' . $posted . ' مردود مشتريات (مخزون وذمة المورد والقيد المحاسبي).';
        if ($skipped > 0) {
            $msg .= ' (' . $skipped . ' كان مرحّلاً مسبقًا)';
        }
    } else {
        $msg = 'لم يُرحَّل أي مستند.';
    }

    if ($result['errors'] !== []) {
        $msg .= ' — أخطاء: ' . implode('؛ ', array_slice($result['errors'], 0, 3));
    }

    $counts = crm_supplier_ledger_count_unposted($pdo);

    echo json_encode([
        'ok' => $posted > 0 || ($result['errors'] === [] && $skipped > 0),
        'posted' => $posted,
        'skipped' => $skipped,
        'errors' => $result['errors'],
        'remaining_returns' => $counts['returns'],
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $detail = trim($e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $detail !== '' ? ('تعذر الترحيل: ' . $detail) : 'تعذر الترحيل.',
    ], JSON_UNESCAPED_UNICODE);
}
