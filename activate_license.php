<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once app_path('includes/license.php');

$next = license_safe_next_url($_GET['next'] ?? $_POST['next'] ?? null);
$error = '';
$success = '';

$status = [
    'enforced' => license_is_enforced(),
    'valid' => !license_is_enforced(),
    'message' => license_is_enforced()
        ? 'تعذر قراءة حالة الترخيص حالياً.'
        : 'التحقق من الترخيص غير مفعّل حالياً.',
    'fingerprint_hash' => license_fingerprint_hash(),
    'fingerprint_display' => license_fingerprint_display(),
    'issued_to' => '',
    'expires_on' => null,
    'days_left' => null,
    'license_key_masked' => '—',
];

$pdo = null;
try {
    $pdo = db();
    $status = license_status($pdo);
} catch (Throwable $e) {
    $status['valid'] = false;
    $status['message'] = 'تعذر الاتصال بقاعدة البيانات: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = 'جلسة غير صالحة، أعد المحاولة.';
    } elseif (!$status['enforced']) {
        $success = 'نظام الترخيص غير مفعّل حالياً.';
    } elseif (!$pdo instanceof PDO) {
        $error = 'لا يمكن حفظ الترخيص حالياً بسبب مشكلة اتصال قاعدة البيانات.';
    } else {
        $result = license_activate($pdo, (string) ($_POST['license_key'] ?? ''));
        $status = $result['status'];
        if ($result['ok']) {
            $success = $result['message'];
            if ($next !== '') {
                redirect($next);
            }
        } else {
            $error = $result['message'];
        }
    }
}

$settings = ['company_name_ar' => 'النظام المحاسبي', 'logo_path' => null];
try {
    require_once app_path('includes/nav_helpers.php');
    $st = db()->query('SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1');
    $row = $st->fetch();
    if (is_array($row)) {
        $settings = $row;
    }
    $tabTitle = app_browser_tab_title('تفعيل النظام', '', (string) ($settings['company_name_ar'] ?? ''));
} catch (Throwable $e) {
    require_once app_path('includes/nav_helpers.php');
    $tabTitle = 'تفعيل النظام';
}

$continueUrl = $next !== '' ? $next : app_url('login.php');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($tabTitle) ?></title>
    <?php render_app_favicon_links($settings); ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <div class="brand-mark lg">🔐</div>
        <div>
            <div class="login-title">تفعيل النظام</div>
            <div class="login-sub">ربط الترخيص ببصمة الخادم الحالية</div>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= esc($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= esc($error) ?></div>
    <?php endif; ?>
    <?php if ((string) ($status['message'] ?? '') !== ''): ?>
        <div class="alert alert-<?= !empty($status['valid']) ? 'success' : 'error' ?>">
            <?= esc((string) $status['message']) ?>
        </div>
    <?php endif; ?>

    <div class="field" style="margin-bottom:1rem;">
        <span class="field-label">Fingerprint Hash (بصمة الجهاز)</span>
        <input class="input" type="text" readonly value="<?= esc((string) ($status['fingerprint_hash'] ?? '')) ?>" dir="ltr">
        <p class="muted" style="margin:.35rem 0 0;font-size:.82rem;">
            أرسل هذه البصمة لمزوّد النظام ليولّد لك رقم تفعيل مطابق.
        </p>
    </div>

    <?php if (!empty($status['valid'])): ?>
        <div class="field" style="margin-bottom:1rem;">
            <span class="field-label">حالة الترخيص</span>
            <div class="muted" style="line-height:1.8;">
                <div><strong>الترخيص:</strong> صالح</div>
                    <?php if ((string) ($status['license_no'] ?? '') !== ''): ?>
                        <div><strong>رقم النسخة:</strong> <code dir="ltr"><?= esc((string) $status['license_no']) ?></code></div>
                    <?php endif; ?>
                <?php if ((string) ($status['issued_to'] ?? '') !== ''): ?>
                    <div><strong>مرخّص إلى:</strong> <?= esc((string) $status['issued_to']) ?></div>
                <?php endif; ?>
                    <div><strong>المستخدمون النشطون:</strong> <?= (int) ($status['active_users'] ?? 0) ?></div>
                    <?php if (!empty($status['max_users'])): ?>
                        <div><strong>حد المستخدمين:</strong> <?= (int) $status['max_users'] ?></div>
                    <?php endif; ?>
                <?php if (!empty($status['expires_on'])): ?>
                    <div><strong>ينتهي بتاريخ:</strong> <span dir="ltr"><?= esc((string) $status['expires_on']) ?></span></div>
                <?php else: ?>
                    <div><strong>تاريخ الانتهاء:</strong> غير محدد</div>
                <?php endif; ?>
            </div>
        </div>
        <a class="btn btn-primary btn-block" href="<?= esc($continueUrl) ?>">متابعة إلى النظام</a>
    <?php elseif (!empty($status['enforced'])): ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="next" value="<?= esc($next) ?>">
            <label class="field">
                <span class="field-label">رقم التفعيل</span>
                <textarea class="input" name="license_key" rows="4" dir="ltr" style="resize:vertical;"
                          placeholder="LIC1.xxxxx.yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"
                          required><?= esc((string) ($_POST['license_key'] ?? '')) ?></textarea>
            </label>
            <button type="submit" class="btn btn-primary btn-block">حفظ وتفعيل</button>
        </form>
    <?php else: ?>
        <a class="btn btn-primary btn-block" href="<?= esc(app_url('login.php')) ?>">العودة لتسجيل الدخول</a>
    <?php endif; ?>
</div>
</body>
</html>
