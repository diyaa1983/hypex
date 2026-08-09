<?php
/**
 * Front reverse-proxy: توحيد الرابط http://localhost/hypex → Node.js
 * يُستدعى من .htaccess لكل المسارات ما عدا PHP القديم/الأصول.
 *
 * ملاحظة: مع enctype=multipart/form-data تكون php://input فارغة في PHP
 * (يملأ $_POST و $_FILES) — لذا نعيد بناء الطلب لـ curl.
 */
declare(strict_types=1);

$nodeOrigin = getenv('HYPEX_NODE_ORIGIN') ?: 'http://127.0.0.1:3000';
$nodeOrigin = rtrim($nodeOrigin, '/');

$uri = $_SERVER['REQUEST_URI'] ?? '/';
// الهدف على Node بنفس المسار (APP_BASE_PATH=/hypex)
$target = $nodeOrigin . $uri;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$isMultipart = $method !== 'GET'
    && $method !== 'HEAD'
    && stripos($contentType, 'multipart/form-data') !== false;

/**
 * بناء حقول POST لـ curl من $_POST و $_FILES (متعدد الأبعاد للأسماء الشبكية).
 *
 * @param array<string,mixed> $src
 * @param string $prefix
 * @param array<string,mixed> $out
 */
function hypex_proxy_flatten_post(array $src, string $prefix, array &$out): void
{
    foreach ($src as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
        if (is_array($v)) {
            hypex_proxy_flatten_post($v, $key, $out);
        } else {
            $out[$key] = $v;
        }
    }
}

/**
 * @return array<string, mixed>|string|null
 */
function hypex_proxy_build_body(string $method, string $contentType, bool $isMultipart)
{
    if (in_array($method, ['GET', 'HEAD'], true)) {
        return null;
    }

    if ($isMultipart) {
        $fields = [];
        hypex_proxy_flatten_post($_POST, '', $fields);

        foreach ($_FILES as $name => $file) {
            // ملف واحد
            if (!is_array($file['name'])) {
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
                    && !empty($file['tmp_name'])
                    && is_uploaded_file($file['tmp_name'])
                ) {
                    $fields[$name] = new CURLFile(
                        $file['tmp_name'],
                        $file['type'] ?: 'application/octet-stream',
                        $file['name'] ?: 'file'
                    );
                }
                continue;
            }
            // مصفوفة ملفات (نادراً)
            foreach ((array) $file['name'] as $i => $fname) {
                $err = (int) ($file['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                $tmp = (string) ($file['tmp_name'][$i] ?? '');
                if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                    continue;
                }
                $fields[$name . '[' . $i . ']'] = new CURLFile(
                    $tmp,
                    (string) ($file['type'][$i] ?? 'application/octet-stream'),
                    (string) $fname
                );
            }
        }

        return $fields;
    }

    // application/x-www-form-urlencoded أو JSON أو غيره — من php://input
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        return $raw;
    }
    // احتياط إن فُرِّغ الإدخال وامتلأت $_POST فقط
    if (!empty($_POST)) {
        return http_build_query($_POST);
    }

    return '';
}

$body = hypex_proxy_build_body($method, $contentType, $isMultipart);

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
        if (in_array(strtolower($name), ['host', 'connection', 'content-length', 'accept-encoding'], true)) {
            continue;
        }
        // عند إعادة بناء multipart يضبط curl الـ Content-Type + boundary
        if ($isMultipart && strtolower($name) === 'content-type') {
            continue;
        }
        $headers[] = $name . ': ' . $v;
    }
}
if (!$isMultipart && $contentType !== '') {
    $headers[] = 'Content-Type: ' . $contentType;
}
$headers[] = 'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '');
$headers[] = 'X-Forwarded-Proto: ' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$headers[] = 'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$headers[] = 'X-Forwarded-Prefix: /hypex';

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_ENCODING => '', // allow identity; Node may gzip
]);

if ($body !== null) {
    if (is_array($body)) {
        // multipart fields array — curl يضبط boundary تلقائياً
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif (is_string($body) && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
}

$raw = curl_exec($ch);
if ($raw === false) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8">';
    echo '<title>Hypex · Node متوقف</title>';
    echo '<body style="font-family:Segoe UI,Tahoma,sans-serif;padding:2rem;max-width:36rem;margin:auto;line-height:1.6">';
    echo '<h1>واجهة Node غير متاحة</h1>';
    echo '<p>الرابط <code>/hypex</code> يعتمد على تشغيل Node. شغّل:</p>';
    echo '<pre style="background:#f4f4f5;padding:1rem;border-radius:8px">cd hypex-node&#10;npm start</pre>';
    echo '<p style="color:#666">أو نفّذ <code>deploy\\start-hypex-node.cmd</code></p>';
    echo '<p style="color:#b91c1c">' . htmlspecialchars(curl_error($ch), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    curl_close($ch);
    exit;
}

$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$headerBlob = substr($raw, 0, $headerSize);
$respBody = substr($raw, $headerSize);
http_response_code($status ?: 502);

$hopByHop = [
    'transfer-encoding' => true,
    'connection' => true,
    'keep-alive' => true,
    'proxy-authenticate' => true,
    'proxy-authorization' => true,
    'te' => true,
    'trailers' => true,
    'upgrade' => true,
    'content-encoding' => true, // body already decoded by curl ENCODING
    'content-length' => true,
];

foreach (explode("\r\n", $headerBlob) as $line) {
    if ($line === '' || str_starts_with(strtolower($line), 'http/')) {
        continue;
    }
    $pos = strpos($line, ':');
    if ($pos === false) {
        continue;
    }
    $name = substr($line, 0, $pos);
    $value = ltrim(substr($line, $pos + 1));
    if (isset($hopByHop[strtolower($name)])) {
        continue;
    }
    header($name . ': ' . $value, false);
}

echo $respBody;
