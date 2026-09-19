<?php
declare(strict_types=1);

require_permission('settings');

require_once app_path('includes/dashboard_accounts.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');

$pdo = db();
acc_account_ensure_schema($pdo);
dashboard_accounts_ensure_schema($pdo);
dashboard_accounts_sync_all($pdo);
dashboard_accounts_seed_defaults_if_empty($pdo);

$listUrl = app_url('index.php?r=dashboard_accounts_settings');
$exitUrl = nav_exit_url($activeRoute ?? 'dashboard_accounts_settings');
$formId = 'dashboard-accounts-settings-form';
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } elseif (($_POST['_action'] ?? '') !== 'save_all') {
        $msg = 'إجراء غير معروف.';
        $msgType = 'error';
    } else {
        $posted = $_POST['visible'] ?? [];
        $visibleIds = [];
        if (is_array($posted)) {
            foreach ($posted as $idStr => $val) {
                if ($val !== null && $val !== '') {
                    $visibleIds[] = (int) $idStr;
                }
            }
        }
        try {
            dashboard_accounts_save_visibility($pdo, $visibleIds);
            $msg = 'تم حفظ حسابات الشاشة الرئيسية.';
            $msgType = 'success';
        } catch (Throwable $e) {
            $msg = 'تعذر الحفظ.';
            $msgType = 'error';
        }
    }
}

$accounts = dashboard_accounts_list($pdo);
$typeLabels = acc_account_type_labels();
$visibleCount = 0;
foreach ($accounts as $accRow) {
    if ((int) ($accRow['is_visible'] ?? 0) === 1) {
        $visibleCount++;
    }
}

$jsUrl = app_url('assets/js/dashboard-accounts-settings.js');
$jsPath = app_path('assets/js/dashboard-accounts-settings.js');
if (is_file($jsPath)) {
    $jsUrl .= '?v=' . (string) filemtime($jsPath);
}
$cssPath = app_path('assets/css/dashboard-accounts-oracle12.css');
$cssUrl = app_url('assets/css/dashboard-accounts-oracle12.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
?>
<?php sales_ora12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen dash-acct-ora12-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">حسابات الشاشة الرئيسية</h1>
        <?php if ($accounts !== []): ?>
            <span class="dashboard-ora-screen-title__meta">
                <?= (int) $visibleCount ?> / <?= count($accounts) ?> ظاهر
            </span>
        <?php endif; ?>
        <?php nav_render_screen_close($activeRoute ?? 'dashboard_accounts_settings'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> dash-acct-ora12-flash no-print">
                <?= esc($msg) ?>
            </div>
        <?php endif; ?>

        <section class="dashboard-ora-panel dash-acct-ora12-panel">
            <h2 class="dashboard-ora-panel__title">اختيار الحسابات للوحة التحكم</h2>
            <p class="dashboard-ora-panel__sub">
                حدّد الحسابات التي تظهر في لوحة التحكم (الصندوق والحسابات / المستحقات).
                أي حساب نهائي جديد يُضاف إلى دليل الحسابات يظهر هنا تلقائياً — ضع علامة ✓ لإظهاره في الشاشة الرئيسية.
                <?php if ($accounts !== []): ?>
                    عدّل الاختيار ثم اضغط <strong>حفظ</strong> في الشريط العلوي.
                <?php endif; ?>
            </p>

            <?php if ($accounts !== []): ?>
                <form id="<?= esc($formId) ?>" method="post" action="<?= esc($listUrl) ?>" class="sr-only" aria-hidden="true">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_all">
                    <button type="submit" id="dashboard-accounts-settings-submit">حفظ</button>
                </form>
            <?php endif; ?>

            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <div class="table-wrap dash-acct-ora12-table-wrap">
                    <table class="data-table dash-acct-ora12-table">
                        <thead>
                        <tr>
                            <th class="dash-acct-ora12-col-show">إظهار</th>
                            <th class="dash-acct-ora12-col-code">الرقم</th>
                            <th>اسم الحساب</th>
                            <th class="dash-acct-ora12-col-type">النوع</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $acc):
                            $aid = (int) ($acc['id'] ?? 0);
                            $visible = (int) ($acc['is_visible'] ?? 0) === 1;
                            $type = (string) ($acc['account_type'] ?? '');
                            ?>
                            <tr>
                                <td class="dash-acct-ora12-col-show">
                                    <label class="dash-acct-ora12-chk" title="إظهار في الشاشة الرئيسية">
                                        <input type="checkbox" form="<?= esc($formId) ?>"
                                               name="visible[<?= $aid ?>]" value="1"
                                            <?= $visible ? 'checked' : '' ?>>
                                        <span class="sr-only">إظهار <?= esc((string) ($acc['name_ar'] ?? '')) ?></span>
                                    </label>
                                </td>
                                <td class="dash-acct-ora12-col-code" dir="ltr">
                                    <code><?= esc(acc_account_format_code((string) ($acc['code'] ?? ''))) ?></code>
                                </td>
                                <td><?= esc((string) ($acc['name_ar'] ?? '')) ?></td>
                                <td class="dash-acct-ora12-col-type"><?= esc($typeLabels[$type] ?? $type) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($accounts === []): ?>
                            <tr>
                                <td colspan="4" class="muted dash-acct-ora12-empty">لا توجد حسابات نهائية نشطة.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
<script src="<?= esc($jsUrl) ?>" defer></script>
