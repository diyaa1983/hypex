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



function tax_ar3_render_digit_cells(string $value, int $count = 10): string

{

    $digits = preg_replace('/\D/', '', $value) ?? '';

    $html = '<span class="tax-ar3-digit-cells" dir="ltr">';

    for ($i = 0; $i < $count; $i++) {

        $ch = isset($digits[$i]) ? (string) $digits[$i] : '';

        $html .= '<span class="tax-ar3-digit-cell">' . ($ch !== '' ? esc($ch) : '&nbsp;') . '</span>';

    }

    $html .= '</span>';



    return $html;

}



/** @param array{dinar:int, fils:int} $parts */

function tax_ar3_render_amount_cell(?array $parts): string
{
    if ($parts === null) {
        return '<td class="tax-ar3-amount tax-ar3-col-amount tax-ar3-amount--empty">&nbsp;</td>';
    }

    $dinar = (int) ($parts['dinar'] ?? 0);
    $fils = (int) ($parts['fils'] ?? 0);
    if ($dinar === 0 && $fils === 0) {
        return '<td class="tax-ar3-amount tax-ar3-col-amount tax-ar3-amount--empty">&nbsp;</td>';
    }

    $display = (string) ($parts['display'] ?? number_format($dinar + ($fils / 1000), 3));

    return '<td class="tax-ar3-amount tax-ar3-col-amount" dir="ltr">' . esc($display) . '</td>';
}

function tax_ar3_render_total_cell(array $parts, string $words): string
{
    $dinar = (int) ($parts['dinar'] ?? 0);
    $fils = (int) ($parts['fils'] ?? 0);
    $words = trim($words);
    $hasAmount = $dinar !== 0 || $fils !== 0;

    $html = '<td class="tax-ar3-amount tax-ar3-col-amount"><div class="tax-ar3-total-block">';
    if ($hasAmount) {
        $display = (string) ($parts['display'] ?? '');
        $html .= '<span class="tax-ar3-total-num">' . esc($display) . '</span>';
    }
    if ($words !== '') {
        $html .= '<span class="tax-ar3-total-words">'
            . '<span class="tax-ar3-total-words-prefix">فقط وقدره: </span>'
            . esc($words)
            . '</span>';
    }
    $html .= '</div></td>';

    return $html;
}

/** @return string */
function tax_ar3_field_text(string $value): string
{
    return trim($value);
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

        <p class="hr-tax-ar3-hint muted">نموذج أر/3 — شهادة الرواتب والأجور والاقتطاعات السنوية للموظف.</p>

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

        $allowLines = $report['allowance_lines'] ?? [];

        $deductLines = $report['deduction_lines'] ?? [];

        $rowCount = max(count($allowLines), count($deductLines));

        $employerName = tax_ar3_field_text((string) ($employer['name'] ?? ''));

        $employerAddress = tax_ar3_field_text((string) ($employer['address'] ?? ''));

        $employerTaxNo = tax_ar3_field_text((string) ($employer['tax_no'] ?? ''));

        $appointmentDmy = tax_ar3_field_text((string) ($report['appointment_dmy'] ?? ''));

        $terminationDmy = tax_ar3_field_text((string) ($report['termination_dmy'] ?? ''));

        $empGovernorate = tax_ar3_field_text((string) ($emp['governorate'] ?? ''));

        $empCity = tax_ar3_field_text((string) ($emp['city'] ?? ''));

        $empStreet = tax_ar3_field_text((string) ($emp['street'] ?? ''));

        $empPoBox = tax_ar3_field_text((string) ($emp['po_box'] ?? ''));

        $empPhone = tax_ar3_field_text((string) ($emp['phone'] ?? ''));

        $empNationalId = tax_ar3_field_text((string) ($emp['national_id'] ?? ''));

        $empFileNo = tax_ar3_field_text((string) ($emp['file_no'] ?? ''));

        ?>

        <div class="hr-tax-ar3-doc report-sales-result report-sales-print-area">

            <header class="tax-ar3-official-head">
                <div class="tax-ar3-official-head__side tax-ar3-official-head__side--code">
                    <div class="tax-ar3-form-code">نموذج رقم أر / 3</div>
                </div>
                <div class="tax-ar3-official-head__center">
                    <div class="tax-ar3-bismillah">بسم الله الرحمن الرحيم</div>
                    <div class="tax-ar3-kingdom">المملكة الأردنية الهاشمية</div>
                    <div class="tax-ar3-ministry">وزارة المالية / دائرة ضريبة الدخل</div>
                </div>
                <div class="tax-ar3-official-head__side tax-ar3-official-head__side--file">&nbsp;</div>
            </header>

            <h2 class="tax-ar3-official-title">شهادة صادرة بالإستناد إلى أحكام المادتين السادسة والثامنة</h2>

            <div class="tax-ar3-nid-row">
                <span class="tax-ar3-field-label">الرقم الوطني</span>
                <?= tax_ar3_render_digit_cells($empNationalId, 10) ?>
            </div>

            <table class="tax-ar3-grid-table tax-ar3-name-row">
                <tbody>
                <tr>
                    <td class="tax-ar3-col-first">
                        <span class="tax-ar3-field-label">الاسم الأول</span>
                        <?= esc((string) ($nameParts['first'] ?? '')) ?>
                    </td>
                    <td class="tax-ar3-col-father">
                        <span class="tax-ar3-field-label">اسم الأب</span>
                        <?= esc((string) ($nameParts['father'] ?? '')) ?>
                    </td>
                    <td class="tax-ar3-col-grand">
                        <span class="tax-ar3-field-label">اسم الجد</span>
                        <?= esc((string) ($nameParts['grandfather'] ?? '')) ?>
                    </td>
                    <td class="tax-ar3-col-family">
                        <span class="tax-ar3-field-label">الاسم الأخير / العائلة</span>
                        <?= esc((string) ($nameParts['family'] ?? '')) ?>
                    </td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-grid-table tax-ar3-file-row">
                <tbody>
                <tr>
                    <td class="tax-ar3-file-label">رقم الملف</td>
                    <td class="tax-ar3-file-cells"><?= tax_ar3_render_digit_cells($empFileNo, 8) ?></td>
                    <td class="tax-ar3-year-label">السنة</td>
                    <td class="tax-ar3-year-value" dir="ltr"><?= esc((string) ($report['tax_period'] ?? '')) ?></td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-grid-table tax-ar3-addr-head">
                <tbody>
                <tr>
                    <td colspan="6">عنوانه</td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-grid-table tax-ar3-addr-row">
                <tbody>
                <tr>
                    <td>
                        <span class="tax-ar3-field-label">المحافظة</span>
                        <span class="tax-ar3-addr-val"><?= esc($empGovernorate) ?></span>
                    </td>
                    <td>
                        <span class="tax-ar3-field-label">المدينة</span>
                        <span class="tax-ar3-addr-val"><?= esc($empCity) ?></span>
                    </td>
                    <td>
                        <span class="tax-ar3-field-label">الحي</span>
                        <span class="tax-ar3-addr-val"></span>
                    </td>
                    <td colspan="2">
                        <span class="tax-ar3-field-label">الشارع</span>
                        <span class="tax-ar3-addr-val"><?= esc($empStreet) ?></span>
                    </td>
                    <td>
                        <span class="tax-ar3-field-label">ص.ب</span>
                        <span class="tax-ar3-addr-val"><?= esc($empPoBox) ?></span>
                    </td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-grid-table tax-ar3-phone-row">
                <tbody>
                <tr>
                    <td colspan="6">
                        <span class="tax-ar3-field-label">هاتف</span>
                        <span class="tax-ar3-addr-val" dir="ltr"><?= esc($empPhone) ?></span>
                    </td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-employer-table">
                <tbody>
                <tr>
                    <td class="tax-ar3-info-label">إسم المستخدم</td>
                    <td colspan="2" class="tax-ar3-employer-val"><?= esc($employerName) ?></td>
                    <td class="tax-ar3-info-label">عنوانه</td>
                    <td colspan="2" class="tax-ar3-employer-val"><?= esc($employerAddress) ?></td>
                </tr>
                <tr class="tax-ar3-dates-row">
                    <td class="tax-ar3-info-label">تاريخ مباشرة العمل</td>
                    <td dir="ltr"><?= esc($appointmentDmy) ?></td>
                    <td class="tax-ar3-info-label">تاريخ إنتهاء العمل</td>
                    <td dir="ltr"><?= esc($terminationDmy) ?></td>
                    <td class="tax-ar3-info-label">السنة</td>
                    <td dir="ltr" class="tax-ar3-year-value"><?= esc((string) ($report['tax_period'] ?? '')) ?></td>
                </tr>
                </tbody>
            </table>

            <table class="tax-ar3-financial-table">
                <thead>
                <tr>
                    <th colspan="2" class="tax-ar3-section-head">الرواتب والأجور المدفوعة</th>
                    <th colspan="2" class="tax-ar3-section-head">إقتطاعات من الرواتب والأجور</th>
                </tr>
                <tr class="tax-ar3-sub-head">
                    <th class="tax-ar3-col-label">البيان</th>
                    <th class="tax-ar3-col-amount">المبلغ</th>
                    <th class="tax-ar3-col-label">البيان</th>
                    <th class="tax-ar3-col-amount">المبلغ</th>
                </tr>
                </thead>
                <tbody>
                <?php for ($i = 0; $i < $rowCount; $i++):
                    $allow = $allowLines[$i] ?? null;
                    $ded = $deductLines[$i] ?? null;
                    ?>
                    <tr>
                        <td class="tax-ar3-col-label"><?= esc((string) ($allow['label'] ?? '')) ?></td>
                        <?= tax_ar3_render_amount_cell($allow ? ($allow['amount'] ?? null) : null) ?>
                        <td class="tax-ar3-col-label"><?= esc((string) ($ded['label'] ?? '')) ?></td>
                        <?= tax_ar3_render_amount_cell($ded ? ($ded['amount'] ?? null) : null) ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                <tr class="tax-ar3-total-row">
                    <td class="tax-ar3-col-label">المجموع</td>
                    <?= tax_ar3_render_total_cell($report['allowance_total'] ?? [], (string) ($report['allowance_total_words'] ?? '')) ?>
                    <td class="tax-ar3-col-label">المجموع</td>
                    <?= tax_ar3_render_total_cell($report['deduction_total'] ?? [], (string) ($report['deduction_total_words'] ?? '')) ?>
                </tr>
                </tfoot>
            </table>

            <p class="tax-ar3-work-days">
                مدة العمل أثناء العام :
                <strong dir="ltr"><?= esc((string) ($report['work_duration'] ?? '')) ?></strong>
            </p>

            <p class="tax-ar3-declaration">
                أشهد بصحة المعلومات الواردة أعلاه، وأن الضرائب والاقتطاعات المبينة قد تمت عن الموظف
                <strong><?= esc((string) ($emp['name_ar'] ?? '')) ?></strong>
                للسنة <strong dir="ltr"><?= esc((string) ($report['tax_period'] ?? '')) ?></strong>
                وفق أحكام قانون ضريبة الدخل رقم (34) لسنة 2014 وتعديلاته.
            </p>

            <table class="tax-ar3-footer-table">
                <tbody>
                <tr>
                    <td class="tax-ar3-info-label">اسم صاحب العمل</td>
                    <td class="tax-ar3-employer-val"><?= esc($employerName) ?></td>
                    <td class="tax-ar3-info-label">الرقم الضريبي</td>
                    <td dir="ltr"><?= esc($employerTaxNo) ?></td>
                </tr>
                <tr>
                    <td class="tax-ar3-info-label">التاريخ</td>
                    <td dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></td>
                    <td class="tax-ar3-info-label">توقيعه والختم</td>
                    <td class="tax-ar3-sign-space">&nbsp;</td>
                </tr>
                </tbody>
            </table>

            <div class="tax-ar3-footer-file">
                <span class="tax-ar3-field-label">رقم الملف</span>
                <?= tax_ar3_render_digit_cells($empFileNo, 8) ?>
            </div>

            <p class="tax-ar3-footnote muted no-print">
                البيانات من الرواتب المرحّلة. العلاوات التفصيلية غير المسجّلة في النظام تظهر ضمن «علاوة شخصية»
                والعمل الإضافي والمكافآت ضمن بندها المخصص.
            </p>
        </div>

    <?php endif; ?>

</div>



<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>


