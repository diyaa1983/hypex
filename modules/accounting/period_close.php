<?php
declare(strict_types=1);

require_once app_path('includes/acc_period_lock.php');
require_once app_path('includes/nav_helpers.php');

require_permission('acc_period_close');

$pdo = db();
acc_period_ensure_schema($pdo);

$listUrl = app_url('index.php?r=acc_period_close');
$formId = 'acc-period-close-form';
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl . '&year=' . $year);
    }
    $action = (string) ($_POST['_action'] ?? '');
    if ($action === 'save_periods') {
        $postYear = (int) ($_POST['period_year'] ?? $year);
        $lockedRaw = $_POST['locked'] ?? [];
        if (!is_array($lockedRaw)) {
            $lockedRaw = [];
        }
        $locks = [];
        for ($m = 1; $m <= 12; $m++) {
            $locks[$m] = !empty($lockedRaw[$m]) ? 1 : 0;
        }
        try {
            $uid = (int) (current_user()['id'] ?? 0) ?: null;
            acc_period_save_year_locks($pdo, $postYear, $locks, $uid);
            flash_set('success', 'تم حفظ حالة إغلاق الأشهر لعام ' . $postYear . '.');
            redirect($listUrl . '&year=' . $postYear);
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'تعذر الحفظ.');
            redirect($listUrl . '&year=' . $postYear);
        }
    }
    redirect($listUrl . '&year=' . $year);
}

$months = acc_period_months_for_year($pdo, $year);
$flash = flash_get();
$now = new DateTimeImmutable('today');
$curY = (int) $now->format('Y');
$curM = (int) $now->format('n');
$exitUrl = nav_exit_url('acc_period_close');

$cssPath = app_path('assets/css/acc-period-close.css');
$cssUrl = app_url('assets/css/acc-period-close.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$jsPath = app_path('assets/js/acc-period-close.js');
$jsUrl = app_url('assets/js/acc-period-close.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="acc-period-close-wrap" data-exit-guard-root data-exit-url="<?= esc($exitUrl) ?>">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> acc-period-close-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar acc-period-toolbar no-print">
        <button type="button" class="dashboard-ora-btn dashboard-ora-btn--save" id="acc-period-btn-save">حفظ</button>
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="acc-period-year-form no-exit-guard">
            <input type="hidden" name="r" value="acc_period_close">
            <label for="acc_period_year_pick">السنة</label>
            <select name="year" id="acc_period_year_pick" class="input input-compact" onchange="this.form.submit()">
                <?php for ($y = $curY + 1; $y >= $curY - 10; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <p class="acc-period-toolbar-hint muted no-print">
        عدّل خانات <strong>مغلق</strong> ثم اضغط <strong>حفظ</strong> في الشريط.
    </p>

    <p class="acc-period-hint">
        ضع علامة ✓ في عمود <strong>مغلق</strong> لمنع إدخال أو تعديل فواتير وسندات بتاريخ ذلك الشهر.
        الأشهر السابقة للشهر الحالي تُغلق تلقائياً عند انتهائها — يمكن فتحها يدوياً بإزالة العلامة ثم الحفظ.
    </p>

    <form method="post"
          action="<?= esc($listUrl . '&year=' . $year) ?>"
          class="acc-period-panel acc-period-form master-page-form"
          id="<?= esc($formId) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_periods">
        <input type="hidden" name="period_year" value="<?= (int) $year ?>">

        <h2 class="acc-period-panel-head">الأشهر المحاسبية — <?= (int) $year ?></h2>
        <div class="acc-period-panel-body">
            <div class="table-wrap">
                <table class="data-table dashboard-ora-table acc-period-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>الشهر</th>
                        <th class="acc-period-col-lock">مغلق</th>
                        <th>الحالة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($months as $m): ?>
                        <?php
                        $isCurrent = $year === $curY && (int) $m['month'] === $curM;
                        $statusText = $m['is_locked'] ? 'مغلق — لا إدخال' : 'مفتوح — يُسمح بالإدخال';
                        if ($isCurrent && !$m['is_locked']) {
                            $statusText = 'الشهر الحالي — مفتوح';
                        } elseif ($m['is_default'] && $m['is_locked'] && acc_period_default_locked($year, (int) $m['month'])) {
                            $statusText = 'مغلق تلقائياً (انتهى الشهر)';
                        }
                        ?>
                        <tr class="<?= $m['is_locked'] ? 'is-locked' : 'is-open' ?><?= $isCurrent ? ' is-current-month' : '' ?>">
                            <td><?= (int) $m['month'] ?></td>
                            <td><?= esc($m['name_ar']) ?><?= $isCurrent ? ' <span class="acc-period-badge-current">الحالي</span>' : '' ?></td>
                            <td class="acc-period-col-lock">
                                <label class="acc-period-check">
                                    <input type="checkbox" name="locked[<?= (int) $m['month'] ?>]" value="1" <?= $m['is_locked'] ? 'checked' : '' ?>>
                                    <span class="sr-only">مغلق</span>
                                </label>
                            </td>
                            <td><?= esc($statusText) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>
<script src="<?= esc($jsUrl) ?>" defer></script>
