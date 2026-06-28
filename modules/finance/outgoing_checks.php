<?php
declare(strict_types=1);

require_once app_path('includes/fin_outgoing_check_register.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/document_header.php');

$pdo = db();
fin_outgoing_check_register_ensure_schema($pdo);

$activeRoute = $activeRoute ?? 'fin_outgoing_checks';
$flash = flash_get();
$filters = fin_outgoing_check_register_parse_filters($_GET);

$rows = [];
$err = '';
$sumAmount = 0.0;

if ($filters['date_range_active']) {
    $fromIso = parse_date_to_iso($filters['from']);
    $toIso = parse_date_to_iso($filters['to']);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $filters['from'] = $fromIso;
        $filters['to'] = $toIso;
    }
}

if ($err === '') {
    $rows = fin_outgoing_check_register_fetch($pdo, $filters);
    foreach ($rows as $r) {
        $sumAmount += (float) ($r['check_amount'] ?? 0);
    }
}

$fromDisplay = ($filters['from'] ?? '') !== '' ? format_date_dmY((string) $filters['from']) : '';
$toDisplay = ($filters['to'] ?? '') !== '' ? format_date_dmY((string) $filters['to']) : '';

$finCssPath = app_path('assets/css/fin-checks.css');
$finCssUrl = app_url('assets/css/fin-checks.css') . (is_file($finCssPath) ? '?v=' . (string) filemtime($finCssPath) : '');
$docCssPath = app_path('assets/css/document-header.css');
$docCssUrl = app_url('assets/css/document-header.css') . (is_file($docCssPath) ? '?v=' . (string) filemtime($docCssPath) : '');
$jsPath = app_path('assets/js/fin-outgoing-checks.js');
$jsUrl = app_url('assets/js/fin-outgoing-checks.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$printCheckApi = app_url('api/fin_outgoing_check_print.php');
$printCheckCssPath = app_path('assets/css/fin-outgoing-check-print.css');
$printCheckCssUrl = app_url('assets/css/fin-outgoing-check-print.css')
    . (is_file($printCheckCssPath) ? '?v=' . (string) filemtime($printCheckCssPath) : '');
$printFilterLines = fin_outgoing_check_register_filter_caption_lines($filters, $fromDisplay, $toDisplay);
$exitUrl = nav_exit_url('fin_outgoing_checks');

sales_ora12_enqueue_assets();
sales_inv_oracle12_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($finCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($printCheckCssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page fin-checks-page fin-outgoing-checks-page"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-print-check-api="<?= esc($printCheckApi) ?>"
     data-print-check-css="<?= esc($printCheckCssUrl) ?>">
    <?php sales_ora12_render_title_bar('سجل الشيكات الصادرة', '', $activeRoute); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error sales-ora-flash"><?= esc($err) ?></div>
    <?php endif; ?>

    <p class="sales-ora-info muted">
        يُسجَّل كل شيك صادر تلقائياً عند حفظ <strong>سند صرف</strong> بطريقة دفع «شيك»،
        ويُمنح <strong>رقم تسلسلي</strong> من النظام (مثل 001-2026).
        لإضافة شيك جديد: أنشئ سند صرف واختر طريقة الدفع «شيك».
    </p>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row fin-checks-filters">
            <input type="hidden" name="r" value="fin_outgoing_checks">
            <label class="field">
                <span class="field-label">من (اختياري)</span>
                <input class="input input-compact js-date-dmy" type="text" name="from" value="<?= esc($fromDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى (اختياري)</span>
                <input class="input input-compact js-date-dmy" type="text" name="to" value="<?= esc($toDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">رقم تسلسلي</span>
                <input class="input input-compact" type="search" name="register_no" value="<?= esc($filters['register_no']) ?>"
                       placeholder="001-2026" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">رقم الشيك</span>
                <input class="input input-compact" type="search" name="check_no" value="<?= esc($filters['check_no']) ?>"
                       placeholder="بحث" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">الجهة المصروف لها</span>
                <input class="input input-compact" type="search" name="party_q" value="<?= esc($filters['party_q']) ?>"
                       placeholder="اسم العميل / المورد / الموظف" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">ترحيل السند</span>
                <select class="input input-compact" name="posted">
                    <option value="all"<?= $filters['posted'] === 'all' ? ' selected' : '' ?>>الكل</option>
                    <option value="posted"<?= $filters['posted'] === 'posted' ? ' selected' : '' ?>>مرحّل</option>
                    <option value="unposted"<?= $filters['posted'] === 'unposted' ? ' selected' : '' ?>>غير مرحّل</option>
                </select>
            </label>
            <div class="field fin-checks-filter-actions">
                <span class="field-label">&nbsp;</span>
                <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary">عرض</button>
            </div>
        </form>
    </div>

    <div class="sales-ora-panel card fin-checks-results">
        <div class="fin-outgoing-checks-toolbar no-print">
            <button type="button" class="dashboard-ora-btn" id="fin-outgoing-checks-print-list"
                    title="طباعة الشيكات المعروضة حسب الفلاتر الحالية"<?= $rows === [] ? ' disabled' : '' ?>>
                طباعة القائمة
            </button>
        </div>
        <div class="fin-outgoing-checks-print-area" id="fin-outgoing-checks-print-area">
            <div class="fin-outgoing-checks-print-only">
                <?= document_print_header_html('سجل الشيكات الصادرة', $pdo) ?>
                <div class="doc-print-meta">
                    <table>
                        <?php foreach ($printFilterLines as $line): ?>
                            <tr><td><?= esc($line) ?></td></tr>
                        <?php endforeach; ?>
                        <tr>
                            <td>
                                <strong>عدد الشيكات:</strong> <?= count($rows) ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>الإجمالي:</strong> <?= esc(format_money($sumAmount)) ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <p class="sales-ora-info fin-checks-summary no-print">
            <?php if ($filters['date_range_active']): ?>
                الفترة: <strong><?= esc($fromDisplay) ?></strong> — <strong><?= esc($toDisplay) ?></strong> —
            <?php else: ?>
                <strong>جميع الشيكات الصادرة</strong> —
            <?php endif; ?>
            العدد: <strong><?= count($rows) ?></strong>
            — الإجمالي: <strong><?= esc(format_money($sumAmount)) ?></strong>
        </p>
        <div class="table-wrap">
            <table class="data-table fin-checks-table fin-outgoing-checks-table" id="fin-outgoing-checks-table">
                <thead>
                <tr>
                    <th>رقم تسلسلي</th>
                    <th>رقم الشيك</th>
                    <th>المبلغ</th>
                    <th>الجهة المصروف لها</th>
                    <th>نوع الجهة</th>
                    <th>البنك</th>
                    <th>سند الصرف</th>
                    <th>تاريخ السند</th>
                    <th>الاستحقاق</th>
                    <th>ترحيل السند</th>
                    <th>حالة الشيك</th>
                    <th class="fin-outgoing-checks-col-print no-print">طباعة</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="12" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد شيكات صادرة مطابقة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><code><?= esc((string) ($r['register_no'] ?? '')) ?></code></td>
                            <td class="fin-chk-col-no"><code><?= esc((string) (($r['check_no'] ?? '') !== '' ? $r['check_no'] : '—')) ?></code></td>
                            <td class="fin-chk-col-money"><?= esc(format_money((float) ($r['check_amount'] ?? 0))) ?></td>
                            <td class="fin-chk-col-party fin-chk-col-ellipsis" title="<?= esc((string) ($r['party_name'] ?? '')) ?>">
                                <?= esc((string) ($r['party_name'] ?? '—')) ?>
                            </td>
                            <td><?= esc((string) ($r['party_type_label'] ?? '—')) ?></td>
                            <td class="fin-chk-col-ellipsis" title="<?= esc((string) ($r['bank_name'] ?? '')) ?>">
                                <?= esc((string) (($r['bank_name'] ?? '') !== '' ? $r['bank_name'] : '—')) ?>
                            </td>
                            <td>
                                <?php if (($r['voucher_url'] ?? '') !== ''): ?>
                                    <a class="no-print" href="<?= esc((string) $r['voucher_url']) ?>"><code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code></a>
                                    <code class="fin-outgoing-checks-print-only"><?= esc((string) ($r['voucher_no'] ?? '')) ?></code>
                                <?php else: ?>
                                    <code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code>
                                <?php endif; ?>
                            </td>
                            <td class="fin-chk-col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['voucher_date'] ?? ''))) ?></td>
                            <td class="fin-chk-col-date" dir="ltr">
                                <?= ($r['due_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['due_date'])) : '—' ?>
                            </td>
                            <td>
                                <span class="fin-chk-badge<?= !empty($r['is_posted']) ? ' fin-chk-badge--posted' : '' ?>">
                                    <?= esc((string) ($r['posted_label'] ?? '')) ?>
                                </span>
                            </td>
                            <td><?= esc((string) ($r['lifecycle_label'] ?? 'قيد')) ?></td>
                            <td class="fin-outgoing-checks-col-print no-print">
                                <button type="button"
                                        class="btn btn-secondary btn-sm fin-outgoing-check-print-one"
                                        data-check-id="<?= (int) ($r['check_id'] ?? 0) ?>"
                                        title="طباعة هذا الشيك">طباعة</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>
    </div>

    <?php sales_ora12_workspace_close(); ?>
</div>

<div id="fin-oc-print-overlay" class="sales-inv-print-overlay fin-oc-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel fin-oc-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title" id="fin-oc-print-overlay-title">معاينة الشيك</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-primary btn-sm" id="fin-oc-print-run">طباعة</button>
                <button type="button" class="btn btn-secondary btn-sm" id="fin-oc-print-close">إغلاق</button>
            </div>
        </div>
        <div class="fin-oc-print-preview-body bank-check-page" id="fin-oc-print-preview"></div>
    </div>
</div>

<script src="<?= esc($jsUrl) ?>"></script>
