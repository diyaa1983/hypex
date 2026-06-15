<?php
declare(strict_types=1);

require_once app_path('includes/einvoice_settings.php');

$pdo = db();
einvoice_ensure_schema($pdo);

$settings = einvoice_settings_get($pdo);
$cashOpts = einvoice_invoice_cash_options();
$debitOpts = einvoice_invoice_debit_options();

$msg = '';
$msgType = '';
$verifyHtml = '';
$showVerifyOnly = false;
$connTest = null;

$action = (string) ($_GET['action'] ?? '');

if ($action === 'test_connection' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!verify_csrf($_GET['_csrf'] ?? null)) {
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=einvoice_settings'));
    }
    $connTest = einvoice_test_connection($pdo);
}

if ($action === 'import_admin' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!verify_csrf($_GET['_csrf'] ?? null)) {
        flash_set('error', 'انتهت صلاحية الجلسة.');
    } else {
        $res = einvoice_import_from_admin($pdo);
        flash_set($res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirect(app_url('index.php?r=einvoice_settings'));
}

if ($action === 'copy_galaxy' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!verify_csrf($_GET['_csrf'] ?? null)) {
        flash_set('error', 'انتهت صلاحية الجلسة.');
    } else {
        $res = einvoice_copy_from_galaxy($pdo);
        flash_set($res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirect(app_url('index.php?r=einvoice_settings'));
}

if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify = einvoice_verify_credentials($pdo);
    $verifyHtml = $verify['html'];
    $showVerifyOnly = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $data = [
            'company_name' => $_POST['company_name'] ?? '',
            'trade_name' => $_POST['trade_name'] ?? '',
            'vat_no' => $_POST['vat_no'] ?? '',
            'gst_no' => $_POST['gst_no'] ?? '',
            'company_email' => $_POST['company_email'] ?? '',
            'company_phone' => $_POST['company_phone'] ?? '',
            'address' => $_POST['address'] ?? '',
            'city' => $_POST['city'] ?? '',
            'taxes_type' => $_POST['taxes_type'] ?? 2,
            'invoice_cash' => $_POST['invoice_cash'] ?? '',
            'invoice_debit' => $_POST['invoice_debit'] ?? '',
            'client_id' => $_POST['client_id'] ?? '',
            'secret_key' => $_POST['secret_key'] ?? '',
            'admin_email' => $_POST['admin_email'] ?? '',
            'jofotara_api_url' => $_POST['jofotara_api_url'] ?? '',
            'notes' => $_POST['notes'] ?? '',
        ];
        if (trim((string) $data['company_name']) === '') {
            $msg = 'اسم الشركة مطلوب.';
            $msgType = 'error';
        } elseif (trim((string) $data['vat_no']) === '' || trim((string) $data['gst_no']) === '') {
            $msg = 'الرقم الضريبي ورقم GST مطلوبان.';
            $msgType = 'error';
        } elseif (trim((string) $data['client_id']) === '' || trim((string) $data['secret_key']) === '') {
            $msg = 'Client ID و Secret Key مطلوبان.';
            $msgType = 'error';
        } elseif (einvoice_settings_save($pdo, $data)) {
            $msg = 'تم حفظ إعدادات الفوترة بنجاح.';
            $msgType = 'success';
            $settings = einvoice_settings_get($pdo);
        } else {
            $msg = 'تعذر الحفظ.';
            $msgType = 'error';
        }
    }
}

$flash = flash_get();
if ($flash) {
    $msg = (string) $flash['message'];
    $msgType = $flash['type'] === 'success' ? 'success' : 'error';
}

$selCash = (string) ($settings['invoice_cash'] ?? '011');
$selDebit = (string) ($settings['invoice_debit'] ?? '021');
if ($selCash !== '' && !isset($cashOpts[$selCash])) {
    $cashOpts[$selCash] = 'قيمة محفوظة: ' . $selCash;
}
if ($selDebit !== '' && !isset($debitOpts[$selDebit])) {
    $debitOpts[$selDebit] = 'قيمة محفوظة: ' . $selDebit;
}

$baseUrl = app_url('index.php?r=einvoice_settings');
$csrf = csrf_token();
$apiUrlDisplay = (string) ($settings['jofotara_api_url'] ?? 'https://backend.jofotara.gov.jo/core/invoices/');

$cssSettingsPath = app_path('assets/css/settings-oracle12.css');
$cssSettingsUrl = app_url('assets/css/settings-oracle12.css')
    . (is_file($cssSettingsPath) ? '?v=' . (string) filemtime($cssSettingsPath) : '');
$cssEinvPath = app_path('assets/css/einvoice-oracle12.css');
$cssEinvUrl = app_url('assets/css/einvoice-oracle12.css')
    . (is_file($cssEinvPath) ? '?v=' . (string) filemtime($cssEinvPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssSettingsUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssEinvUrl) ?>">

<?php if ($showVerifyOnly && $verifyHtml !== ''): ?>
<div class="einvoice-ora-verify-wrap">
    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">مقارنة الاعتماد المحلية</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint">مقارنة بين بيانات هذا النظام وقواعد admin / Galaxy.</p>
            <?= $verifyHtml ?>
        </div>
    </div>
    <div class="settings-ora-actions">
        <a class="btn btn-secondary" href="<?= esc($baseUrl) ?>">العودة لإعدادات الفوترة</a>
    </div>
</div>
<?php return; endif; ?>

<?php if ($msg !== ''): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> settings-ora-flash"><?= esc($msg) ?></div>
<?php endif; ?>

<p class="einvoice-ora-intro">بيانات البائع واعتماد JoFotara للربط مع نظام الفوترة الأردني.</p>

<div class="einvoice-ora-toolbar no-print">
    <span class="einvoice-ora-toolbar-label">اختبار وتنزيل الاعتماد</span>
    <div class="einvoice-ora-toolbar-actions">
        <a class="btn btn-sm btn-test-jofotara"
           href="<?= esc($baseUrl . '&action=test_connection&_csrf=' . rawurlencode($csrf)) ?>"
           title="إجراء اتصال فعلي مع نظام الفوترة للتحقق من Client-Id و Secret-Key">
            اختبار الاتصال بـ JoFotara
        </a>
        <a class="btn btn-secondary btn-sm" href="<?= esc($baseUrl . '&action=import_admin&_csrf=' . rawurlencode($csrf)) ?>"
           onclick="return confirm('استيراد من admin؟');">استيراد admin</a>
        <a class="btn btn-secondary btn-sm" href="<?= esc($baseUrl . '&action=copy_galaxy&_csrf=' . rawurlencode($csrf)) ?>"
           onclick="return confirm('نسخ من Galaxy؟');">نسخ Galaxy</a>
        <a class="btn btn-primary btn-sm" href="<?= esc($baseUrl . '&action=verify') ?>"
           title="مقارنة Client/Secret بقاعدتي admin/Galaxy">مقارنة محلية</a>
    </div>
</div>

<?php if ($connTest !== null): ?>
    <?php $alertCls = $connTest['level'] === 'success' ? 'alert-success' : 'alert-error'; ?>
    <div class="alert <?= esc($alertCls) ?> einvoice-ora-conn-alert">
        <strong><?= esc($connTest['title']) ?></strong>
        <div><?= esc($connTest['message']) ?></div>
        <div class="einvoice-ora-conn-meta">
            HTTP: <code><?= (int) $connTest['http_code'] ?></code> ·
            URL: <code><?= esc($connTest['url']) ?></code>
        </div>
        <?php if (!empty($connTest['raw'])): ?>
            <details>
                <summary>عرض رد الخادم (للتشخيص)</summary>
                <pre><?= esc((string) $connTest['raw']) ?></pre>
            </details>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= esc($baseUrl) ?>" class="settings-ora-form master-page-form" id="einvoice-settings-form">
    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">معلومات الشركة (البائع)</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">تأكد من صحة البيانات عند الإرسال إلى نظام الفوترة الأردني.</p>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">اسم الشركة *</span>
                    <input class="input" type="text" name="company_name" required value="<?= esc((string) ($settings['company_name'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">الاسم التجاري</span>
                    <input class="input" type="text" name="trade_name" value="<?= esc((string) ($settings['trade_name'] ?? '')) ?>">
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">الرقم الضريبي (VAT) *</span>
                    <input class="input" type="text" name="vat_no" required value="<?= esc((string) ($settings['vat_no'] ?? '')) ?>">
                    <span class="field-hint">بيانات المورد (Supplier Party)</span>
                </label>
                <label class="field">
                    <span class="field-label">رقم GST *</span>
                    <input class="input" type="text" name="gst_no" required value="<?= esc((string) ($settings['gst_no'] ?? '')) ?>">
                    <span class="field-hint">بيانات البائع (Seller Party)</span>
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">البريد الإلكتروني</span>
                    <input class="input" type="email" name="company_email" value="<?= esc((string) ($settings['company_email'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">الهاتف</span>
                    <input class="input" type="text" name="company_phone" value="<?= esc((string) ($settings['company_phone'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">المدينة</span>
                    <input class="input" type="text" name="city" value="<?= esc((string) ($settings['city'] ?? '')) ?>">
                </label>
            </div>
            <label class="field field--full">
                <span class="field-label">العنوان</span>
                <textarea class="input" name="address" rows="2"><?= esc((string) ($settings['address'] ?? '')) ?></textarea>
            </label>
        </div>
    </div>

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">أنواع الفواتير</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">كود الفاتورة عند الإرسال (نقد / آجل).</p>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">نوع الضريبة</span>
                    <select class="input" name="taxes_type">
                        <option value="1" <?= (int) ($settings['taxes_type'] ?? 2) === 1 ? 'selected' : '' ?>>فاتورة دخل</option>
                        <option value="2" <?= (int) ($settings['taxes_type'] ?? 2) === 2 ? 'selected' : '' ?>>فاتورة مبيعات (ضريبة)</option>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">كود فاتورة نقدية *</span>
                    <select class="input" name="invoice_cash" required>
                        <?php foreach ($cashOpts as $val => $lab): ?>
                            <?php $valStr = (string) $val; ?>
                            <option value="<?= esc($valStr) ?>" <?= $selCash === $valStr ? 'selected' : '' ?>><?= esc($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">كود فاتورة آجلة *</span>
                    <select class="input" name="invoice_debit" required>
                        <?php foreach ($debitOpts as $val => $lab): ?>
                            <?php $valStr = (string) $val; ?>
                            <option value="<?= esc($valStr) ?>" <?= $selDebit === $valStr ? 'selected' : '' ?>><?= esc($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
    </div>

    <div class="settings-ora-panel">
        <h2 class="settings-ora-panel-head">الربط مع نظام الفوترة الأردني</h2>
        <div class="settings-ora-panel-body">
            <p class="field-hint" style="margin:0 0 0.55rem;">
                بيانات الاعتماد من بوابة الفوترة. إعدادات الاتصال بقواعد admin/Galaxy في <code>config/einvoice.php</code>
            </p>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">Client ID *</span>
                    <input class="input" type="text" name="client_id" required value="<?= esc((string) ($settings['client_id'] ?? '')) ?>">
                    <span class="field-hint">من بوابة الفوترة الأردنية</span>
                </label>
                <label class="field">
                    <span class="field-label">Secret Key *</span>
                    <input class="input" type="text" name="secret_key" required value="<?= esc((string) ($settings['secret_key'] ?? '')) ?>">
                    <span class="field-hint">من بوابة الفوترة الأردنية</span>
                </label>
                <label class="field">
                    <span class="field-label">بريد المسؤول</span>
                    <input class="input" type="email" name="admin_email" placeholder="example@domain.com"
                           value="<?= esc((string) ($settings['admin_email'] ?? '')) ?>">
                </label>
            </div>
            <label class="field field--full">
                <span class="field-label">رابط API</span>
                <input class="input" type="url" name="jofotara_api_url" value="<?= esc($apiUrlDisplay) ?>" dir="ltr">
            </label>
            <p class="field-hint">POST — JSON + Base64 XML — Headers: Client-Id, Secret-Key</p>
            <label class="field field--full">
                <span class="field-label">ملاحظات داخلية</span>
                <textarea class="input" name="notes" rows="2"><?= esc((string) ($settings['notes'] ?? '')) ?></textarea>
            </label>
        </div>
    </div>

    <div class="settings-ora-actions no-print">
        <button type="submit" class="btn btn-primary">حفظ إعدادات الفوترة</button>
        <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=settings')) ?>">الإعدادات العامة</a>
    </div>
</form>

<script>
document.addEventListener('master-toolbar', function (e) {
  if (e.detail && e.detail.action === 'save') {
    e.preventDefault();
    var f = document.getElementById('einvoice-settings-form');
    if (f) f.requestSubmit ? f.requestSubmit() : f.submit();
  }
});
</script>
