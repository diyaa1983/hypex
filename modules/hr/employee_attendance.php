<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_employee_attendance_actions.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_attendance_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);

$route = 'hr_employee_attendance';
$listUrl = app_url('index.php?r=' . rawurlencode($route));
$config = hr_attendance_load_config($pdo);
$employees = hr_employee_active_list($pdo);

$dateFrom = trim((string) ($_GET['date_from'] ?? $_POST['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string) ($_GET['date_to'] ?? $_POST['date_to'] ?? date('Y-m-d')));
$filterEmpId = (int) ($_GET['employee_id'] ?? $_POST['filter_employee_id'] ?? 0);

$buildUrl = static fn (string $df, string $dt, int $emp = 0): string =>
    hr_attendance_build_screen_url($route, $df, $dt, $emp);

hr_attendance_handle_post($pdo, $config, $buildUrl, $listUrl, 'main');

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
$mapPostUrl = $buildUrl($dateFrom, $dateTo, $filterEmpId);
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
        <?php hr_attendance_render_nav_tabs('main'); ?>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-att-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <p class="hr-att-hint muted">
            عرض وربط سجلات البصمة بعد المزامنة.
            للمزامنة اختر:
            <a href="<?= esc(app_url('index.php?r=hr_attendance_sync_server')) ?>">السيرفر (ZKT)</a>
            أو
            <a href="<?= esc(app_url('index.php?r=hr_attendance_sync_local')) ?>">Windows (محلي)</a>.
            آخر مزامنة: <strong><?= esc($config['last_sync_at'] ?: '—') ?></strong>
            — إجمالي السجلات: <strong><?= esc((string) $totalPunches) ?></strong>
        </p>

        <div class="dashboard-ora-toolbar hr-att-toolbar no-print">
            <form method="post" action="<?= esc($mapPostUrl) ?>" class="hr-att-sync-form">
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
                            لا توجد أرقام بصمة غير مربوطة — نفّذ المزامنة من شاشة Windows أو السيرفر.
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
                    <p class="muted hr-att-empty">لا توجد سجلات في الفترة المحددة. نفّذ المزامنة من شاشة السيرفر أو Windows.</p>
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
