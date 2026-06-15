<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_tax_ar3_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$postedYears = hr_payroll_tax_ar3_posted_years($pdo);
$maxPosted = hr_payroll_max_posted_period($pdo);

$payYear = (int) ($_GET['year'] ?? ($maxPosted['year'] ?? (int) date('Y')));
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$showReport = !empty($_GET['show']);

if ($payYear < 2000) {
    $payYear = (int) date('Y');
}

$employees = hr_payroll_tax_ar3_employees_for_year($pdo, $payYear);
if ($employees !== [] && $employeeId > 0) {
    $empIds = array_column($employees, 'id');
    if (!in_array($employeeId, $empIds, true)) {
        $employeeId = (int) ($employees[0]['id'] ?? 0);
    }
} elseif ($employees !== [] && $employeeId < 1) {
    $employeeId = (int) ($employees[0]['id'] ?? 0);
}

$report = null;
$yearNotPosted = false;
$errorMsg = '';

if ($showReport) {
    if ($employeeId < 1) {
        $errorMsg = 'اختر الموظف.';
    } elseif (!hr_payroll_tax_ar3_year_has_posted($pdo, $payYear)) {
        $yearNotPosted = true;
    } else {
        $report = hr_payroll_tax_ar3_report_build($pdo, $payYear, $employeeId);
        if ($report === null) {
            $errorMsg = 'لا توجد رواتب مرحّلة لهذا الموظف في السنة المختارة.';
        }
    }
}

$reportTitle = 'تقرير الضريبة (أر/3)';
$reportDate = date('Y-m-d');
$exportLabel = 'tax-ar3-' . $payYear . '-emp-' . ($employeeId > 0 ? (string) $employeeId : '0');

$cssPath = app_path('assets/css/hr-payroll-tax-ar3-report.css');
$cssUrl = app_url('assets/css/hr-payroll-tax-ar3-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$wmRootCss = document_print_watermark_root_css($pdo);

/**
 * @param array{dinar:int, fils:int} $parts
 */
function tax_ar3_render_money_cell(array $parts, bool $empty = false): string
{
    if ($empty) {
        return '<td class="tax-ar3-money"></td><td class="tax-ar3-money"></td>';
    }

    $dinar = (int) ($parts['dinar'] ?? 0);
    $fils = (int) ($parts['fils'] ?? 0);
    if ($dinar === 0 && $fils === 0) {
        return '<td class="tax-ar3-money">&nbsp;</td><td class="tax-ar3-money">&nbsp;</td>';
    }

    return '<td class="tax-ar3-money" dir="ltr">' . esc((string) $dinar) . '</td>'
        . '<td class="tax-ar3-money" dir="ltr">' . esc(str_pad((string) $fils, 3, '0', STR_PAD_LEFT)) . '</td>';
}
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
    class="card report-sales-page hr-tax-ar3-page"
    data-report-title="<?= esc($reportTitle) ?>"
    data-report-route="report_tax_ar3"
    data-export-label="<?= esc($exportLabel) ?>"
    data-from-dmy="<?= esc(format_date_dmY($reportDate)) ?>"
>
    <div class="hr-tax-ar3-filters no-print">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-tax-ar3-filters-form">
            <input type="hidden" name="r" value="report_tax_ar3">
            <input type="hidden" name="show" value="1">

            <label class="field">
                <span class="field-label">السنة الضريبية</span>
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

            <label class="field field--wide">
                <span class="field-label">الموظف (رواتب مرحّلة)</span>
                <select class="input" name="employee_id" required<?= $employees === [] ? ' disabled' : '' ?>>
                    <?php if ($employees === []): ?>
                        <option value="">— لا يوجد موظفون —</option>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <?php
                            $eid = (int) ($emp['id'] ?? 0);
                            $label = trim((string) ($emp['emp_code'] ?? ''));
                            $label = $label !== '' ? $label . ' — ' : '';
                            $label .= (string) ($emp['name_ar'] ?? '');
                            ?>
                            <option value="<?= $eid ?>" <?= $employeeId === $eid ? 'selected' : '' ?>>
                                <?= esc($label) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>

            <button type="submit" class="btn btn-primary btn-sm"<?= $employees === [] ? ' disabled' : '' ?>>
                عرض الشهادة
            </button>
        </form>
        <p class="hr-tax-ar3-hint muted">شهادة QP 177-F3 — مجموع الرواتب والأجور والضريبة المقتطعة للموظف (سنوياً).</p>
    </div>

    <?php if ($postedYears === []): ?>
        <p class="alert alert-error no-print">لا توجد رواتب مرحّلة بعد — رحّل رواتب السنة من شاشة «قيد الرواتب» أولاً.</p>
    <?php elseif ($showReport && $yearNotPosted): ?>
        <p class="alert alert-error no-print">السنة المختارة لا تحتوي رواتب مرحّلة.</p>
    <?php elseif ($showReport && $errorMsg !== ''): ?>
        <p class="alert alert-error no-print"><?= esc($errorMsg) ?></p>
    <?php elseif ($showReport && $report !== null): ?>
        <?php
        $emp = $report['employee'] ?? [];
        $nameParts = $emp['name_parts'] ?? [];
        $employer = $report['employer'] ?? [];
        ?>
        <div class="hr-tax-ar3-doc report-sales-result report-sales-print-area doc-print-watermark-scope">
            <?= document_print_watermark_html($pdo) ?>

            <div class="tax-ar3-head">
                <div class="tax-ar3-head__code">QP 177-F3</div>
                <div class="tax-ar3-head__ministry">
                    <div class="tax-ar3-head__ministry-line">وزارة المالية</div>
                    <div class="tax-ar3-head__ministry-line">دائرة ضريبة الدخل والمبيعات</div>
                </div>
            </div>

            <h2 class="tax-ar3-title">شهادة مجموع الرواتب والاجور والضريبة المقتطعة</h2>
            <p class="tax-ar3-law">
                بناءً على أحكام المادة (12) من قانون ضريبة الدخل رقم (34) لسنة 2014 وتعديلاته
            </p>

            <table class="tax-ar3-emp-table">
                <tbody>
                <tr>
                    <th colspan="8" class="tax-ar3-section-title">معلومات الموظف</th>
                </tr>
                <tr>
                    <td colspan="8" class="tax-ar3-emp-fullname">
                        <span class="tax-ar3-emp-label">اسم الموظف</span>
                        <strong><?= esc((string) ($emp['name_ar'] ?? '')) ?></strong>
                    </td>
                </tr>
                <tr class="tax-ar3-name-row">
                    <td><span class="tax-ar3-emp-label">الاسم</span><?= esc((string) ($nameParts['first'] ?? '')) ?></td>
                    <td><span class="tax-ar3-emp-label">الاب</span><?= esc((string) ($nameParts['father'] ?? '')) ?></td>
                    <td><span class="tax-ar3-emp-label">الجد</span><?= esc((string) ($nameParts['grandfather'] ?? '')) ?></td>
                    <td colspan="5"><span class="tax-ar3-emp-label">العائلة</span><?= esc((string) ($nameParts['family'] ?? '')) ?></td>
                </tr>
                <tr>
                    <td colspan="2"><span class="tax-ar3-emp-label">الرقم الضريبي</span><?= esc((string) ($emp['tax_no'] ?? '') !== '' ? (string) $emp['tax_no'] : '—') ?></td>
                    <td colspan="2"><span class="tax-ar3-emp-label">الرقم الوطني / جواز السفر</span><span dir="ltr"><?= esc((string) ($emp['national_id'] ?? '') !== '' ? (string) $emp['national_id'] : '—') ?></span></td>
                    <td><span class="tax-ar3-emp-label">صندوق البريد</span><?= esc((string) ($emp['po_box'] ?? '') !== '' ? (string) $emp['po_box'] : '—') ?></td>
                    <td colspan="3"><span class="tax-ar3-emp-label">الرمز البريدي</span><?= esc((string) ($emp['postal_code'] ?? '') !== '' ? (string) $emp['postal_code'] : '—') ?></td>
                </tr>
                <tr>
                    <td colspan="5"><span class="tax-ar3-emp-label">العنوان</span><?= esc((string) ($emp['address'] ?? '') !== '' ? (string) $emp['address'] : '—') ?></td>
                    <td colspan="3"><span class="tax-ar3-emp-label">الهاتف</span><span dir="ltr"><?= esc((string) ($emp['phone'] ?? '') !== '' ? (string) $emp['phone'] : '—') ?></span></td>
                </tr>
                <tr>
                    <td colspan="2"><span class="tax-ar3-emp-label">الفترة الضريبية</span><?= esc((string) ($report['tax_period'] ?? '')) ?></td>
                    <td colspan="2"><span class="tax-ar3-emp-label">مدة العمل اثناء الفترة الضريبية</span><?= esc((string) ($report['work_duration'] ?? '')) ?></td>
                    <td colspan="2"><span class="tax-ar3-emp-label">تاريخ مباشرة العمل (التعيين)</span><span dir="ltr"><?= esc((string) ($report['appointment_dmy'] ?? '') !== '' ? (string) $report['appointment_dmy'] : '—') ?></span></td>
                    <td colspan="2"><span class="tax-ar3-emp-label">تاريخ انتهاء العمل (انهاء الخدمة)</span><span dir="ltr"><?= esc((string) ($report['termination_dmy'] ?? '') !== '' ? (string) $report['termination_dmy'] : '—') ?></span></td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-main-table">
                <thead>
                <tr>
                    <th rowspan="2" class="tax-ar3-col-desc">البيان</th>
                    <th colspan="2">اجمالي الرواتب والاجور</th>
                    <th colspan="2">الضريبة المقتطعة من اجمالي</th>
                </tr>
                <tr>
                    <th>دينار</th>
                    <th>فلس</th>
                    <th>دينار</th>
                    <th>فلس</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['lines'] as $line):
                    $taxOnly = !empty($line['tax_only']);
                    ?>
                    <tr>
                        <td class="tax-ar3-col-desc"><?= esc((string) ($line['label'] ?? '')) ?></td>
                        <?= tax_ar3_render_money_cell($line['gross'] ?? [], $taxOnly) ?>
                        <?= tax_ar3_render_money_cell($line['tax'] ?? []) ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="tax-ar3-total-row">
                    <td class="tax-ar3-col-desc"><strong>المجموع</strong></td>
                    <?= tax_ar3_render_money_cell($report['totals']['gross'] ?? []) ?>
                    <?= tax_ar3_render_money_cell($report['totals']['tax'] ?? []) ?>
                </tr>
                </tfoot>
            </table>

            <p class="tax-ar3-declaration">
                أشهد بأن المعلومات الواردة في هذه الشهادة صحيحة، وأن الضريبة المقتطعة من رواتب وأجور الموظف
                <strong><?= esc((string) ($emp['name_ar'] ?? '')) ?></strong>
                عن الفترة الضريبية <strong><?= esc((string) ($report['tax_period'] ?? '')) ?></strong>
                قد تم تسديدها إلى دائرة ضريبة الدخل والمبيعات.
            </p>

            <table class="tax-ar3-employer-table">
                <tbody>
                <tr>
                    <td><span class="tax-ar3-emp-label">اسم صاحب العمل</span><?= esc((string) ($employer['name'] ?? '')) ?></td>
                    <td><span class="tax-ar3-emp-label">الرقم الضريبي</span><span dir="ltr"><?= esc((string) ($employer['tax_no'] ?? '') !== '' ? (string) $employer['tax_no'] : '—') ?></span></td>
                    <td><span class="tax-ar3-emp-label">ختم وتوقيع صاحب العمل</span></td>
                </tr>
                <tr>
                    <td colspan="3" class="tax-ar3-date-row">
                        <span class="tax-ar3-emp-label">التاريخ:</span>
                        <span dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></span>
                    </td>
                </tr>
                </tbody>
            </table>

            <p class="tax-ar3-footnote muted no-print">
                البيانات من الرواتب المرحّلة في النظام. صفوف مكافآت مجلس الإدارة ونهاية الخدمة تظهر فارغة
                إذا لم تُسجَّل في الرواتب.
            </p>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>
