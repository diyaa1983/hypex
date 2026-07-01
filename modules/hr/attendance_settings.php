<?php
declare(strict_types=1);

require_once app_path('includes/hr_attendance_shift.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_attendance_shift_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_attendance_settings');
$editorFormId = 'hr-att-shift-editor-form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_attendance_shift_parse_row($pdo, $_POST, $id);
            if (hr_attendance_shift_name_taken($pdo, $parsed['name'], $id)) {
                throw new RuntimeException('اسم الشفت مستخدم لسجل آخر.');
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_att_shift SET shift_code = ?, shift_name = ?, start_time = ?, end_time = ?, is_active = ?
                     WHERE id = ?'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['start'], $parsed['end'],
                    $parsed['active'], $id,
                ]);
                flash_set('success', 'تم حفظ تعديلات الشفت.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_att_shift (shift_code, shift_name, start_time, end_time, is_active)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $parsed['code'], $parsed['name'], $parsed['start'], $parsed['end'], $parsed['active'],
                ]);
                flash_set('success', 'تم إضافة الشفت برقم ' . $parsed['code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE hr_att_shift SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $id]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل الشفت.' : 'تم إيقاف الشفت.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_attendance_shift_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف هذا الشفت.'));
                }
                $st = $pdo->prepare('DELETE FROM hr_att_shift WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف الشفت.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();
$shifts = hr_attendance_shift_list($pdo);
$nextShiftCode = hr_attendance_shift_next_code($pdo);

$cssPath = app_path('assets/css/hr-attendance-settings.css');
$cssUrl = app_url('assets/css/hr-attendance-settings.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-attendance-settings-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-attendance-settings-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$jsPath = app_path('assets/js/hr-attendance-settings.js');
$jsUrl = app_url('assets/js/hr-attendance-settings.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_attendance_settings');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">

<div class="dashboard-ora hr-att-shift-ora12-screen hr-att-shift-wrap hr-att-shift-grid-page hr-att-shift-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextShiftCode) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إعدادات دوام الموظفين</h1>
        <?php nav_render_screen_close('hr_attendance_settings'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-att-shift-grid-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <p class="hr-att-shift-hint muted">
            عرّف شفتات الدوام: <strong>رقم الشفت أرقام فقط</strong> (مثل 1 أو 2)،
            ووقت البداية والنهاية بصيغة <strong dir="ltr">HH:mm</strong>
            مثل <strong dir="ltr">07:00</strong> أو <strong dir="ltr">14:30</strong>.
            للعطل (أسبوعية أو رسمية) ضع البداية والنهاية <strong dir="ltr">00:00</strong>.
        </p>

        <div class="dashboard-ora-toolbar hr-att-shift-top-bar hr-att-shift-toolbar">
            <button type="button" class="btn btn-primary btn-sm" id="hr-att-shift-btn-add">شفت جديد</button>
            <button type="button" class="btn btn-secondary btn-sm" id="hr-att-shift-btn-edit" disabled>تعديل</button>
            <button type="button" class="btn btn-danger btn-sm" id="hr-att-shift-btn-delete" disabled>حذف</button>
        </div>

        <section class="dashboard-ora-panel hr-att-shift-editor-panel hr-att-shift-editor" id="hr-att-shift-editor" hidden>
            <div class="dashboard-ora-panel__body">
                <button type="button" class="btn btn-ghost btn-sm hr-att-shift-editor-close" id="hr-att-shift-editor-close" aria-label="إغلاق">✕</button>
                <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-att-shift-editor-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_one">
                    <input type="hidden" name="id" id="hr-att-shift-editor-id" value="0">
                    <section class="dashboard-ora-panel hr-att-shift-section">
                        <h2 class="dashboard-ora-panel__title" id="hr-att-shift-editor-title">إضافة شفت</h2>
                        <div class="dashboard-ora-panel__body">
                            <div class="hr-att-shift-editor-fields">
                                <label class="field hr-att-shift-editor-field-code">
                                    <span class="field-label required">رقم الشفت</span>
                                    <input class="input" type="text" name="shift_code" id="hr-att-shift-editor-code"
                                           pattern="[0-9]+" inputmode="numeric" dir="ltr"
                                           autocomplete="off" required>
                                    <small class="hr-att-shift-code-hint" id="hr-att-shift-editor-code-hint">أرقام فقط — لا يُعدَّل بعد الحفظ</small>
                                </label>
                                <label class="field">
                                    <span class="field-label required">اسم الشفت</span>
                                    <input class="input" type="text" name="shift_name" id="hr-att-shift-editor-name"
                                           maxlength="80" autocomplete="off" required>
                                </label>
                                <label class="field">
                                    <span class="field-label required">وقت بداية الشفت</span>
                                    <input class="input js-time-hm" type="text" name="start_time" id="hr-att-shift-editor-start"
                                           placeholder="07:00" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$"
                                           dir="ltr" inputmode="numeric" autocomplete="off" required>
                                </label>
                                <label class="field">
                                    <span class="field-label required">وقت نهاية الشفت</span>
                                    <input class="input js-time-hm" type="text" name="end_time" id="hr-att-shift-editor-end"
                                           placeholder="15:00" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$"
                                           dir="ltr" inputmode="numeric" autocomplete="off" required>
                                    <button type="button" class="btn btn-ghost btn-sm hr-att-shift-holiday-btn" id="hr-att-shift-set-holiday">
                                        تعيين كعطلة (00:00)
                                    </button>
                                </label>
                                <label class="field hr-att-shift-editor-field-active">
                                    <span class="field-label">تنشيط</span>
                                    <span class="hr-att-shift-active-check">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" name="is_active" id="hr-att-shift-editor-active" value="1" checked>
                                        <span>شفت مفعّل</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </section>
                    <div class="hr-att-shift-editor-actions">
                        <button type="submit" class="btn btn-primary btn-sm">حفظ الشفت</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="hr-att-shift-editor-cancel">إلغاء</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel hr-att-shift-grid-panel">
            <h2 class="dashboard-ora-panel__title">قائمة الشفتات</h2>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <div class="dashboard-ora-table-wrap hr-att-shift-grid-wrap">
                    <table class="dashboard-ora-table hr-att-shift-grid-table">
                        <thead>
                        <tr>
                            <th>رقم الشفت</th>
                            <th>اسم الشفت</th>
                            <th>بداية الشفت</th>
                            <th>نهاية الشفت</th>
                            <th class="hr-att-shift-col-active">تنشيط</th>
                        </tr>
                        </thead>
                        <tbody id="hr-att-shift-grid-body">
                        <?php if (!$shifts): ?>
                            <tr class="hr-att-shift-row hr-att-shift-row--empty">
                                <td colspan="5" class="muted">لا توجد شفتات بعد — اضغط «شفت جديد».</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($shifts as $s):
                            $sid = (int) $s['id'];
                            $code = (string) ($s['shift_code'] ?? '');
                            $name = (string) ($s['shift_name'] ?? '');
                            $start = hr_attendance_shift_format_time(isset($s['start_time']) ? (string) $s['start_time'] : null);
                            $end = hr_attendance_shift_format_time(isset($s['end_time']) ? (string) $s['end_time'] : null);
                            $isHoliday = hr_attendance_shift_is_holiday($start, $end);
                            $isActive = (int) ($s['is_active'] ?? 1) === 1;
                            ?>
                            <tr class="hr-att-shift-row<?= $isHoliday ? ' is-holiday' : '' ?><?= !$isActive ? ' is-inactive' : '' ?>"
                                data-id="<?= $sid ?>"
                                data-code="<?= esc($code) ?>"
                                data-name="<?= esc($name) ?>"
                                data-start="<?= esc($start) ?>"
                                data-end="<?= esc($end) ?>"
                                data-active="<?= $isActive ? '1' : '0' ?>"
                                tabindex="0">
                                <td dir="ltr"><?= esc($code !== '' ? $code : '—') ?></td>
                                <td><?= esc($name !== '' ? $name : '—') ?><?= $isHoliday ? ' <span class="hr-att-shift-holiday-tag">عطلة</span>' : '' ?></td>
                                <td class="hr-att-shift-time-cell" dir="ltr"><?= esc($start !== '' ? $start : '—') ?></td>
                                <td class="hr-att-shift-time-cell" dir="ltr"><?= esc($end !== '' ? $end : '—') ?></td>
                                <td class="hr-att-shift-cell-active">
                                    <form method="post" action="<?= esc($listUrl) ?>" class="hr-att-shift-toggle-form">
                                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="_action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <input type="hidden" name="is_active" value="0">
                                        <label class="hr-att-shift-toggle-chk" title="<?= $isActive ? 'إيقاف الشفت' : 'تفعيل الشفت' ?>">
                                            <input type="checkbox" name="is_active" value="1" class="hr-att-shift-active-cb"
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

        <form method="post" action="<?= esc($listUrl) ?>" id="hr-att-shift-delete-form" class="sr-only" aria-hidden="true">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="delete">
            <input type="hidden" name="id" id="hr-att-shift-delete-id" value="0">
        </form>
    </div>
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
