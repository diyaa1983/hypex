<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_delivery_invoice_link.php');
require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/sal_invoice_gps.php');

header('Content-Type: application/json; charset=utf-8');

$mayPost = is_logged_in() && (
    (user_can_sales_invoices() && user_can_action('action_post_sales_invoice'))
    || mobile_can_post_sales_invoice()
);

if (!$mayPost) {
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

if ($ids === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد أي فاتورة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$linkDeliveryId = (int) ($_POST['delivery_id'] ?? 0);
if ($linkDeliveryId > 0 && count($ids) === 1) {
    sal_delivery_invoice_link_ensure($pdo);
    $linkCheck = sal_invoice_validate_delivery_link($pdo, $linkDeliveryId, 0, null, $ids[0]);
    if ($linkCheck['ok']) {
        sal_invoice_set_delivery_id($pdo, $ids[0], $linkDeliveryId);
    }
}

try {
    $result = sal_invoice_post_by_ids($pdo, $ids);

    $gpsPayload = sal_invoice_gps_parse_request();
    $postUserId = (int) (current_user()['id'] ?? 0);
    $gpsSaved = 0;
    foreach ($ids as $rawInvId) {
        $invId = (int) $rawInvId;
        if ($invId < 1 || !sal_invoice_is_posted($pdo, $invId)) {
            continue;
        }
        if (sal_invoice_gps_apply_on_post($pdo, $invId, $gpsPayload, $postUserId > 0 ? $postUserId : null)) {
            $gpsSaved++;
        }
    }

    $posted = (int) $result['posted'];
    $skipped = (int) $result['skipped'];
    $warnings = $result['warnings'] ?? [];

    if ($posted === 0 && $result['errors'] === [] && $skipped > 0) {
        $msg = 'الفاتورة/الفواتير مرحّلة مسبقًا.';
    } elseif ($posted > 0) {
        $msg = 'تم ترحيل ' . $posted . ' فاتورة (مستودعيًا وماليًا).';
        if ($skipped > 0) {
            $msg .= ' (' . $skipped . ' كانت مرحّلة مسبقًا)';
        }
        if ($gpsSaved > 0 && app_gps_enabled()) {
            /* موقع GPS يُحفظ صامتاً — لا نذكره في رسالة الترحيل للمستخدم */
        }
    } else {
        $msg = 'لم يُرحَّل أي مستند.';
    }

    if ($result['errors'] !== []) {
        $msg .= ' — أخطاء: ' . implode('؛ ', array_slice($result['errors'], 0, 3));
    }

    $ok = $posted > 0 || ($result['errors'] === [] && $skipped > 0);

    echo json_encode([
        'ok' => $ok,
        'posted' => $posted,
        'skipped' => $skipped,
        'gps_saved' => $gpsSaved,
        'errors' => $result['errors'],
        'warnings' => $warnings,
        'warning' => $warnings[0] ?? null,
        'remaining_invoices' => crm_ledger_count_unposted($pdo)['invoices'],
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'تعذر الترحيل.'], JSON_UNESCAPED_UNICODE);
}
