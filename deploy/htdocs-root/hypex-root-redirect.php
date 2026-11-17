<?php
declare(strict_types=1);

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$query = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '');
$qs = $query !== '' ? '?' . $query : '';

if ($path === '/' || $path === '') {
    header('Location: /hypex/' . ($qs !== '' ? $qs : ''), true, 302);
    exit;
}

if (preg_match('#^/(n|sales|customers|purchases|sales-reps|accounting|inventory|hr|system|suppliers|mobile|main|hub|menu|app|embed|login|logout)(/|$)#', $path)) {
    header('Location: /hypex' . $path . $qs, true, 302);
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>غير موجود</title>';
echo '<meta http-equiv="refresh" content="2;url=/hypex/"></head><body style="font-family:Tahoma,sans-serif;padding:2rem;line-height:1.6">';
echo '<h1>الصفحة غير موجودة</h1>';
echo '<p>المسار المطلوب غير موجود. جارٍ التحويل إلى النظام…</p>';
echo '<p><a href="/hypex/">فتح نظام Hypex</a></p>';
echo '</body></html>';
