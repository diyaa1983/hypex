<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_advance.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_advance_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_advances');
$editorFormId = 'hr-adv-editor-form';
$employees = hr_employee_active_list($pdo);

$advUrlForEmployee = static function (int $employeeId = 0) use ($listUrl): string {
    return $employeeId > 0 ? $listUrl . '&employee_id=' . $employeeId : $listUrl;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($advUrlForEmployee((int) ($_POST['filter_employee_id'] ?? 0)));
    }
    $act = (string) ($_POST['_action'] ?? '');
    $returnEmpId = (int) ($_POST['filter_employee_id'] ?? 0);

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_employee_advance_parse_row($_POST);

            if ($id > 0) {
                $chk = hr_employee_advance_delete_check($pdo, $id);
                $stOld = $pdo->prepare('SELECT advance_code FROM hr_employee_advance WHERE id = ? LIMIT 1');
                $stOld->execute([$id]);
                $old = $stOld->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    throw new RuntimeException('السلفة غير موجودة.');
                }
                if (!$chk['can_delete']) {
                    throw new RuntimeException('لا يمكن تعديل السلفة بعد اقتطاعها من الراتب.');
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
            redirect($advUrlForEmployee((int) $parsed['employee_id']));
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
            redirect($advUrlForEmployee($returnEmpId));
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
            redirect($advUrlForEmployee($returnEmpId));
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($advUrlForEmployee($returnEmpId));
    }
}

$flash = flash_get();
$filterEmpId = (int) ($_GET['employee_id'] ?? 0);
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

$advances = [];
if ($filterEmpId > 0) {
    $st = $pdo->prepare(
        'SELECT a.id, a.advance_code, a.employee_id, a.advance_type, a.total_amount,
                a.start_date, a.end_date, a.notes, a.status,
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
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="hr-adv-grid-page hr-adv-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextCode) ?>"
     data-filter-employee-id="<?= $filterEmpId ?>">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-adv-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php hr_ora_render_title_bar('سلف الموظفين', 'hr_employee_advances'); ?>

    <div class="hr-adv-panel hr-adv-picker-panel">
        <h2 class="hr-adv-picker-title">اختيار الموظف</h2>
        <div class="hr-adv-panel-body">
        <div class="hr-adv-picker-table-wrap">
            <table class="hr-adv-picker-table">
                <thead>
                <tr>
                    <th>اختيار الموظف</th>
                    <th>الرقم</th>
                    <th>الاسم</th>
                    <th class="hr-adv-picker-th-count">عدد السلف</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td class="hr-adv-picker-td-label muted">—</td>
                    <td>
                        <input type="text" class="input hr-adv-picker-code" id="hr-adv-picker-code"
                               value="<?= esc($filterEmpCode !== '' ? $filterEmpCode : '—') ?>"
                               readonly dir="ltr" tabindex="-1" aria-readonly="true">
                    </td>
                    <td>
                        <div class="hr-adv-ora-lov">
                            <select class="input hr-adv-ora-lov-field" id="hr-adv-filter-employee" title="اختر موظفاً لعرض سلفه">
                                <option value="" data-emp-code="" data-advance-count="0">— اختر موظفاً —</option>
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
                            <button type="button" class="hr-adv-ora-lov-btn" tabindex="-1" aria-label="اختيار الموظف" title="اختيار الموظف"></button>
                        </div>
                    </td>
                    <td class="hr-adv-picker-td-count" dir="ltr">
                        <strong id="hr-adv-picker-count"><?= $filterEmpId > 0 ? (int) $filterAdvanceCount : '—' ?></strong>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
        </div>
    </div>

    <div class="dashboard-ora-toolbar hr-adv-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-adv-btn-add"<?= $filterEmpId < 1 ? ' disabled' : '' ?>>إضافة سلفة</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-adv-btn-edit" disabled>تعديل</button>
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

            <fieldset class="hr-adv-type-fieldset">
                <legend class="field-label required">نوع السلفة</legend>
                <label class="hr-adv-type-opt">
                    <input type="radio" name="advance_type" value="once" class="hr-adv-type-radio" checked>
                    <span>سلفة لمرة واحدة</span>
                </label>
                <label class="hr-adv-type-opt">
                    <input type="radio" name="advance_type" value="long" class="hr-adv-type-radio">
                    <span>سلفة طويلة (تقسيط على عدة أشهر)</span>
                </label>
            </fieldset>

            <div class="hr-adv-editor-fields">
                <div class="field hr-adv-editor-field-code">
                    <span class="field-label">رقم السلفة</span>
                    <div class="hr-adv-code-display" id="hr-adv-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-adv-code-hint" id="hr-adv-editor-code-hint">يُولَّد تلقائياً</small>
                </div>
                <label class="field hr-adv-editor-field-emp">
                    <span class="field-label required">الموظف</span>
                    <div class="hr-adv-ora-lov">
                        <select class="input hr-adv-ora-lov-field" name="employee_id" id="hr-adv-editor-employee" required>
                            <option value="">— اختر الموظف —</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= (int) $emp['id'] ?>">
                                    <?= esc((string) ($emp['emp_code'] ?? '')) ?> — <?= esc((string) ($emp['name_ar'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="hr-adv-ora-lov-btn" tabindex="-1" aria-label="اختيار الموظف" title="اختيار الموظف"></button>
                    </div>
                </label>
                <label class="field">
                    <span class="field-label required">مبلغ السلفة</span>
                    <input class="input" type="number" name="total_amount" id="hr-adv-editor-amount" min="0.001" step="0.001" required>
                </label>

                <div class="hr-adv-dates-once" id="hr-adv-dates-once">
                    <label class="field">
                        <span class="field-label required">شهر الاقتطاع (تاريخ)</span>
                        <input class="input js-date-dmy" type="text" name="deduct_date" id="hr-adv-editor-deduct-date" placeholder="01-01-2026" autocomplete="off">
                    </label>
                </div>

                <div class="hr-adv-dates-long" id="hr-adv-dates-long" hidden>
                    <label class="field">
                        <span class="field-label required">من تاريخ</span>
                        <input class="input js-date-dmy" type="text" name="start_date" id="hr-adv-editor-start" placeholder="01-01-2026" autocomplete="off">
                    </label>
                    <label class="field">
                        <span class="field-label required">إلى تاريخ</span>
                        <input class="input js-date-dmy" type="text" name="end_date" id="hr-adv-editor-end" placeholder="30-04-2026" autocomplete="off">
                    </label>
                </div>

                <label class="field hr-adv-editor-field-notes">
                    <span class="field-label">ملاحظات</span>
                    <input class="input" type="text" name="notes" id="hr-adv-editor-notes" autocomplete="off">
                </label>
            </div>

            <div class="hr-adv-editor-actions">
                <button type="submit" class="btn btn-primary btn-sm">حفظ السلفة</button>
                <button type="button" class="btn btn-ghost btn-sm" id="hr-adv-editor-cancel">إلغاء</button>
            </div>
        </form>
        </div>
    </div>

    <div class="hr-adv-panel hr-adv-grid-panel">
        <h2 class="hr-adv-grid-title">سلف الموظف</h2>
        <div class="hr-adv-panel-body hr-adv-grid-wrap">
        <table class="hr-adv-grid-table">
            <thead>
            <tr>
                <th>رقم</th>
                <th>النوع</th>
                <th>المبلغ</th>
                <th>من تاريخ</th>
                <th>إلى تاريخ</th>
                <th>الحالة</th>
                <th>ملاحظات</th>
            </tr>
            </thead>
            <tbody id="hr-adv-grid-body">
            <?php if ($filterEmpId < 1): ?>
                <tr class="hr-adv-row hr-adv-row--empty">
                    <td colspan="7" class="muted">اختر موظفاً من القائمة أعلاه لعرض سلفه.</td>
                </tr>
            <?php elseif (!$advances): ?>
                <tr class="hr-adv-row hr-adv-row--empty">
                    <td colspan="7" class="muted">لا توجد سلف لهذا الموظف — اضغط «إضافة سلفة».</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($advances as $a):
                $rid = (int) $a['id'];
                $type = (string) ($a['advance_type'] ?? 'once');
                $delChk = hr_employee_advance_delete_check($pdo, $rid);
                $linked = !$delChk['can_delete'];
                $startDmy = format_date_dmY((string) ($a['start_date'] ?? ''));
                $endRaw = (string) ($a['end_date'] ?? '');
                $endDmy = $type === 'once' ? '—' : format_date_dmY($endRaw);
                $deductDmy = $type === 'once' ? $startDmy : '';
            ?>
                <tr class="hr-adv-row<?= $linked ? ' is-linked' : '' ?><?= (string) ($a['status'] ?? '') !== 'active' ? ' is-inactive' : '' ?>"
                    data-id="<?= $rid ?>"
                    data-code="<?= esc((string) ($a['advance_code'] ?? '')) ?>"
                    data-employee-id="<?= (int) ($a['employee_id'] ?? 0) ?>"
                    data-type="<?= esc($type) ?>"
                    data-amount="<?= esc((string) ($a['total_amount'] ?? '0')) ?>"
                    data-start="<?= esc($startDmy) ?>"
                    data-end="<?= esc($type === 'long' ? format_date_dmY($endRaw) : '') ?>"
                    data-deduct="<?= esc($deductDmy) ?>"
                    data-notes="<?= esc((string) ($a['notes'] ?? '')) ?>"
                    data-status="<?= esc((string) ($a['status'] ?? '')) ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <td dir="ltr"><?= esc((string) ($a['advance_code'] ?? '—')) ?></td>
                    <td><?= esc(hr_employee_advance_type_label($type)) ?></td>
                    <td dir="ltr" class="num"><?= esc(format_money((float) ($a['total_amount'] ?? 0))) ?></td>
                    <td dir="ltr"><?= esc($startDmy) ?></td>
                    <td dir="ltr"><?= esc($endDmy) ?></td>
                    <td><?= esc(hr_employee_advance_status_label((string) ($a['status'] ?? 'active'))) ?></td>
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
        <input type="hidden" name="id" id="hr-adv-delete-id" value="0">
    </form>
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
