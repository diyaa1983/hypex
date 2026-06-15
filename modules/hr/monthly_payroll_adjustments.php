<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/hr_employee_monthly_payroll.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
hr_employee_monthly_payroll_line_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_monthly_payroll_adjustments');
$editorFormId = 'hr-mpa-editor-form';
$employees = hr_employee_active_list($pdo);

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

/**
 * @return string
 */
function hr_mpa_build_url(int $year, int $month, int $empId = 0): string
{
    $q = 'year=' . $year . '&month=' . $month;
    if ($empId > 0) {
        $q .= '&employee_id=' . $empId;
    }

    return app_url('index.php?r=hr_monthly_payroll_adjustments&' . $q);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect(hr_mpa_build_url($payYear, $payMonth, $filterEmpId));
    }

    $act = (string) ($_POST['_action'] ?? '');
    $payYear = (int) ($_POST['pay_year'] ?? $payYear);
    $payMonth = (int) ($_POST['pay_month'] ?? $payMonth);
    $filterEmpId = (int) ($_POST['filter_employee_id'] ?? $filterEmpId);
    $returnUrl = hr_mpa_build_url($payYear, $payMonth, $filterEmpId);

    try {
        if ($act === 'save_one') {
            $lineId = (int) ($_POST['id'] ?? 0);
            $baseSalary = (float) ($_POST['base_salary'] ?? 0);
            $parsed = hr_employee_monthly_payroll_parse_row($pdo, $_POST, $baseSalary);
            hr_employee_monthly_payroll_save_line($pdo, $lineId, $parsed);
            flash_set('success', $lineId > 0 ? 'تم حفظ تعديل البند.' : 'تم إضافة البند للشهر.');
            redirect($returnUrl);
        }

        if ($act === 'delete') {
            $lineId = (int) ($_POST['id'] ?? 0);
            if ($lineId > 0) {
                hr_employee_monthly_payroll_delete_line($pdo, $lineId);
                flash_set('success', 'تم حذف البند.');
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
$filterBaseSalary = 0.0;
$empSalaryPosted = false;
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
        $stBase = $pdo->prepare('SELECT base_salary FROM hr_employee WHERE id = ? LIMIT 1');
        $stBase->execute([$filterEmpId]);
        $filterBaseSalary = (float) ($stBase->fetchColumn() ?: 0);
        $salRow = hr_payroll_salary_row($pdo, $filterEmpId, $payYear, $payMonth);
        $empSalaryPosted = $salRow && (int) ($salRow['is_posted'] ?? 0) === 1;
    }
}

$allowComponents = hr_payroll_component_active_by_type($pdo, 'allowance');
$deductComponents = hr_payroll_component_active_by_type($pdo, 'deduction');
$allowLines = $filterEmpId > 0
    ? hr_employee_monthly_payroll_lines_list($pdo, $filterEmpId, $payYear, $payMonth, 'allowance')
    : [];
$deductLines = $filterEmpId > 0
    ? hr_employee_monthly_payroll_lines_list($pdo, $filterEmpId, $payYear, $payMonth, 'deduction')
    : [];
$lineCount = $filterEmpId > 0
    ? hr_employee_monthly_payroll_count_for_period($pdo, $filterEmpId, $payYear, $payMonth)
    : 0;

$monthNames = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
    7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];

$allowComponentsJson = json_encode($allowComponents, JSON_UNESCAPED_UNICODE);
$deductComponentsJson = json_encode($deductComponents, JSON_UNESCAPED_UNICODE);

$cssPath = app_path('assets/css/hr-monthly-payroll.css');
$cssUrl = app_url('assets/css/hr-monthly-payroll.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOra12Path = app_path('assets/css/hr-monthly-payroll-oracle12.css');
$cssOra12Url = app_url('assets/css/hr-monthly-payroll-oracle12.css')
    . (is_file($cssOra12Path) ? '?v=' . (string) filemtime($cssOra12Path) : '');
$jsPath = app_path('assets/js/hr-monthly-payroll.js');
$jsUrl = app_url('assets/js/hr-monthly-payroll.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_monthly_payroll_adjustments');

/**
 * @param list<array<string, mixed>> $rows
 */
function hr_mpa_render_rows(array $rows, float $baseSalary, string $panelType, string $emptyMsg): void
{
    if (!$rows) {
        ?>
        <tr class="hr-mpa-row hr-mpa-row--empty">
            <td colspan="3" class="muted"><?= esc($emptyMsg) ?></td>
        </tr>
        <?php
        return;
    }

    foreach ($rows as $line) {
        $lineId = (int) ($line['line_id'] ?? 0);
        $code = (string) ($line['comp_code'] ?? '');
        $name = (string) ($line['name_ar'] ?? '');
        $amountCell = hr_employee_monthly_payroll_format_amount_display($line, $baseSalary);
        $amountInput = hr_employee_monthly_payroll_amount_input_value($line, $baseSalary);
        $isPercent = (int) ($line['is_percent'] ?? 0);
        $notes = (string) ($line['notes'] ?? '');
        ?>
        <tr class="hr-mpa-row"
            data-id="<?= $lineId ?>"
            data-comp-type="<?= esc($panelType) ?>"
            data-component-id="<?= (int) ($line['component_id'] ?? 0) ?>"
            data-comp-code="<?= esc($code) ?>"
            data-comp-name="<?= esc($name) ?>"
            data-amount="<?= esc($amountInput) ?>"
            data-is-percent="<?= $isPercent ?>"
            data-notes="<?= esc($notes) ?>"
            tabindex="0">
            <td class="hr-mpa-col-num"><?= esc($code !== '' ? $code : '—') ?></td>
            <td><?= esc($name !== '' ? $name : '—') ?></td>
            <td class="hr-mpa-col-amount" dir="ltr"><?= esc($amountCell) ?></td>
        </tr>
        <?php
    }
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOra12Url) ?>">

<div class="hr-mpa-classic hr-mpa-ora-screen hr-mpa-page"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-filter-employee-id="<?= $filterEmpId ?>"
     data-pay-year="<?= $payYear ?>"
     data-pay-month="<?= $payMonth ?>"
     data-base-salary="<?= esc((string) $filterBaseSalary) ?>"
     data-can-edit="<?= ($canEdit && !$empSalaryPosted && $filterEmpId > 0) ? '1' : '0' ?>">

    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-mpa-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php hr_ora_render_title_bar('علاوات واقتطاعات شهرية', 'hr_monthly_payroll_adjustments'); ?>

    <?php if (($monthAccess['message'] ?? '') !== ''): ?>
        <div class="alert no-print alert-<?= ($monthAccess['alert_type'] ?? '') === 'warn' ? 'error' : 'info' ?> hr-mpa-access-msg">
            <?= esc((string) $monthAccess['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($filterEmpId > 0 && $empSalaryPosted): ?>
        <div class="alert no-print alert-info hr-mpa-access-msg">
            راتب هذا الموظف مرحّل لهذا الشهر — العرض فقط.
        </div>
    <?php endif; ?>

    <section class="hr-mpa-panel hr-mpa-master-panel no-print">
        <div class="hr-mpa-panel-body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" id="hr-mpa-filter-form">
                <input type="hidden" name="r" value="hr_monthly_payroll_adjustments">
                <div class="hr-mpa-master-grid">
                    <div class="hr-mpa-master-cell hr-mpa-master-cell--code">
                        <label class="hr-mpa-field-label" for="hr-mpa-master-emp-code">رقم الموظف</label>
                        <input class="input" type="text" id="hr-mpa-master-emp-code"
                               value="<?= esc($filterEmpCode !== '' ? $filterEmpCode : '') ?>"
                               readonly dir="ltr" tabindex="-1" aria-readonly="true" placeholder="—">
                    </div>
                    <div class="hr-mpa-master-cell hr-mpa-master-cell--name">
                        <label class="hr-mpa-field-label" for="hr-mpa-filter-employee">اسم الموظف</label>
                        <div class="hr-mpa-ora-lov">
                            <select class="input hr-mpa-ora-lov-field" name="employee_id" id="hr-mpa-filter-employee" required>
                                <option value="">— اختر موظفاً —</option>
                                <?php foreach ($employees as $emp):
                                    $eid = (int) ($emp['id'] ?? 0);
                                ?>
                                    <option value="<?= $eid ?>"
                                            data-emp-code="<?= esc((string) ($emp['emp_code'] ?? '')) ?>"
                                        <?= $filterEmpId === $eid ? 'selected' : '' ?>>
                                        <?= esc((string) ($emp['name_ar'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="hr-mpa-ora-lov-btn" tabindex="-1" aria-label="اختيار الموظف" title="اختيار الموظف"></button>
                        </div>
                    </div>
                    <div class="hr-mpa-master-cell hr-mpa-master-cell--month">
                        <label class="hr-mpa-field-label" for="hr-mpa-filter-month">الشهر</label>
                        <div class="hr-mpa-month-wrap">
                            <input class="input hr-mpa-year-input" type="number" name="year" id="hr-mpa-filter-year"
                                   min="2000" max="2100" value="<?= $payYear ?>" dir="ltr" required
                                   aria-label="السنة">
                            <select class="input hr-mpa-month-select" name="month" id="hr-mpa-filter-month" required>
                            <?php foreach ($monthPickerOptions as $opt):
                                $m = (int) ($opt['month'] ?? 0);
                                if ($m < 1 || $m > 12) {
                                    continue;
                                }
                                $monthLabel = sprintf('%02d', $m) . ' — ' . ($monthNames[$m] ?? (string) $m)
                                    . (string) ($opt['label_suffix'] ?? '');
                            ?>
                                <option value="<?= $m ?>" <?= $payMonth === $m ? 'selected' : '' ?>>
                                    <?= esc($monthLabel) ?>
                                </option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="hr-mpa-master-cell hr-mpa-master-cell--pick">
                        <button type="submit" class="btn btn-primary btn-sm">عرض</button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <?php if ($filterEmpId > 0): ?>

    <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc(hr_mpa_build_url($payYear, $payMonth, $filterEmpId)) ?>" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_one">
        <input type="hidden" name="id" id="hr-mpa-editor-id" value="0">
        <input type="hidden" name="pay_year" value="<?= $payYear ?>">
        <input type="hidden" name="pay_month" value="<?= $payMonth ?>">
        <input type="hidden" name="filter_employee_id" value="<?= $filterEmpId ?>">
        <input type="hidden" name="employee_id" value="<?= $filterEmpId ?>">
        <input type="hidden" name="base_salary" value="<?= esc((string) $filterBaseSalary) ?>">
        <input type="hidden" name="comp_type" id="hr-mpa-editor-comp-type" value="allowance">
        <input type="hidden" name="component_id" id="hr-mpa-editor-component" value="">
        <input type="hidden" name="amount" id="hr-mpa-editor-amount" value="0">
        <input type="hidden" name="notes" id="hr-mpa-editor-notes" value="">
    </form>

    <section class="hr-mpa-panel hr-mpa-panel--allow hr-mpa-lines-panel" data-panel-type="allowance">
        <h2 class="hr-mpa-panel-title">العلاوات</h2>
        <div class="hr-mpa-panel-toolbar">
            <button type="button" class="btn btn-primary btn-sm hr-mpa-btn-add" data-type="allowance"
                <?= (!$canEdit || $empSalaryPosted) ? ' disabled' : '' ?>>إضافة</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-save" data-type="allowance" disabled>حفظ</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-cancel" data-type="allowance" disabled>إلغاء</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-edit" data-type="allowance" disabled>تعديل</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-delete" data-type="allowance" disabled>حذف</button>
        </div>
        <div class="hr-mpa-panel-body hr-mpa-panel-body--flush">
            <div class="hr-mpa-grid-wrap">
                <table class="hr-mpa-grid-table hr-mpa-lines-table">
                    <thead>
                    <tr>
                        <th>رقم العلاوة</th>
                        <th>اسم العلاوة</th>
                        <th class="hr-mpa-col-amount-h">المبلغ</th>
                    </tr>
                    </thead>
                    <tbody class="hr-mpa-tbody" data-panel-type="allowance">
                    <?php hr_mpa_render_rows(
                        $allowLines,
                        $filterBaseSalary,
                        'allowance',
                        'لا توجد علاوات — اضغط «إضافة» واختر العلاوة من الجدول.'
                    ); ?>
                    </tbody>
                </table>
            </div>
            <p class="hr-mpa-inline-hint muted" id="hr-mpa-allow-hint" hidden></p>
        </div>
    </section>

    <section class="hr-mpa-panel hr-mpa-panel--deduct hr-mpa-lines-panel" data-panel-type="deduction">
        <h2 class="hr-mpa-panel-title">الاقتطاعات</h2>
        <div class="hr-mpa-panel-toolbar">
            <button type="button" class="btn btn-primary btn-sm hr-mpa-btn-add" data-type="deduction"
                <?= (!$canEdit || $empSalaryPosted) ? ' disabled' : '' ?>>إضافة</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-save" data-type="deduction" disabled>حفظ</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-cancel" data-type="deduction" disabled>إلغاء</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-edit" data-type="deduction" disabled>تعديل</button>
            <button type="button" class="btn btn-secondary btn-sm hr-mpa-btn-delete" data-type="deduction" disabled>حذف</button>
        </div>
        <div class="hr-mpa-panel-body hr-mpa-panel-body--flush">
            <div class="hr-mpa-grid-wrap">
                <table class="hr-mpa-grid-table hr-mpa-lines-table">
                    <thead>
                    <tr>
                        <th>رقم الاقتطاع</th>
                        <th>اسم الاقتطاع</th>
                        <th class="hr-mpa-col-amount-h">المبلغ</th>
                    </tr>
                    </thead>
                    <tbody class="hr-mpa-tbody" data-panel-type="deduction">
                    <?php hr_mpa_render_rows(
                        $deductLines,
                        $filterBaseSalary,
                        'deduction',
                        'لا توجد اقتطاعات — اضغط «إضافة» واختر الاقتطاع من الجدول.'
                    ); ?>
                    </tbody>
                </table>
            </div>
            <p class="hr-mpa-inline-hint muted" id="hr-mpa-deduct-hint" hidden></p>
        </div>
    </section>

    <form method="post" action="<?= esc(hr_mpa_build_url($payYear, $payMonth, $filterEmpId)) ?>" id="hr-mpa-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-mpa-delete-id" value="0">
        <input type="hidden" name="pay_year" value="<?= $payYear ?>">
        <input type="hidden" name="pay_month" value="<?= $payMonth ?>">
        <input type="hidden" name="filter_employee_id" value="<?= $filterEmpId ?>">
    </form>
    <?php else: ?>
        <p class="hr-mpa-pick-hint">اختر رقم الموظف واسم الموظف والشهر ثم اضغط «عرض».</p>
    <?php endif; ?>
</div>

<script type="application/json" id="hr-mpa-allow-components-json"><?= $allowComponentsJson ?></script>
<script type="application/json" id="hr-mpa-deduct-components-json"><?= $deductComponentsJson ?></script>
<script src="<?= esc($jsUrl) ?>" defer></script>
