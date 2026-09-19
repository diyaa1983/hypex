<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_employee_attendance_actions.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_attendance_ensure_schema($pdo);

$route = 'hr_attendance_sync_server';
$listUrl = app_url('index.php?r=' . rawurlencode($route));
$config = hr_attendance_load_config($pdo);

$buildUrl = static fn (string $df, string $dt, int $emp = 0): string =>
    hr_attendance_build_screen_url($route, $df, $dt, $emp);

hr_attendance_handle_post($pdo, $config, $buildUrl, $listUrl, 'server');

$flash = flash_get();
$totalPunches = hr_attendance_count_punches($pdo);
$syncToken = hr_attendance_sync_token_ensure($pdo);
$pushApiUrl = hr_attendance_push_api_url();

$cssPath = app_path('assets/css/hr-employee-attendance.css');
$cssUrl = app_url('assets/css/hr-employee-attendance.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-employee-attendance-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-employee-attendance-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$exitUrl = nav_exit_url('hr_attendance_sync_server');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">

<div class="dashboard-ora hr-att-ora12-screen hr-att-wrap hr-att-page hr-att-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-exit-guard="custom">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">مزامنة البصمة — السيرفر (وكيل ZKT)</h1>
        <?php nav_render_screen_close('hr_attendance_sync_server'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php hr_attendance_render_nav_tabs('server'); ?>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-att-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-success hr-att-flash" style="background:#eff6ff;border-color:#93c5fd;color:#1e3a8a;">
            <strong>وضع السيرفر (Linux):</strong>
            ملف <code dir="ltr">att2000.mdb</code> يبقى على جهاز البصمة (Windows).
            ثبّت وكيل المزامنة على ذلك الجهاز ليرسل البصمات إلى هذا السيرفر.
        </div>

        <section class="dashboard-ora-panel hr-att-config-panel">
            <h2 class="dashboard-ora-panel__title">إعداد وكيل ZKT على جهاز البصمة</h2>
            <div class="dashboard-ora-panel__body">
                <div class="hr-att-agent-box">
                    <p class="hr-att-hint">
                        1. على <strong>جهاز ZKT (Windows)</strong> أنشئ المجلد
                        <code dir="ltr">C:\zktdata\tools\</code> وانسخ:
                        <code dir="ltr">zk_sync_agent.ps1</code>، <code dir="ltr">zk_sync_run.bat</code>.<br>
                        2. أنشئ <code dir="ltr">zk_sync.local.php</code> وضع الرمز ورابط API أدناه.<br>
                        3. شغّل <code dir="ltr">zk_sync_run.bat</code> أو جدوله كل 5–15 دقيقة.
                    </p>
                    <dl class="hr-att-meta hr-att-agent-meta">
                        <div>
                            <dt>رابط API</dt>
                            <dd dir="ltr"><code><?= esc($pushApiUrl) ?></code></dd>
                        </div>
                        <div>
                            <dt>رمز المزامنة</dt>
                            <dd dir="ltr"><code class="hr-att-sync-token"><?= esc((string) $syncToken) ?></code></dd>
                        </div>
                    </dl>
                    <form method="post" action="<?= esc($listUrl) ?>" class="hr-att-config-form"
                          onsubmit="return confirm('إنشاء رمز جديد؟ يجب تحديث zk_sync.local.php على جهاز ZKT.');">
                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="_action" value="regenerate_sync_token">
                        <button type="submit" class="btn btn-secondary btn-sm">رمز مزامنة جديد</button>
                    </form>
                </div>
                <dl class="hr-att-meta">
                    <div><dt>آخر مزامنة</dt><dd><?= esc($config['last_sync_at'] ?: '—') ?></dd></div>
                    <div><dt>آخر بصمة مُزامَنة</dt><dd><?= esc($config['last_punch_time'] ?: '—') ?></dd></div>
                    <div><dt>إجمالي السجلات في Manager</dt><dd><?= esc((string) $totalPunches) ?></dd></div>
                </dl>
            </div>
        </section>

        <p class="hr-att-hint muted">
            لعرض السجلات وربط الموظفين: <a href="<?= esc(app_url('index.php?r=hr_employee_attendance')) ?>">بصمات الموظفين</a>.
            للتقرير الكامل: <a href="<?= esc(app_url('index.php?r=report_hr_att_punch_movements')) ?>">حركات البصمات (الكل)</a>.
        </p>
    </div>
</div>
