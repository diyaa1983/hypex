<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_attendance_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_attendance');
$config = hr_attendance_load_config($pdo);
$employees = hr_employee_active_list($pdo);

$dateFrom = trim((string) ($_GET['date_from'] ?? $_POST['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string) ($_GET['date_to'] ?? $_POST['date_to'] ?? date('Y-m-d')));
$filterEmpId = (int) ($_GET['employee_id'] ?? $_POST['filter_employee_id'] ?? 0);

function hr_att_build_url(string $dateFrom, string $dateTo, int $empId = 0): string
{
    $q = 'date_from=' . rawurlencode($dateFrom) . '&date_to=' . rawurlencode($dateTo);
    if ($empId > 0) {
        $q .= '&employee_id=' . $empId;
    }

    return app_url('index.php?r=hr_employee_attendance&' . $q);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect(hr_att_build_url($dateFrom, $dateTo, $filterEmpId));
    }

    $act = (string) ($_POST['_action'] ?? '');
    $dateFrom = trim((string) ($_POST['date_from'] ?? $dateFrom));
    $dateTo = trim((string) ($_POST['date_to'] ?? $dateTo));
    $filterEmpId = (int) ($_POST['filter_employee_id'] ?? $filterEmpId);
    $returnUrl = hr_att_build_url($dateFrom, $dateTo, $filterEmpId);

    try {
        if ($act === 'save_config') {
            if (hr_attendance_uses_remote_agent()) {
                hr_attendance_save_config($pdo, hr_attendance_remote_agent_marker());
                flash_set('success', 'تم حفظ إعدادات المزامنة (وضع الوكيل المحلي).');
            } else {
                hr_attendance_save_config($pdo, (string) ($_POST['mdb_path'] ?? ''));
                flash_set('success', 'تم حفظ مسار قاعدة البصمة.');
            }
            redirect($listUrl);
        }

        if ($act === 'regenerate_sync_token') {
            hr_attendance_sync_token_regenerate($pdo);
            hr_attendance_save_config($pdo, hr_attendance_remote_agent_marker());
            flash_set('success', 'تم إنشاء رمز مزامنة جديد — حدّث zk_sync.local.php على جهاز ZKT.');
            redirect($listUrl);
        }

        if ($act === 'upload_mdb') {
            if (!isset($_FILES['mdb_file']) || !is_array($_FILES['mdb_file'])) {
                throw new RuntimeException('لم يُرفَع أي ملف.');
            }
            $upload = $_FILES['mdb_file'];
            $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException('تعذر رفع الملف (رمز ' . $err . ').');
            }
            $tmp = (string) ($upload['tmp_name'] ?? '');
            $orig = strtolower((string) ($upload['name'] ?? ''));
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('ملف الرفع غير صالح.');
            }
            if (!str_ends_with($orig, '.mdb')) {
                throw new RuntimeException('يجب أن يكون الملف att2000.mdb (Access).');
            }
            $dest = hr_attendance_recommended_mdb_path();
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new RuntimeException('تعذر إنشاء مجلد: ' . $destDir);
            }
            if (!@move_uploaded_file($tmp, $dest)) {
                if (!@copy($tmp, $dest)) {
                    throw new RuntimeException('تعذر حفظ الملف على الخادم.');
                }
            }
            @chmod($dest, 0644);
            hr_attendance_save_config($pdo, $dest);
            flash_set('success', 'تم رفع att2000.mdb وحفظ المسار. يمكنك «اختبار الاتصال» ثم «مزامنة الآن».');
            redirect($listUrl);
        }

        if ($act === 'test_mdb') {
            $test = hr_attendance_test_mdb(trim((string) ($_POST['mdb_path'] ?? $config['mdb_path'])));
            flash_set($test['ok'] ? 'success' : 'error', $test['message']);
            redirect($listUrl);
        }

        if ($act === 'sync') {
            $result = hr_attendance_sync($pdo);
            flash_set('success', $result['message']);
            redirect($returnUrl);
        }

        if ($act === 'mark_all_flags') {
            $mark = hr_attendance_mdb_mark_all_pending_flags($config['mdb_path']);
            flash_set($mark['ok'] ? 'success' : 'error', $mark['message']);
            redirect($returnUrl);
        }

        if ($act === 'auto_map') {
            $n = hr_attendance_auto_map_existing($pdo);
            flash_set('success', $n > 0
                ? 'تم ربط ' . $n . ' سجل — رقم الموظف في النظام = رقم البصمة في Access.'
                : 'لا يوجد ربط تلقائي جديد — تأكد من تطابق رقم الموظف مع رقم البصمة.');
            redirect($returnUrl);
        }

        if ($act === 'map_employee') {
            $empCode = trim((string) ($_POST['emp_code'] ?? ''));
            if ($empCode !== '') {
                hr_attendance_save_manual_map_by_emp_code(
                    $pdo,
                    (int) ($_POST['zk_user_id'] ?? 0),
                    $empCode
                );
            } else {
                hr_attendance_save_manual_map(
                    $pdo,
                    (int) ($_POST['zk_user_id'] ?? 0),
                    (int) ($_POST['employee_id'] ?? 0)
                );
            }
            flash_set('success', 'تم ربط رقم الموظف برقم البصمة.');
            redirect($returnUrl);
        }

        if ($act === 'map_batch') {
            $maps = $_POST['maps'] ?? [];
            if (!is_array($maps)) {
                $maps = [];
            }
            if ($maps === []) {
                $empCodes = $_POST['emp_codes'] ?? [];
                if (is_array($empCodes) && $empCodes !== []) {
                    $result = hr_attendance_save_manual_maps_by_emp_code_batch($pdo, $empCodes);
                } else {
                    throw new RuntimeException('اختر موظفاً ورقم بصمة واحداً على الأقل للربط.');
                }
            } else {
                $result = hr_attendance_save_manual_maps_batch($pdo, $maps);
            }
            $message = $result['saved'] > 0
                ? 'تم حفظ ' . $result['saved'] . ' ربط.'
                : 'لم يُحفظ أي ربط.';
            if ($result['errors'] !== []) {
                $message .= ' ' . implode(' — ', array_slice($result['errors'], 0, 3));
            }
            flash_set($result['saved'] > 0 ? 'success' : 'error', $message);
            redirect($returnUrl);
        }

        if ($act === 'unmap_employee') {
            hr_attendance_delete_map($pdo, (int) ($_POST['zk_user_id'] ?? 0));
            flash_set('success', 'تم إلغاء ربط مستخدم البصمة بالموظف.');
            redirect($returnUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

$flash = flash_get();
$punches = hr_attendance_list_punches($pdo, $dateFrom, $dateTo, $filterEmpId);
$linkEmployees = hr_attendance_picker_employees_available($pdo);
usort($linkEmployees, static function (array $a, array $b): int {
    return strnatcmp(
        trim((string) ($a['emp_code'] ?? '')),
        trim((string) ($b['emp_code'] ?? ''))
    );
});
$zkForLink = hr_attendance_zk_users_for_link($pdo);
$mapped = hr_attendance_list_mapped_users($pdo);
$totalPunches = hr_attendance_count_punches($pdo);
$odbcOk = hr_attendance_pdo_odbc_available();
$comOk = hr_attendance_com_available();
$mdbtoolsOk = hr_attendance_mdbtools_available();
$linuxServer = hr_attendance_is_linux_server();
$recommendedMdb = hr_attendance_recommended_mdb_path();
$canSyncMdb = !$linuxServer && ($odbcOk || $comOk || $mdbtoolsOk);
$remoteAgentMode = hr_attendance_uses_remote_agent();
$syncModeInfo = hr_attendance_sync_mode_info();
$syncToken = $remoteAgentMode ? hr_attendance_sync_token_ensure($pdo) : null;
$pushApiUrl = hr_attendance_push_api_url();
$mdbPathIssue = hr_attendance_path_issue($config['mdb_path']);
$displayMdbPath = $remoteAgentMode
    ? hr_attendance_remote_agent_marker()
    : ($mdbPathIssue !== null ? $recommendedMdb : $config['mdb_path']);
$mdbTest = null;

$filterEmpName = '';
if ($filterEmpId > 0) {
    foreach ($employees as $emp) {
        if ((int) ($emp['id'] ?? 0) === $filterEmpId) {
            $filterEmpName = (string) ($emp['name_ar'] ?? '');
            break;
        }
    }
}

$cssPath = app_path('assets/css/hr-employee-attendance.css');
$cssUrl = app_url('assets/css/hr-employee-attendance.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-employee-attendance-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-employee-attendance-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$exitUrl = nav_exit_url('hr_employee_attendance');
$mapPostUrl = hr_att_build_url($dateFrom, $dateTo, $filterEmpId);
$mapApiUrl = app_url('api/hr_attendance_map_batch.php');
$attJsPath = app_path('assets/js/hr-employee-attendance.js');
$attJsUrl = app_url('assets/js/hr-employee-attendance.js')
    . (is_file($attJsPath) ? '?v=' . (string) filemtime($attJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">

<div class="dashboard-ora hr-att-ora12-screen hr-att-wrap hr-att-page hr-att-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-exit-guard="custom"
     data-map-api="<?= esc($mapApiUrl) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">بصمات الموظفين</h1>
        <?php nav_render_screen_close('hr_employee_attendance'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-att-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($remoteAgentMode): ?>
            <div class="alert alert-success hr-att-flash" style="background:#eff6ff;border-color:#93c5fd;color:#1e3a8a;">
                <strong><?= esc($syncModeInfo['label']) ?>:</strong>
                <?= esc($syncModeInfo['hint']) ?>
            </div>
        <?php elseif (!$canSyncMdb): ?>
            <div class="alert alert-error hr-att-flash">
                لا يمكن قراءة att2000.mdb: فعّل <strong>pdo_odbc</strong> أو <strong>com_dotnet</strong> في php.ini ثم أعد تشغيل Apache.
            </div>
        <?php elseif (!$odbcOk && $comOk): ?>
            <div class="alert alert-success hr-att-flash" style="background:#ecfdf5;border-color:#86efac;color:#166534;">
                <strong><?= esc($syncModeInfo['label']) ?>:</strong>
                <?= esc($syncModeInfo['hint']) ?>
                ODBC غير متاح — سيُستخدم <strong>OLEDB (COM)</strong> لقراءة قاعدة البصمة.
            </div>
        <?php elseif (!$remoteAgentMode && $canSyncMdb): ?>
            <div class="alert alert-success hr-att-flash" style="background:#ecfdf5;border-color:#86efac;color:#166534;">
                <strong><?= esc($syncModeInfo['label']) ?>:</strong>
                <?= esc($syncModeInfo['hint']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$remoteAgentMode && $mdbPathIssue !== null): ?>
            <div class="alert alert-error hr-att-flash">
                <?= esc($mdbPathIssue) ?>
            </div>
        <?php endif; ?>

        <p class="hr-att-hint muted">
            يُحمَّل الحضور من برنامج ZKT إلى <strong>att2000.mdb</strong> (Access)، ثم تُزامَن السجلات إلى Manager.
            <?php if ($remoteAgentMode): ?>
                <br><strong>على السيرفر:</strong> لا تُرفَع قاعدة البصمة — شغّل الوكيل على جهاز ZKT (انظر الإعدادات أدناه).
            <?php else: ?>
                <strong>الربط:</strong> رقم الموظف في النظام ←→ رقم البصمة (<strong>BADGENUMBER</strong>) في Access.
                عند التطابق يُربَط تلقائياً أثناء المزامنة أو عبر «ربط تلقائي».
                <br><strong>مهم:</strong> أثناء المزامنة أغلق برنامج Attendance Management.
            <?php endif; ?>
        </p>

        <section class="dashboard-ora-panel hr-att-config-panel">
            <h2 class="dashboard-ora-panel__title"><?= $remoteAgentMode ? 'مزامنة من جهاز ZKT' : 'إعدادات المزامنة' ?></h2>
            <div class="dashboard-ora-panel__body">
                <?php if ($remoteAgentMode): ?>
                <div class="hr-att-agent-box">
                    <p class="hr-att-hint">
                        1. على <strong>جهاز ZKT (Windows)</strong> انسخ مجلد <code dir="ltr">tools</code> من المشروع
                        (أو نسخة XAMPP المحلية) إلى الجهاز.<br>
                        2. انسخ <code dir="ltr">tools/zk_sync.local.example.php</code> إلى
                        <code dir="ltr">tools/zk_sync.local.php</code> وضع الرمز أدناه.<br>
                        3. شغّل <code dir="ltr">tools\zk_sync_run.bat</code> أو جدوله في Windows Task Scheduler كل 5–15 دقيقة.
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
                <?php else: ?>
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
                <?php endif; ?>
                <dl class="hr-att-meta">
                    <div><dt>آخر مزامنة</dt><dd><?= esc($config['last_sync_at'] ?: '—') ?></dd></div>
                    <div><dt>آخر بصمة مُزامَنة</dt><dd><?= esc($config['last_punch_time'] ?: '—') ?></dd></div>
                    <div><dt>إجمالي السجلات في Manager</dt><dd><?= esc((string) $totalPunches) ?></dd></div>
                    <?php if ($mdbTest): ?>
                        <div><dt>حالة MDB</dt><dd class="<?= $mdbTest['ok'] ? 'is-ok' : 'is-err' ?>"><?= esc($mdbTest['message']) ?></dd></div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <div class="dashboard-ora-toolbar hr-att-toolbar no-print">
            <form method="post" action="<?= esc(hr_att_build_url($dateFrom, $dateTo, $filterEmpId)) ?>" class="hr-att-sync-form">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="sync">
                <input type="hidden" name="date_from" value="<?= esc($dateFrom) ?>">
                <input type="hidden" name="date_to" value="<?= esc($dateTo) ?>">
                <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">
                <button type="submit" class="btn btn-primary btn-sm" <?= $canSyncMdb ? '' : 'disabled' ?>
                        title="<?= $remoteAgentMode ? 'المزامنة من جهاز ZKT عبر الوكيل' : '' ?>">
                    <?= $remoteAgentMode ? 'مزامنة من السيرفر (معطّلة)' : 'مزامنة الآن' ?>
                </button>
            </form>
            <form method="post" action="<?= esc(hr_att_build_url($dateFrom, $dateTo, $filterEmpId)) ?>" class="hr-att-sync-form"
                  onsubmit="return confirm('تعليم كل السجلات الحالية في Access كـ Flag=1؟ نفّذ هذا مرة واحدة بعد إضافة الحقل إذا كانت البيانات موجودة مسبقاً في Manager.');">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="mark_all_flags">
                <input type="hidden" name="date_from" value="<?= esc($dateFrom) ?>">
                <input type="hidden" name="date_to" value="<?= esc($dateTo) ?>">
                <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">
                <button type="submit" class="btn btn-secondary btn-sm" <?= ($canSyncMdb && ($odbcOk || $comOk)) ? '' : 'disabled' ?>>
                    تعليم الكل Flag=1
                </button>
            </form>
            <form method="post" action="<?= esc(hr_att_build_url($dateFrom, $dateTo, $filterEmpId)) ?>" class="hr-att-sync-form">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="auto_map">
                <input type="hidden" name="date_from" value="<?= esc($dateFrom) ?>">
                <input type="hidden" name="date_to" value="<?= esc($dateTo) ?>">
                <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">
                <button type="submit" class="btn btn-secondary btn-sm">ربط تلقائي (رقم الموظف = رقم البصمة)</button>
            </form>
        </div>

        <?php if ($mapped !== []): ?>
            <section class="dashboard-ora-panel hr-att-mapped-panel">
                <h2 class="dashboard-ora-panel__title">الموظفون المربوطون بالبصمة</h2>
                <div class="dashboard-ora-panel__body">
                    <div class="dashboard-ora-table-wrap hr-att-table-wrap">
                        <table class="dashboard-ora-table hr-att-table">
                            <thead>
                            <tr>
                                <th>رقم الموظف (النظام)</th>
                                <th>اسم الموظف</th>
                                <th>رقم البصمة (Access)</th>
                                <th>الاسم في Access</th>
                                <th>آخر بصمة</th>
                                <th>عدد السجلات</th>
                                <th class="hr-att-col-actions">إجراء</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($mapped as $mp): ?>
                                <?php
                                $zkUid = (int) ($mp['zk_user_id'] ?? 0);
                                $badge = trim((string) ($mp['badge_number'] ?? ''));
                                $empCode = trim((string) ($mp['emp_code'] ?? ''));
                                $empName = trim((string) ($mp['emp_name'] ?? ''));
                                $matchClass = hr_attendance_badge_matches_emp_code($badge, $empCode) ? 'is-match' : 'is-mismatch';
                                ?>
                                <tr class="hr-att-map-row is-mapped <?= esc($matchClass) ?>">
                                    <td dir="ltr"><?= esc($empCode !== '' ? $empCode : '—') ?></td>
                                    <td><?= esc($empName !== '' ? $empName : '—') ?></td>
                                    <td dir="ltr"><?= esc($badge !== '' ? $badge : '—') ?></td>
                                    <td><?= esc((string) ($mp['zk_name'] ?? '—')) ?></td>
                                    <td dir="ltr"><?= esc((string) ($mp['last_punch'] ?? '—')) ?></td>
                                    <td><?= (int) ($mp['punch_count'] ?? 0) ?></td>
                                    <td class="hr-att-col-actions">
                                        <form method="post" action="<?= esc($mapPostUrl) ?>" class="hr-att-unmap-form"
                                              onsubmit="return confirm('إلغاء ربط هذا الموظف برقم البصمة؟');">
                                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="_action" value="unmap_employee">
                                            <input type="hidden" name="zk_user_id" value="<?= $zkUid ?>">
                                            <input type="hidden" name="date_from" value="<?= esc($dateFrom) ?>">
                                            <input type="hidden" name="date_to" value="<?= esc($dateTo) ?>">
                                            <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm hr-att-unmap-btn">إلغاء الربط</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="dashboard-ora-panel hr-att-link-panel">
            <h2 class="dashboard-ora-panel__title">ربط موظف بالبصمة</h2>
            <div class="dashboard-ora-panel__body">
                <p class="hr-att-map-hint muted">
                    اختر <strong>موظفاً من النظام</strong> و<strong>رقم بصمة من Access</strong> من القائمتين ثم اضغط <strong>حفظ</strong> من شريط الأدوات.
                </p>
                <?php if ($linkEmployees === [] || $zkForLink === []): ?>
                    <p class="muted hr-att-empty">
                        <?php if ($linkEmployees === [] && $zkForLink === []): ?>
                            لا يوجد موظفون متاحون للربط ولا أرقام بصمة غير مربوطة.
                        <?php elseif ($linkEmployees === []): ?>
                            جميع الموظفين مربوطون مسبقاً — لا يوجد موظف متاح للربط.
                        <?php else: ?>
                            لا توجد أرقام بصمة غير مربوطة — نفّذ «مزامنة الآن» أو تحقق من ملف Access.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <form method="post" action="<?= esc($mapPostUrl) ?>" id="hr-att-map-batch-form"
                          class="hr-att-map-batch-form master-page-form no-exit-guard" novalidate>
                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="r" value="hr_employee_attendance">
                        <input type="hidden" name="_action" value="map_batch">
                        <input type="hidden" name="date_from" value="<?= esc($dateFrom) ?>">
                        <input type="hidden" name="date_to" value="<?= esc($dateTo) ?>">
                        <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">
                        <div class="dashboard-ora-table-wrap hr-att-table-wrap">
                            <table class="dashboard-ora-table hr-att-table hr-att-link-table">
                                <thead>
                                <tr>
                                    <th>موظف النظام (رقم — اسم)</th>
                                    <th>رقم البصمة (Access)</th>
                                    <th class="hr-att-col-actions"></th>
                                </tr>
                                </thead>
                                <tbody id="hr-att-link-rows"></tbody>
                            </table>
                        </div>
                        <div class="hr-att-link-actions no-print">
                            <button type="button" class="btn btn-secondary btn-sm" id="hr-att-add-link-row">+ إضافة سطر</button>
                        </div>
                    </form>
                    <?php
                    $linkEmployeesJson = array_map(static function (array $e): array {
                        return [
                            'id' => (int) ($e['id'] ?? 0),
                            'code' => trim((string) ($e['emp_code'] ?? '')),
                            'name' => trim((string) ($e['name_ar'] ?? '')),
                            'label' => hr_attendance_link_label_employee($e),
                        ];
                    }, $linkEmployees);
                    $linkZkJson = array_map(static function (array $z): array {
                        return [
                            'zk_user_id' => (int) ($z['zk_user_id'] ?? 0),
                            'badge' => trim((string) ($z['badge_number'] ?? '')),
                            'name' => trim((string) ($z['zk_name'] ?? '')),
                            'label' => hr_attendance_link_label_zk($z),
                        ];
                    }, $zkForLink);
                    $jsonFlags = JSON_UNESCAPED_UNICODE;
                    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
                    }
                    ?>
                    <script type="application/json" id="hr-att-link-employees-json"><?= json_encode($linkEmployeesJson, $jsonFlags) ?: '[]' ?></script>
                    <script type="application/json" id="hr-att-link-zk-json"><?= json_encode($linkZkJson, $jsonFlags) ?: '[]' ?></script>
                <?php endif; ?>
            </div>
        </section>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">سجلات الحضور المُزامَنة</h2>
            <div class="dashboard-ora-panel__body">
                <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-att-filter-form no-print">
                    <input type="hidden" name="r" value="hr_employee_attendance">
                    <label class="field">
                        <span class="field-label">من تاريخ</span>
                        <input class="input" type="date" name="date_from" value="<?= esc($dateFrom) ?>">
                    </label>
                    <label class="field">
                        <span class="field-label">إلى تاريخ</span>
                        <input class="input" type="date" name="date_to" value="<?= esc($dateTo) ?>">
                    </label>
                    <label class="field">
                        <span class="field-label">موظف</span>
                        <select class="input" name="employee_id">
                            <option value="0">— الكل —</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= (int) $emp['id'] ?>" <?= $filterEmpId === (int) $emp['id'] ? 'selected' : '' ?>>
                                    <?= esc(trim((string) ($emp['emp_code'] ?? '')) !== ''
                                        ? ($emp['emp_code'] . ' — ' . $emp['name_ar'])
                                        : (string) $emp['name_ar']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-secondary btn-sm">عرض</button>
                </form>

                <?php if ($punches === []): ?>
                    <p class="muted hr-att-empty">لا توجد سجلات في الفترة المحددة. استخدم «مزامنة الآن» بعد تحميل البيانات من جهاز ZKT.</p>
                <?php else: ?>
                    <div class="dashboard-ora-table-wrap hr-att-table-wrap">
                        <table class="dashboard-ora-table hr-att-table">
                            <thead>
                            <tr>
                                <th>التاريخ والوقت</th>
                                <th>الموظف</th>
                                <th>رقم البصمة</th>
                                <th>الاسم (جهاز)</th>
                                <th>النوع</th>
                                <th>التحقق</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($punches as $p): ?>
                                <tr class="<?= empty($p['employee_id']) ? 'is-unlinked' : '' ?>">
                                    <td dir="ltr"><?= esc((string) ($p['punch_time'] ?? '')) ?></td>
                                    <td>
                                        <?php if (!empty($p['employee_id'])): ?>
                                            <?= esc((string) ($p['employee_name'] ?? '')) ?>
                                            <?php if (!empty($p['emp_code'])): ?>
                                                <span class="muted">(<?= esc((string) $p['emp_code']) ?>)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="hr-att-unlinked-tag">غير مربوط</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= esc((string) ($p['badge_number'] ?? '—')) ?></td>
                                    <td><?= esc((string) ($p['zk_name'] ?? '—')) ?></td>
                                    <td><?= esc(hr_attendance_punch_type_label($p['punch_type'] ?? null)) ?></td>
                                    <td><?= esc(hr_attendance_verify_label(isset($p['verify_code']) ? (int) $p['verify_code'] : null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<script src="<?= esc($attJsUrl) ?>" defer></script>
