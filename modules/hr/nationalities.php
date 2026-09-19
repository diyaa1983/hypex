<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_nationality_delete.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_nationality_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_nationalities');
$editorFormId = 'hr-nat-editor-form';

/**
 * @return array{code:string,name:string,active:int}
 */
function hr_nat_parse_row(PDO $pdo, array $row, int $id): array
{
    $name = trim((string) ($row['name_ar'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('اسم الجنسية مطلوب.');
    }

    $isActive = !empty($row['is_active']) ? 1 : 0;
    $code = '';

    if ($id > 0) {
        $stCur = $pdo->prepare('SELECT nat_code FROM hr_nationality WHERE id = ? LIMIT 1');
        $stCur->execute([$id]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('الجنسية غير موجودة.');
        }
        $code = trim((string) ($cur['nat_code'] ?? ''));
        if ($code === '' || !ctype_digit($code)) {
            $code = hr_nationality_next_code($pdo);
        }
    } else {
        $code = hr_nationality_next_code($pdo);
        if (!array_key_exists('is_active', $row)) {
            $isActive = 1;
        }
    }

    $stChk = $pdo->prepare('SELECT id FROM hr_nationality WHERE nat_code = ? AND id <> ? LIMIT 1');
    $stChk->execute([$code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('تعذر توليد رقم جنسية تسلسلي، أعد المحاولة.');
    }

    return [
        'code' => $code,
        'name' => $name,
        'active' => $isActive,
    ];
}

function hr_nat_name_taken(PDO $pdo, string $name, int $excludeId = 0): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    hr_nationality_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM hr_nationality WHERE name_ar = ? AND id <> ? LIMIT 1');
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
            $parsed = hr_nat_parse_row($pdo, $_POST, $id);
            if ($id > 0 && hr_nat_name_taken($pdo, $parsed['name'], $id)) {
                throw new RuntimeException('اسم الجنسية مستخدم لسجل آخر.');
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_nationality SET nat_code = ?, name_ar = ?, is_active = ? WHERE id = ?'
                );
                $st->execute([$parsed['code'], $parsed['name'], $parsed['active'], $id]);
                flash_set('success', 'تم حفظ تعديلات الجنسية.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_nationality (nat_code, name_ar, is_active) VALUES (?, ?, ?)'
                );
                $st->execute([$parsed['code'], $parsed['name'], $parsed['active']]);
                flash_set('success', 'تم إضافة الجنسية برقم ' . $parsed['code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'save_batch') {
            $raw = (string) ($_POST['pending_names_json'] ?? '[]');
            $items = json_decode($raw, true);
            if (!is_array($items) || $items === []) {
                throw new RuntimeException('أضف جنسية واحدة على الأقل ثم احفظ من شريط الأدوات.');
            }
            $defaultActive = !empty($_POST['is_active']) ? 1 : 0;
            $saved = 0;
            $pdo->beginTransaction();
            try {
                foreach ($items as $item) {
                    $name = trim((string) (is_array($item) ? ($item['name'] ?? $item['name_ar'] ?? '') : $item));
                    if ($name === '') {
                        continue;
                    }
                    if (hr_nat_name_taken($pdo, $name, 0)) {
                        throw new RuntimeException('الجنسية «' . $name . '» موجودة مسبقاً.');
                    }
                    $parsed = hr_nat_parse_row($pdo, [
                        'name_ar' => $name,
                        'is_active' => $defaultActive,
                    ], 0);
                    $st = $pdo->prepare(
                        'INSERT INTO hr_nationality (nat_code, name_ar, is_active) VALUES (?, ?, ?)'
                    );
                    $st->execute([$parsed['code'], $parsed['name'], $parsed['active']]);
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
                throw new RuntimeException('أضف جنسية واحدة على الأقل ثم احفظ من شريط الأدوات.');
            }
            flash_set('success', $saved === 1
                ? 'تم حفظ جنسية واحدة.'
                : 'تم حفظ ' . $saved . ' جنسيات.');
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE hr_nationality SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $id]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل الجنسية.' : 'تم إيقاف الجنسية.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_nationality_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف هذه الجنسية.'));
                }
                $st = $pdo->prepare('DELETE FROM hr_nationality WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف الجنسية.');
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
    'SELECT id, nat_code, name_ar, is_active FROM hr_nationality
     ORDER BY CAST(nat_code AS UNSIGNED) ASC, id ASC'
);
$nationalities = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$nextNatCode = hr_nationality_next_code($pdo);

$cssPath = app_path('assets/css/hr-nationalities.css');
$cssUrl = app_url('assets/css/hr-nationalities.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-nationalities-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-nationalities-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-nationalities.js');
$jsUrl = app_url('assets/js/hr-nationalities.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_nationalities');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-nat-ora12-screen hr-nat-wrap hr-nat-grid-page hr-nat-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextNatCode) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">الجنسيات</h1>
        <?php nav_render_screen_close('hr_nationalities'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-nat-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-nat-top-bar hr-nat-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-nat-btn-add">جنسية جديدة</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-nat-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-nat-btn-delete" disabled
                title="حدد جنسية غير مرتبطة بموظف">حذف</button>
    </div>
    <p class="hr-nat-toolbar-hint muted">يمكن حذف الجنسية فقط إن لم تكن مرتبطة بأي موظف.</p>

    <section class="dashboard-ora-panel hr-nat-editor-panel hr-nat-editor" id="hr-nat-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-nat-editor-close" id="hr-nat-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-nat-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" id="hr-nat-form-action" value="save_one">
            <input type="hidden" name="pending_names_json" id="hr-nat-pending-json" value="[]">
            <input type="hidden" name="id" id="hr-nat-editor-id" value="0">
            <section class="dashboard-ora-panel hr-nat-section">
            <h2 class="dashboard-ora-panel__title">بيانات الجنسية</h2>
            <div class="dashboard-ora-panel__body">
            <div class="hr-nat-editor-fields">
                <div class="field hr-nat-editor-field-code" id="hr-nat-editor-code-wrap">
                    <span class="field-label">رقم الجنسية</span>
                    <div class="hr-nat-code-display" id="hr-nat-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-nat-code-hint" id="hr-nat-editor-code-hint">يُولَّد تلقائياً عند الحفظ من شريط الأدوات</small>
                </div>
                <div class="field hr-nat-editor-field-name">
                    <span class="field-label required">الجنسية</span>
                    <div class="hr-nat-name-add-row">
                        <input class="input" type="text" name="name_ar" id="hr-nat-editor-name" autocomplete="off"
                               placeholder="اكتب الجنسية ثم اضغط إضافة">
                        <button type="button" class="btn btn-primary btn-sm" id="hr-nat-btn-inline-add">إضافة</button>
                    </div>
                </div>
                <label class="field hr-nat-editor-field-active">
                    <span class="field-label">تنشيط</span>
                    <span class="hr-nat-active-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="hr-nat-editor-active" value="1" checked>
                        <span>جنسية مفعّلة</span>
                    </span>
                </label>
            </div>
            <p class="hr-nat-editor-hint muted" id="hr-nat-editor-hint">
                أضف كل الجنسيات المطلوبة للجدول، ثم اضغط «حفظ» في شريط الأدوات.
            </p>
            </div>
            </section>
        </form>
        </div>
    </section>

    <section class="dashboard-ora-panel hr-nat-grid-panel">
        <h2 class="dashboard-ora-panel__title">قائمة الجنسيات</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="dashboard-ora-table-wrap hr-nat-grid-wrap">
        <table class="dashboard-ora-table hr-nat-grid-table">
            <thead>
            <tr>
                <th>رقم الجنسية</th>
                <th>الجنسية</th>
                <th class="hr-nat-col-active">تنشيط</th>
            </tr>
            </thead>
            <tbody id="hr-nat-pending-body"></tbody>
            <tbody id="hr-nat-grid-body">
            <?php if (!$nationalities): ?>
                <tr class="hr-nat-row hr-nat-row--empty" id="hr-nat-row-empty">
                    <td colspan="3" class="muted">لا توجد جنسيات بعد — اضغط «جنسية جديدة».</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($nationalities as $n):
                $rid = (int) $n['id'];
                $code = (string) ($n['nat_code'] ?? '');
                $name = (string) ($n['name_ar'] ?? '');
                $isActive = (int) ($n['is_active'] ?? 1) === 1;
                $delChk = hr_nationality_delete_check($pdo, $rid);
                $linked = !$delChk['can_delete'];
            ?>
                <tr class="hr-nat-row<?= $linked ? ' is-linked' : '' ?><?= !$isActive ? ' is-inactive' : '' ?>"
                    data-id="<?= $rid ?>"
                    data-code="<?= esc($code) ?>"
                    data-name="<?= esc($name) ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <td><?= esc($code !== '' ? $code : '—') ?></td>
                    <td><?= esc($name !== '' ? $name : '—') ?></td>
                    <td class="hr-nat-cell-active">
                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-nat-toggle-form">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <input type="hidden" name="is_active" value="0">
                            <label class="hr-nat-toggle-chk" title="<?= $isActive ? 'إيقاف الجنسية' : 'تفعيل الجنسية' ?>">
                                <input type="checkbox" name="is_active" value="1" class="hr-nat-active-cb"
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

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-nat-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-nat-delete-id" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
