<?php
declare(strict_types=1);

/**
 * CLI: التحقق من وجود فاتورة Oracle المرتبطة بطلب شراء.
 *   php oracle_order_status.php <order_id>
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$orderId = (int) ($argv[1] ?? 0);

try {
    require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
    require_once app_path('includes/oracle_order_post.php');

    $result = oracle_order_invoice_status(db(), $orderId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
