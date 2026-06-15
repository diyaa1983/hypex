<?php
declare(strict_types=1);

require_once app_path('includes/einvoice_schema.php');

/** @param array<string, mixed> $cfg */
function einvoice_external_pdo(array $cfg): ?PDO
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['name'] ?? '',
            $cfg['charset'] ?? 'utf8mb4'
        );

        return new PDO($dsn, (string) ($cfg['user'] ?? 'root'), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array<string, mixed> */
function einvoice_settings_get(PDO $pdo): array
{
    einvoice_ensure_schema($pdo);
    $row = $pdo->query('SELECT * FROM sys_einvoice_settings WHERE id = 1 LIMIT 1')->fetch();

    return is_array($row) ? $row : ['id' => 1];
}

/** @param array<string, mixed> $data */
function einvoice_settings_save(PDO $pdo, array $data): bool
{
    einvoice_ensure_schema($pdo);
    $st = $pdo->prepare(
        'UPDATE sys_einvoice_settings SET
            company_name = ?, trade_name = ?, vat_no = ?, gst_no = ?,
            company_email = ?, company_phone = ?, address = ?, city = ?,
            taxes_type = ?, invoice_cash = ?, invoice_debit = ?,
            client_id = ?, secret_key = ?, admin_email = ?, jofotara_api_url = ?, notes = ?
         WHERE id = 1'
    );

    return $st->execute([
        trim((string) ($data['company_name'] ?? '')),
        trim((string) ($data['trade_name'] ?? '')) ?: null,
        trim((string) ($data['vat_no'] ?? '')) ?: null,
        trim((string) ($data['gst_no'] ?? '')) ?: null,
        trim((string) ($data['company_email'] ?? '')) ?: null,
        trim((string) ($data['company_phone'] ?? '')) ?: null,
        trim((string) ($data['address'] ?? '')) ?: null,
        trim((string) ($data['city'] ?? '')) ?: null,
        (int) ($data['taxes_type'] ?? 2) === 1 ? 1 : 2,
        trim((string) ($data['invoice_cash'] ?? '011')) ?: '011',
        trim((string) ($data['invoice_debit'] ?? '021')) ?: '021',
        trim((string) ($data['client_id'] ?? '')) ?: null,
        trim((string) ($data['secret_key'] ?? '')) ?: null,
        trim((string) ($data['admin_email'] ?? '')) ?: null,
        trim((string) ($data['jofotara_api_url'] ?? '')) ?: 'https://backend.jofotara.gov.jo/core/invoices/',
        trim((string) ($data['notes'] ?? '')) ?: null,
    ]);
}

/** @return array{ok:bool, message:string} */
function einvoice_import_from_admin(PDO $pdo): array
{
    $cfg = require app_path('config/einvoice.php');
    $ext = einvoice_external_pdo($cfg['admin'] ?? []);
    if ($ext === null) {
        return ['ok' => false, 'message' => 'تعذر الاتصال بقاعدة بيانات admin. راجع config/einvoice.php'];
    }

    $pfx = (string) (($cfg['admin']['prefix'] ?? '') ?: 'glx_');
    $data = [];

    try {
        $st = $ext->query('SELECT client_id, secret_key, invoice_cash, invoice_debit, taxes_type, admin_email FROM ' . $pfx . 'settings WHERE id = 1 LIMIT 1');
        $settings = $st->fetch() ?: [];
        $data['client_id'] = $settings['client_id'] ?? null;
        $data['secret_key'] = $settings['secret_key'] ?? null;
        $data['invoice_cash'] = $settings['invoice_cash'] ?? '011';
        $data['invoice_debit'] = $settings['invoice_debit'] ?? '021';
        $data['taxes_type'] = (int) ($settings['taxes_type'] ?? 2);
        $data['admin_email'] = $settings['admin_email'] ?? null;
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر قراءة إعدادات admin: ' . $e->getMessage()];
    }

    try {
        $biller = $ext->query(
            "SELECT name, company, vat_no, gst_no, email, phone, address, city
             FROM {$pfx}companies WHERE group_name = 'biller' ORDER BY id ASC LIMIT 1"
        )->fetch();
        if (is_array($biller)) {
            $data['company_name'] = $biller['name'] ?? '';
            $data['trade_name'] = $biller['company'] ?? '';
            $data['vat_no'] = $biller['vat_no'] ?? '';
            $data['gst_no'] = $biller['gst_no'] ?? '';
            $data['company_email'] = $biller['email'] ?? '';
            $data['company_phone'] = $biller['phone'] ?? '';
            $data['address'] = $biller['address'] ?? '';
            $data['city'] = $biller['city'] ?? '';
        }
    } catch (Throwable $e) {
        // biller اختياري
    }

    $current = einvoice_settings_get($pdo);
    foreach ($data as $k => $v) {
        if ($v !== null && $v !== '') {
            $current[$k] = $v;
        }
    }

    if (!einvoice_settings_save($pdo, $current)) {
        return ['ok' => false, 'message' => 'تعذر حفظ البيانات المستوردة.'];
    }

    return ['ok' => true, 'message' => 'تم استيراد إعدادات الفوترة من نظام admin بنجاح.'];
}

/** @return array{ok:bool, message:string} */
function einvoice_copy_from_galaxy(PDO $pdo): array
{
    $cfg = require app_path('config/einvoice.php');
    $ext = einvoice_external_pdo($cfg['galaxy'] ?? []);
    if ($ext === null) {
        return ['ok' => false, 'message' => 'تعذر الاتصال بقاعدة بيانات Galaxy.'];
    }

    $pfx = (string) (($cfg['galaxy']['prefix'] ?? '') ?: 'glx_');
    try {
        $row = $ext->query('SELECT client_id, secret_key FROM ' . $pfx . 'settings WHERE id = 1 LIMIT 1')->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر قراءة بيانات Galaxy: ' . $e->getMessage()];
    }

    if (!$row) {
        return ['ok' => false, 'message' => 'لم تُعثر على بيانات اعتماد في Galaxy.'];
    }

    $current = einvoice_settings_get($pdo);
    $current['client_id'] = $row['client_id'] ?? '';
    $current['secret_key'] = $row['secret_key'] ?? '';
    if (!einvoice_settings_save($pdo, $current)) {
        return ['ok' => false, 'message' => 'تعذر حفظ بيانات الاعتماد.'];
    }

    return ['ok' => true, 'message' => 'تم نسخ Client ID و Secret Key من Galaxy.'];
}

/** @return array{ok:bool, html:string} */
function einvoice_verify_credentials(PDO $pdo): array
{
    $cfg = require app_path('config/einvoice.php');
    $cur = einvoice_settings_get($pdo);
    $curId = (string) ($cur['client_id'] ?? '');
    $curKey = (string) ($cur['secret_key'] ?? '');

    $rows = [
        ['المصدر', 'Client ID', 'Secret Key'],
        ['النظام الحالي', $curId, $curKey !== '' ? (substr($curKey, 0, 12) . '… (' . strlen($curKey) . ')') : '—'],
    ];

    $ext = einvoice_external_pdo($cfg['admin'] ?? []);
    if ($ext !== null) {
        $pfx = (string) (($cfg['admin']['prefix'] ?? '') ?: 'glx_');
        try {
            $adm = $ext->query('SELECT client_id, secret_key FROM ' . $pfx . 'settings WHERE id = 1 LIMIT 1')->fetch();
            if ($adm) {
                $aKey = (string) ($adm['secret_key'] ?? '');
                $rows[] = [
                    'admin',
                    (string) ($adm['client_id'] ?? ''),
                    $aKey !== '' ? (substr($aKey, 0, 12) . '… (' . strlen($aKey) . ')') : '—',
                ];
            }
        } catch (Throwable $e) {
            $rows[] = ['admin', 'خطأ', $e->getMessage()];
        }
    }

    $gExt = einvoice_external_pdo($cfg['galaxy'] ?? []);
    if ($gExt !== null) {
        $pfx = (string) (($cfg['galaxy']['prefix'] ?? '') ?: 'glx_');
        try {
            $gal = $gExt->query('SELECT client_id, secret_key FROM ' . $pfx . 'settings WHERE id = 1 LIMIT 1')->fetch();
            if ($gal) {
                $gKey = (string) ($gal['secret_key'] ?? '');
                $rows[] = [
                    'Galaxy',
                    (string) ($gal['client_id'] ?? ''),
                    $gKey !== '' ? (substr($gKey, 0, 12) . '… (' . strlen($gKey) . ')') : '—',
                ];
            }
        } catch (Throwable $e) {
            $rows[] = ['Galaxy', 'خطأ', $e->getMessage()];
        }
    }

    $match = true;
    if (isset($rows[2]) && $rows[2][0] === 'admin') {
        $match = ($rows[1][1] === $rows[2][1] && $curKey === ($adm['secret_key'] ?? ''));
    }

    $html = '<table class="einv-verify-table"><thead><tr>';
    foreach ($rows[0] as $h) {
        $html .= '<th>' . esc((string) $h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    for ($i = 1; $i < count($rows); $i++) {
        $html .= '<tr>';
        foreach ($rows[$i] as $cell) {
            $html .= '<td>' . esc((string) $cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p class="' . ($match ? 'einv-verify-ok' : 'einv-verify-warn') . '">';
    $html .= $match ? 'البيانات متطابقة مع admin (إن وُجد).' : 'تحقق من تطابق بيانات الاعتماد بين الأنظمة.';
    $html .= '</p>';

    return ['ok' => true, 'html' => $html];
}

/** @return list<string> */
function einvoice_settings_validation_errors(array $settings): array
{
    $errors = [];
    if (trim((string) ($settings['company_name'] ?? '')) === '') {
        $errors[] = 'اسم الشركة مطلوب.';
    }
    if (trim((string) ($settings['vat_no'] ?? '')) === '') {
        $errors[] = 'الرقم الضريبي (VAT) مطلوب.';
    }
    if (trim((string) ($settings['gst_no'] ?? '')) === '') {
        $errors[] = 'رقم GST مطلوب.';
    }
    if (trim((string) ($settings['client_id'] ?? '')) === '') {
        $errors[] = 'Client ID مطلوب للإرسال.';
    }
    if (trim((string) ($settings['secret_key'] ?? '')) === '') {
        $errors[] = 'Secret Key مطلوب للإرسال.';
    }
    if (trim((string) ($settings['invoice_cash'] ?? '')) === '') {
        $errors[] = 'كود الفاتورة النقدية مطلوب.';
    }
    if (trim((string) ($settings['invoice_debit'] ?? '')) === '') {
        $errors[] = 'كود الفاتورة الآجلة مطلوب.';
    }

    return $errors;
}

/**
 * يختبر الاتصال الفعلي بنظام الفوترة JoFotara باستخدام بيانات الاعتماد المحفوظة.
 *
 * يُرسل طلب POST بجسم فارغ ويُحلِّل رمز HTTP والرد:
 * - 200/201 + EINV_STATUS → الاعتمادات صحيحة والـ API متاح.
 * - 400 ومحتوى الخطأ يخص بنية الفاتورة → الاتصال صحيح والاعتمادات سليمة.
 * - 401/403 → بيانات اعتماد خاطئة.
 * - 404/500 → مشكلة في رابط الـ API أو الخدمة معطّلة.
 * - cURL error → الشبكة/SSL/DNS.
 *
 * @return array{
 *   ok:bool,
 *   level:string,        // 'success' | 'warning' | 'error'
 *   title:string,
 *   message:string,
 *   http_code:int,
 *   raw:?string,
 *   url:string
 * }
 */
function einvoice_test_connection(PDO $pdo): array
{
    $settings = einvoice_settings_get($pdo);

    $clientId = trim((string) ($settings['client_id'] ?? ''));
    $secretKey = trim((string) ($settings['secret_key'] ?? ''));
    $apiUrl = rtrim((string) ($settings['jofotara_api_url'] ?? 'https://backend.jofotara.gov.jo/core/invoices/'), '/') . '/';

    $out = [
        'ok' => false,
        'level' => 'error',
        'title' => 'فشل الاختبار',
        'message' => '',
        'http_code' => 0,
        'raw' => null,
        'url' => $apiUrl,
    ];

    if ($clientId === '' || $secretKey === '') {
        $out['title'] = 'بيانات اعتماد ناقصة';
        $out['message'] = 'Client ID و Secret Key مطلوبان لإجراء الاختبار. عبّئهما في الإعدادات أولاً.';

        return $out;
    }

    if (!function_exists('curl_init')) {
        $out['title'] = 'cURL غير مُفعّل';
        $out['message'] = 'إضافة cURL في PHP غير مُفعّلة. لا يمكن الاتصال بنظام الفوترة بدونها.';

        return $out;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['invoice' => ''], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Id: ' . $clientId,
            'Secret-Key: ' . $secretKey,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    $curlNo = curl_errno($ch);
    curl_close($ch);

    $out['http_code'] = $httpCode;
    $out['raw'] = is_string($response) ? substr($response, 0, 800) : null;

    if ($curlNo !== 0) {
        $out['title'] = 'تعذّر الوصول إلى الخادم';
        switch ($curlNo) {
            case CURLE_COULDNT_RESOLVE_HOST:
                $out['message'] = 'تعذّر تحويل اسم النطاق إلى عنوان IP. تأكّد من اتصال السيرفر بالإنترنت وأن رابط الـ API صحيح.';
                break;
            case CURLE_COULDNT_CONNECT:
                $out['message'] = 'تعذّر فتح اتصال بالخادم. قد يكون الجدار الناري على XAMPP/الخادم يحجب الاتصال.';
                break;
            case CURLE_OPERATION_TIMEDOUT:
                $out['message'] = 'انتهت مهلة الاتصال (timeout). الخادم بطيء أو غير متاح حالياً.';
                break;
            case CURLE_SSL_CONNECT_ERROR:
            case CURLE_SSL_CACERT:
                $out['message'] = 'مشكلة شهادة SSL/TLS. حدّث شهادات XAMPP أو تواصل مع الدعم.';
                break;
            default:
                $out['message'] = 'cURL Error (' . $curlNo . '): ' . $curlErr;
        }

        return $out;
    }

    $body = (string) $response;
    $bodyLower = strtolower($body);
    $parsed = json_decode($body, true);
    $isJson = is_array($parsed);
    $hasAuthHint = strpos($bodyLower, 'unauthor') !== false
        || strpos($bodyLower, 'invalid client') !== false
        || strpos($bodyLower, 'invalid secret') !== false
        || strpos($bodyLower, 'client-id') !== false
        || strpos($bodyLower, 'secret-key') !== false
        || strpos($bodyLower, 'forbidden') !== false;

    if ($httpCode === 401 || $httpCode === 403 || $hasAuthHint) {
        $out['title'] = 'بيانات الاعتماد غير صحيحة';
        $out['message'] = 'تم الاتصال بالخادم لكنه رفض البيانات. راجع Client ID و Secret Key واطلبهما من بوابة JoFotara.';
        $out['level'] = 'error';

        return $out;
    }

    if ($httpCode === 404) {
        $out['title'] = 'رابط API غير صحيح';
        $out['message'] = 'الخادم يرد لكن المسار غير موجود. تأكّد من رابط الـ API في الإعدادات.';

        return $out;
    }

    if ($httpCode >= 500) {
        $out['title'] = 'خطأ في خدمة الفوترة';
        $out['message'] = 'الخادم يرد بخطأ داخلي (HTTP ' . $httpCode . '). الاعتمادات سليمة على الأرجح لكن الخدمة معطّلة مؤقتاً.';
        $out['level'] = 'warning';
        $out['ok'] = false;

        return $out;
    }

    // 200/201/400 جسم فارغ مرفوض كمحتوى ولكنه يعني الاتصال + المصادقة نجحت.
    if ($httpCode === 200 || $httpCode === 201 || $httpCode === 400 || $httpCode === 422) {
        $out['ok'] = true;
        $out['level'] = 'success';
        $out['title'] = 'الاتصال ناجح';
        $out['message'] = 'تمّ الاتصال بـ JoFotara بنجاح وقُبلت بيانات الاعتماد. النظام جاهز لإرسال الفواتير.';
        if ($isJson && isset($parsed['EINV_STATUS'])) {
            $out['message'] .= ' (الحالة: ' . (string) $parsed['EINV_STATUS'] . ')';
        }

        return $out;
    }

    if ($httpCode === 0) {
        $out['title'] = 'لا استجابة من الخادم';
        $out['message'] = 'لم تُستلَم استجابة. تحقّق من اتصال الإنترنت ومن إعدادات الـ proxy.';

        return $out;
    }

    $out['title'] = 'استجابة غير متوقَّعة';
    $out['message'] = 'الخادم رد بحالة HTTP ' . $httpCode . '. راجع رسالة الخادم في الأسفل.';
    $out['level'] = 'warning';

    return $out;
}

/** مُرسلة للفوترة — نفس admin: وجود EINV_QR */
function einvoice_sale_is_sent(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')) {
        return false;
    }
    $st = $pdo->prepare('SELECT einv_qr FROM sal_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $qr = $st->fetchColumn();

    return is_string($qr) && trim($qr) !== '';
}

/** @return array<string, mixed>|null */
function einvoice_sale_status_row(PDO $pdo, int $invoiceId): ?array
{
    if ($invoiceId < 1 || !einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT invoice_uuid, reference_status, return_id, einv_hash,
                einv_status, einv_results, einv_signed_invoice, einv_qr, einv_num, einv_inv_uuid, einv_sent_at
         FROM sal_invoice WHERE id = ? LIMIT 1'
    );
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
