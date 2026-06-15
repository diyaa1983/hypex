<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_job_title_delete.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_job_title_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_job_titles');
$editorFormId = 'hr-jt-editor-form';

/**
 * @return array{code:string,name:string,desc:?string,dept:?int,active:int}
 */
function hr_jt_parse_row(PDO $pdo, array $row, int $id): array
{
    $name = trim((string) ($row['name_ar'] ?? ''));
    $description = trim((string) ($row['description'] ?? ''));
    $deptId = (int) ($row['department_id'] ?? 0);
    $deptVal = $deptId > 0 ? $deptId : null;
    $isActive = !empty($row['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new RuntimeException('اسم المسمى الوظيفي مطلوب.');
    }

    if ($deptVal !== null) {
        $stD = $pdo->prepare('SELECT id FROM hr_department WHERE id = ? LIMIT 1');
        $stD->execute([$deptVal]);
        if (!$stD->fetchColumn()) {
            throw new RuntimeException('القسم المحدد غير موجود.');
        }
    }

    $code = '';
    if ($id > 0) {
        $stCur = $pdo->prepare('SELECT title_code FROM hr_job_title WHERE id = ? LIMIT 1');
        $stCur->execute([$id]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('المسمى الوظيفي غير موجود.');
        }
        $code = trim((string) ($cur['title_code'] ?? ''));
        if ($code === '' || !ctype_digit($code)) {
            $code = hr_job_title_next_code($pdo);
        }
    } else {
        $code = hr_job_title_next_code($pdo);
        if (!array_key_exists('is_active', $row)) {
            $isActive = 1;
        }
    }

    $stChk = $pdo->prepare('SELECT id FROM hr_job_title WHERE title_code = ? AND id <> ? LIMIT 1');
    $stChk->execute([$code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('تعذر توليد رقم مسمى تسلسلي، أعد المحاولة.');
    }

    return [
        'code' => $code,
        'name' => $name,
        'desc' => $description !== '' ? $description : null,
        'dept' => $deptVal,
        'active' => $isActive,
    ];
}

function hr_jt_name_taken(PDO $pdo, string $name, int $excludeId = 0): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    hr_job_title_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM hr_job_title WHERE name_ar = ? AND id <> ? LIMIT 1');
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
            $parsed = hr_jt_parse_row($pdo, $_POST, $id);
            if ($id > 0 && hr_jt_name_taken($pdo, $parsed['name'], $id)) {
                throw new RuntimeException('اسم المسمى مستخدم لسجل آخر.');
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_job_title SET title_code = ?, name_ar = ?, department_id = ?,
                     description = ?, is_active = ? WHERE id = ?'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['dept'], $parsed['desc'],
                    $parsed['active'], $id,
                ]);
                flash_set('success', 'تم حفظ تعديلات المسمى الوظيفي.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_job_title (title_code, name_ar, department_id, description, is_active)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['dept'], $parsed['desc'], $parsed['active'],
                ]);
                flash_set('success', 'تم إضافة المسمى الوظيفي برقم ' . $parsed['code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'save_batch') {
            $raw = (string) ($_POST['pending_items_json'] ?? '[]');
            $items = json_decode($raw, true);
            if (!is_array($items) || $items === []) {
                throw new RuntimeException('أضف مسمى واحداً على الأقل ثم احفظ من شريط الأدوات.');
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
                    if (hr_jt_name_taken($pdo, $name, 0)) {
                        throw new RuntimeException('المسمى «' . $name . '» موجود مسبقاً.');
                    }
                    $desc = trim((string) ($item['description'] ?? ''));
                    $deptId = (int) ($item['department_id'] ?? 0);
                    $parsed = hr_jt_parse_row($pdo, [
                        'name_ar' => $name,
                        'description' => $desc,
                        'department_id' => $deptId,
                        'is_active' => $defaultActive,
                    ], 0);
                    $st = $pdo->prepare(
                        'INSERT INTO hr_job_title (title_code, name_ar, department_id, description, is_active)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        $parsed['code'], $parsed['name'], $parsed['dept'], $parsed['desc'], $parsed['active'],
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
                throw new RuntimeException('أضف مسمى واحداً على الأقل ثم احفظ من شريط الأدوات.');
            }
            flash_set('success', $saved === 1
                ? 'تم حفظ مسمى واحد.'
                : 'تم حفظ ' . $saved . ' مسميات.');
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE hr_job_title SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $id]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل المسمى الوظيفي.' : 'تم إيقاف المسمى الوظيفي.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_job_title_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف هذا المسمى.'));
                }
                $st = $pdo->prepare('DELETE FROM hr_job_title WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف المسمى الوظيفي.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();
$departments = hr_department_active_list($pdo);
$defaultDeptId = isset($_GET['department_id']) && $_GET['department_id'] !== ''
    ? (int) $_GET['department_id'] : 0;

$st = $pdo->query(
    'SELECT jt.id, jt.title_code, jt.name_ar, jt.description, jt.is_active, jt.department_id,
            d.name_ar AS department_name
     FROM hr_job_title jt
     LEFT JOIN hr_department d ON d.id = jt.department_id
     ORDER BY CAST(jt.title_code AS UNSIGNED) ASC, jt.id ASC'
);
$jobTitles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$nextJtCode = hr_job_title_next_code($pdo);

$cssPath = app_path('assets/css/hr-job-titles.css');
$cssUrl = app_url('assets/css/hr-job-titles.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-job-titles-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-job-titles-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-job-titles.js');
$jsUrl = app_url('assets/js/hr-job-titles.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_job_titles');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-jt-ora12-screen hr-jt-wrap hr-jt-grid-page hr-jt-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextJtCode) ?>"
     data-default-dept-id="<?= (int) $defaultDeptId ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">المسميات الوظيفية</h1>
        <?php nav_render_screen_close('hr_job_titles'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-jt-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-jt-top-bar hr-jt-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-jt-btn-add">مسمى جديد</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-jt-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-jt-btn-delete" disabled
                title="حدد مسمى غير مرتبط">حذف</button>
    </div>
    <p class="hr-jt-toolbar-hint muted">أضف المسميات للجدول ثم احفظ من شريط الأدوات. لا يُحذف المسمى المرتبط بموظف.</p>

    <section class="dashboard-ora-panel hr-jt-editor-panel hr-jt-editor" id="hr-jt-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-jt-editor-close" id="hr-jt-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-jt-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" id="hr-jt-form-action" value="save_one">
            <input type="hidden" name="pending_items_json" id="hr-jt-pending-json" value="[]">
            <input type="hidden" name="id" id="hr-jt-editor-id" value="0">
            <section class="dashboard-ora-panel hr-jt-section">
            <h2 class="dashboard-ora-panel__title">بيانات المسمى الوظيفي</h2>
            <div class="dashboard-ora-panel__body">
            <div class="hr-jt-editor-fields">
                <div class="field hr-jt-editor-field-code">
                    <span class="field-label">الرقم</span>
                    <div class="hr-jt-code-display" id="hr-jt-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-jt-code-hint" id="hr-jt-editor-code-hint">يُولَّد تلقائياً عند الحفظ من شريط الأدوات</small>
                </div>
                <div class="field hr-jt-editor-field-name">
                    <span class="field-label required">المسمى الوظيفي</span>
                    <div class="hr-jt-name-add-row">
                        <input class="input" type="text" name="name_ar" id="hr-jt-editor-name"
                               placeholder="اكتب المسمى ثم اضغط إضافة" autocomplete="off">
                        <button type="button" class="btn btn-primary btn-sm" id="hr-jt-btn-inline-add">إضافة</button>
                    </div>
                </div>
                <label class="field">
                    <span class="field-label">القسم</span>
                    <div class="hr-jt-ora-lov">
                        <select class="input hr-jt-ora-lov-field" name="department_id" id="hr-jt-editor-dept">
                            <option value="">— بدون قسم —</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int) $d['id'] ?>"><?= esc((string) $d['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="hr-jt-ora-lov-btn" tabindex="-1" aria-label="اختيار القسم" title="اختيار القسم"></button>
                    </div>
                    <?php if (!$departments): ?>
                        <small class="hr-jt-dept-warn">
                            <a href="<?= esc(app_url('index.php?r=hr_departments')) ?>">أضف أقساماً أولاً</a>
                        </small>
                    <?php endif; ?>
                </label>
                <label class="field">
                    <span class="field-label">ملاحظات</span>
                    <input class="input" type="text" name="description" id="hr-jt-editor-notes" autocomplete="off"
                           placeholder="المهام المتعلقة بهذا المسمى…">
                </label>
                <label class="field hr-jt-editor-field-active">
                    <span class="field-label">تنشيط</span>
                    <span class="hr-jt-active-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="hr-jt-editor-active" value="1" checked>
                        <span>مسمى مفعّل</span>
                    </span>
                </label>
            </div>
            <p class="hr-jt-editor-hint muted" id="hr-jt-editor-hint">
                أضف كل المسميات المطلوبة للجدول، ثم اضغط «حفظ» في شريط الأدوات.
            </p>
            </div>
            </section>
        </form>
        </div>
    </section>

    <section class="dashboard-ora-panel hr-jt-grid-panel">
        <h2 class="dashboard-ora-panel__title">قائمة المسميات الوظيفية</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="dashboard-ora-table-wrap hr-jt-grid-wrap">
        <table class="dashboard-ora-table hr-jt-grid-table">
            <thead>
            <tr>
                <th>الرقم</th>
                <th>المسمى الوظيفي</th>
                <th>القسم</th>
                <th>ملاحظات</th>
                <th class="hr-jt-col-active">تنشيط</th>
            </tr>
            </thead>
            <tbody id="hr-jt-pending-body"></tbody>
            <tbody id="hr-jt-grid-body">
            <?php if (!$jobTitles): ?>
                <tr class="hr-jt-row hr-jt-row--empty" id="hr-jt-row-empty">
                    <td colspan="5" class="muted">لا توجد مسميات وظيفية بعد — اضغط «مسمى جديد».</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($jobTitles as $t):
                $rid = (int) $t['id'];
                $code = (string) ($t['title_code'] ?? '');
                $name = (string) ($t['name_ar'] ?? '');
                $desc = (string) ($t['description'] ?? '');
                $deptId = (int) ($t['department_id'] ?? 0);
                $deptName = (string) ($t['department_name'] ?? '');
                $isActive = (int) ($t['is_active'] ?? 1) === 1;
                $delChk = hr_job_title_delete_check($pdo, $rid);
                $linked = !$delChk['can_delete'];
            ?>
                <tr class="hr-jt-row<?= $linked ? ' is-linked' : '' ?><?= !$isActive ? ' is-inactive' : '' ?>"
                    data-id="<?= $rid ?>"
                    data-code="<?= esc($code) ?>"
                    data-name="<?= esc($name) ?>"
                    data-description="<?= esc($desc) ?>"
                    data-dept-id="<?= $deptId ?>"
                    data-dept-name="<?= esc($deptName) ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <td><?= esc($code !== '' ? $code : '—') ?></td>
                    <td><?= esc($name !== '' ? $name : '—') ?></td>
                    <td><?= esc($deptName !== '' ? $deptName : '—') ?></td>
                    <td><?= esc($desc !== '' ? $desc : '—') ?></td>
                    <td class="hr-jt-cell-active">
                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-jt-toggle-form">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <input type="hidden" name="is_active" value="0">
                            <label class="hr-jt-toggle-chk" title="<?= $isActive ? 'إيقاف المسمى' : 'تفعيل المسمى' ?>">
                                <input type="checkbox" name="is_active" value="1" class="hr-jt-active-cb"
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

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-jt-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-jt-delete-id" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
