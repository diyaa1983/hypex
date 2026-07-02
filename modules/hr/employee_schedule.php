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
$isNewWeekly = !empty($_GET['new_week']);

function hr_emp_sch_url(int $employeeId, int $weeklyId = 0, bool $newWeek = false): string
{
    $q = 'employee_id=' . $employeeId;
    if ($newWeek) {
        $q .= '&new_week=1';
    } elseif ($weeklyId > 0) {
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
$scheduleLoaded = $employeeId > 0;
$schedule = $scheduleLoaded ? hr_employee_schedule_load($pdo, $employeeId) : ['weekly_periods' => [], 'default_shift_id' => 0];
$weeklyPeriods = $schedule['weekly_periods'];
$defaultShiftId = (int) ($schedule['default_shift_id'] ?? 0);
$canEditSchedule = $scheduleLoaded && $shiftOptions !== [];

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
if (!$isNewWeekly && $editWeekly === null && $weeklyPeriods !== []) {
    $editWeekly = $weeklyPeriods[count($weeklyPeriods) - 1];
    $weeklyId = (int) ($editWeekly['id'] ?? 0);
}

$weeklyDateFrom = '';
$weeklyDateTo = '';
$weeklyDays = array_fill(0, 7, 0);
if ($editWeekly) {
    $weeklyDateFrom = (string) ($editWeekly['date_from_dmY'] ?? '');
    $weeklyDateTo = (string) ($editWeekly['date_to_dmY'] ?? '');
    $weeklyDays = (array) ($editWeekly['days'] ?? array_fill(0, 7, 0));
} elseif ($isNewWeekly || ($scheduleLoaded && $weeklyPeriods === [])) {
    $suggestedWeek = hr_employee_schedule_suggest_next_week($weeklyPeriods);
    $weeklyDateFrom = (string) ($suggestedWeek['date_from_dmY'] ?? '');
    $weeklyDateTo = (string) ($suggestedWeek['date_to_dmY'] ?? '');
}

$weeklyPeriodTotal = count($weeklyPeriods);
$weeklyPeriodPos = -1;
if ($weeklyId > 0) {
    foreach ($weeklyPeriods as $idx => $p) {
        if ((int) ($p['id'] ?? 0) === $weeklyId) {
            $weeklyPeriodPos = $idx;
            break;
        }
    }
}

$prevWeeklyUrl = '';
$nextWeeklyUrl = '';
$newWeeklyUrl = hr_emp_sch_url($employeeId, 0, true);

if ($isNewWeekly) {
    if ($weeklyPeriodTotal > 0) {
        $lastPeriod = $weeklyPeriods[$weeklyPeriodTotal - 1];
        $prevWeeklyUrl = hr_emp_sch_url($employeeId, (int) ($lastPeriod['id'] ?? 0));
    }
} elseif ($weeklyPeriodPos >= 0) {
    if ($weeklyPeriodPos > 0) {
        $prevWeeklyUrl = hr_emp_sch_url(
            $employeeId,
            (int) ($weeklyPeriods[$weeklyPeriodPos - 1]['id'] ?? 0)
        );
    }
    if ($weeklyPeriodPos < $weeklyPeriodTotal - 1) {
        $nextWeeklyUrl = hr_emp_sch_url(
            $employeeId,
            (int) ($weeklyPeriods[$weeklyPeriodPos + 1]['id'] ?? 0)
        );
    } else {
        $nextWeeklyUrl = $newWeeklyUrl;
    }
}

$weeklyNavLabel = '';
if ($isNewWeekly) {
    $weeklyNavLabel = $weeklyPeriodTotal > 0
        ? ('فترة جديدة (' . ($weeklyPeriodTotal + 1) . ')')
        : 'فترة جديدة';
} elseif ($weeklyPeriodPos >= 0) {
    $weeklyNavLabel = 'الفترة ' . ($weeklyPeriodPos + 1) . ' من ' . $weeklyPeriodTotal;
}

$weekDayDates = array_fill(0, 7, '');
$weekStartIso = parse_date_to_iso($weeklyDateFrom) ?? '';
if ($weekStartIso !== '') {
    for ($d = 0; $d <= 6; $d++) {
        $ts = strtotime($weekStartIso . ' +' . $d . ' days');
        $weekDayDates[$d] = $ts !== false ? format_date_dmY(date('Y-m-d', $ts)) : '';
    }
}

$defaultShiftLabel = $defaultShiftId > 0
    ? hr_employee_schedule_shift_label($pdo, $defaultShiftId)
    : '— غير معرّف —';

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

<div class="dashboard-ora hr-emp-sch-ora12-screen hr-emp-sch-wrap hr-emp-sch-page hr-emp-sch-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-weekly-form-id="<?= esc($weeklyFormId) ?>"
     data-default-form-id="<?= esc($defaultFormId) ?>"
     data-employee-id="<?= $employeeId ?>"
     data-schedule-loaded="<?= $scheduleLoaded ? '1' : '0' ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">تعريف دوام الموظف</h1>
        <?php nav_render_screen_close('hr_employee_schedule'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-emp-sch-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

        <section class="dashboard-ora-panel hr-emp-sch-master-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات الموظف</h2>
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

        <?php if ($scheduleLoaded && $shiftOptions === []): ?>
            <p class="hr-emp-sch-empty muted">
                لا توجد شفتات مفعّلة —
                <a href="<?= esc(app_url('index.php?r=hr_attendance_settings')) ?>">عرّف الشفتات أولاً</a>.
            </p>
        <?php endif; ?>

        <div class="sales-ora-tabs hr-emp-sch-tabs no-print" role="tablist">
            <button type="button" class="sales-ora-tab is-active" data-tab="weekly" role="tab" aria-selected="true">جدول أسبوعي</button>
            <button type="button" class="sales-ora-tab" data-tab="default" role="tab" aria-selected="false">الشفت الافتراضي</button>
        </div>

        <div class="hr-emp-sch-tab-panels">
            <section class="hr-emp-sch-tab-panel is-active" data-panel="weekly">
                <form id="<?= esc($weeklyFormId) ?>" method="post" action="<?= esc(hr_emp_sch_url($employeeId, $weeklyId)) ?>">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_weekly">
                    <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                    <input type="hidden" name="weekly_id" value="<?= $weeklyId ?>">

                    <section class="dashboard-ora-panel hr-emp-sch-week-panel<?= !$scheduleLoaded ? ' is-empty-state' : '' ?>">
                        <h2 class="dashboard-ora-panel__title">الجدول الأسبوعي</h2>
                        <div class="dashboard-ora-panel__body hr-emp-sch-week-body">
                            <?php if (!$scheduleLoaded): ?>
                                <p class="hr-emp-sch-empty muted">اختر موظفاً ثم اضغط «عرض» لتحميل جدول دوامه.</p>
                            <?php endif; ?>
                            <div class="hr-emp-sch-week-center">
                                <div class="hr-emp-sch-period-bar no-print">
                                    <div class="hr-emp-sch-period-strip">
                                        <div class="hr-emp-sch-period-nav" role="group" aria-label="تنقّل بين الفترات الأسبوعية" dir="rtl">
                                            <?php if ($canEditSchedule && $prevWeeklyUrl !== ''): ?>
                                                <a class="hr-emp-sch-nav-btn hr-emp-sch-nav-btn--prev" href="<?= esc($prevWeeklyUrl) ?>"
                                                   title="الفترة السابقة" aria-label="الفترة السابقة">‹</a>
                                            <?php else: ?>
                                                <span class="hr-emp-sch-nav-btn hr-emp-sch-nav-btn--prev is-disabled" aria-hidden="true">‹</span>
                                            <?php endif; ?>

                                            <label class="hr-emp-sch-date-field hr-emp-sch-date-field--from">
                                                <span class="hr-emp-sch-date-label">من (سبت)</span>
                                                <input class="input input-compact js-date-dmy" type="text" name="date_from" id="hr-emp-sch-date-from"
                                                       value="<?= esc($weeklyDateFrom) ?>" dir="ltr" autocomplete="off"
                                                       <?= $canEditSchedule ? 'required' : 'readonly' ?>
                                                       data-date-calendar="ar-sat">
                                            </label>

                                            <span class="hr-emp-sch-period-sep" aria-hidden="true">—</span>

                                            <label class="hr-emp-sch-date-field hr-emp-sch-date-field--to">
                                                <span class="hr-emp-sch-date-label">إلى (جمعة)</span>
                                                <input class="input input-compact js-date-dmy" type="text" name="date_to" id="hr-emp-sch-date-to"
                                                       value="<?= esc($weeklyDateTo) ?>" dir="ltr" autocomplete="off"
                                                       <?= $canEditSchedule ? 'required' : 'readonly' ?>
                                                       data-date-calendar="ar-sat">
                                            </label>

                                            <?php if ($canEditSchedule && $nextWeeklyUrl !== ''): ?>
                                                <a class="hr-emp-sch-nav-btn hr-emp-sch-nav-btn--next" href="<?= esc($nextWeeklyUrl) ?>"
                                                   title="<?= $nextWeeklyUrl === $newWeeklyUrl ? 'فترة جديدة' : 'الفترة التالية' ?>"
                                                   aria-label="<?= $nextWeeklyUrl === $newWeeklyUrl ? 'فترة جديدة' : 'الفترة التالية' ?>">›</a>
                                            <?php else: ?>
                                                <span class="hr-emp-sch-nav-btn hr-emp-sch-nav-btn--next is-disabled" aria-hidden="true">›</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="hr-emp-sch-period-actions no-print">
                                            <?php if ($canEditSchedule && $weeklyNavLabel !== ''): ?>
                                                <span class="hr-emp-sch-period-badge"><?= esc($weeklyNavLabel) ?></span>
                                            <?php endif; ?>
                                            <?php if ($canEditSchedule): ?>
                                                <a class="btn btn-secondary btn-sm hr-emp-sch-new-week-btn<?= $isNewWeekly ? ' is-active' : '' ?>"
                                                   href="<?= esc($newWeeklyUrl) ?>">إضافة فترة جديدة</a>
                                            <?php else: ?>
                                                <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">إضافة فترة جديدة</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <p class="hr-emp-sch-week-hint muted" id="hr-emp-sch-week-hint"></p>

                                <div class="dashboard-ora-table-wrap hr-emp-sch-week-table-wrap">
                                    <table class="dashboard-ora-table hr-emp-sch-week-table">
                                    <thead>
                                    <tr>
                                        <th class="hr-emp-sch-col-idx">#</th>
                                        <th class="hr-emp-sch-col-day">اليوم</th>
                                        <th class="hr-emp-sch-col-date">التاريخ</th>
                                        <th class="hr-emp-sch-col-shift">الشفت</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php for ($d = 0; $d <= 6; $d++): ?>
                                        <tr>
                                            <td class="hr-emp-sch-col-idx"><?= $d + 1 ?></td>
                                            <td class="hr-emp-sch-col-day"><?= esc((string) ($dayNames[$d] ?? '')) ?></td>
                                            <td class="hr-emp-sch-col-date" dir="ltr">
                                                <span class="hr-emp-sch-day-date" data-day-offset="<?= $d ?>">
                                                    <?= esc($scheduleLoaded && $weekDayDates[$d] !== '' ? $weekDayDates[$d] : '—') ?>
                                                </span>
                                            </td>
                                            <td class="hr-emp-sch-col-shift">
                                                <select class="input" name="day_shift[<?= $d ?>]" <?= $canEditSchedule ? '' : 'disabled' ?>>
                                                    <option value="0">— الشفت الافتراضي —</option>
                                                    <?php foreach ($shiftOptions as $opt): ?>
                                                        <option value="<?= (int) $opt['id'] ?>"
                                                            <?= $scheduleLoaded && (int) ($weeklyDays[$d] ?? 0) === (int) $opt['id'] ? 'selected' : '' ?>>
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

                                <div class="hr-emp-sch-form-actions no-print">
                                    <button type="submit" class="btn btn-primary btn-sm" <?= $canEditSchedule ? '' : 'disabled' ?>>حفظ الأسبوع</button>
                                    <?php if ($canEditSchedule && $weeklyId > 0): ?>
                                        <button type="button" class="btn btn-danger btn-sm" id="hr-emp-sch-delete-weekly">حذف الفترة</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                </form>

                <?php if ($canEditSchedule && $weeklyId > 0): ?>
                    <form method="post" action="<?= esc(hr_emp_sch_url($employeeId)) ?>" id="hr-emp-sch-delete-form" class="sr-only">
                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="_action" value="delete_weekly">
                        <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                        <input type="hidden" name="weekly_id" value="<?= $weeklyId ?>">
                    </form>
                <?php endif; ?>
            </section>

            <section class="hr-emp-sch-tab-panel" data-panel="default" hidden>
                <div class="dashboard-ora-panel hr-emp-sch-default-panel">
                    <h2 class="dashboard-ora-panel__title">الشفت الافتراضي للموظف</h2>
                    <div class="dashboard-ora-panel__body">
                        <?php if (!$scheduleLoaded): ?>
                            <p class="hr-emp-sch-hint muted">اختر موظفاً ثم اضغط «عرض» لتعريف الشفت الافتراضي.</p>
                        <?php else: ?>
                            <p class="hr-emp-sch-hint muted">
                                يُستخدم للأيام بدون شفت محدد، أو عندما لا توجد فترة أسبوعية تغطي التاريخ.
                                الشفت الحالي: <strong><?= esc($defaultShiftLabel) ?></strong>
                            </p>
                            <form id="<?= esc($defaultFormId) ?>" method="post" action="<?= esc(hr_emp_sch_url($employeeId)) ?>">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="save_default">
                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                <label class="field hr-emp-sch-default-field">
                                    <span class="field-label required">الشفت الافتراضي</span>
                                    <select class="input" name="default_shift_id" required <?= $shiftOptions === [] ? 'disabled' : '' ?>>
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
                                    <button type="submit" class="btn btn-primary btn-sm" <?= $shiftOptions === [] ? 'disabled' : '' ?>>حفظ الشفت الافتراضي</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
