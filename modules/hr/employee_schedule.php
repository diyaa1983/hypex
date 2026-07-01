<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_schedule.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
hr_employee_schedule_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_schedule');
$weeklyFormId = 'hr-emp-sch-weekly-form';
$defaultFormId = 'hr-emp-sch-default-form';

$employees = hr_employee_active_list($pdo);
$pickerEmployees = hr_employee_picker_list($pdo);
$shiftOptions = hr_employee_schedule_shift_options($pdo);
$dayNames = hr_employee_schedule_day_names();

$employeeId = (int) ($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
$weeklyId = (int) ($_GET['weekly_id'] ?? $_POST['weekly_id'] ?? 0);

function hr_emp_sch_url(int $employeeId, int $weeklyId = 0): string
{
    $q = 'employee_id=' . $employeeId;
    if ($weeklyId > 0) {
        $q .= '&weekly_id=' . $weeklyId;
    }

    return app_url('index.php?r=hr_employee_schedule&' . $q);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    $weeklyId = (int) ($_POST['weekly_id'] ?? 0);
    $returnUrl = hr_emp_sch_url($employeeId, $weeklyId);

    try {
        if ($act === 'save_default') {
            hr_employee_schedule_save_default($pdo, $employeeId, (int) ($_POST['default_shift_id'] ?? 0));
            flash_set('success', 'تم حفظ الشفت الافتراضي للموظف.');
            redirect(hr_emp_sch_url($employeeId));
        }

        if ($act === 'save_weekly') {
            $dayShifts = [];
            for ($i = 0; $i <= 6; $i++) {
                $dayShifts[$i] = (int) ($_POST['day_shift'][$i] ?? $_POST['day_shift_' . $i] ?? 0);
            }
            $newId = hr_employee_schedule_save_weekly(
                $pdo,
                $employeeId,
                $weeklyId,
                (string) ($_POST['date_from'] ?? ''),
                (string) ($_POST['date_to'] ?? ''),
                $dayShifts
            );
            flash_set('success', $weeklyId > 0 ? 'تم حفظ الفترة الأسبوعية.' : 'تم إضافة فترة أسبوعية جديدة.');
            redirect(hr_emp_sch_url($employeeId, $newId));
        }

        if ($act === 'delete_weekly') {
            hr_employee_schedule_delete_weekly($pdo, $employeeId, $weeklyId);
            flash_set('success', 'تم حذف الفترة الأسبوعية.');
            redirect(hr_emp_sch_url($employeeId));
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

$flash = flash_get();
$schedule = hr_employee_schedule_load($pdo, $employeeId);
$weeklyPeriods = $schedule['weekly_periods'];
$defaultShiftId = (int) ($schedule['default_shift_id'] ?? 0);

$empCode = '';
$empName = '';
if ($employeeId > 0) {
    foreach ($employees as $emp) {
        if ((int) ($emp['id'] ?? 0) === $employeeId) {
            $empCode = trim((string) ($emp['emp_code'] ?? ''));
            $empName = (string) ($emp['name_ar'] ?? '');
            break;
        }
    }
    if ($empName === '') {
        $employeeId = 0;
        $empCode = '';
    }
}

$editWeekly = null;
if ($weeklyId > 0) {
    foreach ($weeklyPeriods as $p) {
        if ((int) ($p['id'] ?? 0) === $weeklyId) {
            $editWeekly = $p;
            break;
        }
    }
}
if ($editWeekly === null && $weeklyPeriods !== []) {
    $editWeekly = $weeklyPeriods[count($weeklyPeriods) - 1];
    $weeklyId = (int) ($editWeekly['id'] ?? 0);
}

$weeklyDateFrom = $editWeekly ? (string) ($editWeekly['date_from_dmY'] ?? '') : '';
$weeklyDateTo = $editWeekly ? (string) ($editWeekly['date_to_dmY'] ?? '') : '';
$weeklyDays = $editWeekly ? (array) ($editWeekly['days'] ?? []) : array_fill(0, 7, 0);

$cssPath = app_path('assets/css/hr-employee-schedule.css');
$cssUrl = app_url('assets/css/hr-employee-schedule.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-employee-schedule-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-employee-schedule-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$jsPath = app_path('assets/js/hr-employee-schedule.js');
$jsUrl = app_url('assets/js/hr-employee-schedule.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_employee_schedule');
?>
<?php employee_picker_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">
<?php employee_picker_json_script($pickerEmployees, 'hr-emp-sch-picker-json'); ?>
<script src="<?= esc($jsUrl) ?>" defer></script>

<div class="dashboard-ora hr-emp-sch-ora12-screen hr-emp-sch-wrap hr-emp-sch-page"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-weekly-form-id="<?= esc($weeklyFormId) ?>"
     data-default-form-id="<?= esc($defaultFormId) ?>"
     data-employee-id="<?= $employeeId ?>">

    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-emp-sch-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php hr_ora_render_title_bar('تعريف دوام الموظف', 'hr_employee_schedule'); ?>

    <div class="dashboard-ora-workspace">
        <section class="dashboard-ora-panel hr-emp-sch-master-panel no-print">
            <h2 class="dashboard-ora-panel__title">اختيار الموظف</h2>
            <div class="dashboard-ora-panel__body">
                <form method="get" action="<?= esc(app_url('index.php')) ?>" id="hr-emp-sch-filter-form" class="hr-emp-sch-filter-form">
                    <input type="hidden" name="r" value="hr_employee_schedule">
                    <div class="hr-emp-sch-master-grid">
                        <div class="hr-emp-sch-master-cell">
                            <label class="field-label" for="hr-emp-sch-emp-code">رقم الموظف</label>
                            <input class="input" type="text" id="hr-emp-sch-emp-code"
                                   value="<?= esc($empCode) ?>" dir="ltr" inputmode="numeric" autocomplete="off" placeholder="رقم">
                        </div>
                        <div class="hr-emp-sch-master-cell hr-emp-sch-master-cell--name">
                            <?= employee_picker_field([
                                'id' => 'hr-emp-sch-employee-id',
                                'name' => 'employee_id',
                                'label' => 'اسم الموظف',
                                'compact' => true,
                                'wrapper_class' => 'hr-emp-sch-picker-slot',
                                'json_id' => 'hr-emp-sch-picker-json',
                                'manual_bind' => true,
                                'value' => $employeeId,
                                'required' => true,
                                'placeholder' => 'اضغط لاختيار الموظف',
                            ]) ?>
                        </div>
                        <div class="hr-emp-sch-master-cell hr-emp-sch-master-cell--action">
                            <button type="submit" class="btn btn-primary btn-sm">عرض</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($employeeId < 1): ?>
            <p class="hr-emp-sch-empty muted">اختر موظفاً لعرض وتعريف دوامه.</p>
        <?php elseif ($shiftOptions === []): ?>
            <p class="hr-emp-sch-empty muted">
                لا توجد شفتات مفعّلة —
                <a href="<?= esc(app_url('index.php?r=hr_attendance_settings')) ?>">عرّف الشفتات أولاً</a>.
            </p>
        <?php else: ?>
            <div class="sales-ora-tabs hr-emp-sch-tabs no-print" role="tablist">
                <button type="button" class="sales-ora-tab is-active" data-tab="weekly" role="tab" aria-selected="true">جدول أسبوعي</button>
                <button type="button" class="sales-ora-tab" data-tab="default" role="tab" aria-selected="false">الشفت الافتراضي</button>
            </div>

            <div class="hr-emp-sch-tab-panels">
                <section class="hr-emp-sch-tab-panel is-active" data-panel="weekly">
                    <div class="hr-emp-sch-weekly-layout">
                        <aside class="hr-emp-sch-periods-panel dashboard-ora-panel">
                            <h3 class="dashboard-ora-panel__title">فترات الدوام</h3>
                            <div class="dashboard-ora-panel__body">
                                <a class="btn btn-secondary btn-sm hr-emp-sch-new-period"
                                   href="<?= esc(hr_emp_sch_url($employeeId)) ?>">فترة جديدة</a>
                                <ul class="hr-emp-sch-periods-list">
                                    <?php if ($weeklyPeriods === []): ?>
                                        <li class="muted">لا توجد فترات بعد</li>
                                    <?php endif; ?>
                                    <?php foreach ($weeklyPeriods as $p): ?>
                                        <?php $pid = (int) ($p['id'] ?? 0); ?>
                                        <li>
                                            <a class="hr-emp-sch-period-link<?= $pid === $weeklyId ? ' is-active' : '' ?>"
                                               href="<?= esc(hr_emp_sch_url($employeeId, $pid)) ?>">
                                                <span dir="ltr"><?= esc((string) ($p['date_from_dmY'] ?? '')) ?></span>
                                                —
                                                <span dir="ltr"><?= esc((string) ($p['date_to_dmY'] ?? '')) ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </aside>

                        <div class="hr-emp-sch-weekly-editor dashboard-ora-panel">
                            <h3 class="dashboard-ora-panel__title">الجدول الأسبوعي للفترة</h3>
                            <div class="dashboard-ora-panel__body">
                                <p class="hr-emp-sch-hint muted">
                                    حدّد الفترة ثم عيّن شفتاً لكل يوم. إذا تركت يوماً بدون شفت يُستخدم
                                    <strong>الشفت الافتراضي</strong> من التبويب الثاني.
                                </p>

                                <form id="<?= esc($weeklyFormId) ?>" method="post" action="<?= esc(hr_emp_sch_url($employeeId, $weeklyId)) ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                    <input type="hidden" name="_action" value="save_weekly">
                                    <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                    <input type="hidden" name="weekly_id" value="<?= $weeklyId ?>">

                                    <div class="hr-emp-sch-date-row form-row">
                                        <label class="field">
                                            <span class="field-label required">من تاريخ</span>
                                            <input class="input js-date-dmy" type="text" name="date_from"
                                                   value="<?= esc($weeklyDateFrom) ?>" dir="ltr" autocomplete="off" required>
                                        </label>
                                        <label class="field">
                                            <span class="field-label required">إلى تاريخ</span>
                                            <input class="input js-date-dmy" type="text" name="date_to"
                                                   value="<?= esc($weeklyDateTo) ?>" dir="ltr" autocomplete="off" required>
                                        </label>
                                    </div>

                                    <div class="dashboard-ora-table-wrap hr-emp-sch-week-table-wrap">
                                        <table class="dashboard-ora-table hr-emp-sch-week-table">
                                            <thead>
                                            <tr>
                                                <th>اليوم</th>
                                                <th>الشفت</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php for ($d = 0; $d <= 6; $d++): ?>
                                                <tr>
                                                    <td><?= esc((string) ($dayNames[$d] ?? '')) ?></td>
                                                    <td>
                                                        <select class="input" name="day_shift[<?= $d ?>]">
                                                            <option value="0">— الشفت الافتراضي —</option>
                                                            <?php foreach ($shiftOptions as $opt): ?>
                                                                <option value="<?= (int) $opt['id'] ?>"
                                                                    <?= (int) ($weeklyDays[$d] ?? 0) === (int) $opt['id'] ? 'selected' : '' ?>>
                                                                    <?= esc((string) $opt['label']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php endfor; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="hr-emp-sch-form-actions">
                                        <button type="submit" class="btn btn-primary btn-sm">حفظ الفترة الأسبوعية</button>
                                        <?php if ($weeklyId > 0): ?>
                                            <button type="button" class="btn btn-danger btn-sm" id="hr-emp-sch-delete-weekly">حذف الفترة</button>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <?php if ($weeklyId > 0): ?>
                                    <form method="post" action="<?= esc(hr_emp_sch_url($employeeId)) ?>" id="hr-emp-sch-delete-form" class="sr-only">
                                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="_action" value="delete_weekly">
                                        <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                        <input type="hidden" name="weekly_id" value="<?= $weeklyId ?>">
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="hr-emp-sch-tab-panel" data-panel="default" hidden>
                    <div class="dashboard-ora-panel hr-emp-sch-default-panel">
                        <h3 class="dashboard-ora-panel__title">الشفت الافتراضي للموظف</h3>
                        <div class="dashboard-ora-panel__body">
                            <p class="hr-emp-sch-hint muted">
                                يُستخدم عندما لا تغطي أي فترة أسبوعية التاريخ المطلوب،
                                أو عندما يكون يوم معيّن في الجدول الأسبوعي بدون شفت محدد.
                            </p>
                            <form id="<?= esc($defaultFormId) ?>" method="post" action="<?= esc(hr_emp_sch_url($employeeId)) ?>">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="save_default">
                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                <label class="field hr-emp-sch-default-field">
                                    <span class="field-label required">الشفت الافتراضي</span>
                                    <select class="input" name="default_shift_id" required>
                                        <option value="">— اختر شفتاً —</option>
                                        <?php foreach ($shiftOptions as $opt): ?>
                                            <option value="<?= (int) $opt['id'] ?>"
                                                <?= $defaultShiftId === (int) $opt['id'] ? 'selected' : '' ?>>
                                                <?= esc((string) $opt['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="hr-emp-sch-form-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">حفظ الشفت الافتراضي</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>
</div>
