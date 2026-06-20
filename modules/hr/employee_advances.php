<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_advance.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_employee_advance_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);
require_once app_path('includes/hr_employee_advance_gl.php');
hr_employee_advance_ensure_post_columns($pdo);
hr_employee_advance_gl_ensure_rule($pdo);
try {
    $pdo->exec(
        "UPDATE hr_employee_advance
         SET advance_code = CAST(id AS CHAR)
         WHERE TRIM(IFNULL(advance_code, '')) = ''"
    );
} catch (Throwable $e) {
    // ignored
}

$listUrl = app_url('index.php?r=hr_employee_advances');
$editorFormId = 'hr-adv-editor-form';
$employees = hr_employee_active_list($pdo);
$pickerEmployees = hr_employee_picker_list($pdo);
require_once app_path('includes/acc_period_lock.php');
$monthNames = acc_period_month_names_ar();

$advUrlFor = static function (int $employeeId = 0, int $year = 0, int $month = 0) use ($listUrl): string {
    $parts = [];
    if ($employeeId > 0) {
        $parts[] = 'employee_id=' . $employeeId;
    }
    if ($year >= 2000 && $month >= 1 && $month <= 12) {
        $parts[] = 'year=' . $year;
        $parts[] = 'month=' . $month;
    }

    return $parts !== [] ? $listUrl . '&' . implode('&', $parts) : $listUrl;
};

$advUrlForEmployee = static function (int $employeeId = 0) use ($advUrlFor): string {
    return $advUrlFor($employeeId, 0, 0);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($advUrlFor(
            (int) ($_POST['filter_employee_id'] ?? 0),
            (int) ($_POST['filter_year'] ?? 0),
            (int) ($_POST['filter_month'] ?? 0)
        ));
    }
    $act = (string) ($_POST['_action'] ?? '');
    $returnEmpId = (int) ($_POST['filter_employee_id'] ?? 0);
    $returnYear = (int) ($_POST['filter_year'] ?? 0);
    $returnMonth = (int) ($_POST['filter_month'] ?? 0);
    $redirectAfter = static function () use ($advUrlFor, $returnEmpId, $returnYear, $returnMonth): void {
        redirect($advUrlFor($returnEmpId, $returnYear, $returnMonth));
    };

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_employee_advance_parse_row($_POST, $pdo);

            if ($id > 0) {
                $editChk = hr_employee_advance_edit_check($pdo, $id);
                $stOld = $pdo->prepare('SELECT advance_code FROM hr_employee_advance WHERE id = ? LIMIT 1');
                $stOld->execute([$id]);
                $old = $stOld->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    throw new RuntimeException('السلفة غير موجودة.');
                }
                if (!$editChk['can_edit']) {
                    throw new RuntimeException((string) ($editChk['message'] ?? 'لا يمكن تعديل السلفة.'));
                }
                $code = trim((string) ($old['advance_code'] ?? ''));
                if ($code === '' || !ctype_digit($code)) {
                    $code = hr_employee_advance_next_code($pdo);
                }
                $st = $pdo->prepare(
                    'UPDATE hr_employee_advance SET advance_code = ?, employee_id = ?, advance_type = ?,
                     total_amount = ?, start_date = ?, end_date = ?, notes = ?, status = \'active\'
                     WHERE id = ?'
                );
                $st->execute([
                    $code,
                    $parsed['employee_id'],
                    $parsed['advance_type'],
                    $parsed['total_amount'],
                    $parsed['start_date'],
                    $parsed['end_date'],
                    $parsed['notes'],
                    $id,
                ]);
                flash_set('success', 'تم حفظ تعديلات السلفة.');
            } else {
                $code = hr_employee_advance_next_code($pdo);
                $st = $pdo->prepare(
                    'INSERT INTO hr_employee_advance (advance_code, employee_id, advance_type, total_amount,
                     start_date, end_date, notes, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')'
                );
                $st->execute([
                    $code,
                    $parsed['employee_id'],
                    $parsed['advance_type'],
                    $parsed['total_amount'],
                    $parsed['start_date'],
                    $parsed['end_date'],
                    $parsed['notes'],
                ]);
                flash_set('success', 'تم إضافة السلفة برقم ' . $code . '.');
            }
            redirect($advUrlFor((int) $parsed['employee_id'], $returnYear, $returnMonth));
        }

        if ($act === 'cancel_advance') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_employee_advance_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن إلغاء السلفة.'));
                }
                $pdo->prepare('UPDATE hr_employee_advance SET status = \'cancelled\' WHERE id = ?')
                    ->execute([$id]);
                flash_set('success', 'تم إلغاء السلفة.');
            }
            $redirectAfter();
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_employee_advance_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف السلفة.'));
                }
                $pdo->prepare('DELETE FROM hr_employee_advance WHERE id = ?')->execute([$id]);
                flash_set('success', 'تم حذف السلفة.');
            }
            $redirectAfter();
        }

        if ($act === 'post_advance') {
            if (!user_can_action('action_post_employee_advance')) {
                throw new RuntimeException('ليس لديك صلاحية ترحيل السلفة.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_advance_post($pdo, $id);
                flash_set('success', 'تم ترحيل السلفة محاسبياً.');
            }
            $redirectAfter();
        }

        if ($act === 'unpost_advance') {
            if (!user_can_action('action_unpost_employee_advance')) {
                throw new RuntimeException('ليس لديك صلاحية فك ترحيل السلفة.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_advance_unpost($pdo, $id);
                flash_set('success', 'تم فك ترحيل السلفة وإلغاء أثرها المحاسبي.');
            }
            $redirectAfter();
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        $redirectAfter();
    }
}

$flash = flash_get();
$filterEmpId = (int) ($_GET['employee_id'] ?? 0);
$filterYear = (int) ($_GET['year'] ?? 0);
$filterMonth = (int) ($_GET['month'] ?? 0);
$monthFilterActive = $filterYear >= 2000 && $filterMonth >= 1 && $filterMonth <= 12;
if (!$monthFilterActive) {
    $filterYear = 0;
    $filterMonth = 0;
}
$filterEmpCode = '';
$filterEmpName = '';
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
    }
}

$advanceCountByEmp = [];
try {
    $stCnt = $pdo->query(
        'SELECT employee_id, COUNT(*) AS cnt FROM hr_employee_advance GROUP BY employee_id'
    );
    foreach ($stCnt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $advanceCountByEmp[(int) ($row['employee_id'] ?? 0)] = (int) ($row['cnt'] ?? 0);
    }
} catch (Throwable $e) {
    $advanceCountByEmp = [];
}
$filterAdvanceCount = $filterEmpId > 0 ? (int) ($advanceCountByEmp[$filterEmpId] ?? 0) : 0;
$filterMonthAdvanceCount = $monthFilterActive
    ? hr_employee_advances_count_for_period($pdo, $filterYear, $filterMonth, $filterEmpId)
    : 0;

$advances = [];
$monthView = $monthFilterActive;
if ($monthFilterActive) {
    $advances = hr_employee_advances_for_period($pdo, $filterYear, $filterMonth, $filterEmpId);
} elseif ($filterEmpId > 0) {
    $st = $pdo->prepare(
        'SELECT a.id, a.advance_code, a.employee_id, a.advance_type, a.total_amount,
                a.start_date, a.end_date, a.notes, a.status, a.is_posted,
                e.emp_code, e.name_ar AS emp_name
         FROM hr_employee_advance a
         INNER JOIN hr_employee e ON e.id = a.employee_id
         WHERE a.employee_id = ?
         ORDER BY CAST(a.advance_code AS UNSIGNED) ASC, a.id DESC'
    );
    $st->execute([$filterEmpId]);
    $advances = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$nextCode = hr_employee_advance_next_code($pdo);
$exitUrl = nav_exit_url('hr_employee_advances');

$cssPath = app_path('assets/css/hr-employee-advances.css');
$cssUrl = app_url('assets/css/hr-employee-advances.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$jsPath = app_path('assets/js/hr-employee-advances.js');
$jsUrl = app_url('assets/js/hr-employee-advances.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<?php employee_picker_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php employee_picker_json_script($pickerEmployees, 'hr-adv-picker-json'); ?>

<div class="hr-adv-grid-page hr-adv-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextCode) ?>"
     data-filter-employee-id="<?= $filterEmpId ?>"
     data-filter-year="<?= $filterYear ?>"
     data-filter-month="<?= $filterMonth ?>"
     data-month-filter-active="<?= $monthFilterActive ? '1' : '0' ?>"
     data-filter-month-advance-count="<?= (int) $filterMonthAdvanceCount ?>">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-adv-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php hr_ora_render_title_bar('سلف الموظفين', 'hr_employee_advances'); ?>

    <?php
    $monthShortNames = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];
    $displayYear = $monthFilterActive ? $filterYear : (int) date('Y');
    $displayMonth = $monthFilterActive ? $filterMonth : (int) date('n');
    $displayAdvanceCount = $monthFilterActive
        ? (int) $filterMonthAdvanceCount
        : ($filterEmpId > 0 ? (int) $filterAdvanceCount : 0);
    ?>

    <div class="hr-adv-panel hr-adv-filter-panel">
        <h2 class="hr-adv-picker-title">البحث — موظف / شهر</h2>
        <div class="hr-adv-panel-body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-adv-filter-form" id="hr-adv-month-filter-form">
                <input type="hidden" name="r" value="hr_employee_advances">
                <input type="hidden" name="employee_id" id="hr-adv-month-filter-employee" value="<?= $filterEmpId > 0 ? $filterEmpId : '' ?>">
                <input type="hidden" name="month" id="hr-adv-filter-month" value="<?= $displayMonth ?>">
                <div class="hr-adv-picker-table-wrap">
                    <table class="hr-adv-picker-table hr-adv-filter-table">
                        <thead>
                        <tr>
                            <th class="hr-adv-th-code">رقم الموظف</th>
                            <th class="hr-adv-th-name">اسم الموظف</th>
                            <th class="hr-adv-th-period">الشهر / السنة</th>
                            <th class="hr-adv-picker-th-count">عدد السلف</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td>
                                <input type="text" class="input hr-adv-picker-code" id="hr-adv-picker-code"
                                       value="<?= esc($filterEmpCode !== '' ? $filterEmpCode : '—') ?>"
                                       dir="ltr" inputmode="numeric" autocomplete="off" placeholder="رقم">
                            </td>
                            <td class="hr-adv-picker-cell-name">
                                <?= employee_picker_field([
                                    'id' => 'hr-adv-picker-id',
                                    'label' => '',
                                    'compact' => true,
                                    'wrapper_class' => 'hr-adv-picker-slot',
                                    'json_id' => 'hr-adv-picker-json',
                                    'manual_bind' => true,
                                    'value' => $filterEmpId,
                                    'placeholder' => $monthFilterActive && $filterEmpId === 0
                                        ? '— جميع الموظفين —'
                                        : 'اختر موظفاً — أو جميع الموظفين',
                                ]) ?>
                                <select class="input hr-adv-filter-select-sr" id="hr-adv-filter-employee" title="اختر موظفاً لعرض سلفه" hidden tabindex="-1" aria-hidden="true">
                                    <option value="" data-emp-code="" data-advance-count="0"<?= !$monthFilterActive && $filterEmpId === 0 ? ' selected' : '' ?>>— اختر موظفاً —</option>
                                    <option value="0" data-emp-code="" data-advance-count="<?= (int) $filterMonthAdvanceCount ?>"<?= $monthFilterActive && $filterEmpId === 0 ? ' selected' : '' ?>>— جميع الموظفين —</option>
                                    <?php foreach ($employees as $emp):
                                        $eid = (int) ($emp['id'] ?? 0);
                                        $ecode = trim((string) ($emp['emp_code'] ?? ''));
                                        $ename = (string) ($emp['name_ar'] ?? '');
                                        $ecnt = (int) ($advanceCountByEmp[$eid] ?? 0);
                                    ?>
                                        <option value="<?= $eid ?>"
                                                data-emp-code="<?= esc($ecode) ?>"
                                                data-advance-count="<?= $ecnt ?>"
                                            <?= $filterEmpId === $eid ? 'selected' : '' ?>>
                                            <?= esc($ename !== '' ? $ename : '—') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="hr-adv-picker-cell-period">
                                <div class="hr-adv-period-wrap">
                                    <label class="sr-only" for="hr-adv-filter-year">السنة</label>
                                    <input class="input hr-adv-year-input" type="number" name="year" id="hr-adv-filter-year"
                                           min="2000" max="2100" value="<?= $displayYear ?>"
                                           dir="ltr" required aria-label="السنة">
                                    <div class="hr-adv-month-strip" role="group" aria-label="اختر الشهر">
                                        <?php for ($m = 1; $m <= 12; $m++):
                                            $isSelected = $monthFilterActive && $filterMonth === $m;
                                        ?>
                                            <button type="button"
                                                    class="hr-adv-month-chip<?= $isSelected ? ' is-active' : '' ?>"
                                                    data-month="<?= $m ?>"
                                                    title="<?= esc($monthShortNames[$m] ?? (string) $m) ?>"
                                                    aria-pressed="<?= $isSelected ? 'true' : 'false' ?>">
                                                <span class="hr-adv-month-chip-num" dir="ltr"><?= sprintf('%02d', $m) ?></span>
                                                <span class="hr-adv-month-chip-label"><?= esc($monthShortNames[$m] ?? (string) $m) ?></span>
                                            </button>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="hr-adv-picker-td-count" dir="ltr">
                                <strong id="hr-adv-picker-count"><?= ($monthFilterActive || $filterEmpId > 0) ? $displayAdvanceCount : '—' ?></strong>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <?php if ($monthFilterActive): ?>
                    <p class="hr-adv-filter-summary muted">
                        عرض
                        <strong dir="ltr"><?= (int) $filterMonthAdvanceCount ?></strong>
                        سلفة —
                        <strong><?= esc(($monthNames[$filterMonth] ?? (string) $filterMonth) . ' / ' . (string) $filterYear) ?></strong>
                        <?= $filterEmpId > 0 ? (' — ' . esc($filterEmpName)) : ' — جميع الموظفين' ?>
                        <a class="hr-adv-filter-clear" href="<?= esc($advUrlFor($filterEmpId, 0, 0)) ?>">إلغاء فلتر الشهر</a>
                    </p>
                <?php else: ?>
                    <p class="hr-adv-picker-help muted">اختر موظفاً أو <strong>جميع الموظفين</strong>، و/أو اضغط شهراً من الشريط لعرض السلف.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="dashboard-ora-toolbar hr-adv-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-adv-btn-add"<?= ($filterEmpId < 1 && !$monthFilterActive) ? ' disabled' : '' ?>>إضافة سلفة</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-adv-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-adv-btn-delete" disabled>حذف السلفة</button>
    </div>

    <div class="hr-adv-panel hr-adv-editor" id="hr-adv-editor" hidden>
        <div class="hr-adv-editor-head">
            <h2 class="hr-adv-editor-title" id="hr-adv-editor-title">إضافة سلفة</h2>
            <button type="button" class="btn btn-ghost btn-sm hr-adv-editor-close" id="hr-adv-editor-close" aria-label="إغلاق">×</button>
        </div>
        <div class="hr-adv-panel-body">
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-adv-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_one">
            <input type="hidden" name="id" id="hr-adv-editor-id" value="0">
            <input type="hidden" name="filter_employee_id" id="hr-adv-filter-employee-id" value="<?= $filterEmpId ?>">
            <input type="hidden" name="filter_year" id="hr-adv-filter-year-hidden" value="<?= $filterYear ?>">
            <input type="hidden" name="filter_month" id="hr-adv-filter-month-hidden" value="<?= $filterMonth ?>">

            <table class="hr-adv-editor-table">
                <tbody>
                <tr>
                    <th class="required">نوع السلفة</th>
                    <td colspan="3">
                        <fieldset class="hr-adv-type-fieldset">
                            <label class="hr-adv-type-opt">
                                <input type="radio" name="advance_type" value="once" class="hr-adv-type-radio" checked>
                                <span>سلفة لمرة واحدة</span>
                            </label>
                            <label class="hr-adv-type-opt">
                                <input type="radio" name="advance_type" value="long" class="hr-adv-type-radio">
                                <span>سلفة طويلة (تقسيط على عدة أشهر)</span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th>رقم السلفة</th>
                    <td>
                        <div class="hr-adv-code-display" id="hr-adv-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                        <small class="hr-adv-code-hint" id="hr-adv-editor-code-hint">يُولَّد تلقائياً</small>
                    </td>
                    <th class="required">الموظف</th>
                    <td class="hr-adv-editor-cell-emp">
                        <div class="hr-adv-ora-lov">
                            <input class="input hr-adv-ora-lov-smart-input" type="search" id="hr-adv-editor-employee-smart"
                                   autocomplete="off" spellcheck="false" placeholder="ابحث بالاسم أو الرقم...">
                            <select class="input hr-adv-ora-lov-field" name="employee_id" id="hr-adv-editor-employee" required hidden tabindex="-1" aria-hidden="true">
                                <option value="">— اختر الموظف —</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= (int) $emp['id'] ?>">
                                        <?= esc((string) ($emp['emp_code'] ?? '')) ?> — <?= esc((string) ($emp['name_ar'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="hr-adv-ora-lov-btn" id="hr-adv-editor-employee-toggle" aria-label="اختيار الموظف" title="اختيار الموظف"></button>
                        </div>
                        <div class="hr-adv-emp-pick-list" id="hr-adv-editor-employee-list" hidden></div>
                    </td>
                </tr>
                <tr>
                    <th class="required">مبلغ السلفة</th>
                    <td>
                        <input class="input" type="number" name="total_amount" id="hr-adv-editor-amount" min="0.001" step="0.001" required>
                    </td>
                    <th class="required">شهر الاقتطاع</th>
                    <td id="hr-adv-dates-once">
                        <input class="input js-date-dmy" type="text" name="deduct_date" id="hr-adv-editor-deduct-date" placeholder="01-01-2026" autocomplete="off">
                    </td>
                </tr>
                <tr id="hr-adv-dates-long" hidden>
                    <th class="required">من تاريخ</th>
                    <td>
                        <input class="input js-date-dmy" type="text" name="start_date" id="hr-adv-editor-start" placeholder="01-01-2026" autocomplete="off">
                    </td>
                    <th class="required">إلى تاريخ</th>
                    <td>
                        <input class="input js-date-dmy" type="text" name="end_date" id="hr-adv-editor-end" placeholder="30-04-2026" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th>ملاحظات</th>
                    <td colspan="3">
                        <input class="input" type="text" name="notes" id="hr-adv-editor-notes" autocomplete="off">
                    </td>
                </tr>
                </tbody>
            </table>

            <div class="hr-adv-editor-actions">
                <button type="submit" class="btn btn-primary btn-sm">حفظ السلفة</button>
                <button type="button" class="btn btn-ghost btn-sm" id="hr-adv-editor-cancel">إلغاء</button>
            </div>
        </form>
        </div>
    </div>

    <div class="hr-adv-panel hr-adv-grid-panel">
        <h2 class="hr-adv-grid-title">
            <?php if ($monthFilterActive): ?>
                سلف شهر <?= esc(($monthNames[$filterMonth] ?? (string) $filterMonth) . ' / ' . (string) $filterYear) ?>
                <?= $filterEmpId > 0 ? '— ' . esc($filterEmpName) : '— جميع الموظفين' ?>
            <?php else: ?>
                سلف الموظف
            <?php endif; ?>
        </h2>
        <div class="hr-adv-panel-body hr-adv-grid-wrap">
        <table class="hr-adv-grid-table">
            <thead>
            <tr>
                <?php if ($monthView): ?>
                    <th>رقم الموظف</th>
                    <th>اسم الموظف</th>
                <?php endif; ?>
                <th>رقم السلفة</th>
                <th>النوع</th>
                <th>المبلغ</th>
                <th>من تاريخ</th>
                <th>إلى تاريخ</th>
                <th>الحالة</th>
                <th>ملاحظات</th>
            </tr>
            </thead>
            <tbody id="hr-adv-grid-body">
            <?php
            $emptyColspan = $monthView ? 9 : 7;
            if (!$monthFilterActive && $filterEmpId < 1): ?>
                <tr class="hr-adv-row hr-adv-row--empty">
                    <td colspan="<?= $emptyColspan ?>" class="muted">اختر موظفاً أو شهراً من الفلاتر أعلاه لعرض السلف.</td>
                </tr>
            <?php elseif (!$advances): ?>
                <tr class="hr-adv-row hr-adv-row--empty">
                    <td colspan="<?= $emptyColspan ?>" class="muted">
                        <?= $monthFilterActive
                            ? 'لا توجد سلف في هذا الشهر' . ($filterEmpId > 0 ? ' لهذا الموظف' : '') . '.'
                            : 'لا توجد سلف لهذا الموظف — اضغط «إضافة سلفة».' ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($advances as $a):
                $rid = (int) $a['id'];
                $advanceCode = trim((string) ($a['advance_code'] ?? ''));
                if ($advanceCode === '') {
                    $advanceCode = (string) $rid;
                }
                $type = (string) ($a['advance_type'] ?? 'once');
                $delChk = hr_employee_advance_delete_check($pdo, $rid);
                $editChk = hr_employee_advance_edit_check($pdo, $rid);
                $unpostChk = hr_employee_advance_unpost_check($pdo, $rid);
                $hasDeduction = hr_employee_advance_total_deducted($pdo, $rid) > 0.0005;
                $linked = $hasDeduction;
                $isPosted = (int) ($a['is_posted'] ?? 0) === 1;
                $startDmy = format_date_dmY((string) ($a['start_date'] ?? ''));
                $endRaw = (string) ($a['end_date'] ?? '');
                $endDmy = $type === 'once' ? '—' : format_date_dmY($endRaw);
                $deductDmy = $type === 'once' ? $startDmy : '';
            ?>
                <tr class="hr-adv-row<?= $linked ? ' is-linked' : '' ?><?= $isPosted ? ' is-posted' : '' ?><?= (string) ($a['status'] ?? '') !== 'active' ? ' is-inactive' : '' ?>"
                    data-id="<?= $rid ?>"
                    data-code="<?= esc($advanceCode) ?>"
                    data-employee-id="<?= (int) ($a['employee_id'] ?? 0) ?>"
                    data-type="<?= esc($type) ?>"
                    data-amount="<?= esc((string) ($a['total_amount'] ?? '0')) ?>"
                    data-start="<?= esc($startDmy) ?>"
                    data-end="<?= esc($type === 'long' ? format_date_dmY($endRaw) : '') ?>"
                    data-deduct="<?= esc($deductDmy) ?>"
                    data-notes="<?= esc((string) ($a['notes'] ?? '')) ?>"
                    data-status="<?= esc((string) ($a['status'] ?? '')) ?>"
                    data-posted="<?= $isPosted ? '1' : '0' ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
                    data-edit-msg="<?= esc((string) ($editChk['message'] ?? '')) ?>"
                    data-unpost-msg="<?= esc((string) ($unpostChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <?php if ($monthView): ?>
                        <td dir="ltr"><?= esc((string) ($a['emp_code'] ?? '—')) ?></td>
                        <td><?= esc((string) ($a['emp_name'] ?? '—')) ?></td>
                    <?php endif; ?>
                    <td dir="ltr"><?= esc($advanceCode) ?></td>
                    <td><?= esc(hr_employee_advance_type_label($type)) ?></td>
                    <td dir="ltr" class="num"><?= esc(format_money((float) ($a['total_amount'] ?? 0))) ?></td>
                    <td dir="ltr"><?= esc($startDmy) ?></td>
                    <td dir="ltr"><?= esc($endDmy) ?></td>
                    <td><?= esc(hr_employee_advance_display_status($isPosted ? 1 : 0, (string) ($a['status'] ?? 'active'))) ?></td>
                    <td><?= esc((string) ($a['notes'] ?? '')) !== '' ? esc((string) $a['notes']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-adv-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="filter_employee_id" id="hr-adv-delete-filter-employee-id" value="<?= $filterEmpId ?>">
        <input type="hidden" name="filter_year" value="<?= $filterYear ?>">
        <input type="hidden" name="filter_month" value="<?= $filterMonth ?>">
        <input type="hidden" name="id" id="hr-adv-delete-id" value="0">
    </form>
    <form method="post" action="<?= esc($listUrl) ?>" id="hr-adv-post-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="post_advance">
        <input type="hidden" name="filter_employee_id" id="hr-adv-post-filter-employee-id" value="<?= $filterEmpId ?>">
        <input type="hidden" name="filter_year" value="<?= $filterYear ?>">
        <input type="hidden" name="filter_month" value="<?= $filterMonth ?>">
        <input type="hidden" name="id" id="hr-adv-post-id" value="0">
    </form>
    <form method="post" action="<?= esc($listUrl) ?>" id="hr-adv-unpost-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="unpost_advance">
        <input type="hidden" name="filter_employee_id" id="hr-adv-unpost-filter-employee-id" value="<?= $filterEmpId ?>">
        <input type="hidden" name="filter_year" value="<?= $filterYear ?>">
        <input type="hidden" name="filter_month" value="<?= $filterMonth ?>">
        <input type="hidden" name="id" id="hr-adv-unpost-id" value="0">
    </form>
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
