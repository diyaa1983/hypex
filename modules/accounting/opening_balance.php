<?php
declare(strict_types=1);

require_once app_path('includes/acc_opening_balance.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/nav_helpers.php');

require_permission('acc_opening_balance');

function acc_opening_balance_enqueue_oracle_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $dashPath = app_path('assets/css/dashboard.css');
    $dashUrl = app_url('assets/css/dashboard.css');
    if (is_file($dashPath)) {
        $dashUrl .= '?v=' . (string) filemtime($dashPath);
    }

    $cssPath = app_path('assets/css/acc-opening-balance.css');
    $cssUrl = app_url('assets/css/acc-opening-balance.css');
    if (is_file($cssPath)) {
        $cssUrl .= '?v=' . (string) filemtime($cssPath);
    }

    $jsPath = app_path('assets/js/acc-opening-balance.js');
    $jsUrl = app_url('assets/js/acc-opening-balance.js');
    if (is_file($jsPath)) {
        $jsUrl .= '?v=' . (string) filemtime($jsPath);
    }

    echo '<link rel="stylesheet" href="' . esc($dashUrl) . '">' . "\n";
    echo '<link rel="stylesheet" href="' . esc($cssUrl) . '">' . "\n";
    echo '<script src="' . esc($jsUrl) . '" defer></script>' . "\n";
}

$pdo = db();
acc_gl_ensure_schema($pdo);
acc_journal_ensure_schema($pdo);

require_once app_path('includes/sql_migration.php');
try {
    sql_migration_run_file($pdo, 'database/migrations/218_acc_opening_balance.sql');
} catch (Throwable $e) {
    // الشاشة تُنشأ عند أول زيارة
}

$listUrl = app_url('index.php?r=acc_opening_balance');
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$entryDateRaw = trim((string) ($_GET['entry_date'] ?? ''));
$entryDateIso = parse_date_to_iso($entryDateRaw) ?? acc_opening_balance_default_date($year);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl . '&year=' . $year);
    }

    $action = (string) ($_POST['_action'] ?? '');
    if ($action === 'save_opening') {
        $postYear = (int) ($_POST['fiscal_year'] ?? $year);
        $postDate = trim((string) ($_POST['entry_date'] ?? ''));
        $amounts = $_POST['amounts'] ?? [];
        if (!is_array($amounts)) {
            $amounts = [];
        }
        $uid = (int) (current_user()['id'] ?? 0) ?: null;

        try {
            $result = acc_opening_balance_save_and_post($pdo, $postYear, $postDate, $amounts, $uid);
            $msg = 'تم حفظ وترحيل الأرصدة الافتتاحية لسنة ' . $postYear . '.';
            if ($result['replaced']) {
                $msg .= ' (استبدال القيد السابق)';
            }
            $msg .= ' قيد #' . $result['journal_id'] . '.';
            flash_set('success', $msg);
            redirect($listUrl . '&year=' . $postYear . '&entry_date=' . rawurlencode(format_date_dmY(parse_date_to_iso($postDate) ?? acc_opening_balance_default_date($postYear))));
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'تعذر الحفظ.');
            redirect($listUrl . '&year=' . $postYear);
        }
    }

    if ($action === 'unpost_opening') {
        $postYear = (int) ($_POST['fiscal_year'] ?? $year);
        $uid = (int) (current_user()['id'] ?? 0) ?: null;
        try {
            acc_opening_balance_unpost_execute($pdo, $postYear, $uid);
            flash_set('success', 'تم فك ترحيل الأرصدة الافتتاحية لسنة ' . $postYear . '. يمكنك التعديل وإعادة الحفظ.');
            redirect($listUrl . '&year=' . $postYear);
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'تعذر فك الترحيل.');
            redirect($listUrl . '&year=' . $postYear);
        }
    }

    redirect($listUrl . '&year=' . $year);
}

$grid = acc_opening_balance_grid($pdo, $year);
$status = acc_opening_balance_status($pdo, $year);
if ($status['entry_date'] !== '' && $entryDateRaw === '') {
    $entryDateIso = $status['entry_date'];
}
$entryDateDisplay = format_date_dmY($entryDateIso);

$postedPreview = [];
foreach ($grid as $row) {
    if ((float) $row['debit'] > 0 || (float) $row['credit'] > 0) {
        $postedPreview[(int) $row['account_id']] = [
            'debit' => (string) $row['debit'],
            'credit' => (string) $row['credit'],
        ];
    }
}
$preflight = acc_opening_balance_preflight($pdo, $year, $entryDateIso, $postedPreview);
$isPosted = acc_opening_balance_is_posted($pdo, $year);

$flash = flash_get();
$activeRoute = (string) ($GLOBALS['activeRoute'] ?? 'acc_opening_balance');
$exitUrl = nav_exit_url('acc_opening_balance');
$curY = (int) date('Y');
$formId = 'acc-opening-balance-form';

acc_opening_balance_enqueue_oracle_assets();
?>
<div class="dashboard-ora acc-opening-balance-ora acc-opening-balance-ora-screen"
     data-exit-guard-root
     data-exit-url="<?= esc($exitUrl) ?>"
     data-is-posted="<?= $isPosted ? '1' : '0' ?>">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">الأرصدة الافتتاحية</h1>
        <span class="dashboard-ora-screen-title__meta">سنة <?= (int) $year ?></span>
        <?php nav_render_screen_close($activeRoute, $exitUrl); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <div class="dashboard-ora-toolbar no-print">
            <button type="submit" form="<?= esc($formId) ?>" class="dashboard-ora-btn dashboard-ora-btn--save" id="acc-opening-btn-save">حفظ وترحيل</button>
            <?php if ($isPosted): ?>
                <button type="button" class="dashboard-ora-btn dashboard-ora-btn--unpost" id="acc-opening-btn-unpost">فك الترحيل</button>
            <?php endif; ?>
            <?php if (user_can('chart_of_accounts')): ?>
                <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=chart_of_accounts')) ?>">شجرة الحسابات</a>
            <?php endif; ?>
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="acc-opening-year-form no-exit-guard">
                <input type="hidden" name="r" value="acc_opening_balance">
                <label class="acc-opening-field-label" for="acc_opening_year_pick">السنة</label>
                <select name="year" id="acc_opening_year_pick" class="input input-compact" onchange="this.form.submit()">
                    <?php for ($y = $curY + 1; $y >= $curY - 8; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>

        <p class="acc-opening-toolbar-hint muted no-print">
            أدخل المبالغ في عمود <strong>مدين</strong> أو <strong>دائن</strong> ثم اضغط <strong>حفظ وترحيل</strong> في الشريط.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> acc-opening-balance-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <section class="dashboard-ora-panel" aria-label="إرشادات">
            <h2 class="dashboard-ora-panel__title">إرشادات</h2>
            <div class="dashboard-ora-panel__body acc-opening-intro">
                <p>
                    أدخل <strong>رصيد افتتاحي</strong> لكل حساب نهائي في الشجرة — مناسب لبدء نظام جديد أو نقل أرصدة من نظام سابق.
                    يُنشأ <strong>قيد افتتاحي واحد</strong> بتاريخ بداية السنة.
                </p>
                <p class="acc-opening-intro-steps">
                    <span>① اختر السنة</span>
                    <span>→</span>
                    <span>② أدخل الأرصدة</span>
                    <span>→</span>
                    <span>③ حفظ وترحيل</span>
                    <?php if ($isPosted): ?>
                        <span>— أو —</span>
                        <span>فك الترحيل</span> للتعديل
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <?php if ((int) $status['journal_id'] > 0): ?>
            <section class="dashboard-ora-panel acc-opening-panel--status" aria-label="القيد الحالي">
                <h2 class="dashboard-ora-panel__title">القيد الافتتاحي المرحّل — <?= (int) $year ?></h2>
                <div class="dashboard-ora-panel__body acc-opening-status">
                    <span>القيد: <a href="<?= esc(acc_report_journal_voucher_url((int) $status['journal_id'])) ?>"><?= esc($status['entry_no'] !== '' ? $status['entry_no'] : ('#' . (int) $status['journal_id'])) ?></a></span>
                    <span>التاريخ: <strong><?= esc(format_date_dmY((string) $status['entry_date'])) ?></strong></span>
                    <span>أسطر: <strong><?= (int) $status['line_count'] ?></strong></span>
                    <span class="muted">الحفظ مجدداً يستبدل القيد السابق. فك الترحيل يحذف القيد لتعديل الأرصدة.</span>
                </div>
            </section>
        <?php endif; ?>

        <form method="post"
              action="<?= esc($listUrl . '&year=' . $year) ?>"
              class="acc-opening-form master-page-form"
              id="<?= esc($formId) ?>">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_opening">
            <input type="hidden" name="fiscal_year" value="<?= (int) $year ?>">

            <section class="dashboard-ora-panel" aria-label="إعدادات">
                <h2 class="dashboard-ora-panel__title">إعدادات الافتتاح — <?= (int) $year ?></h2>
                <div class="dashboard-ora-panel__body">
                    <div class="acc-opening-filters">
                        <label class="field">
                            <span class="field-label">تاريخ الافتتاح</span>
                            <input type="text" name="entry_date" class="input js-date-dmy" value="<?= esc($entryDateDisplay) ?>" placeholder="01-01-<?= (int) $year ?>" dir="ltr" autocomplete="off" required>
                        </label>
                        <label class="field acc-opening-search-field">
                            <span class="field-label">بحث حساب</span>
                            <input type="search" id="acc_opening_account_filter" class="input" placeholder="كود أو اسم الحساب…" autocomplete="off">
                        </label>
                    </div>
                </div>
            </section>

            <section class="dashboard-ora-panel" aria-label="جدول الأرصدة">
                <h2 class="dashboard-ora-panel__title">جدول الأرصدة الافتتاحية</h2>
                <p class="dashboard-ora-panel__sub">
                    <?= count($grid) ?> حساب — <?= (int) $preflight['line_count'] ?> برصيد — فرق:
                    <strong id="acc-opening-diff-inline" class="<?= abs((float) ($preflight['totals']['diff'] ?? 0)) < 0.000001 ? 'is-balanced' : 'is-unbalanced' ?>"><?= esc(format_money(abs((float) ($preflight['totals']['diff'] ?? 0)))) ?></strong>
                </p>
                <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                    <div class="dashboard-ora-table-wrap acc-opening-table-wrap">
                        <table class="dashboard-ora-table acc-opening-table" id="acc-opening-table">
                            <thead>
                            <tr>
                                <th>الكود</th>
                                <th>الحساب</th>
                                <th>النوع</th>
                                <th class="col-money">مدين</th>
                                <th class="col-money">دائن</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($grid as $row): ?>
                                <?php
                                $aid = (int) $row['account_id'];
                                $hasVal = (float) $row['debit'] > 0 || (float) $row['credit'] > 0;
                                $typeKey = (string) ($row['account_type'] ?? '');
                                $typeTagClass = in_array($typeKey, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)
                                    ? ' acc-opening-tag--' . $typeKey
                                    : '';
                                ?>
                                <tr class="<?= $hasVal ? 'has-value' : '' ?>" data-search="<?= esc(mb_strtolower($row['code'] . ' ' . $row['name_ar'])) ?>">
                                    <td><code><?= esc($row['code']) ?></code></td>
                                    <td><?= esc($row['name_ar']) ?></td>
                                    <td><span class="acc-opening-tag<?= esc($typeTagClass) ?>"><?= esc(acc_opening_balance_account_type_label($typeKey)) ?></span></td>
                                    <td class="col-money">
                                        <input type="text"
                                               name="amounts[<?= $aid ?>][debit]"
                                               class="acc-opening-amount acc-opening-debit"
                                               inputmode="decimal"
                                               value="<?= (float) $row['debit'] > 0 ? esc(format_amount((float) $row['debit'], null, false)) : '' ?>"
                                               placeholder="0">
                                    </td>
                                    <td class="col-money">
                                        <input type="text"
                                               name="amounts[<?= $aid ?>][credit]"
                                               class="acc-opening-amount acc-opening-credit"
                                               inputmode="decimal"
                                               value="<?= (float) $row['credit'] > 0 ? esc(format_amount((float) $row['credit'], null, false)) : '' ?>"
                                               placeholder="0">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                            <tr class="acc-opening-totals-row">
                                <td colspan="3"><strong>المجموع</strong></td>
                                <td class="col-money"><strong id="acc-opening-total-debit"><?= esc(format_money((float) ($preflight['totals']['debit'] ?? 0))) ?></strong></td>
                                <td class="col-money"><strong id="acc-opening-total-credit"><?= esc(format_money((float) ($preflight['totals']['credit'] ?? 0))) ?></strong></td>
                            </tr>
                            <tr class="acc-opening-diff-row">
                                <td colspan="3">الفرق (يجب = 0)</td>
                                <td colspan="2" class="col-money">
                                    <strong id="acc-opening-diff" class="<?= abs((float) ($preflight['totals']['diff'] ?? 0)) < 0.000001 ? 'is-balanced' : 'is-unbalanced' ?>">
                                        <?= esc(format_money(abs((float) ($preflight['totals']['diff'] ?? 0)))) ?>
                                    </strong>
                                </td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </section>
        </form>

        <form method="post"
              action="<?= esc($listUrl . '&year=' . $year) ?>"
              id="acc-opening-unpost-form"
              class="sr-only no-exit-guard"
              aria-hidden="true"
              data-confirm="فك ترحيل الأرصدة الافتتاحية لسنة <?= (int) $year ?>؟&#10;&#10;سيتم حذف القيد الافتتاحي ويمكنك إعادة الإدخال.">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="unpost_opening">
            <input type="hidden" name="fiscal_year" value="<?= (int) $year ?>">
        </form>

        <?php if ($preflight['warnings'] !== []): ?>
            <section class="dashboard-ora-panel acc-opening-panel--warn">
                <h2 class="dashboard-ora-panel__title">تنبيهات</h2>
                <div class="dashboard-ora-panel__body">
                    <ul class="acc-opening-msg-list">
                        <?php foreach ($preflight['warnings'] as $warn): ?>
                            <li><?= esc($warn) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
