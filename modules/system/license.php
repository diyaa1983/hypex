<?php
declare(strict_types=1);

require_once app_path('includes/license.php');
require_once app_path('includes/nav_helpers.php');

$pageUrl = app_url('index.php?r=system_license');
$flash = flash_get();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($pageUrl);
    }

    try {
        $result = license_activate($pdo, (string) ($_POST['license_key'] ?? ''));
        flash_set($result['ok'] ? 'success' : 'error', $result['message']);
    } catch (Throwable $e) {
        flash_set('error', 'تعذر حفظ الترخيص: ' . $e->getMessage());
    }
    redirect($pageUrl);
}

$status = license_status($pdo);
$activatePublicUrl = app_url('activate_license.php');
?>
<div class="dashboard-ora">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">ترخيص النظام</h1>
        <?php nav_render_screen_close('system_license'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">بصمة الخادم</h2>
            <div class="dashboard-ora-panel__body">
                <label class="field">
                    <span class="field-label">Fingerprint</span>
                    <input class="input" type="text" readonly dir="ltr"
                           value="<?= esc((string) ($status['fingerprint_display'] ?? '')) ?>">
                </label>
                <p class="muted" style="margin:0;">
                    أرسل هذه البصمة لمزوّد النظام لإصدار رقم تفعيل مطابق لهذا الخادم.
                </p>
            </div>
        </section>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">حالة الترخيص</h2>
            <div class="dashboard-ora-panel__body">
                <div class="alert alert-<?= !empty($status['valid']) ? 'success' : 'error' ?>" style="margin-bottom:1rem;">
                    <?= esc((string) ($status['message'] ?? '')) ?>
                </div>
                <div class="muted" style="line-height:1.9;">
                    <div><strong>الإلزام:</strong> <?= !empty($status['enforced']) ? 'مفعّل' : 'غير مفعّل' ?></div>
                    <div><strong>رقم التفعيل المحفوظ:</strong> <code dir="ltr"><?= esc((string) ($status['license_key_masked'] ?? '—')) ?></code></div>
                    <?php if ((string) ($status['issued_to'] ?? '') !== ''): ?>
                        <div><strong>مرخّص إلى:</strong> <?= esc((string) $status['issued_to']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($status['expires_on'])): ?>
                        <div><strong>تاريخ الانتهاء:</strong> <span dir="ltr"><?= esc((string) $status['expires_on']) ?></span></div>
                    <?php else: ?>
                        <div><strong>تاريخ الانتهاء:</strong> غير محدد</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">تحديث رقم التفعيل</h2>
            <div class="dashboard-ora-panel__body">
                <?php if (!empty($status['enforced'])): ?>
                    <form method="post" class="form-grid" action="<?= esc($pageUrl) ?>">
                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                        <label class="field">
                            <span class="field-label">رقم التفعيل الجديد</span>
                            <textarea class="input" name="license_key" rows="4" dir="ltr" required
                                      placeholder="LIC1.xxxxx.yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"></textarea>
                        </label>
                        <button type="submit" class="btn btn-primary">حفظ رقم التفعيل</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-error">
                        تفعيل الترخيص غير مفعل حالياً. فعّل <code>APP_LICENSE_ENFORCE</code> من
                        <code>config/app.local.php</code> أولاً.
                    </div>
                <?php endif; ?>
                <p class="muted" style="margin:1rem 0 0;">
                    يمكن فتح شاشة التفعيل العامة من الرابط:
                    <a href="<?= esc($activatePublicUrl) ?>" target="_blank" rel="noopener"><?= esc($activatePublicUrl) ?></a>
                </p>
            </div>
        </section>
    </div>
</div>
