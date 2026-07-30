<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');

require_mobile_permission('m_customer_add');

$pdo = db();
$msg = '';
$msgType = '';
$uid = (int) (current_user()['id'] ?? 0);
$rep = crm_sales_rep_row_for_user($pdo, $uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة.';
        $msgType = 'error';
    } else {
        $result = crm_mobile_customer_create_for_user(
            $pdo,
            $uid,
            (string) ($_POST['name_ar'] ?? ''),
            (string) ($_POST['phone'] ?? ''),
            (string) ($_POST['address_ar'] ?? '')
        );
        $msg = $result['message'];
        $msgType = $result['ok'] ? 'success' : 'error';
    }
}

$nameVal = $msgType === 'error' ? trim((string) ($_POST['name_ar'] ?? '')) : '';
$phoneVal = $msgType === 'error' ? trim((string) ($_POST['phone'] ?? '')) : '';
$addrVal = $msgType === 'error' ? trim((string) ($_POST['address_ar'] ?? '')) : '';
?>
<div class="m-ora12 m-ora12-invoice">
<div class="m-ora12-workspace">
    <?php if ($msg !== ''): ?>
        <div class="m-alert m-alert--<?= $msgType === 'success' ? 'ok' : 'error' ?>"><?= esc($msg) ?></div>
    <?php endif; ?>

    <?php if ($rep === null): ?>
        <div class="m-alert m-alert--error">
            حسابك غير مربوط بمندوب مبيعات. راجع مدير النظام من شاشة المستخدمون.
        </div>
    <?php else: ?>
        <section class="m-ora12-panel">
            <h2 class="m-ora12-panel__title">إضافة عميل</h2>
            <div class="m-ora12-panel__body">
                <p class="m-muted" style="margin:0 0 12px;">
                    سيُربط العميل تلقائياً بالمندوب: <strong><?= esc((string) ($rep['name_ar'] ?? '')) ?></strong>
                </p>
                <form method="post" class="m-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <label class="m-field">
                        <span class="m-field__label">اسم العميل *</span>
                        <input class="m-input" name="name_ar" required maxlength="200"
                               value="<?= esc($nameVal) ?>" autocomplete="organization">
                    </label>
                    <label class="m-field">
                        <span class="m-field__label">رقم التلفون</span>
                        <input class="m-input" name="phone" type="tel" maxlength="40"
                               value="<?= esc($phoneVal) ?>" autocomplete="tel" dir="ltr">
                    </label>
                    <label class="m-field">
                        <span class="m-field__label">العنوان</span>
                        <textarea class="m-input" name="address_ar" rows="3" maxlength="500"><?= esc($addrVal) ?></textarea>
                    </label>
                    <button type="submit" class="m-btn m-btn--primary" style="width:100%;margin-top:8px;">حفظ العميل</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>
</div>
