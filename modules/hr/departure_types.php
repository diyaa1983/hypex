<?php
declare(strict_types=1);

require_once app_path('includes/hr_departure_type.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_departure_type_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_departure_types');
$editorFormId = 'hr-dep-type-editor-form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_departure_type_parse_row($_POST, $pdo, $id);
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE hr_departure_type SET type_code = ?, name_ar = ?, is_active = ? WHERE id = ?'
                )->execute([$parsed['type_code'], $parsed['name_ar'], $parsed['is_active'], $id]);
                flash_set('success', 'تم حفظ تعديلات نوع المغادرة.');
            } else {
                $pdo->prepare(
                    'INSERT INTO hr_departure_type (type_code, name_ar, is_active) VALUES (?, ?, ?)'
                )->execute([$parsed['type_code'], $parsed['name_ar'], $parsed['is_active']]);
                flash_set('success', 'تم إضافة نوع المغادرة برقم ' . $parsed['type_code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_departure_type_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن الحذف.'));
                }
                $pdo->prepare('DELETE FROM hr_departure_type WHERE id = ?')->execute([$id]);
                flash_set('success', 'تم حذف نوع المغادرة.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();
$types = hr_departure_type_list($pdo);
$nextCode = hr_departure_type_next_code($pdo);
$exitUrl = nav_exit_url('hr_departure_types');

$cssPath = app_path('assets/css/hr-departure-types.css');
$cssUrl = app_url('assets/css/hr-departure-types.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-departure-types-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-departure-types-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">

<div class="dashboard-ora hr-dt-ora12-screen hr-dt-wrap hr-dep-type-page hr-dt-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">أنواع المغادرات</h1>
        <?php nav_render_screen_close('hr_departure_types'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-dt-grid-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-ora-toolbar hr-dt-top-bar hr-dt-toolbar no-print">
            <button type="submit" class="btn btn-primary btn-sm" form="<?= esc($editorFormId) ?>">حفظ</button>
            <button type="button" class="btn btn-secondary btn-sm" id="hr-dep-type-reset">جديد</button>
        </div>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">إضافة / تعديل نوع مغادرة</h2>
            <div class="dashboard-ora-panel__body">
                <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-dep-type-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_one">
                    <input type="hidden" name="id" id="hr-dep-type-id" value="0">
                    <div class="hr-dep-type-fields">
                        <label class="field">
                            <span class="field-label">رقم المغادرة</span>
                            <input class="input" type="text" id="hr-dep-type-code"
                                   dir="ltr" inputmode="numeric"
                                   value="<?= esc($nextCode) ?>" readonly tabindex="-1"
                                   aria-readonly="true" title="يُولَّد تلقائياً عند الحفظ">
                        </label>
                        <label class="field">
                            <span class="field-label required">اسم المغادرة</span>
                            <input class="input" type="text" name="name_ar" id="hr-dep-type-name" required>
                        </label>
                        <label class="field hr-dep-type-active">
                            <span class="field-label">نشط</span>
                            <span class="hr-dt-check">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" id="hr-dep-type-active" checked>
                                <span>نشط</span>
                            </span>
                        </label>
                    </div>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">قائمة أنواع المغادرات</h2>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <div class="dashboard-ora-table-wrap">
                    <table class="dashboard-ora-table hr-dt-type-table hr-dep-type-table">
                        <thead>
                        <tr>
                            <th>رقم المغادرة</th>
                            <th>اسم المغادرة</th>
                            <th>الحالة</th>
                            <th>إجراء</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($types === []): ?>
                            <tr><td colspan="4" class="muted">لا توجد أنواع مغادرات.</td></tr>
                        <?php else: ?>
                            <?php foreach ($types as $type): ?>
                                <tr>
                                    <td dir="ltr"><?= esc((string) ($type['type_code'] ?? '')) ?></td>
                                    <td><?= esc((string) ($type['name_ar'] ?? '')) ?></td>
                                    <td><?= !empty($type['is_active']) ? 'نشط' : 'موقوف' ?></td>
                                    <td class="hr-dt-type-row-actions hr-dep-type-row-actions">
                                        <button type="button" class="btn btn-secondary btn-xs hr-dep-type-edit"
                                                data-id="<?= (int) ($type['id'] ?? 0) ?>"
                                                data-code="<?= esc((string) ($type['type_code'] ?? '')) ?>"
                                                data-name="<?= esc((string) ($type['name_ar'] ?? '')) ?>"
                                                data-active="<?= !empty($type['is_active']) ? '1' : '0' ?>">تعديل</button>
                                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-dep-type-delete-form"
                                              onsubmit="return confirm('حذف نوع المغادرة؟');">
                                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="_action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) ($type['id'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
<script>
(function () {
  var nextCode = <?= json_encode($nextCode, JSON_UNESCAPED_UNICODE) ?>;
  var idEl = document.getElementById('hr-dep-type-id');
  var codeEl = document.getElementById('hr-dep-type-code');
  var nameEl = document.getElementById('hr-dep-type-name');
  var activeEl = document.getElementById('hr-dep-type-active');
  var resetBtn = document.getElementById('hr-dep-type-reset');
  function resetForm() {
    if (idEl) idEl.value = '0';
    if (codeEl) { codeEl.value = nextCode; codeEl.readOnly = true; }
    if (nameEl) nameEl.value = '';
    if (activeEl) activeEl.checked = true;
    document.querySelectorAll('.hr-dt-type-table tbody tr').forEach(function (tr) {
      tr.classList.remove('is-editing');
    });
  }
  document.querySelectorAll('.hr-dep-type-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.hr-dt-type-table tbody tr').forEach(function (tr) {
        tr.classList.remove('is-editing');
      });
      var row = btn.closest('tr');
      if (row) row.classList.add('is-editing');
      if (idEl) idEl.value = btn.getAttribute('data-id') || '0';
      if (codeEl) { codeEl.value = btn.getAttribute('data-code') || ''; codeEl.readOnly = true; }
      if (nameEl) nameEl.value = btn.getAttribute('data-name') || '';
      if (activeEl) activeEl.checked = (btn.getAttribute('data-active') || '0') === '1';
      if (nameEl) nameEl.focus();
    });
  });
  if (resetBtn) resetBtn.addEventListener('click', resetForm);
})();
</script>
