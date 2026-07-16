<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_bank_transfer_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$postedYears = hr_payroll_ss_report_posted_years($pdo);
$maxPosted = hr_payroll_max_posted_period($pdo);
$banks = hr_payroll_bank_transfer_report_bank_options($pdo);

$payYear = (int) ($_GET['year'] ?? ($maxPosted['year'] ?? (int) date('Y')));
$payMonth = (int) ($_GET['month'] ?? ($maxPosted['month'] ?? (int) date('n')));
$bankId = (int) ($_GET['bank_id'] ?? 0);
$requireAccount = !empty($_GET['require_account']);
$showReport = !empty($_GET['show']);

if ($payYear < 2000) {
    $payYear = (int) date('Y');
}
if ($payMonth < 1 || $payMonth > 12) {
    $payMonth = (int) date('n');
}

$postedMonthOptions = hr_payroll_ss_report_posted_month_options($pdo, $payYear);
if ($postedMonthOptions !== []) {
    $postedMonthIds = array_column($postedMonthOptions, 'month');
    if (!in_array($payMonth, $postedMonthIds, true)) {
        $payMonth = (int) $postedMonthOptions[array_key_last($postedMonthOptions)]['month'];
    }
}

$report = null;
$reportDate = date('Y-m-d');
$monthNotPosted = false;

if ($showReport) {
    if (!hr_payroll_ss_report_month_is_posted($pdo, $payYear, $payMonth)) {
        $monthNotPosted = true;
    } else {
        $report = hr_payroll_bank_transfer_report_build($pdo, $payYear, $payMonth, $bankId, $requireAccount);
    }
}

$reportTitle = 'كشف تحويل الرواتب للبنوك';
$bankLabel = hr_payroll_bank_transfer_report_bank_label($bankId, $banks);
$exportLabel = 'bank-transfer-report-' . $payYear . '-' . str_pad((string) $payMonth, 2, '0', STR_PAD_LEFT);

$cssPath = app_path('assets/css/hr-payroll-bank-transfer-report.css');
$cssUrl = app_url('assets/css/hr-payroll-bank-transfer-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$wmRootCss = document_print_watermark_root_css($pdo);
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php if ($wmRootCss !== ''): ?><style><?= $wmRootCss ?></style><?php endif; ?>
<style><?= document_print_header_css() ?></style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>

<div
    class="card report-sales-page hr-pr-bank-rpt-page"
    data-report-title="<?= esc($reportTitle) ?>"
    data-report-route="hr_payroll_bank_transfer_report"
    data-export-label="<?= esc($exportLabel) ?>"
    data-from-dmy="<?= esc(format_date_dmY($reportDate)) ?>"
>
    <div class="hr-pr-bank-rpt-filters no-print">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-pr-bank-rpt-filters-form">
            <input type="hidden" name="r" value="hr_payroll_bank_transfer_report">
            <input type="hidden" name="show" value="1">

            <label class="field">
                <span class="field-label">البنك</span>
                <select class="input" name="bank_id">
                    <option value="0" <?= $bankId === 0 ? 'selected' : '' ?>>جميع البنوك</option>
                    <option value="-1" <?= $bankId === -1 ? 'selected' : '' ?>>بدون بنك</option>
                    <?php foreach ($banks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $bankId === (int) $b['id'] ? 'selected' : '' ?>>
                            <?= esc(trim((string) ($b['bank_code'] ?? '') . ' — ' . (string) ($b['name_ar'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field-label">السنة</span>
                <select class="input" name="year" required>
                    <?php if ($postedYears === []): ?>
                        <option value="<?= (int) $payYear ?>"><?= (int) $payYear ?></option>
                    <?php else: ?>
                        <?php foreach ($postedYears as $y): ?>
                            <option value="<?= (int) $y ?>" <?= $payYear === (int) $y ? 'selected' : '' ?>>
                                <?= (int) $y ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>

            <label class="field">
                <span class="field-label">الشهر (مرحّل فقط)</span>
                <select class="input" name="month" required<?= $postedMonthOptions === [] ? ' disabled' : '' ?>>
                    <?php if ($postedMonthOptions === []): ?>
                        <option value="">— لا توجد أشهر مرحّلة —</option>
                    <?php else: ?>
                        <?php foreach ($postedMonthOptions as $opt): ?>
                            <option value="<?= (int) $opt['month'] ?>" <?= $payMonth === (int) $opt['month'] ? 'selected' : '' ?>>
                                <?= esc((string) $opt['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>

            <label class="field hr-pr-bank-rpt-check-field">
                <span class="field-label">&nbsp;</span>
                <label class="hr-pr-bank-rpt-check">
                    <input type="checkbox" name="require_account" value="1" <?= $requireAccount ? 'checked' : '' ?>>
                    موظفون لديهم رقم حساب فقط
                </label>
            </label>

            <button type="submit" class="btn btn-primary btn-sm"<?= $postedYears === [] || $postedMonthOptions === [] ? ' disabled' : '' ?>>
                عرض الكشف
            </button>
        </form>
    </div>

    <?php if ($postedYears === []): ?>
        <p class="alert alert-error no-print">لا توجد رواتب مرحّلة بعد — رحّل شهراً من شاشة «قيد الرواتب» أولاً.</p>
    <?php elseif ($showReport && $monthNotPosted): ?>
        <p class="alert alert-error no-print">الشهر المختار غير مرحّل. اختر شهراً من قائمة الأشهر المرحّلة فقط.</p>
    <?php elseif ($showReport && $report !== null): ?>
        <div class="hr-pr-bank-rpt-doc report-sales-result report-sales-print-area doc-print-watermark-scope">
            <?= document_print_watermark_html($pdo) ?>
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong> <?= esc((string) $report['period_label']) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>تاريخ التقرير:</strong> <span dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>البنك:</strong> <?= esc($bankLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد الموظفين:</strong> <?= (int) $report['emp_count'] ?>
                            <?php if ($requireAccount): ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>فلتر:</strong> برقم حساب فقط
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if (!$report['banks']): ?>
                <p class="hr-pr-bank-rpt-empty muted">لا توجد رواتب صافية مطابقة للفترة والفلاتر المحددة.</p>
            <?php else: ?>
                <?php foreach ($report['banks'] as $block): ?>
                    <section class="hr-pr-bank-rpt-block">
                        <?php if ($bankId === 0): ?>
                            <h3 class="hr-pr-bank-rpt-bank-name">
                                البنك: <?= esc((string) $block['bank_name']) ?>
                                <?php if (trim((string) ($block['bank_code'] ?? '')) !== ''): ?>
                                    <span class="hr-pr-bank-rpt-bank-code" dir="ltr">(<?= esc((string) $block['bank_code']) ?>)</span>
                                <?php endif; ?>
                            </h3>
                        <?php endif; ?>
                        <table class="hr-pr-bank-rpt-table report-sales-table">
                            <thead>
                            <tr>
                                <th class="col-seq" dir="ltr">#</th>
                                <th>رقم الموظف</th>
                                <th>اسم الموظف</th>
                                <th>رقم الحساب</th>
                                <th class="col-money">صافي الراتب</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($block['rows'] as $ln): ?>
                                <tr>
                                    <td class="col-seq"><?= (int) ($ln['seq'] ?? 0) ?></td>
                                    <td class="col-emp-code" dir="ltr"><?= esc((string) ($ln['emp_code'] ?? '—')) ?></td>
                                    <td class="col-emp-name"><?= esc((string) ($ln['name_ar'] ?? '')) ?></td>
                                    <td class="col-account" dir="ltr"><?= esc(hr_payroll_bank_transfer_report_account_display((string) ($ln['bank_account'] ?? ''))) ?></td>
                                    <td class="col-money num" dir="ltr"><?= esc(number_format((float) ($ln['net'] ?? 0), 3)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                            <tr class="hr-pr-bank-rpt-sum">
                                <td colspan="4">مجموع البنك (<?= (int) ($block['totals']['count'] ?? 0) ?> موظف)</td>
                                <td class="col-money num" dir="ltr"><?= esc(number_format((float) $block['totals']['net'], 3)) ?></td>
                            </tr>
                            </tfoot>
                        </table>
                    </section>
                <?php endforeach; ?>

                <?php if ($bankId === 0 && count($report['banks']) > 1): ?>
                    <section class="hr-pr-bank-rpt-block hr-pr-bank-rpt-block--grand">
                        <h3 class="hr-pr-bank-rpt-bank-name">الإجمالي العام</h3>
                        <table class="hr-pr-bank-rpt-table hr-pr-bank-rpt-table--grand report-sales-table">
                            <tbody>
                            <tr class="hr-pr-bank-rpt-sum hr-pr-bank-rpt-sum--grand">
                                <td class="col-seq"></td>
                                <td colspan="3">الإجمالي (<?= (int) $report['grand']['count'] ?> موظف)</td>
                                <td class="col-money num" dir="ltr"><?= esc(number_format((float) $report['grand']['net'], 3)) ?></td>
                            </tr>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
