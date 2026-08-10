<?php
declare(strict_types=1);

require_permission('inventory_stocktake');

require_once app_path('includes/inv_stocktake_schema.php');
require_once app_path('includes/inv_stocktake_save.php');
require_once app_path('includes/inv_stocktake_post.php');
require_once app_path('includes/inv_stocktake_delete.php');
require_once app_path('includes/inv_stocktake_load.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/sales_oracle12_ui.php');

$pdo = db();
$schemaOk = inv_stocktake_ensure_schema($pdo);

$listUrl = app_url('index.php?r=inventory_stocktake');
$apiItems = app_url('api/items_search.php');
$apiDoc = app_url('api/stocktake_view.php');
$apiStock = app_url('index.php?r=warehouse_moves&ajax=stock');
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $docId = (int) ($_POST['doc_id'] ?? 0);
        $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);
        if (!is_array($lines)) {
            $lines = [];
        }
        if ($action === 'save') {
            $res = inv_stocktake_save(
                $pdo,
                $docId,
                (string) ($_POST['take_date'] ?? ''),
                (int) ($_POST['warehouse_id'] ?? 0),
                $lines,
                (int) (current_user()['id'] ?? 0),
                trim((string) ($_POST['notes'] ?? ''))
            );
            if ($res['ok']) {
                flash_set('success', 'تم حفظ سند الجرد.');
                redirect($listUrl . '&id=' . (int) $res['id'] . nav_hub_query_for_redirect());
            }
            $msg = (string) ($res['error'] ?? 'تعذر الحفظ.');
            $msgType = 'error';
        } elseif ($action === 'post') {
            if ($docId < 1) {
                $msg = 'احفظ السند أولاً.';
                $msgType = 'error';
            } else {
                $res = inv_stocktake_post_document($pdo, $docId);
                if ($res['ok']) {
                    flash_set('success', 'تم ترحيل سند الجرد وتحديث أرصدة المخزون.');
                    redirect($listUrl . '&id=' . $docId . nav_hub_query_for_redirect());
                }
                $msg = (string) ($res['error'] ?? 'تعذر الترحيل.');
                $msgType = 'error';
            }
        } elseif ($action === 'unpost') {
            if (!user_can_action('action_unpost_inventory_stocktake')) {
                $msg = 'ليس لديك صلاحية فك ترحيل سند الجرد.';
                $msgType = 'error';
            } elseif ($docId < 1) {
                $msg = 'لا يوجد سند لفك ترحيله.';
                $msgType = 'error';
            } else {
                $res = inv_stocktake_unpost_document($pdo, $docId);
                if ($res['ok']) {
                    flash_set('success', 'تم فك ترحيل سند الجرد.');
                    redirect($listUrl . '&id=' . $docId . nav_hub_query_for_redirect());
                }
                $msg = (string) ($res['error'] ?? 'تعذر فك الترحيل.');
                $msgType = 'error';
            }
        } elseif ($action === 'delete') {
            if (!user_can_action('action_delete_inventory_stocktake')) {
                $msg = 'ليس لديك صلاحية حذف سند الجرد.';
                $msgType = 'error';
            } elseif ($docId < 1) {
                $msg = 'لا يوجد سند لحذفه.';
                $msgType = 'error';
            } else {
                $res = inv_stocktake_delete_by_id($pdo, $docId);
                if ($res['ok']) {
                    flash_set('success', (string) ($res['message'] ?? 'تم حذف سند الجرد.'));
                    redirect($listUrl . nav_hub_query_for_redirect());
                }
                $msg = (string) ($res['error'] ?? 'تعذر حذف السند.');
                $msgType = 'error';
            }
        }
    }
}

$flash = flash_get();
if ($flash && $msg === '') {
    $msg = (string) ($flash['message'] ?? '');
    $msgType = (string) ($flash['type'] ?? 'success');
}
if (!$schemaOk && $msg === '') {
    $msg = 'تعذر تجهيز جداول سندات الجرد. نفّذ migration 094_inv_stocktake.sql ثم حدّث الصفحة.';
    $msgType = 'error';
}

$docId = (int) ($_GET['id'] ?? 0);
$doc = $docId > 0 ? inv_stocktake_fetch_by_id($pdo, $docId) : null;
$isPosted = $doc !== null && !empty($doc['is_posted']);

$takeNo = $doc !== null ? (string) ($doc['take_no'] ?? '') : '';
$takeDateIso = $doc !== null ? (string) ($doc['take_date'] ?? date('Y-m-d')) : date('Y-m-d');
$takeDateDisplay = format_date_dmY($takeDateIso);
$warehouseId = $doc !== null ? (int) ($doc['warehouse_id'] ?? 0) : 0;
$warehouseName = $doc !== null ? (string) ($doc['warehouse_name'] ?? '') : '';
$notes = $doc !== null ? trim((string) ($doc['notes'] ?? '')) : '';

$warehouses = $pdo->query('SELECT id, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$linesJson = json_encode($doc['lines'] ?? [], JSON_UNESCAPED_UNICODE);
if ($linesJson === false) {
    $linesJson = '[]';
}

$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssStockPath = app_path('assets/css/stocktake.css');
$cssStock = app_url('assets/css/stocktake.css') . (is_file($cssStockPath) ? '?v=' . (string) filemtime($cssStockPath) : '');
$exportJsUrl = app_url('assets/js/report-sales-export.js');
$jsUrl = app_url('assets/js/stocktake.js');
$jsItemDisplay = app_url('assets/js/inv-item-display.js');
item_picker_enqueue_assets();
?>
<?php sales_ora12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssInv) ?>">
<link rel="stylesheet" href="<?= esc($cssStock) ?>">

<div class="dashboard-ora sales-ora12-screen stocktake-page report-sales-page master-page"
     data-report-route="inventory_stocktake"
     data-report-title="سند جرد المواد"
     data-exit-guard-root
     data-exit-url="<?= esc(nav_exit_url($activeRoute ?? 'inventory_stocktake')) ?>"
     data-export-label="<?= esc($takeNo !== '' ? ('جرد_' . $takeNo) : 'سند_جرد') ?>">

    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">سند جرد المواد</h1>
        <span class="dashboard-ora-screen-title__meta stocktake-status-badge<?= $isPosted ? ' is-posted' : ' is-draft' ?>">
            <?= $isPosted ? 'مرحّل' : 'مسودة' ?>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'inventory_stocktake'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> no-print stocktake-flash"><?= esc($msg) ?></div>
        <?php endif; ?>

        <div class="dashboard-ora-toolbar no-print stocktake-toolbar">
            <a class="dashboard-ora-btn dashboard-ora-btn--primary" href="<?= esc($listUrl . nav_hub_query_for_redirect()) ?>">سند جديد</a>
        </div>

        <form method="post" action="<?= esc($listUrl) ?>" id="stocktake-form" class="stocktake-form no-print<?= $isPosted ? ' is-posted' : '' ?>"
              data-api-items="<?= esc($apiItems) ?>"
              data-api-doc="<?= esc($apiDoc) ?>"
              data-api-stock="<?= esc($apiStock) ?>"
              data-initial-id="<?= (int) $docId ?>"
              data-new-url="<?= esc($listUrl . nav_hub_query_for_redirect()) ?>">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" id="stocktake-action" value="save">
            <input type="hidden" name="doc_id" id="stocktake-doc-id" value="<?= $docId > 0 ? (string) $docId : '' ?>">
            <input type="hidden" name="lines_json" id="stocktake-lines-json" value="">

            <section class="dashboard-ora-panel stocktake-doc-panel">
                <h2 class="dashboard-ora-panel__title">بيانات السند</h2>
                <div class="dashboard-ora-panel__body">
                    <div class="stocktake-meta">
                        <label class="field stocktake-no-wrap">
                            <span class="field-label">رقم السند</span>
                            <div class="sales-inv-no-nav">
                                <button type="button" class="sales-inv-no-arrow" id="stocktake-no-prev">‹</button>
                                <input type="text" class="input input-compact sales-inv-no-input" id="stocktake-no" value="<?= esc($takeNo) ?>">
                                <button type="button" class="sales-inv-no-arrow" id="stocktake-no-next">›</button>
                            </div>
                        </label>
                        <label class="field">
                            <span class="field-label">تاريخ السند *</span>
                            <input class="input input-compact js-date-dmy" name="take_date" id="stocktake-date" required
                                   value="<?= esc($takeDateDisplay) ?>" <?= $isPosted ? 'readonly' : '' ?>>
                        </label>
                        <label class="field">
                            <span class="field-label">المستودع *</span>
                            <select class="input input-compact" name="warehouse_id" id="stocktake-warehouse" <?= $isPosted ? 'disabled' : '' ?>>
                                <option value="">— اختر المستودع —</option>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= (int) $wh['id'] ?>" <?= $warehouseId === (int) $wh['id'] ? 'selected' : '' ?>>
                                        <?= esc((string) $wh['name_ar']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($isPosted): ?><input type="hidden" name="warehouse_id" value="<?= (int) $warehouseId ?>"><?php endif; ?>
                        </label>
                    </div>
                </div>
            </section>

            <section class="dashboard-ora-panel stocktake-lines-panel">
                <h2 class="dashboard-ora-panel__title">بنود الجرد</h2>
                <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                    <div class="table-wrap stocktake-table-wrap">
                        <table class="data-table stocktake-table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>الباركود</th>
                                <th>اسم المادة</th>
                                <th>الكمية الفعلية</th>
                                <th>كمية الجرد المدخلة</th>
                                <th>سعر الوحدة (غ ش)</th>
                                <th>الفرق</th>
                                <th>تكلفة الفرق</th>
                                <?php if (!$isPosted): ?><th></th><?php endif; ?>
                            </tr>
                            </thead>
                            <tbody id="stocktake-lines-body"></tbody>
                        </table>
                    </div>
                    <?php if (!$isPosted): ?>
                        <div class="stocktake-actions dashboard-ora-toolbar">
                            <button type="button" class="dashboard-ora-btn" id="stocktake-pick-items" title="اختيار مواد (F3)">اختيار مواد <kbd class="sales-inv-field-hotkey" aria-hidden="true">F3</kbd></button>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="dashboard-ora-panel stocktake-notes-panel">
                <h2 class="dashboard-ora-panel__title">ملاحظات</h2>
                <div class="dashboard-ora-panel__body">
                    <label class="field stocktake-notes-field">
                        <textarea class="input" name="notes" id="stocktake-notes" rows="3" <?= $isPosted ? 'readonly' : '' ?>><?= esc($notes) ?></textarea>
                    </label>
                </div>
            </section>
        </form>

        <div class="report-sales-result report-sales-print-area stocktake-print-area">
            <?= document_print_header_html('سند جرد المواد', $pdo) ?>
            <div class="doc-print-meta">
                <table>
                    <tr><td><strong>رقم السند:</strong> <span id="stocktake-print-no"><?= esc($takeNo) ?></span></td></tr>
                    <tr><td><strong>التاريخ:</strong> <span id="stocktake-print-date"><?= esc($takeDateDisplay) ?></span></td></tr>
                    <tr><td><strong>المستودع:</strong> <span id="stocktake-print-wh"><?= esc($warehouseName) ?></span></td></tr>
                </table>
            </div>
            <div class="report-sales-table-wrap">
                <table class="report-sales-table stocktake-print-table">
                    <thead>
                    <tr>
                        <th>#</th><th>الباركود</th><th>اسم المادة</th><th>الكمية الفعلية</th><th>كمية الجرد المدخلة</th><th>سعر الوحدة (غ ش)</th><th>الفرق</th><th>تكلفة الفرق</th>
                    </tr>
                    </thead>
                    <tbody id="stocktake-print-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<template id="stocktake-line-tpl">
    <tr class="stocktake-line" data-item-id="">
        <td class="js-seq"></td>
        <td><code class="js-sku"></code></td>
        <td class="js-name"></td>
        <td class="js-book" dir="ltr">0</td>
        <td><input type="number" class="input js-counted" step="any" dir="ltr"></td>
        <td class="js-unit-cost" dir="ltr">0</td>
        <td class="js-diff" dir="ltr">0</td>
        <td class="js-diff-cost" dir="ltr">0</td>
        <?php if (!$isPosted): ?>
            <td>
                <button type="button" class="stocktake-remove-btn js-remove" aria-label="حذف المادة" title="حذف المادة">×</button>
            </td>
        <?php endif; ?>
    </tr>
</template>

<script>window.__STOCKTAKE_INITIAL_LINES__ = <?= $linesJson ?>;</script>
<script src="<?= esc($jsItemDisplay) ?>" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
<script src="<?= esc($jsUrl) ?>" defer></script>
