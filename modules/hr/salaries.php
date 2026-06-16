<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_salary.php');
require_once app_path('includes/hr_employee_salary.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
hr_employee_salary_line_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_salaries');
$masterFormId = 'hr-sal-master-form';
$lineFormId = 'hr-sal-line-form';

$employees = hr_employee_active_list($pdo);
$pickerEmployees = hr_employee_picker_list($pdo);
$allowComponents = hr_payroll_component_active_by_type($pdo, 'allowance');

/**
 * @return string
 */
function hr_sal_build_url(int $empId = 0): string
{
    if ($empId > 0) {
        return app_url('index.php?r=hr_salaries&employee_id=' . $empId);
    }

    return app_url('index.php?r=hr_salaries');
}

$filterEmpId = (int) ($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect(hr_sal_build_url($filterEmpId));
    }

    $act = (string) ($_POST['_action'] ?? '');
    $filterEmpId = (int) ($_POST['employee_id'] ?? $filterEmpId);
    $returnUrl = hr_sal_build_url($filterEmpId);

    try {
        if ($act === 'save_master') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $base = (float) ($_POST['base_salary'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($empId < 1) {
                throw new RuntimeException('اختر الموظف أولاً.');
            }
            if ($base < 0) {
                throw new RuntimeException('الراتب الأساسي يجب أن يكون موجباً أو صفر.');
            }

            $stEmp = $pdo->prepare('SELECT id, base_salary FROM hr_employee WHERE id = ? LIMIT 1');
            $stEmp->execute([$empId]);
            $empRow = $stEmp->fetch(PDO::FETCH_ASSOC);
            if (!$empRow) {
                throw new RuntimeException('الموظف غير موجود.');
            }
            $oldBase = (float) ($empRow['base_salary'] ?? 0);

            if ($oldBase > 0 && abs($oldBase - $base) >= 0.0001) {
                hr_employee_salary_recalc_percent_lines($pdo, $empId, $oldBase, $base);
            }

            $lines = hr_employee_salary_allowance_lines_only(hr_employee_salary_lines_load($pdo, $empId));
            $totals = hr_employee_salary_totals($base, $lines);

            $st = $pdo->prepare(
                'UPDATE hr_employee SET base_salary = ?, allowances = ?, notes = ? WHERE id = ?'
            );
            $st->execute([
                $base,
                $totals['allowances'],
                $notes !== '' ? $notes : null,
                $empId,
            ]);

            flash_set('success', 'تم حفظ معلومات الراتب.');
            redirect(hr_sal_build_url($empId));
        }

        if ($act === 'save_line') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $componentId = (int) ($_POST['component_id'] ?? 0);
            $base = (float) ($_POST['base_salary'] ?? 0);
            $prevComponentId = (int) ($_POST['prev_component_id'] ?? 0);

            if ($empId < 1) {
                throw new RuntimeException('اختر الموظف أولاً.');
            }
            if ($componentId < 1) {
                throw new RuntimeException('اختر العلاوة من القائمة.');
            }

            if ($prevComponentId > 0 && $prevComponentId !== $componentId) {
                hr_employee_salary_delete_allowance_line($pdo, $empId, $prevComponentId);
            }

            $stComp = $pdo->prepare('SELECT default_amount FROM hr_payroll_component WHERE id = ? LIMIT 1');
            $stComp->execute([$componentId]);
            $amount = (float) ($stComp->fetchColumn() ?: 0);

            hr_employee_salary_save_allowance_line($pdo, $empId, $componentId, $amount, $base);
            flash_set('success', 'تم حفظ العلاوة.');
            redirect(hr_sal_build_url($empId));
        }

        if ($act === 'delete_line') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $componentId = (int) ($_POST['component_id'] ?? 0);
            if ($empId > 0 && $componentId > 0) {
                hr_employee_salary_delete_allowance_line($pdo, $empId, $componentId);
                flash_set('success', 'تم حذف العلاوة.');
            }
            redirect(hr_sal_build_url($empId));
        }

        if ($act === 'clear_salary') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            if ($empId > 0) {
                hr_employee_salary_clear($pdo, $empId);
                flash_set('success', 'تم مسح إعداد راتب الموظف.');
            }
            redirect(hr_sal_build_url($empId));
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

$flash = flash_get();

$filterEmpCode = '';
$filterEmpName = '';
$filterBaseSalary = 0.0;
$filterAllowancesTotal = 0.0;
$filterGrossSalary = 0.0;
$filterNotes = '';
$allowLines = [];

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
        $stEmp = $pdo->prepare('SELECT base_salary, notes FROM hr_employee WHERE id = ? LIMIT 1');
        $stEmp->execute([$filterEmpId]);
        $empRow = $stEmp->fetch(PDO::FETCH_ASSOC) ?: [];
        $filterBaseSalary = (float) ($empRow['base_salary'] ?? 0);
        $filterNotes = (string) ($empRow['notes'] ?? '');
        $allowLines = hr_employee_salary_allowance_lines_list($pdo, $filterEmpId);
        $totals = hr_employee_salary_totals($filterBaseSalary, $allowLines);
        $filterAllowancesTotal = (float) ($totals['allowances'] ?? 0);
        $filterGrossSalary = (float) ($totals['gross'] ?? 0);
    }
}

$allowComponentsJson = json_encode($allowComponents, JSON_UNESCAPED_UNICODE);

$cssPath = app_path('assets/css/hr-salaries.css');
$cssUrl = app_url('assets/css/hr-salaries.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOra12Path = app_path('assets/css/hr-salaries-oracle12.css');
$cssOra12Url = app_url('assets/css/hr-salaries-oracle12.css')
    . (is_file($cssOra12Path) ? '?v=' . (string) filemtime($cssOra12Path) : '');
$jsPath = app_path('assets/js/hr-salaries.js');
$jsUrl = app_url('assets/js/hr-salaries.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$slipBaseUrl = app_url('index.php?r=hr_salary_slip');
$exitUrl = nav_exit_url('hr_salaries');

/**
 * @param list<array<string, mixed>> $rows
 */
function hr_sal_render_allow_rows(array $rows, float $baseSalary): void
{
    if (!$rows) {
        ?>
        <tr class="hr-sal-row hr-sal-row--empty">
            <td colspan="3" class="muted">لا توجد علاوات — اضغط «إضافة» واختر العلاوة من الجدول.</td>
        </tr>
        <?php
        return;
    }

    foreach ($rows as $line) {
        $compId = (int) ($line['component_id'] ?? 0);
        $code = (string) ($line['comp_code'] ?? '');
        $name = (string) ($line['name_ar'] ?? '');
        $amountCell = hr_employee_salary_format_amount_display($line, $baseSalary);
        $amountInput = hr_employee_salary_amount_input_value($line, $baseSalary);
        $isPercent = (int) ($line['is_percent'] ?? 0);
        ?>
        <tr class="hr-sal-row"
            data-component-id="<?= $compId ?>"
            data-comp-code="<?= esc($code) ?>"
            data-comp-name="<?= esc($name) ?>"
            data-amount="<?= esc($amountInput) ?>"
            data-is-percent="<?= $isPercent ?>"
            tabindex="0">
            <td class="hr-sal-col-num" dir="ltr"><?= esc($code !== '' ? $code : '—') ?></td>
            <td><?= esc($name !== '' ? $name : '—') ?></td>
            <td class="hr-sal-col-amount" dir="ltr"><?= esc($amountCell) ?></td>
        </tr>
        <?php
    }
}
?>
<?php employee_picker_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOra12Url) ?>">
<?php employee_picker_json_script($pickerEmployees, 'hr-salaries-picker-json'); ?>

<div class="hr-sal-classic hr-sal-ora-screen hr-sal-page"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-slip-url="<?= esc($slipBaseUrl) ?>"
     data-master-form-id="<?= esc($masterFormId) ?>"
     data-line-form-id="<?= esc($lineFormId) ?>"
     data-filter-employee-id="<?= $filterEmpId ?>"
     data-base-salary="<?= esc((string) $filterBaseSalary) ?>"
     data-allow-total="<?= esc((string) $filterAllowancesTotal) ?>"
     data-can-edit="<?= $filterEmpId > 0 ? '1' : '0' ?>">

    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-sal-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php hr_ora_render_title_bar('رواتب الموظفين', 'hr_salaries'); ?>

    <section class="hr-sal-panel hr-sal-master-panel">
        <h2 class="hr-sal-panel-title">معلومات الراتب</h2>
        <div class="hr-sal-panel-body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" id="hr-sal-pick-form">
                <input type="hidden" name="r" value="hr_salaries">
                <div class="hr-sal-master-grid">
                    <div class="hr-sal-master-cell hr-sal-master-cell--code">
                        <label class="hr-sal-field-label" for="hr-sal-emp-code">رقم الموظف</label>
                        <div class="hr-sal-emp-code-nav" role="group" aria-label="تنقل بين الموظفين">
                            <button type="button" class="hr-sal-emp-nav-btn" id="hr-sal-emp-prev"
                                    title="الموظف السابق" aria-label="الموظف السابق">‹</button>
                            <input class="input" type="text" id="hr-sal-emp-code"
                                   value="<?= esc($filterEmpCode !== '' ? $filterEmpCode : '') ?>"
                                   dir="ltr" inputmode="numeric" autocomplete="off" placeholder="رقم">
                            <button type="button" class="hr-sal-emp-nav-btn" id="hr-sal-emp-next"
                                    title="الموظف التالي" aria-label="الموظف التالي">›</button>
                        </div>
                    </div>
                    <div class="hr-sal-master-cell hr-sal-master-cell--name">
                        <?= employee_picker_field([
                            'id' => 'hr-sal-picker-id',
                            'label' => 'اسم الموظف',
                            'compact' => true,
                            'wrapper_class' => 'hr-sal-picker-slot',
                            'json_id' => 'hr-salaries-picker-json',
                            'manual_bind' => true,
                            'value' => $filterEmpId,
                            'placeholder' => 'اضغط لاختيار الموظف',
                        ]) ?>
                    </div>
                </div>
            </form>

            <?php if ($filterEmpId > 0): ?>
            <form method="post" action="<?= esc(hr_sal_build_url($filterEmpId)) ?>" id="<?= esc($masterFormId) ?>" class="hr-sal-master-grid hr-sal-master-grid--salary">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="save_master">
                <input type="hidden" name="employee_id" value="<?= $filterEmpId ?>">
                <div class="hr-sal-master-cell hr-sal-master-cell--base">
                    <label class="hr-sal-field-label" for="hr-sal-base-salary">الراتب الأساسي</label>
                    <input class="input" type="number" name="base_salary" id="hr-sal-base-salary"
                           step="0.001" min="0" value="<?= esc((string) $filterBaseSalary) ?>"
                           dir="ltr" inputmode="decimal" required>
                </div>
                <div class="hr-sal-master-cell hr-sal-master-cell--allow-total">
                    <label class="hr-sal-field-label" for="hr-sal-allow-total">مجموع العلاوات</label>
                    <input class="input" type="text" id="hr-sal-allow-total"
                           value="<?= esc(number_format($filterAllowancesTotal, 3)) ?>"
                           dir="ltr" readonly>
                </div>
                <div class="hr-sal-master-cell hr-sal-master-cell--gross-total">
                    <label class="hr-sal-field-label" for="hr-sal-gross-total">إجمالي الراتب</label>
                    <input class="input" type="text" id="hr-sal-gross-total"
                           value="<?= esc(number_format($filterGrossSalary, 3)) ?>"
                           dir="ltr" readonly>
                </div>
                <input type="hidden" name="notes" id="hr-sal-notes" value="<?= esc($filterNotes) ?>">
            </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($filterEmpId > 0): ?>
    <form id="<?= esc($lineFormId) ?>" method="post" action="<?= esc(hr_sal_build_url($filterEmpId)) ?>" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_line">
        <input type="hidden" name="employee_id" value="<?= $filterEmpId ?>">
        <input type="hidden" name="base_salary" id="hr-sal-line-base" value="<?= esc((string) $filterBaseSalary) ?>">
        <input type="hidden" name="component_id" id="hr-sal-line-component" value="">
        <input type="hidden" name="prev_component_id" id="hr-sal-line-prev-component" value="0">
        <input type="hidden" name="amount" id="hr-sal-line-amount" value="0">
    </form>
    <?php endif; ?>

    <section class="hr-sal-panel hr-sal-panel--allow hr-sal-lines-panel">
        <h2 class="hr-sal-panel-title">العلاوات</h2>
        <div class="hr-sal-panel-toolbar">
            <button type="button" class="btn btn-primary btn-sm hr-sal-btn-add"<?= $filterEmpId > 0 ? '' : ' disabled' ?>>إضافة</button>
            <button type="button" class="btn btn-secondary btn-sm hr-sal-btn-save" disabled>حفظ</button>
            <button type="button" class="btn btn-secondary btn-sm hr-sal-btn-cancel" disabled>إلغاء</button>
            <button type="button" class="btn btn-secondary btn-sm hr-sal-btn-edit" disabled>تعديل</button>
            <button type="button" class="btn btn-secondary btn-sm hr-sal-btn-delete" disabled>حذف</button>
        </div>
        <div class="hr-sal-panel-body hr-sal-panel-body--flush">
            <div class="hr-sal-grid-wrap">
                <table class="hr-sal-grid-table hr-sal-lines-table">
                    <thead>
                    <tr>
                        <th>رقم العلاوة</th>
                        <th>اسم العلاوة</th>
                        <th class="hr-sal-col-amount-h">المبلغ</th>
                    </tr>
                    </thead>
                    <tbody class="hr-sal-tbody" id="hr-sal-allow-tbody">
                    <?php if ($filterEmpId > 0): ?>
                        <?php hr_sal_render_allow_rows($allowLines, $filterBaseSalary); ?>
                    <?php else: ?>
                        <tr class="hr-sal-row hr-sal-row--empty">
                            <td colspan="3" class="muted">اختر الموظف أولاً لعرض العلاوات.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="hr-sal-inline-hint muted" id="hr-sal-allow-hint" hidden></p>
        </div>
    </section>

    <?php if ($filterEmpId > 0): ?>
    <form method="post" action="<?= esc(hr_sal_build_url($filterEmpId)) ?>" id="hr-sal-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete_line">
        <input type="hidden" name="employee_id" value="<?= $filterEmpId ?>">
        <input type="hidden" name="component_id" id="hr-sal-delete-component" value="0">
    </form>

    <form method="post" action="<?= esc(hr_sal_build_url($filterEmpId)) ?>" id="hr-sal-clear-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="clear_salary">
        <input type="hidden" name="employee_id" value="<?= $filterEmpId ?>">
    </form>
    <?php endif; ?>
</div>

<script type="application/json" id="hr-sal-allow-components-json"><?= $allowComponentsJson ?></script>
<script src="<?= esc($jsUrl) ?>" defer></script>
