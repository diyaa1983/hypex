<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once app_path('includes/sql_migration.php');
require_once app_path('includes/sys_password_reset.php');
require_once app_path('includes/login_recaptcha.php');

try {
    $pdoBoot = db();
    sql_migration_run_file($pdoBoot, 'database/migrations/146_login_security_password_reset.sql');
    sys_password_reset_ensure_schema($pdoBoot);
} catch (Throwable $e) {
    // لا نُعطّل الصفحة عند تعذّر الاتصال
}

if (is_logged_in()) {
    redirect(app_url('index.php'));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    try {
        if (!login_recaptcha_verify(null, $_POST['g-recaptcha-response'] ?? null)) {
            $error = 'يرجى تأكيد أنك لست روبوتاً (reCAPTCHA).';
        } else {
            $result = sys_password_reset_request(db(), $email);
            if ($result['ok']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } catch (Throwable $e) {
        $error = 'تعذر تنفيذ الطلب حالياً. تحقق من اتصال قاعدة البيانات ثم أعد المحاولة.';
    }
}

$loginSettings = ['company_name_ar' => 'النظام المحاسبي', 'logo_path' => null];
try {
    require_once app_path('includes/nav_helpers.php');
    $st = db()->query('SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1');
    $row = $st->fetch();
    if ($row) {
        $loginSettings = $row;
    }
    $loginTabTitle = app_browser_tab_title('نسيت كلمة المرور', '', (string) ($loginSettings['company_name_ar'] ?? ''));
} catch (Throwable $e) {
    require_once app_path('includes/nav_helpers.php');
    $loginTabTitle = 'نسيت كلمة المرور';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($loginTabTitle) ?></title>
    <?php render_app_favicon_links($loginSettings); ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app.css')) ?>">
    <?= login_recaptcha_script_tag(null) ?>
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <div class="brand-mark lg">N</div>
        <div>
            <div class="login-title">استعادة كلمة المرور</div>
            <div class="login-sub">أدخل البريد الإلكتروني المربوط بحسابك</div>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= esc($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= esc($error) ?></div>
    <?php endif; ?>

    <?php if ($success === ''): ?>
    <form method="post" class="form-grid">
        <label class="field">
            <span class="field-label">البريد الإلكتروني</span>
            <input class="input" name="email" type="email" autocomplete="email" required
                   value="<?= esc((string) ($_POST['email'] ?? '')) ?>"
                   placeholder="user@company.com">
        </label>
        <p class="login-hint">يُرسل الرابط إلى البريد المسجّل في شاشة المستخدمين لنفس الحساب.</p>
        <?php login_recaptcha_render_widget(null); ?>
        <button class="btn btn-primary btn-block" type="submit">إرسال رابط الاستعادة</button>
    </form>
    <?php endif; ?>

    <p class="login-forgot-link">
        <a href="<?= esc(app_url('login.php')) ?>">← العودة لتسجيل الدخول</a>
    </p>
</div>
</body>
</html>
