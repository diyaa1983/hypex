<?php
declare(strict_types=1);

require_once app_path('includes/sal_sales_by_item_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
    ? (int) $_GET['customer_id']
    : -1;
$itemId = isset($_GET['item_id']) && $_GET['item_id'] !== '' ? (int) $_GET['item_id'] : -1;
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$customerName = '';
$itemLabel = '';
$itemDisplayName = '';
$showResult = false;
$err = '';

$submitted = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
    && isset($_GET['item_id']) && $_GET['item_id'] !== '';

if ($submitted) {
    if ($customerId < 0) {
        $err = 'اختر العميل أو «جميع العملاء».';
    } elseif ($itemId < 0) {
        $err = 'اختر المادة أو «جميع المواد».';
    } else {
        $fromIso = parse_date_to_iso($from);
        $toIso = parse_date_to_iso($to);
        if ($fromIso === null || $toIso === null) {
            $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
        } elseif ($fromIso > $toIso) {
            $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
        } else {
            $from = $fromIso;
            $to = $toIso;

            if ($customerId === 0) {
                $customerName = 'جميع العملاء';
            } else {
                $st = $pdo->prepare('SELECT name_ar FROM crm_customer WHERE id = ? LIMIT 1');
                $st->execute([$customerId]);
                $cust = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cust) {
                    $err = 'العميل غير موجود.';
                } else {
                    $customerName = (string) ($cust['name_ar'] ?? '');
                }
            }

            if ($err === '') {
                if ($itemId === 0) {
                    $itemLabel = 'جميع المواد';
                    $itemDisplayName = 'جميع المواد';
                } else {
                    $stItem = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id = ? AND is_active = 1 LIMIT 1');
                    $stItem->execute([$itemId]);
                    $itRow = $stItem->fetch(PDO::FETCH_ASSOC);
                    if (!$itRow) {
                        $err = 'المادة غير موجودة.';
                    } else {
                        $itemLabel = (string) ($itRow['name_ar'] ?? '');
                        $itemDisplayName = $itemLabel;
                    }
                }
            }

            if ($err === '') {
                $showResult = true;
                $rows = sal_report_sales_by_item($pdo, $customerId, $itemId, $from, $to);
            }
        }
    }
} elseif ($itemId === 0) {
    $itemDisplayName = 'جميع المواد';
} elseif ($itemId > 0) {
    $stItem = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id = ? AND is_active = 1 LIMIT 1');
    $stItem->execute([$itemId]);
    $itRow = $stItem->fetch(PDO::FETCH_ASSOC);
    if ($itRow) {
        $itemDisplayName = (string) ($itRow['name_ar'] ?? '');
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$byItemJsPath = app_path('assets/js/report-sales-by-item.js');
$byItemJsUrl = app_url('assets/js/report-sales-by-item.js') . (is_file($byItemJsPath) ? '?v=' . (string) filemtime($byItemJsPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$apiItems = app_url('api/items_search.php');

$reportTitle = 'تقرير المبيعات حسب المادة';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales_by_item"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($customerName . ' — ' . $itemLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/item_picker.php');
customer_picker_enqueue_assets();
item_picker_enqueue_assets();
customer_picker_json_script($customers, 'report-sales-item-customers-json');
?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_sales_by_item">
        <div class="form-row">
            <?= customer_picker_field([
                'id' => 'report_sales_item_cust',
                'name' => 'customer_id',
                'value' => $customerId >= 0 ? $customerId : '',
                'label' => 'العميل *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'json_id' => 'report-sales-item-customers-json',
            ]) ?>
            <?= item_picker_single_field([
                'id' => 'report_sales_item_id',
                'name' => 'item_id',
                'value' => $itemId >= 0 ? $itemId : '',
                'display_text' => $itemDisplayName,
                'label' => 'المادة *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'api_items' => $apiItems,
            ]) ?>
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td><strong>العميل:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($customerName) ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>المادة:</strong> <?= esc($itemLabel) ?></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد السطور:</strong> <?= count($rows) ?></td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-sales-table--by-item">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <col class="col-item">
                        <col class="col-date">
                        <col class="col-money">
                        <col class="col-money">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-inv-no">رقم الفاتورة</th>
                        <th class="col-item">اسم المادة</th>
                        <th class="col-date">تاريخ الفاتورة</th>
                        <th class="col-money">سعر الوحدة شامل</th>
                        <th class="col-money">سعر الوحدة غير شامل</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="6" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد بنود مبيعات مطابقة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        ?>
                        <tr>
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no"><?= esc((string) $r['invoice_no']) ?></td>
                            <td class="col-item"><span class="report-sales-item-name"><?= esc((string) $r['item_name']) ?></span></td>
                            <td class="col-date"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['unit_price_incl'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['unit_price_excl'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($byItemJsUrl) ?>" defer></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
