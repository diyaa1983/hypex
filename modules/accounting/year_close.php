<?php
declare(strict_types=1);

require_once app_path('includes/acc_year_close.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/nav_helpers.php');

require_permission('acc_year_close');

function acc_year_close_enqueue_oracle_assets(): void
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

    $oraPath = app_path('assets/css/acc-year-close.css');
    $oraUrl = app_url('assets/css/acc-year-close.css');
    if (is_file($oraPath)) {
        $oraUrl .= '?v=' . (string) filemtime($oraPath);
    }

    echo '<link rel="stylesheet" href="' . esc($dashUrl) . '">' . "\n";
    echo '<link rel="stylesheet" href="' . esc($oraUrl) . '">' . "\n";
}

$pdo = db();
acc_year_close_ensure_schema($pdo);

$listUrl = app_url('index.php?r=acc_year_close');
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
    $postYear = (int) ($_POST['fiscal_year'] ?? $year);
    $uid = (int) (current_user()['id'] ?? 0) ?: null;

    if ($action === 'close_year') {
        try {
            $result = acc_year_close_execute($pdo, $postYear, $uid);
            $msg = 'تم إقفال السنة المالية ' . $postYear . ' وفتح سنة ' . $result['next_year'] . '.';
            if ($result['journal_id'] > 0) {
                $msg .= ' قيد الإقفال: #' . $result['journal_id'] . '.';
            }
            flash_set('success', $msg);
            redirect($listUrl . '&year=' . $result['next_year']);
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'تعذر إقفال السنة.');
            redirect($listUrl . '&year=' . $postYear);
        }
    }

    if ($action === 'register_open') {
        try {
            acc_year_close_register_open_year($pdo, $postYear, $uid);
            flash_set('success', 'تم تسجيل السنة المالية ' . $postYear . ' كمفتوحة.');
            redirect($listUrl . '&year=' . $postYear);
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'تعذر التسجيل.');
            redirect($listUrl . '&year=' . $postYear);
        }
    }

    if ($action === 'reopen_year') {
        try {
            $result = acc_year_close_reopen_execute($pdo, $postYear, $uid);
            $msg = 'تم فتح السنة المالية ' . $postYear . ' — يمكنك التعديل والإدخال من جديد.';
            if ($result['journal_deleted']) {
                $msg .= ' تم حذف قيد الإقفال واستعادة أرصدة الإيرادات والمصروفات.';
            }
            flash_set('success', $msg);
            redirect($listUrl . '&year=' . $postYear);
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'تعذر فتح السنة.');
            redirect($listUrl . '&year=' . $postYear);
        }
    }

    redirect($listUrl . '&year=' . $year);
}

$board = acc_year_close_status_board($pdo, $year);
$preflight = acc_year_close_preflight($pdo, $year);
$flash = flash_get();
$activeRoute = (string) ($GLOBALS['activeRoute'] ?? 'acc_year_close');
$exitUrl = nav_exit_url('acc_year_close');
$curY = (int) date('Y');
$closedCount = 0;
foreach ($board as $bRow) {
    if (($bRow['status'] ?? '') === 'closed') {
        $closedCount++;
    }
}

acc_year_close_enqueue_oracle_assets();
?>
<div class="dashboard-ora acc-year-close-ora" data-exit-guard-root data-exit-url="<?= esc($exitUrl) ?>">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إقفال السنة المالية</h1>
        <span class="dashboard-ora-screen-title__meta">معاينة <?= (int) $year ?></span>
        <?php nav_render_screen_close($activeRoute, $exitUrl); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <div class="dashboard-ora-toolbar no-print">
            <?php if (user_can('acc_period_close')): ?>
                <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=acc_period_close&year=' . $year)) ?>">إغلاق الأشهر</a>
            <?php endif; ?>
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="acc-year-close-year-form no-exit-guard">
                <input type="hidden" name="r" value="acc_year_close">
                <label class="acc-year-close-field-label" for="acc_year_close_pick">معاينة سنة</label>
                <select name="year" id="acc_year_close_pick" class="input input-compact" onchange="this.form.submit()">
                    <?php for ($y = $curY + 1; $y >= $curY - 12; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <section class="dashboard-ora-panel" aria-label="إرشادات الإقفال">
            <h2 class="dashboard-ora-panel__title">إرشادات</h2>
            <div class="dashboard-ora-panel__body acc-year-close-intro">
                <p>
                    <strong>إقفال السنة</strong> يصفّر الإيرادات والمصروفات ويرحّل الصافي إلى <strong>الأرباح المحتجزة</strong>،
                    ثم يغلق أشهر السنة ويفتح السنة التالية.
                </p>
                <p class="acc-year-close-intro-steps">
                    <span>① تسجيل</span>
                    <span>→</span>
                    <span>② إقفال</span>
                    <span>— أو —</span>
                    <span>فتح</span> لعكس الإقفال
                </p>
            </div>
        </section>

        <section class="dashboard-ora-panel" aria-label="حالة السنوات المالية">
            <h2 class="dashboard-ora-panel__title">حالة السنوات المالية</h2>
            <p class="dashboard-ora-panel__sub"><?= count($board) ?> سنة — <?= (int) $closedCount ?> مغلقة</p>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <div class="dashboard-ora-table-wrap">
                    <table class="dashboard-ora-table acc-year-close-table">
                        <thead>
                        <tr>
                            <th>السنة</th>
                            <th>الحالة</th>
                            <th>تاريخ الإقفال</th>
                            <th>قيد الإقفال</th>
                            <th class="acc-year-close-col-actions">إجراء</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($board as $row): ?>
                            <?php
                            $fy = (int) $row['fiscal_year'];
                            $isSelected = $fy === $year;
                            $isCurrentYear = $fy === $curY;
                            $rowClass = match ($row['status']) {
                                'closed' => 'is-closed',
                                'open' => 'is-open',
                                default => 'is-legacy',
                            };
                            if ($isSelected) {
                                $rowClass .= ' is-selected';
                            }
                            if ($isCurrentYear) {
                                $rowClass .= ' is-current-year';
                            }
                            ?>
                            <tr class="<?= esc(trim($rowClass)) ?>">
                                <td>
                                    <strong><?= $fy ?></strong>
                                    <?php if ($isCurrentYear): ?>
                                        <span class="acc-year-close-tag acc-year-close-tag--current">الحالية</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'closed'): ?>
                                        <span class="acc-year-close-tag acc-year-close-tag--closed">مغلقة</span>
                                    <?php elseif ($row['status'] === 'open'): ?>
                                        <span class="acc-year-close-tag acc-year-close-tag--open">مفتوحة</span>
                                    <?php else: ?>
                                        <span class="acc-year-close-tag acc-year-close-tag--legacy">غير مسجّلة</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['closed_at'] ? esc(date('d-m-Y H:i', strtotime((string) $row['closed_at']))) : '—' ?></td>
                                <td>
                                    <?php if ((int) $row['journal_id'] > 0): ?>
                                        <a href="<?= esc(acc_report_journal_voucher_url((int) $row['journal_id'])) ?>">#<?= (int) $row['journal_id'] ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="acc-year-close-col-actions">
                                    <div class="acc-year-close-row-actions">
                                    <?php if ($row['status'] === 'legacy'): ?>
                                        <form method="post" action="<?= esc($listUrl) ?>" class="acc-year-close-inline-form">
                                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="_action" value="register_open">
                                            <input type="hidden" name="fiscal_year" value="<?= $fy ?>">
                                            <button type="submit" class="dashboard-ora-btn">تسجيل</button>
                                        </form>
                                    <?php elseif ($row['status'] === 'open' && $row['can_close']): ?>
                                        <form method="post" action="<?= esc($listUrl) ?>" class="acc-year-close-inline-form"
                                              onsubmit="return confirm('إقفال السنة المالية <?= $fy ?>؟');">
                                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="_action" value="close_year">
                                            <input type="hidden" name="fiscal_year" value="<?= $fy ?>">
                                            <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary">إقفال</button>
                                        </form>
                                    <?php elseif ($row['status'] === 'open' && !$row['can_close']): ?>
                                        <span class="acc-year-close-muted">بعد 31/12</span>
                                    <?php elseif ($row['status'] === 'closed' && $row['can_reopen']): ?>
                                        <form method="post" action="<?= esc($listUrl) ?>" class="acc-year-close-inline-form"
                                              onsubmit="return confirm('فتح السنة المالية <?= $fy ?>؟\n\nسيتم حذف قيد الإقفال وإعادة فتح جميع أشهر السنة للإدخال.');">
                                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="_action" value="reopen_year">
                                            <input type="hidden" name="fiscal_year" value="<?= $fy ?>">
                                            <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--reopen">فتح</button>
                                        </form>
                                    <?php elseif ($row['status'] === 'closed' && !$row['can_reopen']): ?>
                                        <span class="acc-year-close-muted" title="افتح السنوات اللاحقة أولاً">افتح الأحدث أولاً</span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if (!$preflight['ok'] && $preflight['errors'] !== []): ?>
            <section class="dashboard-ora-panel acc-year-close-panel--error" aria-label="أخطاء الإقفال">
                <h2 class="dashboard-ora-panel__title">لا يمكن إقفال <?= (int) $year ?> حالياً</h2>
                <div class="dashboard-ora-panel__body">
                    <ul class="acc-year-close-msg-list">
                        <?php foreach ($preflight['errors'] as $err): ?>
                            <li><?= esc($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($preflight['warnings'] !== []): ?>
            <section class="dashboard-ora-panel acc-year-close-panel--warn" aria-label="تنبيهات">
                <h2 class="dashboard-ora-panel__title">تنبيهات — <?= (int) $year ?></h2>
                <div class="dashboard-ora-panel__body">
                    <ul class="acc-year-close-msg-list">
                        <?php foreach ($preflight['warnings'] as $warn): ?>
                            <li><?= esc($warn) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <?php $preview = $preflight['preview']; ?>
        <?php if ($preview['line_count'] > 0): ?>
            <section class="dashboard-ora-panel" aria-label="معاينة قيد الإقفال">
                <h2 class="dashboard-ora-panel__title">معاينة قيد إقفال <?= (int) $year ?></h2>
                <p class="dashboard-ora-panel__sub">
                    إيرادات: <?= esc(format_money((float) $preview['total_revenue'])) ?>
                    — مصروفات: <?= esc(format_money((float) $preview['total_expenses'])) ?>
                    — صافي الدخل: <?= esc(format_money((float) $preview['net_income'])) ?>
                </p>
                <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                    <div class="dashboard-ora-table-wrap">
                        <table class="dashboard-ora-table acc-year-close-preview-table">
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
                            <?php foreach ($preview['lines'] as $ln): ?>
                                <tr>
                                    <td><code><?= esc($ln['code']) ?></code></td>
                                    <td><?= esc($ln['name_ar']) ?></td>
                                    <td><?= esc($ln['account_type'] === 'revenue' ? 'إيراد' : ($ln['account_type'] === 'expense' ? 'مصروف' : 'حقوق ملكية')) ?></td>
                                    <td class="col-money"><?= (float) $ln['debit'] > 0 ? esc(format_money((float) $ln['debit'])) : '—' ?></td>
                                    <td class="col-money"><?= (float) $ln['credit'] > 0 ? esc(format_money((float) $ln['credit'])) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
