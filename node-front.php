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
        $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $locHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($reqHost !== '' && strcasecmp($locHost, $reqHost) !== 0) {
            return $value;
        }
        $path = (string) ($parts['path'] ?? '/');
        $fixed = hypex_proxy_fix_location($path, $appBase);
        $rebuilt = ($parts['scheme'] ?? 'http') . '://' . $locHost . $fixed;
        if (!empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }
    if ($value[0] !== '/') {
        return $value;
    }
    if ($value === $appBase || str_starts_with($value, $appBase . '/')) {
        return $value;
    }
    // مسارات التطبيق فقط — لا تلمس أصولاً عامة خارج Hypex
    if (preg_match('#^/(n|sales|customers|purchases|sales-reps|accounting|inventory|hr|system|suppliers|mobile|main|hub|menu|app|embed|login|logout|api|assets|static|health)(/|$)#', $value)) {
        return $appBase . $value;
    }
    return $value;
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';
// الهدف على Node بنفس المسار (APP_BASE_PATH=/hypex)
$target = $nodeOrigin . $uri;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$isMultipart = $method !== 'GET'
    && $method !== 'HEAD'
    && stripos($contentType, 'multipart/form-data') !== false;

// تقارير Oracle/تقارير ثقيلة تحتاج مهلة أطول من الصفحات العادية
$isHeavyReport = (bool) preg_match(
    '#/(sales/reports/oracle-|accounting/reports/|inventory/reports/)#i',
    (string) $uri
);
@ini_set('max_execution_time', $isHeavyReport ? '900' : '180');
@set_time_limit($isHeavyReport ? 900 : 180);
@ignore_user_abort(true);

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

$respStatus = 0;
$respHeaders = [];
$headerDone = false;
$hopByHop = [
    'transfer-encoding' => true,
    'connection' => true,
    'keep-alive' => true,
    'proxy-authenticate' => true,
    'proxy-authorization' => true,
    'te' => true,
    'trailers' => true,
    'upgrade' => true,
    // body قد يُفك ضغطه عبر CURLOPT_ENCODING
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
    // تقارير Oracle قد تتجاوز دقيقتين (استعلام + بناء HTML)
    CURLOPT_TIMEOUT => $isHeavyReport ? 900 : 180,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_ENCODING => '',
    CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$respStatus, &$respHeaders, &$headerDone, $hopByHop): int {
        $len = strlen($headerLine);
        $trim = trim($headerLine);
        if ($trim === '') {
            $headerDone = true;
            return $len;
        }
        if (preg_match('#^HTTP/\S+\s+(\d+)#i', $trim, $m)) {
            $respStatus = (int) $m[1];
            $respHeaders = [];
            $headerDone = false;
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
        // منع Location بدون /hypex → Apache 404 على جذر السيرفر
        if (strtolower($name) === 'location') {
            $value = hypex_proxy_fix_location($value);
        }
        $respHeaders[] = [$name, $value];
        return $len;
    },
    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$respStatus, &$respHeaders, &$headerDone): int {
        static $sent = false;
        if (!$sent) {
            http_response_code($respStatus ?: 502);
            foreach ($respHeaders as [$name, $value]) {
                header($name . ': ' . $value, false);
            }
            $sent = true;
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
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
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
