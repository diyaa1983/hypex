<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/pur_invoice_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_invoices() || !user_can_action('action_post_purchase_invoice')) {
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
crm_supplier_ledger_ensure_schema($pdo);

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
    $result = pur_invoice_post_by_ids($pdo, $ids);

    $posted = (int) $result['posted'];
    $skipped = (int) $result['skipped'];
    $warnings = $result['warnings'] ?? [];

    if ($posted === 0 && $result['errors'] === [] && $skipped > 0) {
        $msg = 'الفاتورة/الفواتير مرحّلة مسبقًا.';
    } elseif ($posted > 0) {
        $msg = 'تم ترحيل ' . $posted . ' فاتورة شراء (مستودعيًا وذمة المورد).';
        if ($skipped > 0) {
            $msg .= ' (' . $skipped . ' كانت مرحّلة مسبقًا)';
        }
    } else {
        $msg = 'لم يُرحَّل أي مستند.';
    }

    if ($result['errors'] !== []) {
        $msg .= ' — أخطاء: ' . implode('؛ ', array_slice($result['errors'], 0, 3));
    }
    if ($warnings !== []) {
        $msg .= ' — تنبيه: ' . implode('؛ ', array_slice($warnings, 0, 2));
    }

    $ok = $posted > 0 || ($result['errors'] === [] && $skipped > 0);

    echo json_encode([
        'ok' => $ok,
        'posted' => $posted,
        'skipped' => $skipped,
        'errors' => $result['errors'],
        'warnings' => $warnings,
        'warning' => $warnings[0] ?? null,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    $detail = $e->getMessage();
    echo json_encode(
        [
            'ok' => false,
            'error' => 'تعذر الترحيل: ' . $detail,
            'message' => 'تعذر الترحيل: ' . $detail,
        ],
        JSON_UNESCAPED_UNICODE
    );
}
