<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_salary_bank_delete.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_salary_bank_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_salary_banks');
$editorFormId = 'hr-bank-editor-form';

/**
 * @return array{code:string,name:string,active:int}
 */
function hr_bank_parse_row(PDO $pdo, array $row, int $id): array
{
    $name = trim((string) ($row['name_ar'] ?? ''));
    $isActive = !empty($row['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new RuntimeException('اسم البنك مطلوب.');
    }

    $code = '';
    if ($id > 0) {
        $stCur = $pdo->prepare('SELECT bank_code FROM hr_salary_bank WHERE id = ? LIMIT 1');
        $stCur->execute([$id]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('البنك غير موجود.');
        }
        $code = trim((string) ($cur['bank_code'] ?? ''));
        if ($code === '' || !ctype_digit($code)) {
            $code = hr_salary_bank_next_code($pdo);
        }
    } else {
        $code = hr_salary_bank_next_code($pdo);
        if (!array_key_exists('is_active', $row)) {
            $isActive = 1;
        }
    }

    $stChk = $pdo->prepare('SELECT id FROM hr_salary_bank WHERE bank_code = ? AND id <> ? LIMIT 1');
    $stChk->execute([$code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('تعذر توليد رقم بنك تسلسلي، أعد المحاولة.');
    }

    return [
        'code' => $code,
        'name' => $name,
        'active' => $isActive,
    ];
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
            $parsed = hr_bank_parse_row($pdo, $_POST, $id);

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_salary_bank SET bank_code = ?, name_ar = ?, is_active = ? WHERE id = ?'
                );
                $st->execute([$parsed['code'], $parsed['name'], $parsed['active'], $id]);
                flash_set('success', 'تم حفظ تعديلات البنك.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_salary_bank (bank_code, name_ar, is_active) VALUES (?, ?, ?)'
                );
                $st->execute([$parsed['code'], $parsed['name'], $parsed['active']]);
                flash_set('success', 'تم إضافة البنك برقم ' . $parsed['code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE hr_salary_bank SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $id]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل البنك.' : 'تم إيقاف البنك.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_salary_bank_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف هذا البنك.'));
                }
                $st = $pdo->prepare('DELETE FROM hr_salary_bank WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف البنك.');
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
    'SELECT id, bank_code, name_ar, is_active FROM hr_salary_bank
     ORDER BY CAST(bank_code AS UNSIGNED) ASC, id ASC'
);
$banks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$nextBankCode = hr_salary_bank_next_code($pdo);

$cssPath = app_path('assets/css/hr-salary-banks.css');
$cssUrl = app_url('assets/css/hr-salary-banks.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-salary-banks-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-salary-banks-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-salary-banks.js');
$jsUrl = app_url('assets/js/hr-salary-banks.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_salary_banks');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-bank-ora12-screen hr-bank-wrap hr-bank-grid-page hr-bank-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextBankCode) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">البنوك</h1>
        <?php nav_render_screen_close('hr_salary_banks'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-bank-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-bank-top-bar hr-bank-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-bank-btn-add">بنك جديد</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-bank-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-bank-btn-delete" disabled
                title="حدد بنكاً غير مرتبط بموظف">حذف</button>
    </div>
    <p class="hr-bank-toolbar-hint muted">يمكن حذف البنك فقط إن لم يكن مرتبطاً بأي موظف.</p>

    <section class="dashboard-ora-panel hr-bank-editor-panel hr-bank-editor" id="hr-bank-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-bank-editor-close" id="hr-bank-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-bank-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_one">
            <input type="hidden" name="id" id="hr-bank-editor-id" value="0">
            <section class="dashboard-ora-panel hr-bank-section">
            <h2 class="dashboard-ora-panel__title" id="hr-bank-editor-title">إضافة بنك</h2>
            <div class="dashboard-ora-panel__body">
            <div class="hr-bank-editor-fields">
                <div class="field hr-bank-editor-field-code">
                    <span class="field-label">رقم البنك</span>
                    <div class="hr-bank-code-display" id="hr-bank-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-bank-code-hint" id="hr-bank-editor-code-hint">يُولَّد تلقائياً ولا يمكن تعديله</small>
                </div>
                <label class="field">
                    <span class="field-label required">اسم البنك</span>
                    <input class="input" type="text" name="name_ar" id="hr-bank-editor-name" required
                           placeholder="مثال: البنك العربي" autocomplete="off">
                </label>
                <label class="field hr-bank-editor-field-active">
                    <span class="field-label">تنشيط</span>
                    <span class="hr-bank-active-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="hr-bank-editor-active" value="1" checked>
                        <span>بنك مفعّل</span>
                    </span>
                </label>
            </div>
            </div>
            </section>
            <div class="hr-bank-editor-actions">
                <button type="submit" class="btn btn-primary btn-sm">حفظ البنك</button>
                <button type="button" class="btn btn-ghost btn-sm" id="hr-bank-editor-cancel">إلغاء</button>
            </div>
        </form>
        </div>
    </section>

    <section class="dashboard-ora-panel hr-bank-grid-panel">
        <h2 class="dashboard-ora-panel__title">قائمة البنوك</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="dashboard-ora-table-wrap hr-bank-grid-wrap">
        <table class="dashboard-ora-table hr-bank-grid-table">
            <thead>
            <tr>
                <th>رقم البنك</th>
                <th>اسم البنك</th>
                <th class="hr-bank-col-active">تنشيط</th>
            </tr>
            </thead>
            <tbody id="hr-bank-grid-body">
            <?php if (!$banks): ?>
                <tr class="hr-bank-row hr-bank-row--empty">
                    <td colspan="3" class="muted">لا توجد بنوك بعد — اضغط «بنك جديد».</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($banks as $b):
                $rid = (int) $b['id'];
                $code = (string) ($b['bank_code'] ?? '');
                $name = (string) ($b['name_ar'] ?? '');
                $isActive = (int) ($b['is_active'] ?? 1) === 1;
                $delChk = hr_salary_bank_delete_check($pdo, $rid);
                $linked = !$delChk['can_delete'];
            ?>
                <tr class="hr-bank-row<?= $linked ? ' is-linked' : '' ?><?= !$isActive ? ' is-inactive' : '' ?>"
                    data-id="<?= $rid ?>"
                    data-code="<?= esc($code) ?>"
                    data-name="<?= esc($name) ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <td><?= esc($code !== '' ? $code : '—') ?></td>
                    <td><?= esc($name !== '' ? $name : '—') ?></td>
                    <td class="hr-bank-cell-active">
                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-bank-toggle-form">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <input type="hidden" name="is_active" value="0">
                            <label class="hr-bank-toggle-chk" title="<?= $isActive ? 'إيقاف البنك' : 'تفعيل البنك' ?>">
                                <input type="checkbox" name="is_active" value="1" class="hr-bank-active-cb"
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

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-bank-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-bank-delete-id" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
