<?php
/**
 * خدمة ملفات CSS/JS/صور من القرص مباشرة (بدون curl إلى Node).
 * يفضّل hypex-node/public ثم assets/.
 */
declare(strict_types=1);

$rel = isset($_GET['p']) ? str_replace('\\', '/', (string) $_GET['p']) : '';
if (
    $rel === ''
    || str_contains($rel, '..')
    || str_starts_with($rel, '/')
    || !preg_match('#^(css|js)/[A-Za-z0-9._-]+$#', $rel)
) {
    http_response_code(400);
    exit;
}

$node = __DIR__ . DIRECTORY_SEPARATOR . 'hypex-node' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
    . str_replace('/', DIRECTORY_SEPARATOR, $rel);
$php = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
    . str_replace('/', DIRECTORY_SEPARATOR, $rel);
$file = is_file($node) ? $node : (is_file($php) ? $php : '');
if ($file === '') {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ico' => 'image/x-icon',
];
$mime = $types[$ext] ?? '';
if ($mime === '') {
    http_response_code(403);
    exit;
}

$mtime = (int) filemtime($file);
$body = file_get_contents($file);
if ($body === false) {
    http_response_code(500);
    exit;
}

// نفس rewriteJs في Node: مسارات الجذر داخل JS تحت /hypex
if ($ext === 'js') {
    $base = '/hypex';
    $rewritten = preg_replace_callback(
        '/([\'"`])(\/(?!\/)(?:api|assets|static|sales|purchases|customers|sales-reps|suppliers|accounting|inventory|hr|system|mobile|main|hub|menu|app|embed|login|logout|health|n)(?:\/[^\'"`]*)?)\1/',
        static function (array $m) use ($base): string {
            $path = $m[2];
            if ($path === $base || str_starts_with($path, $base . '/')) {
                return $m[0];
            }
            return $m[1] . $base . $path . $m[1];
        },
        $body
    );
    if (is_string($rewritten)) {
        $body = $rewritten;
    }
}

$etag = '"hx1-' . dechex($mtime) . '-' . dechex(strlen($body)) . '"';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=31536000, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
$inm = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
if ($inm === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . (string) strlen($body));
echo $body;
