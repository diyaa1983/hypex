<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_department_delete.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_department_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_departments');
$editorFormId = 'hr-dept-editor-form';

/**
 * @return array{code:string,name:string,desc:?string,manager:?int,active:int}
 */
function hr_dept_parse_row(PDO $pdo, array $row, int $id): array
{
    $name = trim((string) ($row['name_ar'] ?? ''));
    $description = trim((string) ($row['description'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('اسم القسم مطلوب.');
    }

    $managerVal = null;
    $isActive = !empty($row['is_active']) ? 1 : 0;
    $code = '';

    if ($id > 0) {
        $stMgr = $pdo->prepare('SELECT dept_code, manager_id FROM hr_department WHERE id = ? LIMIT 1');
        $stMgr->execute([$id]);
        $cur = $stMgr->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('القسم غير موجود.');
        }
        $code = trim((string) ($cur['dept_code'] ?? ''));
        if ($code === '' || !ctype_digit($code)) {
            $code = hr_department_next_code($pdo);
        }
        $mgrRaw = $cur['manager_id'] ?? null;
        $managerVal = $mgrRaw !== null && (int) $mgrRaw > 0 ? (int) $mgrRaw : null;
    } else {
        $code = hr_department_next_code($pdo);
        if (!array_key_exists('is_active', $row)) {
            $isActive = 1;
        }
    }

    $stChk = $pdo->prepare('SELECT id FROM hr_department WHERE dept_code = ? AND id <> ? LIMIT 1');
    $stChk->execute([$code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('تعذر توليد رقم قسم تسلسلي، أعد المحاولة.');
    }

    return [
        'code' => $code,
        'name' => $name,
        'desc' => $description !== '' ? $description : null,
        'manager' => $managerVal,
        'active' => $isActive,
    ];
}

function hr_dept_name_taken(PDO $pdo, string $name, int $excludeId = 0): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    hr_department_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM hr_department WHERE name_ar = ? AND id <> ? LIMIT 1');
    $st->execute([$name, $excludeId]);
    return (bool) $st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_dept_parse_row($pdo, $_POST, $id);
            if ($id > 0 && hr_dept_name_taken($pdo, $parsed['name'], $id)) {
                throw new RuntimeException('اسم القسم مستخدم لسجل آخر.');
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_department SET dept_code = ?, name_ar = ?, manager_id = ?, description = ?, is_active = ?
                     WHERE id = ?'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['manager'], $parsed['desc'],
                    $parsed['active'], $id,
                ]);
                flash_set('success', 'تم حفظ تعديلات القسم.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_department (dept_code, name_ar, manager_id, description, is_active)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['manager'], $parsed['desc'], $parsed['active'],
                ]);
                flash_set('success', 'تم إضافة القسم برقم ' . $parsed['code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'save_batch') {
            $raw = (string) ($_POST['pending_items_json'] ?? '[]');
            $items = json_decode($raw, true);
            if (!is_array($items) || $items === []) {
                throw new RuntimeException('أضف قسماً واحداً على الأقل ثم احفظ من شريط الأدوات.');
            }
            $defaultActive = !empty($_POST['is_active']) ? 1 : 0;
            $saved = 0;
            $pdo->beginTransaction();
            try {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = trim((string) ($item['name'] ?? $item['name_ar'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    if (hr_dept_name_taken($pdo, $name, 0)) {
                        throw new RuntimeException('القسم «' . $name . '» موجود مسبقاً.');
                    }
                    $desc = trim((string) ($item['description'] ?? ''));
                    $parsed = hr_dept_parse_row($pdo, [
                        'name_ar' => $name,
                        'description' => $desc,
                        'is_active' => $defaultActive,
                    ], 0);
                    $st = $pdo->prepare(
                        'INSERT INTO hr_department (dept_code, name_ar, manager_id, description, is_active)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        $parsed['code'], $parsed['name'], $parsed['manager'], $parsed['desc'], $parsed['active'],
                    ]);
                    $saved++;
                }
                $pdo->commit();
            } catch (Throwable $eBatch) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $eBatch;
            }
            if ($saved < 1) {
                throw new RuntimeException('أضف قسماً واحداً على الأقل ثم احفظ من شريط الأدوات.');
            }
            flash_set('success', $saved === 1
                ? 'تم حفظ قسم واحد.'
                : 'تم حفظ ' . $saved . ' أقسام.');
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE hr_department SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $id]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل القسم.' : 'تم إيقاف القسم.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_department_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف هذا القسم.'));
                }
                $st = $pdo->prepare('DELETE FROM hr_department WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف القسم.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();

$st = $pdo->query(
    'SELECT id, dept_code, name_ar, description, is_active FROM hr_department
     ORDER BY CAST(dept_code AS UNSIGNED) ASC, id ASC'
);
$departments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$nextDeptCode = hr_department_next_code($pdo);

$cssPath = app_path('assets/css/hr-departments.css');
$cssUrl = app_url('assets/css/hr-departments.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-departments-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-departments-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-departments.js');
$jsUrl = app_url('assets/js/hr-departments.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_departments');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-dept-ora12-screen hr-dept-wrap hr-dept-grid-page hr-dept-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextDeptCode) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">أقسام الموظفين</h1>
        <?php nav_render_screen_close('hr_departments'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-dept-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-dept-top-bar hr-dept-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-dept-btn-add">قسم جديد</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-dept-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-dept-btn-delete" disabled
                title="حدد قسماً غير مرتبط">حذف</button>
    </div>
    <p class="hr-dept-toolbar-hint muted">أضف الأقسام للجدول ثم احفظ من شريط الأدوات. لا يُحذف القسم المرتبط بموظف أو مسمى وظيفي.</p>

    <section class="dashboard-ora-panel hr-dept-editor-panel hr-dept-editor" id="hr-dept-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-dept-editor-close" id="hr-dept-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-dept-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" id="hr-dept-form-action" value="save_one">
            <input type="hidden" name="pending_items_json" id="hr-dept-pending-json" value="[]">
            <input type="hidden" name="id" id="hr-dept-editor-id" value="0">
            <section class="dashboard-ora-panel hr-dept-section">
            <h2 class="dashboard-ora-panel__title">بيانات القسم</h2>
            <div class="dashboard-ora-panel__body">
            <div class="hr-dept-editor-fields">
                <div class="field hr-dept-editor-field-code">
                    <span class="field-label">رقم القسم</span>
                    <div class="hr-dept-code-display" id="hr-dept-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-dept-code-hint" id="hr-dept-editor-code-hint">يُولَّد تلقائياً عند الحفظ من شريط الأدوات</small>
                </div>
                <div class="field hr-dept-editor-field-name">
                    <span class="field-label required">اسم القسم</span>
                    <div class="hr-dept-name-add-row">
                        <input class="input" type="text" name="name_ar" id="hr-dept-editor-name" autocomplete="off"
                               placeholder="اكتب اسم القسم ثم اضغط إضافة">
                        <button type="button" class="btn btn-primary btn-sm" id="hr-dept-btn-inline-add">إضافة</button>
                    </div>
                </div>
                <label class="field hr-dept-editor-field-notes">
                    <span class="field-label">ملاحظات</span>
                    <input class="input" type="text" name="description" id="hr-dept-editor-notes" autocomplete="off">
                </label>
                <label class="field hr-dept-editor-field-active">
                    <span class="field-label">تنشيط</span>
                    <span class="hr-dept-active-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="hr-dept-editor-active" value="1" checked>
                        <span>قسم مفعّل</span>
                    </span>
                </label>
            </div>
            <p class="hr-dept-editor-hint muted" id="hr-dept-editor-hint">
                أضف كل الأقسام المطلوبة للجدول، ثم اضغط «حفظ» في شريط الأدوات.
            </p>
            </div>
            </section>
        </form>
        </div>
    </section>

    <section class="dashboard-ora-panel hr-dept-grid-panel">
        <h2 class="dashboard-ora-panel__title">قائمة الأقسام</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="dashboard-ora-table-wrap hr-dept-grid-wrap">
        <table class="dashboard-ora-table hr-dept-grid-table">
            <thead>
            <tr>
                <th>رقم القسم</th>
                <th>اسم القسم</th>
                <th>ملاحظات</th>
                <th class="hr-dept-col-active">تنشيط</th>
            </tr>
            </thead>
            <tbody id="hr-dept-pending-body"></tbody>
            <tbody id="hr-dept-grid-body">
            <?php if (!$departments): ?>
                <tr class="hr-dept-row hr-dept-row--empty" id="hr-dept-row-empty">
                    <td colspan="4" class="muted">لا توجد أقسام بعد — اضغط «قسم جديد».</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($departments as $d):
                $rid = (int) $d['id'];
                $code = (string) ($d['dept_code'] ?? '');
                $name = (string) ($d['name_ar'] ?? '');
                $desc = (string) ($d['description'] ?? '');
                $isActive = (int) ($d['is_active'] ?? 1) === 1;
                $delChk = hr_department_delete_check($pdo, $rid);
                $linked = !$delChk['can_delete'];
            ?>
                <tr class="hr-dept-row<?= $linked ? ' is-linked' : '' ?><?= !$isActive ? ' is-inactive' : '' ?>"
                    data-id="<?= $rid ?>"
                    data-code="<?= esc($code) ?>"
                    data-name="<?= esc($name) ?>"
                    data-description="<?= esc($desc) ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <td><?= esc($code !== '' ? $code : '—') ?></td>
                    <td><?= esc($name !== '' ? $name : '—') ?></td>
                    <td><?= esc($desc !== '' ? $desc : '—') ?></td>
                    <td class="hr-dept-cell-active">
                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-dept-toggle-form">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <input type="hidden" name="is_active" value="0">
                            <label class="hr-dept-toggle-chk" title="<?= $isActive ? 'إيقاف القسم' : 'تفعيل القسم' ?>">
                                <input type="checkbox" name="is_active" value="1" class="hr-dept-active-cb"
                                    <?= $isActive ? 'checked' : '' ?>>
                                <span class="sr-only">تنشيط</span>
                            </label>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </section>

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-dept-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-dept-delete-id" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
