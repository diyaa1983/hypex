<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/einvoice_settings.php');
require_once app_path('includes/einvoice_schema.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/invoice_amount_decimals.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_sales_invoices() || !user_can_action('sales_send_einvoice')) {
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

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
if ($invoiceId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم تُحدَّد فاتورة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_invoice_ensure_schema($pdo);
einvoice_ensure_schema($pdo);

try {
    $st = $pdo->prepare('SELECT id, status, einv_qr, einv_status FROM sal_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'الفاتورة غير موجودة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $wasSent = is_string($row['einv_qr'] ?? null) && trim((string) $row['einv_qr']) !== '';

    $pdo->beginTransaction();

    // 1) مسح حقول الإرسال السابق للفوترة، حتى تُعتبر الفاتورة "غير مُرسلة" مجددًا.
    $cols = [
        'einv_status' => null,
        'einv_results' => null,
        'einv_signed_invoice' => null,
        'einv_qr' => null,
        'einv_num' => null,
        'einv_inv_uuid' => null,
        'einv_sent_at' => null,
    ];
    $sets = [];
    $vals = [];
    foreach ($cols as $col => $val) {
        if (!einvoice_column_exists($pdo, 'sal_invoice', $col)) {
            continue;
        }
        $sets[] = "`{$col}` = ?";
        $vals[] = $val;
    }
    if ($sets !== []) {
        $vals[] = $invoiceId;
        $pdo->prepare('UPDATE sal_invoice SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    // 2) إعادة احتساب أسعار الأسطر والمجاميع لضمان عدم وجود فروقات عند إعادة الإرسال.
    //    تتم إعادة الحساب فقط للفاتورة غير المرحّلة، لتجنّب الإخلال بقيود المخزون
    //    والقيود المالية المرحّلة المرتبطة بالفاتورة الحالية.
    $isPosted = (($row['status'] ?? '') === 'confirmed');
    if (!$isPosted) {
        $dp = sal_invoice_amount_decimals($pdo, $invoiceId);
        sal_invoice_persist_normalized($pdo, $invoiceId, $dp);
    }

    $pdo->commit();

    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();

    $msg = $wasSent
        ? 'تم فك إرسال الفاتورة للفوترة.'
        : 'لم تكن الفاتورة مُرسَلة للفوترة.';
    if (!$isPosted) {
        $msg .= ' وأُعيد احتساب الأسعار والمجاميع.';
    } else {
        $msg .= ' الفاتورة مرحّلة، فلم تُعَد محاسبة الأسطر (احذف الترحيل أولًا لتعديل الأسعار).';
    }
    $msg .= ' يمكنك الآن استخدام زر «إرسال للفوترة» لإعادة الإرسال بقيم محدّثة ومطابقة لما يَعرضه النظام.';

    echo json_encode([
        'ok' => true,
        'was_sent' => $wasSent,
        'recalculated' => !$isPosted,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'تعذر فك الإرسال: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
