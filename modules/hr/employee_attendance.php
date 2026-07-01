<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_attendance_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_attendance');
$config = hr_attendance_load_config($pdo);
$employees = hr_employee_active_list($pdo);
$pickerEmployees = hr_attendance_picker_employees_available($pdo);

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
            hr_attendance_save_config($pdo, (string) ($_POST['mdb_path'] ?? ''));
            flash_set('success', 'تم حفظ مسار قاعدة البصمة.');
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

        if ($act === 'auto_map') {
            $n = hr_attendance_auto_map_existing($pdo);
            flash_set('success', $n > 0 ? 'تم ربط ' . $n . ' مستخدم بصمة بموظفين (حسب رقم الموظف).' : 'لا يوجد ربط تلقائي جديد — تأكد من تطابق emp_code مع BADGENUMBER.');
            redirect($returnUrl);
        }

        if ($act === 'map_employee') {
            hr_attendance_save_manual_map(
                $pdo,
                (int) ($_POST['zk_user_id'] ?? 0),
                (int) ($_POST['employee_id'] ?? 0)
            );
            flash_set('success', 'تم ربط مستخدم البصمة بالموظف.');
            redirect($returnUrl);
        }

        if ($act === 'map_batch') {
            $maps = $_POST['maps'] ?? [];
            if (!is_array($maps)) {
                $maps = [];
            }
            $result = hr_attendance_save_manual_maps_batch($pdo, $maps);
            $message = $result['saved'] > 0
                ? 'تم حفظ ' . $result['saved'] . ' ربط.'
                : 'لم يُحفظ أي ربط.';
            if ($result['errors'] !== []) {
                $message .= ' ' . implode(' — ', array_slice($result['errors'], 0, 3));
            }
            flash_set($result['saved'] > 0 ? 'success' : 'error', $message);
            redirect($returnUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

$flash = flash_get();
$punches = hr_attendance_list_punches($pdo, $dateFrom, $dateTo, $filterEmpId);
$unmapped = hr_attendance_unmapped_zk_users($pdo);
$totalPunches = hr_attendance_count_punches($pdo);
$odbcOk = hr_attendance_pdo_odbc_available();
$comOk = hr_attendance_com_available();
$mdbTest = null;
if (is_file($config['mdb_path'])) {
    $mdbTest = hr_attendance_test_mdb($config['mdb_path']);
}

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
$exitUrl = nav_exit_url('hr_employee_attendance');
$mapPostUrl = hr_att_build_url($dateFrom, $dateTo, $filterEmpId);
$mapApiUrl = app_url('api/hr_attendance_map_batch.php');
$attJsPath = app_path('assets/js/hr-employee-attendance.js');
$attJsUrl = app_url('assets/js/hr-employee-attendance.js')
    . (is_file($attJsPath) ? '?v=' . (string) filemtime($attJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="dashboard-ora hr-att-ora12-screen hr-att-wrap"
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

        <?php if (!$odbcOk && !$comOk): ?>
            <div class="alert alert-error hr-att-flash">
                لا يمكن قراءة att2000.mdb: فعّل <strong>pdo_odbc</strong> أو <strong>com_dotnet</strong> في php.ini ثم أعد تشغيل Apache.
            </div>
        <?php elseif (!$odbcOk && $comOk): ?>
            <div class="alert alert-success hr-att-flash" style="background:#ecfdf5;border-color:#86efac;color:#166534;">
                ODBC غير متاح — سيُستخدم <strong>OLEDB (COM)</strong> لقراءة قاعدة البصمة (مناسب لـ XAMPP على Windows).
            </div>
        <?php endif; ?>

        <p class="hr-att-hint muted">
            يُحمَّل الحضور من برنامج ZKT إلى <strong>att2000.mdb</strong> (Access)، ثم تُزامَن السجلات الجديدة إلى Manager.
            الربط التلقائي عند تطابق <strong>رقم الموظف</strong> في النظام مع <strong>BADGENUMBER</strong> في البصمة.
            <br><strong>مهم:</strong> أثناء المزامنة أغلق برنامج Attendance Management، أو ضع نسخة من الملف في
            <code dir="ltr">data\zk_cache\att2000_sync.mdb</code> وحدّد مسارها أدناه.
        </p>

        <section class="dashboard-ora-panel hr-att-config-panel">
            <h2 class="dashboard-ora-panel__title">إعدادات المزامنة</h2>
            <div class="dashboard-ora-panel__body">
                <form method="post" action="<?= esc($listUrl) ?>" class="hr-att-config-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_config">
                    <label class="field hr-att-mdb-field">
                        <span class="field-label">مسار att2000.mdb</span>
                        <input class="input" type="text" name="mdb_path" dir="ltr"
                               value="<?= esc($config['mdb_path']) ?>" required>
                    </label>
                    <div class="hr-att-config-actions">
                        <button type="submit" class="btn btn-secondary btn-sm">حفظ المسار</button>
                        <button type="submit" class="btn btn-secondary btn-sm" formaction="<?= esc($listUrl) ?>"
                                name="_action" value="test_mdb" formnovalidate>اختبار الاتصال</button>
                    </div>
                </form>
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
                <button type="submit" class="btn btn-primary btn-sm" <?= ($odbcOk || $comOk) ? '' : 'disabled' ?>>
                    مزامنة الآن
                </button>
            </form>
            <form method="post" action="<?= esc(hr_att_build_url($dateFrom, $dateTo, $filterEmpId)) ?>" class="hr-att-sync-form">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="auto_map">
                <input type="hidden" name="date_from" value="<?= esc($dateFrom) ?>">
                <input type="hidden" name="date_to" value="<?= esc($dateTo) ?>">
                <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">
                <button type="submit" class="btn btn-secondary btn-sm">ربط تلقائي (emp_code)</button>
            </form>
        </div>

        <?php if ($unmapped !== []): ?>
            <section class="dashboard-ora-panel hr-att-unmapped-panel">
                <h2 class="dashboard-ora-panel__title">مستخدمون غير مربوطين بموظف</h2>
                <div class="dashboard-ora-panel__body">
                    <p class="hr-att-map-hint muted">
                        اختر الموظفين من القائمة ثم اضغط <strong>حفظ</strong> من شريط الأدوات أعلى الشاشة.
                        الموظفون المربوطون مسبقاً ببصمة أخرى لا يظهرون في القائمة.
                    </p>
                    <form method="post" action="<?= esc($mapPostUrl) ?>" id="hr-att-map-batch-form" class="hr-att-map-batch-form master-page-form no-exit-guard" novalidate>
                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="r" value="hr_employee_attendance">
                        <input type="hidden" name="_action" value="map_batch">
                        <input type="hidden" name="date_from" value="<?= esc($dateFrom) ?>">
                        <input type="hidden" name="date_to" value="<?= esc($dateTo) ?>">
                        <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">
                        <table class="hr-att-table">
                            <thead>
                            <tr>
                                <th>ZK USERID</th>
                                <th>رقم البصمة</th>
                                <th>الاسم في الجهاز</th>
                                <th>آخر بصمة</th>
                                <th>عدد السجلات</th>
                                <th>ربط بموظف</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($unmapped as $um): ?>
                                <?php $zkUid = (int) ($um['zk_user_id'] ?? 0); ?>
                                <tr class="hr-att-map-row" data-zk-user-id="<?= $zkUid ?>">
                                    <td><?= $zkUid ?></td>
                                    <td><?= esc((string) ($um['badge_number'] ?? '—')) ?></td>
                                    <td><?= esc((string) ($um['zk_name'] ?? '—')) ?></td>
                                    <td><?= esc((string) ($um['last_punch'] ?? '—')) ?></td>
                                    <td><?= (int) ($um['punch_count'] ?? 0) ?></td>
                                    <td>
                                        <?= employee_picker_field([
                                            'id' => 'hr-att-map-emp-' . $zkUid,
                                            'name' => 'maps[' . $zkUid . ']',
                                            'compact' => true,
                                            'label' => '',
                                            'wrapper_class' => 'hr-att-map-picker',
                                            'json_id' => 'hr-att-picker-json',
                                            'placeholder' => 'اختر موظفاً',
                                        ]) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
            </section>
        <?php endif; ?>

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
                    <div class="hr-att-table-wrap">
                        <table class="hr-att-table">
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
<?php
employee_picker_enqueue_assets();
employee_picker_json_script($pickerEmployees, 'hr-att-picker-json');
?>
<script src="<?= esc($attJsUrl) ?>" defer></script>
