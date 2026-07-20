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

/** @param array{dinar:int, fils:int}|null $parts */
function tax_ar3_render_money_cells(?array $parts, bool $allowEmpty = true): string
{
    if ($parts === null) {
        if (!$allowEmpty) {
            return '<td class="tax-ar3-money">&nbsp;</td><td class="tax-ar3-money">&nbsp;</td>';
        }

        return '<td class="tax-ar3-money tax-ar3-money--empty">&nbsp;</td><td class="tax-ar3-money tax-ar3-money--empty">&nbsp;</td>';
    }

    $dinar = (int) ($parts['dinar'] ?? 0);
    $fils = (int) ($parts['fils'] ?? 0);
    if ($dinar === 0 && $fils === 0) {
        return '<td class="tax-ar3-money tax-ar3-money--empty">&nbsp;</td><td class="tax-ar3-money tax-ar3-money--empty">&nbsp;</td>';
    }

    return '<td class="tax-ar3-money" dir="ltr">' . esc(number_format($dinar)) . '</td>'
        . '<td class="tax-ar3-money" dir="ltr">' . esc(str_pad((string) $fils, 3, '0', STR_PAD_LEFT)) . '</td>';
}

function tax_ar3_field_text(string $value, bool $zeroIfEmpty = false): string
{
    $value = trim($value);
    if ($value === '' && $zeroIfEmpty) {
        return '0';
    }

    return $value;
}

$emblemUrl = app_url('assets/images/jordan-coat-of-arms.png');
$emblemPath = app_path('assets/images/jordan-coat-of-arms.png');
if (is_file($emblemPath)) {
    $emblemUrl .= '?v=' . (string) filemtime($emblemPath);
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
        <p class="hr-tax-ar3-hint muted">نموذج QP 177-F3 — شهادة مجموع الرواتب والأجور والضريبة المقتطعة.</p>
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
        $financialRows = $report['financial_rows'] ?? [];
        $employerName = tax_ar3_field_text((string) ($employer['name'] ?? ''));
        $employerTaxNo = tax_ar3_field_text((string) ($employer['tax_no'] ?? ''));
        $appointmentDmy = tax_ar3_field_text((string) ($report['appointment_dmy'] ?? ''));
        $terminationDmy = tax_ar3_field_text((string) ($report['termination_dmy'] ?? ''), true);
        $empAddress = tax_ar3_field_text((string) ($emp['address'] ?? ''));
        $empPoBox = tax_ar3_field_text((string) ($emp['po_box'] ?? ''), true);
        $empPostal = tax_ar3_field_text((string) ($emp['postal_code'] ?? ''), true);
        $empPhone = tax_ar3_field_text((string) ($emp['phone'] ?? ''));
        $empNationalId = tax_ar3_field_text((string) ($emp['national_id'] ?? ''));
        $empTaxNo = tax_ar3_field_text((string) ($emp['tax_no'] ?? ''), true);
        $nameFirst = tax_ar3_field_text((string) ($nameParts['first'] ?? ''));
        $nameFather = tax_ar3_field_text((string) ($nameParts['father'] ?? ''), true);
        $nameGrand = tax_ar3_field_text((string) ($nameParts['grandfather'] ?? ''), true);
        $nameFamily = tax_ar3_field_text((string) ($nameParts['family'] ?? ''), true);
        $reportDateAr3 = date('d-m-Y');
        ?>
        <div class="hr-tax-ar3-doc report-sales-result report-sales-print-area">
            <header class="tax-ar3-official-head">
                <div class="tax-ar3-official-head__side tax-ar3-official-head__side--code">
                    <div class="tax-ar3-form-code">QP 177-F3</div>
                </div>
                <div class="tax-ar3-official-head__center">
                    <img class="tax-ar3-emblem" src="<?= esc($emblemUrl) ?>" alt="شعار المملكة الأردنية الهاشمية" width="78" height="47">
                    <div class="tax-ar3-kingdom">المملكة الأردنية الهاشمية</div>
                </div>
                <div class="tax-ar3-official-head__side tax-ar3-official-head__side--right">
                    <div class="tax-ar3-ministry">وزارة الماليــــــة</div>
                    <div class="tax-ar3-department">دائرة ضريبة الدخل والمبيعات</div>
                </div>
            </header>

            <h2 class="tax-ar3-official-title">شهادة مجموع الرواتب والأجور والضريبة المقتطعة</h2>
            <p class="tax-ar3-official-subtitle">
                صادر بالاستناد لأحكام الفقرة (و) من المادة (12) من قانون ضريبة الدخل رقم (34) لسنة 2014 وتعديلاته.
            </p>

            <table class="tax-ar3-grid-table tax-ar3-info-section">
                <thead>
                <tr>
                    <th colspan="4" class="tax-ar3-section-title">معلومات الموظف</th>
                </tr>
                <tr>
                    <th colspan="4" class="tax-ar3-subsection-title">اسم الموظف</th>
                </tr>
                </thead>
                <tbody>
                <tr class="tax-ar3-label-row">
                    <td>الاسم</td>
                    <td>الأب</td>
                    <td>الجد</td>
                    <td>العائلة</td>
                </tr>
                <tr class="tax-ar3-value-row">
                    <td><?= esc($nameFirst) ?></td>
                    <td dir="ltr"><?= esc($nameFather) ?></td>
                    <td dir="ltr"><?= esc($nameGrand) ?></td>
                    <td><?= esc($nameFamily) ?></td>
                </tr>
                <tr class="tax-ar3-label-row">
                    <td>الرقم الضريبي</td>
                    <td>الرقم الوطني / جواز السفر</td>
                    <td>صندوق البريد</td>
                    <td>الرمز البريدي</td>
                </tr>
                <tr class="tax-ar3-value-row">
                    <td dir="ltr"><?= esc($empTaxNo) ?></td>
                    <td dir="ltr"><?= esc($empNationalId) ?></td>
                    <td dir="ltr"><?= esc($empPoBox) ?></td>
                    <td dir="ltr"><?= esc($empPostal) ?></td>
                </tr>
                <tr class="tax-ar3-label-row">
                    <td colspan="2">العنــــــوان</td>
                    <td colspan="2">الهاتف</td>
                </tr>
                <tr class="tax-ar3-value-row">
                    <td colspan="2"><?= esc($empAddress) ?></td>
                    <td colspan="2" dir="ltr"><?= esc($empPhone) ?></td>
                </tr>
                <tr class="tax-ar3-label-row">
                    <td>الفترة الضريبية</td>
                    <td>مدة العمل أثناء الفترة الضريبية</td>
                    <td>تاريخ مباشرة العمل (التعيين)</td>
                    <td>تاريخ انتهاء العمل (إنهاء الخدمة)</td>
                </tr>
                <tr class="tax-ar3-value-row">
                    <td dir="ltr"><?= esc((string) ($report['tax_period'] ?? '')) ?></td>
                    <td dir="ltr"><?= esc((string) ($report['work_duration'] ?? '')) ?></td>
                    <td dir="ltr"><?= esc($appointmentDmy) ?></td>
                    <td dir="ltr"><?= esc($terminationDmy) ?></td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-financial-table">
                <thead>
                <tr>
                    <th rowspan="3" class="tax-ar3-row-label-head">&nbsp;</th>
                    <th colspan="2" class="tax-ar3-section-head">إجمالي الرواتب والأجور</th>
                    <th colspan="2" class="tax-ar3-section-head">الضريبة المقتطعة من إجمالي الرواتب والأجور</th>
                </tr>
                <tr class="tax-ar3-value-head">
                    <th colspan="2">القيمة</th>
                    <th colspan="2">القيمة</th>
                </tr>
                <tr class="tax-ar3-sub-head">
                    <th>دينار</th>
                    <th>فلس</th>
                    <th>دينار</th>
                    <th>فلس</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($financialRows as $row): ?>
                    <?php
                    $isNationalRow = ((string) ($row['label'] ?? '')) === 'المساهمة الوطنية';
                    ?>
                    <tr>
                        <td class="tax-ar3-row-label"><?= esc((string) ($row['label'] ?? '')) ?></td>
                        <?= tax_ar3_render_money_cells($isNationalRow ? null : ($row['wage'] ?? null)) ?>
                        <?= tax_ar3_render_money_cells($row['tax'] ?? null) ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="tax-ar3-total-row">
                    <td class="tax-ar3-row-label">المجموع</td>
                    <?= tax_ar3_render_money_cells($report['wage_total'] ?? null, false) ?>
                    <?= tax_ar3_render_money_cells($report['tax_total'] ?? null, false) ?>
                </tr>
                </tfoot>
            </table>

            <p class="tax-ar3-declaration">
                أشهد أن المعلومات المدرجة أعلاه صحيحة وحقيقية وكاملة وغير منقوصة،
                وأنني قمت بدفع ضريبة الدخل المقتطعة والمبينة أعلاه إلى دائرة ضريبة الدخل والمبيعات.
            </p>

            <table class="tax-ar3-employer-table">
                <tbody>
                <tr>
                    <td class="tax-ar3-info-label">اسم صاحب العمل</td>
                    <td class="tax-ar3-employer-val"><?= esc($employerName) ?></td>
                    <td class="tax-ar3-sign-space" rowspan="2">ختم وتوقيع صاحب العمل</td>
                </tr>
                <tr>
                    <td class="tax-ar3-info-label">الرقم الضريبي</td>
                    <td class="tax-ar3-employer-val" dir="ltr"><?= esc($employerTaxNo) ?></td>
                </tr>
                </tbody>
            </table>

            <footer class="tax-ar3-doc-footer">
                <div class="tax-ar3-doc-footer__date">
                    التاريخ: <span dir="ltr"><?= esc($reportDateAr3) ?></span>
                </div>
                <div class="tax-ar3-doc-footer__note">حدث النموذج بتاريخ 2019/8/4م</div>
            </footer>

            <p class="tax-ar3-footnote muted no-print">
                البيانات من الرواتب المرحّلة. الراتب الأساسي والعلاوات ضمن «الرواتب والأجور»،
                والعمل الإضافي والمكافآت ضمن «الرواتب والأجور غير الشهرية».
            </p>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>
