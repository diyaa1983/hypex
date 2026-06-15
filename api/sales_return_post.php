<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/sal_return_schema.php');

header('Content-Type: application/json; charset=utf-8');

require_once app_path('includes/mobile_return.php');
if (!is_logged_in() || !user_can_mobile_sales_returns() || !user_can_action('action_post_sales_return')) {
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
require_once app_path('includes/inv_stock.php');
inv_stock_move_ensure_table($pdo);

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
    $result = sal_return_post_by_ids($pdo, $ids);

    $counts = crm_ledger_count_unposted($pdo);
    $posted = (int) $result['posted'];
    $skipped = (int) $result['skipped'];

    if ($posted === 0 && $result['errors'] === [] && $skipped > 0) {
        $msg = 'المرتجع/المرتجعات مرحّلة مسبقًا.';
    } elseif ($posted > 0) {
        $msg = 'تم ترحيل ' . $posted . ' مرتجع (إرجاع المخزون إلى المستودع وتخفيض ذمة العميل).';
        if ($skipped > 0) {
            $msg .= ' (' . $skipped . ' كانت مرحّلة مسبقًا)';
        }
    } else {
        $msg = 'لم يُرحَّل أي مستند.';
    }

    $warnings = $result['warnings'] ?? [];
    if ($result['errors'] !== []) {
        $msg .= ' — أخطاء: ' . implode('؛ ', array_slice($result['errors'], 0, 3));
    }
    if ($warnings !== []) {
        $msg .= ' — تنبيه: ' . implode('؛ ', array_slice($warnings, 0, 2));
    }

    $firstError = $result['errors'][0] ?? null;

    echo json_encode([
        'ok' => $posted > 0 || ($result['errors'] === [] && $skipped > 0),
        'posted' => $posted,
        'skipped' => $skipped,
        'errors' => $result['errors'],
        'warnings' => $warnings,
        'warning' => $warnings[0] ?? null,
        'remaining_returns' => $counts['returns'],
        'message' => $msg,
        'error' => $firstError,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    $detail = trim($e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $detail !== '' ? ('تعذر الترحيل: ' . $detail) : 'تعذر الترحيل.',
        'message' => $detail !== '' ? ('تعذر الترحيل: ' . $detail) : 'تعذر الترحيل.',
    ], JSON_UNESCAPED_UNICODE);
}
