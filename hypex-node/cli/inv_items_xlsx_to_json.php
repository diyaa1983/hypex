<?php
/**
 * Dump first sheet of XLSX as JSON rows (no DB / bootstrap).
 * Usage: php inv_items_xlsx_to_json.php "C:\path\file.xlsx"
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "unreadable path\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/includes/xlsx_simple_reader.php';

try {
    $rows = xlsx_simple_read_rows($path, 50000);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo json_encode(['ok' => true, 'path' => $path, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
