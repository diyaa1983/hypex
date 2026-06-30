<?php
declare(strict_types=1);

require_once app_path('includes/sales_return_post.php');
require_once app_path('includes/sal_return_schema.php');

$pdo = db();
$schemaOk = sal_return_ensure_schema($pdo);

require_once app_path('includes/sal_return_invoices.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/sal_invoice_schema.php');
crm_ledger_ensure_schema($pdo);
sal_invoice_ensure_schema($pdo);

if (($_GET['ajax'] ?? '') === 'invoices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $invoices = $customerId > 0 ? sal_return_invoices_for_customer($pdo, $customerId) : [];
    echo json_encode(['ok' => true, 'invoices' => $invoices], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'lines' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once app_path('includes/sal_invoice_post.php');
    require_once app_path('includes/sal_return_invoice_lines.php');
    header('Content-Type: application/json; charset=utf-8');
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($invoiceId < 1) {
        echo json_encode(['ok' => false, 'error' => 'invoice_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $invSt = $pdo->prepare('SELECT id, customer_id, invoice_no, status FROM sal_invoice WHERE id = ? LIMIT 1');
    $invSt->execute([$invoiceId]);
    $inv = $invSt->fetch();
    if (!$inv || (string) $inv['status'] !== 'confirmed') {
        echo json_encode(['ok' => false, 'error' => 'invoice_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($customerId > 0 && (int) $inv['customer_id'] !== $customerId) {
        echo json_encode(['ok' => false, 'error' => 'customer_mismatch'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!sal_invoice_is_posted($pdo, $invoiceId)) {
        echo json_encode(
            [
                'ok' => false,
                'error' => 'invoice_not_posted',
                'message' => 'لا يمكن إرجاع إلا فواتير مبيعات مرحّلة. رحّل الفاتورة أولاً من «ترحيل فواتير المبيعات».',
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
    $lines = sal_return_fetch_invoice_lines($pdo, $invoiceId);
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
    handle_sales_return_post();
}

$flash = flash_get();
$customers = $pdo->query('SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar')->fetchAll();

/** فواتير مؤكدة مجمّعة حسب العميل — تُحمَّل مع الصفحة دون انتظار AJAX */
$invoicesByCustomer = [];
$linesByInvoice = [];
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_return_invoice_lines.php');
$invRows = $pdo->query(
    "SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.customer_id
     FROM sal_invoice i
     WHERE i.status = 'confirmed'
     ORDER BY i.invoice_date DESC, i.id DESC
     LIMIT 500"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($invRows as $row) {
    $cid = (int) ($row['customer_id'] ?? 0);
    $iid = (int) ($row['id'] ?? 0);
    if ($cid < 1 || $iid < 1) {
        continue;
    }
    if (!sal_invoice_is_posted($pdo, $iid)) {
        continue;
    }
    $returnLines = sal_return_fetch_invoice_lines($pdo, $iid);
    if ($returnLines === []) {
        continue;
    }
    unset($row['customer_id']);
    $row['invoice_date'] = format_date_dmY((string) ($row['invoice_date'] ?? ''));
    $row['is_posted'] = 1;
    $invoicesByCustomer[$cid][] = $row;
    $linesByInvoice[$iid] = $returnLines;
}
$invoicesByCustomerJson = json_encode($invoicesByCustomer, JSON_UNESCAPED_UNICODE);
if ($invoicesByCustomerJson === false) {
    $invoicesByCustomerJson = '{}';
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
$newReturnUrl = app_url('index.php?r=sales_returns');
require_once app_path('includes/nav_helpers.php');
$exitUrl = nav_exit_url($activeRoute ?? 'sales_returns');
$apiInvoices = app_url('index.php?r=sales_returns&ajax=invoices');
$apiLines = app_url('index.php?r=sales_returns&ajax=lines');
$apiReturn = app_url('api/sales_return_view.php');
$apiPostReturn = app_url('api/sales_return_post.php');
$apiUnpostReturn = app_url('api/sales_return_unpost.php');
$canUnpostReturn = user_can_action('action_unpost_sales_return');
$apiDeleteReturn = app_url('api/sales_return_delete.php');
require_once app_path('includes/fin_voucher_archive.php');
fin_voucher_archive_ensure_schema($pdo);
$canArchiveReturn = user_can_action('action_archive_sales_return');
$archiveApiUrl = app_url('api/fin_voucher_archive.php');
$archiveCssPath = app_path('assets/css/fin-voucher-archive.css');
$archiveCssUrl = app_url('assets/css/fin-voucher-archive.css') . (is_file($archiveCssPath) ? '?v=' . (string) filemtime($archiveCssPath) : '');
$archiveJsPath = app_path('assets/js/fin-voucher-archive.js');
$archiveJsUrl = app_url('assets/js/fin-voucher-archive.js') . (is_file($archiveJsPath) ? '?v=' . (string) filemtime($archiveJsPath) : '');
$apiEinvoiceReturn = app_url('api/sales_return_einvoice_send.php');
$apiSendEmail = app_url('api/document_send_email.php');
$listReturnsUrl = app_url('index.php?r=sales_returns_list');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssRetPath = app_path('assets/css/sales-return.css');
$cssRet = app_url('assets/css/sales-return.css') . (is_file($cssRetPath) ? '?v=' . (string) filemtime($cssRetPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
$jsRetPath = app_path('assets/js/sales-return.js');
$jsRet = app_url('assets/js/sales-return.js') . '?v=' . (is_readable($jsRetPath) ? (string) filemtime($jsRetPath) : '1');
$jsInvPrintPath = app_path('assets/js/inv-invoice-print.js');
$jsInvPrint = app_url('assets/js/inv-invoice-print.js') . (is_file($jsInvPrintPath) ? '?v=' . (string) filemtime($jsInvPrintPath) : '');
$ledgerView = nav_is_ledger_view_request();
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/ledger_document_view_assets.php');
$screenTitle = $ledgerView ? 'عرض مرتجع مبيعات' : 'مرتجع مبيعات';
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssRet) ?>">
<link rel="stylesheet" href="<?= esc($archiveCssUrl) ?>">
<?php
require_once app_path('includes/customer_picker.php');
customer_picker_enqueue_assets();
ledger_document_view_enqueue_assets();
customer_picker_json_script($customers, 'sales-ret-customers-json');
?>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-inv-bold sales-ret-wrap" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="ret_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden></span>
            <span id="ret_einv_badge" class="sales-inv-posted-badge badge badge-ok" hidden title="حالة الفوترة"></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'sales_returns'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if (!$schemaOk): ?>
        <?php $schemaErr = sal_return_schema_last_error(); ?>
        <div class="alert alert-error sales-inv-grid-flash">
            تعذر تجهيز جداول مرتجع المبيعات.
            <?php if ($schemaErr): ?>
                <br><span class="muted" style="font-size:0.85rem;"><?= esc($schemaErr) ?></span>
            <?php else: ?>
                نفّذ من phpMyAdmin: <code>database/migrations/007_sal_return.sql</code>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <?php require app_path('includes/ledger_back_button.php'); ?>
        <?php if (!$ledgerView): ?>
            <a class="dashboard-ora-btn dashboard-ora-btn--primary sales-inv-btn-new sales-ret-btn-new" href="<?= esc($newReturnUrl) ?>">+ مرتجع جديد</a>
        <?php endif; ?>
    </div>

    <form id="sales-ret-form" class="master-page-form sales-ret-form" method="post" action="<?= esc(app_url('index.php?r=sales_returns')) ?>"
          data-app-busy-msg="جاري حفظ المرتجع..."
          data-api-invoices="<?= esc($apiInvoices) ?>"
          data-api-lines="<?= esc($apiLines) ?>"
          data-api-return="<?= esc($apiReturn) ?>"
          data-return-post-url="<?= esc($apiPostReturn) ?>"
          data-return-unpost-url="<?= esc($apiUnpostReturn) ?>"
          data-can-unpost="<?= $canUnpostReturn ? '1' : '0' ?>"
          data-can-archive="<?= $canArchiveReturn ? '1' : '0' ?>"
          data-archive-api="<?= esc($archiveApiUrl) ?>"
          data-archive-kind="sales_return"
          data-return-delete-url="<?= esc($apiDeleteReturn) ?>"
          data-return-einvoice-url="<?= esc($apiEinvoiceReturn) ?>"
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
            <h2 class="dashboard-ora-panel__title">بيانات المرتجع</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="ret_no">رقم الإرجاع</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="ret_no_prev" title="المرتجع السابق" aria-label="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" id="ret_no" value="" placeholder="001-2026" title="رقم المرتجع — الأسهم للتنقل">
                        <button type="button" class="sales-inv-no-arrow" id="ret_no_next" title="المرتجع التالي" aria-label="التالي">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="ret_date">تاريخ الإرجاع</label>
                    <input class="input input-compact js-date-dmy" type="text" name="return_date" id="ret_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr"
                           autocomplete="off" inputmode="numeric" required>
                </div>
                <?= customer_picker_field([
                    'id' => 'ret_customer',
                    'label' => 'العميل',
                    'compact' => true,
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                    'json_id' => 'sales-ret-customers-json',
                    'manual_bind' => true,
                ]) ?>
                <div class="sales-inv-meta-item sales-ret-meta-invoice">
                    <label for="ret_invoice">فاتورة البيع</label>
                    <select class="input input-compact" name="invoice_id" id="ret_invoice" required disabled
                            onchange="if (window.SalesRetPickInvoice) SalesRetPickInvoice(this);">
                        <option value="">— اختر العميل أولًا —</option>
                    </select>
                </div>
            </div>
        </header>
            </div>
        </section>

        <div class="sales-inv-print-area" id="sales-ret-print-area">
            <div class="sales-inv-print-only">
                <?= document_print_header_html('مرتجع مبيعات', $pdo) ?>
            </div>

            <section class="dashboard-ora-panel sales-inv-card">
                <h2 class="dashboard-ora-panel__title no-print">مواد المرتجع</h2>
                <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <p id="sales-ret-hint" class="muted sales-ret-hint no-print" style="padding:0.55rem;margin:0;">
                    يُسمح بإرجاع <strong>فواتير بيع مرحّلة</strong> فقط. اختر العميل والفاتورة — حدّد ☑ المواد وكميات الإرجاع (والكمية الإضافية إن وُجدت) ثم احفظ؛ يُطبَّق الأثر <strong>مستودعياً</strong> (إرجاع الكميات) و<strong>مالياً</strong> (تخفيض ذمة العميل) تلقائياً.
                </p>
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
                            <th class="sales-inv-col-qty-extra" title="كمية إضافية للمخزون — بدون أثر مالي">ك. إضافية</th>
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
                        <div id="ret_reason_return_wrap" class="sales-ret-reason-wrap" hidden style="margin-top:0.5rem;">
                            <label for="ret_reason_return" style="display:block;font-size:0.82rem;font-weight:600;color:#991b1b;margin-bottom:0.25rem;">
                                سبب الإرجاع <span style="color:#dc2626;">*</span>
                                <span style="color:#7f1d1d;font-size:0.78em;font-weight:400;">(إلزامي لإرسال الإرجاع للفوترة)</span>
                            </label>
                            <textarea class="input" name="reason_return" id="ret_reason_return" rows="2"
                                      style="width:100%;font-size:0.85rem;padding:0.4rem 0.55rem;border:1px solid #fca5a5;background:#fff5f5;"
                                      placeholder="مثال: المنتج معيب / خطأ في الكمية"></textarea>
                        </div>
                    </div>
                    <div class="sales-inv-totals">
                        <div class="row"><span>المجموع بدون ضريبة</span><span id="sales-ret-sum-sub"><?= esc(format_amount(0)) ?></span></div>
                        <div class="row"><span>مجموع الضريبة</span><span id="sales-ret-sum-tax"><?= esc(format_amount(0)) ?></span></div>
                        <div class="row grand"><span>الإجمالي</span><span id="sales-ret-sum-grand"><?= esc(format_amount(0)) ?></span></div>
                    </div>
                </div>
                </div>
            </section>
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
        <td class="sales-inv-col-qty-extra">
            <input type="number" class="input input-num js-qty-extra-ret" min="0" step="any" placeholder="0" disabled title="كمية إضافية للمخزون — بدون أثر مالي">
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

<script type="application/json" id="sales-ret-invoices-by-customer"><?= $invoicesByCustomerJson ?></script>
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
      sel.innerHTML = '<option value="">— اختر العميل أولًا —</option>';
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
      ph.textContent = '— لا توجد فواتير قابلة للإرجاع —';
      sel.disabled = true;
      if (hint) {
        hint.textContent =
          'لا توجد فواتير مرحّلة بكميات متبقية للإرجاع لهذا العميل.';
      }
      return;
    }
    sel.disabled = false;
    sel.removeAttribute('disabled');
    list.forEach(function (inv) {
      var o = document.createElement('option');
      o.value = String(inv.id);
      var label = (inv.invoice_no || '#' + inv.id) + ' — ' + (inv.invoice_date || '');
      if (inv.total != null && inv.total !== '') label += ' (' + inv.total + ')';
      o.textContent = label;
      o.setAttribute('data-posted', '1');
      sel.appendChild(o);
    });
    if (hint) {
      hint.textContent =
        'اختر فاتورة بيع مرحّلة — ستظهر موادها في الجدول. حدّد ☑ ما تريد إرجاعه.';
    }
  }

  function invoiceLines(invoiceId) {
    var iid = String(invoiceId || '');
    if (!iid) return [];
    return linesByInvoice[iid] || linesByInvoice[parseInt(iid, 10)] || [];
  }

  w.SalesRetPickCustomer = function (el) {
    var custId = '';
    if (el && el.value !== undefined && el.value !== null) {
      custId = String(el.value);
    } else {
      var h = document.getElementById('ret_customer');
      custId = h && h.value ? String(h.value) : '';
    }
    fillInvoices(custId);
    var invSel = document.getElementById('ret_invoice');
    if (invSel) {
      invSel.value = '';
      w.SalesRetPickInvoice(invSel);
    }
    try {
      document.dispatchEvent(new CustomEvent('sales-ret-customer-picked', { detail: { customerId: custId } }));
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
          'لا يمكن إرجاع إلا فواتير مرحّلة. اختر فاتورة من القائمة أو رحّل الفاتورة من «ترحيل فواتير المبيعات».';
      }
      lines = [];
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

  w.SalesRetSetInvoicesForCustomer = function (customerId, invoices) {
    var cid = String(customerId || '');
    if (!cid) return;
    byCustomer[cid] = Array.isArray(invoices) ? invoices : [];
    byCustomer[parseInt(cid, 10)] = byCustomer[cid];
  };

  w.SalesRetSetLinesForInvoice = function (invoiceId, lines) {
    var iid = String(invoiceId || '');
    if (!iid) return;
    if (lines && lines.length) {
      linesByInvoice[iid] = lines;
      linesByInvoice[parseInt(iid, 10)] = lines;
    } else {
      delete linesByInvoice[iid];
      delete linesByInvoice[parseInt(iid, 10)];
    }
  };
})(window);
</script>
<script src="<?= esc($archiveJsUrl) ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" defer crossorigin="anonymous"></script>
<script src="<?= esc($jsInvPrint) ?>" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc(app_url('assets/js/doc-send-email.js')) ?>" defer></script>
<script src="<?= esc($jsRet) ?>" defer></script>
