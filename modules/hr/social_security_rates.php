<?php
declare(strict_types=1);

require_once app_path('includes/hr_social_security_rate.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_social_security_rate_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_social_security_rates');
$editorFormId = 'hr-ss-rate-editor-form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $parsed = hr_social_security_rate_parse_row($pdo, $_POST, $id);

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_social_security_rate SET rate_code = ?, employee_percent = ?, employer_percent = ?,
                     description = ?, is_active = ? WHERE id = ?'
                );
                $st->execute([
                    $parsed['code'], $parsed['emp_pct'], $parsed['er_pct'],
                    $parsed['desc'], $parsed['active'], $id,
                ]);
                flash_set('success', 'تم حفظ تعديلات نسبة الضمان.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_social_security_rate (rate_code, employee_percent, employer_percent, description, is_active)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $parsed['code'], $parsed['emp_pct'], $parsed['er_pct'], $parsed['desc'], $parsed['active'],
                ]);
                flash_set('success', 'تم إضافة نسبة الضمان برقم ' . $parsed['code'] . '.');
            }
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE hr_social_security_rate SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $id]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل النسبة.' : 'تم إيقاف النسبة.');
            }
            redirect($listUrl);
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = hr_social_security_rate_delete_check($pdo, $id);
                if (!$chk['can_delete']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن حذف هذه النسبة.'));
                }
                $st = $pdo->prepare('DELETE FROM hr_social_security_rate WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف نسبة الضمان.');
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
    'SELECT id, rate_code, employee_percent, employer_percent, description, is_active
     FROM hr_social_security_rate
     ORDER BY CAST(rate_code AS UNSIGNED) ASC, id ASC'
);
$rates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$nextRateCode = hr_social_security_rate_next_code($pdo);

$cssPath = app_path('assets/css/hr-social-security-rates.css');
$cssUrl = app_url('assets/css/hr-social-security-rates.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-social-security-rates-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-social-security-rates-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-social-security-rates.js');
$jsUrl = app_url('assets/js/hr-social-security-rates.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_social_security_rates');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-ss-rate-ora12-screen hr-ss-rate-wrap hr-ss-rate-grid-page hr-ss-rate-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>"
     data-next-code="<?= esc($nextRateCode) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">نسب الضمان الاجتماعي</h1>
        <?php nav_render_screen_close('hr_social_security_rates'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-ss-rate-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-ss-rate-top-bar hr-ss-rate-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-ss-rate-btn-add">نسبة جديدة</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-ss-rate-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-ss-rate-btn-delete" disabled>حذف</button>
    </div>

    <section class="dashboard-ora-panel hr-ss-rate-editor-panel hr-ss-rate-editor" id="hr-ss-rate-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-ss-rate-editor-close" id="hr-ss-rate-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-ss-rate-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_one">
            <input type="hidden" name="id" id="hr-ss-rate-editor-id" value="0">
            <section class="dashboard-ora-panel hr-ss-rate-section">
            <h2 class="dashboard-ora-panel__title" id="hr-ss-rate-editor-title">إضافة نسبة</h2>
            <div class="dashboard-ora-panel__body">
            <div class="hr-ss-rate-editor-fields">
                <div class="field hr-ss-rate-editor-field-code">
                    <span class="field-label">الرقم</span>
                    <div class="hr-ss-rate-code-display" id="hr-ss-rate-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-ss-rate-code-hint" id="hr-ss-rate-editor-code-hint">يُولَّد تلقائياً ولا يمكن تعديله</small>
                </div>
                <label class="field">
                    <span class="field-label required">نسبة الموظف</span>
                    <input class="input" type="number" name="employee_percent" id="hr-ss-rate-editor-emp-pct"
                           step="0.001" min="0" max="100" value="0" dir="ltr" inputmode="decimal" required>
                    <small class="hr-ss-rate-pct-hint">% من الراتب الخاضع للضمان</small>
                </label>
                <label class="field">
                    <span class="field-label required">نسبة الشركة</span>
                    <input class="input" type="number" name="employer_percent" id="hr-ss-rate-editor-er-pct"
                           step="0.001" min="0" max="100" value="0" dir="ltr" inputmode="decimal" required>
                    <small class="hr-ss-rate-pct-hint">% من الراتب الخاضع للضمان</small>
                </label>
                <label class="field hr-ss-rate-editor-field-notes">
                    <span class="field-label">ملاحظات</span>
                    <input class="input" type="text" name="description" id="hr-ss-rate-editor-notes" autocomplete="off">
                </label>
                <label class="field hr-ss-rate-editor-field-active">
                    <span class="field-label">تنشيط</span>
                    <span class="hr-ss-rate-active-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="hr-ss-rate-editor-active" value="1" checked>
                        <span>نسبة مفعّلة</span>
                    </span>
                </label>
            </div>
            </div>
            </section>
            <div class="hr-ss-rate-editor-actions">
                <button type="submit" class="btn btn-primary btn-sm">حفظ النسبة</button>
                <button type="button" class="btn btn-ghost btn-sm" id="hr-ss-rate-editor-cancel">إلغاء</button>
            </div>
        </form>
        </div>
    </section>

    <section class="dashboard-ora-panel hr-ss-rate-grid-panel">
        <h2 class="dashboard-ora-panel__title">قائمة نسب الضمان</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="dashboard-ora-table-wrap hr-ss-rate-grid-wrap">
        <table class="dashboard-ora-table hr-ss-rate-grid-table">
            <thead>
            <tr>
                <th>الرقم</th>
                <th>نسبة الموظف</th>
                <th>نسبة الشركة</th>
                <th>ملاحظات</th>
                <th class="hr-ss-rate-col-active">تنشيط</th>
            </tr>
            </thead>
            <tbody id="hr-ss-rate-grid-body">
            <?php if (!$rates): ?>
                <tr class="hr-ss-rate-row hr-ss-rate-row--empty">
                    <td colspan="5" class="muted">لا توجد نسب بعد — اضغط «نسبة جديدة».</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rates as $r):
                $rid = (int) $r['id'];
                $code = (string) ($r['rate_code'] ?? '');
                $empPct = (float) ($r['employee_percent'] ?? 0);
                $erPct = (float) ($r['employer_percent'] ?? 0);
                $desc = (string) ($r['description'] ?? '');
                $isActive = (int) ($r['is_active'] ?? 1) === 1;
                $delChk = hr_social_security_rate_delete_check($pdo, $rid);
                $linked = !$delChk['can_delete'];
            ?>
                <tr class="hr-ss-rate-row<?= $linked ? ' is-linked' : '' ?><?= !$isActive ? ' is-inactive' : '' ?>"
                    data-id="<?= $rid ?>"
                    data-code="<?= esc($code) ?>"
                    data-emp-pct="<?= esc((string) $empPct) ?>"
                    data-er-pct="<?= esc((string) $erPct) ?>"
                    data-description="<?= esc($desc) ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($delChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <td dir="ltr"><?= esc($code !== '' ? $code : '—') ?></td>
                    <td class="hr-ss-rate-pct-cell" dir="ltr"><?= esc(hr_social_security_rate_format_pct($empPct)) ?></td>
                    <td class="hr-ss-rate-pct-cell" dir="ltr"><?= esc(hr_social_security_rate_format_pct($erPct)) ?></td>
                    <td><?= esc($desc !== '' ? $desc : '—') ?></td>
                    <td class="hr-ss-rate-cell-active">
                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-ss-rate-toggle-form">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <input type="hidden" name="is_active" value="0">
                            <label class="hr-ss-rate-toggle-chk" title="<?= $isActive ? 'إيقاف النسبة' : 'تفعيل النسبة' ?>">
                                <input type="checkbox" name="is_active" value="1" class="hr-ss-rate-active-cb"
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

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-ss-rate-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="id" id="hr-ss-rate-delete-id" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
