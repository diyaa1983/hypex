<?php
declare(strict_types=1);

/**
 * إرسال PDF عبر WhatsApp Cloud API (Meta Graph).
 *
 * يستخدم نظامين منفصلين من نقاط النهاية:
 *   1) POST /{phone-id}/media     ⟵ لرفع الملف مؤقتاً والحصول على media_id.
 *   2) POST /{phone-id}/messages  ⟵ لإرسال رسالة document مع media_id إلى الرقم.
 *
 * تتطلب: امتداد cURL في PHP (موجود افتراضياً مع XAMPP).
 */

require_once app_path('includes/db.php');

function company_whatsapp_ensure_schema(PDO $pdo): void
{
    $cols = [
        'wa_provider'        => "VARCHAR(16) NOT NULL DEFAULT 'cloud'",
        'wa_phone_id'        => "VARCHAR(64) NULL",
        'wa_access_token'    => "VARCHAR(512) NULL",
        'wa_api_version'     => "VARCHAR(16) NOT NULL DEFAULT 'v20.0'",
        'wa_default_country' => "VARCHAR(8) NULL",
        'wa_bridge_url'      => "VARCHAR(255) NULL",
        'wa_bridge_token'    => "VARCHAR(255) NULL",
    ];
    foreach ($cols as $name => $def) {
        try {
            $pdo->query("SELECT $name FROM sys_company_settings LIMIT 1");
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Unknown column') === false) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE sys_company_settings ADD COLUMN $name $def");
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
}

/**
 * @return array{provider:string,phone_id:string,access_token:string,api_version:string,default_country:string,bridge_url:string,bridge_token:string}
 */
function company_whatsapp_settings(?PDO $pdo = null): array
{
    $out = [
        'provider' => 'cloud',
        'phone_id' => '',
        'access_token' => '',
        'api_version' => 'v20.0',
        'default_country' => '',
        'bridge_url' => '',
        'bridge_token' => '',
    ];
    try {
        $pdo = $pdo ?? db();
        company_whatsapp_ensure_schema($pdo);
        $row = $pdo->query(
            'SELECT wa_provider, wa_phone_id, wa_access_token, wa_api_version, wa_default_country,
                    wa_bridge_url, wa_bridge_token
             FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $provider = strtolower(trim((string) ($row['wa_provider'] ?? 'cloud')));
        $out['provider'] = in_array($provider, ['cloud', 'bridge'], true) ? $provider : 'cloud';
        $out['phone_id'] = trim((string) ($row['wa_phone_id'] ?? ''));
        $out['access_token'] = trim((string) ($row['wa_access_token'] ?? ''));
        $apiV = trim((string) ($row['wa_api_version'] ?? 'v20.0'));
        $out['api_version'] = $apiV !== '' ? $apiV : 'v20.0';
        $out['default_country'] = preg_replace('/\D+/', '', (string) ($row['wa_default_country'] ?? '')) ?: '';
        $out['bridge_url'] = rtrim(trim((string) ($row['wa_bridge_url'] ?? '')), '/');
        $out['bridge_token'] = trim((string) ($row['wa_bridge_token'] ?? ''));
    } catch (Throwable $e) {
        // DB غير مهيأ
    }

    return $out;
}

function company_whatsapp_is_configured(?PDO $pdo = null): bool
{
    $s = company_whatsapp_settings($pdo);
    if ($s['provider'] === 'bridge') {
        return $s['bridge_url'] !== '';
    }

    return $s['phone_id'] !== '' && $s['access_token'] !== '';
}

/**
 * يطبّع رقم الهاتف لصيغة Cloud API:
 *   - يزيل المسافات والشرطات والأقواس والرمز +
 *   - يحوّل بادئة 00 إلى رقم دولي
 *   - إذا كان الرقم محلياً (يبدأ بـ 0) ولديك كود دولة افتراضي، يستبدل الصفر بالكود.
 */
function company_whatsapp_normalize_phone(string $raw, string $defaultCountry = ''): string
{
    $s = preg_replace('/[\s\-\(\)]+/', '', trim($raw)) ?? '';
    if ($s === '') {
        return '';
    }
    if (strpos($s, '+') === 0) {
        $s = substr($s, 1);
    } elseif (strpos($s, '00') === 0) {
        $s = substr($s, 2);
    } elseif (strpos($s, '0') === 0 && $defaultCountry !== '') {
        $s = $defaultCountry . substr($s, 1);
    }

    return preg_replace('/\D+/', '', $s) ?? '';
}

/**
 * يُنفذ طلب cURL ويُعيد ['ok', 'code', 'body', 'error'].
 *
 * @return array{ok:bool,code:int,body:string,json:array<mixed>|null,error:?string}
 */
function company_whatsapp_curl_exec($ch): array
{
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        return [
            'ok' => false,
            'code' => $code,
            'body' => '',
            'json' => null,
            'error' => 'cURL: ' . ($err !== '' ? $err : 'فشل الاتصال بـ Meta Graph API.'),
        ];
    }
    $json = json_decode((string) $body, true);
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'code' => $code, 'body' => (string) $body, 'json' => is_array($json) ? $json : null, 'error' => null];
    }
    $errMsg = '';
    if (is_array($json) && isset($json['error'])) {
        $e = $json['error'];
        if (is_array($e)) {
            $errMsg = (string) ($e['message'] ?? '');
            $sub = (string) ($e['error_user_msg'] ?? '');
            if ($sub !== '') {
                $errMsg = $errMsg !== '' ? ($errMsg . ' — ' . $sub) : $sub;
            }
            if ($errMsg === '' && isset($e['type'])) {
                $errMsg = (string) $e['type'];
            }
        } elseif (is_string($e)) {
            $errMsg = $e;
        }
    }
    if ($errMsg === '') {
        $errMsg = 'HTTP ' . $code;
    }

    return ['ok' => false, 'code' => $code, 'body' => (string) $body, 'json' => is_array($json) ? $json : null, 'error' => $errMsg];
}

/**
 * يرفع ملف PDF إلى Meta Graph ويُعيد media_id.
 *
 * @return array{ok:bool,media_id?:string,error?:string,code?:int}
 */
function company_whatsapp_upload_media(string $pdfPath, string $filename): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'امتداد cURL غير مفعّل في PHP.'];
    }
    $cfg = company_whatsapp_settings();
    if ($cfg['phone_id'] === '' || $cfg['access_token'] === '') {
        return ['ok' => false, 'error' => 'إعدادات WhatsApp غير مكتملة.'];
    }
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        return ['ok' => false, 'error' => 'ملف PDF غير قابل للقراءة.'];
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($cfg['api_version']) . '/' . rawurlencode($cfg['phone_id']) . '/media';
    $ch = curl_init($url);
    $post = [
        'messaging_product' => 'whatsapp',
        'type' => 'application/pdf',
        'file' => new CURLFile($pdfPath, 'application/pdf', $filename !== '' ? $filename : 'document.pdf'),
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['access_token']],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res = company_whatsapp_curl_exec($ch);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => 'فشل رفع الملف إلى Meta: ' . ($res['error'] ?? ''), 'code' => $res['code']];
    }
    $id = $res['json']['id'] ?? '';
    if (!is_string($id) || $id === '') {
        return ['ok' => false, 'error' => 'استجابة غير صالحة من Meta عند رفع الملف.', 'code' => $res['code']];
    }

    return ['ok' => true, 'media_id' => $id];
}

/**
 * يُرسل رسالة document إلى رقم بصيغة دولية كاملة (مثل 962790000000) باستخدام media_id.
 *
 * @return array{ok:bool,message_id?:string,wa_id?:string,error?:string,code?:int}
 */
function company_whatsapp_send_document_by_media_id(string $toIntl, string $mediaId, string $caption, string $filename): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'امتداد cURL غير مفعّل في PHP.'];
    }
    $cfg = company_whatsapp_settings();
    if ($cfg['phone_id'] === '' || $cfg['access_token'] === '') {
        return ['ok' => false, 'error' => 'إعدادات WhatsApp غير مكتملة.'];
    }
    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $toIntl,
        'type' => 'document',
        'document' => [
            'id' => $mediaId,
            'filename' => $filename !== '' ? $filename : 'document.pdf',
        ],
    ];
    if ($caption !== '') {
        $payload['document']['caption'] = mb_substr($caption, 0, 1024, 'UTF-8');
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($cfg['api_version']) . '/' . rawurlencode($cfg['phone_id']) . '/messages';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['access_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res = company_whatsapp_curl_exec($ch);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?? 'تعذر إرسال الرسالة.', 'code' => $res['code']];
    }
    $msgId = '';
    $waId = '';
    if (is_array($res['json'])) {
        if (isset($res['json']['messages'][0]['id'])) {
            $msgId = (string) $res['json']['messages'][0]['id'];
        }
        if (isset($res['json']['contacts'][0]['wa_id'])) {
            $waId = (string) $res['json']['contacts'][0]['wa_id'];
        }
    }

    return ['ok' => true, 'message_id' => $msgId, 'wa_id' => $waId];
}

/**
 * يُرسل PDF عبر الخادم المحلي (whatsapp-web.js bridge).
 *
 * @return array{ok:bool,error?:string,message_id?:string,code?:int}
 */
function company_whatsapp_send_via_bridge(string $toIntl, string $pdfPath, string $filename, string $caption = ''): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'امتداد cURL غير مفعّل في PHP.'];
    }
    $cfg = company_whatsapp_settings();
    if ($cfg['bridge_url'] === '') {
        return ['ok' => false, 'error' => 'عنوان جسر WhatsApp غير مُعدّ.'];
    }
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        return ['ok' => false, 'error' => 'ملف PDF غير قابل للقراءة.'];
    }
    $url = $cfg['bridge_url'] . '/send';
    $ch = curl_init($url);
    $headers = [];
    if ($cfg['bridge_token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $cfg['bridge_token'];
    }
    $post = [
        'phone' => $toIntl,
        'caption' => mb_substr($caption, 0, 1024, 'UTF-8'),
        'pdf' => new CURLFile($pdfPath, 'application/pdf', $filename !== '' ? $filename : 'document.pdf'),
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $res = company_whatsapp_curl_exec($ch);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?? 'تعذر الاتصال بجسر WhatsApp المحلي.', 'code' => $res['code']];
    }
    $msgId = is_array($res['json']) ? (string) ($res['json']['message_id'] ?? '') : '';

    return ['ok' => true, 'message_id' => $msgId];
}

/**
 * يفحص حالة الجسر المحلي.
 *
 * @return array{ok:bool,ready:bool,hasQr:bool,error?:string}
 */
function company_whatsapp_bridge_status(): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'ready' => false, 'hasQr' => false, 'error' => 'امتداد cURL غير مفعّل.'];
    }
    $cfg = company_whatsapp_settings();
    if ($cfg['bridge_url'] === '') {
        return ['ok' => false, 'ready' => false, 'hasQr' => false, 'error' => 'لم يُعدّ عنوان الجسر.'];
    }
    $ch = curl_init($cfg['bridge_url'] . '/status');
    $headers = [];
    if ($cfg['bridge_token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $cfg['bridge_token'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $res = company_whatsapp_curl_exec($ch);
    if (!$res['ok'] || !is_array($res['json'])) {
        return ['ok' => false, 'ready' => false, 'hasQr' => false, 'error' => $res['error'] ?? 'تعذر الاتصال بالجسر المحلي. تأكد من تشغيل start.bat.'];
    }

    return [
        'ok' => true,
        'ready' => (bool) ($res['json']['ready'] ?? false),
        'hasQr' => (bool) ($res['json']['hasQr'] ?? false),
        'error' => $res['json']['error'] ?? null,
    ];
}

/**
 * مساعد عالي المستوى: يرسل PDF حسب الـ provider المختار.
 *
 * @return array{ok:bool,error?:string,message_id?:string,wa_id?:string,code?:int}
 */
function company_whatsapp_send_pdf_file(string $toIntl, string $pdfPath, string $filename, string $caption = ''): array
{
    $cfg = company_whatsapp_settings();
    if ($cfg['provider'] === 'bridge') {
        return company_whatsapp_send_via_bridge($toIntl, $pdfPath, $filename, $caption);
    }
    $up = company_whatsapp_upload_media($pdfPath, $filename);
    if (!($up['ok'] ?? false)) {
        return ['ok' => false, 'error' => $up['error'] ?? 'فشل رفع الملف.', 'code' => $up['code'] ?? null];
    }

    return company_whatsapp_send_document_by_media_id($toIntl, (string) $up['media_id'], $caption, $filename);
}
