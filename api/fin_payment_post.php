<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_voucher_post.php');
require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('cash_payment') || !user_can_action('action_post_cash_payment')) {
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
    // تهيئة الجداول قبل المعاملة — MySQL يُنهي المعاملة تلقائياً عند DDL
    fin_voucher_prepare_payment_post_schemas($pdo);

    $pdo->beginTransaction();
    $result = fin_voucher_post_payments_by_ids($pdo, $ids);
    if (!empty($result['errors'])) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['ok' => false, 'message' => implode("\n", $result['errors'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    // محاولة فورية لبريد الشيكات الصادرة ضمن نافذة التنبيه
    if ((int) ($result['posted'] ?? 0) > 0) {
        try {
            require_once app_path('includes/fin_out_check_due_email.php');
            fin_out_check_due_email_run($pdo);
        } catch (Throwable $e) {
            // لا يفشل الترحيل بسبب البريد
        }
    }

    $counts = fin_voucher_count_unposted($pdo, 'payment');
    $posted = (int) $result['posted'];
    $skipped = (int) $result['skipped'];

    if ($posted === 0 && $skipped > 0) {
        $msg = 'السند/السندات مرحّلة مسبقًا.';
    } elseif ($posted > 0) {
        $msg = 'تم ترحيل ' . $posted . ' سند صرف (قيد محاسبي + كشف حساب الطرف).';
        if ($skipped > 0) {
            $msg .= ' (' . $skipped . ' كانت مرحّلة مسبقًا)';
        }
    } else {
        $msg = 'لم يُرحَّل أي سند.';
    }

    echo json_encode([
        'ok' => true,
        'posted' => $posted,
        'skipped' => $skipped,
        'remaining_payments' => (int) ($counts['payments'] ?? 0),
        'is_posted' => count($ids) === 1,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = trim($e->getMessage());
    if ($msg === '' || stripos($msg, 'no active transaction') !== false) {
        $msg = 'تعذر الترحيل.';
    }
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
