<?php
declare(strict_types=1);

/**
 * CLI: جلب التشغيلات المتاحة من Oracle STOCK لطلب معتمد.
 *   php oracle_order_batches.php <order_id>
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$orderId = (int) ($argv[1] ?? 0);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$postFile = app_path('includes/oracle_order_post.php');
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate($postFile, true);
}
require_once $postFile;

$result = oracle_order_batch_picker_data(db(), $orderId);
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
