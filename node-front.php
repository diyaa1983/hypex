<?php
/**
 * Front reverse-proxy: توحيد الرابط http://localhost/hypex → Node.js
 * يُستدعى من .htaccess لكل المسارات ما عدا PHP القديمة/الأصول.
 *
 * ملاحظة: مع enctype=multipart/form-data تكون php://input فارغة في PHP
 * (يملأ $_POST و $_FILES) — لذا نعيد بناء الطلب لـ curl.
 */
declare(strict_types=1);

$nodeOrigin = getenv('HYPEX_NODE_ORIGIN') ?: 'http://127.0.0.1:3000';
$nodeOrigin = rtrim($nodeOrigin, '/');
$appBase = '/hypex';

/**
 * إن أعاد Node ترويسة Location بدون /hypex يصطدم الطلب بـ Apache 404 على الجذر.
 * كما نحوّل روابط 127.0.0.1/localhost إلى مسار نسبي تحت المضيف العام.
 */
function hypex_proxy_fix_location(string $value, string $appBase = '/hypex'): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '//')) {
        return $value;
    }
    if (preg_match('#^https?://#i', $value)) {
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) {
            return $value;
        }
        $hostOnly = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $frag = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        // Node أحياناً يعيد Location على 127.0.0.1:3000 — لا يفتح من الإنترنت
        if ($hostOnly === '127.0.0.1' || $hostOnly === 'localhost' || $hostOnly === '::1') {
            return hypex_proxy_fix_location($path . $query . $frag, $appBase);
        }
        $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $locHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $fixedPath = hypex_proxy_fix_location($path, $appBase);
        if ($reqHost !== '' && strcasecmp($locHost, $reqHost) === 0) {
            return $fixedPath . $query . $frag;
        }
        $rebuilt = ($parts['scheme'] ?? 'http') . '://' . $locHost . $fixedPath . $query . $frag;
        return $rebuilt;
    }
    if ($value[0] !== '/') {
        return $value;
    }
    if ($value === $appBase || str_starts_with($value, $appBase . '/')) {
        return $value;
    }
    if (preg_match('#^/(n|sales|customers|purchases|sales-reps|accounting|inventory|hr|system|suppliers|mobile|main|hub|menu|app|embed|login|logout|api|assets|static|health)(/|$)#', $value)) {
        return $appBase . $value;
    }
    return $value;
}

/** Location مطلق على نفس المضيف العام — أوضح للمتصفح من المسار النسبي بعد فتح الجذر */
function hypex_proxy_public_location(string $value, string $appBase = '/hypex'): string
{
    $fixed = hypex_proxy_fix_location($value, $appBase);
    if ($fixed === '' || !str_starts_with($fixed, '/')) {
        return $fixed;
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return $fixed;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $proto = $https ? 'https' : 'http';
    return $proto . '://' . $host . $fixed;
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$target = $nodeOrigin . $uri;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$isMultipart = $method !== 'GET'
    && $method !== 'HEAD'
    && stripos($contentType, 'multipart/form-data') !== false;

$isHeavyReport = (bool) preg_match(
    '#/(sales/reports/oracle-|accounting/reports/|inventory/reports/)#i',
    (string) $uri
);
@ini_set('max_execution_time', $isHeavyReport ? '900' : '180');
@set_time_limit($isHeavyReport ? 900 : 180);
@ignore_user_abort(true);

/**
 * @param array<string,mixed> $src
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
            $n = count($file['name']);
            for ($i = 0; $i < $n; $i++) {
                if ((int) ($file['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmp = (string) ($file['tmp_name'][$i] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    continue;
                }
                $fields[$name . '[' . $i . ']'] = new CURLFile(
                    $tmp,
                    $file['type'][$i] ?: 'application/octet-stream',
                    $file['name'][$i] ?: 'file'
                );
            }
        }
        return $fields;
    }

    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        return $raw;
    }
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

$respStatus = 0;
$respHeaders = [];
$headerDone = false;
$bufferRedirect = false;
$bufferedBody = '';
$headersSent = false;
$hopByHop = [
    'transfer-encoding' => true,
    'connection' => true,
    'keep-alive' => true,
    'proxy-authenticate' => true,
    'proxy-authorization' => true,
    'te' => true,
    'trailers' => true,
    'upgrade' => true,
    'content-encoding' => true,
    'content-length' => true,
];

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => $isHeavyReport ? 900 : 180,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_ENCODING => '',
    CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (
        &$respStatus,
        &$respHeaders,
        &$headerDone,
        &$bufferRedirect,
        $hopByHop
    ): int {
        $len = strlen($headerLine);
        $trim = trim($headerLine);
        if ($trim === '') {
            $headerDone = true;
            if ($respStatus >= 300 && $respStatus < 400) {
                $bufferRedirect = true;
            }
            return $len;
        }
        if (preg_match('#^HTTP/\S+\s+(\d+)#i', $trim, $m)) {
            $respStatus = (int) $m[1];
            $respHeaders = [];
            $headerDone = false;
            $bufferRedirect = $respStatus >= 300 && $respStatus < 400;
            return $len;
        }
        $pos = strpos($trim, ':');
        if ($pos === false) {
            return $len;
        }
        $name = substr($trim, 0, $pos);
        $value = ltrim(substr($trim, $pos + 1));
        if (isset($hopByHop[strtolower($name)])) {
            return $len;
        }
        if (strtolower($name) === 'location') {
            $value = hypex_proxy_public_location($value);
        }
        $respHeaders[] = [$name, $value];
        return $len;
    },
    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (
        &$respStatus,
        &$respHeaders,
        &$headerDone,
        &$bufferRedirect,
        &$bufferedBody,
        &$headersSent
    ): int {
        if ($bufferRedirect || ($respStatus >= 300 && $respStatus < 400)) {
            $bufferRedirect = true;
            $bufferedBody .= $chunk;
            return strlen($chunk);
        }
        if (!$headersSent) {
            http_response_code($respStatus ?: 502);
            foreach ($respHeaders as [$name, $value]) {
                // لا تمرّر Content-Type: text/plain من Express مع صفحات HTML
                if (strtolower($name) === 'content-type' && $respStatus >= 200 && $respStatus < 300) {
                    // اترك كما هو من Node
                }
                header($name . ': ' . $value, false);
            }
            $headersSent = true;
            $headerDone = true;
        }
        echo $chunk;
        if (function_exists('flush')) {
            @flush();
        }
        return strlen($chunk);
    },
]);

if ($body !== null) {
    if (is_array($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif (is_string($body) && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
}

$ok = curl_exec($ch);
if ($ok === false) {
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!headers_sent()) {
        http_response_code(502);
        header('Content-Type: text/html; charset=utf-8');
    }
    $hint = 'الرابط <code>/hypex</code> يعتمد على تشغيل Node.';
    if ($errno === 28 || stripos($err, 'timed out') !== false) {
        $hint = 'انتهت مهلة التقرير (بيانات كثيرة). حاول تصفية مندوب/سنة/شهر، أو أعد المحاولة.';
    } elseif ($errno === 56 || stripos($err, 'reset') !== false || stripos($err, 'recv') !== false) {
        $hint = 'انقطع الاتصال أثناء التقرير الكبير (غالباً إعادة تشغيل Node بسبب الذاكرة). أعد تشغيل Node ثم حاول مرة أخرى بفلاتر أقل إن استمر.';
    }
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8">';
    echo '<title>Hypex · Node متوقف</title>';
    echo '<body style="font-family:Segoe UI,Tahoma,sans-serif;padding:2rem;max-width:36rem;margin:auto;line-height:1.6">';
    echo '<h1>واجهة Node غير متاحة</h1>';
    echo '<p>' . $hint . '</p>';
    echo '<p>شغّل:</p>';
    echo '<pre style="background:#f4f4f5;padding:1rem;border-radius:8px">cd hypex-node&#10;npm start</pre>';
    echo '<p style="color:#666">أو نفّذ <code>deploy\\start-hypex-node.cmd</code> / <code>deploy\\HypexNode-Start.cmd</code></p>';
    echo '<p style="color:#b91c1c">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

curl_close($ch);

// إعادة توجيه نظيفة — لا نعرض نص Express "Found. Redirecting…" كصفحة بيضاء
if ($bufferRedirect && $respStatus >= 300 && $respStatus < 400) {
    $location = '';
    foreach ($respHeaders as [$name, $value]) {
        if (strtolower($name) === 'location') {
            $location = $value;
            break;
        }
    }
    if ($location === '') {
        $location = hypex_proxy_public_location('/hypex/');
    }
    if (!headers_sent()) {
        http_response_code($respStatus);
        // مهم: تمرير Set-Cookie عند نجاح تسجيل الدخول — وإلا تُفقد الجلسة ويُعاد /login بلا خطأ
        foreach ($respHeaders as [$name, $value]) {
            $ln = strtolower($name);
            if ($ln === 'location' || $ln === 'content-type' || $ln === 'content-length') {
                continue;
            }
            header($name . ': ' . $value, false);
        }
        header('Location: ' . $location, true, $respStatus);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $safe = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
    $js = json_encode($location, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">';
    echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">';
    echo '<title>جاري التحويل…</title></head><body>';
    echo '<p style="font-family:Tahoma,sans-serif;padding:1.5rem">جاري التحويل… ';
    echo '<a href="' . $safe . '">اضغط هنا إن لم تُحوَّل تلقائياً</a></p>';
    echo '<script>try{location.replace(' . $js . ');}catch(e){location.href=' . $js . ';}</script>';
    echo '</body></html>';
    exit;
}
