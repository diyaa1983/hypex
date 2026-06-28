<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_overtime.php');
require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');
require_once app_path('includes/hr_month_chip_strip.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
hr_employee_overtime_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_overtime');
$editorFormId = 'hr-ot-editor-form';
$employees = hr_employee_active_list($pdo);
$pickerEmployees = hr_employee_picker_list($pdo);
$config = hr_overtime_load_config($pdo);

$payYear = (int) ($_GET['year'] ?? $_POST['pay_year'] ?? date('Y'));
$monthPickerOptions = hr_payroll_month_picker_options($pdo, $payYear);
$payMonthRaw = (int) ($_GET['month'] ?? $_POST['pay_month'] ?? 0);
if ($payMonthRaw >= 1 && $payMonthRaw <= 12) {
    $payMonth = $payMonthRaw;
} else {
    $payMonth = hr_payroll_default_picker_month($pdo, $payYear);
}
$payMonth = hr_payroll_month_picker_resolve($payMonth, $monthPickerOptions);
$filterEmpId = (int) ($_GET['employee_id'] ?? $_POST['filter_employee_id'] ?? 0);

function hr_ot_build_url(int $year, int $month, int $empId = 0): string
{
    $q = 'year=' . $year . '&month=' . $month;
    if ($empId > 0) {
        $q .= '&employee_id=' . $empId;
    }

    return app_url('index.php?r=hr_employee_overtime&' . $q);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect(hr_ot_build_url($payYear, $payMonth, $filterEmpId));
    }

    $act = (string) ($_POST['_action'] ?? '');
    $payYear = (int) ($_POST['pay_year'] ?? $payYear);
    $payMonth = (int) ($_POST['pay_month'] ?? $payMonth);
    $filterEmpId = (int) ($_POST['filter_employee_id'] ?? $filterEmpId);
    $returnUrl = hr_ot_build_url($payYear, $payMonth, $filterEmpId);

    try {
        if ($act === 'save') {
            $post = $_POST;
            if ((int) ($post['employee_id'] ?? 0) < 1 && $filterEmpId > 0) {
                $post['employee_id'] = (string) $filterEmpId;
            }
            hr_employee_overtime_save($pdo, $post);
            flash_set('success', 'تم حفظ ساعات العمل الإضافي.');
            redirect(hr_ot_build_url($payYear, $payMonth, (int) ($post['employee_id'] ?? $filterEmpId)));
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_overtime_delete($pdo, $id);
                flash_set('success', 'تم حذف سجل العمل الإضافي.');
            }
            redirect($returnUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

$flash = flash_get();
$monthAccess = hr_payroll_month_access($pdo, $payYear, $payMonth);
$canEdit = (bool) ($monthAccess['can_edit'] ?? false);

$filterEmpCode = '';
$filterEmpName = '';
$filterGrossSalary = 0.0;
$empSalaryPosted = false;
$currentOvertime = null;

if ($filterEmpId > 0) {
    foreach ($employees as $emp) {
        if ((int) ($emp['id'] ?? 0) === $filterEmpId) {
            $filterEmpCode = trim((string) ($emp['emp_code'] ?? ''));
            $filterEmpName = (string) ($emp['name_ar'] ?? '');
            break;
        }
    }
    if ($filterEmpName === '') {
        $filterEmpId = 0;
        $filterEmpCode = '';
    } else {
        $filterGrossSalary = hr_overtime_employee_gross($pdo, $filterEmpId);
        $salRow = hr_payroll_salary_row($pdo, $filterEmpId, $payYear, $payMonth);
        $empSalaryPosted = $salRow && (int) ($salRow['is_posted'] ?? 0) === 1;
        $currentOvertime = hr_employee_overtime_get($pdo, $filterEmpId, $payYear, $payMonth);
    }
}

$periodRows = hr_employee_overtime_list_period($pdo, $payYear, $payMonth);
$periodTotal = 0.0;
foreach ($periodRows as $pr) {
    $periodTotal += (float) ($pr['overtime_amount'] ?? 0);
}

$multiplierOptions = hr_overtime_multiplier_options($config);
$currentMultiplier = $currentOvertime
    ? hr_overtime_resolve_multiplier($config, (float) ($currentOvertime['hour_multiplier'] ?? 0))
    : (float) $multiplierOptions[0]['value'];

$currentHours = $currentOvertime ? (float) ($currentOvertime['overtime_hours'] ?? 0) : 0.0;
$currentAmount = $currentOvertime
    ? (float) ($currentOvertime['overtime_amount'] ?? 0)
    : hr_overtime_calc_amount(
        $filterGrossSalary,
        $currentHours,
        $currentMultiplier,
        (float) $config['monthly_work_days'],
        (float) $config['daily_work_hours']
    );
$currentNotes = $currentOvertime ? (string) ($currentOvertime['notes'] ?? '') : '';
$hourlyRate = hr_overtime_hourly_rate(
    $filterGrossSalary,
    (float) $config['monthly_work_days'],
    (float) $config['daily_work_hours']
);
$overtimeHourlyRate = round($hourlyRate * $currentMultiplier, 6);

$monthNames = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
    7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];
$payMonthName = (string) ($monthNames[$payMonth] ?? (string) $payMonth);

$monthStatusLabels = [
    'posted' => 'مرحّل',
    'mixed' => 'مرحّل/محتسب',
    'calculated' => 'محتسب',
    'open' => 'مفتوح',
    'empty' => '—',
];
$payMonthStatus = 'empty';
foreach ($monthPickerOptions as $opt) {
    if ((int) ($opt['month'] ?? 0) === $payMonth) {
        $payMonthStatus = (string) ($opt['status'] ?? 'empty');
        break;
    }
}
$payMonthStatusLabel = $monthStatusLabels[$payMonthStatus] ?? '—';

$cssPath = app_path('assets/css/hr-employee-overtime.css');
$cssUrl = app_url('assets/css/hr-employee-overtime.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-employee-overtime-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-employee-overtime-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$jsPath = app_path('assets/js/hr-employee-overtime.js');
$jsUrl = app_url('assets/js/hr-employee-overtime.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_employee_overtime');
?>
<?php employee_picker_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">
<link rel="stylesheet" href="<?= esc(hr_month_chip_strip_css_url()) ?>">
<script src="<?= esc(app_url('assets/js/hr-month-chip-strip.js')) ?>"></script>
<?php employee_picker_json_script($pickerEmployees, 'hr-ot-picker-json'); ?>
<script src="<?= esc($jsUrl) ?>" defer></script>

<div class="dashboard-ora hr-ot-ora12-screen hr-ot-wrap hr-ot-page"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-filter-employee-id="<?= $filterEmpId ?>"
     data-pay-year="<?= $payYear ?>"
     data-pay-month="<?= $payMonth ?>"
     data-gross-salary="<?= esc((string) $filterGrossSalary) ?>"
     data-hour-multiplier="<?= esc((string) $currentMultiplier) ?>"
     data-hour-multiplier-b="<?= esc((string) ($config['hour_multiplier_b'] ?? 1.5)) ?>"
     data-monthly-days="<?= esc((string) $config['monthly_work_days']) ?>"
     data-daily-hours="<?= esc((string) $config['daily_work_hours']) ?>"
     data-can-edit="<?= ($canEdit && !$empSalaryPosted && $filterEmpId > 0) ? '1' : '0' ?>">

    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-ot-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php hr_ora_render_title_bar('تسجيل العمل الإضافي', 'hr_employee_overtime'); ?>

    <div class="dashboard-ora-workspace">

    <?php if (($monthAccess['message'] ?? '') !== ''): ?>
        <div class="alert no-print alert-<?= ($monthAccess['alert_type'] ?? '') === 'warn' ? 'error' : 'info' ?> hr-ot-access-msg">
            <?= esc((string) $monthAccess['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($filterEmpId > 0 && $empSalaryPosted): ?>
        <div class="alert no-print alert-info hr-ot-access-msg">
            راتب هذا الموظف مرحّل لهذا الشهر — العرض فقط.
        </div>
    <?php endif; ?>

    <section class="hr-ot-panel hr-ot-master-panel dashboard-ora-panel no-print">
        <h2 class="hr-ot-panel-title">اختيار الموظف والفترة</h2>
        <div class="hr-ot-panel-body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" id="hr-ot-filter-form">
                <input type="hidden" name="r" value="hr_employee_overtime">
                <div class="hr-ot-master-grid">
                    <div class="hr-ot-master-cell hr-ot-master-cell--code">
                        <label class="hr-ot-field-label" for="hr-ot-master-emp-code">رقم الموظف</label>
                        <input class="input" type="text" id="hr-ot-master-emp-code"
                               value="<?= esc($filterEmpCode) ?>"
                               dir="ltr" inputmode="numeric" autocomplete="off" placeholder="رقم">
                    </div>
                    <div class="hr-ot-master-cell hr-ot-master-cell--name">
                        <?= employee_picker_field([
                            'id' => 'hr-ot-picker-id',
                            'label' => 'اسم الموظف',
                            'compact' => true,
                            'wrapper_class' => 'hr-ot-picker-slot',
                            'json_id' => 'hr-ot-picker-json',
                            'manual_bind' => true,
                            'value' => $filterEmpId,
                            'placeholder' => 'اضغط لاختيار الموظف',
                        ]) ?>
                    </div>
                    <div class="hr-ot-master-cell hr-ot-master-cell--month">
                        <label class="hr-ot-field-label">الشهر / السنة</label>
                        <div class="hr-ot-month-wrap">
                            <?php hr_render_month_chip_strip($monthPickerOptions, [
                                'year' => $payYear,
                                'selected_month' => $payMonth,
                                'year_input_id' => 'hr-ot-filter-year',
                                'year_input_name' => 'year',
                                'month_input_id' => 'hr-ot-filter-month',
                                'month_input_name' => 'month',
                            ]); ?>
                            <input class="input hr-ot-month-name" type="text" id="hr-ot-filter-month-name"
                                   value="<?= esc($payMonthName) ?>" readonly tabindex="-1"
                                   aria-label="اسم الشهر">
                            <input class="input hr-ot-month-status" type="text" id="hr-ot-filter-month-status"
                                   value="<?= esc($payMonthStatusLabel) ?>" readonly tabindex="-1"
                                   aria-label="حالة الشهر"
                                   data-status="<?= esc($payMonthStatus) ?>">
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <?php if ($filterEmpId > 0): ?>
        <div class="dashboard-ora-toolbar hr-ot-top-bar no-print">
            <button type="submit" class="btn btn-primary btn-sm" form="<?= esc($editorFormId) ?>"
                <?= ($canEdit && !$empSalaryPosted) ? '' : ' disabled' ?>>حفظ</button>
            <?php if ($currentOvertime && $canEdit && !$empSalaryPosted): ?>
                <button type="submit" class="btn btn-danger btn-sm" form="hr-ot-delete-form"
                        onclick="return confirm('حذف سجل العمل الإضافي لهذا الشهر؟');">حذف</button>
            <?php endif; ?>
        </div>

        <section class="hr-ot-panel hr-ot-editor-panel dashboard-ora-panel">
            <h2 class="hr-ot-panel-title">
                بيانات العمل الإضافي — <?= esc($filterEmpName) ?>
                <?php if ($filterEmpCode !== ''): ?>
                    <span class="hr-ot-emp-code" dir="ltr">(<?= esc($filterEmpCode) ?>)</span>
                <?php endif; ?>
            </h2>
            <div class="hr-ot-panel-body">
                <div class="hr-ot-calc-meta muted">
                    إجمالي الراتب: <strong dir="ltr"><?= esc(format_amount($filterGrossSalary)) ?></strong>
                    &nbsp;|&nbsp;
                    أجر الساعة: <strong dir="ltr" id="hr-ot-hourly-rate"><?= esc(format_amount($hourlyRate)) ?></strong>
                    &nbsp;|&nbsp;
                    ساعة إضافية: <strong dir="ltr" id="hr-ot-overtime-hourly-rate"><?= esc(format_amount($overtimeHourlyRate)) ?></strong>
                    &nbsp;|&nbsp;
                    المضاعف: <strong dir="ltr" id="hr-ot-multiplier-display"><?= esc(number_format($currentMultiplier, 2, '.', '')) ?></strong>
                    (<span id="hr-ot-multiplier-label"><?= esc(hr_overtime_multiplier_label($currentMultiplier)) ?></span>)
                </div>

                <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc(hr_ot_build_url($payYear, $payMonth, $filterEmpId)) ?>" class="hr-ot-editor-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save">
                    <input type="hidden" name="pay_year" value="<?= (int) $payYear ?>">
                    <input type="hidden" name="pay_month" value="<?= (int) $payMonth ?>">
                    <input type="hidden" name="filter_employee_id" value="<?= $filterEmpId ?>">
                    <input type="hidden" name="employee_id" value="<?= $filterEmpId ?>">

                    <div class="hr-ot-editor-fields">
                        <div class="field hr-ot-field-multiplier">
                            <span class="field-label required">طريقة احتساب الساعة</span>
                            <div class="hr-ot-multiplier-options" role="radiogroup" aria-label="طريقة احتساب الساعة">
                                <?php foreach ($multiplierOptions as $i => $opt): ?>
                                    <?php
                                    $mVal = (float) $opt['value'];
                                    $mId = 'hr-ot-mult-' . str_replace('.', '_', number_format($mVal, 3, '.', ''));
                                    $checked = hr_overtime_multiplier_matches($currentMultiplier, $mVal);
                                    ?>
                                    <label class="hr-ot-multiplier-option">
                                        <input type="radio" name="hour_multiplier" id="<?= esc($mId) ?>"
                                               value="<?= esc(number_format($mVal, 3, '.', '')) ?>"
                                               <?= $checked ? 'checked' : '' ?>
                                               <?= ($canEdit && !$empSalaryPosted && $i === 0) ? 'required' : '' ?>
                                               <?= ($canEdit && !$empSalaryPosted) ? '' : 'disabled' ?>>
                                        <span dir="ltr"><?= esc(number_format($mVal, 2, '.', '')) ?></span>
                                        <span class="muted">(<?= esc($opt['label']) ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <label class="field">
                            <span class="field-label required">عدد ساعات العمل الإضافي</span>
                            <input class="input" type="number" name="overtime_hours" id="hr-ot-hours-input"
                                   min="0" max="999" step="0.001"
                                   value="<?= esc(number_format($currentHours, 3, '.', '')) ?>"
                                   dir="ltr"
                                   <?= ($canEdit && !$empSalaryPosted) ? '' : 'readonly' ?>>
                        </label>
                        <label class="field">
                            <span class="field-label">مبلغ العمل الإضافي (محسوب)</span>
                            <input class="input hr-ot-amount-readonly" type="text" id="hr-ot-amount-display"
                                   value="<?= esc(format_amount($currentAmount)) ?>" readonly dir="ltr">
                        </label>
                        <label class="field hr-ot-field-notes">
                            <span class="field-label">ملاحظات</span>
                            <input class="input" type="text" name="notes" maxlength="255"
                                   value="<?= esc($currentNotes) ?>"
                                   <?= ($canEdit && !$empSalaryPosted) ? '' : 'readonly' ?>>
                        </label>
                    </div>

                    <?php if ($canEdit && !$empSalaryPosted): ?>
                        <div class="hr-ot-editor-actions">
                            <button type="submit" class="btn btn-primary">حفظ</button>
                            <?php if ($currentOvertime): ?>
                                <button type="submit" form="hr-ot-delete-form" class="btn btn-danger"
                                        onclick="return confirm('حذف سجل العمل الإضافي لهذا الشهر؟');">حذف</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if ($currentOvertime && $canEdit && !$empSalaryPosted): ?>
                    <form id="hr-ot-delete-form" method="post" action="<?= esc(hr_ot_build_url($payYear, $payMonth, $filterEmpId)) ?>" class="hidden">
                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) ($currentOvertime['id'] ?? 0) ?>">
                        <input type="hidden" name="pay_year" value="<?= (int) $payYear ?>">
                        <input type="hidden" name="pay_month" value="<?= (int) $payMonth ?>">
                        <input type="hidden" name="filter_employee_id" value="<?= $filterEmpId ?>">
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <p class="hr-ot-pick-emp muted no-print">اختر الموظف لإدخال ساعات العمل الإضافي.</p>
    <?php endif; ?>

    <section class="hr-ot-panel hr-ot-list-panel dashboard-ora-panel">
        <h2 class="hr-ot-panel-title">
            سجل الشهر — <?= sprintf('%02d', $payMonth) ?> / <?= (int) $payYear ?>
            <?php if ($periodRows): ?>
                <span class="hr-ot-list-meta">(<?= count($periodRows) ?> موظف — <?= esc(format_amount($periodTotal)) ?>)</span>
            <?php endif; ?>
        </h2>
        <div class="hr-ot-panel-body dashboard-ora-panel__body--flush">
            <div class="hr-ot-table-wrap">
                <table class="hr-ot-table">
                    <thead>
                    <tr>
                        <th>رقم الموظف</th>
                        <th>اسم الموظف</th>
                        <th>الساعات</th>
                        <th>المضاعف</th>
                        <th>إجمالي الراتب</th>
                        <th>المبلغ</th>
                        <th class="no-print"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$periodRows): ?>
                        <tr>
                            <td colspan="7" class="muted hr-ot-empty">لا توجد ساعات عمل إضافي مسجّلة لهذا الشهر.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($periodRows as $row): ?>
                            <?php
                            $rowEmpId = (int) ($row['employee_id'] ?? 0);
                            $rowUrl = hr_ot_build_url($payYear, $payMonth, $rowEmpId);
                            ?>
                            <tr class="hr-ot-row<?= $rowEmpId === $filterEmpId ? ' is-selected' : '' ?>">
                                <td dir="ltr"><code><?= esc((string) ($row['emp_code'] ?? '—')) ?></code></td>
                                <td>
                                    <a href="<?= esc($rowUrl) ?>" class="hr-ot-row-link"><?= esc((string) ($row['name_ar'] ?? '')) ?></a>
                                </td>
                                <td dir="ltr" class="num"><?= esc(number_format((float) ($row['overtime_hours'] ?? 0), 3, '.', '')) ?></td>
                                <td dir="ltr" class="num"><?= esc(number_format((float) ($row['hour_multiplier'] ?? 0), 2, '.', '')) ?></td>
                                <td dir="ltr" class="num"><?= esc(format_amount((float) ($row['base_salary'] ?? 0))) ?></td>
                                <td dir="ltr" class="num"><strong><?= esc(format_amount((float) ($row['overtime_amount'] ?? 0))) ?></strong></td>
                                <td class="no-print">
                                    <a href="<?= esc($rowUrl) ?>" class="btn btn-sm btn-secondary">فتح</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($periodRows): ?>
                        <tfoot>
                        <tr class="hr-ot-sum">
                            <td colspan="5"><strong>المجموع</strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(format_amount($periodTotal)) ?></strong></td>
                            <td class="no-print"></td>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </section>
    </div>
</div>
