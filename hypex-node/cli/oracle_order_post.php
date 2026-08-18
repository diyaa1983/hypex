<?php
declare(strict_types=1);

/**
 * CLI: ترحيل طلب شراء معتمد إلى فاتورة Oracle.
 *   php oracle_order_post.php <order_id> [user_id] [--dry]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$orderId = (int) ($argv[1] ?? 0);
$userId = (int) ($argv[2] ?? 0);
$dry = in_array('--dry', $argv, true) || in_array('dry', $argv, true);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/oracle_order_post.php');

$result = oracle_post_customer_order(db(), $orderId, $userId, $dry);
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
