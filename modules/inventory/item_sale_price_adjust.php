<?php
declare(strict_types=1);

require_permission('item_sale_price_adjust');

require_once app_path('includes/inv_item_sale_price_adj.php');
require_once app_path('includes/inv_price_adj_schema.php');
require_once app_path('includes/inv_price_adj_save.php');
require_once app_path('includes/inv_price_adj_post.php');
require_once app_path('includes/inv_price_adj_load.php');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
$schemaReady = false;
$schemaError = '';
try {
    $schemaReady = inv_price_adj_ensure_schema($pdo);
    if (!$schemaReady) {
        $schemaError = 'جداول تعديل الأسعار غير جاهزة. نفّذ ترحيل قاعدة البيانات 091_inv_price_adj_doc.sql ثم حدّث الصفحة.';
    }
} catch (Throwable $e) {
    $schemaError = 'تعذر تهيئة جداول تعديل الأسعار: ' . $e->getMessage();
}

$listUrl = app_url('index.php?r=item_sale_price_adjust');
$apiItems = app_url('api/items_search.php');
$apiDoc = app_url('api/price_adj_view.php');
$formId = 'item-price-adj-form';
$unitPriceDp = company_invoice_unit_price_decimal_places($pdo);
$unitPriceStep = company_invoice_unit_price_decimal_step($pdo);
$defaultTax = inv_item_sale_price_adj_default_tax_percent($pdo);
$canPost = user_can_action('action_post_item_sale_price_adjust');

$docId = (int) ($_GET['id'] ?? 0);
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $postDocId = (int) ($_POST['doc_id'] ?? 0);
        $linesJson = (string) ($_POST['lines_json'] ?? '[]');
        $lines = json_decode($linesJson, true);
        if (!is_array($lines)) {
            $lines = [];
        }
        $userId = (int) (current_user()['id'] ?? 0);
        $hubQ = nav_hub_query_for_redirect();

        if ($action === 'save') {
            $res = inv_price_adj_save(
                $pdo,
                $postDocId,
                (string) ($_POST['adj_date'] ?? ''),
                $lines,
                $userId,
                trim((string) ($_POST['notes'] ?? ''))
            );
            if ($res['ok']) {
                flash_set('success', 'تم حفظ حركة تعديل الأسعار (مسودة).');
                redirect($listUrl . '&id=' . (int) $res['id'] . $hubQ);
            }
            $msg = $res['error'] ?? 'تعذر الحفظ.';
            $msgType = 'error';
            $docId = $postDocId;
        } elseif ($action === 'post') {
            if ($postDocId < 1) {
                $msg = 'احفظ الحركة أولاً.';
                $msgType = 'error';
            } elseif (!$canPost) {
                $msg = 'ليس لديك صلاحية الترحيل.';
                $msgType = 'error';
            } else {
                $res = inv_price_adj_post_document($pdo, $postDocId);
                if ($res['ok']) {
                    flash_set('success', 'تم ترحيل الأسعار وتحديث بطاقات المواد.');
                    redirect($listUrl . '&id=' . $postDocId . $hubQ);
                }
                $msg = $res['error'] ?? 'تعذر الترحيل.';
                $msgType = 'error';
                $docId = $postDocId;
            }
        } else {
            $msg = 'إجراء غير معروف.';
            $msgType = 'error';
        }
    }
}

$doc = $docId > 0 ? inv_price_adj_fetch_by_id($pdo, $docId) : null;
$isPosted = $doc !== null && (string) ($doc['status'] ?? '') === 'posted';
$adjNo = $doc !== null ? (string) ($doc['adj_no'] ?? '') : '';
$adjDateIso = $doc !== null ? (string) ($doc['adj_date'] ?? date('Y-m-d')) : date('Y-m-d');
$adjDateDisplay = format_date_dmY($adjDateIso);
$notes = $doc !== null ? trim((string) ($doc['notes'] ?? '')) : '';

$linesJson = '[]';
if ($doc !== null && ($doc['lines'] ?? []) !== []) {
    $linesJson = json_encode($doc['lines'], JSON_UNESCAPED_UNICODE);
    if ($linesJson === false) {
        $linesJson = '[]';
    }
}

$reportCssPath = app_path('assets/css/report-sales.css');
$reportCssUrl = app_url('assets/css/report-sales.css') . (is_file($reportCssPath) ? '?v=' . (string) filemtime($reportCssPath) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssPath = app_path('assets/css/item-sale-price-adjust.css');
$cssUrl = app_url('assets/css/item-sale-price-adjust.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$jsPath = app_path('assets/js/item-sale-price-adjust.js');
$jsUrl = app_url('assets/js/item-sale-price-adjust.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$jsItemDisplayPath = app_path('assets/js/inv-item-display.js');
$jsItemDisplayUrl = app_url('assets/js/inv-item-display.js')
    . (is_file($jsItemDisplayPath) ? '?v=' . (string) filemtime($jsItemDisplayPath) : '');

$hubQ = nav_hub_query_for_redirect();
$pageTitle = 'تعديل أسعار المواد';
?>
<link rel="stylesheet" href="<?= esc($reportCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php item_picker_enqueue_assets(); ?>

<div class="card item-price-adj-page report-sales-page master-page"
     data-report-route="item_sale_price_adjust">

    <?php if ($schemaError !== ''): ?>
        <div class="alert alert-error no-print"><?= esc($schemaError) ?></div>
    <?php endif; ?>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> no-print"><?= esc($msg) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= esc($listUrl) ?>" id="<?= esc($formId) ?>"
          class="item-price-adj-main-form no-print<?= $isPosted ? ' item-price-adj-form-is-posted' : '' ?><?= $docId > 0 ? ' item-price-adj-form-is-saved' : '' ?>"
          data-api-items="<?= esc($apiItems) ?>"
          data-api-doc="<?= esc($apiDoc) ?>"
          data-initial-id="<?= (int) $docId ?>"
          data-new-url="<?= esc($listUrl . $hubQ) ?>"
          data-unit-price-step="<?= esc($unitPriceStep) ?>"
          data-default-tax="<?= esc((string) $defaultTax) ?>"
          data-can-post="<?= $canPost ? '1' : '0' ?>"
          data-schema-ready="<?= $schemaReady ? '1' : '0' ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" id="item-price-adj-action" value="save">
        <input type="hidden" name="doc_id" id="item-price-adj-doc-id" value="<?= $docId > 0 ? (string) $docId : '' ?>">
        <input type="hidden" name="lines_json" id="item-price-adj-lines-json" value="">
        <?php
        if ($hubQ !== ''):
            parse_str(ltrim($hubQ, '&'), $hubParams);
            foreach ($hubParams as $hk => $hv): ?>
                <input type="hidden" name="<?= esc((string) $hk) ?>" value="<?= esc((string) $hv) ?>">
            <?php endforeach;
        endif; ?>

        <header class="item-price-adj-doc-header">
            <div class="item-price-adj-header-bar no-print">
                <a class="btn btn-primary btn-sm" href="<?= esc($listUrl . $hubQ) ?>">+ تعديل جديد</a>
                <?php if (!$isPosted): ?>
                    <button type="button" class="btn btn-secondary btn-sm" id="item-price-adj-add-line">
                        + إضافة مادة
                    </button>
                <?php endif; ?>
            </div>
            <h2 class="item-price-adj-doc-title"><?= esc($pageTitle) ?></h2>
            <div class="item-price-adj-meta-row no-print">
                <div class="item-price-adj-meta-item item-price-adj-meta-no">
                    <label for="item-price-adj-no">رقم الحركة</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="item-price-adj-no-prev"
                                title="الحركة السابقة" aria-label="السابقة">‹</button>
                        <input type="text" class="input input-compact sales-inv-no-input" id="item-price-adj-no"
                               value="<?= esc($adjNo) ?>" readonly
                               title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب الرقم واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="item-price-adj-no-next"
                                title="الحركة التالية" aria-label="التالية">›</button>
                    </div>
                </div>
                <div class="item-price-adj-meta-item">
                    <label for="item-price-adj-date">تاريخ الحركة *</label>
                    <input type="text" class="input input-compact js-date-dmy" name="adj_date" id="item-price-adj-date"
                           required value="<?= esc($adjDateDisplay) ?>"
                        <?= $isPosted ? 'readonly' : '' ?>>
                </div>
                <?php if ($isPosted): ?>
                    <div class="item-price-adj-meta-item item-price-adj-meta-status">
                        <span class="item-price-adj-meta-status-label">الحالة</span>
                        <strong class="item-price-adj-status-posted">مرحّل</strong>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <div class="report-sales-table-wrap item-price-adj-table-wrap">
            <table class="report-sales-table item-price-adj-table" id="item-price-adj-table" dir="rtl">
                <thead>
                <tr>
                    <th class="col-seq">تسلسل</th>
                    <th class="col-inv-no">رقم المادة</th>
                    <th class="col-item">اسم المادة</th>
                    <th class="col-money">السعر الحالي</th>
                    <th class="col-money">السعر المعدّل</th>
                    <th class="col-tax">الضريبة</th>
                    <?php if (!$isPosted): ?>
                        <th class="col-del" aria-label="حذف"></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody id="item-price-adj-lines-body"></tbody>
            </table>
        </div>

        <div class="item-price-adj-notes-block no-print">
            <label class="field" for="item-price-adj-notes">
                <span class="field-label">ملاحظات</span>
                <textarea class="input" name="notes" id="item-price-adj-notes" rows="2" maxlength="500"
                          placeholder="اختياري" <?= $isPosted ? 'readonly' : '' ?>><?= esc($notes) ?></textarea>
            </label>
        </div>
    </form>

    <?php require_once app_path('includes/app_icons.php'); ?>
    <template id="item-price-adj-line-tpl">
        <tr class="item-price-adj-line is-entry-row" data-item-id="">
            <td class="col-seq"><span class="js-seq"></span></td>
            <td class="col-inv-no"><code class="js-sku"></code></td>
            <td class="col-item">
                <button type="button" class="sales-inv-item-pick js-pick-open" title="اختيار المادة (F3)" aria-label="اختيار المادة (F3)">
                    <span class="js-name sales-inv-item-name is-placeholder">اضغط لاختيار المادة</span>
                    <kbd class="sales-inv-field-hotkey" aria-hidden="true">F3</kbd>
                </button>
            </td>
            <td class="col-money" dir="ltr"><span class="js-old-price">—</span></td>
            <td class="col-money">
                <input type="number" class="input input-num item-price-adj-price-inp js-new-price" min="0"
                       step="<?= esc($unitPriceStep) ?>" inputmode="decimal" dir="ltr" placeholder="0">
            </td>
            <td class="col-tax"><span class="js-tax"><?= esc(inv_item_sale_price_adj_format_tax($defaultTax)) ?></span></td>
            <td class="col-del">
                <button type="button" class="btn-icon danger js-remove" title="حذف البند" aria-label="حذف"><?= app_icon_svg('trash', 18) ?></button>
            </td>
        </tr>
    </template>
</div>

<script>
window.__ITEM_PRICE_ADJ_INITIAL_LINES__ = <?= $linesJson ?>;
</script>
<script src="<?= esc($jsItemDisplayUrl) ?>" defer></script>
<script src="<?= esc($jsUrl) ?>" defer></script>






























