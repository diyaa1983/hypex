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
    // ignored
}

if (is_logged_in()) {
    redirect(app_url('index.php'));
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$tokenValid = false;
try {
    $tokenValid = $token !== '' && sys_password_reset_find_user_id(db(), $token) > 0;
} catch (Throwable $e) {
    $tokenValid = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    try {
        if (!login_recaptcha_verify(null, $_POST['g-recaptcha-response'] ?? null)) {
            $error = 'يرجى تأكيد أنك لست روبوتاً (reCAPTCHA).';
        } elseif ($password !== $confirm) {
            $error = 'تأكيد كلمة المرور غير متطابق.';
        } else {
            $result = sys_password_reset_complete(db(), $token, $password);
            if ($result['ok']) {
                $success = $result['message'];
                $tokenValid = false;
            } else {
                $error = $result['message'];
            }
        }
    } catch (Throwable $e) {
        $error = 'تعذر حفظ كلمة المرور حالياً. تحقق من اتصال قاعدة البيانات ثم أعد المحاولة.';
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
    $loginTabTitle = app_browser_tab_title('كلمة مرور جديدة', '', (string) ($loginSettings['company_name_ar'] ?? ''));
} catch (Throwable $e) {
    require_once app_path('includes/nav_helpers.php');
    $loginTabTitle = 'كلمة مرور جديدة';
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
            <div class="login-title">تعيين كلمة مرور جديدة</div>
            <div class="login-sub">اختر كلمة مرور قوية (6 أحرف على الأقل)</div>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= esc($success) ?></div>
        <p class="login-forgot-link">
            <a class="btn btn-primary btn-block" href="<?= esc(app_url('login.php')) ?>">تسجيل الدخول</a>
        </p>
    <?php elseif (!$tokenValid && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="alert alert-error">الرابط غير صالح أو منتهي الصلاحية.</div>
        <p class="login-forgot-link">
            <a href="<?= esc(app_url('forgot_password.php')) ?>">طلب رابط جديد</a>
            ·
            <a href="<?= esc(app_url('login.php')) ?>">تسجيل الدخول</a>
        </p>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= esc($error) ?></div>
        <?php endif; ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="token" value="<?= esc($token) ?>">
            <label class="field">
                <span class="field-label">كلمة المرور الجديدة</span>
                <input class="input" name="password" type="password" autocomplete="new-password" required minlength="6">
            </label>
            <label class="field">
                <span class="field-label">تأكيد كلمة المرور</span>
                <input class="input" name="password_confirm" type="password" autocomplete="new-password" required minlength="6">
            </label>
            <?php login_recaptcha_render_widget(null); ?>
            <button class="btn btn-primary btn-block" type="submit">حفظ كلمة المرور</button>
        </form>
        <p class="login-forgot-link">
            <a href="<?= esc(app_url('login.php')) ?>">← العودة لتسجيل الدخول</a>
        </p>
    <?php endif; ?>
</div>
</body>
</html>
