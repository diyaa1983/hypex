<?php
declare(strict_types=1);

require_permission('settings');

require_once app_path('includes/dashboard_accounts.php');
require_once app_path('includes/acc_account_tree.php');

$pdo = db();
acc_account_ensure_schema($pdo);
dashboard_accounts_ensure_schema($pdo);
dashboard_accounts_sync_all($pdo);
dashboard_accounts_seed_defaults_if_empty($pdo);

$listUrl = app_url('index.php?r=dashboard_accounts_settings');
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
$jsUrl = app_url('assets/js/dashboard-accounts-settings.js');
$jsPath = app_path('assets/js/dashboard-accounts-settings.js');
if (is_file($jsPath)) {
    $jsUrl .= '?v=' . (string) filemtime($jsPath);
}
?>
<div class="card" style="max-width:920px;">
    <p class="muted" style="margin:0 0 1rem;">
        حدّد الحسابات التي تظهر في لوحة التحكم (الصندوق والحسابات / المستحقات).
        أي حساب نهائي جديد يُضاف إلى دليل الحسابات يظهر هنا تلقائياً — ضع علامة ✓ لإظهاره في الشاشة الرئيسية.
    </p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= esc($msg) ?></div>
    <?php endif; ?>

    <?php if ($accounts !== []): ?>
        <form id="<?= esc($formId) ?>" method="post" action="<?= esc($listUrl) ?>" class="sr-only" aria-hidden="true">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_all">
            <button type="submit" id="dashboard-accounts-settings-submit">حفظ</button>
        </form>
        <p class="muted" style="margin:0 0 0.75rem;font-size:0.9rem;">
            عدّل الاختيار ثم اضغط <strong>حفظ</strong> في الشريط العلوي.
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table dashboard-accounts-settings-table">
            <thead>
            <tr>
                <th style="width:6rem;text-align:center;">إظهار</th>
                <th style="width:7rem;">الرقم</th>
                <th>اسم الحساب</th>
                <th style="width:8rem;">النوع</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $acc):
                $aid = (int) ($acc['id'] ?? 0);
                $visible = (int) ($acc['is_visible'] ?? 0) === 1;
                $type = (string) ($acc['account_type'] ?? '');
                ?>
                <tr>
                    <td style="text-align:center;">
                        <label class="dashboard-accounts-settings-chk" title="إظهار في الشاشة الرئيسية">
                            <input type="checkbox" form="<?= esc($formId) ?>"
                                   name="visible[<?= $aid ?>]" value="1"
                                <?= $visible ? 'checked' : '' ?>>
                            <span class="sr-only">إظهار <?= esc((string) ($acc['name_ar'] ?? '')) ?></span>
                        </label>
                    </td>
                    <td dir="ltr" style="text-align:end;"><?= esc(acc_account_format_code((string) ($acc['code'] ?? ''))) ?></td>
                    <td><strong><?= esc((string) ($acc['name_ar'] ?? '')) ?></strong></td>
                    <td><?= esc($typeLabels[$type] ?? $type) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($accounts === []): ?>
                <tr>
                    <td colspan="4" class="muted" style="text-align:center;">لا توجد حسابات نهائية نشطة.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="<?= esc($jsUrl) ?>" defer></script>
