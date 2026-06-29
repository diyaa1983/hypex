<?php
declare(strict_types=1);

require_once app_path('includes/fin_incoming_checks_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$suppliers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filters = fin_outgoing_checks_report_parse_filters($_GET);
$supplierId = $filters['supplier_id'];
$checkNoFilter = $filters['check_no'];

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$dateField = (string) ($_GET['date_field'] ?? 'voucher');
$postedFilter = (string) ($_GET['posted'] ?? 'all');

if (!in_array($dateField, ['voucher', 'due'], true)) {
    $dateField = 'voucher';
}
if (!in_array($postedFilter, ['all', 'posted', 'unposted'], true)) {
    $postedFilter = 'all';
}

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$err = '';
$showResult = false;
$sumAmount = 0.0;

$submitted = isset($_GET['from']) || isset($_GET['to']) || isset($_GET['date_field']) || isset($_GET['posted'])
    || isset($_GET['supplier_id']) || isset($_GET['check_no']);

if ($submitted) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;
        $rows = fin_outgoing_checks_report_fetch(
            $pdo,
            $from,
            $to,
            $dateField,
            $postedFilter,
            $supplierId,
            $checkNoFilter
        );
        foreach ($rows as $r) {
            $sumAmount += (float) ($r['check_amount'] ?? 0);
        }
        $showResult = true;
    }
}

$reportTitle = 'تقرير الشيكات الصادرة';
$supplierLabel = fin_outgoing_checks_report_supplier_label($pdo, $supplierId);
$paymentEditBase = app_url('index.php?r=cash_payment&id=');

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_outgoing_checks"';
if ($showResult) {
    $dateFieldLabel = $dateField === 'due' ? 'تاريخ الصرف' : 'تاريخ الشيك';
    $pageDataAttrs .= ' data-export-label="' . esc($supplierLabel . ' — ' . $dateFieldLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}

$dateFieldLabel = $dateField === 'due' ? 'تاريخ الصرف' : 'تاريخ الشيك';
$postedFilterLabel = $postedFilter === 'posted' ? 'مرحّل فقط' : ($postedFilter === 'unposted' ? 'غير مرحّل فقط' : 'الكل');
$checkNoLabel = $checkNoFilter !== '' ? $checkNoFilter : 'الكل';
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters report-checks-filters no-print">
        <input type="hidden" name="r" value="report_outgoing_checks">
        <div class="form-row report-checks-filters-row">
            <label class="field report-checks-field report-checks-field--party">
                <span class="field-label">المورد</span>
                <select class="input" name="supplier_id">
                    <option value="0" <?= $supplierId === 0 ? 'selected' : '' ?>>جميع الموردين</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $supplierId ? 'selected' : '' ?>>
                            <?= esc((string) ($s['name_ar'] ?? '')) ?>
                            <?php if (($s['code'] ?? '') !== ''): ?>
                                (<?= esc((string) $s['code']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field report-checks-field report-checks-field--check-no">
                <span class="field-label">رقم الشيك</span>
                <input class="input" type="text" name="check_no" value="<?= esc($checkNoFilter) ?>"
                       placeholder="بحث" dir="ltr" autocomplete="off">
            </label>
            <label class="field report-checks-field report-checks-field--date-field">
                <span class="field-label">فلترة حسب</span>
                <select class="input" name="date_field">
                    <option value="voucher" <?= $dateField === 'voucher' ? 'selected' : '' ?>>تاريخ الشيك</option>
                    <option value="due" <?= $dateField === 'due' ? 'selected' : '' ?>>تاريخ الصرف</option>
                </select>
            </label>
            <label class="field report-checks-field report-checks-field--posted">
                <span class="field-label">حالة الترحيل</span>
                <select class="input" name="posted">
                    <option value="all" <?= $postedFilter === 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="posted" <?= $postedFilter === 'posted' ? 'selected' : '' ?>>مرحّل</option>
                    <option value="unposted" <?= $postedFilter === 'unposted' ? 'selected' : '' ?>>غير مرحّل</option>
                </select>
            </label>
            <label class="field report-checks-field report-checks-field--date">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field report-checks-field report-checks-field--date">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <div class="report-checks-field report-checks-field--submit">
                <button class="btn btn-primary" type="submit">عرض التقرير</button>
            </div>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>المورد:</strong> <?= esc($supplierLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>رقم الشيك:</strong> <?= esc($checkNoLabel) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>فلترة حسب:</strong> <?= esc($dateFieldLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>حالة الترحيل:</strong> <?= esc($postedFilterLabel) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>عدد الشيكات:</strong> <?= count($rows) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>مجموع القيم:</strong> <?= esc(format_amount($sumAmount)) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-incoming-checks-table js-sortable-report"
                       data-default-sort="check_date"
                       data-default-dir="asc">
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number" title="ترتيب تصاعدي/تنازلي">تسلسل</th>
                        <th class="col-inv-no js-sort-th" data-sort="check_no" data-sort-type="text" title="ترتيب تصاعدي/تنازلي">رقم الشيك</th>
                        <th class="col-customer js-sort-th" data-sort="party_name" data-sort-type="text" title="ترتيب تصاعدي/تنازلي">اسم المورد</th>
                        <th class="col-date js-sort-th" data-sort="check_date" data-sort-type="date" title="ترتيب تصاعدي/تنازلي">تاريخ الشيك</th>
                        <th class="col-date js-sort-th" data-sort="due_date" data-sort-type="date" title="ترتيب تصاعدي/تنازلي">تاريخ الصرف</th>
                        <th class="col-inv-no js-sort-th" data-sort="voucher_no" data-sort-type="text" title="ترتيب تصاعدي/تنازلي">سند الصرف</th>
                        <th class="col-posted js-sort-th" data-sort="posted_label" data-sort-type="text" title="ترتيب تصاعدي/تنازلي">حالة الشيك</th>
                        <th class="col-customer js-sort-th" data-sort="bank_name" data-sort-type="text" title="ترتيب تصاعدي/تنازلي">البنك</th>
                        <th class="col-money js-sort-th" data-sort="check_amount" data-sort-type="number" title="ترتيب تصاعدي/تنازلي">قيمة الشيك</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد شيكات صادرة مطابقة للفلتر المحدد.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $isPosted = !empty($r['is_posted']);
                        $postedLabel = fin_outgoing_checks_report_posted_label($isPosted);
                        $checkNo = trim((string) ($r['check_no'] ?? ''));
                        $bankName = trim((string) ($r['bank_name'] ?? ''));
                        $dueDate = trim((string) ($r['due_date'] ?? ''));
                        $checkDate = trim((string) ($r['check_date'] ?? ''));
                        $editUrl = $paymentEditBase . (int) ($r['voucher_id'] ?? 0);
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-check_no="<?= esc($checkNo !== '' ? $checkNo : '—') ?>"
                            data-sort-party_name="<?= esc((string) ($r['party_name'] ?? '')) ?>"
                            data-sort-check_date="<?= esc($checkDate) ?>"
                            data-sort-due_date="<?= esc($dueDate) ?>"
                            data-sort-posted_label="<?= esc($postedLabel) ?>"
                            data-sort-bank_name="<?= esc($bankName !== '' ? $bankName : '—') ?>"
                            data-sort-voucher_no="<?= esc((string) ($r['voucher_no'] ?? '')) ?>"
                            data-sort-check_amount="<?= esc((string) (float) ($r['check_amount'] ?? 0)) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc($checkNo !== '' ? $checkNo : '—') ?></code>
                            </td>
                            <td class="col-customer">
                                <span class="report-sales-party-name"><?= esc((string) ($r['party_name'] ?? '—')) ?></span>
                            </td>
                            <td class="col-date"><?= $checkDate !== '' ? esc(format_date_dmY($checkDate)) : '—' ?></td>
                            <td class="col-date"><?= $dueDate !== '' ? esc(format_date_dmY($dueDate)) : '—' ?></td>
                            <td class="col-inv-no">
                                <span class="report-incoming-checks-voucher">
                                    <code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code>
                                    <?php if ((int) ($r['voucher_id'] ?? 0) > 0): ?>
                                        <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                           href="<?= esc($editUrl) ?>">عرض</a>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="col-posted">
                                <?php if ($isPosted): ?>
                                    <span class="badge badge-ok">مرحّل</span>
                                <?php else: ?>
                                    <span class="badge badge-warn">غير مرحّل</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-customer">
                                <span class="report-sales-party-name"><?= esc($bankName !== '' ? $bankName : '—') ?></span>
                            </td>
                            <td class="col-money"><?= esc(format_amount((float) ($r['check_amount'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="8">الإجمالي</td>
                        <td class="col-money"><?= esc(format_amount($sumAmount)) ?></td>
                    </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php if ($showResult): ?>
<script src="<?= esc($sortJsUrl) ?>" defer></script>
<?php endif; ?>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
