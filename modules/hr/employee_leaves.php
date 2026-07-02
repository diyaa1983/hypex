<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_leave.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_employee_leave_ensure_schema($pdo);
hr_leave_type_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_leaves');
$editorFormId = 'hr-emp-leave-editor-form';
$pickerEmployees = hr_employee_picker_list($pdo);
$leaveTypes = hr_leave_type_list($pdo, true);

$selectedId = (int) ($_GET['selected_id'] ?? 0);
$isNew = !empty($_GET['new'])
    || (!empty($_POST['is_new']) && (int) ($_POST['id'] ?? 0) < 1);
$voucherQuery = trim((string) ($_GET['voucher_q'] ?? ''));

$buildUrl = static function (int $selId = 0, string $voucherQ = '') use ($listUrl): string {
    $url = $selId > 0 ? $listUrl . '&selected_id=' . $selId : $listUrl;
    if ($voucherQ !== '') {
        $url .= '&voucher_q=' . rawurlencode($voucherQ);
    }

    return $url;
};

$buildNavUrl = static function (int $selId, int $matchIdx = -1) use ($buildUrl, $voucherQuery): string {
    $url = $buildUrl($selId, $voucherQuery);
    if ($voucherQuery !== '' && $matchIdx >= 0) {
        $url .= '&match_idx=' . $matchIdx;
    }

    return $url;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $voucherLookup = trim((string) ($_GET['voucher_lookup'] ?? ''));
    if ($voucherLookup !== '') {
        $resolved = hr_employee_leave_lookup_by_voucher($pdo, $voucherLookup);
        if ($resolved === null) {
            flash_set('error', 'لم يُعثر على سند إجازة يطابق «' . $voucherLookup . '».');
            redirect($listUrl);
        }
        $url = $listUrl . '&selected_id=' . (int) ($resolved['id'] ?? 0);
        if ((int) ($resolved['search_match_count'] ?? 0) > 1) {
            $url .= '&voucher_q=' . rawurlencode((string) ($resolved['search_query'] ?? $voucherLookup));
            $url .= '&match_idx=' . (int) ($resolved['search_match_index'] ?? 0);
        }
        redirect($url);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');
    $postId = (int) ($_POST['id'] ?? 0);
    $returnUrl = $postId > 0
        ? $buildUrl($postId)
        : $buildUrl(0) . (!empty($_POST['is_new']) ? '&new=1' : '');

    try {
        if ($act === 'save') {
            $savedId = hr_employee_leave_save($pdo, $_POST);
            flash_set('success', 'تم حفظ سند الإجازة.');
            redirect($buildUrl($savedId));
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_leave_delete($pdo, $id);
                flash_set('success', 'تم حذف سند الإجازة.');
            }
            redirect($listUrl);
        }

        if ($act === 'post_leave') {
            if (!user_can_action('action_post_employee_leave')) {
                throw new RuntimeException('ليس لديك صلاحية ترحيل الإجازة.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_leave_post($pdo, $id);
                flash_set('success', 'تم ترحيل الإجازة — ستظهر في كشف حركة دوام الموظفين ويُخصم من الرصيد.');
            }
            redirect($buildUrl($id));
        }

        if ($act === 'unpost_leave') {
            if (!user_can_action('action_unpost_employee_leave')) {
                throw new RuntimeException('ليس لديك صلاحية فك ترحيل الإجازة.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_leave_unpost($pdo, $id);
                flash_set('success', 'تم فك ترحيل الإجازة.');
            }
            redirect($buildUrl($id));
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

$flash = flash_get();
$rows = hr_employee_leave_list($pdo);

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && !$isNew
    && $selectedId === 0
    && $rows !== []
    && trim((string) ($_GET['voucher_lookup'] ?? '')) === ''
) {
    $autoId = (int) ($rows[0]['id'] ?? 0);
    if ($autoId > 0) {
        redirect($buildUrl($autoId));
    }
}

$current = $selectedId > 0 ? hr_employee_leave_get($pdo, $selectedId) : null;
$isPosted = $current ? (int) ($current['is_posted'] ?? 0) === 1 : false;
$canEdit = ($current && !$isNew) ? hr_employee_leave_edit_check($pdo, (int) $current['id'])['can_edit'] : true;
$nextVoucher = hr_employee_leave_next_voucher_no($pdo);
$canPost = $current && !$isPosted && user_can_action('action_post_employee_leave');
$canUnpost = $current && $isPosted && user_can_action('action_unpost_employee_leave');
$canDelete = $current && hr_employee_leave_delete_check($pdo, (int) $current['id'])['can_delete'];

$currentRecordId = ($current && !$isNew) ? (int) ($current['id'] ?? 0) : 0;
$displayVoucherNo = ($current && !$isNew)
    ? (string) ($current['voucher_no'] ?? '')
    : $nextVoucher;
$voucherInputSize = max(3, strlen($displayVoucherNo));
$voucherNoClass = 'sales-inv-no-input';
if ($currentRecordId > 0) {
    $voucherNoClass .= $isPosted ? ' is-posted' : ' is-unposted';
}

$searchIds = $voucherQuery !== '' ? hr_employee_leave_search_ids_by_voucher_fragment($pdo, $voucherQuery) : [];
$searchPos = ($searchIds !== [] && $currentRecordId > 0)
    ? array_search($currentRecordId, $searchIds, true)
    : false;

if ($searchPos !== false && count($searchIds) > 1) {
    $navPrev = $searchPos > 0 ? (int) $searchIds[$searchPos - 1] : 0;
    $navNext = $searchPos < count($searchIds) - 1 ? (int) $searchIds[$searchPos + 1] : 0;
    $navPrevIdx = $searchPos > 0 ? $searchPos - 1 : -1;
    $navNextIdx = $searchPos < count($searchIds) - 1 ? $searchPos + 1 : -1;
} else {
    $leaveNav = hr_employee_leave_browse_nav($pdo, $currentRecordId);
    $navPrev = (int) ($leaveNav['prev'] ?? 0);
    $navNext = (int) ($leaveNav['next'] ?? 0);
    $navPrevIdx = -1;
    $navNextIdx = -1;
}

$voucherNavTitle = $voucherQuery !== '' && count($searchIds) > 1
    ? ('بحث: «' . $voucherQuery . '» — ' . ($searchPos !== false ? ($searchPos + 1) : 1) . ' من ' . count($searchIds))
    : 'اكتب رقم السند أو جزءاً منه ثم Enter';

$exitUrl = nav_exit_url('hr_employee_leaves');
employee_picker_enqueue_assets();

$cssPath = app_path('assets/css/hr-employee-leaves.css');
$cssUrl = app_url('assets/css/hr-employee-leaves.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-leave-module-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-leave-module-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$jsPath = app_path('assets/js/hr-employee-leaves.js');
$jsUrl = app_url('assets/js/hr-employee-leaves.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">
<script src="<?= esc($jsUrl) ?>" defer></script>
<?php employee_picker_json_script($pickerEmployees, 'hr-emp-leave-picker-json'); ?>

<div class="dashboard-ora hr-leave-ora12-screen hr-emp-leave-wrap hr-emp-leave-page hr-leave-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-selected-id="<?= (int) $currentRecordId ?>"
     data-voucher-no="<?= esc($displayVoucherNo) ?>"
     data-is-posted="<?= $isPosted ? '1' : '0' ?>"
     data-can-post="<?= $canPost ? '1' : '0' ?>"
     data-can-unpost="<?= $canUnpost ? '1' : '0' ?>"
     data-can-delete="<?= $canDelete ? '1' : '0' ?>">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إدخال الإجازات</h1>
        <?php nav_render_screen_close('hr_employee_leaves'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-leave-grid-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($leaveTypes === []): ?>
            <div class="alert alert-error hr-leave-grid-flash">
                لا توجد أنواع إجازات نشطة. أضف أنواعاً من شاشة
                <a href="<?= esc(app_url('index.php?r=hr_leave_types')) ?>">إعدادات الإجازات</a> أولاً.
            </div>
        <?php endif; ?>

        <div class="dashboard-ora-toolbar hr-emp-leave-toolbar no-print">
            <a href="<?= esc($buildUrl() . '&new=1') ?>" class="btn btn-primary btn-sm">إجازة جديدة</a>
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-emp-leave-voucher-bar" id="hr-emp-leave-voucher-form">
                <input type="hidden" name="r" value="hr_employee_leaves">
                <span class="hr-emp-leave-voucher-label">رقم السند</span>
                <div class="hr-emp-leave-no-nav">
                    <?php if ($navPrev > 0): ?>
                        <a class="hr-emp-leave-no-arrow" href="<?= esc($buildNavUrl($navPrev, $navPrevIdx)) ?>"
                           title="السند السابق" aria-label="السند السابق">‹</a>
                    <?php else: ?>
                        <span class="hr-emp-leave-no-arrow is-disabled" aria-hidden="true">‹</span>
                    <?php endif; ?>
                    <input class="input hr-emp-leave-no-input <?= esc($voucherNoClass) ?>" type="text" name="voucher_lookup"
                           id="hr-emp-leave-voucher-no" dir="ltr" autocomplete="off"
                           value="<?= esc($displayVoucherNo) ?>"
                           size="<?= (int) $voucherInputSize ?>"
                           title="<?= esc($voucherNavTitle) ?>"
                           placeholder="رقم السند">
                    <?php if ($navNext > 0): ?>
                        <a class="hr-emp-leave-no-arrow" href="<?= esc($buildNavUrl($navNext, $navNextIdx)) ?>"
                           title="السند التالي" aria-label="السند التالي">›</a>
                    <?php else: ?>
                        <span class="hr-emp-leave-no-arrow is-disabled" aria-hidden="true">›</span>
                    <?php endif; ?>
                </div>
            </form>
            <div class="hr-emp-leave-employee-head">
                <span class="field-label<?= $canEdit ? ' required' : '' ?>">الموظف</span>
                <?php if ($canEdit): ?>
                    <?= employee_picker_field([
                        'id' => 'hr-emp-leave-picker',
                        'name' => 'employee_id',
                        'label' => '',
                        'compact' => true,
                        'form_id' => $editorFormId,
                        'wrapper_class' => 'hr-emp-leave-picker-slot',
                        'json_id' => 'hr-emp-leave-picker-json',
                        'value' => (int) ($current['employee_id'] ?? 0),
                        'placeholder' => 'اختر الموظف',
                        'required' => true,
                    ]) ?>
                <?php else: ?>
                    <input class="input hr-emp-leave-employee-readonly" type="text" readonly
                           value="<?= esc(trim((string) ($current['emp_code'] ?? '') . ' — ' . (string) ($current['employee_name'] ?? ''))) ?>">
                <?php endif; ?>
            </div>
        </div>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">بيانات سند الإجازة</h2>
            <div class="dashboard-ora-panel__body">
                <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($buildUrl($selectedId) . ($isNew ? '&new=1' : '')) ?>">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save">
                    <input type="hidden" name="id" value="<?= $isNew ? 0 : (int) ($current['id'] ?? 0) ?>">
                    <?php if ($isNew): ?>
                        <input type="hidden" name="is_new" value="1">
                    <?php endif; ?>
                    <?php if (!$canEdit): ?>
                        <input type="hidden" name="employee_id" value="<?= (int) ($current['employee_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="hr-emp-leave-fields">
                        <label class="field hr-emp-leave-field-created">
                            <span class="field-label">تاريخ الإنشاء</span>
                            <input class="input" type="text" dir="ltr" readonly
                                   value="<?php
                                   $createdRaw = $current ? (string) ($current['created_at'] ?? '') : date('Y-m-d H:i:s');
                                   $createdTs = strtotime($createdRaw);
                                   echo esc($createdTs !== false
                                       ? format_date_dmY(date('Y-m-d', $createdTs)) . ' ' . date('H:i', $createdTs)
                                       : $createdRaw);
                                   ?>">
                        </label>
                        <label class="field hr-emp-leave-field-date">
                            <span class="field-label required">تاريخ الإجازة</span>
                            <input class="input js-date-dmy" type="text" name="leave_date" dir="ltr"
                                   value="<?= esc($current ? format_date_dmY((string) ($current['leave_date'] ?? '')) : format_date_dmY(date('Y-m-d'))) ?>"
                                   <?= $canEdit ? '' : 'readonly' ?> required>
                        </label>
                        <label class="field hr-emp-leave-field-type">
                            <span class="field-label required">نوع الإجازة</span>
                            <select class="input" name="leave_type_id" <?= $canEdit ? '' : 'disabled' ?> required>
                                <option value="">— اختر —</option>
                                <?php foreach ($leaveTypes as $type): ?>
                                    <option value="<?= (int) ($type['id'] ?? 0) ?>"
                                        <?= (int) ($current['leave_type_id'] ?? 0) === (int) ($type['id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= esc((string) ($type['leave_code'] ?? '') . ' — ' . (string) ($type['name_ar'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field hr-emp-leave-field-date">
                            <span class="field-label required">من تاريخ</span>
                            <input class="input js-date-dmy" type="text" name="date_from" id="hr-emp-leave-from" dir="ltr"
                                   value="<?= esc($current ? format_date_dmY((string) ($current['date_from'] ?? '')) : '') ?>"
                                   <?= $canEdit ? '' : 'readonly' ?> required>
                        </label>
                        <label class="field hr-emp-leave-field-date">
                            <span class="field-label required">إلى تاريخ</span>
                            <input class="input js-date-dmy" type="text" name="date_to" id="hr-emp-leave-to" dir="ltr"
                                   value="<?= esc($current ? format_date_dmY((string) ($current['date_to'] ?? '')) : '') ?>"
                                   <?= $canEdit ? '' : 'readonly' ?> required>
                        </label>
                        <label class="field hr-emp-leave-field-days">
                            <span class="field-label required">عدد الأيام</span>
                            <input class="input" type="text" name="days_count" id="hr-emp-leave-days" dir="ltr"
                                   inputmode="decimal"
                                   value="<?= esc($current ? number_format((float) ($current['days_count'] ?? 0), 2, '.', '') : '') ?>"
                                   <?= $canEdit ? '' : 'readonly' ?> required>
                        </label>
                        <label class="field hr-emp-leave-notes">
                            <span class="field-label">ملاحظات</span>
                            <input class="input" type="text" name="notes"
                                   value="<?= esc((string) ($current['notes'] ?? '')) ?>"
                                   <?= $canEdit ? '' : 'readonly' ?>>
                        </label>
                        <label class="field hr-emp-leave-field-status">
                            <span class="field-label">الحالة</span>
                            <input class="input" type="text" readonly
                                   value="<?= esc($current ? hr_employee_leave_posted_label((int) ($current['is_posted'] ?? 0)) : 'مسودة') ?>">
                        </label>
                    </div>

                    <?php if ($canEdit): ?>
                        <div class="hr-emp-leave-actions">
                            <button type="submit" class="btn btn-primary btn-sm">حفظ</button>
                            <?php if ($canDelete): ?>
                                <button type="button" class="btn btn-danger btn-sm" id="hr-emp-leave-btn-delete">حذف</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">سجلات الإجازات</h2>
            <div class="dashboard-ora-panel__body">
                <div class="dashboard-ora-table-wrap">
                    <table class="dashboard-ora-table hr-emp-leave-table">
                        <thead>
                        <tr>
                            <th>رقم السند</th>
                            <th>الموظف</th>
                            <th>نوع الإجازة</th>
                            <th>تاريخ الإجازة</th>
                            <th>من</th>
                            <th>إلى</th>
                            <th>الأيام</th>
                            <th>الحالة</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="8" class="muted">لا توجد إجازات.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $rid = (int) ($row['id'] ?? 0);
                                $rowPosted = (int) ($row['is_posted'] ?? 0) === 1;
                                ?>
                                <tr class="hr-emp-leave-row<?= $rid === $selectedId ? ' is-selected' : '' ?>"
                                    data-leave-id="<?= $rid ?>"
                                    data-leave-posted="<?= $rowPosted ? '1' : '0' ?>"
                                    data-leave-url="<?= esc($buildUrl($rid)) ?>">
                                    <td dir="ltr">
                                        <a href="<?= esc($buildUrl($rid)) ?>"><?= esc((string) ($row['voucher_no'] ?? '')) ?></a>
                                    </td>
                                    <td><?= esc((string) ($row['employee_name'] ?? '')) ?></td>
                                    <td><?= esc((string) ($row['type_name'] ?? '')) ?></td>
                                    <td dir="ltr"><?= esc(format_date_dmY((string) ($row['leave_date'] ?? ''))) ?></td>
                                    <td dir="ltr"><?= esc(format_date_dmY((string) ($row['date_from'] ?? ''))) ?></td>
                                    <td dir="ltr"><?= esc(format_date_dmY((string) ($row['date_to'] ?? ''))) ?></td>
                                    <td dir="ltr"><?= esc(number_format((float) ($row['days_count'] ?? 0), 2, '.', '')) ?></td>
                                    <td><?= esc(hr_employee_leave_posted_label((int) ($row['is_posted'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if ($currentRecordId > 0): ?>
            <form method="post" action="<?= esc($buildUrl($selectedId)) ?>" id="hr-emp-leave-post-form" class="hr-emp-leave-master-form" hidden>
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="post_leave">
                <input type="hidden" name="id" value="<?= (int) $currentRecordId ?>">
            </form>
            <form method="post" action="<?= esc($buildUrl($selectedId)) ?>" id="hr-emp-leave-unpost-form" class="hr-emp-leave-master-form" hidden>
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="unpost_leave">
                <input type="hidden" name="id" value="<?= (int) $currentRecordId ?>">
            </form>
            <form method="post" action="<?= esc($buildUrl()) ?>" id="hr-emp-leave-delete-form" class="hr-emp-leave-master-form" hidden>
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $currentRecordId ?>">
            </form>
        <?php endif; ?>
    </div>
</div>
