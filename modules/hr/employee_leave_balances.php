<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_leave_balance.php');
require_once app_path('includes/employee_picker.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_leave_balance_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);
hr_leave_type_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_leave_balances');
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$leaveTypes = hr_leave_type_list($pdo, true);
$pickerEmployees = hr_employee_picker_list($pdo);

$defaultPeriod = hr_employee_leave_balance_default_period();
$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? $defaultPeriod['from'];
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? $defaultPeriod['to'];
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$flash = flash_get();
$balanceRows = $employeeId > 0
    ? hr_employee_leave_balance_rows_for_employee($pdo, $employeeId, $dateFrom, $dateTo)
    : hr_employee_leave_balance_rows_template($pdo);

$selectedEmpLabel = '';
$hireDateDisplay = '—';
if ($employeeId > 0) {
    foreach ($pickerEmployees as $emp) {
        if ((int) ($emp['id'] ?? 0) === $employeeId) {
            $selectedEmpLabel = trim((string) ($emp['emp_code'] ?? '') . ' — ' . (string) ($emp['name_ar'] ?? ''));
            break;
        }
    }
    $stHire = $pdo->prepare('SELECT hire_date FROM hr_employee WHERE id = ? LIMIT 1');
    $stHire->execute([$employeeId]);
    $hireRaw = $stHire->fetchColumn();
    if ($hireRaw !== false && trim((string) $hireRaw) !== '') {
        $hireDateDisplay = format_date_dmY((string) $hireRaw);
    }
}

$exitUrl = nav_exit_url('hr_employee_leave_balances');
$leaveBalCssPath = app_path('assets/css/hr-employee-leave-balances.css');
$leaveBalCssUrl = app_url('assets/css/hr-employee-leave-balances.css')
    . (is_file($leaveBalCssPath) ? '?v=' . (string) filemtime($leaveBalCssPath) : '');
$leaveBalOraCssPath = app_path('assets/css/hr-leave-module-sales-ora12.css');
$leaveBalOraCssUrl = app_url('assets/css/hr-leave-module-sales-ora12.css')
    . (is_file($leaveBalOraCssPath) ? '?v=' . (string) filemtime($leaveBalOraCssPath) : '');
employee_picker_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($leaveBalCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($leaveBalOraCssUrl) ?>">
<?php employee_picker_json_script($pickerEmployees, 'hr-emp-leave-bal-picker-json'); ?>

<div class="dashboard-ora hr-leave-ora12-screen hr-emp-leave-bal-wrap hr-emp-leave-bal-page hr-leave-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">رصيد إجازات الموظفين</h1>
        <?php nav_render_screen_close('hr_employee_leave_balances'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-leave-grid-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($leaveTypes === []): ?>
            <div class="alert alert-error hr-leave-grid-flash">
                لا توجد أنواع إجازات نشطة. أضف أنواعاً من شاشة
                <a href="<?= esc(app_url('index.php?r=hr_leave_types')) ?>">إعدادات الإجازات</a> أولاً.
            </div>
        <?php endif; ?>

        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-emp-leave-bal-filter no-print">
            <input type="hidden" name="r" value="hr_employee_leave_balances">
            <div class="form-row hr-emp-leave-bal-filter-row">
                <div class="field hr-emp-leave-bal-field-employee">
                    <span class="field-label">الموظف</span>
                    <?= employee_picker_field([
                        'id' => 'hr-emp-leave-bal-employee-id',
                        'name' => 'employee_id',
                        'label' => '',
                        'compact' => true,
                        'wrapper_class' => 'hr-emp-leave-bal-picker-slot',
                        'json_id' => 'hr-emp-leave-bal-picker-json',
                        'value' => $employeeId,
                        'placeholder' => '— اختر الموظف —',
                    ]) ?>
                </div>
                <label class="field hr-emp-leave-bal-field-date">
                    <span class="field-label">من تاريخ</span>
                    <input class="input js-date-dmy" type="text" name="date_from"
                           value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off">
                </label>
                <label class="field hr-emp-leave-bal-field-date">
                    <span class="field-label">إلى تاريخ</span>
                    <input class="input js-date-dmy" type="text" name="date_to"
                           value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr" autocomplete="off">
                </label>
                <div class="hr-emp-leave-bal-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">عرض</button>
                </div>
            </div>
        </form>

        <section class="dashboard-ora-panel hr-emp-leave-bal-panel">
            <h2 class="dashboard-ora-panel__title">
                رصيد الإجازات<?= $selectedEmpLabel !== '' ? ' — ' . esc($selectedEmpLabel) : '' ?>
            </h2>
            <div class="dashboard-ora-panel__body">
                <p class="hr-emp-leave-bal-meta muted">
                    الفترة:
                    <span dir="ltr"><?= esc(format_date_dmY($dateFrom)) ?></span>
                    —
                    <span dir="ltr"><?= esc(format_date_dmY($dateTo)) ?></span>
                    &nbsp;|&nbsp;
                    تاريخ التعيين:
                    <span dir="ltr"><?= esc($hireDateDisplay) ?></span>
                </p>

                <div class="dashboard-ora-table-wrap">
                    <table class="dashboard-ora-table hr-emp-leave-bal-table">
                        <thead>
                        <tr>
                            <th>رقم الإجازة</th>
                            <th>نوع الإجازة</th>
                            <th>يجدول على مدار السنة</th>
                            <th>أيام الإعداد</th>
                            <th>رصيد الإجازات</th>
                            <th>الرصيد المستحق</th>
                            <th>Take</th>
                            <th>المتبقي</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($balanceRows === []): ?>
                            <tr><td colspan="8" class="muted">لا توجد أنواع إجازات.</td></tr>
                        <?php else: ?>
                            <?php foreach ($balanceRows as $row): ?>
                                <tr>
                                    <td dir="ltr"><?= esc((string) ($row['leave_code'] ?? '')) ?></td>
                                    <td><?= esc((string) ($row['type_name'] ?? '')) ?></td>
                                    <td class="center"><?= !empty($row['prorate_yearly']) ? '✓' : '—' ?></td>
                                    <td dir="ltr" class="num"><?= esc(number_format((float) ($row['annual_days'] ?? 0), 2, '.', '')) ?></td>
                                    <td dir="ltr" class="num"><?= esc(number_format((float) ($row['opening_balance'] ?? 0), 2, '.', '')) ?></td>
                                    <td dir="ltr" class="num"><?= esc(number_format((float) ($row['entitled_balance'] ?? 0), 2, '.', '')) ?></td>
                                    <td dir="ltr" class="num"><?= esc(number_format((float) ($row['taken_days'] ?? 0), 2, '.', '')) ?></td>
                                    <td dir="ltr" class="num"><?= esc(number_format((float) ($row['remaining'] ?? 0), 2, '.', '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="hr-emp-leave-bal-hint muted">
                    شاشة عرض فقط — الرصيد المستحق يُحسب من إعدادات الإجازة.
                    إذا كان نوع الإجازة «يجدول على مدار السنة» يُقسَّم الاستحقاق حسب تاريخ التعيين ضمن الفترة المحددة.
                </p>
            </div>
        </section>
    </div>
</div>
