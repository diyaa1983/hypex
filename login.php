<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once app_path('includes/login_recaptcha.php');

try {
    require_once app_path('includes/sql_migration.php');
    require_once app_path('includes/login_security_schema.php');
    sql_migration_run_file(db(), 'database/migrations/018_seed_admin_if_missing.sql');
    sql_migration_run_file(db(), 'database/migrations/146_login_security_password_reset.sql');
    login_security_ensure_schema(db());
} catch (Throwable $e) {
    // لا نُعطّل صفحة الدخول عند تعذّر الاتصال بقاعدة البيانات
}

if (is_logged_in()) {
    redirect(app_url('index.php'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string) ($_POST['username'] ?? ''));
    $p = (string) ($_POST['password'] ?? '');
    try {
        if (!login_recaptcha_verify(null, $_POST['g-recaptcha-response'] ?? null)) {
            $error = 'يرجى تأكيد أنك لست روبوتاً (reCAPTCHA).';
        } elseif ($u === '' || $p === '') {
            $error = 'أدخل اسم المستخدم وكلمة المرور.';
        } elseif (!attempt_login($u, $p)) {
            $loginErr = trim((string) ($_SESSION['_login_last_error'] ?? ''));
            unset($_SESSION['_login_last_error']);
            $error = $loginErr !== '' ? $loginErr : 'بيانات الدخول غير صحيحة.';
        } else {
            redirect(app_url('index.php'));
        }
    } catch (Throwable $e) {
        $error = 'تعذر تسجيل الدخول حالياً. يرجى التحقق من إعدادات قاعدة البيانات ثم المحاولة مرة أخرى.';
    }
}

$loginSettings = ['company_name_ar' => 'النظام المحاسبي', 'logo_path' => null];
try {
    require_once app_path('includes/company_settings.php');
    require_once app_path('includes/nav_helpers.php');
    $st = db()->query('SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1');
    $row = $st->fetch();
    if ($row) {
        $loginSettings = $row;
    }
    $loginTabTitle = app_browser_tab_title('تسجيل الدخول', '', (string) ($loginSettings['company_name_ar'] ?? ''));
} catch (Throwable $e) {
    require_once app_path('includes/nav_helpers.php');
    $loginTabTitle = 'تسجيل الدخول';
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
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/ui-dialog.css')) ?>">
    <?= login_recaptcha_script_tag(null) ?>
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <div class="brand-mark lg">N</div>
        <div>
            <div class="login-title">نظام محاسبة متكامل</div>
            <div class="login-sub">تسجيل الدخول</div>
        </div>
    </div>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= esc($error) ?></div>
    <?php endif; ?>
    <form method="post" class="form-grid">
        <label class="field">
            <span class="field-label">اسم المستخدم</span>
            <input class="input" name="username" autocomplete="username" required>
        </label>
        <label class="field">
            <span class="field-label">كلمة المرور</span>
            <input class="input" name="password" type="password" autocomplete="current-password" required>
        </label>
        <?php login_recaptcha_render_widget(null); ?>
        <button class="btn btn-primary btn-block" type="submit">دخول</button>
    </form>
    <p class="login-forgot-link">
        <a href="<?= esc(app_url('forgot_password.php')) ?>">نسيت كلمة المرور؟</a>
    </p>
</div>
<script src="<?= esc(app_url('assets/js/ui-dialog.js')) ?>"></script>
</body>
</html>
