<?php
declare(strict_types=1);

/**
 * CLI: جلب التشغيلات المتاحة من Oracle لطلب معتمد.
 *   php oracle_order_batches.php <order_id> [--opts-file=/path/to.json]
 *
 * opts JSON: { "cat_picks": [...], "need_overrides": [ { "srl": 1, "line_id": 10, "need": 4 } ] }
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$orderId = (int) ($argv[1] ?? 0);
$opts = [];

foreach ($argv as $arg) {
    if (!is_string($arg) || !str_starts_with($arg, '--opts-file=')) {
        continue;
    }
    $path = substr($arg, 12);
    if ($path !== '' && is_file($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        if (is_array($raw)) {
            $opts = $raw;
        }
    }
}

try {
    require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

    $postFile = app_path('includes/oracle_order_post.php');
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($postFile, true);
    }
    require_once $postFile;

    $result = oracle_order_batch_picker_data(db(), $orderId, $opts);
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
