<?php
declare(strict_types=1);

require_permission('warehouse_moves');

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_wh_move_post.php');
require_once app_path('includes/inv_movement_type_schema.php');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
$whMoveSchemaOk = inv_wh_move_ensure_schema($pdo);
inv_movement_type_ensure_schema($pdo);

if (($_GET['ajax'] ?? '') === 'stock' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once app_path('includes/inv_stock.php');
    header('Content-Type: application/json; charset=utf-8');
    $warehouseId = (int) ($_GET['warehouse_id'] ?? 0);
    $itemId = (int) ($_GET['item_id'] ?? 0);
    $qty = $warehouseId > 0 && $itemId > 0
        ? inv_stock_qty_on_hand($pdo, $warehouseId, $itemId)
        : 0.0;
    echo json_encode([
        'ok' => true,
        'stock_qty' => company_round_amount($qty),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$listUrl = app_url('index.php?r=warehouse_moves');
$apiStock = app_url('index.php?r=warehouse_moves&ajax=stock');
$formId = 'warehouse-move-form';
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $moveId = (int) ($_POST['move_id'] ?? 0);
        $linesJson = (string) ($_POST['lines_json'] ?? '[]');
        $lines = json_decode($linesJson, true);
        if (!is_array($lines)) {
            $lines = [];
        }
        $userId = (int) (current_user()['id'] ?? 0);

        if ($action === 'save' || $action === 'save_post') {
            $res = inv_wh_move_save(
                $pdo,
                $moveId,
                (string) ($_POST['move_date'] ?? ''),
                (string) ($_POST['movement_type_code'] ?? ''),
                (int) ($_POST['warehouse_id'] ?? 0),
                (int) ($_POST['warehouse_to_id'] ?? 0),
                trim((string) ($_POST['notes'] ?? '')),
                $lines,
                $userId
            );
            if (!$res['ok']) {
                $msg = $res['error'] ?? 'تعذر الحفظ.';
                $msgType = 'error';
            } else {
                $moveId = (int) ($res['id'] ?? 0);
                if ($action === 'save_post') {
                    $postRes = inv_wh_move_post_document($pdo, $moveId);
                    if ($postRes['ok']) {
                        $msg = 'تم حفظ الحركة وترحيلها بنجاح.';
                        if (trim((string) ($postRes['gl_warning'] ?? '')) !== '') {
                            $msg .= ' ' . trim((string) $postRes['gl_warning']);
                        }
                        $msgType = 'success';
                    } else {
                        $msg = 'تم الحفظ لكن تعذر الترحيل: ' . ($postRes['error'] ?? '');
                        $msgType = 'error';
                    }
                } else {
                    $msg = 'تم حفظ الحركة.';
                    $msgType = 'success';
                }
                redirect($listUrl . '&id=' . $moveId . nav_hub_query_for_redirect());
            }
        } elseif ($action === 'unpost') {
            if ($moveId < 1) {
                $msg = 'احفظ الحركة أولاً.';
                $msgType = 'error';
            } elseif (!user_can_action('action_unpost_warehouse_move')) {
                $msg = 'ليس لديك صلاحية فك الترحيل.';
                $msgType = 'error';
            } else {
                require_once app_path('includes/inv_wh_move_unpost.php');
                $unpostRes = inv_wh_move_unpost_document($pdo, $moveId);
                if ($unpostRes['ok']) {
                    flash_set('success', $unpostRes['message'] ?? 'تم فك ترحيل الحركة.');
                    redirect($listUrl . '&id=' . $moveId . nav_hub_query_for_redirect());
                }
                $msg = $unpostRes['error'] ?? 'تعذر فك الترحيل.';
                $msgType = 'error';
            }
        } elseif ($action === 'delete') {
            if ($moveId < 1) {
                $msg = 'لا توجد حركة محفوظة للحذف.';
                $msgType = 'error';
            } elseif (!user_can_action('action_delete_warehouse_move')) {
                $msg = 'ليس لديك صلاحية الحذف.';
                $msgType = 'error';
            } else {
                require_once app_path('includes/inv_wh_move_delete.php');
                $delRes = inv_wh_move_delete_by_id($pdo, $moveId);
                if ($delRes['ok']) {
                    flash_set('success', $delRes['message'] ?? 'تم حذف الحركة.');
                    redirect($listUrl . nav_hub_query_for_redirect());
                }
                $msg = $delRes['error'] ?? 'تعذر الحذف.';
                $msgType = 'error';
            }
        } elseif ($action === 'post') {
            if ($moveId < 1) {
                $msg = 'احفظ الحركة أولاً.';
                $msgType = 'error';
            } else {
                $postRes = inv_wh_move_post_document($pdo, $moveId);
                if ($postRes['ok']) {
                    $okMsg = 'تم ترحيل الحركة بنجاح.';
                    if (trim((string) ($postRes['gl_warning'] ?? '')) !== '') {
                        $okMsg .= ' ' . trim((string) $postRes['gl_warning']);
                    }
                    flash_set('success', $okMsg);
                    redirect($listUrl . '&id=' . $moveId . nav_hub_query_for_redirect());
                }
                $msg = $postRes['error'] ?? 'تعذر الترحيل.';
                $msgType = 'error';
            }
        } else {
            $msg = 'إجراء غير معروف.';
            $msgType = 'error';
        }
    }
}

$flash = flash_get();
if ($flash !== null && $msg === '') {
    $msg = (string) ($flash['message'] ?? '');
    $msgType = (string) ($flash['type'] ?? 'success');
}
if (!$whMoveSchemaOk && $msg === '') {
    $msg = 'تعذر تجهيز جداول حركات المستودع. حدّث الصفحة (F5). إن استمر الخطأ نفّذ ملف database/migrations/089_inv_wh_move.sql من phpMyAdmin.';
    $msgType = 'error';
}

$moveId = (int) ($_GET['id'] ?? 0);
$move = $moveId > 0 ? inv_wh_move_by_id($pdo, $moveId) : null;
$lines = $move !== null ? inv_wh_move_lines($pdo, $moveId) : [];
$isPosted = $move !== null && (string) ($move['status'] ?? '') === 'posted';

$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$movementTypes = inv_movement_types_for_picker($pdo);
$movementTypeCodes = array_map(
    static fn (array $t): string => (string) ($t['code'] ?? ''),
    $movementTypes
);

$todayIso = date('Y-m-d');
$moveDateIso = $move !== null ? (string) ($move['move_date'] ?? $todayIso) : $todayIso;
$moveDateDisplay = format_date_dmY($moveDateIso);
$moveNo = $move !== null ? (string) ($move['move_no'] ?? '') : '';
$movementTypeCode = $move !== null ? (string) ($move['movement_type_code'] ?? '') : 'adjust_in';
if ($movementTypeCode === '' || $movementTypeCode === 'adjustment' || !in_array($movementTypeCode, $movementTypeCodes, true)) {
    $movementTypeCode = in_array('adjust_in', $movementTypeCodes, true)
        ? 'adjust_in'
        : ($movementTypeCodes[0] ?? 'adjust_in');
}
$warehouseId = $move !== null ? (int) ($move['warehouse_id'] ?? 0) : 0;
$warehouseToId = $move !== null ? (int) ($move['warehouse_to_id'] ?? 0) : 0;
$notes = $move !== null ? trim((string) ($move['notes'] ?? '')) : '';

require_once app_path('includes/inv_item_display.php');
$linesJson = json_encode(array_map(static function (array $ln): array {
    return [
        'item_id' => (int) ($ln['item_id'] ?? 0),
        'sku' => inv_item_material_number_digits(
            (string) ($ln['barcode'] ?? ''),
            (string) ($ln['sku'] ?? '')
        ),
        'name_ar' => (string) ($ln['item_name'] ?? ''),
        'qty' => (float) ($ln['qty'] ?? 0),
        'on_hand' => 0,
    ];
}, $lines), JSON_UNESCAPED_UNICODE);
if ($linesJson === false) {
    $linesJson = '[]';
}

$apiItems = app_url('api/items_search.php');
$apiMove = app_url('api/warehouse_move_view.php');
$apiPrint = app_url('api/warehouse_move_print.php');
$apiUnpost = app_url('api/warehouse_move_unpost.php');
$apiDelete = app_url('api/warehouse_move_delete.php');
$canPost = user_can_action('action_post_warehouse_move');
$canUnpost = user_can_action('action_unpost_warehouse_move');
$canDelete = user_can_action('action_delete_warehouse_move');
require_once app_path('includes/document_header.php');
require_once app_path('includes/sales_oracle12_ui.php');
$docPrintCss = document_print_stylesheet_url('assets/css/document-header.css');
$reportPrintCss = document_print_stylesheet_url('assets/css/report-sales.css');
$docBrandPrint = document_header_brand($pdo);
$companyLogoPrintUrl = (string) ($docBrandPrint['logo_url'] ?? '');
$qtyDp = company_decimal_places($pdo);
$screenTitle = (string) ($pageTitle ?? 'حركات المستودع');
$newMoveUrl = $listUrl . nav_hub_query_for_redirect();

$cssPath = app_path('assets/css/warehouse-move.css');
$cssUrl = app_url('assets/css/warehouse-move.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$jsPath = app_path('assets/js/warehouse-move.js');
$jsUrl = app_url('assets/js/warehouse-move.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$jsItemDisplayPath = app_path('assets/js/inv-item-display.js');
$jsItemDisplayUrl = app_url('assets/js/inv-item-display.js')
    . (is_file($jsItemDisplayPath) ? '?v=' . (string) filemtime($jsItemDisplayPath) : '');

sales_inv_oracle12_enqueue_assets();
item_picker_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($reportPrintCss) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-inv-bold warehouse-move-page master-page report-sales-page"
     data-exit-guard="custom"
     data-report-route="warehouse_moves"
     data-active-route="warehouse_moves"
     data-api-print="<?= esc($apiPrint) ?>"
     data-move-unpost-url="<?= esc($apiUnpost) ?>"
     data-move-delete-url="<?= esc($apiDelete) ?>"
     data-doc-print-css="<?= esc($docPrintCss) ?>"
     data-report-print-css="<?= esc($reportPrintCss) ?>"
     data-company-logo-url="<?= esc($companyLogoPrintUrl) ?>"
     data-move-id="<?= $moveId ?>"
     data-is-posted="<?= $isPosted ? '1' : '0' ?>"
     data-can-post="<?= $canPost ? '1' : '0' ?>"
     data-can-unpost="<?= $canUnpost ? '1' : '0' ?>"
     data-can-delete="<?= $canDelete ? '1' : '0' ?>">

    <header class="dashboard-ora-screen-title no-print" role="banner">
        <div class="dashboard-ora-screen-title__group">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
            <div class="sales-inv-title-actions no-print">
                <a class="dashboard-ora-screen-title__action sales-inv-btn-new sales-inv-title-new"
                   href="<?= esc($newMoveUrl) ?>">+ حركة جديدة</a>
            </div>
        </div>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="wh-move-posted-badge" class="sales-inv-posted-badge badge badge-posted"<?= $isPosted ? '' : ' hidden' ?>><?= $isPosted ? 'مرحّل' : '' ?></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'warehouse_moves'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> no-print sales-inv-grid-flash"><?= esc($msg) ?></div>
    <?php endif; ?>
    <?php if ($movementTypes === []): ?>
        <div class="alert alert-error no-print sales-inv-grid-flash">
            لا توجد أنواع حركة مفعّلة.
            <a href="<?= esc(app_url('index.php?r=inv_movement_types_settings')) ?>">افتح إعداد أنواع الحركات</a>
            لتفعيل نوع واحد على الأقل.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= esc($listUrl) ?>" id="<?= esc($formId) ?>" class="warehouse-move-form master-page-form no-print<?= $isPosted ? ' wh-move-form-is-posted' : '' ?><?= $moveId > 0 ? ' wh-move-form-is-saved' : '' ?>"
          data-api-items="<?= esc($apiItems) ?>"
          data-api-stock="<?= esc($apiStock) ?>"
          data-api-move="<?= esc($apiMove) ?>"
          data-qty-dp="<?= (int) $qtyDp ?>"
          data-initial-type="<?= esc($movementTypeCode) ?>"
          data-initial-id="<?= (int) $moveId ?>"
          data-new-url="<?= esc($listUrl . nav_hub_query_for_redirect()) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" id="wh-move-action" value="save">
        <input type="hidden" name="move_id" id="wh-move-id" value="<?= $moveId > 0 ? (string) $moveId : '' ?>">
        <input type="hidden" name="lines_json" id="wh-move-lines-json" value="">
        <?php
        $hubQ = nav_hub_query_for_redirect();
        if ($hubQ !== ''):
            parse_str(ltrim($hubQ, '&'), $hubParams);
            foreach ($hubParams as $hk => $hv): ?>
                <input type="hidden" name="<?= esc((string) $hk) ?>" value="<?= esc((string) $hv) ?>">
            <?php endforeach;
        endif; ?>

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات الحركة</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel warehouse-move-doc-header">
            <div class="sales-inv-meta-row warehouse-move-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no warehouse-move-meta-no">
                    <label for="wh-move-no">رقم الحركة</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="wh-move-no-prev"
                                title="الحركة السابقة" aria-label="السابقة">‹</button>
                        <input type="text" class="input input-compact sales-inv-no-input" id="wh-move-no" readonly
                               value="<?= esc($moveNo) ?>"
                               placeholder=""
                               title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب رقم حركة محفوظة واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="wh-move-no-next"
                                title="الحركة التالية" aria-label="التالية">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="wh-move-date">تاريخ الحركة *</label>
                    <input type="text" class="input input-compact js-date-dmy" name="move_date" id="wh-move-date" required
                           value="<?= esc($moveDateDisplay) ?>"
                        <?= $isPosted ? 'readonly' : '' ?>>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-type warehouse-move-meta-type">
                    <label for="wh-move-type">نوع الحركة *</label>
                    <select class="input input-compact" name="movement_type_code" id="wh-move-type" required
                        <?= ($isPosted || $movementTypes === []) ? 'disabled' : '' ?>>
                        <?php if ($movementTypes === []): ?>
                            <option value="">— لا أنواع متاحة —</option>
                        <?php else: ?>
                            <?php foreach ($movementTypes as $mt): ?>
                                <?php
                                $mtCode = (string) ($mt['code'] ?? '');
                                $mtName = trim((string) ($mt['name_ar'] ?? ''));
                                if ($mtName === '') {
                                    $mtName = $mtCode;
                                }
                                ?>
                                <option value="<?= esc($mtCode) ?>"
                                    <?= $movementTypeCode === $mtCode ? 'selected' : '' ?>>
                                    <?= esc($mtName) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if ($isPosted): ?>
                        <input type="hidden" name="movement_type_code" value="<?= esc($movementTypeCode) ?>">
                    <?php endif; ?>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-wh">
                    <label for="wh-move-warehouse" id="wh-move-wh-label">المستودع *</label>
                    <select class="input input-compact" name="warehouse_id" id="wh-move-warehouse" required <?= $isPosted ? 'disabled' : '' ?>>
                        <?php if (!$isPosted): ?>
                            <option value="" <?= $warehouseId < 1 ? 'selected' : '' ?>>— اختر المستودع —</option>
                        <?php endif; ?>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= (int) $wh['id'] ?>"
                                <?= $warehouseId === (int) $wh['id'] ? 'selected' : '' ?>>
                                <?= esc((string) $wh['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isPosted): ?>
                        <input type="hidden" name="warehouse_id" value="<?= (string) $warehouseId ?>">
                    <?php endif; ?>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-wh wh-move-transfer-only" id="wh-move-to-wrap" hidden>
                    <label for="wh-move-warehouse-to">المستودع المستهدف *</label>
                    <select class="input input-compact" name="warehouse_to_id" id="wh-move-warehouse-to" <?= $isPosted ? 'disabled' : '' ?>>
                        <option value="">— اختر —</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= (int) $wh['id'] ?>"
                                <?= $warehouseToId === (int) $wh['id'] ? 'selected' : '' ?>>
                                <?= esc((string) $wh['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isPosted && $warehouseToId > 0): ?>
                        <input type="hidden" name="warehouse_to_id" value="<?= (string) $warehouseToId ?>">
                    <?php endif; ?>
                </div>
            </div>
            <p class="warehouse-move-hint muted" id="wh-move-hint"></p>
            <p class="wh-move-warehouse-notice sales-inv-delivery-link-hint" id="wh-move-warehouse-notice"
               <?= $warehouseId > 0 || $isPosted ? 'hidden' : '' ?>>
                اختر المستودع لعرض الكمية الحالية واختيار المواد من الجدول.
            </p>
        </header>
            </div>
        </section>

        <section class="dashboard-ora-panel sales-inv-card warehouse-move-lines-card">
            <h2 class="dashboard-ora-panel__title no-print">بنود الحركة</h2>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="sales-inv-table-wrap wh-move-table-wrap" id="wh-move-lines-panel">
            <table class="sales-inv-table wh-move-lines-table" id="wh-move-lines-table">
                <thead>
                <tr>
                    <th class="wh-col-seq">#</th>
                    <th class="wh-col-sku">رقم المادة</th>
                    <th class="wh-col-item">المادة</th>
                    <th class="wh-col-onhand" id="wh-move-onhand-col-label">الكمية الحالية</th>
                    <th class="wh-col-qty" id="wh-move-qty-col-label">الكمية</th>
                    <?php if (!$isPosted): ?>
                        <th class="wh-col-del" aria-label="حذف"></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody id="wh-move-lines-body"></tbody>
            </table>
        </div>
            <div class="sales-inv-footer-grid">
            <div class="sales-inv-notes sales-inv-field warehouse-move-notes-block">
                <label for="wh-move-notes">ملاحظات</label>
                <textarea class="input warehouse-move-notes-input" name="notes" id="wh-move-notes"
                          rows="3" maxlength="500" placeholder="ملاحظات اختيارية على الحركة (تظهر في الطباعة)"
                    <?= $isPosted ? 'readonly' : '' ?>><?= esc($notes) ?></textarea>
            </div>
            </div>
            </div>
        </section>
    </form>
    </div>

    <?php require_once app_path('includes/app_icons.php'); ?>
    <template id="wh-move-line-tpl">
        <tr class="wh-move-line is-entry-row" data-item-id="">
            <td class="wh-col-seq"><span class="js-seq"></span></td>
            <td class="wh-col-sku"><code class="js-sku"></code></td>
            <td class="wh-col-item">
                <button type="button" class="sales-inv-item-pick js-pick-open" aria-label="اختيار مادة">
                    <span class="js-name sales-inv-item-name is-placeholder"></span>
                </button>
            </td>
            <td class="wh-col-onhand"><span class="js-on-hand wh-on-hand-readonly">—</span></td>
            <td class="wh-col-qty">
                <input type="number" class="input input-num js-qty" min="0" step="any" inputmode="decimal" value=""
                       title="الكمية التي تُضاف إلى الرصيد عند الترحيل">
            </td>
            <td class="wh-col-del">
                <button type="button" class="btn-icon danger js-remove" title="حذف البند" aria-label="حذف البند"><?= app_icon_svg('trash', 18) ?></button>
            </td>
        </tr>
    </template>

</div>

<div id="wh-move-print-overlay" class="sales-inv-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title" id="wh-move-print-overlay-title">معاينة الطباعة — اضغط «طباعة» في الشريط العلوي</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="wh-move-print-close">إغلاق</button>
            </div>
        </div>
        <div class="sales-inv-print-preview-body report-sales-print-area report-sales-result doc-print-watermark-scope"
             id="wh-move-print-preview"></div>
    </div>
</div>

<script>
window.__WH_MOVE_INITIAL_LINES__ = <?= $linesJson ?>;
window.__WH_MOVE_TYPES__ = <?= json_encode(array_map(static function (array $t): array {
    return [
        'code' => (string) ($t['code'] ?? ''),
        'name_ar' => (string) ($t['name_ar'] ?? ''),
        'hint_ar' => (string) ($t['hint_ar'] ?? ''),
    ];
}, $movementTypes), JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= esc($jsItemDisplayUrl) ?>" defer></script>
<script src="<?= esc($jsUrl) ?>" defer
        data-can-post="<?= $canPost ? '1' : '0' ?>"></script>
