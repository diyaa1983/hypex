(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('sales-inv-form');
  if (!form) return;

  var ledgerView = form.getAttribute('data-ledger-view') === '1';
  var headerDiscountMode = false;

  var tbody = document.getElementById('sales-inv-lines-body');
  var tpl = document.getElementById('sales-inv-line-template');
  if (!tbody || !tpl) {
    console.error('purchase-order: missing table body or line template');
    return;
  }
  var linesJson = document.getElementById('sales-inv-lines-json');
  var apiUrl = form.getAttribute('data-api-items') || '';
  var apiOrderUrl = form.getAttribute('data-api-order') || '';
  var orderApproveUrl = form.getAttribute('data-order-approve-url') || '';
  var orderUnapproveUrl = form.getAttribute('data-order-unapprove-url') || '';
  var canUnpostByPermission = form.getAttribute('data-can-unpost') === '1';
  var einvoiceSendUrl = form.getAttribute('data-einvoice-send-url') || '';
  var sendEmailUrl = form.getAttribute('data-send-email-url') || '';
  var canSendEinvoice = form.getAttribute('data-can-send-einvoice') === '1';
  var newInvoiceUrl = form.getAttribute('data-new-url') || '';
  var initialOrderId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var currentOrderId = 0;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var docNoSearch = window.DocumentNoNav ? DocumentNoNav.createSearchState() : { matchIds: [], matchIndex: -1, query: '', currentDocNo: '' };
  var DOC_NO_SEARCH_UI = {
    noInputId: 'inv_no',
    prevBtnId: 'inv_no_prev',
    nextBtnId: 'inv_no_next',
    defaultNoTitle: 'اكتب جزءاً من رقم الطلب واضغط Enter للبحث',
  };
  var orderIsApproved = false;
  var orderIsEditable = true;
  var orderStatusLabel = '';
  var orderConvertUrl = form.getAttribute('data-order-convert-url') || '';
  var invoiceEinvQr = '';
  var invoiceEinvStatus = '';
  var invoiceEinvNum = '';
  var einvQrDataUrl = '';
  var isSavedMode = false;
  var formDirty = false;
  var formSubmitting = false;
  var suppressDirtyMark = 0;
  var draftPersistTimer = null;
  var draftKey = form.getAttribute('data-draft-key') || 'purchase_orders';
  var DOC_PRINT_TITLE = 'طلب شراء';

  function fmtDate(value) {
    return global.AppFormat && AppFormat.formatDateDmY
      ? AppFormat.formatDateDmY(value)
      : String(value == null ? '' : value);
  }

  /** حقول البحث بالرقم لا تُعتبر تعديلاً على المستند. */
  function isSearchOnlyField(el) {
    if (!el || !el.id) return false;
    return el.id === 'inv_no' || el.id === 'inv_supplier_invoice_no';
  }

  function markFormDirty() {
    if (suppressDirtyMark > 0) return;
    formDirty = true;
    schedulePersistDraft();
  }

  function markFormDirtyFromEvent(e) {
    if (e && e.target && isSearchOnlyField(e.target)) return;
    markFormDirty();
  }

  function clearFormDirty() {
    formDirty = false;
  }

  function runWithoutDirtyMark(fn) {
    suppressDirtyMark++;
    try {
      fn();
    } finally {
      suppressDirtyMark--;
    }
    clearFormDirty();
  }

  function syncInvoiceIdField() {
    var rec = document.getElementById('inv_record_id');
    if (!rec) return;
    rec.value = currentOrderId > 0 ? String(currentOrderId) : '';
  }

  function syncInvoiceNoDisplay(invoiceNo) {
    var invNo = document.getElementById('inv_no');
    if (!invNo) return;
    if (currentOrderId > 0) {
      var no =
        invoiceNo !== undefined && invoiceNo !== null
          ? String(invoiceNo)
          : invNo.value;
      invNo.value = no.trim();
    } else {
      invNo.value = '';
    }
    invNo.placeholder = '';
    updateInvoiceNoPostedStyle();
  }

  function refreshInvoiceEditState() {
    var locked = ledgerView || (currentOrderId > 0 && !orderIsEditable);
    isSavedMode = locked;
    form.classList.toggle('sales-inv-form-is-posted', locked && !ledgerView);
    form.classList.toggle('sales-inv-form-is-ledger-view', ledgerView);
    var fields = form.querySelectorAll(
      '#inv_date, #po_expected_date, #inv_payment_type, #inv_supplier, #inv_wh, #inv_notes, #inv_supplier_invoice_no'
    );
    fields.forEach(function (el) {
      if (!el) return;
      if (locked) {
        el.setAttribute('readonly', 'readonly');
        if (el.tagName === 'SELECT') el.disabled = true;
      } else {
        el.removeAttribute('readonly');
        if (el.tagName === 'SELECT') el.disabled = false;
      }
    });
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var pick = tr.querySelector('.js-pick-open');
      var rm = tr.querySelector('.js-remove');
      tr.querySelectorAll('.js-qty, .js-qty-extra, .js-price, .js-line-sub, .js-line-gross, .js-discount, .js-tax, .js-unit, .js-barcode-inp').forEach(function (inp) {
        if (locked) {
          inp.setAttribute('readonly', 'readonly');
          if (inp.tagName === 'SELECT') inp.disabled = true;
        } else {
          inp.removeAttribute('readonly');
          if (inp.tagName === 'SELECT') inp.disabled = false;
        }
      });
      if (pick) {
        if (locked) {
          pick.disabled = true;
          pick.setAttribute('aria-disabled', 'true');
          pick.classList.add('is-readonly-display');
        } else {
          pick.disabled = false;
          pick.removeAttribute('aria-disabled');
          pick.classList.remove('is-readonly-display');
        }
      }
      if (rm) rm.style.display = locked ? 'none' : '';
      if (!locked) applyRowItemPickLock(tr);
    });
    if (!locked) ensureEntryRow();
  }

  function buildDraftSnapshot() {
    syncJson();
    var invDate = document.getElementById('inv_date');
    var sup = document.getElementById('inv_supplier');
    var pay = document.getElementById('inv_payment_type');
    var wh = document.getElementById('inv_wh');
    var notes = document.getElementById('inv_notes');
    var invNo = document.getElementById('inv_no');
    var supInvNo = document.getElementById('inv_supplier_invoice_no');
    return {
      v: 1,
      currentOrderId: currentOrderId,
      orderIsApproved: orderIsApproved,
      invoice_no: invNo ? invNo.value : '',
      supplier_invoice_no: supInvNo ? supInvNo.value : '',
      invoice_date: invDate ? invDate.value : '',
      supplier_id: sup ? sup.value : '',
      payment_type: pay ? pay.value : 'cash',
      warehouse_id: wh ? wh.value : '',
      notes: notes ? notes.value : '',
      lines: JSON.parse(linesJson.value || '[]'),
    };
  }

  function clearPersistedDraft() {
    try {
      sessionStorage.removeItem('manager:inv_draft:' + draftKey);
    } catch (e) {}
  }

  function persistDraft() {
    if (orderIsApproved) {
      clearPersistedDraft();
      return;
    }
    if (!hasDraftContent() && currentOrderId < 1) {
      clearPersistedDraft();
      return;
    }
    try {
      sessionStorage.setItem('manager:inv_draft:' + draftKey, JSON.stringify(buildDraftSnapshot()));
    } catch (e) {}
  }

  function schedulePersistDraft() {
    if (suppressDirtyMark > 0 || orderIsApproved) return;
    clearTimeout(draftPersistTimer);
    draftPersistTimer = setTimeout(persistDraft, 350);
  }

  function applyDraftSnapshot(draft) {
    runWithoutDirtyMark(function () {
      tbody.innerHTML = '';
      ensureEntryRow();
      var invDate = document.getElementById('inv_date');
      if (invDate && draft.invoice_date) invDate.value = fmtDate(draft.invoice_date);
      var paySel = document.getElementById('inv_payment_type');
      if (paySel && draft.payment_type) paySel.value = draft.payment_type;
      var supSel = document.getElementById('inv_supplier');
      if (supSel && draft.supplier_id !== undefined) supSel.value = String(draft.supplier_id);
      var wh = document.getElementById('inv_wh');
      if (wh && draft.warehouse_id !== undefined) {
        wh.value = draft.warehouse_id ? String(draft.warehouse_id) : '';
        if (!wh.value) applyDefaultWarehouse();
      }
      var notes = document.getElementById('inv_notes');
      if (notes) notes.value = draft.notes || '';
      var supInvNo = document.getElementById('inv_supplier_invoice_no');
      if (supInvNo) supInvNo.value = draft.supplier_invoice_no || '';
      currentOrderId = parseInt(draft.currentOrderId, 10) || 0;
      orderIsApproved = currentOrderId > 0 && !!draft.orderIsApproved;
      syncInvoiceNoDisplay(
        currentOrderId > 0 ? draft.invoice_no || '' : ''
      );
      syncInvoiceIdField();
      (draft.lines || []).forEach(function (ln) {
        addLineFromData(ln);
      });
      if (currentOrderId < 1) ensureEntryRow();
      refreshInvoiceEditState();
      updatePostedBadge();
      renumberRows();
      applyDecimalPlacesToInvoiceScreen();
    });
  }

  function tryRestoreDraft() {
    try {
      var raw = sessionStorage.getItem('manager:inv_draft:' + draftKey);
      if (!raw) return false;
      var draft = JSON.parse(raw);
      if (!draft || draft.v !== 1) return false;
      var draftId = parseInt(draft.currentOrderId, 10) || 0;
      if (initialOrderId > 0) {
        if (draftId !== initialOrderId) return false;
      } else if (draftId > 0) {
        clearPersistedDraft();
        return false;
      }
      applyDraftSnapshot(draft);
      return true;
    } catch (e) {
      clearPersistedDraft();
      return false;
    }
  }

  function bootstrapExistingRows() {
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (!tr.dataset.itemId) tr.dataset.itemId = '';
      if (!tr.dataset.lineId) tr.dataset.lineId = newLineId();
      applyQtyPriceInputAttrs(tr);
      bindRow(tr);
      recalcRow(tr);
    });
  }

  function safeEnsureEntryRow() {
    try {
      return ensureEntryRow();
    } catch (e) {
      console.error('purchase-order: ensureEntryRow failed', e);
      return null;
    }
  }

  function discardChangesAndProceed(onProceed) {
    formSubmitting = false;
    clearFormDirty();
    clearPersistedDraft();
    if (onProceed) {
      setTimeout(onProceed, 0);
    }
  }

  function confirmUnsavedChanges(onProceed, onCancel) {
    if (global.ScreenExitGuard && typeof global.ScreenExitGuard.confirmSaveDiscardLeave === 'function') {
      global.ScreenExitGuard.confirmSaveDiscardLeave({
        when: function () {
          return formDirty && !orderIsApproved;
        },
        onSave: function (proceed) {
          trySave(proceed);
        },
        onDiscard: function (proceed) {
          discardChangesAndProceed(proceed);
        },
        onProceed: onProceed,
        onCancel: onCancel,
      });
      return;
    }
    if (!formDirty || orderIsApproved) {
      if (onProceed) onProceed();
      return;
    }
    if (onProceed) onProceed();
  }

  function parseInvoiceIdFromUrl(urlStr) {
    try {
      var u = new URL(urlStr, window.location.href);
      return parseInt(u.searchParams.get('id') || '0', 10) || 0;
    } catch (e) {
      return 0;
    }
  }

  function loadSavedInvoiceAfterSubmit(invoiceId, pageUrl, onDone) {
    fetchInvoiceResponse({ id: invoiceId }).then(function (data) {
      formSubmitting = false;
      setSaveBusy(false);
      if (data && data.ok && data.invoice) {
        applyInvoiceData(data.invoice);
        if (pageUrl) {
          window.history.replaceState({ invoiceId: invoiceId }, '', pageUrl);
        } else {
          updateHistory(invoiceId);
        }
        clearFormDirty();
        if (onDone) {
          onDone();
        } else if (global.AppDialog) {
          AppDialog.success('تم حفظ طلب الشراء بنجاح.');
        }
        return;
      }
      window.location.href = pageUrl || window.location.href;
    }).catch(function () {
      formSubmitting = false;
      setSaveBusy(false);
      window.location.href = pageUrl || window.location.href;
    });
  }

  var decimals = parseInt(form.getAttribute('data-decimals') || '', 10);
  if (isNaN(decimals) || decimals < 0) {
    decimals = global.AppFormat ? AppFormat.decimals() : 2;
  }
  var unitPriceDecimals = parseInt(form.getAttribute('data-unit-price-decimals') || '', 10);
  if (isNaN(unitPriceDecimals) || unitPriceDecimals < 0) {
    unitPriceDecimals = global.AppFormat && AppFormat.invoiceUnitPriceDecimals
      ? AppFormat.invoiceUnitPriceDecimals()
      : decimals;
  }
  var printDecimals = parseInt(form.getAttribute('data-print-decimals') || '', 10);
  if (isNaN(printDecimals) || printDecimals < 0) {
    printDecimals = decimals;
  }
  var printUnitPriceDecimals = parseInt(form.getAttribute('data-print-unit-price-decimals') || '', 10);
  if (isNaN(printUnitPriceDecimals) || printUnitPriceDecimals < 0) {
    printUnitPriceDecimals = unitPriceDecimals;
  }
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var companyName = form.getAttribute('data-company-name') || '';
  var companyLogoUrl = form.getAttribute('data-company-logo') || '';

  function buildDocPrintHeader(title) {
    if (window.DocumentHeader && typeof window.DocumentHeader.build === 'function') {
      return window.DocumentHeader.build({
        companyName: companyName,
        logoUrl: companyLogoUrl,
        title: title || '',
      });
    }
    return (
      '<header class="doc-print-header"><div class="doc-print-header-top"><div class="doc-print-header-brand"><div class="doc-print-header-co">' +
      escapeHtml(companyName) +
      '</div><div class="doc-print-header-logo"></div></div></div><div class="doc-print-header-title">' +
      escapeHtml(title || '') +
      '</div></header>'
    );
  }
  var defaultDate = form.getAttribute('data-default-date') || '';
  var defaultWarehouseId = parseInt(form.getAttribute('data-default-warehouse-id') || '0', 10) || 0;

  function applyDefaultWarehouse() {
    var wh = document.getElementById('inv_wh');
    if (!wh || defaultWarehouseId < 1) return;
    wh.value = String(defaultWarehouseId);
  }
  var activePickRow = null;

  tbody.addEventListener(
    'keydown',
    function (e) {
      if (e.key !== 'Enter') return;
      var inp = e.target.closest('.js-barcode-inp');
      if (!inp || !tbody.contains(inp)) return;
      if (handleTableArrowKey(e, inp.closest('tr[data-line-id]'), inp)) return;
      var tr = inp.closest('tr[data-line-id]');
      if (!tr) return;
      e.preventDefault();
      e.stopPropagation();
      resolveBarcodeOnRow(tr);
    },
    true
  );

  form.addEventListener('input', markFormDirtyFromEvent);
  form.addEventListener('change', markFormDirtyFromEvent);

  function fmtAmount(n) {
    if (global.AppFormat && AppFormat.fmtInvoiceAmount) {
      return AppFormat.fmtInvoiceAmount(n);
    }
    return roundToDp(n, decimals).toFixed(decimals);
  }

  function fmtPrintAmount(n) {
    return global.AppFormat && AppFormat.fmt
      ? AppFormat.fmt(n, printDecimals)
      : String(n);
  }

  function fmtPrintUnitPrice(n) {
    return global.AppFormat && AppFormat.fmt
      ? AppFormat.fmt(n, printUnitPriceDecimals)
      : fmtPrintAmount(n);
  }

  function getLineSubPrintDisplay(tr) {
    var subInp = tr.querySelector('input.js-line-sub');
    var n = subInp ? parseNum(subInp.value) : parseNum(tr.dataset.sub || 0);
    return fmtPrintAmount(n);
  }

  function getLineGrossPrintDisplay(tr) {
    var el = tr.querySelector('.js-line-gross');
    var n = 0;
    if (el) {
      n = el.tagName === 'INPUT' ? parseNum(el.value) : parseNum(tr.dataset.gross || el.textContent);
    } else {
      n = parseNum(tr.dataset.gross || 0);
    }
    return fmtPrintAmount(n);
  }

  function getLineTaxPrintDisplay(tr) {
    var el = tr.querySelector('.js-tax-amt');
    return fmtPrintAmount(el ? parseNum(el.textContent) : 0);
  }

  function getLinePricePrintDisplay(tr) {
    var el = tr.querySelector('.js-price');
    return fmtPrintUnitPrice(el ? parseNum(el.value) : 0);
  }

  function invoicePrintLineCtx() {
    return {
      escapeHtml: escapeHtml,
      getBarcodeFromRow: getBarcodeFromRow,
      getLineSubDisplay: getLineSubPrintDisplay,
      getLineGrossDisplay: getLineGrossPrintDisplay,
      fmtUnitPrice: getLinePricePrintDisplay,
      getTaxAmtDisplay: getLineTaxPrintDisplay,
      fmtAmount: fmtPrintAmount,
    };
  }

  function parseNum(v) {
    if (global.AppFormat && AppFormat.parseInvoiceDecimalInput) {
      return AppFormat.parseInvoiceDecimalInput(v);
    }
    if (v === '' || v === null || v === undefined) return 0;
    var s = String(v).replace(/,/g, '.');
    var x = parseFloat(s);
    return isFinite(x) ? x : 0;
  }

  function roundToDp(x, dp) {
    var n = Number(x);
    if (!isFinite(n)) return 0;
    dp = Math.max(0, parseInt(dp, 10) || 0);
    var factor = Math.pow(10, dp);
    return Math.round(n * factor) / factor;
  }

  function roundMoney(x) {
    return global.AppFormat && AppFormat.roundInvoiceAmount
      ? AppFormat.roundInvoiceAmount(x)
      : roundToDp(x, decimals);
  }

  function roundUnitPrice(x) {
    return global.AppFormat && AppFormat.roundInvoiceUnitPrice
      ? AppFormat.roundInvoiceUnitPrice(x)
      : roundToDp(x, unitPriceDecimals);
  }

  function lineTaxAndGrossFromSub(sub, rate) {
    var taxFactor = 1 + rate / 100;
    var gross = roundMoney(sub * taxFactor);
    var tax = roundMoney(gross - sub);
    return { gross: gross, tax: tax };
  }

  var unitPriceStep =
    global.AppFormat && AppFormat.invoiceUnitPriceInputStep
      ? AppFormat.invoiceUnitPriceInputStep()
      : '0.000001';
  var amountStep =
    global.AppFormat && AppFormat.invoicePriceInputStep
      ? AppFormat.invoicePriceInputStep()
      : '0.000001';

  function refreshInvoiceDecimalsFromSettings() {
    if (global.AppFormat && AppFormat.decimals) {
      decimals = AppFormat.decimals();
    } else {
      decimals = parseInt(form.getAttribute('data-decimals') || '', 10);
      if (isNaN(decimals) || decimals < 0) decimals = 2;
    }
    if (global.AppFormat && AppFormat.invoiceUnitPriceDecimals) {
      unitPriceDecimals = AppFormat.invoiceUnitPriceDecimals();
    } else {
      unitPriceDecimals = parseInt(form.getAttribute('data-unit-price-decimals') || '', 10);
      if (isNaN(unitPriceDecimals) || unitPriceDecimals < 0) unitPriceDecimals = decimals;
    }
    form.setAttribute('data-decimals', String(decimals));
    form.setAttribute('data-unit-price-decimals', String(unitPriceDecimals));
    unitPriceStep =
      global.AppFormat && AppFormat.invoiceUnitPriceInputStep
        ? AppFormat.invoiceUnitPriceInputStep()
        : unitPriceDecimals > 0
          ? '0.' + '0'.repeat(Math.max(0, unitPriceDecimals - 1)) + '1'
          : '1';
    amountStep =
      global.AppFormat && AppFormat.invoicePriceInputStep
        ? AppFormat.invoicePriceInputStep()
        : decimals > 0
          ? '0.' + '0'.repeat(Math.max(0, decimals - 1)) + '1'
          : '1';
  }

  function applyDecimalPlacesToInvoiceScreen() {
    refreshInvoiceDecimalsFromSettings();
    if (global.AppFormat && AppFormat.setInvoiceUnitPriceDecimals) {
      AppFormat.setInvoiceUnitPriceDecimals(unitPriceDecimals, { persist: false, silent: true });
    }
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      applyQtyPriceInputAttrs(tr);
      var itemId = parseInt(tr.dataset.itemId, 10);
      if (itemId > 0) {
        recalcRow(tr, rowAmountSource(tr), { normalizeStored: true });
      }
    });
    tbody.querySelectorAll('input.js-price').forEach(function (inp) {
      inp.value = formatUnitPriceValue(parseNum(inp.value), inp.value);
    });
    tbody.querySelectorAll('input.js-line-sub, input.js-line-gross').forEach(function (inp) {
      inp.value = formatAmountValue(parseNum(inp.value), inp.value);
    });
    recalcFooter();
    syncJson();
  }

  window.addEventListener('app:decimal-places', function () {
    if (orderIsApproved && currentOrderId > 0) {
      return;
    }
    applyDecimalPlacesToInvoiceScreen();
  });

  window.addEventListener('app:invoice-unit-price-decimals', function () {
    if (orderIsApproved && currentOrderId > 0) {
      return;
    }
    applyDecimalPlacesToInvoiceScreen();
  });

  var LINE_NAV_SELECTORS = [
    '.js-barcode-inp',
    '.js-qty',
    '.js-qty-extra',
    '.js-price',
    '.js-discount',
    '.js-line-sub',
    '.js-tax',
    '.js-line-gross',
  ];

  var ROW_QTY_LOCK_SELECTORS =
    '.js-qty-extra, .js-price, .js-discount, .js-line-sub, .js-tax, .js-unit, input.js-line-gross';

  var ROW_ITEM_LOCK_SELECTORS =
    '.js-qty, .js-qty-extra, .js-price, .js-discount, .js-line-sub, .js-tax, .js-unit, input.js-line-gross';

  function isFormLineLocked() {
    return ledgerView || orderIsApproved || (currentOrderId > 0 && !orderIsEditable);
  }

  function formatAmountValue(n, rawStr) {
    if (global.AppFormat && AppFormat.formatInvoiceDecimalInput) {
      return AppFormat.formatInvoiceDecimalInput(n, rawStr);
    }
    return fmtAmount(n);
  }

  function formatUnitPriceValue(n, rawStr) {
    if (global.AppFormat && AppFormat.formatInvoiceUnitPriceInput) {
      return AppFormat.formatInvoiceUnitPriceInput(n, rawStr);
    }
    return roundToDp(n, unitPriceDecimals).toFixed(unitPriceDecimals);
  }

  function formatPriceValue(n, rawStr) {
    return formatUnitPriceValue(n, rawStr);
  }

  function formatQtyValue(n, rawStr) {
    if (global.AppFormat && AppFormat.formatInvoiceQtyInput) {
      return AppFormat.formatInvoiceQtyInput(n, rawStr);
    }
    var x = Number(n);
    if (!isFinite(x) || Math.abs(x) < 1e-12) return '';
    if (Math.abs(x - Math.round(x)) < 1e-9) return String(Math.round(x));
    var out = String(x).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    return out === '' ? '' : out;
  }

  function applyQtyPriceInputAttrs(tr) {
    var qty = tr.querySelector('.js-qty');
    var qtyExtra = tr.querySelector('.js-qty-extra');
    var price = tr.querySelector('.js-price');
    var sub = tr.querySelector('input.js-line-sub');
    if (qty) {
      qty.setAttribute('step', '1');
      qty.setAttribute('inputmode', 'decimal');
    }
    if (qtyExtra) {
      qtyExtra.setAttribute('step', '1');
      qtyExtra.setAttribute('inputmode', 'decimal');
    }
    if (price) {
      price.setAttribute('step', unitPriceStep);
      price.setAttribute('inputmode', 'decimal');
    }
    if (sub) {
      sub.setAttribute('step', amountStep);
      sub.setAttribute('inputmode', 'decimal');
    }
    var gross = tr.querySelector('input.js-line-gross');
    if (gross) {
      gross.setAttribute('step', amountStep);
      gross.setAttribute('inputmode', 'decimal');
    }
  }

  function normalizeQtyInput(inp) {
    if (!inp) return;
    if (String(inp.value).trim() === '') {
      inp.value = '';
      return;
    }
    var n = parseNum(inp.value);
    if (n <= 0) {
      inp.value = '';
      return;
    }
    inp.value = formatQtyValue(n, inp.value);
  }

  function normalizeQtyExtraInput(inp) {
    if (!inp) return;
    if (String(inp.value).trim() === '') {
      inp.value = '';
      return;
    }
    var n = parseNum(inp.value);
    if (n < 0) n = 0;
    inp.value = formatQtyValue(n, inp.value);
  }

  function normalizeUnitPriceInput(inp) {
    if (!inp) return;
    inp.value = formatUnitPriceValue(parseNum(inp.value), inp.value);
  }

  function normalizePriceInput(inp) {
    normalizeUnitPriceInput(inp);
  }

  function normalizeSubInput(inp) {
    if (!inp) return;
    inp.value = formatAmountValue(parseNum(inp.value), inp.value);
  }

  function normalizeGrossInput(inp) {
    normalizeSubInput(inp);
  }

  function getLineSubDisplay(tr) {
    var el = tr.querySelector('input.js-line-sub') || tr.querySelector('.js-line-sub');
    if (!el) return '';
    return el.tagName === 'INPUT' ? el.value : el.textContent || '';
  }

  function getLineGrossDisplay(tr) {
    var el = tr.querySelector('.js-line-gross');
    if (!el) return '';
    if (el.tagName === 'INPUT') return el.value;
    var span = el.querySelector('.sales-inv-amt-display');
    return span ? span.textContent || '' : el.textContent || '';
  }

  function setAmtDisplayCell(el, txt, isGross) {
    if (!el) return;
    if (el.tagName === 'INPUT') {
      el.value = txt;
      return;
    }
    if (!txt) {
      el.textContent = '';
      el.innerHTML = '';
      return;
    }
    el.innerHTML =
      '<span class="sales-inv-amt-display' +
      (isGross ? ' sales-inv-amt-display--gross' : '') +
      '">' +
      escapeHtml(txt) +
      '</span>';
  }

  function setLineGrossDisplay(tr, gross) {
    var el = tr.querySelector('.js-line-gross');
    if (!el) return;
    if (el.tagName === 'INPUT' && document.activeElement === el) return;
    var txt = fmtAmount(gross);
    if (el.tagName === 'INPUT') {
      el.value = formatAmountValue(gross, '');
    } else {
      setAmtDisplayCell(el, txt, true);
    }
  }

  function rowAmountSource(tr) {
    var d = tr.dataset.amountDriver || 'unit';
    if (d === 'gross' || d === 'subtotal' || d === 'unit') return d;
    return 'unit';
  }

  function recalcRowLiveFromField(tr, el) {
    if (!tr || !el) return;
    if (!getRowItemId(tr)) return;

    if (el.classList.contains('js-qty-extra')) {
      syncJson();
      return;
    }
    if (el.classList.contains('js-discount')) {
      onLineDiscountEdited(tr);
      return;
    }

    if ((el.classList.contains('js-qty') || el.classList.contains('js-price')) && headerDiscountMode) {
      applyHeaderDiscount();
      return;
    }

    if (el.classList.contains('js-line-sub')) {
      tr.dataset.amountDriver = 'subtotal';
      recalcRow(tr, 'subtotal');
    } else if (el.classList.contains('js-line-gross')) {
      tr.dataset.amountDriver = 'gross';
      recalcRow(tr, 'gross');
    } else if (el.classList.contains('js-price')) {
      tr.dataset.amountDriver = 'unit';
      recalcRow(tr, 'unit');
    } else if (el.classList.contains('js-qty')) {
      if (rowAmountSource(tr) === 'gross') {
        recalcRow(tr, 'gross');
      } else {
        tr.dataset.amountDriver = 'unit';
        recalcRow(tr, 'unit');
      }
    } else if (el.classList.contains('js-tax')) {
      recalcRow(tr, rowAmountSource(tr));
    } else {
      recalcRow(tr, 'unit');
    }
    recalcFooter();
    syncJson();
  }

  function commitAmountFieldAndRecalc(tr, el) {
    if (!tr || !el) return;
    if (el.classList.contains('js-qty-extra')) {
      normalizeQtyExtraInput(el);
      syncJson();
      return;
    }
    if (el.classList.contains('js-qty')) normalizeQtyInput(el);
    else if (el.classList.contains('js-price')) normalizePriceInput(el);
    else if (el.classList.contains('js-line-sub')) normalizeSubInput(el);
    else if (el.classList.contains('js-line-gross')) normalizeGrossInput(el);

    if (el.classList.contains('js-discount')) {
      onLineDiscountEdited(tr);
      return;
    }

    if ((el.classList.contains('js-qty') || el.classList.contains('js-price')) && headerDiscountMode) {
      applyHeaderDiscount();
      return;
    }

    var source = 'unit';
    if (el.classList.contains('js-line-sub')) {
      source = 'subtotal';
      tr.dataset.amountDriver = 'subtotal';
    } else if (el.classList.contains('js-line-gross')) {
      source = 'gross';
      tr.dataset.amountDriver = 'gross';
    } else if (el.classList.contains('js-qty') && rowAmountSource(tr) === 'gross') {
      source = 'gross';
    } else if (el.classList.contains('js-price')) {
      tr.dataset.amountDriver = 'unit';
    } else {
      tr.dataset.amountDriver = 'unit';
    }

    recalcRow(tr, source, { normalizeStored: true });
    recalcFooter();
    syncJson();
  }

  function isAmountFieldEnterCommit(el) {
    return (
      el.classList.contains('js-qty') ||
      el.classList.contains('js-price') ||
      el.classList.contains('js-discount') ||
      el.classList.contains('js-line-sub') ||
      el.classList.contains('js-line-gross')
    );
  }

  function inferLineAmountDriver(ln) {
    if (!ln) return 'unit';
    var d = String(ln.amount_driver || '').toLowerCase();
    if (d === 'gross' || d === 'subtotal' || d === 'unit') return d;
    var qty = parseNum(ln.qty);
    var up = parseNum(ln.unit_price);
    var sub = parseNum(ln.line_subtotal != null ? ln.line_subtotal : ln.line_total);
    var tax = parseNum(ln.tax_amount);
    var gross = parseNum(ln.line_gross != null ? ln.line_gross : sub + tax);
    var rate = parseNum(ln.tax_rate_percent);
    var tol = Math.pow(10, -decimals) * 0.51;
    var base = qty > 0 && up > 0 ? roundMoney(qty * up) : 0;
    var fromUnitLine = lineTaxAndGrossFromSub(base, rate);
    var fromUnitGross = fromUnitLine.gross;
    if (gross > 0 && Math.abs(gross - fromUnitGross) >= tol) {
      return 'gross';
    }
    if (qty > 0 && up > 0 && Math.abs(sub - base) >= tol) {
      return 'subtotal';
    }
    return 'unit';
  }

  function listNavRows() {
    return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-line-id]')).filter(function (tr) {
      if (tr.classList.contains('is-entry-row')) return true;
      return parseInt(tr.dataset.itemId, 10) > 0;
    });
  }

  function getRowNavFields(tr) {
    return LINE_NAV_SELECTORS.map(function (sel) {
      return tr.querySelector(sel);
    }).filter(function (el) {
      if (!el || el.disabled || el.readOnly || el.offsetParent === null) return false;
      if (el.classList.contains('is-item-pick-locked') || el.classList.contains('is-qty-required-locked')) {
        return false;
      }
      return true;
    });
  }

  function focusFieldEl(el) {
    if (!el) return;
    el.focus();
    if (el.select && el.tagName !== 'SELECT') el.select();
  }

  function handleTableArrowKey(e, tr, el) {
    var key = e.key;
    if (key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'ArrowLeft' && key !== 'ArrowRight') {
      return false;
    }
    e.preventDefault();

    var rows = listNavRows();
    var rowIdx = rows.indexOf(tr);
    if (rowIdx < 0) return true;

    var fields = getRowNavFields(tr);
    var colIdx = fields.indexOf(el);
    if (colIdx < 0) return true;

    var isRtl = document.documentElement.getAttribute('dir') === 'rtl';
    var dRow = 0;
    var dCol = 0;
    if (key === 'ArrowUp') dRow = -1;
    else if (key === 'ArrowDown') dRow = 1;
    else if (key === 'ArrowRight') dCol = isRtl ? -1 : 1;
    else if (key === 'ArrowLeft') dCol = isRtl ? 1 : -1;

    if (dCol !== 0) {
      var newCol = colIdx + dCol;
      if (newCol >= 0 && newCol < fields.length) {
        focusFieldEl(fields[newCol]);
      }
      return true;
    }

    if (dRow !== 0) {
      if (rowNeedsQtyInput(tr)) {
        focusRowQtyField(tr);
        return true;
      }
      var newRowIdx = rowIdx + dRow;
      while (newRowIdx >= 0 && newRowIdx < rows.length) {
        var targetRow = rows[newRowIdx];
        if (getRowItemId(targetRow) < 1) {
          if (blockItemPickUntilQty(targetRow, { alert: false, actionLabel: 'الانتقال لسطر جديد' })) {
            return true;
          }
          focusRowMaterialCodeField(targetRow);
          return true;
        }
        var targetFields = getRowNavFields(targetRow);
        if (targetFields.length) {
          var pickCol = Math.min(colIdx, targetFields.length - 1);
          focusFieldEl(targetFields[pickCol]);
          return true;
        }
        newRowIdx += dRow;
      }
    }
    return true;
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function newLineId() {
    return 'L' + Date.now() + '-' + Math.random().toString(36).slice(2, 7);
  }

  function clearHeaderDiscountMode(clearLineDiscountInputs) {
    headerDiscountMode = false;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      delete tr.dataset.headerDiscShare;
      var dEl = tr.querySelector('.js-discount');
      if (dEl) {
        dEl.readOnly = false;
        if (clearLineDiscountInputs || dEl.classList.contains('is-header-discount')) {
          dEl.value = '';
        }
        dEl.classList.remove('is-header-discount');
      }
    });
  }

  function isDiscountInputEmpty(tr) {
    var el = tr.querySelector('.js-discount');
    if (!el) return true;
    return String(el.value || '').trim() === '';
  }

  function getLineDiscountAmount(tr, lineBase) {
    if (!(lineBase > 0)) {
      return 0;
    }
    if (headerDiscountMode && tr.dataset.headerDiscShare != null && tr.dataset.headerDiscShare !== '') {
      return roundMoney(parseNum(tr.dataset.headerDiscShare));
    }
    var el = tr.querySelector('.js-discount');
    if (!el || !global.InvDiscount) {
      return 0;
    }
    return global.InvDiscount.amountForBase(lineBase, el.value, roundMoney);
  }

  function recalcAllItemRows(forceUnitDriver) {
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (!getRowItemId(tr)) return;
      var src = forceUnitDriver ? 'unit' : rowAmountSource(tr);
      if (forceUnitDriver) tr.dataset.amountDriver = 'unit';
      recalcRow(tr, src);
    });
  }

  function getLineMerchandiseBeforeTax(tr) {
    var q = parseNum(tr.querySelector('.js-qty') ? tr.querySelector('.js-qty').value : 0);
    var p = parseNum(tr.querySelector('.js-price') ? tr.querySelector('.js-price').value : 0);
    return q > 0 ? roundMoney(q * p) : 0;
  }

  function applyHeaderDiscount() {
    var inpEl = document.getElementById('inv-invoice-discount');
    if (!inpEl || !global.InvDiscount) {
      return;
    }
    var raw = String(inpEl.value || '').trim();
    if (!raw) {
      clearHeaderDiscountMode(true);
      recalcAllItemRows(true);
      recalcFooter();
      syncJson();
      return;
    }
    var bases = [];
    var rows = [];
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (!getRowItemId(tr)) return;
      recalcRow(tr, rowAmountSource(tr));
      rows.push(tr);
      bases.push(getLineMerchandiseBeforeTax(tr));
    });
    if (!rows.length) {
      return;
    }
    var sumPreTax = 0;
    rows.forEach(function (tr) {
      sumPreTax += roundMoney(parseNum(tr.dataset.sub) + parseNum(tr.dataset.disc));
    });
    if (!(sumPreTax > 0)) {
      sumPreTax = bases.reduce(function (a, b) {
        return a + b;
      }, 0);
    }
    var totalDisc = global.InvDiscount.amountForBase(sumPreTax, raw, roundMoney);
    var parts = global.InvDiscount.distribute(totalDisc, bases, roundMoney);
    headerDiscountMode = true;
    rows.forEach(function (tr, i) {
      tr.dataset.headerDiscShare = String(parts[i] || 0);
      var dEl = tr.querySelector('.js-discount');
      if (dEl) {
        dEl.readOnly = true;
        dEl.classList.add('is-header-discount');
        var amt = parts[i] || 0;
        dEl.value = formatAmountValue(amt, '');
      }
    });
    recalcAllItemRows();
    recalcFooter();
    syncJson();
  }

  function ensureDiscountsBeforePrint() {
    var hdr = document.getElementById('inv-invoice-discount');
    var hdrRaw = hdr ? String(hdr.value || '').trim() : '';
    if (hdrRaw || headerDiscountMode) {
      applyHeaderDiscount();
      return;
    }
    recalcAllItemRows();
    recalcFooter();
  }

  function onLineDiscountEdited(tr) {
    clearHeaderDiscountMode(false);
    var hdr = document.getElementById('inv-invoice-discount');
    if (hdr) hdr.value = '';
    tr.dataset.amountDriver = 'unit';
    recalcRow(tr, 'unit');
    recalcFooter();
    syncJson();
  }

  function hasExplicitLineDiscount(tr) {
    if (headerDiscountMode) return true;
    return !isDiscountInputEmpty(tr);
  }

  function resolveLineBaseFromNetSub(tr, sub) {
    if (!(sub > 0)) return 0;
    if (isDiscountInputEmpty(tr)) return sub;
    var discEl = tr.querySelector('.js-discount');
    if (!discEl || !global.InvDiscount) return sub;
    var p = global.InvDiscount.parseInput(discEl.value);
    if (!p) return sub;
    if (p.type === 'percent') {
      var factor = 1 - p.value / 100;
      if (factor <= 0) return sub;
      return roundMoney(sub / factor);
    }
    return roundMoney(sub + p.value);
  }

  function recalcRow(tr, source, opts) {
    opts = opts || {};
    source = source || rowAmountSource(tr);
    var qtyEl = tr.querySelector('.js-qty');
    var priceEl = tr.querySelector('.js-price');
    var subInp = tr.querySelector('input.js-line-sub');
    var grossInp = tr.querySelector('input.js-line-gross') || tr.querySelector('.js-line-gross');
    var taxAmtEl = tr.querySelector('.js-tax-amt');
    if (!taxAmtEl) return;
    var qty = parseNum(qtyEl ? qtyEl.value : 0);
    if (qty <= 0) qty = 0;
    var taxSel = tr.querySelector('.js-tax');
    var rate = 0;
    if (taxSel && taxSel.options && taxSel.options.length > 0) {
      var tIdx = taxSel.selectedIndex >= 0 ? taxSel.selectedIndex : 0;
      rate = parseNum(taxSel.options[tIdx].getAttribute('data-rate'));
    }
    var sub;
    var price;
    var lineBase;
    var discountAmt;
    var taxAmt;
    var gross;
    var taxFactor = 1 + rate / 100;

    if (source === 'gross') {
      gross = roundMoney(parseNum(grossInp ? grossInp.value : tr.dataset.gross));
      if (!hasExplicitLineDiscount(tr)) {
        sub = taxFactor > 0 ? roundMoney(gross / taxFactor) : gross;
        price = qty > 0 ? roundUnitPrice(sub / qty) : 0;
        lineBase = sub;
        discountAmt = 0;
        taxAmt = roundMoney(gross - sub);
      } else {
        sub = taxFactor > 0 ? roundMoney(gross / taxFactor) : gross;
        taxAmt = roundMoney(gross - sub);
        lineBase = resolveLineBaseFromNetSub(tr, sub);
        discountAmt = roundMoney(Math.max(0, lineBase - sub));
        price = qty > 0 ? roundUnitPrice(lineBase / qty) : 0;
      }
      tr.dataset.lineBase = String(lineBase);
      tr.dataset.lineMerch = String(lineBase);
      if (priceEl && document.activeElement !== priceEl) {
        priceEl.value = formatUnitPriceValue(price, String(price));
      }
      if (subInp && document.activeElement !== subInp) {
        subInp.value = formatAmountValue(sub, subInp.value);
      }
      if (grossInp && (opts.normalizeStored || document.activeElement !== grossInp)) {
        grossInp.value = formatAmountValue(gross, grossInp.value);
      }
      tr.dataset.amountDriver = 'gross';
    } else {
      if (rowAmountSource(tr) === 'gross') {
        recalcRow(tr, 'gross', opts);
        return;
      }
      price = parseNum(priceEl ? priceEl.value : 0);
      if (opts.normalizeStored) price = roundUnitPrice(price);
      lineBase = qty > 0 ? roundMoney(qty * price) : 0;
      tr.dataset.lineBase = String(lineBase);
      tr.dataset.lineMerch = String(lineBase);

      if (source === 'subtotal' && subInp) {
        if (!hasExplicitLineDiscount(tr)) {
          sub = roundMoney(parseNum(subInp.value));
          price = qty > 0 ? roundUnitPrice(sub / qty) : 0;
          lineBase = sub;
          discountAmt = 0;
          if (priceEl && document.activeElement !== priceEl) {
            priceEl.value = formatUnitPriceValue(price, String(price));
          }
          if (opts.normalizeStored || document.activeElement !== subInp) {
            subInp.value = formatAmountValue(sub, subInp.value);
          }
          var subNoDiscTax = lineTaxAndGrossFromSub(sub, rate);
          gross = subNoDiscTax.gross;
          taxAmt = subNoDiscTax.tax;
        } else {
          discountAmt = getLineDiscountAmount(tr, lineBase);
          if (document.activeElement === subInp && isDiscountInputEmpty(tr)) {
            sub = roundMoney(parseNum(subInp.value));
            discountAmt = roundMoney(Math.max(0, lineBase - sub));
          } else {
            sub = roundMoney(Math.max(0, lineBase - discountAmt));
          }
          price = qty > 0 ? roundUnitPrice(sub / qty) : 0;
          if (priceEl && document.activeElement !== priceEl) {
            priceEl.value = formatUnitPriceValue(price, String(price));
          }
          if (opts.normalizeStored || document.activeElement !== subInp) {
            subInp.value = formatAmountValue(sub, subInp.value);
          }
          var subDiscTax = lineTaxAndGrossFromSub(sub, rate);
          gross = subDiscTax.gross;
          taxAmt = subDiscTax.tax;
        }
        tr.dataset.amountDriver = 'subtotal';
      } else {
        discountAmt = getLineDiscountAmount(tr, lineBase);
        sub = roundMoney(Math.max(0, lineBase - discountAmt));
        var unitLineTax = lineTaxAndGrossFromSub(sub, rate);
        gross = unitLineTax.gross;
        taxAmt = unitLineTax.tax;
        if (priceEl && (opts.normalizeStored || document.activeElement !== priceEl)) {
          priceEl.value = formatUnitPriceValue(price, String(price));
        }
        if (subInp && document.activeElement !== subInp) {
          subInp.value = formatAmountValue(sub, subInp.value);
        }
        tr.dataset.amountDriver = 'unit';
      }
    }
    setAmtDisplayCell(
      taxAmtEl,
      fmtAmount(taxAmt),
      false
    );
    setLineGrossDisplay(tr, gross);
    tr.dataset.disc = String(discountAmt);
    tr.dataset.sub = String(sub);
    tr.dataset.tax = String(taxAmt);
    tr.dataset.gross = String(gross);
    applyRowQtyLock(tr);
  }

  function recalcFooter() {
    var sub = 0;
    var tax = 0;
    var gross = 0;
    var disc = 0;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (!getRowItemId(tr)) return;
      sub += parseNum(tr.dataset.sub);
      tax += parseNum(tr.dataset.tax);
      gross += parseNum(tr.dataset.gross);
      disc += parseNum(tr.dataset.disc);
    });
    var elDisc = document.getElementById('sales-inv-sum-disc');
    if (elDisc) elDisc.textContent = fmtAmount(disc);
    document.getElementById('sales-inv-sum-sub').textContent = fmtAmount(sub);
    document.getElementById('sales-inv-sum-tax').textContent = fmtAmount(tax);
    document.getElementById('sales-inv-sum-grand').textContent = fmtAmount(gross);
  }

  function getRowItemId(tr) {
    if (!tr) return 0;
    var id = parseInt(tr.dataset.itemId, 10);
    if (id > 0) return id;
    id = parseInt(tr.getAttribute('data-item-id') || '', 10);
    return id > 0 ? id : 0;
  }

  function invoiceHasLines() {
    var hasLine = false;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (getRowItemId(tr) > 0) hasLine = true;
    });
    return hasLine;
  }

  function focusRowQtyField(tr) {
    if (!tr) return;
    var run = function () {
      var qtyEl = tr.querySelector('.js-qty');
      if (!qtyEl || qtyEl.disabled || qtyEl.classList.contains('is-item-pick-locked')) return;
      qtyEl.focus();
      if (qtyEl.select) qtyEl.select();
    };
    if (window.requestAnimationFrame) {
      window.requestAnimationFrame(function () {
        window.setTimeout(run, 0);
      });
    } else {
      window.setTimeout(run, 0);
    }
  }

  function rowStockQty(tr) {
    var qty = parseNum(tr.querySelector('.js-qty') ? tr.querySelector('.js-qty').value : 0);
    var qtyExtra = parseNum(tr.querySelector('.js-qty-extra') ? tr.querySelector('.js-qty-extra').value : 0);
    return qty + qtyExtra;
  }

  function rowNeedsQtyInput(tr) {
    return getRowItemId(tr) > 0 && rowStockQty(tr) <= 0;
  }

  function findFirstRowMissingQty() {
    var found = null;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (found) return;
      if (rowNeedsQtyInput(tr)) found = tr;
    });
    return found;
  }

  function alertQtyRequired(tr, actionLabel) {
    if (!tr || !global.AppDialog) return;
    var name = tr.dataset.nameAr || 'المادة';
    AppDialog.alert(
      'أدخل الكمية قبل ' + (actionLabel || 'المتابعة') + '.\n\nالمادة: ' + name,
      { type: 'warning', title: 'الكمية مطلوبة' }
    );
  }

  function blockItemPickUntilQty(tr, opts) {
    opts = opts || {};
    if (isFormLineLocked()) return false;

    if (tr && rowNeedsQtyInput(tr)) {
      if (opts.alert !== false) {
        alertQtyRequired(tr, opts.actionLabel || 'اختيار مادة أخرى');
      }
      focusRowQtyField(tr);
      return true;
    }

    var pending = findFirstRowMissingQty();
    if (pending && (!tr || pending !== tr)) {
      if (opts.alert !== false) {
        alertQtyRequired(pending, opts.actionLabel || 'اختيار مادة أخرى');
      }
      focusRowQtyField(pending);
      return true;
    }

    return false;
  }

  function applyRowQtyLock(tr) {
    if (!tr) return;
    if (isFormLineLocked() || getRowItemId(tr) < 1) {
      tr.querySelectorAll('.js-qty').forEach(function (el) {
        el.classList.remove('is-qty-required');
      });
      return;
    }
    var needsQty = rowStockQty(tr) <= 0;
    tr.querySelectorAll(ROW_QTY_LOCK_SELECTORS).forEach(function (el) {
      if (needsQty) {
        el.setAttribute('readonly', 'readonly');
        el.setAttribute('tabindex', '-1');
        el.classList.add('is-qty-required-locked');
        if (el.tagName === 'SELECT') el.disabled = true;
      } else if (el.classList.contains('is-qty-required-locked')) {
        el.removeAttribute('readonly');
        el.removeAttribute('tabindex');
        el.classList.remove('is-qty-required-locked');
        if (el.tagName === 'SELECT') el.disabled = false;
      }
    });
    var pick = tr.querySelector('.js-pick-open');
    if (pick) {
      pick.classList.toggle('is-qty-required-locked', needsQty);
      pick.disabled = !!needsQty;
    }
    var itemCell = tr.querySelector('.sales-inv-item-cell');
    if (itemCell) {
      itemCell.classList.toggle('is-qty-required-locked', needsQty);
    }
    var barcodeInpLock = tr.querySelector('.js-barcode-inp');
    if (barcodeInpLock && getRowItemId(tr) > 0) {
      if (needsQty) {
        barcodeInpLock.setAttribute('readonly', 'readonly');
        barcodeInpLock.classList.add('is-qty-required-locked');
      } else if (barcodeInpLock.classList.contains('is-qty-required-locked')) {
        barcodeInpLock.removeAttribute('readonly');
        barcodeInpLock.classList.remove('is-qty-required-locked');
      }
    }
    var qtyEl = tr.querySelector('.js-qty');
    if (qtyEl) {
      qtyEl.classList.toggle('is-qty-required', needsQty);
      qtyEl.removeAttribute('readonly');
      qtyEl.removeAttribute('tabindex');
      qtyEl.classList.remove('is-item-pick-locked');
    }
  }

  function blockLineNavIfQtyMissing(tr, current) {
    if (!current || !rowNeedsQtyInput(tr)) return false;
    if (
      current.classList.contains('js-qty') ||
      current.classList.contains('js-qty-extra') ||
      current.classList.contains('js-barcode-inp')
    ) {
      focusRowQtyField(tr);
      return true;
    }
    return false;
  }

  function applyRowItemPickLock(tr) {
    if (!tr) return;
    if (isFormLineLocked()) return;
    var needsPick = getRowItemId(tr) < 1;
    tr.querySelectorAll(ROW_ITEM_LOCK_SELECTORS).forEach(function (el) {
      if (needsPick) {
        el.setAttribute('readonly', 'readonly');
        el.setAttribute('tabindex', '-1');
        el.classList.add('is-item-pick-locked');
        if (el.tagName === 'SELECT') el.disabled = true;
      } else {
        el.removeAttribute('readonly');
        el.removeAttribute('tabindex');
        el.classList.remove('is-item-pick-locked');
        if (el.tagName === 'SELECT') el.disabled = false;
      }
    });
    var pick = tr.querySelector('.js-pick-open');
    if (pick && needsPick) {
      pick.tabIndex = 0;
    } else if (pick) {
      pick.removeAttribute('tabindex');
    }
    if (needsPick) {
      var barcodeInp = tr.querySelector('.js-barcode-inp');
      if (barcodeInp) {
        barcodeInp.removeAttribute('readonly');
        barcodeInp.removeAttribute('tabindex');
        barcodeInp.classList.remove('is-item-pick-locked');
      }
    }
    applyRowQtyLock(tr);
  }

  function focusRowMaterialCodeField(tr) {
    if (!tr) return;
    if (blockItemPickUntilQty(tr, { alert: false, actionLabel: 'إدخال مادة' })) return;
    var bc = tr.querySelector('.js-barcode-inp');
    if (bc && !bc.disabled) {
      bc.focus();
      if (bc.select) bc.select();
      return;
    }
    openPickerForRow(tr);
  }

  function focusRowItemPick(tr) {
    if (!tr) return;
    if (getRowItemId(tr) < 1 && !orderIsApproved) {
      focusRowMaterialCodeField(tr);
      return;
    }
    openPickerForRow(tr);
  }

  function validateInvoiceLineQuantities() {
    var firstBad = null;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (!getRowItemId(tr)) return;
      if (rowStockQty(tr) <= 0 && !firstBad) firstBad = tr;
    });
    if (!firstBad) {
      return { ok: true };
    }
    var name = firstBad.dataset.nameAr || 'مادة بدون اسم';
    return {
      ok: false,
      msg: 'أدخل كمية لكل مادة في طلب الشراء.\n\nالمادة: ' + name,
      tr: firstBad,
    };
  }

  function setSaveBusy(busy, message) {
    if (global.AppBusy && AppBusy.setSaveBusy) {
      AppBusy.setSaveBusy(busy, message || 'جاري حفظ أمر الشراء...');
      return;
    }
    var saveBtn = document.querySelector('#master-toolbar [data-master-action="save"]');
    if (saveBtn) saveBtn.disabled = !!busy;
  }

  function syncJson() {
    var lines = [];
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var itemId = getRowItemId(tr);
      if (!itemId) return;
      var qtyEl = tr.querySelector('.js-qty');
      var priceEl = tr.querySelector('.js-price');
      var taxSel = tr.querySelector('.js-tax');
      var taxRate = 0;
      if (taxSel && taxSel.options && taxSel.options.length > 0) {
        var idx = taxSel.selectedIndex >= 0 ? taxSel.selectedIndex : 0;
        taxRate = parseNum(taxSel.options[idx].getAttribute('data-rate'));
      }
      var driver = rowAmountSource(tr);
      var grossVal = parseNum(tr.dataset.gross);
      var qtyExtraEl = tr.querySelector('.js-qty-extra');
      var discEl = tr.querySelector('.js-discount');
      var lineDiscInp = '';
      if (!headerDiscountMode && discEl) {
        lineDiscInp = String(discEl.value || '').trim();
      }
      var unitSel = tr.querySelector('.js-unit');
      var unitFactorEl = tr.querySelector('.js-unit-factor');
      var unitId = unitSel ? parseInt(unitSel.value, 10) || 0 : 0;
      var unitName = '';
      if (unitSel && unitSel.selectedIndex >= 0 && unitSel.options[unitSel.selectedIndex]) {
        unitName = String(unitSel.options[unitSel.selectedIndex].textContent || '').trim();
      }
      var unitFactor = parseNum(unitFactorEl ? unitFactorEl.value : 1) || 1;
      var qtyVal = parseNum(qtyEl ? qtyEl.value : 0);
      lines.push({
        item_id: itemId,
        name_ar: tr.dataset.nameAr || '',
        qty: qtyVal,
        qty_extra: parseNum(qtyExtraEl ? qtyExtraEl.value : 0),
        unit_id: unitId,
        unit_name: unitName,
        unit_factor: unitFactor,
        qty_base: qtyVal * unitFactor,
        unit_price: parseNum(priceEl ? priceEl.value : 0),
        line_discount_input: lineDiscInp,
        discount_amount: parseNum(tr.dataset.disc),
        tax_rate_percent: taxRate,
        amount_driver: driver,
        line_subtotal: parseNum(tr.dataset.sub),
        tax_amount: parseNum(tr.dataset.tax),
        line_gross: grossVal,
      });
    });
    if (linesJson) linesJson.value = JSON.stringify(lines);
  }

  var MSG_DELETE_INVOICE_NEEDS_EMPTY_LINES =
    'لا يمكن حذف الفاتورة قبل حذف جميع بنود المواد. احذف البنود من الجدول (سلة المهملات) ثم احفظ الفاتورة، وبعدها احذف الفاتورة.';

  function validateInvoiceBeforeSave(opts) {
    opts = opts || {};
    var supplier = document.getElementById('inv_supplier');
    if (!supplier || !supplier.value) {
      AppDialog.alert('اختر المورد قبل الحفظ.', { type: 'warning' });
      if (supplier) supplier.focus();
      return false;
    }
    if (form.getAttribute('data-warehouse-required') === '1') {
      var wh = document.getElementById('inv_wh');
      if (wh && !wh.value) {
        AppDialog.alert('اختر المستودع قبل الحفظ.', { type: 'warning' });
        wh.focus();
        return false;
      }
    }
    var invDate = document.getElementById('inv_date');
    if (invDate && !invDate.value.trim()) {
      AppDialog.alert('أدخل تاريخ الطلب.', { type: 'warning' });
      invDate.focus();
      return false;
    }
    if (invDate && global.AppFormat && AppFormat.parseDateToIso) {
      if (!AppFormat.parseDateToIso(invDate.value)) {
        AppDialog.alert('تاريخ الطلب غير صالح. استخدم يوم-شهر-سنة (مثل 16-05-2026).', {
          type: 'warning',
        });
        invDate.focus();
        return false;
      }
    }
    if (!opts.allowEmptyLines && !invoiceHasLines()) {
      AppDialog.alert('أضف سطرًا واحدًا على الأقل للمواد.', { type: 'warning' });
      return false;
    }
    if (!opts.allowEmptyLines) {
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        if (getRowItemId(tr) < 1) return;
        recalcRow(tr, rowAmountSource(tr), { normalizeStored: true });
      });
      var qtyCheck = validateInvoiceLineQuantities();
      if (!qtyCheck.ok) {
        AppDialog.alert(qtyCheck.msg, { type: 'warning' });
        if (qtyCheck.tr) {
          var qtyEl = qtyCheck.tr.querySelector('.js-qty');
          if (qtyEl) qtyEl.focus();
        }
        return false;
      }
    }
    return true;
  }

  var pendingAfterSave = null;

  function finishSaveFromJson(data, leaveAfterSave, onDone) {
    formSubmitting = false;
    setSaveBusy(false);
    clearPersistedDraft();
    clearFormDirty();

    var savedId = parseInt(data.invoice_id, 10) || parseInt(data.order_id, 10) || 0;
    var savedNo = data.invoice_no || data.order_no || '';
    if (savedId > 0) {
      currentOrderId = savedId;
      syncInvoiceIdField();
      if (savedNo) {
        syncInvoiceNoDisplay(savedNo);
      }
    }

    if (leaveAfterSave && onDone) {
      onDone();
      return;
    }

    if (savedId > 0) {
      loadSavedInvoiceAfterSubmit(savedId, null, null);
      return;
    }

    window.location.reload();
  }

  function normalizeAllLinesBeforeSave() {
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (getRowItemId(tr) < 1) return;
      recalcRow(tr, rowAmountSource(tr), { normalizeStored: true });
    });
    recalcFooter();
  }

  function submitInvoiceForm() {
    formSubmitting = true;
    normalizeAllLinesBeforeSave();
    syncJson();
    syncInvoiceIdField();
    setSaveBusy(true);

    var actionUrl = form.getAttribute('action') || window.location.href;
    var fd = new FormData(form);
    fd.set('_action', 'save_order');
    if (linesJson) fd.set('lines_json', linesJson.value);
    var onDone = pendingAfterSave;
    var leaveAfterSave = !!onDone;
    pendingAfterSave = null;

    fetch(actionUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Invoice-Save': '1',
      },
    })
      .then(function (res) {
        var ct = res.headers.get('Content-Type') || '';
        if (ct.indexOf('application/json') >= 0) {
          return res.json().then(function (data) {
            if (!data || !data.ok) {
              formSubmitting = false;
              setSaveBusy(false);
              var msg = (data && data.message) || 'تعذر حفظ طلب الشراء.';
              if (global.AppDialog) AppDialog.error(msg);
              else alert(msg);
              return;
            }
            finishSaveFromJson(data, leaveAfterSave, onDone);
          });
        }
        if (leaveAfterSave && onDone && res.status >= 300 && res.status < 400) {
          clearPersistedDraft();
          formSubmitting = false;
          setSaveBusy(false);
          clearFormDirty();
          onDone();
          return;
        }
        if (res.status >= 300 && res.status < 400) {
          var loc = res.headers.get('Location');
          if (loc) {
            window.location.href = new URL(loc, window.location.href).href;
            return;
          }
        }
        formSubmitting = false;
        setSaveBusy(false);
        if (global.AppDialog) {
          AppDialog.error('تعذر حفظ طلب الشراء. تحقق من البيانات وحاول مرة أخرى.');
        } else {
          alert('تعذر حفظ طلب الشراء.');
        }
      })
      .catch(function (err) {
        console.error('purchase-order save', err);
        formSubmitting = false;
        setSaveBusy(false);
        if (global.AppDialog) {
          AppDialog.error('تعذر حفظ طلب الشراء. تحقق من الاتصال وحاول مرة أخرى.');
        } else {
          alert('تعذر حفظ طلب الشراء.');
        }
      });
  }

  function getBarcodeFromRow(tr) {
    var inp = tr.querySelector('.js-barcode-inp');
    if (inp) {
      return String(inp.value || '').trim();
    }
    var span = tr.querySelector('.js-barcode');
    return span ? String(span.textContent || '').trim() : '';
  }

  function itemMaterialNumber(barcode, sku) {
    if (window.InvItemDisplay && typeof window.InvItemDisplay.materialNumber === 'function') {
      return window.InvItemDisplay.materialNumber(barcode, sku);
    }
    var bc = String(barcode == null ? '' : barcode).trim();
    if (bc) return bc;
    return String(sku == null ? '' : sku).trim();
  }

  function setRowItemDisplay(tr, name, barcode, sku) {
    var nameEl = tr.querySelector('.js-name');
    if (nameEl) {
      nameEl.textContent = name || 'اضغط لاختيار المادة';
      nameEl.classList.toggle('is-placeholder', !name);
    }
    var lovWrap = tr.querySelector('.sales-inv-item-lov');
    if (lovWrap) {
      lovWrap.classList.toggle('is-empty', !name);
    }
    var materialNo = itemMaterialNumber(barcode, sku);
    var skuEl = tr.querySelector('.js-sku');
    if (skuEl) {
      skuEl.textContent = materialNo;
    }
    var barcodeInp = tr.querySelector('.js-barcode-inp');
    if (barcodeInp) {
      barcodeInp.value = String(barcode == null ? '' : barcode).trim() || materialNo;
    } else {
      var barcodeEl = tr.querySelector('.js-barcode');
      if (barcodeEl) {
        barcodeEl.textContent = materialNo;
      }
    }
  }

  function normalizePickerItem(item) {
    if (!item) return null;
    if (item.id != null && item.name_ar != null && !item.item_id) {
      var existingId = parseInt(item.id, 10);
      if (existingId > 0) return item;
    }
    var id = parseInt(item.id != null ? item.id : item.item_id, 10);
    if (!id) return null;
    var units = Array.isArray(item.units) ? item.units : [];
    return {
      id: id,
      name_ar: item.name_ar || item.name || '',
      barcode: String(item.barcode || '').trim(),
      sku: String(item.sku || '').trim(),
      default_cost:
        item.default_cost != null
          ? item.default_cost
          : item.default_sale != null
            ? item.default_sale
            : 0,
      default_sale: item.default_sale,
      units: units,
    };
  }

  function fillRowUnits(tr, units, selectedUnitId) {
    var sel = tr.querySelector('.js-unit');
    var factorEl = tr.querySelector('.js-unit-factor');
    if (!sel) return 1;
    sel.innerHTML = '';
    var list = Array.isArray(units) && units.length ? units : [{ unit_id: 0, name: '—', factor: 1, is_default: true, is_base: true }];
    var pick = null;
    list.forEach(function (u) {
      var opt = document.createElement('option');
      var uid = parseInt(u.unit_id != null ? u.unit_id : u.id, 10) || 0;
      opt.value = String(uid);
      opt.textContent = String(u.name || u.unit_name || '—');
      opt.setAttribute('data-factor', String(u.factor != null ? u.factor : u.factor_to_base != null ? u.factor_to_base : 1));
      sel.appendChild(opt);
      if (selectedUnitId && uid === selectedUnitId) pick = opt;
      else if (!pick && (u.is_default || u.is_default_issue)) pick = opt;
      else if (!pick && u.is_base) pick = opt;
    });
    if (!pick && sel.options.length) pick = sel.options[0];
    if (pick) sel.value = pick.value;
    sel.disabled = orderIsApproved || list.length <= 1 && !(list[0] && (list[0].unit_id || list[0].id));
    if (list.length > 1) sel.disabled = !!orderIsApproved || isFormLineLocked();
    var factor = parseNum(sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].getAttribute('data-factor') : 1) || 1;
    if (factorEl) factorEl.value = String(factor);
    return factor;
  }

  function applyUnitPriceFromBase(tr) {
    var basePriceEl = tr.querySelector('.js-base-price');
    var factorEl = tr.querySelector('.js-unit-factor');
    var priceEl = tr.querySelector('.js-price');
    if (!basePriceEl || !priceEl) return;
    var base = parseNum(basePriceEl.value);
    var factor = parseNum(factorEl ? factorEl.value : 1) || 1;
    if (base > 0) {
      priceEl.value = formatPriceValue(base * factor, '');
      tr.dataset.listUnitPrice = String(base * factor);
    }
  }

  function readPickerItemFromDomRow(row) {
    if (!row) return null;
    var id = parseInt(row.getAttribute('data-item-id') || '', 10);
    if (!id) return null;
    var nameEl = row.querySelector('.sales-inv-pick-item-name');
    var codeEl = row.querySelector('.sales-inv-pick-item-barcode');
    var priceAttr = row.getAttribute('data-item-price');
    var price =
      priceAttr !== null && priceAttr !== ''
        ? parseNum(priceAttr)
        : 0;
    return {
      id: id,
      name_ar: row.getAttribute('data-item-name') || (nameEl ? nameEl.textContent : '') || '',
      barcode:
        row.getAttribute('data-item-barcode') ||
        (codeEl ? codeEl.textContent : '') ||
        '',
      sku: row.getAttribute('data-item-sku') || (codeEl ? codeEl.textContent : '') || '',
      default_cost: price,
    };
  }

  function pickFromSearchResults(items, code, opts) {
    opts = opts || {};
    if (!items || !items.length) return null;
    if (code) {
      for (var i = 0; i < items.length; i++) {
        if (itemMatchesCode(items[i], code)) return items[i];
      }
    }
    if (opts.exactLookup && items.length > 0) return items[0];
    if (items.length === 1) return items[0];
    return null;
  }

  function applyBarcodeItemToRow(tr, item) {
    var normalized = normalizePickerItem(item);
    if (!normalized || !tr) return false;
    if (blockItemPickUntilQty(tr, { actionLabel: 'إدخال مادة' })) return false;

    var wasEntry = tr.classList.contains('is-entry-row');
    var emptyLine = !parseInt(tr.dataset.itemId, 10);
    var focusTr = null;

    if (wasEntry) {
      focusTr = appendItemsToInvoice([normalized]);
      var entry = getEntryRow();
      var bc = entry && entry.querySelector('.js-barcode-inp');
      if (bc) bc.value = '';
    } else if (emptyLine) {
      fillRowFromItem(tr, normalized);
      focusTr = tr;
    } else {
      focusTr = appendItemsToInvoice([normalized]);
    }

    renumberRows();
    recalcFooter();
    syncJson();

    if (focusTr) {
      focusRowQtyField(focusTr);
    }
    return true;
  }

  function resolveBarcodeOnRow(tr) {
    if (!tr) return;
    var code = getBarcodeFromRow(tr);
    if (!code) {
      openPickerForRow(tr);
      return;
    }
    fetch(buildItemsApiUrl('', false, { exactCode: code }), { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.ok && data.items && data.items.length) {
          var pick = pickFromSearchResults(data.items, code, { exactLookup: !!data.exact });
          if (pick && applyBarcodeItemToRow(tr, pick)) return;
        }
        return fetch(buildItemsApiUrl(code, false, { ignoreWarehouse: true }), {
          credentials: 'same-origin',
        })
          .then(function (r2) {
            return r2.json();
          })
          .then(function (data2) {
            if (!data2 || !data2.ok || !data2.items || !data2.items.length) {
              if (global.AppDialog) {
                AppDialog.alert('لم يُعثر على مادة بهذا الباركود أو الرمز.', {
                  type: 'warning',
                });
              }
              return;
            }
            var pick2 = pickFromSearchResults(data2.items, code, { exactLookup: false });
            if (pick2 && applyBarcodeItemToRow(tr, pick2)) return;
            openPickerForRow(tr, { initialSearch: code });
          });
      })
      .catch(function () {
        if (global.AppDialog) AppDialog.error('تعذر البحث عن المادة.');
      });
  }

  function renumberRows() {
    var n = 1;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var seq = tr.querySelector('.js-seq');
      var itemId = parseInt(tr.dataset.itemId, 10);
      if (!seq) return;
      if (itemId > 0) {
        seq.textContent = String(n);
        n += 1;
      } else {
        seq.textContent = '';
      }
    });
  }

  function applyDefaultTax(tr) {
    var taxSel = tr.querySelector('.js-tax');
    if (!taxSel) return;
    var defRate = form.getAttribute('data-default-tax-rate') || '0';
    for (var i = 0; i < taxSel.options.length; i++) {
      if (parseNum(taxSel.options[i].getAttribute('data-rate')) === parseNum(defRate)) {
        taxSel.selectedIndex = i;
        break;
      }
    }
  }

  function createRow(isEntry) {
    var tr;
    if (tpl && tpl.content && tpl.content.firstElementChild) {
      tr = tpl.content.firstElementChild.cloneNode(true);
    } else {
      var seed = getEntryRow();
      if (!seed) throw new Error('قالب سطر الفاتورة غير موجود');
      tr = seed.cloneNode(true);
      tr.classList.remove('is-picker-active');
      delete tr.dataset.rowBound;
    }
    tr.dataset.lineId = newLineId();
    tr.dataset.itemId = '';
    tr.dataset.nameAr = '';
    tr.dataset.sub = '0';
    tr.dataset.tax = '0';
    tr.dataset.gross = '0';
    setRowItemDisplay(tr, '', '');
    tr.querySelector('.js-qty').value = '';
    var qtyExtraReset = tr.querySelector('.js-qty-extra');
    if (qtyExtraReset) qtyExtraReset.value = '';
    tr.querySelector('.js-price').value = formatUnitPriceValue(0, '');
    applyDefaultTax(tr);
    if (isEntry) {
      tr.classList.add('is-entry-row');
    } else {
      tr.classList.remove('is-entry-row');
    }
    applyQtyPriceInputAttrs(tr);
    bindRow(tr);
    recalcRow(tr);
    return tr;
  }

  function getEntryRow() {
    return tbody.querySelector('tr.is-entry-row');
  }

  /** إدراج سطر بيانات قبل صف الإدخال (مثل فاتورة البيع). */
  function insertDataRowBeforeEntry(tr) {
    var entry = getEntryRow();
    if (entry) {
      tbody.insertBefore(tr, entry);
      return;
    }
    tbody.appendChild(tr);
  }

  function countInvoiceLineRows() {
    var n = 0;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (parseInt(tr.dataset.itemId, 10) > 0) n += 1;
    });
    return n;
  }

  function appendLineFromPickerItem(item) {
    var normalized = normalizePickerItem(item);
    if (!normalized) return null;
    ensureEntryRow();
    var row = createRow(false);
    insertDataRowBeforeEntry(row);
    if (!fillRowFromItem(row, normalized)) {
      row.remove();
      return null;
    }
    return row;
  }

  function appendItemsToInvoice(items) {
    if (!items || !items.length) return null;
    if (blockItemPickUntilQty(null, { actionLabel: 'إضافة مادة' })) return null;
    var firstFocus = null;
    items.forEach(function (raw) {
      var row = appendLineFromPickerItem(raw);
      if (row && !firstFocus) firstFocus = row;
    });
    ensureEntryRow();
    renumberRows();
    recalcFooter();
    syncJson();
    return firstFocus;
  }

  function ensureEntryRow() {
    var entry = getEntryRow();
    if (entry) {
      if (entry !== tbody.lastElementChild) {
        tbody.appendChild(entry);
      }
      return entry;
    }
    entry = createRow(true);
    tbody.appendChild(entry);
    return entry;
  }

  function finalizeEntryRow(tr) {
    if (!tr.classList.contains('is-entry-row')) return;
    tr.classList.remove('is-entry-row');
    var removeBtn = tr.querySelector('.js-remove');
    if (removeBtn) removeBtn.style.visibility = 'visible';
  }

  function fillRowFromItem(tr, item) {
    var normalized = normalizePickerItem(item);
    if (!tr || !normalized) return false;
    var qtyEl = tr.querySelector('.js-qty');
    var priceEl = tr.querySelector('.js-price');
    if (!qtyEl || !priceEl) return false;

    tr.dataset.itemId = String(normalized.id);
    tr.dataset.nameAr = normalized.name_ar || '';
    setRowItemDisplay(tr, normalized.name_ar || '', normalized.barcode, normalized.sku);
    qtyEl.value = '';
    var costPrice =
      normalized.default_cost != null ? parseNum(normalized.default_cost) : 0;
    var basePriceEl = tr.querySelector('.js-base-price');
    if (basePriceEl) basePriceEl.value = costPrice > 0 ? String(costPrice) : '';
    var factor = fillRowUnits(tr, normalized.units || [], 0) || 1;
    if (costPrice > 0) {
      tr.dataset.listUnitPrice = String(costPrice * factor);
    } else {
      delete tr.dataset.listUnitPrice;
    }
    priceEl.value = formatPriceValue(costPrice * factor, '');
    applyDefaultTax(tr);
    recalcRow(tr);
    applyRowItemPickLock(tr);
    return true;
  }

  function getWarehouseId() {
    var wh = document.getElementById('inv_wh');
    if (!wh) return 0;
    return parseInt(wh.value, 10) || 0;
  }

  function normalizeItemCode(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  function itemMatchesCode(item, code) {
    var c = normalizeItemCode(code);
    if (!c || !item) return false;
    return (
      normalizeItemCode(item.barcode) === c || normalizeItemCode(item.sku) === c
    );
  }

  function buildItemsApiUrl(q, listAll, opts) {
    opts = opts || {};
    var url = apiUrl;
    var parts = [];
    if (opts.exactCode) {
      parts.push('code=' + encodeURIComponent(String(opts.exactCode).trim()));
    } else {
      if (listAll || q === '') parts.push('list=1');
      else if (q) parts.push('q=' + encodeURIComponent(q));
    }
    var whId = opts.ignoreWarehouse ? 0 : getWarehouseId();
    if (whId > 0) parts.push('warehouse_id=' + encodeURIComponent(String(whId)));
    if (parts.length) url += (apiUrl.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
    return url;
  }

  function addPickerItems(items) {
    if (!items || !items.length) return;
    if (blockItemPickUntilQty(activePickRow, { actionLabel: 'اختيار مادة' })) {
      closePicker();
      return;
    }
    var focusTr = null;
    var queue = items.slice();
    if (activePickRow && !parseInt(activePickRow.dataset.itemId, 10)) {
      var first = queue.shift();
      if (first && fillRowFromItem(activePickRow, first)) {
        focusTr = activePickRow;
        if (activePickRow.classList.contains('is-entry-row')) {
          finalizeEntryRow(activePickRow);
          ensureEntryRow();
        }
      }
    }
    if (queue.length) {
      var appended = appendItemsToInvoice(queue);
      if (!focusTr) focusTr = appended;
    }
    renumberRows();
    recalcFooter();
    syncJson();
    if (focusTr) {
      focusRowQtyField(focusTr);
    }
  }

  function addPickerItemImmediate(item) {
    if (!item) return;
    addPickerItems([item]);
  }

  function isPickerOpen() {
    return global.ItemPickerModal && ItemPickerModal.isOpen();
  }

  function closePicker() {
    if (global.ItemPickerModal) {
      ItemPickerModal.close();
    }
  }

  function openPickerForRow(tr, opts) {
    opts = opts || {};
    if (orderIsApproved || ledgerView) return;
    if (blockItemPickUntilQty(tr, { actionLabel: 'اختيار مادة' })) return;
    if (!tr) return;
    if (!global.ItemPickerModal) {
      if (global.AppDialog) {
        AppDialog.alert('نافذة اختيار المواد غير متوفرة في الصفحة.', { type: 'warning' });
      }
      return;
    }
    if (!apiUrl) {
      if (global.AppDialog) {
        AppDialog.alert('تعذر تحميل قائمة المواد: رابط البحث غير مضبوط.', { type: 'warning' });
      }
      return;
    }
    ItemPickerModal.open({
      screenCenter: true,
      buildItemsUrl: function (q, listAll) {
        return buildItemsApiUrl(q, listAll);
      },
      getWarehouseId: getWarehouseId,
      initialSearch: opts.initialSearch || '',
      emptyMessage:
        getWarehouseId() > 0 ? 'لا توجد مواد في هذا المستودع' : 'لا توجد مواد مطابقة',
      onOpen: function () {
        tbody.querySelectorAll('tr.is-picker-active').forEach(function (r) {
          r.classList.remove('is-picker-active');
        });
        tr.classList.add('is-picker-active');
        activePickRow = tr;
      },
      onClose: function () {
        if (activePickRow) {
          activePickRow.classList.remove('is-picker-active');
          activePickRow = null;
        }
      },
      onConfirm: function (items) {
        addPickerItems(items);
      },
    });
  }

  function focusNextField(tr, current) {
    if (blockLineNavIfQtyMissing(tr, current)) return;

    var order = ['.js-qty', '.js-qty-extra', '.js-price', '.js-discount', '.js-line-sub', '.js-tax', '.js-line-gross'];
    var idx = -1;
    if (current.classList.contains('js-qty')) idx = 0;
    else if (current.classList.contains('js-qty-extra')) idx = 1;
    else if (current.classList.contains('js-price')) idx = 2;
    else if (current.classList.contains('js-discount')) idx = 3;
    else if (current.classList.contains('js-line-sub')) idx = 4;
    else if (current.classList.contains('js-tax')) idx = 5;
    else if (current.classList.contains('js-line-gross')) idx = 6;
    else if (current.classList.contains('js-barcode-inp') && getRowItemId(tr) > 0) {
      focusRowQtyField(tr);
      return;
    }
    if (idx >= 0 && idx < order.length - 1) {
      if (blockLineNavIfQtyMissing(tr, current)) return;
      var next = tr.querySelector(order[idx + 1]);
      if (next && !next.readOnly && !next.classList.contains('is-qty-required-locked')) {
        next.focus();
        if (next.select) next.select();
      } else if (rowNeedsQtyInput(tr)) {
        focusRowQtyField(tr);
      }
      return;
    }
    if (rowNeedsQtyInput(tr)) {
      focusRowQtyField(tr);
      return;
    }
    completeLineAndNext(tr);
  }

  function completeLineAndNext(tr) {
    var itemId = parseInt(tr.dataset.itemId, 10);
    if (!itemId) {
      focusRowMaterialCodeField(tr);
      return;
    }
    recalcRow(tr, rowAmountSource(tr), { normalizeStored: true });
    recalcFooter();
    syncJson();

    if (tr.classList.contains('is-entry-row')) {
      finalizeEntryRow(tr);
    }

    if (blockItemPickUntilQty(null, { alert: false, actionLabel: 'إضافة سطر جديد' })) {
      return;
    }

    var entry = ensureEntryRow();
    var bc = entry.querySelector('.js-barcode-inp');
    if (bc) {
      bc.focus();
    } else {
      openPickerForRow(entry);
    }
  }

  function bindRow(tr) {
    if (tr.dataset.rowBound === '1') return;
    tr.dataset.rowBound = '1';

    tr.querySelectorAll('.js-pick-open').forEach(function (pickBtn) {
      pickBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openPickerForRow(tr);
      });
    });

    var itemCell = tr.querySelector('.sales-inv-item-cell');
    if (itemCell) {
      itemCell.addEventListener('click', function (e) {
        if (e.target.closest('.js-pick-open')) return;
        e.preventDefault();
        e.stopPropagation();
        openPickerForRow(tr);
      });
      itemCell.addEventListener('mousedown', function (e) {
        if (e.target.closest('.js-pick-open')) return;
        if (itemCell.classList.contains('is-qty-required-locked')) {
          e.preventDefault();
          alertQtyRequired(tr, 'اختيار مادة أخرى');
          focusRowQtyField(tr);
        }
      });
    }

    applyQtyPriceInputAttrs(tr);
    applyRowItemPickLock(tr);

    tr.querySelectorAll(ROW_ITEM_LOCK_SELECTORS + ',' + ROW_QTY_LOCK_SELECTORS).forEach(function (lockEl) {
      lockEl.addEventListener('mousedown', function (e) {
        if (lockEl.classList.contains('is-qty-required-locked')) {
          e.preventDefault();
          focusRowQtyField(tr);
          return;
        }
        if (getRowItemId(tr) < 1 && !orderIsApproved) {
          e.preventDefault();
          focusRowItemPick(tr);
        }
      });
      lockEl.addEventListener('focus', function () {
        if (lockEl.classList.contains('is-qty-required-locked')) {
          lockEl.blur();
          focusRowQtyField(tr);
          return;
        }
        if (getRowItemId(tr) < 1 && !orderIsApproved) {
          lockEl.blur();
          focusRowItemPick(tr);
        }
      });
    });

    var barcodeInp = tr.querySelector('.js-barcode-inp');
    if (barcodeInp) {
      barcodeInp.addEventListener('keydown', function (e) {
        if (handleTableArrowKey(e, tr, barcodeInp)) return;
      });
      barcodeInp.addEventListener('blur', function () {
        var code = String(barcodeInp.value || '').trim();
        if (getRowItemId(tr) < 1 && code) {
          resolveBarcodeOnRow(tr);
        }
      });
    }

    var skuCell = tr.querySelector('.sales-inv-col-sku');
    if (skuCell) {
      skuCell.addEventListener('click', function (e) {
        if (e.target.closest('.js-barcode-inp')) return;
        if (getRowItemId(tr) < 1 && barcodeInp && !orderIsApproved) {
          e.preventDefault();
          focusRowMaterialCodeField(tr);
        }
      });
    }

    var unitSel = tr.querySelector('.js-unit');
    if (unitSel) {
      unitSel.addEventListener('change', function () {
        var opt = unitSel.options[unitSel.selectedIndex];
        var newFactor = parseNum(opt ? opt.getAttribute('data-factor') : 1) || 1;
        var factorEl = tr.querySelector('.js-unit-factor');
        var oldFactor = parseNum(factorEl ? factorEl.value : 1) || 1;
        var qtyEl = tr.querySelector('.js-qty');
        var qtyExtraEl = tr.querySelector('.js-qty-extra');
        if (qtyEl && oldFactor > 0 && newFactor > 0) {
          var oldQty = parseNum(qtyEl.value);
          if (oldQty > 0) {
            qtyEl.value = formatQtyValue((oldQty * oldFactor) / newFactor);
          }
        }
        if (qtyExtraEl && oldFactor > 0 && newFactor > 0) {
          var oldExtra = parseNum(qtyExtraEl.value);
          if (oldExtra > 0) {
            qtyExtraEl.value = formatQtyValue((oldExtra * oldFactor) / newFactor);
          }
        }
        if (factorEl) factorEl.value = String(newFactor);
        applyUnitPriceFromBase(tr);
        recalcRow(tr);
        if (!orderIsApproved) markFormDirty();
      });
    }

    tr
      .querySelectorAll(
        '.js-qty, .js-qty-extra, .js-price, .js-discount, .js-line-sub, .js-line-gross, .js-tax'
      )
      .forEach(function (el) {
      el.addEventListener('input', function () {
        recalcRowLiveFromField(tr, el);
        if (el.classList.contains('js-qty') || el.classList.contains('js-qty-extra')) {
          applyRowQtyLock(tr);
        }
      });
      el.addEventListener('change', function () {
        if (el.classList.contains('js-qty')) normalizeQtyInput(el);
        if (el.classList.contains('js-qty-extra')) normalizeQtyExtraInput(el);
        if (el.classList.contains('js-price')) normalizePriceInput(el);
        if (el.classList.contains('js-line-sub')) normalizeSubInput(el);
        if (el.classList.contains('js-line-gross')) normalizeGrossInput(el);
        recalcRowLiveFromField(tr, el);
        if (el.classList.contains('js-qty') || el.classList.contains('js-qty-extra')) {
          applyRowQtyLock(tr);
        }
      });
      el.addEventListener('blur', function () {
        if (
          el.classList.contains('js-qty') ||
          el.classList.contains('js-price') ||
          el.classList.contains('js-discount') ||
          el.classList.contains('js-line-sub') ||
          el.classList.contains('js-line-gross')
        ) {
          commitAmountFieldAndRecalc(tr, el);
        } else if (el.classList.contains('js-qty-extra')) {
          normalizeQtyExtraInput(el);
          syncJson();
        }
        if (el.classList.contains('js-qty') || el.classList.contains('js-qty-extra')) {
          applyRowQtyLock(tr);
        }
      });
      el.addEventListener('keydown', function (e) {
        if (handleTableArrowKey(e, tr, el)) return;
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (isAmountFieldEnterCommit(el)) {
          commitAmountFieldAndRecalc(tr, el);
          if (blockLineNavIfQtyMissing(tr, el)) return;
          focusNextField(tr, el);
        } else if (el.classList.contains('js-line-gross')) {
          commitAmountFieldAndRecalc(tr, el);
          completeLineAndNext(tr);
        } else if (el.classList.contains('js-tax')) {
          var grossInp = tr.querySelector('input.js-line-gross');
          if (grossInp) {
            grossInp.focus();
            if (grossInp.select) grossInp.select();
          } else {
            completeLineAndNext(tr);
          }
        } else {
          focusNextField(tr, el);
        }
      });
    });

    var removeBtn = tr.querySelector('.js-remove');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        var wasEntry = tr.classList.contains('is-entry-row');
        tr.remove();
        if (wasEntry || !getEntryRow()) {
          ensureEntryRow();
        }
        renumberRows();
        recalcFooter();
        syncJson();
      });
      if (tr.classList.contains('is-entry-row')) {
        removeBtn.style.visibility = 'hidden';
      }
    }
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (ledgerView || orderIsApproved) return;
    trySave();
  });

  function hasDraftContent() {
    var hasLine = false;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (parseInt(tr.dataset.itemId, 10) > 0) hasLine = true;
    });
    if (hasLine) return true;
    var notes = document.getElementById('inv_notes');
    if (notes && String(notes.value).trim() !== '') return true;
    var supInvNo = document.getElementById('inv_supplier_invoice_no');
    if (supInvNo && String(supInvNo.value).trim() !== '') return true;
    var cust = document.getElementById('inv_supplier');
    if (cust && cust.value !== '') return true;
    return false;
  }

  function resetInvoiceForm() {
    runWithoutDirtyMark(function () {
      form.reset();
      var invDate = document.getElementById('inv_date');
      if (invDate && defaultDate) invDate.value = defaultDate;
      var paySel = document.getElementById('inv_payment_type');
      if (paySel) paySel.value = 'cash';
      applyDefaultWarehouse();
      applyCustomerSalesRep();
      syncInvoiceNoDisplay('');
      tbody.innerHTML = '';
      closePicker();
      var entry = ensureEntryRow();
      renumberRows();
      openPickerForRow(entry);
      recalcFooter();
      syncJson();
    });
  }

  function buildInvoicePrintInnerHtml() {
    ensureDiscountsBeforePrint();
    var hdrDiscInp = document.getElementById('inv-invoice-discount');
    var invoiceDiscountLabel =
      hdrDiscInp && String(hdrDiscInp.value || '').trim() !== ''
        ? String(hdrDiscInp.value).trim()
        : '';

    var dateEl = document.getElementById('inv_date');
    var date = fmtDate(dateEl ? dateEl.value : '');
    var custSel = document.getElementById('inv_supplier');
    var cust = '';
    if (custSel && custSel.options[custSel.selectedIndex]) {
      cust = custSel.options[custSel.selectedIndex].text;
    }
    var paySel = document.getElementById('inv_payment_type');
    var payLabel = paySel && paySel.value === 'credit' ? 'ذمم' : 'نقدي';
    var repDisplay = document.getElementById('inv_sales_rep_display');
    var repLine = '';
    if (repDisplay && repDisplay.value && repDisplay.value !== '—' && repDisplay.value.indexOf('بدون') === -1) {
      repLine =
        '<tr><td style="padding:0.2rem 0;direction:rtl;unicode-bidi:isolate;"><strong>المندوب:\u200F</strong> <bdi>' +
        escapeHtml(repDisplay.value) +
        '</bdi></td></tr>';
    }
    var invNoEl = document.getElementById('inv_no');
    var invNo = invNoEl && invNoEl.value ? invNoEl.value : '—';
    var supInvNoEl = document.getElementById('inv_supplier_invoice_no');
    var supInvNo =
      supInvNoEl && String(supInvNoEl.value).trim() !== '' ? String(supInvNoEl.value).trim() : '';
    var wh = document.getElementById('inv_wh');
    var whLine = '';
    var notes = document.getElementById('inv_notes');
    var notesVal = notes ? String(notes.value).trim() : '';
    var notesBlock = notesVal
      ? '<p style="margin:0.75rem 0 0;font-size:0.88rem;direction:rtl;unicode-bidi:isolate;"><strong>ملاحظات:\u200F</strong> <bdi>' +
        escapeHtml(notesVal) +
        '</bdi></p>'
      : '';

    var sub = document.getElementById('sales-inv-sum-sub');
    var tax = document.getElementById('sales-inv-sum-tax');
    var grand = document.getElementById('sales-inv-sum-grand');
    var disc = document.getElementById('sales-inv-sum-disc');
    var subT = fmtPrintAmount(parseNum(sub ? sub.textContent : 0));
    var taxT = fmtPrintAmount(parseNum(tax ? tax.textContent : 0));
    var grandT = fmtPrintAmount(parseNum(grand ? grand.textContent : 0));
    var discT = fmtPrintAmount(parseNum(disc ? disc.textContent : 0));

    var ipp = window.InvInvoicePrint;
    var layout = ipp ? ipp.getLayout(tbody) : { showQtyExtra: false, showDiscount: false };
    var lineCols = ipp ? ipp.lineColCount(layout) : 10;
    var rowHtml = '';
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var itemId = parseInt(tr.dataset.itemId, 10);
      if (!itemId) return;
      if (ipp) {
        rowHtml += ipp.buildLineRow(tr, layout, invoicePrintLineCtx());
      }
    });

    if (!rowHtml) {
      rowHtml =
        '<tr><td colspan="' +
        lineCols +
        '" style="padding:1rem;text-align:center;color:#64748b;">لا توجد بنود</td></tr>';
    }

    if (wh) {
      var wopt = wh.options[wh.selectedIndex];
      if (wopt && wh.value) {
        whLine =
          '<tr><td colspan="' +
          lineCols +
          '" style="padding:0.35rem 0;border-bottom:1px solid #e2e8f0;direction:rtl;unicode-bidi:isolate;"><strong>المستودع:\u200F</strong> <bdi>' +
          escapeHtml(wopt.text) +
          '</bdi></td></tr>';
      }
    }

    var showDiscTotal =
      (layout.showDiscount || invoiceDiscountLabel !== '') && parseNum(discT) > 0.000001;
    var printTotals =
      ipp && ipp.buildPrintTotals
        ? ipp.buildPrintTotals({
            escapeHtml: escapeHtml,
            invoiceDiscountLabel: invoiceDiscountLabel,
            discountTitle: 'خصم الطلب',
            showDiscountTotal: showDiscTotal,
            discTotalText: discT,
            subTotalText: subT,
            taxTotalText: taxT,
            grandTotalText: grandT,
          })
        : '';

    var metaRows = [
      { label: 'رقم الطلب', value: invNo },
      { label: 'تاريخ الطلب', value: date },
      { label: 'المورد', value: cust },
      { label: 'طريقة الدفع', value: payLabel },
    ];
    if (supInvNo) {
      metaRows.splice(1, 0, { label: 'مرجع المورد / عرض السعر', value: supInvNo });
    }
    var metaTable =
      window.DocumentHeader && typeof window.DocumentHeader.buildMetaTable === 'function'
        ? window.DocumentHeader.buildMetaTable(metaRows)
        : '<table><tr><td style="padding:0.2rem 0;"><strong>رقم الطلب:</strong> ' +
          escapeHtml(invNo) +
          '</td></tr></table>';
    if (repLine || whLine) {
      metaTable = metaTable.replace('</table>', repLine + whLine + '</table>');
    }

    var inner =
      buildDocPrintHeader(DOC_PRINT_TITLE) +
      metaTable +
      buildEinvoicePrintBlock() +
      '<table class="inv-print-lines"><thead><tr>' +
      (ipp ? ipp.theadRow(layout) : '') +
      '</tr></thead><tbody>' +
      rowHtml +
      '</tbody></table>' +
      printTotals +
      notesBlock;
    return window.DocumentHeader && window.DocumentHeader.wrapPrintContent
      ? window.DocumentHeader.wrapPrintContent(inner, companyLogoUrl)
      : inner;
  }

  function docPrintWatermarkStyles() {
    var dh = window.DocumentHeader;
    return dh && companyLogoUrl && dh.buildPrintWatermarkStyles
      ? dh.buildPrintWatermarkStyles(companyLogoUrl)
      : '';
  }

  function getPrintFrameStyles() {
    var hdr =
      window.DocumentHeader && window.DocumentHeader.css ? window.DocumentHeader.css : '';
    var bold =
      window.DocumentHeader && window.DocumentHeader.printBoldCss
        ? window.DocumentHeader.printBoldCss
        : '';
    var invTbl =
      window.InvInvoicePrint && window.InvInvoicePrint.tablePrintCss
        ? window.InvInvoicePrint.tablePrintCss()
        : '';
    return (
    docPrintWatermarkStyles() +
    hdr +
    bold +
    invTbl +
    '.doc-print-meta{text-align:start;direction:rtl;}.doc-print-meta table{width:100%;border-collapse:collapse;}' +
    '.doc-print-meta td{border:none!important;padding:0.2rem 0!important;text-align:start!important;}' +
    '.doc-print-meta-value--party{font-weight:800;font-size:1.12em;color:#0f172a;}' +
    '.sales-inv-print-tot{margin-top:0.75rem;text-align:left;max-width:280px;margin-right:0;margin-left:auto;}' +
    '.sales-inv-print-tot div{display:flex;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid #e2e8f0;font-weight:700;}' +
    '.sales-inv-print-tot .g{font-weight:800;font-size:1.05rem;border-top:2px solid #334155;margin-top:0.35rem;padding-top:0.45rem;}'
    );
  }

  function buildEinvoicePrintBlock() {
    if (!invoiceEinvQr && !invoiceEinvNum && !invoiceEinvStatus) return '';
    var block =
      '<div style="margin:0.75rem 0;padding:0.5rem;border:1px dashed #94a3b8;text-align:center;">' +
      '<div style="font-weight:700;margin-bottom:0.35rem;">الفوترة الإلكترونية</div>';
    if (invoiceEinvNum) {
      block += '<div style="font-size:0.85rem;">رقم الفوترة: ' + escapeHtml(invoiceEinvNum) + '</div>';
    }
    if (invoiceEinvStatus) {
      block += '<div style="font-size:0.82rem;color:#64748b;">الحالة: ' + escapeHtml(invoiceEinvStatus) + '</div>';
    }
    if (einvQrDataUrl) {
      block +=
        '<img src="' +
        einvQrDataUrl +
        '" alt="QR" style="width:120px;height:120px;margin:0.35rem auto;display:block;">';
    } else if (invoiceEinvQr) {
      block += '<div style="font-size:0.75rem;word-break:break-all;max-width:280px;margin:0.35rem auto;">' + escapeHtml(invoiceEinvQr) + '</div>';
    }
    block += '</div>';
    return block;
  }

  function buildStandaloneInvoiceHtml() {
    var bodyAttrs =
      window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
        ? window.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>' +
      escapeHtml(DOC_PRINT_TITLE) +
      '</title>' +
      '<style>' +
      getPrintFrameStyles() +
      '</style></head><body' +
      bodyAttrs +
      '>' +
      buildInvoicePrintInnerHtml() +
      '</body></html>'
    );
  }

  function getPrintFrame() {
    var frame = document.getElementById('sales-inv-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'sales-inv-print-frame';
      frame.className = 'sales-inv-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      document.body.appendChild(frame);
    }
    return frame;
  }

  function printHtmlInFrame(fullHtml) {
    var frame = getPrintFrame();
    if (window.PrintOrientation) {
      fullHtml = PrintOrientation.prepareHtml(fullHtml);
      PrintOrientation.sizeFrame(frame);
    }
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(fullHtml);
    win.document.close();
    setTimeout(function () {
      try {
        win.focus();
        win.print();
      } catch (e) {}
    }, 200);
  }

  function closePrintPreview() {
    var overlay = document.getElementById('sales-inv-print-overlay');
    if (overlay) overlay.hidden = true;
  }

  function openPrintPreview(forPdf) {
    syncJson();
    var preview = document.getElementById('sales-inv-print-preview');
    var overlay = document.getElementById('sales-inv-print-overlay');
    var title = document.querySelector('.sales-inv-print-overlay-title');
    function showPreview() {
      if (!preview || !overlay) {
        printHtmlInFrame(buildStandaloneInvoiceHtml());
        return;
      }
      if (overlay.parentNode !== document.body) {
        document.body.appendChild(overlay);
      }
      preview.innerHTML = '<div class="sales-inv-print-paper">' + buildInvoicePrintInnerHtml() + '</div>';
    if (window.PrintOrientation) {
      var _po = document.getElementById('sales-inv-print-overlay');
      if (_po) PrintOrientation.markActive(_po);
    }
      if (title) {
        title.textContent = forPdf
          ? 'معاينة شكل الورقة — اختر «حفظ كـ PDF» من نافذة الطباعة'
          : 'معاينة شكل الورقة';
      }
      overlay.removeAttribute('hidden');
      overlay.hidden = false;
      overlay.style.display = 'flex';
      overlay.style.zIndex = '10050';
    }
    if (invoiceEinvQr && !einvQrDataUrl) {
      refreshEinvQrDataUrl(showPreview);
    } else {
      showPreview();
    }
  }

  function runPrintFromPreview() {
    printHtmlInFrame(buildStandaloneInvoiceHtml());
  }

  function handleToolbarPrint() {
    var overlay = document.getElementById('sales-inv-print-overlay');
    var previewOpen = overlay && !overlay.hidden;
    if (previewOpen) {
      runPrintFromPreview();
      return;
    }
    openPrintPreview(false);
  }

  function getInvoiceFileBase() {
    var invNoEl = document.getElementById('inv_no');
    var no = invNoEl && invNoEl.value ? String(invNoEl.value).trim() : '';
    if (!no) no = 'draft';
    return 'purchase-order-' + no.replace(/[^\w\u0600-\u06FF\-]+/g, '_');
  }

  function getExportHost() {
    var host = document.getElementById('sales-inv-export-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'sales-inv-export-host';
      host.className = 'sales-inv-export-host';
      host.setAttribute('aria-hidden', 'true');
      document.body.appendChild(host);
    }
    return host;
  }

  function downloadInvoicePdf() {
    syncJson();
    if (typeof html2pdf === 'undefined') {
      AppDialog.error('تعذر تحميل مكتبة PDF. تحقق من الاتصال بالإنترنت ثم أعد تحميل الصفحة.');
      return;
    }
    var go = function () {
      var fname = getInvoiceFileBase() + '.pdf';
      var overlay = document.getElementById('sales-inv-print-overlay');
      var preview = document.getElementById('sales-inv-print-preview');
      if (!overlay || !preview) {
        AppDialog.error('عنصر المعاينة غير متاح. أعد تحميل الصفحة.');
        return;
      }
      var wasHidden = overlay.hidden;
      if (overlay.parentNode !== document.body) {
        document.body.appendChild(overlay);
      }
      preview.innerHTML = buildInvoicePrintInnerHtml();
      overlay.removeAttribute('hidden');
      overlay.hidden = false;
      overlay.style.display = 'flex';
      overlay.style.zIndex = '99999';
      overlay.style.opacity = '0.001';
      var cleanup = function () {
        setTimeout(function () {
          try {
            overlay.style.opacity = '';
            overlay.style.zIndex = '';
            overlay.style.display = '';
            if (wasHidden) {
              overlay.hidden = true;
              overlay.setAttribute('hidden', '');
            }
          } catch (_e) {}
        }, 100);
      };
      var waitForImages = function (cb) {
        var imgs = preview.querySelectorAll('img');
        if (!imgs.length) { cb(); return; }
        var pending = imgs.length;
        var done = false;
        var finish = function () { if (!done) { done = true; cb(); } };
        var safety = setTimeout(finish, 5000);
        Array.prototype.forEach.call(imgs, function (img) {
          if (img.complete && img.naturalWidth > 0) {
            if (--pending <= 0) { clearTimeout(safety); finish(); }
          } else {
            img.addEventListener('load', function () { if (--pending <= 0) { clearTimeout(safety); finish(); } });
            img.addEventListener('error', function () { if (--pending <= 0) { clearTimeout(safety); finish(); } });
          }
        });
      };
      requestAnimationFrame(function () {
        waitForImages(function () {
          try {
            html2pdf()
              .set({
                margin: [10, 10, 10, 10],
                filename: fname,
                image: { type: 'jpeg', quality: 0.95 },
                html2canvas: { scale: 2, logging: false, useCORS: true, allowTaint: true, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'] },
              })
              .from(preview)
              .save()
              .then(cleanup)
              .catch(function (err) {
                try { console.error('[pdf] export failed', err); } catch (_e) {}
                cleanup();
                AppDialog.error('تعذر إنشاء ملف PDF.');
              });
          } catch (err) {
            try { console.error('[pdf] sync error', err); } catch (_e) {}
            cleanup();
            AppDialog.error('تعذر إنشاء ملف PDF.');
          }
        });
      });
    };
    if (typeof invoiceEinvQr !== 'undefined' && invoiceEinvQr && !einvQrDataUrl && typeof refreshEinvQrDataUrl === 'function') {
      refreshEinvQrDataUrl(go);
    } else {
      go();
    }
  }

  function sendInvoiceByEmail() {
    if (!sendEmailUrl) {
      AppDialog.error('خدمة إرسال البريد غير مهيأة.');
      return;
    }
    if (!window.DocSendEmail) {
      AppDialog.error('مكتبة الإرسال غير محمّلة.');
      return;
    }
    syncJson();
    var csrfInp = form.querySelector('input[name="_csrf"]');
    var invNoEl = document.getElementById('inv_no');
    var docNo = invNoEl && invNoEl.value ? String(invNoEl.value).trim() : '';
    window.DocSendEmail.send({
      url: sendEmailUrl,
      docType: 'purchase_invoice',
      docNo: docNo,
      fileBase: getInvoiceFileBase(),
      csrfToken: csrfInp ? csrfInp.value : '',
      buildHtml: buildInvoicePrintInnerHtml,
      overlayId: 'sales-inv-print-overlay',
      previewId: 'sales-inv-print-preview',
    });
  }

  function downloadInvoiceExcel() {
    syncJson();
    var html = buildStandaloneInvoiceHtml();
    var blob = new Blob(['\uFEFF' + html], {
      type: 'application/vnd.ms-excel;charset=utf-8',
    });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = getInvoiceFileBase() + '.xls';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      URL.revokeObjectURL(url);
      a.remove();
    }, 200);
  }

  function trySave(onSuccess, saveOpts) {
    saveOpts = saveOpts || {};
    if (formSubmitting) return;
    if (orderIsApproved) {
      AppDialog.alert('لا يمكن تعديل طلب شراء معتمد.', { type: 'warning' });
      return;
    }
    if (!validateInvoiceBeforeSave({ allowEmptyLines: !!saveOpts.allowEmptyLines })) return;
    pendingAfterSave = typeof onSuccess === 'function' ? onSuccess : null;
    try {
      submitInvoiceForm();
    } catch (err) {
      pendingAfterSave = null;
      console.error('trySave', err);
      formSubmitting = false;
      setSaveBusy(false);
      if (window.AppDialog) {
        AppDialog.error('تعذر حفظ الفاتورة: ' + (err.message || 'خطأ غير معروف'));
      }
    }
  }

  function requestDeleteUnpostedInvoice(invId, invNoLabel, deleteUrl, listUrl) {
    AppDialog.confirm(
      'حذف الفاتورة «' + invNoLabel + '» نهائياً؟\nلا يمكن التراجع عن هذا الإجراء.',
      { title: 'حذف الفاتورة', danger: true, okText: 'حذف' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      var csrfInp = form.querySelector('input[name="_csrf"]');
      fd.append('_csrf', csrfInp ? csrfInp.value : '');
      fd.append('invoice_id', String(invId));
      fetch(deleteUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            var errMsg =
              data.error ||
              (data.errors && data.errors.length ? data.errors.join('؛ ') : '') ||
              data.message ||
              'تعذر حذف الفاتورة.';
            AppDialog.error(errMsg);
            return;
          }
          AppDialog.success(data.message || 'تم حذف الفاتورة.').then(function () {
            window.location.href = listUrl || newInvoiceUrl || window.location.pathname;
          });
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function selectTaxByRate(tr, rate) {
    var taxSel = tr.querySelector('.js-tax');
    if (!taxSel) return;
    var target = parseNum(rate);
    for (var i = 0; i < taxSel.options.length; i++) {
      if (parseNum(taxSel.options[i].getAttribute('data-rate')) === target) {
        taxSel.selectedIndex = i;
        return;
      }
    }
  }

  function addLineFromData(ln) {
    var tr = createRow(false);
    tr.dataset.itemId = String(ln.item_id);
    tr.dataset.nameAr = ln.name_ar || ln.line_desc || '';
    tr.dataset.sub = String(ln.line_subtotal != null ? ln.line_subtotal : ln.line_total || 0);
    tr.dataset.tax = String(ln.tax_amount || 0);
    tr.dataset.gross = String(ln.line_gross != null ? ln.line_gross : ln.line_total || 0);
    setRowItemDisplay(tr, tr.dataset.nameAr, ln.barcode || '', ln.sku || '');
    tr.querySelector('.js-qty').value = formatQtyValue(ln.qty);
    var qtyExtraEl = tr.querySelector('.js-qty-extra');
    if (qtyExtraEl) {
      qtyExtraEl.value = formatQtyValue(ln.qty_extra != null ? ln.qty_extra : 0);
    }
    tr.querySelector('.js-price').value = formatUnitPriceValue(
      ln.unit_price,
      ln.unit_price != null ? String(ln.unit_price) : ''
    );
    var loadedUp = parseNum(ln.unit_price);
    var loadedFactor = parseNum(ln.unit_factor != null ? ln.unit_factor : 1) || 1;
    var basePriceEl = tr.querySelector('.js-base-price');
    if (basePriceEl && loadedFactor > 0 && loadedUp > 0) {
      basePriceEl.value = String(loadedUp / loadedFactor);
    }
    fillRowUnits(tr, ln.units || [{ unit_id: ln.unit_id || 0, name: ln.unit_name || '—', factor: loadedFactor, is_default: true }], parseInt(ln.unit_id, 10) || 0);
    if (loadedUp > 0) {
      tr.dataset.listUnitPrice = String(loadedUp);
    } else {
      delete tr.dataset.listUnitPrice;
    }
    applyQtyPriceInputAttrs(tr);
    selectTaxByRate(tr, ln.tax_rate_percent || 0);
    var discEl = tr.querySelector('.js-discount');
    if (discEl) {
      discEl.value = ln.line_discount_input || '';
      discEl.readOnly = false;
      discEl.classList.remove('is-header-discount');
    }
    var subInp = tr.querySelector('input.js-line-sub');
    if (subInp) {
      subInp.value = formatAmountValue(parseNum(tr.dataset.sub), String(tr.dataset.sub));
    }
    setLineGrossDisplay(tr, parseNum(tr.dataset.gross));
    var loadDriver = ln.amount_driver || inferLineAmountDriver(ln);
    tr.dataset.amountDriver = loadDriver;
    recalcRow(tr, loadDriver, { normalizeStored: true });
    applyRowItemPickLock(tr);
    insertDataRowBeforeEntry(tr);
    return tr;
  }

  function applyBrowseNavFromPayload(payload) {
    if (window.DocumentNoNav && DocumentNoNav.applyBrowseNav) {
      DocumentNoNav.applyBrowseNav(docNoSearch, payload, setBrowseNav, DOC_NO_SEARCH_UI);
      return;
    }
    setBrowseNav(payload.prev_id || 0, payload.next_id || 0);
  }

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    updateNavButtons(browseNavPrevId, browseNavNextId);
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('inv_no_prev', 'inv_no_next', prevId, nextId, {
        onEmpty: currentOrderId < 1,
        prevTitle: 'الفاتورة السابقة',
        nextTitle: 'الفاتورة التالية',
        prevBeforeLatestTitle: 'الفاتورة قبل الأخيرة',
        latestTitle: 'آخر فاتورة شراء',
      });
      return;
    }
    var prevBtn = document.getElementById('inv_no_prev');
    var nextBtn = document.getElementById('inv_no_next');
    if (prevBtn) prevBtn.disabled = !(prevId > 0);
    if (nextBtn) nextBtn.disabled = !(nextId > 0);
  }

  function navigateEmptyInvoice(dir) {
    var opts = {
      browseNavPrevId: browseNavPrevId,
      browseNavNextId: browseNavNextId,
      fetchById: function (id) {
        return fetchInvoiceResponse({ id: id });
      },
      fetchLatest: function () {
        return fetchInvoiceResponse({ filter: 'all', edge: 'first' });
      },
      isOk: function (data) {
        return !!(data && data.ok && data.invoice);
      },
      getPayload: function (data) {
        return data.invoice;
      },
      apply: applyInvoiceData,
      emptyMessage: 'لا توجد فواتير محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر فاتورة.',
      loadError: 'تعذر تحميل الفاتورة.',
    };
    if (window.DocumentNoNav) {
      return DocumentNoNav.navigateEmpty(dir, opts);
    }
    return opts.fetchLatest().then(function (data) {
      if (opts.isOk(data)) opts.apply(opts.getPayload(data));
      else AppDialog.alert(opts.emptyMessage, { type: 'info' });
    });
  }

  /** تفعيل أسهم التنقل عند فتح الشاشة بفاتورة جديدة (لا توجد فاتورة محملة). */
  function refreshEmptyBrowseNav() {
    if (!apiOrderUrl) {
      setBrowseNav(0, 0);
      return;
    }
    fetchInvoiceResponse({ filter: 'all', edge: 'first' }).then(function (data) {
      if (!data || !data.ok || !data.invoice) {
        setBrowseNav(0, 0);
        return;
      }
      var inv = data.invoice;
      var newestId = parseInt(inv.id, 10) || 0;
      setBrowseNav(inv.prev_id || 0, newestId);
    });
  }

  function ledgerReturnQs() {
    return form.getAttribute('data-ledger-return-qs') || '';
  }

  function appendLedgerReturnQs(url) {
    var qs = ledgerReturnQs();
    if (!qs) return url;
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + qs;
  }

  function updateHistory(id) {
    if (!window.history || !window.history.replaceState) return;
    var base = newInvoiceUrl || window.location.pathname + '?r=purchase_orders';
    var url = id > 0 ? base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id : base;
    url = appendLedgerReturnQs(url);
    window.history.replaceState({ invoiceId: id }, '', url);
  }

  function updateInvoiceNoPostedStyle() {
    var invNo = document.getElementById('inv_no');
    if (!invNo) return;
    invNo.classList.remove('is-posted', 'is-unposted');
    if (currentOrderId < 1) return;
    if (orderIsApproved) {
      invNo.classList.add('is-posted');
    } else {
      invNo.classList.add('is-unposted');
    }
  }

  function updatePostedBadge() {
    var el = document.getElementById('inv_posted_badge');
    if (currentOrderId < 1) {
      if (el) el.hidden = true;
      updateInvoiceNoPostedStyle();
      return;
    }
    if (el) {
      el.hidden = false;
      if (orderStatusLabel) {
        el.textContent = orderStatusLabel;
        el.className = 'sales-inv-posted-badge badge ' + (orderIsApproved ? 'badge-posted' : 'badge-warn');
      } else if (orderIsApproved) {
        el.textContent = 'معتمد';
        el.className = 'sales-inv-posted-badge badge badge-posted';
      } else {
        el.textContent = 'مسودة';
        el.className = 'sales-inv-posted-badge badge badge-warn';
      }
    }
    updateInvoiceNoPostedStyle();
    updateEinvoiceBadge();
  }

  function updateEinvoiceBadge() {
    var el = document.getElementById('inv_einv_badge');
    if (!el) return;
    if (currentOrderId < 1) {
      el.hidden = true;
      return;
    }
    el.hidden = false;
    if (invoiceEinvQr) {
      el.textContent = 'مُرسلة للفوترة';
      el.className = 'sales-inv-posted-badge badge badge-ok';
    } else if (invoiceEinvStatus) {
      el.textContent = 'فوترة: ' + invoiceEinvStatus;
      el.className = 'sales-inv-posted-badge badge badge-warn';
    } else {
      el.textContent = 'لم تُرسل للفوترة';
      el.className = 'sales-inv-posted-badge badge badge-warn';
    }
  }

  function refreshEinvQrDataUrl(cb) {
    if (!invoiceEinvQr || typeof window.QRCode === 'undefined') {
      einvQrDataUrl = '';
      if (cb) cb();
      return;
    }
    window.QRCode.toDataURL(invoiceEinvQr, { width: 140, margin: 1 }, function (err, url) {
      einvQrDataUrl = err ? '' : url || '';
      if (cb) cb();
    });
  }

  function applyEinvoiceFromInvoice(inv) {
    invoiceEinvQr = (inv && inv.einv_qr) ? String(inv.einv_qr) : '';
    invoiceEinvStatus = (inv && inv.einv_status) ? String(inv.einv_status) : '';
    invoiceEinvNum = (inv && inv.einv_num) ? String(inv.einv_num) : '';
    einvQrDataUrl = '';
    if (invoiceEinvQr) {
      refreshEinvQrDataUrl(updateEinvoiceBadge);
    } else {
      updateEinvoiceBadge();
    }
  }

  function parsePostInvoiceJsonResponse(r) {
    return r.text().then(function (text) {
      var data = null;
      if (text) {
        try {
          data = JSON.parse(text);
        } catch (e) {
          data = null;
        }
      }
      return { data: data };
    });
  }

  function refreshInvoiceAfterPostAttempt(errMsg) {
    if (currentOrderId < 1) {
      if (errMsg) AppDialog.error(errMsg);
      return Promise.resolve(false);
    }
    return fetchInvoiceResponse({ id: currentOrderId }).then(function (data) {
      if (data && data.ok && data.invoice) {
        applyInvoiceData(data.invoice);
        updatePostedBadge();
        if (orderIsApproved) {
          var msg = 'تم اعتماد طلب الشراء.';
          if (errMsg) {
            msg += '\n\n' + errMsg;
          }
          AppDialog.success(msg);
          return true;
        }
      }
      if (errMsg) AppDialog.error(errMsg);
      return false;
    });
  }

  function postCurrentInvoice() {
    if (!orderApproveUrl) {
      AppDialog.alert('الاعتماد غير متاح.', { type: 'warning' });
      return;
    }
    if (currentOrderId < 1) {
      trySave(function () {
        if (currentOrderId < 1) {
          AppDialog.error('تعذر حفظ الطلب قبل الاعتماد.');
          return;
        }
        postCurrentInvoice();
      });
      return;
    }
    if (orderIsApproved) {
      AppDialog.alert('هذا الطلب معتمد مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm(
      'اعتماد طلب الشراء هذا؟\n\nبعد الاعتماد لا يمكن التعديل إلا بفك الاعتماد (إن لم تُنشأ فواتير).',
      { title: 'اعتماد طلب شراء', okText: 'اعتماد' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('order_id', String(currentOrderId));
      fd.append('invoice_id', String(currentOrderId));
      fetch(orderApproveUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(parsePostInvoiceJsonResponse)
        .then(function (res) {
          var data = res.data;
          if (!data) {
            return refreshInvoiceAfterPostAttempt('تعذر قراءة رد الخادم. جارٍ التحقق من حالة الطلب…');
          }
          if (!data.ok) {
            var errText = data.error || data.message || 'تعذر الترحيل.';
            return refreshInvoiceAfterPostAttempt(errText);
          }
          orderIsApproved = true;
          updatePostedBadge();
          var successMsg = data.message || 'تم الاعتماد.';
          if (data.warning) {
            successMsg += '\n\nتنبيه: ' + data.warning;
          }
          AppDialog.success(successMsg);
          if (currentOrderId > 0) {
            loadInvoiceById(currentOrderId);
          }
        })
        .catch(function () {
          refreshInvoiceAfterPostAttempt('تعذر الاتصال بالخادم. جارٍ التحقق من حالة الطلب…');
        });
    });
  }

  function unpostCurrentInvoice() {
    if (!canUnpostByPermission) {
      AppDialog.alert('ليس لديك صلاحية فك ترحيل فاتورة الشراء.', { type: 'warning' });
      return;
    }
    if (!orderUnapproveUrl) {
      AppDialog.alert('فك الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentOrderId < 1) {
      AppDialog.alert('احفظ طلب الشراء أولًا.', { type: 'warning' });
      return;
    }
    if (!orderIsApproved) {
      AppDialog.alert('هذا الطلب غير معتمد.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm(
      'فك اعتماد طلب الشراء وإعادته إلى مسودة؟\n\nلا يمكن ذلك إذا وُجدت فواتير مرتبطة.',
      { title: 'فك الاعتماد', okText: 'فك الاعتماد' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('order_id', String(currentOrderId));
      fd.append('invoice_id', String(currentOrderId));
      fetch(orderUnapproveUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            var errMsg = (data && (data.error || data.message)) || 'تعذر فك الترحيل.';
            AppDialog.error(errMsg);
            return;
          }
          AppDialog.success(data.message || 'تم فك الترحيل.');
          if (currentOrderId > 0) loadInvoiceById(currentOrderId);
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function sendCurrentInvoiceToEinvoice() {
    if (!canSendEinvoice || !einvoiceSendUrl) {
      AppDialog.alert('ليس لديك صلاحية إرسال الفوترة.', { type: 'warning' });
      return;
    }
    if (currentOrderId < 1) {
      AppDialog.alert('احفظ الفاتورة أولًا.', { type: 'warning' });
      return;
    }
    if (invoiceEinvQr) {
      AppDialog.alert('تم إرسال هذه الفاتورة للفوترة مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm('إرسال هذه الفاتورة إلى نظام الفوترة الأردني؟', {
      title: 'إرسال للفوترة',
      okText: 'إرسال',
    }).then(
      function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('_csrf', csrfInput ? csrfInput.value : '');
        fd.append('invoice_id', String(currentOrderId));
        fetch(einvoiceSendUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) {
              AppDialog.error(data.error || data.message || 'تعذر الإرسال.');
              return;
            }
            AppDialog.success(data.message || 'تمت العملية.');
            if (currentOrderId > 0) loadInvoiceById(currentOrderId);
          })
          .catch(function () {
            AppDialog.error('تعذر الاتصال بالخادم.');
          });
      }
    );
  }

  function applyInvoiceData(inv) {
    runWithoutDirtyMark(function () {
    currentOrderId = parseInt(inv.id, 10) || 0;
    orderIsApproved = !!(inv.is_approved || inv.is_posted);
    orderIsEditable = inv.is_editable !== false;
    orderStatusLabel = inv.status_label || '';
    var invDp = parseInt(inv.amount_decimals, 10);
    if (!isNaN(invDp) && invDp >= 0) {
      form.setAttribute('data-decimals', String(invDp));
      decimals = invDp;
    }
    applyEinvoiceFromInvoice(inv);
    var recId = document.getElementById('inv_record_id');
    if (recId) recId.value = currentOrderId > 0 ? String(currentOrderId) : '';
    syncInvoiceNoDisplay(inv.invoice_no || '');

    var invDate = document.getElementById('inv_date');
    if (invDate) {
      invDate.value = fmtDate(inv.invoice_date || inv.order_date || '') || defaultDate;
    }

    var expDate = document.getElementById('po_expected_date');
    if (expDate) {
      expDate.value = fmtDate(inv.expected_date || '') || '';
    }

    var paySel = document.getElementById('inv_payment_type');
    if (paySel && inv.payment_type) paySel.value = inv.payment_type;

    var custSel = document.getElementById('inv_supplier');
    if (custSel) custSel.value = String(inv.supplier_id || '');
    setSalesRepFromInvoice(inv);

    var wh = document.getElementById('inv_wh');
    if (wh) wh.value = inv.warehouse_id ? String(inv.warehouse_id) : '';

    var notes = document.getElementById('inv_notes');
    if (notes) notes.value = inv.notes || '';

    var supInvNo = document.getElementById('inv_supplier_invoice_no');
    if (supInvNo) supInvNo.value = inv.supplier_invoice_no || '';

    var invDisc = document.getElementById('inv-invoice-discount');
    if (invDisc) {
      invDisc.value = inv.invoice_discount_input || '';
      headerDiscountMode = !!(inv.invoice_discount_input && String(inv.invoice_discount_input).trim());
    } else {
      headerDiscountMode = false;
    }

    tbody.innerHTML = '';
    ensureEntryRow();
    (inv.lines || []).forEach(function (ln) {
      addLineFromData(ln);
    });

    renumberRows();
    if (headerDiscountMode) {
      applyHeaderDiscount();
    } else {
      recalcAllItemRows();
      recalcFooter();
    }
    applyDecimalPlacesToInvoiceScreen();
    syncInvoiceIdField();
    refreshInvoiceEditState();
    clearPersistedDraft();
    applyBrowseNavFromPayload(inv);
    updateHistory(currentOrderId);
    updatePostedBadge();
    closePicker();
    var convertBtn = document.getElementById('po_convert_invoice_btn');
    if (convertBtn) {
      var st = inv.status || '';
      convertBtn.hidden = !(orderIsApproved && st !== 'closed' && st !== 'cancelled');
    }
    });
  }

  function convertOrderToInvoice() {
    if (!orderConvertUrl) {
      AppDialog.alert('التحويل إلى فاتورة غير متاح.', { type: 'warning' });
      return;
    }
    if (currentOrderId < 1) {
      AppDialog.alert('احفظ طلب الشراء أولًا.', { type: 'warning' });
      return;
    }
    if (!orderIsApproved) {
      AppDialog.alert('يجب اعتماد الطلب قبل التحويل إلى فاتورة.', { type: 'warning' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm('إنشاء فاتورة شراء من هذا الطلب (البنود غير المُفوَّتة)؟', {
      title: 'تحويل إلى فاتورة',
      okText: 'تحويل',
    }).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('order_id', String(currentOrderId));
      fetch(orderConvertUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            AppDialog.error((data && data.message) || 'تعذر التحويل.');
            return;
          }
          AppDialog.success(data.message || 'تم إنشاء فاتورة الشراء.').then(function () {
            if (data.redirect_url) {
              window.location.href = data.redirect_url;
            } else if (currentOrderId > 0) {
              loadInvoiceById(currentOrderId);
            }
          });
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function fetchInvoiceResponse(query) {
    if (!apiOrderUrl) return Promise.resolve(null);
    var qs = Object.keys(query)
      .map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
      })
      .join('&');
    return fetch(apiOrderUrl + '?' + qs, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function loadInvoiceById(id) {
    if (id < 1) return;
    confirmUnsavedChanges(function () {
      fetchInvoiceResponse({ id: id }).then(function (data) {
        if (!data || !data.ok || !data.invoice) {
          AppDialog.error((data && data.message) || 'تعذر تحميل الفاتورة.');
          return;
        }
        applyInvoiceData(data.invoice);
      });
    });
  }

  function loadInvoiceByNo(no) {
    no = String(no || '').trim();
    if (!no) return;
    confirmUnsavedChanges(function () {
      fetchInvoiceResponse({ no: no }).then(function (data) {
        if (!data || !data.ok || !data.invoice) {
          AppDialog.error((data && data.message) || 'لم يتم العثور على طلب يحتوي على هذا الرقم.');
          return;
        }
        applyInvoiceData(data.invoice);
      });
    });
  }

  function loadInvoiceBySupplierNo(supplierNo) {
    supplierNo = String(supplierNo || '').trim();
    if (!supplierNo) return;
    confirmUnsavedChanges(function () {
      fetchInvoiceResponse({ supplier_no: supplierNo }).then(function (data) {
        if (!data || !data.ok || !data.invoice) {
          AppDialog.error((data && data.message) || 'لم يتم العثور على فاتورة برقم فاتورة المورد هذا.');
          return;
        }
        applyInvoiceData(data.invoice);
      });
    });
  }

  function runToolbarInvoiceSearch() {
    var invNoEl = document.getElementById('inv_no');
    var supNoEl = document.getElementById('inv_supplier_invoice_no');
    var no = invNoEl ? String(invNoEl.value || '').trim() : '';
    var supplierNo = supNoEl ? String(supNoEl.value || '').trim() : '';

    if (!no && !supplierNo) {
      AppDialog.alert('أدخل رقم الفاتورة أو رقم فاتورة المورد ثم اضغط بحث.', { type: 'warning' });
      if (invNoEl) invNoEl.focus();
      return;
    }

    if (no) {
      confirmUnsavedChanges(function () {
        fetchInvoiceResponse({ no: no }).then(function (data) {
          if (data && data.ok && data.invoice) {
            applyInvoiceData(data.invoice);
            return;
          }
          if (supplierNo) {
            fetchInvoiceResponse({ supplier_no: supplierNo }).then(function (data2) {
              if (!data2 || !data2.ok || !data2.invoice) {
                AppDialog.error(
                  (data2 && data2.message) ||
                    (data && data.message) ||
                    'لم يتم العثور على فاتورة بهذا الرقم.'
                );
                return;
              }
              applyInvoiceData(data2.invoice);
            });
            return;
          }
          AppDialog.error((data && data.message) || 'لم يتم العثور على فاتورة بهذا الرقم.');
        });
      });
      return;
    }

    loadInvoiceBySupplierNo(supplierNo);
  }

  function navigateInvoice(dir) {
    confirmUnsavedChanges(function () {
      navigateInvoiceCore(dir);
    });
  }

  function navigateInvoiceCore(dir) {
    if (currentOrderId < 1) {
      navigateEmptyInvoice(dir);
      return;
    }
    if (window.DocumentNoNav && DocumentNoNav.isSearchActive(docNoSearch)) {
      DocumentNoNav.navigateSearchMatch(dir, docNoSearch, {
        fetchById: function (id) {
          return fetchInvoiceResponse({ id: id });
        },
        isOk: function (data) {
          return !!(data && data.ok && data.invoice);
        },
        getPayload: function (data) {
          return data.invoice;
        },
        apply: applyInvoiceData,
        loadError: 'تعذر تحميل الطلب.',
      });
      return;
    }
    fetchInvoiceResponse({ id: currentOrderId, dir: dir }).then(function (data) {
      if (!data || !data.ok || !data.invoice) {
        AppDialog.alert(
          (data && data.message) ||
            (dir === 'prev' ? 'لا توجد فاتورة أقدم.' : 'لا توجد فاتورة أحدث.'),
          { type: 'info' }
        );
        return;
      }
      applyInvoiceData(data.invoice);
    });
  }

  function initNewInvoice(keepBrowseNav) {
    if (window.DocumentNoNav) DocumentNoNav.clearSearch(docNoSearch);
    currentOrderId = 0;
    orderIsApproved = false;
    applyEinvoiceFromInvoice(null);
    updatePostedBadge();
    refreshInvoiceEditState();
    clearPersistedDraft();
    if (keepBrowseNav && (browseNavPrevId > 0 || browseNavNextId > 0)) {
      updateNavButtons(browseNavPrevId, browseNavNextId);
    } else {
      refreshEmptyBrowseNav();
    }
    var recId = document.getElementById('inv_record_id');
    if (recId) recId.value = '';
    var invNo = document.getElementById('inv_no');
    syncInvoiceNoDisplay('');
    resetInvoiceForm();
    updateHistory(0);
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!form || !e.detail) return;
    var action = e.detail.action;

    if (ledgerView && action !== 'exit' && action !== 'print' && action !== 'pdf' && action !== 'excel') {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }

    if (action === 'search') {
      e.preventDefault();
      e.stopImmediatePropagation();
      runToolbarInvoiceSearch();
      return;
    }
    if (action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();
      trySave();
      return;
    }
    if (action === 'post') {
      e.preventDefault();
      e.stopImmediatePropagation();
      postCurrentInvoice();
      return;
    }
    if (action === 'unpost') {
      e.preventDefault();
      e.stopImmediatePropagation();
      unpostCurrentInvoice();
      return;
    }
    if (action === 'print') {
      e.preventDefault();
      handleToolbarPrint();
      return;
    }
    if (action === 'pdf') {
      e.preventDefault();
      downloadInvoicePdf();
      return;
    }
    if (action === 'excel') {
      e.preventDefault();
      downloadInvoiceExcel();
      return;
    }
    if (action === 'send_einvoice') {
      e.preventDefault();
      sendCurrentInvoiceToEinvoice();
      return;
    }
    if (action === 'send_email') {
      e.preventDefault();
      sendInvoiceByEmail();
      return;
    }
    if (action === 'delete') {
      e.preventDefault();
      if (currentOrderId > 0) {
        if (orderIsApproved) {
          AppDialog.alert('لا يمكن حذف فاتورة مرحّلة.', { type: 'warning' });
          return;
        }
        var recIdEl = document.getElementById('inv_record_id');
        var invId = currentOrderId > 0 ? currentOrderId : recIdEl ? parseInt(recIdEl.value, 10) : 0;
        if (invId < 1) {
          if (newInvoiceUrl) window.location.href = newInvoiceUrl;
          else initNewInvoice();
          return;
        }
        if (invoiceHasLines()) {
          AppDialog.alert(MSG_DELETE_INVOICE_NEEDS_EMPTY_LINES, { type: 'warning' });
          return;
        }
        var invNoEl = document.getElementById('inv_no');
        var invNoLabel = invNoEl && invNoEl.value ? invNoEl.value : String(invId);
        var deleteUrl = form.getAttribute('data-order-delete-url') || '';
        var listUrl = form.getAttribute('data-list-url') || newInvoiceUrl || '';
        if (!deleteUrl) {
          AppDialog.error('حذف الفاتورة غير متاح.');
          return;
        }
        trySave(
          function () {
            requestDeleteUnpostedInvoice(invId, invNoLabel, deleteUrl, listUrl);
          },
          { allowEmptyLines: true }
        );
        return;
      }
      if (!hasDraftContent()) {
        resetInvoiceForm();
        return;
      }
      if (invoiceHasLines()) {
        AppDialog.alert(MSG_DELETE_INVOICE_NEEDS_EMPTY_LINES, { type: 'warning' });
        return;
      }
      AppDialog.confirm('مسح بيانات الفاتورة الحالية (البنود والحقول)؟', {
        title: 'مسح الفاتورة',
        danger: true,
        okText: 'مسح',
      }).then(function (ok) {
        if (ok) resetInvoiceForm();
      });
      return;
    }
    if (action === 'exit') {
      e.preventDefault();
      confirmUnsavedChanges(function () {
        if (exitUrl) {
          window.location.href = exitUrl;
        } else {
          var bar = document.getElementById('master-toolbar');
          var url = bar ? bar.getAttribute('data-exit-url') : '';
          if (url) window.location.href = url;
          else window.history.back();
        }
      });
    }
  });

  var newInvBtn = document.querySelector('.sales-inv-btn-new');
  if (newInvBtn) {
    newInvBtn.addEventListener('click', function (e) {
      e.preventDefault();
      confirmUnsavedChanges(function () {
        if (newInvoiceUrl) {
          window.location.href = newInvoiceUrl;
        } else {
          initNewInvoice();
        }
      });
    });
  }

  function setSalesRepFromCustomer(opt) {
    var repHidden = document.getElementById('inv_sales_rep');
    var repDisplay = document.getElementById('inv_sales_rep_display');
    if (!repHidden || !repDisplay) return;

    if (!opt || !opt.value) {
      repHidden.value = '';
      repDisplay.value = '—';
      return;
    }

    var repId = opt.getAttribute('data-sales-rep-id') || '';
    var repName = opt.getAttribute('data-sales-rep-name') || '';
    repHidden.value = repId && repId !== '0' ? repId : '';
    repDisplay.value =
      repName.trim() !== '' ? repName.trim() : repId && repId !== '0' ? 'مندوب #' + repId : '— بدون مندوب —';
  }

  function applyCustomerSalesRep() {
    var custSel = document.getElementById('inv_supplier');
    if (!custSel) return;
    setSalesRepFromCustomer(custSel.options[custSel.selectedIndex]);
  }

  function setSalesRepFromInvoice(inv) {
    var repHidden = document.getElementById('inv_sales_rep');
    var repDisplay = document.getElementById('inv_sales_rep_display');
    if (!repHidden || !repDisplay) return;
    var repId = inv.sales_rep_id ? String(inv.sales_rep_id) : '';
    repHidden.value = repId;
    var name = (inv.sales_rep_name || '').trim();
    repDisplay.value = name !== '' ? name : repId ? 'مندوب #' + repId : '— بدون مندوب —';
  }

  var custEl = document.getElementById('inv_supplier');
  if (custEl) {
    custEl.addEventListener('change', applyCustomerSalesRep);
    applyCustomerSalesRep();
  }

  var prevBtn = document.getElementById('inv_no_prev');
  var nextBtn = document.getElementById('inv_no_next');
  var invNoInput = document.getElementById('inv_no');
  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      navigateInvoice('prev');
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      navigateInvoice('next');
    });
  }
  if (invNoInput) {
    invNoInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      runToolbarInvoiceSearch();
    });
    invNoInput.addEventListener('blur', function () {
      var no = invNoInput.value.trim();
      if (window.DocumentNoNav && DocumentNoNav.shouldSkipBlurSearch(docNoSearch, currentOrderId, no)) {
        return;
      }
      loadInvoiceByNo(no);
    });
  }

  var supInvNoInput = document.getElementById('inv_supplier_invoice_no');
  if (supInvNoInput) {
    supInvNoInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      runToolbarInvoiceSearch();
    });
  }

  var printCloseBtn = document.getElementById('sales-inv-print-close');
  var printRunBtn = document.getElementById('sales-inv-print-run');
  var printOverlay = document.getElementById('sales-inv-print-overlay');
  if (printRunBtn) {
    printRunBtn.addEventListener('click', function () {
      runPrintFromPreview();
    });
  }
  if (printCloseBtn) {
    printCloseBtn.addEventListener('click', closePrintPreview);
  }
  if (printOverlay) {
    printOverlay.addEventListener('click', function (e) {
      if (e.target === printOverlay) closePrintPreview();
    });
  }
  var invHdrDisc = document.getElementById('inv-invoice-discount');
  var headerDiscTimer = null;
  if (invHdrDisc) {
    invHdrDisc.addEventListener('input', function () {
      clearTimeout(headerDiscTimer);
      headerDiscTimer = setTimeout(applyHeaderDiscount, 100);
    });
    invHdrDisc.addEventListener('change', applyHeaderDiscount);
    invHdrDisc.addEventListener('blur', applyHeaderDiscount);
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var overlay = document.getElementById('sales-inv-print-overlay');
    if (overlay && !overlay.hidden) closePrintPreview();
  });

  function bootInvoicePage() {
    refreshInvoiceDecimalsFromSettings();
    if (global.AppFormat && AppFormat.setDecimalPlaces) {
      AppFormat.setDecimalPlaces(decimals, { persist: false, silent: true });
    }
    if (global.AppFormat && AppFormat.setInvoiceUnitPriceDecimals) {
      AppFormat.setInvoiceUnitPriceDecimals(unitPriceDecimals, { persist: false, silent: true });
    }
    var whSelect = document.getElementById('inv_wh');
    if (whSelect && global.ItemPickerModal) {
      whSelect.addEventListener('change', function () {
        ItemPickerModal.invalidateCache();
        if (ItemPickerModal.isOpen()) {
          ItemPickerModal.reload();
        }
      });
    }
    bootstrapExistingRows();

    if (initialOrderId > 0) {
      var restoredSaved = false;
      try {
        var rawInit = sessionStorage.getItem('manager:inv_draft:' + draftKey);
        if (rawInit) {
          var draftInit = JSON.parse(rawInit);
          if (
            draftInit &&
            draftInit.v === 1 &&
            parseInt(draftInit.currentOrderId, 10) === initialOrderId &&
            draftInit.lines &&
            draftInit.lines.length
          ) {
            applyDraftSnapshot(draftInit);
            restoredSaved = true;
          }
        }
      } catch (eInit) {
        clearPersistedDraft();
      }
      if (!restoredSaved) {
        clearPersistedDraft();
        fetchInvoiceResponse({ id: initialOrderId }).then(function (data) {
          if (data && data.ok && data.invoice) {
            applyInvoiceData(data.invoice);
          }
        });
      }
      safeEnsureEntryRow();
      return;
    }

    runWithoutDirtyMark(function () {
      var restored = tryRestoreDraft();
      if (!restored) {
        applyDefaultWarehouse();
      }
      if (!restored && !getEntryRow()) {
        tbody.innerHTML = '';
      }
      bootstrapExistingRows();
      safeEnsureEntryRow();
      renumberRows();
      recalcFooter();
      syncJson();
      refreshInvoiceEditState();
      refreshEmptyBrowseNav();
      var firstEntry = getEntryRow();
      if (firstEntry) {
        setTimeout(function () {
          var bc = firstEntry.querySelector('.js-barcode-inp');
          if (bc) bc.focus();
        }, 80);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootInvoicePage);
  } else {
    bootInvoicePage();
  }

  window.addEventListener(
    'load',
    function () {
      applyDecimalPlacesToInvoiceScreen();
    },
    { once: true }
  );

  var poConvertBtn = document.getElementById('po_convert_invoice_btn');
  if (poConvertBtn) {
    poConvertBtn.addEventListener('click', convertOrderToInvoice);
  }

  window.addEventListener('beforeunload', function (e) {
    if (window.__managerAllowUnload) return;
    if (formSubmitting || !formDirty || orderIsApproved) return;
    persistDraft();
    e.preventDefault();
    e.returnValue = '';
  });

  if (global.ScreenExitGuard && typeof global.ScreenExitGuard.registerScreenExitDeferred === 'function') {
    global.ScreenExitGuard.registerScreenExitDeferred({
      hasUnsaved: function () {
        return formDirty && !orderIsApproved;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  } else if (global.ScreenExitGuard && typeof global.ScreenExitGuard.registerScreenExit === 'function') {
    global.ScreenExitGuard.registerScreenExit({
      hasUnsaved: function () {
        return formDirty && !orderIsApproved;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  } else {
    global.ManagerScreenExit = {
      hasUnsaved: function () {
        return formDirty && !orderIsApproved;
      },
      confirmLeave: confirmUnsavedChanges,
    };
  }
})();
