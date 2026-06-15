<?php
declare(strict_types=1);

/**
 * إعدادات البريد المرسل (SMTP) للشركة + عميل SMTP بسيط لا يحتاج Composer/PHPMailer.
 *
 * يدعم:
 *   - STARTTLS على المنفذ 587 (smtp_secure = 'tls')
 *   - SSL/TLS على المنفذ 465 (smtp_secure = 'ssl')
 *   - بدون تشفير (smtp_secure = 'none')  ⟵ غير موصى به
 *   - مصادقة LOGIN بالاسم وكلمة المرور
 *   - مرفق PDF واحد (Base64) ضمن رسالة MIME multipart/mixed
 */

require_once app_path('includes/db.php');

/**
 * يضمن وجود أعمدة SMTP داخل sys_company_settings.
 */
function company_smtp_ensure_schema(PDO $pdo): void
{
    $cols = [
        'smtp_host'       => "VARCHAR(120) NULL",
        'smtp_port'       => "SMALLINT UNSIGNED NOT NULL DEFAULT 587",
        'smtp_secure'     => "VARCHAR(8) NOT NULL DEFAULT 'tls'",
        'smtp_username'   => "VARCHAR(160) NULL",
        'smtp_password'   => "VARCHAR(255) NULL",
        'smtp_from_email' => "VARCHAR(160) NULL",
        'smtp_from_name'  => "VARCHAR(160) NULL",
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
 * يقرأ إعدادات SMTP الحالية.
 *
 * @return array{host:string,port:int,secure:string,username:string,password:string,from_email:string,from_name:string}
 */
function company_smtp_settings(?PDO $pdo = null): array
{
    $defaults = [
        'host' => '',
        'port' => 587,
        'secure' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
    ];
    try {
        $pdo = $pdo ?? db();
        company_smtp_ensure_schema($pdo);
        $row = $pdo->query(
            'SELECT smtp_host, smtp_port, smtp_secure, smtp_username, smtp_password,
                    smtp_from_email, smtp_from_name, company_name_ar, email
             FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $defaults['host'] = trim((string) ($row['smtp_host'] ?? ''));
        $defaults['port'] = (int) ($row['smtp_port'] ?? 587);
        $secure = strtolower(trim((string) ($row['smtp_secure'] ?? 'tls')));
        $defaults['secure'] = in_array($secure, ['tls', 'ssl', 'none'], true) ? $secure : 'tls';
        $defaults['username'] = (string) ($row['smtp_username'] ?? '');
        $defaults['password'] = (string) ($row['smtp_password'] ?? '');
        $fromEmail = trim((string) ($row['smtp_from_email'] ?? ''));
        if ($fromEmail === '') {
            $fromEmail = trim((string) ($row['email'] ?? ''));
        }
        $defaults['from_email'] = $fromEmail;
        $fromName = trim((string) ($row['smtp_from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = trim((string) ($row['company_name_ar'] ?? ''));
        }
        $defaults['from_name'] = $fromName;
    } catch (Throwable $e) {
        // قاعدة غير مهيأة
    }

    return $defaults;
}

function company_smtp_is_configured(?PDO $pdo = null): bool
{
    $s = company_smtp_settings($pdo);

    return $s['host'] !== '' && $s['port'] > 0 && $s['from_email'] !== '';
}

/**
 * يرسل رسالة بريد عبر SMTP مع مرفق اختياري.
 *
 * @param string                $toEmail عنوان المستقبل
 * @param string                $subject موضوع الرسالة (UTF-8)
 * @param string                $bodyHtml نص HTML
 * @param array{name:string,data:string,mime?:string}|null $attachment ملف مرفق (data خام، ليس Base64)
 *
 * @return array{ok:bool,error?:string,trace?:string[]}
 */
function company_smtp_send(string $toEmail, string $subject, string $bodyHtml, ?array $attachment = null): array
{
    $cfg = company_smtp_settings();
    if ($cfg['host'] === '' || $cfg['from_email'] === '') {
        return ['ok' => false, 'error' => 'إعدادات SMTP غير مكتملة.'];
    }
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'البريد المستلم غير صالح.'];
    }

    $secure = $cfg['secure'];
    $host = $cfg['host'];
    $port = (int) $cfg['port'];
    if ($port <= 0) {
        $port = ($secure === 'ssl') ? 465 : 587;
    }
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $sock = @stream_socket_client($remote, $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        return ['ok' => false, 'error' => 'تعذر الاتصال بخادم SMTP: ' . ($errstr ?: 'خطأ غير معروف') . ' (' . $errno . ')'];
    }
    stream_set_timeout($sock, 25);

    $trace = [];
    $read = function () use ($sock, &$trace): array {
        $out = '';
        $code = 0;
        while (!feof($sock)) {
            $line = fgets($sock, 4096);
            if ($line === false) {
                break;
            }
            $trace[] = '<< ' . rtrim($line);
            $out .= $line;
            if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                $code = (int) substr($line, 0, 3);
                break;
            }
        }

        return ['code' => $code, 'text' => $out];
    };
    $write = function (string $cmd, bool $hidden = false) use ($sock, &$trace): void {
        $trace[] = '>> ' . ($hidden ? '[hidden]' : $cmd);
        fwrite($sock, $cmd . "\r\n");
    };

    $finish = function (?string $error = null) use ($sock, $write, &$trace): array {
        @$write('QUIT');
        @fclose($sock);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error, 'trace' => $trace];
        }

        return ['ok' => true, 'trace' => $trace];
    };

    $r = $read();
    if ((int) ($r['code'] ?? 0) !== 220) {
        return $finish('استجابة الترحيب من SMTP غير صالحة: ' . trim($r['text']));
    }
    $ehloHost = $cfg['from_email'] !== '' ? explode('@', $cfg['from_email'])[1] ?? 'localhost' : 'localhost';
    $write('EHLO ' . $ehloHost);
    $r = $read();
    if ((int) ($r['code'] ?? 0) !== 250) {
        return $finish('EHLO فشل: ' . trim($r['text']));
    }

    if ($secure === 'tls') {
        $write('STARTTLS');
        $r = $read();
        if ((int) ($r['code'] ?? 0) !== 220) {
            return $finish('STARTTLS فشل: ' . trim($r['text']));
        }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
            return $finish('تعذر تفعيل TLS مع الخادم.');
        }
        $write('EHLO ' . $ehloHost);
        $r = $read();
        if ((int) ($r['code'] ?? 0) !== 250) {
            return $finish('EHLO بعد STARTTLS فشل: ' . trim($r['text']));
        }
    }

    if ($cfg['username'] !== '' && $cfg['password'] !== '') {
        $write('AUTH LOGIN');
        $r = $read();
        if ((int) ($r['code'] ?? 0) !== 334) {
            return $finish('AUTH LOGIN رُفض: ' . trim($r['text']));
        }
        $write(base64_encode($cfg['username']));
        $r = $read();
        if ((int) ($r['code'] ?? 0) !== 334) {
            return $finish('اسم المستخدم رُفض: ' . trim($r['text']));
        }
        $write(base64_encode($cfg['password']), true);
        $r = $read();
        if ((int) ($r['code'] ?? 0) !== 235) {
            return $finish('فشل تسجيل الدخول إلى SMTP (تحقق من البريد وكلمة المرور / كلمة مرور التطبيق).');
        }
    }

    $write('MAIL FROM:<' . $cfg['from_email'] . '>');
    $r = $read();
    if (!in_array((int) ($r['code'] ?? 0), [250, 251], true)) {
        return $finish('رفض المرسل: ' . trim($r['text']));
    }
    $write('RCPT TO:<' . $toEmail . '>');
    $r = $read();
    if (!in_array((int) ($r['code'] ?? 0), [250, 251], true)) {
        return $finish('رفض المستلم: ' . trim($r['text']));
    }
    $write('DATA');
    $r = $read();
    if ((int) ($r['code'] ?? 0) !== 354) {
        return $finish('DATA رُفض: ' . trim($r['text']));
    }

    $boundary = 'b' . bin2hex(random_bytes(12));
    $fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : $cfg['from_email'];
    $headers = [];
    $headers[] = 'From: ' . company_smtp_encode_header($fromName) . ' <' . $cfg['from_email'] . '>';
    $headers[] = 'To: <' . $toEmail . '>';
    $headers[] = 'Subject: ' . company_smtp_encode_header($subject);
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'MIME-Version: 1.0';
    if ($attachment !== null) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
    }
    $headerBlock = implode("\r\n", $headers);

    $body = '';
    if ($attachment === null) {
        $body = chunk_split(base64_encode($bodyHtml));
    } else {
        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($bodyHtml));
        $body .= "\r\n--" . $boundary . "\r\n";
        $mime = isset($attachment['mime']) && $attachment['mime'] !== '' ? $attachment['mime'] : 'application/pdf';
        $name = isset($attachment['name']) && $attachment['name'] !== '' ? $attachment['name'] : 'attachment.pdf';
        $name = preg_replace('/[\r\n"]+/', '', $name) ?: 'attachment.pdf';
        $body .= "Content-Type: $mime; name=\"$name\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n";
        $body .= chunk_split(base64_encode((string) $attachment['data']));
        $body .= "\r\n--" . $boundary . "--\r\n";
    }

    $payload = $headerBlock . "\r\n\r\n" . $body;
    $payload = preg_replace("/(^|\r\n)\.(?=\r\n)/", '$1..', $payload);

    fwrite($sock, $payload . "\r\n.\r\n");
    $r = $read();
    if ((int) ($r['code'] ?? 0) !== 250) {
        return $finish('فشل تسليم الرسالة: ' . trim($r['text']));
    }

    return $finish();
}

function company_smtp_encode_header(string $value): string
{
    if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value)) {
        return $value;
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}
