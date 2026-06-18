<?php
declare(strict_types=1);

require_once app_path('includes/acc_period_lock.php');

require_permission('acc_period_close');

$pdo = db();
acc_period_ensure_schema($pdo);

$listUrl = app_url('index.php?r=acc_period_close');
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
?>
<div class="dashboard-ora-panel acc-period-panel">
    <h2 class="dashboard-ora-panel__title">إغلاق الأشهر المحاسبية</h2>
    <div class="dashboard-ora-panel__body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <p class="acc-period-hint">
            ضع علامة ✓ في عمود <strong>مغلق</strong> لمنع إدخال أو تعديل فواتير وسندات بتاريخ ذلك الشهر.
            الأشهر السابقة للشهر الحالي تُغلق تلقائياً عند انتهائها — يمكن فتحها يدوياً بإزالة العلامة ثم الحفظ.
        </p>

        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="acc-period-year-form no-print">
            <input type="hidden" name="r" value="acc_period_close">
            <label for="acc_period_year_pick">السنة</label>
            <select name="year" id="acc_period_year_pick" class="input input-compact" onchange="this.form.submit()">
                <?php for ($y = $curY + 1; $y >= $curY - 10; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>

        <form method="post" action="<?= esc($listUrl . '&year=' . $year) ?>" class="acc-period-form master-page-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_periods">
            <input type="hidden" name="period_year" value="<?= (int) $year ?>">

            <div class="table-wrap">
                <table class="data-table acc-period-table">
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
                                    <span class="acc-period-check-ui" aria-hidden="true"></span>
                                </label>
                            </td>
                            <td><?= esc($statusText) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="acc-period-actions no-print">
                <button type="submit" class="btn btn-primary">حفظ</button>
            </div>
        </form>
    </div>
</div>

<style>
.acc-period-panel { max-width: 720px; }
.acc-period-hint {
    margin: 0 0 1rem;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    color: #475569;
    line-height: 1.6;
}
.acc-period-year-form {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.acc-period-table { width: 100%; }
.acc-period-col-lock { width: 90px; text-align: center; }
.acc-period-table tr.is-locked td { color: #b45309; }
.acc-period-table tr.is-open td { color: #15803d; }
.acc-period-table tr.is-current-month { background: #eff6ff; }
.acc-period-badge-current {
    display: inline-block;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 4px;
    background: #dbeafe;
    color: #1d4ed8;
    font-weight: 700;
}
.acc-period-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.acc-period-check input {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.acc-period-actions { margin-top: 1rem; }
</style>
