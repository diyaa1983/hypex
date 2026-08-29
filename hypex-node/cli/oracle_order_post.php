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

try {
    require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

    $postFile = app_path('includes/oracle_order_post.php');
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($postFile, true);
    }
    require_once $postFile;

    $opts = [];
    foreach ($argv as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--batch-file=')) {
            continue;
        }
        $path = substr($arg, 13);
        if ($path !== '' && is_file($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) {
                if (isset($raw['batch_picks']) || isset($raw['need_overrides'])) {
                    if (isset($raw['batch_picks']) && is_array($raw['batch_picks'])) {
                        $opts['batch_picks'] = $raw['batch_picks'];
                    }
                    if (isset($raw['need_overrides']) && is_array($raw['need_overrides'])) {
                        $opts['need_overrides'] = $raw['need_overrides'];
                    }
                } else {
                    $opts['batch_picks'] = $raw;
                }
            }
        }
    }

    $result = oracle_post_customer_order(db(), $orderId, $userId, $dry, $opts);
    if (is_array($result)) {
        $result['stock_check_file'] = $postFile;
        $result['stock_check_mtime'] = @filemtime($postFile) ?: 0;
    }
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
