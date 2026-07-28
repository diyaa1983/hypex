<?php
declare(strict_types=1);

require_once app_path('includes/fin_private_out_check.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_fin_private_out_check_post();
}

$pdo = db();
fin_private_out_check_ensure_schema($pdo);

$activeRoute = $activeRoute ?? 'fin_private_out_checks';
$flash = flash_get();
$csrf = csrf_token();
$exitUrl = nav_exit_url('fin_private_out_checks');
$editId = (int) ($_GET['id'] ?? 0);
$editRow = $editId > 0 ? fin_private_out_check_fetch($pdo, $editId) : null;
$newMode = isset($_GET['new']);

$filters = fin_private_out_check_parse_filters($_GET);
$err = '';
$dateRangeActive = ($filters['from'] !== '' || $filters['to'] !== '');

if ($dateRangeActive) {
    $fromIso = $filters['from'] !== '' ? parse_date_to_iso($filters['from']) : null;
    $toIso = $filters['to'] !== '' ? parse_date_to_iso($filters['to']) : null;
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $filters['from'] = $fromIso;
        $filters['to'] = $toIso;
    }
}

$rows = $err === '' ? fin_private_out_check_list($pdo, $filters) : [];
$sumAmount = 0.0;
foreach ($rows as $r) {
    $sumAmount += (float) ($r['check_amount'] ?? 0);
}

$fromDisplay = ($filters['from'] ?? '') !== '' ? format_date_dmY((string) $filters['from']) : '';
$toDisplay = ($filters['to'] ?? '') !== '' ? format_date_dmY((string) $filters['to']) : '';

require_once app_path('includes/fin_out_check_due_email.php');
$reminderCfg = fin_out_check_due_email_settings($pdo);

$formId = (int) ($editRow['id'] ?? 0);
$formCheckNo = (string) ($editRow['check_no'] ?? '');
$formBank = (string) ($editRow['bank_name'] ?? '');
$formAmount = $editRow ? format_amount((float) ($editRow['check_amount'] ?? 0), null, false) : '';
$formDue = ($editRow['due_date'] ?? '') !== '' ? format_date_dmY((string) $editRow['due_date']) : '';
$formBeneficiary = (string) ($editRow['beneficiary'] ?? '');
$formNotes = (string) ($editRow['notes'] ?? '');
$formEntryNo = (string) ($editRow['entry_no'] ?? '');
$showForm = $newMode || $editRow !== null;

$finCssPath = app_path('assets/css/fin-checks.css');
$finCssUrl = app_url('assets/css/fin-checks.css') . (is_file($finCssPath) ? '?v=' . (string) filemtime($finCssPath) : '');
$jsPath = app_path('assets/js/fin-private-out-checks.js');
$jsUrl = app_url('assets/js/fin-private-out-checks.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');

sales_ora12_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($finCssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page fin-checks-page fin-private-out-checks-page"
     id="fin-private-out-checks-screen">
    <?php sales_ora12_render_title_bar('شيكات خاصة', '', $activeRoute); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div class="alert no-print alert-error sales-ora-flash"><?= esc($err) ?></div>
    <?php endif; ?>

    <p class="sales-ora-info muted fin-checks-intro">
        إدخال <strong>شيكات صادرة للتذكير فقط</strong> — لا تُنشئ سند صرف ولا قيوداً محاسبية ولا تؤثر على العميل أو المورد.
        تُرسل التنبيهات حسب إعدادات <strong>بريد الشيكات الصادرة</strong>
        (<?= $reminderCfg['enabled'] ? 'مفعّل' : 'غير مفعّل' ?>،
        قبل <?= (int) $reminderCfg['days_before'] ?> يوم<?= $reminderCfg['on_due_day'] ? ' + يوم الاستحقاق' : '' ?>).
    </p>

    <div class="sales-ora-panel card fin-private-out-checks-toolbar fin-checks-toolbar no-print">
        <a class="dashboard-ora-btn dashboard-ora-btn--primary"
           href="<?= esc(app_url('index.php?r=fin_private_out_checks&new=1')) ?>">+ شيك جديد</a>
        <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=settings')) ?>">إعدادات التذكير</a>
    </div>

    <?php if ($showForm): ?>
        <div class="sales-ora-panel card fin-private-out-checks-form-wrap">
            <h2 class="fin-private-out-checks-form-title">
                <?= $formId > 0 ? 'تعديل شيك' : 'شيك جديد' ?>
                <?php if ($formEntryNo !== ''): ?>
                    <code class="muted"><?= esc($formEntryNo) ?></code>
                <?php endif; ?>
            </h2>
            <form method="post" action="<?= esc(app_url('index.php?r=fin_private_out_checks')) ?>"
                  class="fin-private-out-checks-form form-row" id="fin-private-out-checks-form">
                <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                <input type="hidden" name="_action" value="save">
                <input type="hidden" name="id" value="<?= $formId ?>">

                <label class="field">
                    <span class="field-label">رقم الشيك</span>
                    <input class="input input-compact" type="text" name="check_no" value="<?= esc($formCheckNo) ?>"
                           dir="ltr" autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">البنك</span>
                    <input class="input input-compact" type="text" name="bank_name" value="<?= esc($formBank) ?>"
                           autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">المبلغ *</span>
                    <input class="input input-compact fin-poc-amount" type="text" name="check_amount"
                           value="<?= esc($formAmount) ?>" dir="ltr" required>
                </label>
                <label class="field">
                    <span class="field-label">تاريخ الاستحقاق *</span>
                    <input class="input input-compact js-date-dmy" type="text" name="due_date"
                           value="<?= esc($formDue) ?>" placeholder="يوم-شهر-سنة" dir="ltr" required autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">المستفيد (اختياري)</span>
                    <input class="input input-compact" type="text" name="beneficiary"
                           value="<?= esc($formBeneficiary) ?>" autocomplete="off">
                </label>
                <label class="field field-span-2">
                    <span class="field-label">ملاحظات</span>
                    <input class="input input-compact" type="text" name="notes" value="<?= esc($formNotes) ?>">
                </label>
                <div class="field fin-private-out-checks-form-actions">
                    <span class="field-label">&nbsp;</span>
                    <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary">حفظ</button>
                    <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=fin_private_out_checks')) ?>">إلغاء</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row fin-checks-filters" id="fin-poc-filters-form">
            <input type="hidden" name="r" value="fin_private_out_checks">
            <label class="field">
                <span class="field-label">الحالة</span>
                <select class="input input-compact" name="status" id="fin-poc-filter-status">
                    <option value="all"<?= $filters['status'] === 'all' ? ' selected' : '' ?>>الكل</option>
                    <option value="pending"<?= $filters['status'] === 'pending' ? ' selected' : '' ?>>قيد التذكير</option>
                    <option value="done"<?= $filters['status'] === 'done' ? ' selected' : '' ?>>منجز</option>
                    <option value="cancelled"<?= $filters['status'] === 'cancelled' ? ' selected' : '' ?>>ملغى</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">استحقاق من</span>
                <input class="input input-compact js-date-dmy" type="text" name="from" value="<?= esc($fromDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى</span>
                <input class="input input-compact js-date-dmy" type="text" name="to" value="<?= esc($toDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">رقم الشيك</span>
                <input class="input input-compact" type="search" name="check_no" value="<?= esc($filters['check_no']) ?>"
                       dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">المستفيد</span>
                <input class="input input-compact" type="search" name="beneficiary"
                       value="<?= esc($filters['beneficiary']) ?>" autocomplete="off">
            </label>
            <div class="field fin-checks-filter-actions">
                <span class="field-label">&nbsp;</span>
                <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary">عرض</button>
            </div>
        </form>
    </div>

    <div class="sales-ora-panel card fin-checks-results">
        <p class="sales-ora-info fin-checks-summary">
            العدد: <strong><?= count($rows) ?></strong>
            — الإجمالي: <strong><?= esc(format_money($sumAmount)) ?></strong>
        </p>
        <div class="table-wrap">
            <table class="data-table fin-checks-table">
                <thead>
                <tr>
                    <th>الرقم</th>
                    <th>رقم الشيك</th>
                    <th>المبلغ</th>
                    <th>البنك</th>
                    <th>المستفيد</th>
                    <th>الاستحقاق</th>
                    <th>الحالة</th>
                    <th>ملاحظات</th>
                    <th class="no-print">إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="9" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد شيكات مطابقة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $rid = (int) ($r['id'] ?? 0);
                        $status = (string) ($r['status'] ?? 'pending');
                        $urgencyClass = '';
                        if ($status === 'pending' && ($r['due_date'] ?? '') !== '') {
                            $today = new DateTimeImmutable('today');
                            try {
                                $dueDt = new DateTimeImmutable((string) $r['due_date']);
                                $diff = (int) $today->diff($dueDt)->format('%r%a');
                                if ($diff < 0) {
                                    $urgencyClass = 'fin-poc-overdue';
                                } elseif ($diff === 0) {
                                    $urgencyClass = 'fin-poc-today';
                                } elseif ($diff <= 7) {
                                    $urgencyClass = 'fin-poc-soon';
                                }
                            } catch (Throwable $e) {
                                // ignore
                            }
                        }
                        ?>
                        <tr class="<?= esc($urgencyClass) ?>">
                            <td><code><?= esc((string) ($r['entry_no'] ?? '')) ?></code></td>
                            <td dir="ltr"><code><?= esc((string) (($r['check_no'] ?? '') !== '' ? $r['check_no'] : '—')) ?></code></td>
                            <td><?= esc(format_money((float) ($r['check_amount'] ?? 0))) ?></td>
                            <td><?= esc((string) (($r['bank_name'] ?? '') !== '' ? $r['bank_name'] : '—')) ?></td>
                            <td><?= esc((string) (($r['beneficiary'] ?? '') !== '' ? $r['beneficiary'] : '—')) ?></td>
                            <td dir="ltr"><?= ($r['due_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['due_date'])) : '—' ?></td>
                            <td><?= esc(fin_private_out_check_status_label($status)) ?></td>
                            <td><?= esc((string) (($r['notes'] ?? '') !== '' ? $r['notes'] : '—')) ?></td>
                            <td class="no-print fin-poc-actions">
                                <?php if ($status === 'pending'): ?>
                                    <a class="dashboard-ora-btn dashboard-ora-btn--sm"
                                       href="<?= esc(app_url('index.php?r=fin_private_out_checks&id=' . $rid)) ?>">تعديل</a>
                                    <form method="post" class="fin-poc-inline-form"
                                          action="<?= esc(app_url('index.php?r=fin_private_out_checks')) ?>"
                                          data-confirm="إيقاف التذكير لهذا الشيك؟">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                        <input type="hidden" name="_action" value="done">
                                        <input type="hidden" name="id" value="<?= $rid ?>">
                                        <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--sm">تم</button>
                                    </form>
                                    <form method="post" class="fin-poc-inline-form"
                                          action="<?= esc(app_url('index.php?r=fin_private_out_checks')) ?>"
                                          data-confirm="حذف هذا الشيك؟ لا يمكن التراجع.">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?= $rid ?>">
                                        <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--sm dashboard-ora-btn--danger">حذف</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php sales_ora12_workspace_close(); ?>
</div>

<style>
.fin-private-out-checks-page .fin-private-out-checks-form-wrap { margin-bottom: 1rem; }
.fin-private-out-checks-page .fin-private-out-checks-form-title { margin: 0 0 1rem; font-size: 1.05rem; }
.fin-private-out-checks-page .fin-private-out-checks-form .field-span-2 { grid-column: span 2; }
.fin-private-out-checks-page .fin-private-out-checks-form-actions { display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; }
.fin-private-out-checks-page .fin-poc-inline-form { display: inline; }
.fin-private-out-checks-page .fin-poc-actions { white-space: nowrap; }
.fin-private-out-checks-page .fin-poc-overdue td { background: #fef2f2; }
.fin-private-out-checks-page .fin-poc-today td { background: #fffbeb; }
.fin-private-out-checks-page .fin-poc-soon td { background: #f0fdf4; }
.fin-private-out-checks-page .fin-private-out-checks-toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
</style>
<script src="<?= esc($jsUrl) ?>" defer></script>
