<?php
declare(strict_types=1);

require_once app_path('includes/purchase_return_save.php');
require_once app_path('includes/pur_return_schema.php');

$pdo = db();
$schemaOk = pur_return_ensure_schema($pdo);

require_once app_path('includes/pur_return_invoices.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/pur_invoice_schema.php');
crm_supplier_ledger_ensure_schema($pdo);
pur_invoice_ensure_schema($pdo);

if (($_GET['ajax'] ?? '') === 'invoices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $supplierId = (int) ($_GET['supplier_id'] ?? 0);
    $invoices = $supplierId > 0 ? pur_return_invoices_for_supplier($pdo, $supplierId, true) : [];
    echo json_encode(['ok' => true, 'invoices' => $invoices], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'lines' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once app_path('includes/pur_invoice_post.php');
    require_once app_path('includes/pur_return_invoice_lines.php');
    header('Content-Type: application/json; charset=utf-8');
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    $supplierId = (int) ($_GET['supplier_id'] ?? 0);
    if ($invoiceId < 1) {
        echo json_encode(['ok' => false, 'error' => 'invoice_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $invSt = $pdo->prepare('SELECT id, supplier_id, invoice_no, status FROM pur_invoice WHERE id = ? LIMIT 1');
    $invSt->execute([$invoiceId]);
    $inv = $invSt->fetch();
    if (!$inv || (string) $inv['status'] !== 'confirmed') {
        echo json_encode(['ok' => false, 'error' => 'invoice_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($supplierId > 0 && (int) $inv['supplier_id'] !== $supplierId) {
        echo json_encode(['ok' => false, 'error' => 'supplier_mismatch'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!pur_invoice_is_posted($pdo, $invoiceId)) {
        echo json_encode(
            [
                'ok' => false,
                'error' => 'invoice_not_posted',
                'message' => 'لا يمكن إرجاع إلا فواتير شراء مرحّلة. رحّل الفاتورة أولاً من «ترحيل فواتير الشراء».',
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
    $lines = pur_return_fetch_invoice_lines($pdo, $invoiceId);
    echo json_encode([
        'ok' => true,
        'invoice_no' => (string) $inv['invoice_no'],
        'is_posted' => 1,
        'lines' => $lines,
        'message' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_return') {
    handle_purchase_return_post();
}

$flash = flash_get();
$suppliers = $pdo->query('SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar')->fetchAll();

/** فواتير شراء مؤكدة مجمّعة حسب المورد */
$invoicesBySupplier = [];
$linesByInvoice = [];
require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/pur_return_invoice_lines.php');
$invRows = $pdo->query(
    "SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.supplier_id
     FROM pur_invoice i
     WHERE i.status = 'confirmed'
     ORDER BY i.invoice_date DESC, i.id DESC
     LIMIT 500"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($invRows as $row) {
    $sid = (int) ($row['supplier_id'] ?? 0);
    $iid = (int) ($row['id'] ?? 0);
    if ($sid < 1 || $iid < 1) {
        continue;
    }
    if (!pur_invoice_is_posted($pdo, $iid)) {
        continue;
    }
    $returnLines = pur_return_fetch_invoice_lines($pdo, $iid);
    if ($returnLines === []) {
        continue;
    }
    unset($row['supplier_id']);
    $row['invoice_date'] = format_date_dmY((string) ($row['invoice_date'] ?? ''));
    $row['is_posted'] = 1;
    $invoicesBySupplier[$sid][] = $row;
    $linesByInvoice[$iid] = $returnLines;
}
$invoicesBySupplierJson = json_encode($invoicesBySupplier, JSON_UNESCAPED_UNICODE);
if ($invoicesBySupplierJson === false) {
    $invoicesBySupplierJson = '{}';
}
$linesByInvoiceJson = json_encode($linesByInvoice, JSON_UNESCAPED_UNICODE);
if ($linesByInvoiceJson === false) {
    $linesByInvoiceJson = '{}';
}

$dp = company_decimal_places($pdo);
$today = date('Y-m-d');
require_once app_path('includes/document_header.php');
$docBrand = document_header_brand($pdo);
$companyNameAr = $docBrand['company_name_ar'];
$companyLogoUrl = (string) ($docBrand['logo_url'] ?? '');

$initialReturnId = (int) ($_GET['id'] ?? 0);
$newReturnUrl = app_url('index.php?r=purchase_returns');
require_once app_path('includes/nav_helpers.php');
$exitUrl = nav_exit_url($activeRoute ?? 'purchase_returns');
$apiInvoices = app_url('index.php?r=purchase_returns&ajax=invoices');
$apiLines = app_url('index.php?r=purchase_returns&ajax=lines');
$apiReturn = app_url('api/purchase_return_view.php');
$apiPostReturn = app_url('api/purchase_return_post.php');
require_once app_path('includes/fin_voucher_archive.php');
fin_voucher_archive_ensure_schema($pdo);
$canArchiveReturn = user_can_action('action_archive_purchase_return');
$archiveApiUrl = app_url('api/fin_voucher_archive.php');
$archiveCssPath = app_path('assets/css/fin-voucher-archive.css');
$archiveCssUrl = app_url('assets/css/fin-voucher-archive.css') . (is_file($archiveCssPath) ? '?v=' . (string) filemtime($archiveCssPath) : '');
$archiveJsPath = app_path('assets/js/fin-voucher-archive.js');
$archiveJsUrl = app_url('assets/js/fin-voucher-archive.js') . (is_file($archiveJsPath) ? '?v=' . (string) filemtime($archiveJsPath) : '');
$apiSendEmail = app_url('api/document_send_email.php');
$listReturnsUrl = app_url('index.php?r=purchase_returns_list');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssRetPath = app_path('assets/css/sales-return.css');
$cssRet = app_url('assets/css/sales-return.css') . (is_file($cssRetPath) ? '?v=' . (string) filemtime($cssRetPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
$jsRetPath = app_path('assets/js/purchase-return.js');
$jsRet = app_url('assets/js/purchase-return.js') . '?v=' . (is_readable($jsRetPath) ? (string) filemtime($jsRetPath) : '1');
$ledgerView = nav_is_ledger_view_request();
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/ledger_document_view_assets.php');
$screenTitle = $ledgerView ? 'عرض مردود مشتريات' : 'مردود مشتريات';
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssRet) ?>">
<link rel="stylesheet" href="<?= esc($archiveCssUrl) ?>">
<?php ledger_document_view_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-ret-wrap sales-inv-bold" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="ret_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'purchase_returns'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if (!$schemaOk): ?>
        <?php $schemaErr = pur_return_schema_last_error(); ?>
        <div class="alert alert-error">
            تعذر تجهيز جداول مردود المشتريات.
            <?php if ($schemaErr): ?>
                <br><span class="muted" style="font-size:0.85rem;"><?= esc($schemaErr) ?></span>
            <?php else: ?>
                نفّذ من phpMyAdmin: <code>database/migrations/015_purchase_returns_supplier_ledger.sql</code>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <?php require app_path('includes/ledger_back_button.php'); ?>
        <?php if (!$ledgerView): ?>
            <a class="dashboard-ora-btn dashboard-ora-btn--primary sales-inv-btn-new" href="<?= esc($newReturnUrl) ?>">+ مردود جديد</a>
        <?php endif; ?>
    </div>

    <form id="sales-ret-form" class="master-page-form sales-ret-form" method="post" action="<?= esc(app_url('index.php?r=purchase_returns')) ?>"
          data-app-busy-msg="جاري حفظ مرتجع المشتريات..."
          data-api-invoices="<?= esc($apiInvoices) ?>"
          data-api-lines="<?= esc($apiLines) ?>"
          data-api-return="<?= esc($apiReturn) ?>"
          data-return-post-url="<?= esc($apiPostReturn) ?>"
          data-can-archive="<?= $canArchiveReturn ? '1' : '0' ?>"
          data-archive-api="<?= esc($archiveApiUrl) ?>"
          data-archive-kind="purchase_return"
          data-send-email-url="<?= esc($apiSendEmail) ?>"
          data-list-url="<?= esc($listReturnsUrl) ?>"
          data-new-url="<?= esc($newReturnUrl) ?>"
          data-decimals="<?= (int) $dp ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-ledger-return-qs="<?= esc(nav_ledger_return_query_from_request()) ?>"
          data-ledger-view="<?= $ledgerView ? '1' : '0' ?>"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>"
          data-initial-id="<?= (int) $initialReturnId ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_return">
        <input type="hidden" name="lines_json" id="sales-ret-lines-json" value="[]">
        <input type="hidden" id="ret_record_id" value="">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات المردود</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="ret_no">رقم المردود</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="ret_no_prev" title="المردود السابق" aria-label="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" id="ret_no" value="" placeholder="MR001-2026" title="رقم المردود — الأسهم للتنقل">
                        <button type="button" class="sales-inv-no-arrow" id="ret_no_next" title="المردود التالي" aria-label="التالي">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="ret_date">تاريخ المردود</label>
                    <input class="input input-compact js-date-dmy" type="text" name="return_date" id="ret_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr"
                           autocomplete="off" inputmode="numeric" required>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-customer">
                    <label for="ret_supplier">المورد</label>
                    <select class="input input-compact" name="supplier_id" id="ret_supplier" required
                            onchange="if (window.SalesRetPickCustomer) SalesRetPickCustomer(this);">
                        <option value="">— اختر المورد —</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= esc((string) $s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sales-inv-meta-item sales-ret-meta-invoice">
                    <label for="ret_invoice">فاتورة الشراء</label>
                    <select class="input input-compact" name="invoice_id" id="ret_invoice" required disabled
                            onchange="if (window.SalesRetPickInvoice) SalesRetPickInvoice(this);">
                        <option value="">— اختر المورد أولًا —</option>
                    </select>
                </div>
            </div>
        </header>
            </div>
        </section>

        <div class="sales-inv-print-area" id="sales-ret-print-area">
            <div class="sales-inv-print-only">
                <?= document_print_header_html('مردود مشتريات', $pdo) ?>
            </div>

            <div class="sales-inv-card">
                <p id="sales-ret-hint" class="muted sales-ret-hint no-print">
                    اختر المورد وفاتورة الشراء — ستظهر مواد الفاتورة في الجدول. حدّد ☑ المواد المراد إرجاعها وعدّل كمية الإرجاع.
                </p>
                <div class="sales-inv-lines-header no-print">
                    <h3 class="sales-inv-lines-title">مواد المرتجع</h3>
                </div>
                <div class="sales-inv-table-wrap" id="sales-ret-table-wrap">
                    <table class="sales-inv-table sales-inv-table-grid sales-ret-table">
                        <thead>
                        <tr>
                            <th class="sales-ret-col-pick no-print" title="إرجاع">
                                <label class="sales-ret-pick-all">
                                    <input type="checkbox" id="sales-ret-pick-all" aria-label="تحديد الكل">
                                </label>
                            </th>
                            <th class="sales-inv-col-barcode">باركود</th>
                            <th class="sales-inv-col-seq">#</th>
                            <th class="sales-inv-col-item">المادة</th>
                            <th class="sales-inv-col-qty">كمية الإرجاع</th>
                            <th class="sales-inv-col-price">سعر الوحدة</th>
                            <th class="sales-inv-col-money">قبل الضريبة</th>
                            <th class="sales-inv-col-money">الضريبة</th>
                            <th class="sales-inv-col-total">مع الضريبة</th>
                        </tr>
                        </thead>
                        <tbody id="sales-ret-lines-body"></tbody>
                    </table>
                </div>
                <div class="sales-inv-footer-grid">
                    <div class="sales-inv-notes sales-inv-field no-print">
                        <label for="ret_notes">ملاحظات</label>
                        <textarea class="input" name="notes" id="ret_notes" rows="3" placeholder="اختياري"></textarea>
                    </div>
                    <div class="sales-inv-totals">
                        <div class="row"><span>المجموع بدون ضريبة</span><span id="sales-ret-sum-sub"><?= esc(format_amount(0)) ?></span></div>
                        <div class="row"><span>مجموع الضريبة</span><span id="sales-ret-sum-tax"><?= esc(format_amount(0)) ?></span></div>
                        <div class="row grand"><span>الإجمالي</span><span id="sales-ret-sum-grand"><?= esc(format_amount(0)) ?></span></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    </div>
</div>

<template id="sales-ret-line-template">
    <tr data-invoice-line-id="" data-item-id="" class="sales-ret-inv-line">
        <td class="sales-ret-pick-cell no-print">
            <input type="checkbox" class="js-ret-pick" aria-label="إرجاع هذه المادة">
        </td>
        <td class="sales-inv-col-barcode">
            <code class="sales-inv-barcode-val js-barcode-display">—</code>
        </td>
        <td class="sales-inv-col-seq"><span class="js-seq"></span></td>
        <td class="sales-inv-col-item">
            <span class="js-name sales-inv-item-name"></span>
            <span class="sales-ret-line-meta js-qty-meta"></span>
        </td>
        <td class="sales-inv-col-qty">
            <input type="number" class="input input-num js-qty-ret" min="0" step="any" placeholder="0" disabled>
        </td>
        <td class="sales-inv-col-price js-price-readonly">0</td>
        <td class="sales-inv-col-money js-line-sub">0</td>
        <td class="sales-inv-col-money js-tax-amt">0</td>
        <td class="sales-inv-col-total js-line-gross">0</td>
    </tr>
</template>

<div id="sales-inv-print-overlay" class="sales-inv-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title">معاينة الطباعة</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="sales-inv-print-close">إغلاق</button>
            </div>
        </div>
        <div class="sales-inv-print-preview-body" id="sales-inv-print-preview"></div>
    </div>
</div>

<script type="application/json" id="sales-ret-invoices-by-customer"><?= $invoicesBySupplierJson ?></script>
<script type="application/json" id="sales-ret-lines-by-invoice"><?= $linesByInvoiceJson ?></script>
<script>
(function (w) {
  'use strict';

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) return {};
    try {
      return JSON.parse(el.textContent || '{}');
    } catch (e) {
      return {};
    }
  }

  var byCustomer = readJson('sales-ret-invoices-by-customer');
  var linesByInvoice = readJson('sales-ret-lines-by-invoice');

  function fillInvoices(customerId) {
    var sel = document.getElementById('ret_invoice');
    var hint = document.getElementById('sales-ret-hint');
    if (!sel) return;
    var cid = String(customerId || '').trim();
    if (!cid) {
      sel.innerHTML = '<option value="">— اختر المورد أولًا —</option>';
      sel.disabled = true;
      return;
    }
    var list = byCustomer[cid] || byCustomer[parseInt(cid, 10)] || [];
    sel.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = '';
    ph.textContent = '— اختر فاتورة —';
    sel.appendChild(ph);
    if (!list.length) {
      ph.textContent = '— لا توجد فواتير لهذا المورد —';
      sel.disabled = true;
      if (hint) {
        hint.textContent = 'لا توجد فواتير شراء مؤكدة لهذا المورد. أنشئ فاتورة من «فاتورة شراء» أولاً.';
      }
      return;
    }
    sel.disabled = false;
    sel.removeAttribute('disabled');
    var anyPosted = false;
    list.forEach(function (inv) {
      var o = document.createElement('option');
      o.value = String(inv.id);
      var posted = inv.is_posted === 1 || inv.is_posted === true;
      if (posted) anyPosted = true;
      var label = (inv.invoice_no || '#' + inv.id) + ' — ' + (inv.invoice_date || '');
      if (inv.total != null && inv.total !== '') label += ' (' + inv.total + ')';
      if (!posted) label += ' — غير مرحّلة';
      o.textContent = label;
      o.setAttribute('data-posted', posted ? '1' : '0');
      sel.appendChild(o);
    });
    if (hint) {
      hint.textContent = anyPosted
        ? 'اختر فاتورة الشراء — ستظهر موادها في الجدول. حدّد ☑ ما تريد إرجاعه.'
        : 'الفواتير ظاهرة لكنها غير مرحّلة — رحّلها من «ترحيل فواتير الشراء» قبل الإرجاع.';
    }
  }

  function invoiceLines(invoiceId) {
    var iid = String(invoiceId || '');
    if (!iid) return [];
    return linesByInvoice[iid] || linesByInvoice[parseInt(iid, 10)] || [];
  }

  w.SalesRetPickCustomer = function (selectEl) {
    fillInvoices(selectEl && selectEl.value ? selectEl.value : '');
    var invSel = document.getElementById('ret_invoice');
    if (invSel) {
      invSel.value = '';
      w.SalesRetPickInvoice(invSel);
    }
    try {
      document.dispatchEvent(new CustomEvent('sales-ret-customer-picked', { detail: { customerId: selectEl ? selectEl.value : '' } }));
    } catch (e) {}
  };

  w.SalesRetPickInvoice = function (selectEl) {
    var iid = selectEl && selectEl.value ? selectEl.value : '';
    var lines = invoiceLines(iid);
    var posted = true;
    if (selectEl && selectEl.selectedIndex > 0) {
      var opt = selectEl.options[selectEl.selectedIndex];
      posted = opt.getAttribute('data-posted') === '1';
    }
    if (iid && !posted) {
      var hint = document.getElementById('sales-ret-hint');
      if (hint) {
        hint.textContent =
          'هذه الفاتورة غير مرحّلة — يمكنك اختيار المواد، لكن رحّلها من «ترحيل فواتير الشراء» قبل الحفظ.';
      }
    } else if (iid && !lines.length) {
      var hint0 = document.getElementById('sales-ret-hint');
      if (hint0) {
        hint0.textContent = 'لا توجد مواد متبقية للإرجاع في هذه الفاتورة.';
      }
    }
    if (w.SalesRetLoadCatalog) {
      w.SalesRetLoadCatalog(lines);
    } else if (w.SalesRetPopulateInvoiceLines) {
      w.SalesRetPopulateInvoiceLines(lines);
    } else {
      w._salesRetPendingCatalog = lines;
    }
    try {
      document.dispatchEvent(new CustomEvent('sales-ret-invoice-picked', { detail: { invoiceId: iid, lines: lines } }));
    } catch (e) {}
  };

  w.SalesRetGetInvoicesByCustomer = function () {
    return byCustomer;
  };

  w.SalesRetGetLinesByInvoice = function () {
    return linesByInvoice;
  };
})(window);
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc(app_url('assets/js/doc-send-email.js')) ?>" defer></script>
<script src="<?= esc($archiveJsUrl) ?>" defer></script>
<script src="<?= esc($jsRet) ?>" defer></script>
