<?php
declare(strict_types=1);

require_once app_path('includes/company_settings.php');
require_once app_path('includes/company_currency.php');
require_once app_path('includes/company_smtp.php');
require_once app_path('includes/company_whatsapp.php');
require_once app_path('includes/fin_check_due_email.php');
require_once app_path('includes/login_recaptcha.php');

$decimalPlacesMax = invoice_amount_decimals_max();
company_settings_ensure_default_row($pdo);
company_settings_ensure_invoice_unit_price_decimal_places_column($pdo);
company_settings_ensure_invoice_print_decimal_places_columns($pdo);
company_settings_ensure_currency_column($pdo);
company_smtp_ensure_schema($pdo);
company_whatsapp_ensure_schema($pdo);
fin_check_due_email_ensure_settings_columns($pdo);
login_recaptcha_ensure_schema($pdo);

require_once app_path('includes/sal_invoice_schema.php');
sal_invoice_ensure_schema($pdo);

$taxRateOptions = [];
try {
    $taxRateOptions = $pdo->query(
        'SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $taxRateOptions = [];
}

$row = $pdo->query('SELECT * FROM sys_company_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $name = trim((string) ($_POST['company_name_ar'] ?? ''));
        $dec = (int) ($_POST['decimal_places'] ?? 2);
        $unitPriceDec = (int) ($_POST['invoice_unit_price_decimal_places'] ?? 2);
        $printDec = (int) ($_POST['invoice_print_decimal_places'] ?? $dec);
        $printUnitPriceDec = (int) ($_POST['invoice_print_unit_price_decimal_places'] ?? $unitPriceDec);
        $rowsPerPage = (int) ($_POST['rows_per_page'] ?? 10);
        $currencyCode = strtoupper(trim((string) ($_POST['currency_code'] ?? 'SAR')));
        if (!company_currency_is_valid($currencyCode)) {
            $currencyCode = 'SAR';
        }
        $addr = trim((string) ($_POST['address_ar'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        $smtpHost = trim((string) ($_POST['smtp_host'] ?? ''));
        $smtpPortRaw = (int) ($_POST['smtp_port'] ?? 587);
        $smtpPort = ($smtpPortRaw > 0 && $smtpPortRaw < 65536) ? $smtpPortRaw : 587;
        $smtpSecure = strtolower(trim((string) ($_POST['smtp_secure'] ?? 'tls')));
        if (!in_array($smtpSecure, ['tls', 'ssl', 'none'], true)) {
            $smtpSecure = 'tls';
        }
        $smtpUser = trim((string) ($_POST['smtp_username'] ?? ''));
        $smtpPass = (string) ($_POST['smtp_password'] ?? '');
        $smtpFromEmail = trim((string) ($_POST['smtp_from_email'] ?? ''));
        $smtpFromName = trim((string) ($_POST['smtp_from_name'] ?? ''));
        $existingSmtpPass = (string) ($row['smtp_password'] ?? '');
        if ($smtpPass === '' && $existingSmtpPass !== '') {
            $smtpPass = $existingSmtpPass;
        }

        $checkEmailEnabled = !empty($_POST['check_email_enabled']);
        $checkEmailDaysBefore = max(1, min(60, (int) ($_POST['check_email_days_before'] ?? 5)));
        $checkEmailOnDueDay = !empty($_POST['check_email_on_due_day']);
        $checkEmailRecipients = trim((string) ($_POST['check_email_recipients'] ?? ''));

        $loginRecaptchaEnabled = !empty($_POST['login_recaptcha_enabled']);
        $loginRecaptchaSiteKey = trim((string) ($_POST['login_recaptcha_site_key'] ?? ''));
        $loginRecaptchaSecretInput = (string) ($_POST['login_recaptcha_secret_key'] ?? '');
        $existingLoginRecaptchaSecret = (string) ($row['login_recaptcha_secret_key'] ?? '');
        $loginRecaptchaSecret = $loginRecaptchaSecretInput === '' && $existingLoginRecaptchaSecret !== ''
            ? $existingLoginRecaptchaSecret
            : $loginRecaptchaSecretInput;
        if ($loginRecaptchaSiteKey !== '' && $loginRecaptchaSecret !== '') {
            $loginRecaptchaEnabled = true;
        }

        $waProvider = 'cloud';
        $waPhoneId = trim((string) ($_POST['wa_phone_id'] ?? ''));
        $waApiVersion = trim((string) ($_POST['wa_api_version'] ?? 'v20.0'));
        if ($waApiVersion === '') {
            $waApiVersion = 'v20.0';
        }
        $waToken = (string) ($_POST['wa_access_token'] ?? '');
        $existingWaToken = (string) ($row['wa_access_token'] ?? '');
        if ($waToken === '' && $existingWaToken !== '') {
            $waToken = $existingWaToken;
        }
        $waCountry = preg_replace('/\D+/', '', (string) ($_POST['wa_default_country'] ?? '')) ?: '';
        $waBridgeUrl = null;
        $waBridgeToken = null;

        $taxOpts = [];
        try {
            $taxOpts = $pdo->query(
                'SELECT id, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $taxOpts = [];
        }

        $taxF = null;
        if ($taxOpts !== []) {
            $taxId = (int) ($_POST['default_tax_rate_id'] ?? 0);
            if ($taxId > 0) {
                $ts = $pdo->prepare('SELECT rate_percent FROM sys_tax_rate WHERE id = ? AND is_active = 1 LIMIT 1');
                $ts->execute([$taxId]);
                $col = $ts->fetchColumn();
                if ($col !== false) {
                    $taxF = (float) $col;
                }
            }
            if ($taxF === null) {
                $taxF = (float) ($taxOpts[0]['rate_percent'] ?? 0);
            }
        } else {
            $tax = (string) ($_POST['tax_rate_percent'] ?? '0');
            $taxF = (float) str_replace(',', '.', $tax);
        }

        $checkEmailHasRecipient = fin_check_due_email_parse_recipients($checkEmailRecipients) !== []
            || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL));
        $smtpReadyForChecks = company_smtp_is_configured($pdo)
            || ($smtpHost !== '' && ($smtpFromEmail !== '' || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))));

        if ($name === '') {
            $msg = 'اسم الشركة مطلوب.';
            $msgType = 'error';
        } elseif ($dec < 0 || $dec > $decimalPlacesMax) {
            $msg = 'عدد الخانات العشرية يجب أن يكون بين 0 و ' . $decimalPlacesMax . '.';
            $msgType = 'error';
        } elseif ($unitPriceDec < 0 || $unitPriceDec > $decimalPlacesMax) {
            $msg = 'خانات السعر الافرادي في الفواتير يجب أن تكون بين 0 و ' . $decimalPlacesMax . '.';
            $msgType = 'error';
        } elseif ($printDec < 0 || $printDec > $decimalPlacesMax) {
            $msg = 'خانات الطباعة للمبالغ يجب أن تكون بين 0 و ' . $decimalPlacesMax . '.';
            $msgType = 'error';
        } elseif ($printUnitPriceDec < 0 || $printUnitPriceDec > $decimalPlacesMax) {
            $msg = 'خانات الطباعة لسعر الوحدة يجب أن تكون بين 0 و ' . $decimalPlacesMax . '.';
            $msgType = 'error';
        } elseif (!in_array($rowsPerPage, [10, 15, 20], true)) {
            $msg = 'عدد الأسطر بالصفحة يجب أن يكون 10 أو 15 أو 20.';
            $msgType = 'error';
        } elseif ($checkEmailEnabled && !$checkEmailHasRecipient) {
            $msg = 'فعّلت تنبيهات الشيكات: أدخل بريداً واحداً على الأقل في قائمة المستلمين، أو بريداً صالحاً في حقل «البريد» الرئيسي.';
            $msgType = 'error';
        } elseif ($checkEmailEnabled && !$smtpReadyForChecks) {
            $msg = 'فعّلت تنبيهات الشيكات: أكمل إعدادات SMTP (الخادم والبريد المرسل) أولاً.';
            $msgType = 'error';
        } elseif ($loginRecaptchaEnabled && $loginRecaptchaSiteKey === '') {
            $msg = 'reCAPTCHA: أدخل Site Key من Google.';
            $msgType = 'error';
        } elseif ($loginRecaptchaEnabled && $loginRecaptchaSecret === '') {
            $msg = 'reCAPTCHA: أدخل Secret Key من Google (مطلوب في أول حفظ).';
            $msgType = 'error';
        } else {
            if ($taxF < 0 || $taxF > 100) {
                $msg = 'نسبة الضريبة غير منطقية.';
                $msgType = 'error';
            } else {
                $logoPath = trim((string) ($row['logo_path'] ?? ''));
                $logoSkipNote = '';

                $file = $_FILES['logo'] ?? null;
                if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($errCode !== UPLOAD_ERR_OK) {
                        $upMsg = company_settings_upload_error_message($errCode);
                        if ($upMsg !== '') {
                            $logoSkipNote = ' ' . $upMsg . ' لم يُغيَّر الشعار.';
                        }
                    } elseif (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
                        $logoSkipNote = ' تعذر قبول ملف الشعار.';
                    } else {
                        $tmp = (string) $file['tmp_name'];
                        $mime = company_settings_upload_mime($tmp);
                        $map = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                        ];
                        if (!isset($map[$mime])) {
                            $logoSkipNote = ' الشعار لم يُحدَّث (استخدم PNG أو JPG أو WebP).';
                        } elseif (($file['size'] ?? 0) > 2_000_000) {
                            $logoSkipNote = ' الشعار كبير جدًا (الحد 2 ميجابايت) ولم يُحفظ.';
                        } else {
                            $dir = app_path('uploads/logos');
                            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                                $msg = 'تعذر إنشاء مجلد الشعارات. تحقق من صلاحيات مجلد uploads.';
                                $msgType = 'error';
                            } else {
                                $fname = 'logo_' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
                                $dest = $dir . DIRECTORY_SEPARATOR . $fname;
                                if (!move_uploaded_file($tmp, $dest)) {
                                    $msg = 'تعذر حفظ ملف الشعار. تحقق من صلاحيات الكتابة على uploads/logos.';
                                    $msgType = 'error';
                                } else {
                                    $rel = 'uploads/logos/' . $fname;
                                    if ($logoPath !== '' && is_file(app_path($logoPath))) {
                                        @unlink(app_path($logoPath));
                                    }
                                    $logoPath = $rel;
                                }
                            }
                        }
                    }
                }

                if ($msgType !== 'error') {
                    $oldDecimalPlaces = (int) ($row['decimal_places'] ?? 2);
                    try {
                        $st = $pdo->prepare(
                            'UPDATE sys_company_settings SET
                            company_name_ar = ?, tax_rate_percent = ?, decimal_places = ?, invoice_unit_price_decimal_places = ?,
                            invoice_print_decimal_places = ?, invoice_print_unit_price_decimal_places = ?,
                            rows_per_page = ?,
                            currency_code = ?,
                            address_ar = ?, phone = ?, email = ?, logo_path = ?,
                            smtp_host = ?, smtp_port = ?, smtp_secure = ?, smtp_username = ?, smtp_password = ?,
                            smtp_from_email = ?, smtp_from_name = ?,
                            wa_provider = ?, wa_phone_id = ?, wa_access_token = ?, wa_api_version = ?, wa_default_country = ?,
                            wa_bridge_url = ?, wa_bridge_token = ?,
                            check_email_enabled = ?, check_email_days_before = ?, check_email_on_due_day = ?,
                            check_email_recipients = ?,
                            login_recaptcha_enabled = ?, login_recaptcha_site_key = ?, login_recaptcha_secret_key = ?
                            WHERE id = 1'
                        );
                        $params = [
                            $name,
                            number_format($taxF, 3, '.', ''),
                            $dec,
                            $unitPriceDec,
                            $printDec,
                            $printUnitPriceDec,
                            $rowsPerPage,
                            $currencyCode,
                            $addr !== '' ? $addr : null,
                            $phone !== '' ? $phone : null,
                            $email !== '' ? $email : null,
                            $logoPath !== '' ? $logoPath : null,
                            $smtpHost !== '' ? $smtpHost : null,
                            $smtpPort,
                            $smtpSecure,
                            $smtpUser !== '' ? $smtpUser : null,
                            $smtpPass !== '' ? $smtpPass : null,
                            $smtpFromEmail !== '' ? $smtpFromEmail : null,
                            $smtpFromName !== '' ? $smtpFromName : null,
                            $waProvider,
                            $waPhoneId !== '' ? $waPhoneId : null,
                            $waToken !== '' ? $waToken : null,
                            $waApiVersion,
                            $waCountry !== '' ? $waCountry : null,
                            $waBridgeUrl,
                            $waBridgeToken,
                            $checkEmailEnabled ? 1 : 0,
                            $checkEmailDaysBefore,
                            $checkEmailOnDueDay ? 1 : 0,
                            $checkEmailRecipients !== '' ? $checkEmailRecipients : null,
                            $loginRecaptchaEnabled ? 1 : 0,
                            $loginRecaptchaSiteKey !== '' ? $loginRecaptchaSiteKey : null,
                            $loginRecaptchaSecret !== '' ? $loginRecaptchaSecret : null,
                        ];
                        $st->execute($params);
                        if ($st->rowCount() === 0) {
                            company_settings_ensure_default_row($pdo);
                            $st->execute($params);
                        }
                        $GLOBALS['_company_settings_cache'] = null;
                        company_currency_reset_cache();
                        company_settings_ensure_invoice_unit_price_decimal_places_column($pdo);
                        $msg = 'تم حفظ الإعدادات.' . ($logoSkipNote !== '' ? ' —' . $logoSkipNote : '');
                        if ($dec !== $oldDecimalPlaces) {
                            require_once app_path('includes/company_decimal_reapply.php');
                            $reapply = company_reapply_decimal_places_all($pdo);
                            if (!$reapply['ok']) {
                                $msg .= ' تعذر تطبيق الخانات العشرية على بعض الفواتير.';
                                if ($reapply['errors'] !== []) {
                                    $msg .= ' ' . implode(' ', array_slice($reapply['errors'], 0, 2));
                                }
                                $msgType = 'error';
                            } else {
                                $msg .= sprintf(
                                    ' وتم تطبيق التقريب على %d فاتورة بيع و%d فاتورة شراء غير مرحّلة',
                                    (int) $reapply['sal_invoices'],
                                    (int) $reapply['pur_invoices']
                                );
                                $msg .= '. الفواتير المرحّلة تحتفظ بعدد الخانات المثبت عند الترحيل.';
                            }
                        }
                        if ($msgType !== 'error') {
                            $msgType = 'success';
                        }
                        $row = $pdo->query('SELECT * FROM sys_company_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
                    } catch (Throwable $e) {
                        $msg = 'تعذر حفظ الإعدادات في قاعدة البيانات.';
                        if (strpos($e->getMessage(), 'Unknown column') !== false) {
                            $msg .= ' قد يكون عمود الشعار أو الحقول غير موجود — نفّذ database/schema.sql أو ترحيلات الإعدادات.';
                        }
                        $msgType = 'error';
                    }
                }
            }
        }
    }
}

$currentTax = (float) ($row['tax_rate_percent'] ?? 15);
$selectedTaxId = 0;
foreach ($taxRateOptions as $o) {
    if (abs((float) $o['rate_percent'] - $currentTax) < 0.0001) {
        $selectedTaxId = (int) $o['id'];
        break;
    }
}
if ($selectedTaxId < 1 && $taxRateOptions !== []) {
    $selectedTaxId = (int) $taxRateOptions[0]['id'];
}

$taxRatesUrl = app_url('index.php?r=tax_rates_settings');

$dp = (int) ($row['decimal_places'] ?? 2);
$unitPriceDp = (int) ($row['invoice_unit_price_decimal_places'] ?? $dp);
if ($unitPriceDp < 0 || $unitPriceDp > 8) {
    $unitPriceDp = $dp;
}
$printDp = (int) ($row['invoice_print_decimal_places'] ?? $dp);
if ($printDp < 0 || $printDp > 8) {
    $printDp = $dp;
}
$printUnitPriceDp = (int) ($row['invoice_print_unit_price_decimal_places'] ?? $unitPriceDp);
if ($printUnitPriceDp < 0 || $printUnitPriceDp > 8) {
    $printUnitPriceDp = $unitPriceDp;
}
$rpp = (int) ($row['rows_per_page'] ?? 10);
if (!in_array($rpp, [10, 15, 20], true)) {
    $rpp = 10;
}
$currencyCatalog = company_currency_catalog();
$currentCurrencyCode = strtoupper(trim((string) ($row['currency_code'] ?? 'SAR')));
if (!isset($currencyCatalog[$currentCurrencyCode])) {
    $currentCurrencyCode = 'SAR';
}

$cssPath = app_path('assets/css/settings-oracle12.css');
$cssUrl = app_url('assets/css/settings-oracle12.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<?php if ($msg !== ''): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> settings-ora-flash"><?= esc($msg) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="settings-ora-form master-page-form" id="settings-form">
    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">بيانات الشركة</h2>
        <div class="settings-ora-panel-body">
        <div class="form-row">
            <label class="field">
                <span class="field-label">اسم الشركة</span>
                <input class="input" name="company_name_ar" required value="<?= esc((string) ($row['company_name_ar'] ?? '')) ?>">
            </label>
            <label class="field">
                <span class="field-label">النسبة الافتراضية للضريبة</span>
                <?php if ($taxRateOptions !== []): ?>
                    <select class="input" name="default_tax_rate_id" required>
                        <?php foreach ($taxRateOptions as $o): ?>
                            <option value="<?= (int) $o['id'] ?>" <?= (int) $o['id'] === $selectedTaxId ? 'selected' : '' ?>>
                                <?= esc((string) $o['name_ar']) ?> (<?= esc(rtrim(rtrim((string) $o['rate_percent'], '0'), '.')) ?>%)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (user_can('tax_rates_settings')): ?>
                        <span class="field-hint">
                            لإضافة أو تعديل المعدّلات:
                            <a href="<?= esc($taxRatesUrl) ?>">معدّلات الضريبة</a>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <input class="input" name="tax_rate_percent" type="number" step="0.001" min="0" max="100" value="<?= esc((string) ($row['tax_rate_percent'] ?? '0')) ?>">
                    <span class="field-hint">
                        جدول معدّلات الضريبة غير متوفر؛ أدخل النسبة يدويًا أو نفّذ ترحيل قاعدة البيانات.
                        <?php if (user_can('tax_rates_settings')): ?>
                            <a href="<?= esc($taxRatesUrl) ?>">إدارة المعدّلات</a>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </label>
            <label class="field">
                <span class="field-label">الخانات العشرية بعد الفاصلة (النظام)</span>
                <input class="input" name="decimal_places" type="number" min="0" max="<?= (int) $decimalPlacesMax ?>" value="<?= esc((string) $dp) ?>">
                <span class="field-hint">تُطبَّق على جميع المبالغ في النظام (إجماليات، ضريبة، قبل الضريبة، تقارير، …). حتى <?= (int) $decimalPlacesMax ?> خانات.</span>
            </label>
            <label class="field">
                <span class="field-label">الخانات العشرية للسعر الافرادي في الفواتير</span>
                <input class="input" name="invoice_unit_price_decimal_places" type="number" min="0" max="<?= (int) $decimalPlacesMax ?>" value="<?= esc((string) $unitPriceDp) ?>">
                <span class="field-hint">تُطبَّق على عمود <strong>سعر الوحدة</strong> فقط في فواتير البيع والشراء (مثلاً <?= (int) $decimalPlacesMax ?> خانات للسعر و3 لباقي المبالغ).</span>
            </label>
            <label class="field">
                <span class="field-label">خانات عشرية للمبالغ عند طباعة الفاتورة</span>
                <input class="input" name="invoice_print_decimal_places" type="number" min="0" max="<?= (int) $decimalPlacesMax ?>" value="<?= esc((string) $printDp) ?>">
                <span class="field-hint">تُطبَّق عند <strong>طباعة</strong> فاتورة البيع/الشراء فقط (إجماليات، ضريبة، قبل الضريبة، …) — مستقلة عن العرض على الشاشة.</span>
            </label>
            <label class="field">
                <span class="field-label">خانات عشرية لسعر الوحدة عند طباعة الفاتورة</span>
                <input class="input" name="invoice_print_unit_price_decimal_places" type="number" min="0" max="<?= (int) $decimalPlacesMax ?>" value="<?= esc((string) $printUnitPriceDp) ?>">
                <span class="field-hint">تُطبَّق على <strong>سعر الوحدة</strong> في نسخة الطباعة/PDF فقط.</span>
            </label>
            <label class="field">
                <span class="field-label">عدد الأسطر بالصفحة</span>
                <select class="input" name="rows_per_page">
                    <?php foreach ([10, 15, 20] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $rpp === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">يُطبَّق على قوائم العملاء والمواد والفواتير وغيرها مع ترقيم الصفحات.</span>
            </label>
            <label class="field">
                <span class="field-label">العملة</span>
                <select class="input" name="currency_code">
                    <?php foreach ($currencyCatalog as $cc): ?>
                        <option value="<?= esc((string) $cc['code']) ?>" <?= $cc['code'] === $currentCurrencyCode ? 'selected' : '' ?>>
                            <?= esc((string) $cc['name_ar']) ?> (<?= esc((string) $cc['symbol']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">تُستخدم في تفقيط المبالغ على السندات والفواتير المطبوعة.</span>
            </label>
        </div>

        <label class="field field--full">
            <span class="field-label">العنوان</span>
            <textarea class="input" name="address_ar" rows="3"><?= esc((string) ($row['address_ar'] ?? '')) ?></textarea>
        </label>

        <div class="form-row">
            <label class="field">
                <span class="field-label">الهاتف</span>
                <input class="input" name="phone" value="<?= esc((string) ($row['phone'] ?? '')) ?>">
            </label>
            <label class="field">
                <span class="field-label">البريد</span>
                <input class="input" name="email" type="email" value="<?= esc((string) ($row['email'] ?? '')) ?>">
            </label>
        </div>

        <label class="field field--full">
            <span class="field-label">شعار الشركة (PNG / JPG / WebP)</span>
            <input class="input" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
        </label>

        <?php if (!empty($row['logo_path'])): ?>
            <div class="settings-ora-logo-preview">
                الشعار الحالي:
                <img src="<?= esc(app_url((string) $row['logo_path'])) ?>" alt="">
            </div>
        <?php endif; ?>
        </div>
    </div>

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">إعدادات إرسال البريد (SMTP)</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">
                تُستخدم هذه الإعدادات لإرسال الفواتير والمرتجعات كـ PDF عبر البريد إلى العملاء/المورّدين.
                لـ Gmail استعمل <code>smtp.gmail.com</code> ومنفذ <code>587</code> مع TLS وكلمة مرور تطبيق.
            </p>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">خادم SMTP (Host)</span>
                    <input class="input" name="smtp_host" value="<?= esc((string) ($row['smtp_host'] ?? '')) ?>" placeholder="smtp.gmail.com">
                </label>
                <label class="field">
                    <span class="field-label">المنفذ</span>
                    <input class="input" name="smtp_port" type="number" min="1" max="65535" value="<?= esc((string) ($row['smtp_port'] ?? 587)) ?>">
                </label>
                <label class="field">
                    <span class="field-label">التشفير</span>
                    <?php $curSec = strtolower((string) ($row['smtp_secure'] ?? 'tls')); ?>
                    <select class="input" name="smtp_secure">
                        <option value="tls" <?= $curSec === 'tls' ? 'selected' : '' ?>>TLS (StartTLS)</option>
                        <option value="ssl" <?= $curSec === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= $curSec === 'none' ? 'selected' : '' ?>>بدون تشفير</option>
                    </select>
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">اسم المستخدم</span>
                    <input class="input" name="smtp_username" value="<?= esc((string) ($row['smtp_username'] ?? '')) ?>" placeholder="you@example.com" autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">كلمة المرور (اتركها فارغة للإبقاء عليها)</span>
                    <input class="input" name="smtp_password" type="password" value="" placeholder="<?= !empty($row['smtp_password']) ? '•••••• محفوظة' : '' ?>" autocomplete="new-password">
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">البريد المرسل منه (From)</span>
                    <input class="input" name="smtp_from_email" type="email" value="<?= esc((string) ($row['smtp_from_email'] ?? '')) ?>" placeholder="you@example.com">
                </label>
                <label class="field">
                    <span class="field-label">اسم المرسل</span>
                    <input class="input" name="smtp_from_name" value="<?= esc((string) ($row['smtp_from_name'] ?? '')) ?>" placeholder="<?= esc((string) ($row['company_name_ar'] ?? 'الشركة')) ?>">
                </label>
            </div>
        </div>
    </div>

    <?php
    $loginRecaptchaOn = (int) ($row['login_recaptcha_enabled'] ?? 0) === 1;
    $loginRecaptchaSite = (string) ($row['login_recaptcha_site_key'] ?? '');
    $recaptchaLive = login_recaptcha_settings($pdo);
    $recaptchaIsLive = (bool) ($recaptchaLive['enabled'] ?? false);
    $recaptchaHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $recaptchaHost = preg_replace('/:\d+$/', '', $recaptchaHost) ?? $recaptchaHost;
    $recaptchaDomains = ['localhost'];
    if ($recaptchaHost !== '' && !in_array($recaptchaHost, ['localhost', '127.0.0.1', '::1'], true)) {
        $recaptchaDomains[] = $recaptchaHost;
        if (str_starts_with($recaptchaHost, 'www.')) {
            $recaptchaDomains[] = substr($recaptchaHost, 4);
        } else {
            $recaptchaDomains[] = 'www.' . $recaptchaHost;
        }
    }
    $recaptchaDomains = array_values(array_unique(array_filter($recaptchaDomains)));
    ?>
    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">أمان تسجيل الدخول (Google reCAPTCHA)</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">
                <?php if ($recaptchaIsLive): ?>
                    <strong style="color:#15803d;">● reCAPTCHA مفعّل حالياً</strong> — يظهر مربع «I'm not a robot» على تسجيل الدخول.
                <?php else: ?>
                    <strong style="color:#b45309;">● reCAPTCHA غير مفعّل</strong>
                    — أدخل Site Key و Secret Key من Google ثم احفظ. (يجب إدخال Secret Key في أول مرة)
                <?php endif; ?>
            </p>
            <p class="field-hint" style="margin:0 0 0.55rem;">
                أنشئ مفتاحاً من
                <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">Google reCAPTCHA Admin</a>
                — النوع: <strong>v2</strong> ← «I'm not a robot» Checkbox.
                أضف النطاقات:
                <?php foreach ($recaptchaDomains as $i => $domain): ?>
                    <?= $i > 0 ? ' و ' : '' ?><code><?= esc($domain) ?></code>
                <?php endforeach; ?>
            </p>
            <label class="field field-check">
                <input type="checkbox" name="login_recaptcha_enabled" value="1" <?= $loginRecaptchaOn ? 'checked' : '' ?>>
                <span class="field-label">تفعيل reCAPTCHA على تسجيل الدخول واستعادة كلمة المرور</span>
            </label>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">Site Key</span>
                    <input class="input" name="login_recaptcha_site_key" dir="ltr"
                           value="<?= esc($loginRecaptchaSite) ?>" placeholder="6Lf..." autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">Secret Key</span>
                    <input class="input" name="login_recaptcha_secret_key" type="password" dir="ltr" value=""
                           placeholder="<?= !empty($row['login_recaptcha_secret_key']) ? '•••••• محفوظ — اتركه فارغاً للإبقاء' : '6Lf... (مطلوب أول مرة)' ?>"
                           autocomplete="new-password">
                </label>
            </div>
        </div>
    </div>

        <?php
        $checkEmailOn = (int) ($row['check_email_enabled'] ?? 0) === 1;
        $checkEmailDays = (int) ($row['check_email_days_before'] ?? 5);
        if ($checkEmailDays < 1 || $checkEmailDays > 60) {
            $checkEmailDays = 5;
        }
        $checkEmailDueDay = (int) ($row['check_email_on_due_day'] ?? 1) === 1;
        $checkEmailRcptRaw = (string) ($row['check_email_recipients'] ?? '');
        ?>
    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">تنبيهات استحقاق الشيكات (بريد تلقائي)</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">
                يُرسل بريد <strong>مرة واحدة يومياً</strong> لكل شيك في صندوق الشيكات: من اليوم X قبل الاستحقاق
                حتى يوم قبل الموعد (X رسائل)، ويمكن إضافة تنبيه يوم الاستحقاق نفسه.
                مثال: 5 أيام قبل = إرسال عندما يتبقى 5، 4، 3، 2، 1 يوم (5 مرات) ثم اختياري يوم الاستحقاق.
            </p>
            <label class="field field-check field--full">
                <input type="checkbox" name="check_email_enabled" value="1" <?= $checkEmailOn ? 'checked' : '' ?>>
                <span class="field-label">تفعيل التنبيهات التلقائية للشيكات</span>
            </label>
            <div class="form-row" style="margin-top:0.75rem;">
                <label class="field">
                    <span class="field-label">عدد الأيام قبل الاستحقاق (تنبيه يومي)</span>
                    <input class="input" name="check_email_days_before" type="number" min="1" max="60"
                           value="<?= esc((string) $checkEmailDays) ?>">
                    <span class="field-hint">من 1 إلى 60 — بريد واحد في كل يوم ضمن هذه الفترة.</span>
                </label>
                <label class="field field-check">
                    <input type="checkbox" name="check_email_on_due_day" value="1" <?= $checkEmailDueDay ? 'checked' : '' ?>>
                    <span class="field-label">إرسال تنبيه يوم الاستحقاق أيضاً</span>
                </label>
            </div>
            <label class="field field--full">
                <span class="field-label">بريد المستلمين (سطر لكل عنوان)</span>
                <textarea class="input" name="check_email_recipients" rows="4" dir="ltr"
                          placeholder="finance@company.com&#10;manager@company.com"><?= esc($checkEmailRcptRaw) ?></textarea>
                <span class="field-hint">
                    يمكن إضافة عدة عناوين (فاصلة أو سطر جديد). إن تُرك فارغاً يُستخدم حقل «البريد» الرئيسي أعلاه.
                </span>
            </label>
        </div>
    </div>

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">إعدادات WhatsApp</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">
                للإرسال عبر واتساب الأعمال الرسمي (Cloud API). احصل على
                <strong>Phone Number ID</strong> و <strong>Permanent Access Token</strong> من
                <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">Meta for Developers</a>.
            </p>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">Phone Number ID</span>
                    <input class="input" name="wa_phone_id" value="<?= esc((string) ($row['wa_phone_id'] ?? '')) ?>" placeholder="مثال: 1234567890123456" autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">إصدار API</span>
                    <input class="input" name="wa_api_version" value="<?= esc((string) ($row['wa_api_version'] ?? 'v20.0')) ?>" placeholder="v20.0">
                </label>
            </div>
            <label class="field">
                <span class="field-label">Access Token (اتركه فارغاً للإبقاء عليه)</span>
                <input class="input" name="wa_access_token" type="password" value="" placeholder="<?= !empty($row['wa_access_token']) ? '•••••• محفوظ' : 'EAAJ...' ?>" autocomplete="new-password">
            </label>
            <div class="form-row" style="margin-top:0.75rem;">
                <label class="field">
                    <span class="field-label">كود الدولة الافتراضي</span>
                    <input class="input" name="wa_default_country" value="<?= esc((string) ($row['wa_default_country'] ?? '')) ?>" placeholder="962 للأردن، 966 للسعودية" maxlength="5">
                    <span class="field-hint">يُستخدم تلقائياً عند إدخال رقم محلي يبدأ بـ 0.</span>
                </label>
            </div>
        </div>
    </div>

    <div class="settings-ora-actions no-print sr-only" aria-hidden="true">
        <button class="btn btn-primary" type="submit" id="settings-form-submit">حفظ الإعدادات</button>
    </div>
</form>

<p class="muted no-print settings-ora-toolbar-hint" style="margin:0.75rem 0 0;font-size:0.9rem;">
    عدّل الإعدادات ثم اضغط <strong>حفظ</strong> في الشريط العلوي.
</p>

<script>
document.addEventListener('master-toolbar', function (e) {
  if (e.detail && e.detail.action === 'save') {
    e.preventDefault();
    e.stopImmediatePropagation();
    var f = document.getElementById('settings-form');
    if (f) {
      if (typeof f.requestSubmit === 'function') {
        f.requestSubmit();
      } else {
        var btn = document.getElementById('settings-form-submit');
        if (btn) btn.click();
        else f.submit();
      }
    }
  }
});
</script>
