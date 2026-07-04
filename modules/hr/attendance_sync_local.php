<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_employee_attendance_actions.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_attendance_ensure_schema($pdo);

$route = 'hr_attendance_sync_local';
$listUrl = app_url('index.php?r=' . rawurlencode($route));
$config = hr_attendance_load_config($pdo);

$buildUrl = static fn (string $df, string $dt, int $emp = 0): string =>
    hr_attendance_build_screen_url($route, $df, $dt, $emp);

hr_attendance_handle_post($pdo, $config, $buildUrl, $listUrl, 'local');

$flash = flash_get();
$totalPunches = hr_attendance_count_punches($pdo);
$odbcOk = hr_attendance_pdo_odbc_available();
$comOk = hr_attendance_com_available();
$mdbtoolsOk = hr_attendance_mdbtools_available();
$linuxServer = hr_attendance_is_linux_server();
$recommendedMdb = hr_attendance_recommended_mdb_path();
$canSyncMdb = ($odbcOk || $comOk || $mdbtoolsOk);
$mdbPathIssue = hr_attendance_path_issue($config['mdb_path']);
$displayMdbPath = $mdbPathIssue !== null ? $recommendedMdb : $config['mdb_path'];

$cssPath = app_path('assets/css/hr-employee-attendance.css');
$cssUrl = app_url('assets/css/hr-employee-attendance.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-employee-attendance-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-employee-attendance-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$exitUrl = nav_exit_url('hr_attendance_sync_local');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">

<div class="dashboard-ora hr-att-ora12-screen hr-att-wrap hr-att-page hr-att-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-exit-guard="custom">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">مزامنة البصمة — Windows (محلي)</h1>
        <?php nav_render_screen_close('hr_attendance_sync_local'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php hr_attendance_render_nav_tabs('local'); ?>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-att-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($linuxServer): ?>
            <div class="alert alert-error hr-att-flash">
                هذه الشاشة للاستخدام على <strong>Windows / XAMPP</strong> حيث يوجد ملف
                <code dir="ltr">att2000.mdb</code> على نفس الجهاز.
                على السيرفر Linux استخدم شاشة <a href="<?= esc(app_url('index.php?r=hr_attendance_sync_server')) ?>">مزامنة السيرفر (ZKT)</a>.
            </div>
        <?php elseif (!$canSyncMdb): ?>
            <div class="alert alert-error hr-att-flash">
                لا يمكن قراءة att2000.mdb: فعّل <strong>pdo_odbc</strong> أو <strong>com_dotnet</strong> في php.ini ثم أعد تشغيل Apache.
            </div>
        <?php else: ?>
            <div class="alert alert-success hr-att-flash" style="background:#ecfdf5;border-color:#86efac;color:#166534;">
                <strong>مزامنة مباشرة:</strong>
                Manager يقرأ <code dir="ltr">att2000.mdb</code> من جهاز Windows — اضغط «مزامنة الآن» بعد تحديد المسار.
                <?php if (!$odbcOk && $comOk): ?>
                    سيُستخدم <strong>OLEDB (COM)</strong>.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($mdbPathIssue !== null && !$linuxServer): ?>
            <div class="alert alert-error hr-att-flash"><?= esc($mdbPathIssue) ?></div>
        <?php endif; ?>

        <section class="dashboard-ora-panel hr-att-config-panel">
            <h2 class="dashboard-ora-panel__title">مسار قاعدة البصمة</h2>
            <div class="dashboard-ora-panel__body">
                <form method="post" action="<?= esc($listUrl) ?>" class="hr-att-config-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_config">
                    <label class="field hr-att-mdb-field">
                        <span class="field-label">مسار att2000.mdb</span>
                        <input class="input" type="text" name="mdb_path" dir="ltr"
                               value="<?= esc($displayMdbPath) ?>" required>
                    </label>
                    <div class="hr-att-config-actions">
                        <button type="submit" class="btn btn-secondary btn-sm">حفظ المسار</button>
                        <button type="submit" class="btn btn-secondary btn-sm" formaction="<?= esc($listUrl) ?>"
                                name="_action" value="test_mdb" formnovalidate>اختبار الاتصال</button>
                    </div>
                </form>
                <?php if ($linuxServer): ?>
                    <form method="post" action="<?= esc($listUrl) ?>" enctype="multipart/form-data" class="hr-att-config-form">
                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="_action" value="upload_mdb">
                        <label class="field">
                            <span class="field-label">رفع att2000.mdb إلى السيرفر (اختبار)</span>
                            <input class="input" type="file" name="mdb_file" accept=".mdb">
                        </label>
                        <button type="submit" class="btn btn-secondary btn-sm">رفع الملف</button>
                    </form>
                <?php endif; ?>
                <dl class="hr-att-meta">
                    <div><dt>آخر مزامنة</dt><dd><?= esc($config['last_sync_at'] ?: '—') ?></dd></div>
                    <div><dt>آخر بصمة مُزامَنة</dt><dd><?= esc($config['last_punch_time'] ?: '—') ?></dd></div>
                    <div><dt>إجمالي السجلات في Manager</dt><dd><?= esc((string) $totalPunches) ?></dd></div>
                    <div><dt>محرك القراءة</dt><dd><?= esc(hr_attendance_mdb_driver_label()) ?></dd></div>
                </dl>
            </div>
        </section>

        <div class="dashboard-ora-toolbar hr-att-toolbar no-print">
            <form method="post" action="<?= esc($listUrl) ?>" class="hr-att-sync-form">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="sync">
                <button type="submit" class="btn btn-primary btn-sm" <?= $canSyncMdb ? '' : 'disabled' ?>>
                    مزامنة الآن
                </button>
            </form>
            <form method="post" action="<?= esc($listUrl) ?>" class="hr-att-sync-form"
                  onsubmit="return confirm('تعليم كل السجلات في Access كـ Flag=1؟');">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="mark_all_flags">
                <button type="submit" class="btn btn-secondary btn-sm" <?= ($canSyncMdb && ($odbcOk || $comOk)) ? '' : 'disabled' ?>>
                    تعليم الكل Flag=1
                </button>
            </form>
        </div>

        <p class="hr-att-hint muted">
            <strong>مهم:</strong> أغلق برنامج Attendance Management أثناء المزامنة.
            لعرض السجلات: <a href="<?= esc(app_url('index.php?r=hr_employee_attendance')) ?>">بصمات الموظفين</a>.
        </p>
    </div>
</div>
