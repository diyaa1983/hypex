<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_departure.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_employee_departure_ensure_schema($pdo);
hr_departure_type_ensure_schema($pdo);
hr_employee_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_employee_departures');
$editorFormId = 'hr-emp-dep-editor-form';
$pickerEmployees = hr_employee_picker_list($pdo);
$departureTypes = hr_departure_type_list($pdo, true);

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
        $resolved = hr_employee_departure_lookup_by_voucher($pdo, $voucherLookup);
        if ($resolved === null) {
            flash_set('error', 'لم يُعثر على سند مغادرة يطابق «' . $voucherLookup . '».');
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
            $savedId = hr_employee_departure_save($pdo, $_POST);
            flash_set('success', 'تم حفظ سند المغادرة.');
            redirect($buildUrl($savedId));
        }

        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_departure_delete($pdo, $id);
                flash_set('success', 'تم حذف سند المغادرة.');
            }
            redirect($listUrl);
        }

        if ($act === 'post_departure') {
            if (!user_can_action('action_post_employee_departure')) {
                throw new RuntimeException('ليس لديك صلاحية ترحيل المغادرة.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_departure_post($pdo, $id);
                flash_set('success', 'تم ترحيل المغادرة — ستظهر في كشف حركة دوام الموظفين.');
            }
            redirect($buildUrl($id));
        }

        if ($act === 'unpost_departure') {
            if (!user_can_action('action_unpost_employee_departure')) {
                throw new RuntimeException('ليس لديك صلاحية فك ترحيل المغادرة.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_departure_unpost($pdo, $id);
                flash_set('success', 'تم فك ترحيل المغادرة.');
            }
            redirect($buildUrl($id));
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($returnUrl);
    }
}

$flash = flash_get();
$rows = hr_employee_departure_list($pdo);
$current = $selectedId > 0 ? hr_employee_departure_get($pdo, $selectedId) : null;
$isPosted = $current ? (int) ($current['is_posted'] ?? 0) === 1 : false;
$canEdit = ($current && !$isNew) ? hr_employee_departure_edit_check($pdo, (int) $current['id'])['can_edit'] : true;
$nextVoucher = hr_employee_departure_next_voucher_no($pdo);
$canPost = $current && !$isPosted && user_can_action('action_post_employee_departure');
$canUnpost = $current && $isPosted && user_can_action('action_unpost_employee_departure');
$canDelete = $current && hr_employee_departure_delete_check($pdo, (int) $current['id'])['can_delete'];

$currentRecordId = ($current && !$isNew) ? (int) ($current['id'] ?? 0) : 0;
$displayVoucherNo = ($current && !$isNew)
    ? (string) ($current['voucher_no'] ?? '')
    : $nextVoucher;
$voucherInputSize = max(3, strlen($displayVoucherNo));
$voucherNoClass = 'sales-inv-no-input';
if ($currentRecordId > 0) {
    $voucherNoClass .= $isPosted ? ' is-posted' : ' is-unposted';
}

$searchIds = $voucherQuery !== '' ? hr_employee_departure_search_ids_by_voucher_fragment($pdo, $voucherQuery) : [];
$searchPos = ($searchIds !== [] && $currentRecordId > 0)
    ? array_search($currentRecordId, $searchIds, true)
    : false;

if ($searchPos !== false && count($searchIds) > 1) {
    $navPrev = $searchPos > 0 ? (int) $searchIds[$searchPos - 1] : 0;
    $navNext = $searchPos < count($searchIds) - 1 ? (int) $searchIds[$searchPos + 1] : 0;
    $navPrevIdx = $searchPos > 0 ? $searchPos - 1 : -1;
    $navNextIdx = $searchPos < count($searchIds) - 1 ? $searchPos + 1 : -1;
} else {
    $depNav = hr_employee_departure_browse_nav($pdo, $currentRecordId);
    $navPrev = (int) ($depNav['prev'] ?? 0);
    $navNext = (int) ($depNav['next'] ?? 0);
    $navPrevIdx = -1;
    $navNextIdx = -1;
}

$voucherNavTitle = $voucherQuery !== '' && count($searchIds) > 1
    ? ('بحث: «' . $voucherQuery . '» — ' . ($searchPos !== false ? ($searchPos + 1) : 1) . ' من ' . count($searchIds))
    : 'اكتب رقم السند أو جزءاً منه ثم Enter';

$exitUrl = nav_exit_url('hr_employee_departures');
employee_picker_enqueue_assets();

$cssPath = app_path('assets/css/hr-employee-departures.css');
$cssUrl = app_url('assets/css/hr-employee-departures.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssOraPath = app_path('assets/css/hr-leave-module-sales-ora12.css');
$cssOraUrl = app_url('assets/css/hr-leave-module-sales-ora12.css')
    . (is_file($cssOraPath) ? '?v=' . (string) filemtime($cssOraPath) : '');
$jsPath = app_path('assets/js/hr-employee-departures.js');
$jsUrl = app_url('assets/js/hr-employee-departures.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssOraUrl) ?>">
<script src="<?= esc($jsUrl) ?>" defer></script>
<?php employee_picker_json_script($pickerEmployees, 'hr-emp-dep-picker-json'); ?>

<div class="dashboard-ora hr-leave-ora12-screen hr-emp-dep-wrap hr-emp-dep-page hr-leave-ora-screen"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-selected-id="<?= (int) $currentRecordId ?>"
     data-voucher-no="<?= esc($displayVoucherNo) ?>"
     data-is-posted="<?= $isPosted ? '1' : '0' ?>"
     data-can-post="<?= $canPost ? '1' : '0' ?>"
     data-can-unpost="<?= $canUnpost ? '1' : '0' ?>"
     data-can-delete="<?= $canDelete ? '1' : '0' ?>">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">مغادرات الموظفين</h1>
        <?php nav_render_screen_close('hr_employee_departures'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-dep-grid-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($departureTypes === []): ?>
            <div class="alert alert-error hr-dep-grid-flash">
                لا توجد أنواع مغادرات نشطة. أضف أنواعاً من شاشة
                <a href="<?= esc(app_url('index.php?r=hr_departure_types')) ?>">أنواع المغادرات</a> أولاً.
            </div>
        <?php endif; ?>

        <div class="dashboard-ora-toolbar hr-emp-dep-toolbar no-print">
            <a href="<?= esc($buildUrl() . '&new=1') ?>" class="btn btn-primary btn-sm">مغادرة جديدة</a>
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-emp-dep-voucher-bar" id="hr-emp-dep-voucher-form">
                <input type="hidden" name="r" value="hr_employee_departures">
                <span class="hr-emp-dep-voucher-label">رقم السند</span>
                <div class="hr-emp-dep-no-nav">
                    <?php if ($navPrev > 0): ?>
                        <a class="hr-emp-dep-no-arrow" href="<?= esc($buildNavUrl($navPrev, $navPrevIdx)) ?>"
                           title="السند السابق" aria-label="السند السابق">‹</a>
                    <?php else: ?>
                        <span class="hr-emp-dep-no-arrow is-disabled" aria-hidden="true">‹</span>
                    <?php endif; ?>
                    <input class="input hr-emp-dep-no-input <?= esc($voucherNoClass) ?>" type="text" name="voucher_lookup"
                           id="hr-emp-dep-voucher-no" dir="ltr" autocomplete="off"
                           value="<?= esc($displayVoucherNo) ?>"
                           size="<?= (int) $voucherInputSize ?>"
                           title="<?= esc($voucherNavTitle) ?>"
                           placeholder="رقم السند">
                    <?php if ($navNext > 0): ?>
                        <a class="hr-emp-dep-no-arrow" href="<?= esc($buildNavUrl($navNext, $navNextIdx)) ?>"
                           title="السند التالي" aria-label="السند التالي">›</a>
                    <?php else: ?>
                        <span class="hr-emp-dep-no-arrow is-disabled" aria-hidden="true">›</span>
                    <?php endif; ?>
                </div>
            </form>
            <div class="hr-emp-dep-employee-head">
                <span class="field-label<?= $canEdit ? ' required' : '' ?>">الموظف</span>
                <?php if ($canEdit): ?>
                    <?= employee_picker_field([
                        'id' => 'hr-emp-dep-picker',
                        'name' => 'employee_id',
                        'label' => '',
                        'compact' => true,
                        'form_id' => $editorFormId,
                        'wrapper_class' => 'hr-emp-dep-picker-slot',
                        'json_id' => 'hr-emp-dep-picker-json',
                        'value' => (int) ($current['employee_id'] ?? 0),
                        'placeholder' => 'اختر الموظف',
                        'required' => true,
                    ]) ?>
                <?php else: ?>
                    <input class="input hr-emp-dep-employee-readonly" type="text" readonly
                           value="<?= esc(trim((string) ($current['emp_code'] ?? '') . ' — ' . (string) ($current['employee_name'] ?? ''))) ?>">
                <?php endif; ?>
            </div>
        </div>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">بيانات سند المغادرة</h2>
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

                    <div class="hr-emp-dep-fields">
                        <label class="field hr-emp-dep-field-created">
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
                        <label class="field hr-emp-dep-field-date">
                            <span class="field-label required">تاريخ المغادرة</span>
                            <input class="input js-date-dmy" type="text" name="departure_date" dir="ltr"
                                   value="<?= esc($current ? format_date_dmY((string) ($current['departure_date'] ?? '')) : format_date_dmY(date('Y-m-d'))) ?>"
                                   <?= $canEdit ? '' : 'readonly' ?> required>
                        </label>
                        <label class="field hr-emp-dep-field-type">
                            <span class="field-label required">نوع المغادرة</span>
                            <select class="input" name="departure_type_id" <?= $canEdit ? '' : 'disabled' ?> required>
                                <option value="">— اختر —</option>
                                <?php foreach ($departureTypes as $type): ?>
                                    <option value="<?= (int) ($type['id'] ?? 0) ?>"
                                        <?= (int) ($current['departure_type_id'] ?? 0) === (int) ($type['id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= esc((string) ($type['type_code'] ?? '') . ' — ' . (string) ($type['name_ar'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field hr-emp-dep-field-time">
                            <span class="field-label required">بداية المغادرة</span>
                            <input class="input js-time-hhmm" type="text" name="time_from" dir="ltr"
                                   inputmode="numeric" pattern="^([01]?\d|2[0-3]):[0-5]\d$"
                                   placeholder="00:00"
                                   value="<?= esc($current ? hr_employee_departure_format_time((string) ($current['time_from'] ?? '')) : '') ?>"
                                   <?= $canEdit ? '' : 'readonly' ?> required>
                        </label>
                        <label class="field hr-emp-dep-field-time">
                            <span class="field-label required">نهاية المغادرة</span>
                            <input class="input js-time-hhmm" type="text" name="time_to" dir="ltr"
                                   inputmode="numeric" pattern="^([01]?\d|2[0-3]):[0-5]\d$"
                                   placeholder="00:00"
                                   value="<?= esc($current ? hr_employee_departure_format_time((string) ($current['time_to'] ?? '')) : '') ?>"
                                   <?= $canEdit ? '' : 'readonly' ?> required>
                        </label>
                        <label class="field hr-emp-dep-notes">
                            <span class="field-label">ملاحظات</span>
                            <input class="input" type="text" name="notes"
                                   value="<?= esc((string) ($current['notes'] ?? '')) ?>"
                                   <?= $canEdit ? '' : 'readonly' ?>>
                        </label>
                        <label class="field hr-emp-dep-field-status">
                            <span class="field-label">الحالة</span>
                            <input class="input" type="text" readonly
                                   value="<?= esc($current ? hr_employee_departure_posted_label((int) ($current['is_posted'] ?? 0)) : 'مسودة') ?>">
                        </label>
                    </div>

                    <?php if ($canEdit): ?>
                        <div class="hr-emp-dep-actions">
                            <button type="submit" class="btn btn-primary btn-sm">حفظ</button>
                            <?php if ($canDelete): ?>
                                <button type="button" class="btn btn-danger btn-sm" id="hr-emp-dep-btn-delete">حذف</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">سجلات المغادرات</h2>
            <div class="dashboard-ora-panel__body">
                <div class="dashboard-ora-table-wrap">
                    <table class="dashboard-ora-table hr-emp-dep-table">
                        <thead>
                        <tr>
                            <th>رقم السند</th>
                            <th>الموظف</th>
                            <th>نوع المغادرة</th>
                            <th>تاريخ المغادرة</th>
                            <th>من</th>
                            <th>إلى</th>
                            <th>الحالة</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="7" class="muted">لا توجد مغادرات.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php $rid = (int) ($row['id'] ?? 0); ?>
                                <tr class="<?= $rid === $selectedId ? 'is-selected' : '' ?>">
                                    <td dir="ltr">
                                        <a href="<?= esc($buildUrl($rid)) ?>"><?= esc((string) ($row['voucher_no'] ?? '')) ?></a>
                                    </td>
                                    <td><?= esc((string) ($row['employee_name'] ?? '')) ?></td>
                                    <td><?= esc((string) ($row['type_name'] ?? '')) ?></td>
                                    <td dir="ltr"><?= esc(format_date_dmY((string) ($row['departure_date'] ?? ''))) ?></td>
                                    <td dir="ltr"><?= esc(hr_employee_departure_format_time((string) ($row['time_from'] ?? ''))) ?></td>
                                    <td dir="ltr"><?= esc(hr_employee_departure_format_time((string) ($row['time_to'] ?? ''))) ?></td>
                                    <td><?= esc(hr_employee_departure_posted_label((int) ($row['is_posted'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if ($currentRecordId > 0): ?>
            <form method="post" action="<?= esc($buildUrl($selectedId)) ?>" id="hr-emp-dep-post-form" class="hr-emp-dep-master-form" hidden>
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="post_departure">
                <input type="hidden" name="id" value="<?= (int) $currentRecordId ?>">
            </form>
            <form method="post" action="<?= esc($buildUrl($selectedId)) ?>" id="hr-emp-dep-unpost-form" class="hr-emp-dep-master-form" hidden>
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="unpost_departure">
                <input type="hidden" name="id" value="<?= (int) $currentRecordId ?>">
            </form>
            <form method="post" action="<?= esc($buildUrl()) ?>" id="hr-emp-dep-delete-form" class="hr-emp-dep-master-form" hidden>
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $currentRecordId ?>">
            </form>
        <?php endif; ?>
    </div>
</div>
