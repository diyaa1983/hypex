<?php
declare(strict_types=1);

require_once app_path('includes/hr_leave_type.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_leave_type_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_leave_types');
$editorFormId = 'hr-leave-type-editor-form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_leave_type_parse_row($_POST, $pdo, $id);
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE hr_leave_type SET leave_code = ?, name_ar = ?, default_days = ?, prorate_yearly = ?, is_active = ? WHERE id = ?'
                )->execute([
                    $parsed['leave_code'],
                    $parsed['name_ar'],
                    $parsed['default_days'],
                    $parsed['prorate_yearly'],
                    $parsed['is_active'],
                    $id,
                ]);
                flash_set('success', 'تم حفظ تعديلات نوع الإجازة.');
            } else {
                $pdo->prepare(
                    'INSERT INTO hr_leave_type (leave_code, name_ar, default_days, prorate_yearly, is_active) VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $parsed['leave_code'],
                    $parsed['name_ar'],
                    $parsed['default_days'],
                    $parsed['prorate_yearly'],
                    $parsed['is_active'],
                ]);
                flash_set('success', 'تم إضافة نوع الإجازة برقم ' . $parsed['leave_code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_leave_type_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن الحذف.'));
                }
                $pdo->prepare('DELETE FROM hr_leave_type WHERE id = ?')->execute([$id]);
                flash_set('success', 'تم حذف نوع الإجازة.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();
$types = hr_leave_type_list($pdo);
$nextCode = hr_leave_type_next_code($pdo);
$exitUrl = nav_exit_url('hr_leave_types');

$cssPath = app_path('assets/css/hr-leave-types.css');
$cssUrl = app_url('assets/css/hr-leave-types.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-leave-types-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-leave-types-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">

<div class="dashboard-ora hr-lt-ora12-screen hr-lt-wrap hr-lt-page hr-lt-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إعدادات الإجازات</h1>
        <?php nav_render_screen_close('hr_leave_types'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-lt-grid-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-ora-toolbar hr-lt-top-bar hr-lt-toolbar no-print">
            <button type="submit" class="btn btn-primary btn-sm" form="<?= esc($editorFormId) ?>">حفظ</button>
            <button type="button" class="btn btn-secondary btn-sm" id="hr-leave-type-reset">جديد</button>
        </div>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">إضافة / تعديل نوع إجازة</h2>
            <div class="dashboard-ora-panel__body">
                <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-leave-type-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_one">
                    <input type="hidden" name="id" id="hr-leave-type-id" value="0">
                    <div class="hr-leave-type-fields">
                        <label class="field">
                            <span class="field-label">رقم الإجازة</span>
                            <input class="input" type="text" id="hr-leave-type-code"
                                   dir="ltr" inputmode="numeric"
                                   value="<?= esc($nextCode) ?>" readonly tabindex="-1"
                                   aria-readonly="true" title="يُولَّد تلقائياً عند الحفظ">
                        </label>
                        <label class="field">
                            <span class="field-label required">نوع الإجازة</span>
                            <input class="input" type="text" name="name_ar" id="hr-leave-type-name" required>
                        </label>
                        <label class="field">
                            <span class="field-label">عدد الأيام</span>
                            <input class="input" type="text" name="default_days" id="hr-leave-type-days"
                                   dir="ltr" inputmode="decimal" value="0">
                        </label>
                        <label class="field hr-leave-type-prorate">
                            <span class="field-label">يجدول على مدار السنة</span>
                            <span class="hr-lt-check">
                                <input type="hidden" name="prorate_yearly" value="0">
                                <input type="checkbox" name="prorate_yearly" value="1" id="hr-leave-type-prorate">
                            </span>
                        </label>
                        <label class="field hr-leave-type-active">
                            <span class="field-label">نشط</span>
                            <span class="hr-lt-check">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" id="hr-leave-type-active" checked>
                                <span>نشط</span>
                            </span>
                        </label>
                    </div>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">قائمة أنواع الإجازات</h2>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <div class="dashboard-ora-table-wrap">
                    <table class="dashboard-ora-table hr-lt-type-table hr-leave-type-table">
                        <thead>
                        <tr>
                            <th>رقم الإجازة</th>
                            <th>نوع الإجازة</th>
                            <th>عدد الأيام</th>
                            <th>جدولة سنوية</th>
                            <th>الحالة</th>
                            <th>إجراء</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($types === []): ?>
                            <tr><td colspan="6" class="muted">لا توجد أنواع إجازات.</td></tr>
                        <?php else: ?>
                            <?php foreach ($types as $type): ?>
                                <tr>
                                    <td dir="ltr"><?= esc((string) ($type['leave_code'] ?? '')) ?></td>
                                    <td><?= esc((string) ($type['name_ar'] ?? '')) ?></td>
                                    <td dir="ltr"><?= esc(number_format((float) ($type['default_days'] ?? 0), 2, '.', '')) ?></td>
                                    <td><?= !empty($type['prorate_yearly']) ? 'نعم' : 'لا' ?></td>
                                    <td><?= !empty($type['is_active']) ? 'نشط' : 'موقوف' ?></td>
                                    <td class="hr-lt-type-row-actions hr-leave-type-row-actions">
                                        <button type="button" class="btn btn-secondary btn-xs hr-leave-type-edit"
                                                data-id="<?= (int) ($type['id'] ?? 0) ?>"
                                                data-code="<?= esc((string) ($type['leave_code'] ?? '')) ?>"
                                                data-name="<?= esc((string) ($type['name_ar'] ?? '')) ?>"
                                                data-days="<?= esc(number_format((float) ($type['default_days'] ?? 0), 2, '.', '')) ?>"
                                                data-prorate="<?= !empty($type['prorate_yearly']) ? '1' : '0' ?>"
                                                data-active="<?= !empty($type['is_active']) ? '1' : '0' ?>">تعديل</button>
                                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-leave-type-delete-form"
                                              onsubmit="return confirm('حذف نوع الإجازة؟');">
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
  var idEl = document.getElementById('hr-leave-type-id');
  var codeEl = document.getElementById('hr-leave-type-code');
  var nameEl = document.getElementById('hr-leave-type-name');
  var daysEl = document.getElementById('hr-leave-type-days');
  var activeEl = document.getElementById('hr-leave-type-active');
  var prorateEl = document.getElementById('hr-leave-type-prorate');
  var resetBtn = document.getElementById('hr-leave-type-reset');
  function resetForm() {
    if (idEl) idEl.value = '0';
    if (codeEl) { codeEl.value = nextCode; codeEl.readOnly = true; }
    if (nameEl) nameEl.value = '';
    if (daysEl) daysEl.value = '0';
    if (prorateEl) prorateEl.checked = false;
    if (activeEl) activeEl.checked = true;
    document.querySelectorAll('.hr-lt-type-table tbody tr').forEach(function (tr) {
      tr.classList.remove('is-editing');
    });
  }
  document.querySelectorAll('.hr-leave-type-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.hr-lt-type-table tbody tr').forEach(function (tr) {
        tr.classList.remove('is-editing');
      });
      var row = btn.closest('tr');
      if (row) row.classList.add('is-editing');
      if (idEl) idEl.value = btn.getAttribute('data-id') || '0';
      if (codeEl) { codeEl.value = btn.getAttribute('data-code') || ''; codeEl.readOnly = true; }
      if (nameEl) nameEl.value = btn.getAttribute('data-name') || '';
      if (daysEl) daysEl.value = btn.getAttribute('data-days') || '0';
      if (prorateEl) prorateEl.checked = (btn.getAttribute('data-prorate') || '0') === '1';
      if (activeEl) activeEl.checked = (btn.getAttribute('data-active') || '0') === '1';
      if (nameEl) nameEl.focus();
    });
  });
  if (resetBtn) resetBtn.addEventListener('click', resetForm);
})();
</script>
