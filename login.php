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
$postedUser = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string) ($_POST['username'] ?? ''));
    $p = (string) ($_POST['password'] ?? '');
    $postedUser = $u;
    try {
        if (!login_recaptcha_verify(null, $_POST['g-recaptcha-response'] ?? null)) {
            $error = t('يرجى تأكيد أنك لست روبوتاً (reCAPTCHA).');
        } elseif ($u === '' || $p === '') {
            $error = t('أدخل اسم المستخدم وكلمة المرور.');
        } elseif (!attempt_login($u, $p)) {
            $error = t('بيانات الدخول غير صحيحة.');
        } else {
            redirect(app_url('index.php'));
        }
    } catch (Throwable $e) {
        $error = t('تعذر تسجيل الدخول حالياً. يرجى التحقق من إعدادات قاعدة البيانات ثم المحاولة مرة أخرى.');
    }
}

$loginSettings = ['company_name_ar' => t('النظام المحاسبي'), 'logo_path' => null];
try {
    require_once app_path('includes/company_settings.php');
    require_once app_path('includes/nav_helpers.php');
    company_settings_ensure_ui_lang_column(db());
    $company = company_settings(db());
    $loginSettings = [
        'company_name_ar' => (string) ($company['company_name_ar'] ?? t('النظام المحاسبي')),
        'logo_path' => $company['logo_path'] ?? null,
    ];
    $loginTabTitle = app_browser_tab_title(t('تسجيل الدخول'), '', (string) ($loginSettings['company_name_ar'] ?? ''));
} catch (Throwable $e) {
    require_once app_path('includes/nav_helpers.php');
    $loginTabTitle = t('تسجيل الدخول');
}

$companyName = trim((string) ($loginSettings['company_name_ar'] ?? ''));
if ($companyName === '' || $companyName === 'اسم الشركة') {
    $companyName = t('النظام المحاسبي');
}
$logoPath = trim((string) ($loginSettings['logo_path'] ?? ''));
$hasLogo = $logoPath !== '' && is_file(app_path($logoPath));

ob_start();
?>
<!DOCTYPE html>
<html lang="<?= esc(app_lang()) ?>" dir="<?= esc(app_dir()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($loginTabTitle) ?></title>
    <?php render_app_favicon_links($loginSettings); ?>
    <?php
    require_once app_path('includes/app_pwa.php');
    render_app_pwa_head($loginSettings);
    ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app.css')) ?><?= is_file(app_path('assets/css/app.css')) ? '?v=' . filemtime(app_path('assets/css/app.css')) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/login-pro.css')) ?><?= is_file(app_path('assets/css/login-pro.css')) ? '?v=' . filemtime(app_path('assets/css/login-pro.css')) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/ui-lang-ltr.css')) ?><?= is_file(app_path('assets/css/ui-lang-ltr.css')) ? '?v=' . filemtime(app_path('assets/css/ui-lang-ltr.css')) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/ui-dialog.css')) ?>">
    <?= login_recaptcha_script_tag(null) ?>
    <?php render_i18n_js(); ?>
</head>
<body class="login-body login-body--pro">
<div class="login-shell">
    <aside class="login-hero" aria-hidden="false">
        <div class="login-hero__glow" aria-hidden="true"></div>
        <div class="login-hero__content">
            <div class="login-hero__brand">
                <?php if ($hasLogo): ?>
                    <img class="login-hero__logo" src="<?= esc(app_url($logoPath)) ?>" alt="">
                <?php else: ?>
                    <span class="login-hero__mark" aria-hidden="true">ERP</span>
                <?php endif; ?>
                <h1 class="login-hero__title"><?= esc($companyName) ?></h1>
            </div>
            <p class="login-hero__tagline"><?= te('منصة أعمال متكاملة لإدارة العمليات والمالية والمخزون والموارد البشرية.') ?></p>
            <ul class="login-hero__points">
                <li><?= te('لوحة مؤشرات فورية') ?></li>
                <li><?= te('محاسبة ومبيعات ومشتريات') ?></li>
                <li><?= te('صلاحيات آمنة ومتعددة المستخدمين') ?></li>
            </ul>
        </div>
    </aside>

    <main class="login-panel">
        <div class="login-panel__card">
            <header class="login-panel__header">
                <p class="login-panel__eyebrow"><?= te('مرحباً بعودتك') ?></p>
                <h2 class="login-panel__title"><?= te('تسجيل الدخول') ?></h2>
                <p class="login-panel__sub"><?= te('أدخل بياناتك للوصول إلى النظام') ?></p>
            </header>

            <?php if ($error !== ''): ?>
                <div class="login-alert" role="alert"><?= esc($error) ?></div>
            <?php endif; ?>

            <form method="post" class="login-form" autocomplete="on">
                <label class="login-field">
                    <span class="login-field__label"><?= te('اسم المستخدم') ?></span>
                    <input class="login-field__input" name="username" value="<?= esc($postedUser) ?>" autocomplete="username" required autofocus>
                </label>
                <label class="login-field">
                    <span class="login-field__label"><?= te('كلمة المرور') ?></span>
                    <input class="login-field__input" name="password" type="password" autocomplete="current-password" required>
                </label>
                <?php login_recaptcha_render_widget(null); ?>
                <button class="login-submit" type="submit"><?= te('دخول') ?></button>
            </form>
            <p class="login-panel__forgot">
                <a href="<?= esc(app_url('forgot_password.php')) ?>"><?= te('نسيت كلمة المرور؟') ?></a>
            </p>
            <div class="login-panel__pwa">
                <?php render_app_pwa_install_banner(); ?>
            </div>
        </div>
        <p class="login-panel__foot"><?= esc($companyName) ?></p>
    </main>
</div>
<script src="<?= esc(app_url('assets/js/ui-dialog.js')) ?>"></script>
</body>
</html>
<?php
echo i18n_translate_blob(ob_get_clean());
