<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_pwa.php');
require_once app_path('includes/sql_migration.php');

try {
    sql_migration_run_file(db(), 'database/migrations/080_mobile_app.sql');
    sql_migration_run_file(db(), 'database/migrations/081_mobile_invoice_list.sql');
    sql_migration_run_file(db(), 'database/migrations/082_mobile_party_receipt.sql');
    sql_migration_run_file(db(), 'database/migrations/083_mobile_receipt_list.sql');
} catch (Throwable $e) {
    // ignore
}

if (is_logged_in() && mobile_is_context() && user_in_mobile_group()) {
    redirect(mobile_url('r=m_home'));
}

$error = '';
$settingsRow = ['company_name_ar' => 'النظام المحاسبي', 'logo_path' => null];
try {
    $st = db()->query('SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1');
    $row = $st->fetch();
    if ($row) {
        $settingsRow = $row;
    }
} catch (Throwable $e) {
    // ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string) ($_POST['username'] ?? ''));
    $p = (string) ($_POST['password'] ?? '');
    if ($u === '' || $p === '') {
        $error = 'أدخل اسم المستخدم وكلمة المرور.';
    } elseif (!mobile_attempt_login($u, $p)) {
        if (is_logged_in()) {
            logout();
        }
        $error = 'بيانات الدخول غير صحيحة، أو حسابك غير مضاف لمجموعة «هاتف».';
    } else {
        redirect(mobile_url('r=m_home'));
    }
}

$cssV = is_file(app_path('assets/mobile/app.css')) ? (string) filemtime(app_path('assets/mobile/app.css')) : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0572ce">
    <title>دخول — تطبيق الهاتف</title>
    <?php render_app_favicon_links($settingsRow); ?>
    <?php render_mobile_pwa_head($settingsRow); ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/mobile/app.css')) ?><?= $cssV !== '' ? '?v=' . esc($cssV) : '' ?>">
</head>
<body class="m-body m-body--login">
<div class="m-login">
    <div class="m-login-card">
        <?php if (!empty($settingsRow['logo_path']) && is_file(app_path((string) $settingsRow['logo_path']))): ?>
            <img class="m-login-logo" src="<?= esc(app_url((string) $settingsRow['logo_path'])) ?>" alt="">
        <?php else: ?>
            <div class="m-login-mark" aria-hidden="true">📱</div>
        <?php endif; ?>
        <h1 class="m-login-title"><?= esc((string) $settingsRow['company_name_ar']) ?></h1>
        <p class="m-login-sub">تطبيق الهاتف — تسجيل الدخول</p>
        <?php if ($error !== ''): ?>
            <div class="m-alert m-alert--error"><?= esc($error) ?></div>
        <?php endif; ?>
        <form method="post" class="m-form">
            <label class="m-field">
                <span class="m-field-label">اسم المستخدم</span>
                <input class="m-input" name="username" autocomplete="username" required>
            </label>
            <label class="m-field">
                <span class="m-field-label">كلمة المرور</span>
                <input class="m-input" name="password" type="password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="m-btn m-btn--primary m-btn--block">دخول</button>
        </form>
        <p class="m-login-hint muted">يجب أن يكون المستخدم ضمن مجموعة <strong>هاتف</strong> في النظام.</p>
    </div>
</div>
<?php
$appLinkJsV = is_file(app_path('assets/mobile/app-server-link.js'))
    ? (string) filemtime(app_path('assets/mobile/app-server-link.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/app-server-link.js')) ?><?= $appLinkJsV !== '' ? '?v=' . esc($appLinkJsV) : '' ?>"></script>
<?php
$browserHintV = is_file(app_path('assets/mobile/app-browser-hint.js'))
    ? (string) filemtime(app_path('assets/mobile/app-browser-hint.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/app-browser-hint.js')) ?><?= $browserHintV !== '' ? '?v=' . esc($browserHintV) : '' ?>"></script>
</body>
</html>
