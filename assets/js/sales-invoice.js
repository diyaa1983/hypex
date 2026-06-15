(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('sales-inv-form');
  if (!form) return;

  var ledgerView = form.getAttribute('data-ledger-view') === '1';
  var headerDiscountMode = false;

  var tbody = document.getElementById('sales-inv-lines-body');
  var tpl = document.getElementById('sales-inv-line-template');
  if (!tbody) {
    console.error('sales-invoice: missing table body');
    return;
  }
  if (!tpl) {
    console.warn('sales-invoice: line template missing');
  }
  var linesJson = document.getElementById('sales-inv-lines-json');
  var apiUrl = form.getAttribute('data-api-items') || '';
  var apiInvoiceUrl = form.getAttribute('data-api-invoice') || '';
  var deliveryPickUrl = form.getAttribute('data-delivery-pick-url') || '';
  var linkDeliveryUrl = form.getAttribute('data-link-delivery-url') || '';
  var unlinkDeliveryUrl = form.getAttribute('data-unlink-delivery-url') || '';
  var invoicePostUrl = form.getAttribute('data-invoice-post-url') || '';
  var invoiceUnpostUrl = form.getAttribute('data-invoice-unpost-url') || '';
  var canUnpostByPermission = form.getAttribute('data-can-unpost') === '1';
  var einvoiceSendUrl = form.getAttribute('data-einvoice-send-url') || '';
  var sendEmailUrl = form.getAttribute('data-send-email-url') || '';
  var canSendEinvoice = form.getAttribute('data-can-send-einvoice') === '1';
  var newInvoiceUrl = form.getAttribute('data-new-url') || '';
  var initialInvoiceId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var currentInvoiceId = 0;
  var linkedDeliveryId = 0;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var invoiceIsPosted = false;
  var invoiceEinvQr = '';
  var invoiceEinvStatus = '';
  var invoiceEinvNum = '';
  var einvQrDataUrl = '';
  var isSavedMode = false;
  var formDirty = false;
  var formSubmitting = false;
  var suppressDirtyMark = 0;
  var draftPersistTimer = null;
  var draftKey = form.getAttribute('data-draft-key') || 'sales_invoices';

  function fmtDate(value) {
    return global.AppFormat && AppFormat.formatDateDmY
      ? AppFormat.formatDateDmY(value)
      : String(value == null ? '' : value);
  }

  var customersList = [];
  var customersById = {};
  var customerPickerApi = null;
  var custPickerOpen = document.getElementById('inv_customer_open');

  function loadCustomersCatalog() {
    var el = document.getElementById('sales-inv-customers-json');
    if (!el) return;
    try {
      customersList = JSON.parse(el.textContent || '[]');
      customersById = {};
      customersList.forEach(function (c) {
        var id = parseInt(c.id, 10);
        if (id > 0) customersById[id] = c;
      });
    } catch (e) {
      customersList = [];
      customersById = {};
    }
  }

  function getCustomerSalesReps(c) {
    if (!c) return [];
    if (c.sales_reps && c.sales_reps.length) {
      return c.sales_reps.filter(function (r) {
        return r && parseInt(r.id, 10) > 0;
      });
    }
    var repId = c.sales_rep_id ? parseInt(c.sales_rep_id, 10) : 0;
    if (repId > 0) {
      return [{ id: repId, name_ar: (c.sales_rep_name || '').trim() }];
    }
    return [];
  }

  function salesRepOptionEl(value, label) {
    var opt = document.createElement('option');
    opt.value = value;
    opt.textContent = label;
    return opt;
  }

  function syncSalesRepSelectLock() {
    var sel = document.getElementById('inv_sales_rep');
    if (!sel) return;
    var locked = ledgerView || (currentInvoiceId > 0 && !!invoiceIsPosted) || !!invoiceEinvQr;
    var custHidden = document.getElementById('inv_customer');
    var custId = custHidden ? parseInt(custHidden.value, 10) : 0;
    var reps = getCustomerSalesReps(custId > 0 ? customersById[custId] : null);
    if (reps.length > 1) {
      sel.disabled = locked;
    } else {
      sel.disabled = true;
    }
  }

  function getSalesRepDisplayName() {
    var sel = document.getElementById('inv_sales_rep');
    if (!sel || !sel.value) return '';
    var opt = sel.options[sel.selectedIndex];
    return opt ? String(opt.textContent || '').trim() : '';
  }

  function applyCustomerSalesRepFromRecord(c, preferredRepId) {
    var sel = document.getElementById('inv_sales_rep');
    if (!sel) return;
    var reps = getCustomerSalesReps(c);
    sel.innerHTML = '';
    if (reps.length === 0) {
      sel.appendChild(salesRepOptionEl('', '— بدون مندوب —'));
      sel.value = '';
      sel.disabled = true;
      return;
    }
    if (reps.length === 1) {
      var only = reps[0];
      sel.appendChild(salesRepOptionEl(String(only.id), only.name_ar || '—'));
      sel.value = String(only.id);
      sel.disabled = true;
      return;
    }
    sel.appendChild(salesRepOptionEl('', '— اختر المندوب —'));
    reps.forEach(function (r) {
      var id = String(r.id);
      var name = (r.name_ar || '').trim() || 'مندوب #' + id;
      sel.appendChild(salesRepOptionEl(id, name));
    });
    var pick = preferredRepId ? String(preferredRepId) : '';
    if (pick && reps.some(function (r) {
      return String(r.id) === pick;
    })) {
      sel.value = pick;
    } else {
      sel.value = '';
    }
    syncSalesRepSelectLock();
  }

  function applyCustomerSalesRep() {
    var hidden = document.getElementById('inv_customer');
    var id = hidden ? parseInt(hidden.value, 10) : 0;
    applyCustomerSalesRepFromRecord(id > 0 ? customersById[id] : null);
  }

  function setCustomerById(id, silent) {
    if (customerPickerApi) {
      customerPickerApi.setById(id, silent);
    }
    applyCustomerSalesRepFromRecord(customersById[parseInt(id, 10) || 0] || null);
    if (!silent) markFormDirty();
  }

  function getCustomerDisplayName() {
    if (customerPickerApi) return customerPickerApi.getName();
    return global.CustomerPickerModal ? CustomerPickerModal.getLabel('inv_customer') : '';
  }

  function initCustomerPicker() {
    loadCustomersCatalog();
    if (!global.CustomerPickerModal) {
      setTimeout(initCustomerPicker, 40);
      return;
    }
    if (customerPickerApi) return;
    customerPickerApi = CustomerPickerModal.bind({
      hidden: 'inv_customer',
      open: 'inv_customer_open',
      display: 'inv_customer_display',
      jsonId: 'sales-inv-customers-json',
      customers: customersList,
      getDisabled: function () {
        return invoiceIsPosted;
      },
      onSelect: function (c) {
        applyCustomerSalesRepFromRecord(c);
        if (!suppressDirtyMark && !invoiceIsPosted) markFormDirty();
      },
    });
  }

  /** حقول البحث بالرقم لا تُعتبر تعديلاً على المستند. */
  function isSearchOnlyField(el) {
    if (!el || !el.id) return false;
    return el.id === 'inv_no';
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
    rec.value = currentInvoiceId > 0 ? String(currentInvoiceId) : '';
  }

  /** رقم الفاتورة يظهر فقط بعد الحفظ (عند وجود سجل محفوظ). */
  function syncInvoiceNoDisplay(invoiceNo) {
    var invNo = document.getElementById('inv_no');
    if (!invNo) return;
    if (currentInvoiceId > 0) {
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
    var locked = ledgerView || (currentInvoiceId > 0 && !!invoiceIsPosted) || !!invoiceEinvQr;
    isSavedMode = locked;
    form.classList.toggle('sales-inv-form-is-posted', locked && !ledgerView);
    form.classList.toggle('sales-inv-form-is-ledger-view', ledgerView);
    form.classList.toggle('sales-inv-form-is-locked', locked);
    var fields = form.querySelectorAll(
      '#inv_date, #inv_payment_type, #inv_sales_rep, #inv_wh, #inv_notes'
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
    if (custPickerOpen) {
      custPickerOpen.disabled = locked;
      custPickerOpen.classList.toggle('is-disabled', locked);
    }
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var pick = tr.querySelector('.js-pick-open');
      var rm = tr.querySelector('.js-remove');
      tr.querySelectorAll('.js-qty, .js-qty-extra, .js-price, .js-line-sub, .js-discount, .js-tax, .js-barcode-inp').forEach(function (inp) {
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
    });
    if (!locked) ensureEntryRow();
    syncSalesRepSelectLock();
  }

  function buildDraftSnapshot() {
    syncJson();
    var invDate = document.getElementById('inv_date');
    var cust = document.getElementById('inv_customer');
    var pay = document.getElementById('inv_payment_type');
    var wh = document.getElementById('inv_wh');
    var notes = document.getElementById('inv_notes');
    var invNo = document.getElementById('inv_no');
    var repHidden = document.getElementById('inv_sales_rep');
    return {
      v: 1,
      currentInvoiceId: currentInvoiceId,
      invoiceIsPosted: invoiceIsPosted,
      invoice_no: invNo ? invNo.value : '',
      invoice_date: invDate ? invDate.value : '',
      customer_id: cust ? cust.value : '',
      sales_rep_id: repHidden ? repHidden.value : '',
      payment_type: pay ? pay.value : 'credit',
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
    if (invoiceIsPosted) {
      clearPersistedDraft();
      return;
    }
    if (!hasDraftContent() && currentInvoiceId < 1) {
      clearPersistedDraft();
      return;
    }
    try {
      sessionStorage.setItem('manager:inv_draft:' + draftKey, JSON.stringify(buildDraftSnapshot()));
    } catch (e) {}
  }

  function schedulePersistDraft() {
    if (suppressDirtyMark > 0 || invoiceIsPosted) return;
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
      if (draft.customer_id !== undefined) {
        setCustomerById(draft.customer_id, true);
      }
      if (draft.sales_rep_id !== undefined) {
        var draftCust = customersById[parseInt(draft.customer_id, 10) || 0];
        if (draftCust) {
          applyCustomerSalesRepFromRecord(draftCust, draft.sales_rep_id);
        } else {
          var repSelDraft = document.getElementById('inv_sales_rep');
          if (repSelDraft) repSelDraft.value = String(draft.sales_rep_id);
        }
      }
      var wh = document.getElementById('inv_wh');
      if (wh && draft.warehouse_id !== undefined) {
        wh.value = draft.warehouse_id ? String(draft.warehouse_id) : '';
        if (!wh.value) applyDefaultWarehouse();
      }
      var notes = document.getElementById('inv_notes');
      if (notes) notes.value = draft.notes || '';
      currentInvoiceId = parseInt(draft.currentInvoiceId, 10) || 0;
      invoiceIsPosted = currentInvoiceId > 0 && !!draft.invoiceIsPosted;
      syncInvoiceNoDisplay(
        currentInvoiceId > 0 ? draft.invoice_no || '' : ''
      );
      syncInvoiceIdField();
      (draft.lines || []).forEach(function (ln) {
        addLineFromData(ln);
      });
      if (currentInvoiceId < 1) ensureEntryRow();
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
      var draftId = parseInt(draft.currentInvoiceId, 10) || 0;
      if (initialInvoiceId > 0) {
        if (draftId !== initialInvoiceId) return false;
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
      console.error('sales-invoice: ensureEntryRow failed', e);
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
    if (!formDirty || invoiceIsPosted) {
      if (onProceed) onProceed();
      return;
    }

    var isOraUi = document.body && document.body.classList.contains('hr-ora-ui');
    if (isOraUi && global.AppDialog && typeof global.AppDialog.confirmSaveDiscard === 'function') {
      global.AppDialog.confirmSaveDiscard('هل تريد حفظ التغييرات قبل مغادرة الشاشة؟', {
        title: 'حفظ التغييرات',
        saveText: 'نعم، احفظ',
        discardText: 'لا، بدون حفظ',
        cancelText: 'إلغاء',
        theme: 'oracle',
      }).then(function (choice) {
        if (choice === 'save') {
          trySave(function () {
            if (onProceed) onProceed();
          });
        } else if (choice === 'discard') {
          discardChangesAndProceed(onProceed);
        } else if (onCancel) {
          onCancel();
        }
      });
      return;
    }

    AppDialog.confirm('هل تريد حفظ التغييرات؟', {
      title: 'تغييرات غير محفوظة',
      okText: 'نعم، احفظ',
      cancelText: 'لا، اخرج بدون حفظ',
    }).then(function (saveFirst) {
      if (saveFirst) {
        trySave(function () {
          if (onProceed) onProceed();
        });
      } else {
        discardChangesAndProceed(onProceed);
      }
    });
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
          AppDialog.success('تم حفظ الفاتورة بنجاح.');
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
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var companyName = form.getAttribute('data-company-name') || '';
  var companyLogoUrl = form.getAttribute('data-company-logo') || '';

  function buildRecipientSignatureBlock() {
    if (window.DocumentHeader && typeof window.DocumentHeader.buildRecipientSignature === 'function') {
      return window.DocumentHeader.buildRecipientSignature();
    }
    return (
      '<div class="doc-print-signature-block">' +
      '<span class="doc-print-signature-label">توقيع المستلم</span>' +
      '<span class="doc-print-signature-line" aria-hidden="true"></span>' +
      '</div>'
    );
  }

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
  form.addEventListener('change', function (e) {
    if (!e.target || !isSearchOnlyField(e.target)) {
      markFormDirty();
    }
  });

  form.addEventListener(
    'click',
    function (e) {
      var pickBtn = e.target.closest('.js-pick-open');
      if (pickBtn) {
        e.preventDefault();
        e.stopPropagation();
        var trPick = pickBtn.closest('tr[data-line-id]');
        if (trPick) openPickerForRow(trPick);
        return;
      }
      var itemCell = e.target.closest('.sales-inv-item-cell');
      if (itemCell) {
        var trCell = itemCell.closest('tr[data-line-id]');
        if (trCell && !e.target.closest('.js-pick-open')) {
          e.preventDefault();
          e.stopPropagation();
          openPickerForRow(trCell);
        }
      }
    },
    true
  );

  function fmtAmount(n) {
    return global.AppFormat && AppFormat.fmtInvoiceAmount
      ? AppFormat.fmtInvoiceAmount(n)
      : String(n);
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

  function roundMoney(x) {
    return global.AppFormat && AppFormat.roundInvoiceAmount
      ? AppFormat.roundInvoiceAmount(x)
      : Number(x);
  }

  function roundUnitPrice(x) {
    return global.AppFormat && AppFormat.roundInvoiceUnitPrice
      ? AppFormat.roundInvoiceUnitPrice(x)
      : roundMoney(x);
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
    recalcFooter();
    syncJson();
  }

  window.addEventListener('app:decimal-places', function () {
    if (invoiceIsPosted && currentInvoiceId > 0) {
      return;
    }
    applyDecimalPlacesToInvoiceScreen();
  });

  window.addEventListener('app:invoice-unit-price-decimals', function () {
    if (invoiceIsPosted && currentInvoiceId > 0) {
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
  ];

  function formatAmountValue(n, rawStr) {
    if (global.AppFormat && AppFormat.formatInvoiceDecimalInput) {
      return AppFormat.formatInvoiceDecimalInput(n, rawStr);
    }
    return String(n);
  }

  function formatUnitPriceValue(n, rawStr) {
    if (global.AppFormat && AppFormat.formatInvoiceUnitPriceInput) {
      return AppFormat.formatInvoiceUnitPriceInput(n, rawStr);
    }
    return formatAmountValue(n, rawStr);
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
    var txt = Math.abs(gross) < 1e-12 ? '' : fmtAmount(gross);
    if (el.tagName === 'INPUT') {
      el.value = formatAmountValue(gross, '');
    } else {
      setAmtDisplayCell(el, txt, true);
    }
  }

  function rowAmountSource(tr) {
    var d = tr.dataset.amountDriver || 'unit';
    if (d === 'gross') return 'subtotal';
    if (d === 'subtotal') return 'subtotal';
    return 'unit';
  }

  /** إعادة حساب السطر والفاتورة حسب الحقل الذي يُحرَّر (أثناء الكتابة). */
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
    } else if (el.classList.contains('js-price') || el.classList.contains('js-qty')) {
      tr.dataset.amountDriver = 'unit';
      recalcRow(tr, 'unit');
    } else if (el.classList.contains('js-tax')) {
      recalcRow(tr, 'unit');
    } else {
      recalcRow(tr, 'unit');
    }
    recalcFooter();
    syncJson();
  }

  /** عند Enter أو blur: تطبيع الحقل ثم إعادة الحساب. */
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
      el.classList.contains('js-line-sub')
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
    var fromUnitGross = roundMoney(base + roundMoney((base * rate) / 100));
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
      return el && !el.disabled && el.offsetParent !== null;
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
      var newRowIdx = rowIdx + dRow;
      while (newRowIdx >= 0 && newRowIdx < rows.length) {
        var targetRow = rows[newRowIdx];
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
        dEl.value = amt > 0.0000001 ? formatAmountValue(amt, '') : '';
      }
    });
    recalcAllItemRows();
    recalcFooter();
    syncJson();
  }

  /** تطبيق خصم الفاتورة وتحديث البنود قبل الطباعة أو المعاينة. */
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

  function recalcRow(tr, source, opts) {
    opts = opts || {};
    source = source || rowAmountSource(tr);
    if (source === 'gross') source = 'subtotal';
    var qtyEl = tr.querySelector('.js-qty');
    var priceEl = tr.querySelector('.js-price');
    var subInp = tr.querySelector('input.js-line-sub');
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
    price = parseNum(priceEl ? priceEl.value : 0);
    if (opts.normalizeStored) price = roundUnitPrice(price);
    lineBase = qty > 0 ? roundMoney(qty * price) : 0;
    tr.dataset.lineBase = String(lineBase);
    tr.dataset.lineMerch = String(lineBase);

    if (source === 'subtotal' && subInp) {
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
      gross = roundMoney(sub * taxFactor);
      taxAmt = roundMoney(gross - sub);
      tr.dataset.amountDriver = 'subtotal';
    } else {
      discountAmt = getLineDiscountAmount(tr, lineBase);
      sub = roundMoney(Math.max(0, lineBase - discountAmt));
      taxAmt = roundMoney((sub * rate) / 100);
      gross = roundMoney(sub + taxAmt);
      if (priceEl && (opts.normalizeStored || document.activeElement !== priceEl)) {
        priceEl.value = formatUnitPriceValue(price, String(price));
      }
      if (subInp && document.activeElement !== subInp) {
        subInp.value = formatAmountValue(sub, subInp.value);
      }
      tr.dataset.amountDriver = 'unit';
    }
    setAmtDisplayCell(
      taxAmtEl,
      Math.abs(taxAmt) < 1e-12 ? '' : fmtAmount(taxAmt),
      false
    );
    setLineGrossDisplay(tr, gross);
    tr.dataset.disc = String(discountAmt);
    tr.dataset.sub = String(sub);
    tr.dataset.tax = String(taxAmt);
    tr.dataset.gross = String(gross);
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
      lines.push({
        item_id: itemId,
        name_ar: tr.dataset.nameAr || '',
        barcode: tr.dataset.itemBarcode || '',
        sku: tr.dataset.itemSku || '',
        material_number: tr.dataset.materialNumber || '',
        qty: parseNum(qtyEl ? qtyEl.value : 0),
        qty_extra: parseNum(qtyExtraEl ? qtyExtraEl.value : 0),
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

  function invoiceGrandTotalFromFooter() {
    var el = document.getElementById('sales-inv-sum-grand');
    return el ? parseNum(el.textContent) : 0;
  }

  function validateInvoiceBeforePost() {
    recalcAllItemRows();
    recalcFooter();
    syncJson();
    if (invoiceGrandTotalFromFooter() <= 0) {
      AppDialog.alert(
        'لا يمكن الترحيل لأن إجمالي الفاتورة صفر.\n\n' +
          'تأكد من إدخال الكمية وسعر الوحدة لكل بند. إذا سحبت سند تسليم قد يكون السعر فارغاً — أدخل السعر ثم احفظ الفاتورة ثم رحّلها.',
        { type: 'warning', title: 'إجمالي غير صالح' }
      );
      return false;
    }
    return true;
  }

  function validateInvoiceBeforeSave(opts) {
    opts = opts || {};
    var cust = document.getElementById('inv_customer');
    if (!cust || !cust.value) {
      AppDialog.alert('اختر العميل قبل الحفظ.', { type: 'warning' });
      if (custPickerOpen && !custPickerOpen.disabled) custPickerOpen.focus();
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
      AppDialog.alert('أدخل تاريخ الفاتورة.', { type: 'warning' });
      invDate.focus();
      return false;
    }
    if (invDate && global.AppFormat && AppFormat.parseDateToIso) {
      if (!AppFormat.parseDateToIso(invDate.value)) {
        AppDialog.alert('تاريخ الفاتورة غير صالح. استخدم يوم-شهر-سنة (مثل 16-05-2026).', {
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
    var repSel = document.getElementById('inv_sales_rep');
    if (repSel && !repSel.disabled && !repSel.value) {
      AppDialog.alert('اختر مندوب المبيعات لهذا العميل.', { type: 'warning' });
      repSel.focus();
      return false;
    }
    return true;
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

  function setSaveBusy(busy) {
    var saveBtn = document.querySelector('#master-toolbar [data-master-action="save"]');
    if (saveBtn) saveBtn.disabled = !!busy;
  }

  var pendingAfterSave = null;

  function finishSaveFromJson(data, leaveAfterSave, onDone) {
    formSubmitting = false;
    setSaveBusy(false);
    clearPersistedDraft();
    clearFormDirty();

    var savedId = parseInt(data.invoice_id, 10) || 0;
    if (savedId > 0) {
      currentInvoiceId = savedId;
      var recId = document.getElementById('inv_record_id');
      if (recId) recId.value = String(savedId);
      syncInvoiceIdField();
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
    fd.set('_action', 'save_invoice');
    if (linesJson) fd.set('lines_json', linesJson.value);
    if (linkedDeliveryId > 0) {
      fd.set('delivery_id', String(linkedDeliveryId));
    } else {
      fd.set('delivery_id', '');
    }
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
        return res.text().then(function (text) {
          var data = null;
          if (text) {
            try {
              data = JSON.parse(text);
            } catch (parseErr) {
              console.error('sales-invoice save: invalid JSON', parseErr, text.slice(0, 500));
            }
          }
          if (data && typeof data.ok === 'boolean') {
            if (!data.ok) {
              formSubmitting = false;
              setSaveBusy(false);
              var msg = data.message || 'تعذر حفظ الفاتورة.';
              if (global.AppDialog) AppDialog.error(msg);
              else alert(msg);
              return;
            }
            finishSaveFromJson(data, leaveAfterSave, onDone);
            return;
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
          var fallback =
            'تعذر حفظ الفاتورة.' +
            (res.status ? ' (HTTP ' + res.status + ')' : '') +
            (text && text.indexOf('Fatal error') >= 0
              ? '\n\nخطأ من الخادم — راجع سجل PHP أو حدّث الصفحة.'
              : '');
          if (global.AppDialog) AppDialog.error(fallback);
          else alert(fallback);
        });
      })
      .catch(function (err) {
        console.error('sales-invoice save', err);
        formSubmitting = false;
        setSaveBusy(false);
        if (global.AppDialog) {
          AppDialog.error('تعذر حفظ الفاتورة. تحقق من الاتصال وحاول مرة أخرى.');
        } else {
          alert('تعذر حفظ الفاتورة.');
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

  function resolveLineItemCodes(source) {
    var o = source || {};
    var barcode = String(o.barcode == null ? '' : o.barcode).trim();
    var sku = String((o.sku == null ? '' : o.sku) || (o.code == null ? '' : o.code)).trim();
    var material = String(o.material_number == null ? '' : o.material_number).trim();
    var itemId = parseInt(o.item_id != null ? o.item_id : o.id, 10) || 0;

    if (!barcode && !sku && material) {
      barcode = material;
    }

    if (!barcode && !sku && itemId > 0 && global.ItemPickerModal && typeof ItemPickerModal.getCachedItem === 'function') {
      var cached = ItemPickerModal.getCachedItem(itemId);
      if (cached) {
        barcode = String(cached.barcode || '').trim();
        sku = String(cached.sku || '').trim();
      }
    }

    return { barcode: barcode, sku: sku, material_number: material, item_id: itemId };
  }

  function setRowItemDisplay(tr, name, barcode, sku) {
    var nameEl = tr.querySelector('.js-name');
    if (nameEl) {
      nameEl.textContent = name || 'اضغط لاختيار المادة';
      nameEl.classList.toggle('is-placeholder', !name);
    }
    var codes = resolveLineItemCodes({
      barcode: barcode,
      sku: sku,
      material_number: tr.dataset.materialNumber || '',
      item_id: tr.dataset.itemId || 0,
    });
    var materialNo = codes.material_number || itemMaterialNumber(codes.barcode, codes.sku);
    var skuEl = tr.querySelector('.js-sku');
    if (skuEl) {
      skuEl.textContent = materialNo;
    }
    if (materialNo) {
      tr.dataset.materialNumber = materialNo;
    } else {
      delete tr.dataset.materialNumber;
    }
    if (codes.barcode) tr.dataset.itemBarcode = codes.barcode;
    else delete tr.dataset.itemBarcode;
    if (codes.sku) tr.dataset.itemSku = codes.sku;
    else delete tr.dataset.itemSku;
    var barcodeInp = tr.querySelector('.js-barcode-inp');
    if (barcodeInp) {
      barcodeInp.value = String(codes.barcode || '').trim();
    } else {
      var barcodeEl = tr.querySelector('.js-barcode');
      if (barcodeEl) {
        barcodeEl.textContent = materialNo;
      }
    }
  }

  function normalizePickerItem(item) {
    if (!item) return null;
    var id = parseInt(item.id != null ? item.id : item.item_id, 10);
    if (!id) return null;
    var barcode = String(item.barcode || item.material_number || '').trim();
    var sku = String(item.sku || item.code || '').trim();
    return {
      id: id,
      name_ar: item.name_ar || item.name || '',
      barcode: barcode,
      sku: sku,
      material_number: String(item.material_number || itemMaterialNumber(barcode, sku)).trim(),
      default_sale:
        item.default_sale != null
          ? item.default_sale
          : item.default_cost != null
            ? item.default_cost
            : 0,
      default_cost: item.default_cost,
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
      var qty = focusTr.querySelector('.js-qty');
      if (qty) {
        qty.focus();
        if (qty.select) qty.select();
      }
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
      delete tr.dataset.rowBound;
    } else {
      var seed = getEntryRow();
      if (!seed) {
        throw new Error('قالب سطر الفاتورة غير موجود');
      }
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
    tr.querySelector('.js-price').value = '';
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

  /** إدراج سطر بيانات قبل صف الإدخال (التسلسل 1، 2، 3… من أعلى الجدول). */
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
    if (normalized.material_number) {
      tr.dataset.materialNumber = normalized.material_number;
    }
    setRowItemDisplay(tr, normalized.name_ar || '', normalized.barcode, normalized.sku);
    qtyEl.value = '';
    var salePrice =
      normalized.default_sale != null ? parseNum(normalized.default_sale) : 0;
    priceEl.value = formatPriceValue(salePrice, salePrice > 0 ? String(salePrice) : '');
    applyDefaultTax(tr);
    recalcRow(tr);
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
    if (focusTr) {
      var qty = focusTr.querySelector('.js-qty');
      if (qty) {
        qty.focus();
        if (qty.select) qty.select();
      }
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
    if (invoiceIsPosted) return;
    if (global.CustomerPickerModal) {
      CustomerPickerModal.close();
    }
    if (!tr) return;
    if (!global.ItemPickerModal) {
      if (global.AppDialog) {
        AppDialog.alert('نافذة اختيار المواد غير متوفرة في الصفحة.', { type: 'warning' });
      }
      return;
    }
    if (!apiUrl) {
      AppDialog.alert('تعذر تحميل قائمة المواد: رابط البحث غير مضبوط.', { type: 'warning' });
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
    var order = ['.js-qty', '.js-qty-extra', '.js-price', '.js-discount', '.js-line-sub', '.js-tax'];
    var idx = -1;
    if (current.classList.contains('js-qty')) idx = 0;
    else if (current.classList.contains('js-qty-extra')) idx = 1;
    else if (current.classList.contains('js-price')) idx = 2;
    else if (current.classList.contains('js-discount')) idx = 3;
    else if (current.classList.contains('js-line-sub')) idx = 4;
    else if (current.classList.contains('js-tax')) idx = 5;
    if (idx >= 0 && idx < order.length - 1) {
      var next = tr.querySelector(order[idx + 1]);
      if (next) {
        next.focus();
        if (next.select) next.select();
      }
      return;
    }
    completeLineAndNext(tr);
  }

  function completeLineAndNext(tr) {
    var itemId = parseInt(tr.dataset.itemId, 10);
    if (!itemId) {
      openPickerForRow(tr);
      return;
    }
    recalcRow(tr, rowAmountSource(tr), { normalizeStored: true });
    recalcFooter();
    syncJson();

    if (tr.classList.contains('is-entry-row')) {
      finalizeEntryRow(tr);
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
    }

    applyQtyPriceInputAttrs(tr);

    var barcodeInp = tr.querySelector('.js-barcode-inp');
    if (barcodeInp) {
      barcodeInp.addEventListener('keydown', function (e) {
        if (handleTableArrowKey(e, tr, barcodeInp)) return;
      });
    }

    tr
      .querySelectorAll(
        '.js-qty, .js-qty-extra, .js-price, .js-discount, .js-line-sub, .js-tax'
      )
      .forEach(function (el) {
      el.addEventListener('input', function () {
        recalcRowLiveFromField(tr, el);
      });
      el.addEventListener('change', function () {
        if (el.classList.contains('js-qty')) normalizeQtyInput(el);
        if (el.classList.contains('js-qty-extra')) normalizeQtyExtraInput(el);
        if (el.classList.contains('js-price')) normalizePriceInput(el);
        if (el.classList.contains('js-line-sub')) normalizeSubInput(el);
        recalcRowLiveFromField(tr, el);
      });
      el.addEventListener('blur', function () {
        if (
          el.classList.contains('js-qty') ||
          el.classList.contains('js-price') ||
          el.classList.contains('js-discount') ||
          el.classList.contains('js-line-sub')
        ) {
          commitAmountFieldAndRecalc(tr, el);
        } else if (el.classList.contains('js-qty-extra')) {
          normalizeQtyExtraInput(el);
          syncJson();
        }
      });
      el.addEventListener('keydown', function (e) {
        if (handleTableArrowKey(e, tr, el)) return;
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (isAmountFieldEnterCommit(el)) {
          commitAmountFieldAndRecalc(tr, el);
          focusNextField(tr, el);
        } else if (el.classList.contains('js-tax')) {
          completeLineAndNext(tr);
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
    if (ledgerView || invoiceIsPosted) return;
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
    var cust = document.getElementById('inv_customer');
    if (cust && cust.value !== '') return true;
    return false;
  }

  function focusInvoiceNoField() {
    var invNo = document.getElementById('inv_no');
    if (!invNo) return;
    setTimeout(function () {
      invNo.focus();
      if (invNo.select) invNo.select();
    }, 80);
  }

  function syncDeliveryLinkHint(deliveryNo) {
    var el = document.getElementById('inv_delivery_link_hint');
    var unlinkBtn = document.getElementById('inv_unlink_delivery_btn');
    var delField = document.getElementById('inv_delivery_id');
    if (delField) {
      delField.value = linkedDeliveryId > 0 ? String(linkedDeliveryId) : '';
    }
    if (linkedDeliveryId > 0) {
      var hintText =
        'مرتبط بسند تسليم' +
        (deliveryNo ? ' «' + deliveryNo + '»' : '') +
        ' — المخزون مُخصَم من السند.';
      if (invoiceEinvQr) {
        hintText += ' لإزالة الربط الخاطئ اضغط زر «فك ربط السند» أعلى الشاشة (بجانب سحب سند تسليم).';
      }
      if (el) {
        el.hidden = false;
        el.textContent = hintText;
      }
      if (unlinkBtn) unlinkBtn.hidden = false;
    } else {
      if (el) {
        el.hidden = true;
        el.textContent = '';
      }
      if (unlinkBtn) unlinkBtn.hidden = true;
    }
  }

  function unlinkDeliveryFromInvoice() {
    if (!unlinkDeliveryUrl || currentInvoiceId < 1 || linkedDeliveryId < 1) return;
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm(
      'فك ربط هذه الفاتورة عن سند التسليم؟\nلن يُلغى ترحيل الفاتورة ولا يتأثر إرسال الضريبة.',
      { title: 'فك ربط السند' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('invoice_id', String(currentInvoiceId));
      fetch(unlinkDeliveryUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            AppDialog.error((data && data.error) || 'تعذر فك الربط.');
            return;
          }
          linkedDeliveryId = 0;
          syncDeliveryLinkHint('');
          AppDialog.success(data.message || 'تم فك الربط.');
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function resetInvoiceForm() {
    runWithoutDirtyMark(function () {
      form.reset();
      linkedDeliveryId = 0;
      syncDeliveryLinkHint('');
      var invDate = document.getElementById('inv_date');
      if (invDate && defaultDate) invDate.value = defaultDate;
      var paySel = document.getElementById('inv_payment_type');
      if (paySel) paySel.value = 'credit';
      applyDefaultWarehouse();
      setCustomerById(0, true);
      syncInvoiceNoDisplay('');
      tbody.innerHTML = '';
      closePicker();
      ensureEntryRow();
      renumberRows();
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
    var cust = getCustomerDisplayName();
    var paySel = document.getElementById('inv_payment_type');
    var payLabel = paySel && paySel.value === 'credit' ? 'ذمم' : 'نقدي';
    var repName = getSalesRepDisplayName();
    var repLine = '';
    if (repName && repName.indexOf('بدون') === -1 && repName.indexOf('اختر') === -1) {
      repLine =
        '<tr><td style="padding:0.2rem 0;direction:rtl;unicode-bidi:isolate;"><strong>المندوب:\u200F</strong> <bdi>' +
        escapeHtml(repName) +
        '</bdi></td></tr>';
    }
    var invNoEl = document.getElementById('inv_no');
    var invNo = invNoEl && invNoEl.value ? invNoEl.value : '—';
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
    var subT = sub ? sub.textContent : '0';
    var taxT = tax ? tax.textContent : '0';
    var grandT = grand ? grand.textContent : '0';
    var discT = disc ? disc.textContent : '0';

    var ipp = window.InvInvoicePrint;
    var layout = ipp ? ipp.getLayout(tbody) : { showQtyExtra: false, showDiscount: false };
    var lineCols = ipp ? ipp.lineColCount(layout) : 10;
    var rowHtml = '';
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      var itemId = parseInt(tr.dataset.itemId, 10);
      if (!itemId) return;
      if (ipp) {
        rowHtml += ipp.buildLineRow(tr, layout, {
          escapeHtml: escapeHtml,
          getBarcodeFromRow: getBarcodeFromRow,
          getLineSubDisplay: getLineSubDisplay,
          getLineGrossDisplay: getLineGrossDisplay,
          fmtAmount: fmtAmount,
        });
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
            showDiscountTotal: showDiscTotal,
            discTotalText: discT,
            subTotalText: subT,
            taxTotalText: taxT,
            grandTotalText: grandT,
          })
        : '';

    var metaRows = [
      { label: 'رقم الفاتورة', value: invNo },
      { label: 'التاريخ', value: date },
      { label: 'العميل', value: cust },
      { label: 'النوع', value: payLabel },
    ];
    var metaTable =
      window.DocumentHeader && typeof window.DocumentHeader.buildMetaTable === 'function'
        ? window.DocumentHeader.buildMetaTable(metaRows)
        : '<table><tr><td style="padding:0.2rem 0;"><strong>رقم الفاتورة:</strong> ' +
          escapeHtml(invNo) +
          '</td></tr></table>';
    if (repLine || whLine) {
      metaTable = metaTable.replace('</table>', repLine + whLine + '</table>');
    }

    var einvBox = buildEinvoiceQrBox();
    var headerBlock = einvBox
      ? '<table class="inv-print-header-row" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0.3rem 0 0.6rem;direction:rtl;table-layout:fixed;">' +
          '<tr>' +
          '<td class="inv-print-header-meta" style="border:none;padding:0;vertical-align:top;">' + metaTable + '</td>' +
          '<td class="inv-print-header-qr" style="border:none;padding:0;vertical-align:top;width:110px;text-align:center;">' + einvBox + '</td>' +
        '</tr></table>'
      : metaTable;

    var inner =
      buildDocPrintHeader('فاتورة مبيعات') +
      headerBlock +
      '<table class="inv-print-lines"><thead><tr>' +
      (ipp ? ipp.theadRow(layout) : '') +
      '</tr></thead><tbody>' +
      rowHtml +
      '</tbody></table>' +
      printTotals +
      notesBlock +
      buildRecipientSignatureBlock();
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
    '.inv-print-header-row{width:100%;border-collapse:collapse;margin:0.3rem 0 0.6rem;direction:rtl;}' +
    '.inv-print-header-row td{border:none!important;padding:0!important;vertical-align:top;}' +
    '.inv-print-header-row td.inv-print-header-meta{width:auto;}' +
    '.inv-print-header-row td.inv-print-header-qr{width:96px;padding-inline-start:8px!important;text-align:center;}' +
    '.inv-print-qr-wrap{width:96px;text-align:center;margin-inline-start:auto;}' +
    '.inv-print-qr-box{border:2px solid #0f172a;border-radius:10px;padding:4px;background:#fff;width:96px;height:96px;box-sizing:border-box;text-align:center;}' +
    '.inv-print-qr-img{display:inline-block;width:84px;height:84px;vertical-align:middle;}' +
    '.inv-print-qr-placeholder{display:inline-block;width:84px;height:84px;background:#f1f5f9;border-radius:6px;vertical-align:middle;}' +
    '.inv-print-qr-caption{font-size:0.62rem;color:#94a3b8;margin-top:3px;letter-spacing:0.3px;font-weight:500;}' +
    '.sales-inv-print-tot{margin-top:0.75rem;text-align:left;max-width:280px;margin-right:0;margin-left:auto;}' +
    '.sales-inv-print-tot div{display:flex;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid #e2e8f0;font-weight:700;}' +
    '.sales-inv-print-tot .g{font-weight:800;font-size:1.05rem;border-top:2px solid #334155;margin-top:0.35rem;padding-top:0.45rem;}'
    );
  }

  function buildEinvoiceQrBox() {
    if (!einvQrDataUrl && !invoiceEinvQr) return '';
    var wrapStyle = 'width:96px;text-align:center;margin:0;';
    var boxStyle = 'border:2px solid #0f172a;border-radius:10px;padding:4px;background:#fff;width:96px;height:96px;box-sizing:border-box;text-align:center;line-height:0;display:block;';
    var imgStyle = 'width:84px;height:84px;display:inline-block;vertical-align:middle;';
    var capStyle = 'font-size:9px;color:#94a3b8;margin-top:3px;letter-spacing:0.3px;font-weight:500;line-height:1.2;';
    var html =
      '<div class="inv-print-qr-wrap" style="' + wrapStyle + '">' +
        '<div class="inv-print-qr-box" style="' + boxStyle + '">' +
          (einvQrDataUrl
            ? '<img src="' + einvQrDataUrl + '" alt="QR" class="inv-print-qr-img" style="' + imgStyle + '">'
            : '<div class="inv-print-qr-placeholder" style="width:84px;height:84px;background:#f1f5f9;display:inline-block;"></div>') +
        '</div>' +
        '<div class="inv-print-qr-caption" style="' + capStyle + '">Please Check In</div>' +
      '</div>';
    return html;
  }

  // الإبقاء على الاسم القديم لأي استدعاء خارجي.
  function buildEinvoicePrintBlock() {
    return '';
  }

  function buildStandaloneInvoiceHtml() {
    var bodyAttrs =
      window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
        ? window.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>فاتورة مبيعات</title>' +
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
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(fullHtml);
    win.document.close();
    var triggerPrint = function () {
      try {
        win.focus();
        win.print();
      } catch (e) {}
    };
    // انتظر تحميل كل الصور (خصوصاً QR من api.qrserver.com) قبل الطباعة.
    var waitForImages = function () {
      try {
        var doc = win.document;
        var imgs = doc.images ? Array.prototype.slice.call(doc.images) : [];
        var pending = imgs.filter(function (im) { return im && !im.complete; });
        if (pending.length === 0) {
          triggerPrint();
          return;
        }
        var remaining = pending.length;
        var done = function () {
          remaining--;
          if (remaining <= 0) triggerPrint();
        };
        pending.forEach(function (im) {
          im.addEventListener('load', done, { once: true });
          im.addEventListener('error', done, { once: true });
        });
        // أمان: لو لم تُحمَّل الصور خلال 4 ثوانٍ، اطبع على أي حال.
        setTimeout(triggerPrint, 4000);
      } catch (e) {
        triggerPrint();
      }
    };
    setTimeout(waitForImages, 200);
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
      preview.innerHTML = buildInvoicePrintInnerHtml();
      if (title) {
        title.textContent = forPdf
          ? 'معاينة — اختر «حفظ كـ PDF» من نافذة الطباعة'
          : 'معاينة الطباعة — اضغط «طباعة» في الشريط العلوي';
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
    var go = function () {
      printHtmlInFrame(buildStandaloneInvoiceHtml());
    };
    if (invoiceEinvQr && !einvQrDataUrl) {
      refreshEinvQrDataUrl(go);
    } else {
      go();
    }
  }

  /** معاينة من الشريط العلوي؛ إن كانت المعاينة مفتوحة يُنفَّذ الطباعة مباشرة. */
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
    return 'invoice-' + no.replace(/[^\w\u0600-\u06FF\-]+/g, '_');
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
      // نهج موثوق: نفتح preview overlay مرئياً (CSS مصمَّمة للعمل داخل overlay panel)
      // ثم نأخذ html2pdf من الـ preview body، ثم نُغلقه. المستخدم سيرى وميض المعاينة.
      var overlay = document.getElementById('sales-inv-print-overlay');
      var preview = document.getElementById('sales-inv-print-preview');
      if (!overlay || !preview) {
        AppDialog.error('عنصر المعاينة غير متاح. أعد تحميل الصفحة.');
        return;
      }
      var wasHidden = overlay.hidden;
      // اضمن أن overlay في document.body (وليس داخل element مخفي).
      if (overlay.parentNode !== document.body) {
        document.body.appendChild(overlay);
      }
      preview.innerHTML = buildInvoicePrintInnerHtml();
      // اعرض overlay مؤقتاً لكن مع تقليل ظهور المحتوى المرئي للمستخدم.
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
      // ننتظر frame واحدة لضمان أن CSS طُبِّقت بعد إظهار overlay.
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
    if (invoiceEinvQr && !einvQrDataUrl) {
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
    var go = function () {
      var csrfInp = form.querySelector('input[name="_csrf"]');
      var invNoEl = document.getElementById('inv_no');
      var docNo = invNoEl && invNoEl.value ? String(invNoEl.value).trim() : '';
      window.DocSendEmail.send({
        url: sendEmailUrl,
        docType: 'sales_invoice',
        docNo: docNo,
        fileBase: getInvoiceFileBase(),
        csrfToken: csrfInp ? csrfInp.value : '',
        buildHtml: buildInvoicePrintInnerHtml,
        overlayId: 'sales-inv-print-overlay',
        previewId: 'sales-inv-print-preview',
      });
    };
    if (invoiceEinvQr && !einvQrDataUrl) {
      refreshEinvQrDataUrl(go);
    } else {
      go();
    }
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
    if (invoiceEinvQr) {
      AppDialog.alert('لا يمكن تعديل فاتورة أُرسلت إلى نظام الفوترة.', { type: 'warning' });
      return;
    }
    if (invoiceIsPosted) {
      AppDialog.alert('لا يمكن تعديل فاتورة مرحّلة.', { type: 'warning' });
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

  function requestDeleteUnpostedInvoice(invId, invNoLabel, deleteUrl) {
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
            formSubmitting = false;
            initNewInvoice(true);
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
    if (ln.material_number) {
      tr.dataset.materialNumber = String(ln.material_number);
    }
    var codes = resolveLineItemCodes(ln);
    setRowItemDisplay(tr, tr.dataset.nameAr, codes.barcode, codes.sku);
    tr.dataset.sub = String(ln.line_subtotal != null ? ln.line_subtotal : ln.line_total || 0);
    tr.dataset.tax = String(ln.tax_amount || 0);
    tr.dataset.gross = String(ln.line_gross != null ? ln.line_gross : ln.line_total || 0);
    tr.querySelector('.js-qty').value = formatQtyValue(ln.qty);
    var qtyExtraEl = tr.querySelector('.js-qty-extra');
    if (qtyExtraEl) {
      qtyExtraEl.value = formatQtyValue(ln.qty_extra != null ? ln.qty_extra : 0);
    }
    tr.querySelector('.js-price').value = formatUnitPriceValue(
      ln.unit_price,
      ln.unit_price != null ? String(ln.unit_price) : ''
    );
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
    if (loadDriver === 'gross') loadDriver = 'subtotal';
    tr.dataset.amountDriver = loadDriver;
    recalcRow(tr, loadDriver, { normalizeStored: true });
    insertDataRowBeforeEntry(tr);
    return tr;
  }

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    updateNavButtons(browseNavPrevId, browseNavNextId);
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('inv_no_prev', 'inv_no_next', prevId, nextId, {
        onEmpty: currentInvoiceId < 1,
        prevTitle: 'الفاتورة السابقة',
        nextTitle: 'الفاتورة التالية',
        prevBeforeLatestTitle: 'الفاتورة قبل الأخيرة',
        latestTitle: 'آخر فاتورة بيع',
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

  /** تفعيل أسهم التنقل عند فاتورة جديدة فارغة (بعد الحذف أو فتح الشاشة). */
  function refreshEmptyBrowseNav() {
    if (!apiInvoiceUrl) {
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
    var base = newInvoiceUrl || window.location.pathname + '?r=sales_invoices';
    var url = id > 0 ? base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id : base;
    url = appendLedgerReturnQs(url);
    window.history.replaceState({ invoiceId: id }, '', url);
  }

  function updateInvoiceNoPostedStyle() {
    var invNo = document.getElementById('inv_no');
    if (!invNo) return;
    invNo.classList.remove('is-posted', 'is-unposted');
    if (currentInvoiceId < 1) return;
    if (invoiceIsPosted) {
      invNo.classList.add('is-posted');
    } else {
      invNo.classList.add('is-unposted');
    }
  }

  function updatePostedBadge() {
    var el = document.getElementById('inv_posted_badge');
    if (currentInvoiceId < 1) {
      if (el) el.hidden = true;
      updateInvoiceNoPostedStyle();
      return;
    }
    if (el) {
      el.hidden = false;
      if (invoiceIsPosted) {
        el.textContent = 'مرحّلة';
        el.className = 'sales-inv-posted-badge badge badge-posted';
      } else {
        el.textContent = 'غير مرحّلة';
        el.className = 'sales-inv-posted-badge badge badge-warn';
      }
    }
    updateInvoiceNoPostedStyle();
    updateEinvoiceBadge();
    updateInvoiceToolbarUnpostButton();
  }

  function updateInvoiceToolbarUnpostButton() {
    var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
    if (!unpostBtn) return;
    var canUnpost = canUnpostByPermission && currentInvoiceId > 0 && invoiceIsPosted && !invoiceEinvQr;
    unpostBtn.disabled = !canUnpost;
    unpostBtn.classList.toggle('is-inactive', !canUnpost);
    if (invoiceEinvQr) {
      unpostBtn.title = 'لا يمكن فك ترحيل فاتورة أُرسلت إلى نظام الفوترة.';
    } else if (canUnpost) {
      unpostBtn.title = 'فك ترحيل الفاتورة (عكس القيود والمستودع وذمة العميل)';
    } else if (currentInvoiceId < 1) {
      unpostBtn.title = 'احفظ الفاتورة أولاً.';
    } else {
      unpostBtn.title = 'الفاتورة غير مرحّلة.';
    }
  }

  function updateEinvoiceBadge() {
    var el = document.getElementById('inv_einv_badge');
    if (!el) return;
    if (currentInvoiceId < 1) {
      el.hidden = true;
      return;
    }
    el.hidden = false;
    if (invoiceEinvQr) {
      el.textContent = 'مُرسلة للفوترة';
      el.className = 'sales-inv-posted-badge badge badge-einv-sent';
    } else if (invoiceEinvStatus) {
      el.textContent = 'فوترة: ' + invoiceEinvStatus;
      el.className = 'sales-inv-posted-badge badge badge-warn';
    } else {
      el.textContent = 'لم تُرسل للفوترة';
      el.className = 'sales-inv-posted-badge badge badge-warn';
    }
  }

  function refreshEinvQrDataUrl(cb) {
    if (!invoiceEinvQr) {
      einvQrDataUrl = '';
      if (cb) cb();
      return;
    }
    var fallbackToImage = function () {
      try {
        einvQrDataUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&data=' + encodeURIComponent(invoiceEinvQr);
      } catch (_e) {
        einvQrDataUrl = '';
      }
      if (cb) cb();
    };
    if (typeof window.QRCode === 'undefined' || !window.QRCode.toDataURL) {
      fallbackToImage();
      return;
    }
    try {
      window.QRCode.toDataURL(invoiceEinvQr, { width: 200, margin: 1, errorCorrectionLevel: 'L' }, function (err, url) {
        if (err || !url) {
          fallbackToImage();
        } else {
          einvQrDataUrl = url;
          if (cb) cb();
        }
      });
    } catch (_e) {
      fallbackToImage();
    }
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
    updateInvoiceToolbarUnpostButton();
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
    if (currentInvoiceId < 1) {
      if (errMsg) AppDialog.error(errMsg);
      return Promise.resolve(false);
    }
    return fetchInvoiceResponse({ id: currentInvoiceId }).then(function (data) {
      if (data && data.ok && data.invoice) {
        applyInvoiceData(data.invoice);
        updatePostedBadge();
        if (invoiceIsPosted) {
          var msg = 'تم ترحيل الفاتورة.';
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
    if (postDialogBusy) return;
    if (!invoicePostUrl) {
      AppDialog.alert('الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentInvoiceId < 1) {
      AppDialog.alert('احفظ الفاتورة أولًا قبل الترحيل.', { type: 'warning' });
      return;
    }
    if (invoiceIsPosted) {
      AppDialog.alert('هذه الفاتورة مرحّلة مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    postDialogBusy = true;
    AppDialog.confirm(
      'ترحيل هذه الفاتورة (صرف مخزون + حساب العميل)؟\nيُسمح بالصرف حتى لو أصبح الرصيد سالبًا؛ يُصحَّح لاحقًا عند إدخال شراء أو رصيد للمادة.',
      { title: 'ترحيل' }
    ).then(function (ok) {
      postDialogBusy = false;
      if (!ok) return;
      if (!validateInvoiceBeforePost()) return;

      function runPost() {
        var fd = new FormData();
        fd.append('_csrf', csrfInput ? csrfInput.value : '');
        fd.append('invoice_id', String(currentInvoiceId));
        if (linkedDeliveryId > 0) {
          fd.append('delivery_id', String(linkedDeliveryId));
        }
        fetch(invoicePostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(parsePostInvoiceJsonResponse)
          .then(function (res) {
            var data = res.data;
            if (!data) {
              return refreshInvoiceAfterPostAttempt('تعذر قراءة رد الخادم. جارٍ التحقق من حالة الفاتورة…');
            }
            if (!data.ok) {
              var errText = data.error || data.message || 'تعذر الترحيل.';
              return refreshInvoiceAfterPostAttempt(errText);
            }
            invoiceIsPosted = true;
            updatePostedBadge();
            var successMsg = AppDialog.formatActionMessage
              ? AppDialog.formatActionMessage(data, { fallback: 'تم الترحيل.' })
              : data.message || 'تم الترحيل.';
            AppDialog.success(successMsg).then(function () {
              if (currentInvoiceId > 0) {
                loadInvoiceById(currentInvoiceId);
              }
            });
          })
          .catch(function () {
            refreshInvoiceAfterPostAttempt('تعذر الاتصال بالخادم. جارٍ التحقق من حالة الفاتورة…');
          });
      }

      function startPost() {
        runPost();
      }

      if (formDirty) {
        trySave(startPost);
        return;
      }
      startPost();
    });
  }

  var postDialogBusy = false;

  function showEinvoiceErrorDialog(data) {
    var errMsg = (data && (data.error || data.message)) || 'تعذر الإرسال للفوترة.';
    var httpCode = data && data.http_code ? ' (HTTP ' + data.http_code + ')' : '';
    AppDialog.error(errMsg + httpCode, { title: 'فشل الإرسال للفوترة' });
    var raw = data && data.response ? String(data.response) : '';
    if (raw) {
      try {
        console.warn('[JoFotara error]', raw);
      } catch (e) {}
    }
  }

  function performEinvoiceResend() {
    if (!einvoiceSendUrl) return;
    var csrfInput = form.querySelector('[name="_csrf"]');
    var fd = new FormData();
    fd.append('_csrf', csrfInput ? csrfInput.value : '');
    fd.append('invoice_id', String(currentInvoiceId));
    fetch(einvoiceSendUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          showEinvoiceErrorDialog(data || {});
          return;
        }
        AppDialog.success(data.message || 'تم الإرسال للفوترة بنجاح بقيم مطابقة لما يَعرضه النظام.');
        if (currentInvoiceId > 0) loadInvoiceById(currentInvoiceId);
      })
      .catch(function () {
        AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  var unpostDialogBusy = false;
  var unpostInFlight = false;

  function setInvoiceBusy(busy) {
    try {
      document.dispatchEvent(
        new CustomEvent('manager:invoice-busy', { detail: { busy: !!busy } })
      );
    } catch (e) {
      /* ignore */
    }
  }

  function unpostCurrentInvoice() {
    if (!canUnpostByPermission) {
      AppDialog.alert('ليس لديك صلاحية فك ترحيل فاتورة المبيعات.', { type: 'warning' });
      return;
    }
    if (unpostDialogBusy) return;
    if (!invoiceUnpostUrl) {
      AppDialog.alert('فك الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentInvoiceId < 1) {
      AppDialog.alert('احفظ الفاتورة أولًا.', { type: 'warning' });
      return;
    }
    if (invoiceEinvQr) {
      AppDialog.alert('لا يمكن فك ترحيل فاتورة أُرسلت إلى نظام الفوترة.', { type: 'warning' });
      return;
    }
    if (!invoiceIsPosted) {
      AppDialog.alert('هذه الفاتورة غير مرحّلة.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var msg =
      '<p><strong>سيتم فك ترحيل الفاتورة:</strong></p>' +
      '<ul>' +
      '<li>إلغاء القيد المحاسبي تلقائياً (يَختفي من تقارير الأستاذ وميزان المراجعة).</li>' +
      '<li>إعادة الأرصدة المخزنية للمواد (إلغاء حركات الصرف).</li>' +
      '<li>إلغاء أثر حساب العميل (الذمم المدينة).</li>' +
      '</ul>' +
      '<p>بعد فك الترحيل يمكنك تعديل الفاتورة وإعادة ترحيلها.</p>' +
      '<p class="ui-dialog-question">متابعة؟</p>';
    unpostDialogBusy = true;
    AppDialog.confirm(msg, {
      title: 'فك الترحيل',
      okText: 'فك الترحيل',
      html: true,
    }).then(function (ok) {
      unpostDialogBusy = false;
      if (!ok) return;
      if (unpostInFlight) return;
      unpostInFlight = true;
      setInvoiceBusy(true);
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('invoice_id', String(currentInvoiceId));
      fetch(invoiceUnpostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            var errMsg = (data && (data.error || data.message)) || 'تعذر فك الترحيل.';
            AppDialog.error(errMsg);
            return;
          }
          invoiceIsPosted = false;
          updatePostedBadge();
          AppDialog.success(data.message || 'تم فك الترحيل.');
          if (currentInvoiceId > 0) {
            fetchInvoiceResponse({ id: currentInvoiceId }).then(function (invData) {
              if (invData && invData.ok && invData.invoice) {
                applyInvoiceData(invData.invoice);
              }
            });
          }
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        })
        .finally(function () {
          unpostInFlight = false;
          setInvoiceBusy(false);
        });
    });
  }

  function sendCurrentInvoiceToEinvoice() {
    if (!canSendEinvoice || !einvoiceSendUrl) {
      AppDialog.alert('ليس لديك صلاحية إرسال الفوترة.', { type: 'warning' });
      return;
    }
    if (currentInvoiceId < 1) {
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
        fd.append('invoice_id', String(currentInvoiceId));
        fetch(einvoiceSendUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) {
              showEinvoiceErrorDialog(data);
              return;
            }
            AppDialog.success(data.message || 'تمت العملية.');
            if (currentInvoiceId > 0) loadInvoiceById(currentInvoiceId);
          })
          .catch(function () {
            AppDialog.error('تعذر الاتصال بالخادم.');
          });
      }
    );
  }

  function applyInvoiceData(inv) {
    runWithoutDirtyMark(function () {
    currentInvoiceId = parseInt(inv.id, 10) || 0;
    invoiceIsPosted = !!inv.is_posted;
    var invDp = parseInt(inv.amount_decimals, 10);
    if (!isNaN(invDp) && invDp >= 0) {
      form.setAttribute('data-decimals', String(invDp));
      decimals = invDp;
    }
    applyEinvoiceFromInvoice(inv);
    var recId = document.getElementById('inv_record_id');
    if (recId) recId.value = currentInvoiceId > 0 ? String(currentInvoiceId) : '';
    syncInvoiceNoDisplay(inv.invoice_no || '');

    var invDate = document.getElementById('inv_date');
    if (invDate) {
      invDate.value = fmtDate(inv.invoice_date || '') || defaultDate;
    }

    var paySel = document.getElementById('inv_payment_type');
    if (paySel && inv.payment_type) paySel.value = inv.payment_type;

    setCustomerById(inv.customer_id || 0, true);
    setSalesRepFromInvoice(inv);

    var wh = document.getElementById('inv_wh');
    if (wh) wh.value = inv.warehouse_id ? String(inv.warehouse_id) : '';

    var notes = document.getElementById('inv_notes');
    if (notes) notes.value = inv.notes || '';

    linkedDeliveryId = parseInt(inv.delivery_id, 10) || 0;
    syncDeliveryLinkHint(inv.delivery_no || '');

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
    setBrowseNav(inv.prev_id || 0, inv.next_id || 0);
    updateHistory(currentInvoiceId);
    updatePostedBadge();
    closePicker();
    });
  }

  function fetchInvoiceResponse(query) {
    if (!apiInvoiceUrl) return Promise.resolve(null);
    var qs = Object.keys(query)
      .map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
      })
      .join('&');
    return fetch(apiInvoiceUrl + '?' + qs, { credentials: 'same-origin' })
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
          AppDialog.error((data && data.message) || 'لم يتم العثور على فاتورة بهذا الرقم.');
          return;
        }
        applyInvoiceData(data.invoice);
      });
    });
  }

  function runToolbarInvoiceSearch() {
    var invNoEl = document.getElementById('inv_no');
    var no = invNoEl ? String(invNoEl.value || '').trim() : '';
    if (!no) {
      AppDialog.alert('أدخل رقم الفاتورة في الحقل أعلاه ثم اضغط بحث.', { type: 'warning' });
      if (invNoEl) invNoEl.focus();
      return;
    }
    loadInvoiceByNo(no);
  }

  function navigateInvoice(dir) {
    confirmUnsavedChanges(function () {
      navigateInvoiceCore(dir);
    });
  }

  function navigateInvoiceCore(dir) {
    if (currentInvoiceId < 1) {
      navigateEmptyInvoice(dir);
      return;
    }
    fetchInvoiceResponse({ id: currentInvoiceId, dir: dir }).then(function (data) {
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
    currentInvoiceId = 0;
    invoiceIsPosted = false;
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
    syncInvoiceNoDisplay('');
    resetInvoiceForm();
    focusInvoiceNoField();
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
    if (action === 'print') {
      e.preventDefault();
      e.stopImmediatePropagation();
      handleToolbarPrint();
      return;
    }
    if (action === 'pdf') {
      e.preventDefault();
      e.stopImmediatePropagation();
      downloadInvoicePdf();
      return;
    }
    if (action === 'excel') {
      e.preventDefault();
      e.stopImmediatePropagation();
      downloadInvoiceExcel();
      return;
    }
    if (action === 'send_einvoice') {
      e.preventDefault();
      sendCurrentInvoiceToEinvoice();
      return;
    }
    if (action === 'unpost') {
      e.preventDefault();
      e.stopImmediatePropagation();
      unpostCurrentInvoice();
      return;
    }
    if (action === 'send_email') {
      e.preventDefault();
      sendInvoiceByEmail();
      return;
    }
    if (action === 'delete') {
      e.preventDefault();
      if (currentInvoiceId > 0) {
        var recIdEl = document.getElementById('inv_record_id');
        var invId = currentInvoiceId > 0 ? currentInvoiceId : recIdEl ? parseInt(recIdEl.value, 10) : 0;
        if (invId < 1) {
          if (newInvoiceUrl) window.location.href = newInvoiceUrl;
          else initNewInvoice();
          return;
        }
        if (invoiceEinvQr) {
          AppDialog.alert('لا يمكن حذف فاتورة أُرسلت إلى نظام الفوترة.', { type: 'warning' });
          return;
        }
        if (invoiceIsPosted) {
          AppDialog.alert(
            'لا يمكن حذف فاتورة مرحّلة.\n\nللحذف: ألغِ الترحيل أولاً، ثم احذف جميع بنود المواد واحفظ، ثم احذف الفاتورة.',
            { type: 'warning' }
          );
          return;
        }
        if (invoiceHasLines()) {
          AppDialog.alert(MSG_DELETE_INVOICE_NEEDS_EMPTY_LINES, { type: 'warning' });
          return;
        }
        var invNoEl = document.getElementById('inv_no');
        var invNoLabel = invNoEl && invNoEl.value ? invNoEl.value : String(invId);
        var deleteUrl = form.getAttribute('data-invoice-delete-url') || '';
        if (!deleteUrl) {
          AppDialog.error('حذف الفاتورة غير متاح.');
          return;
        }
        trySave(
          function () {
            requestDeleteUnpostedInvoice(invId, invNoLabel, deleteUrl);
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

  var oraCloseBtn = document.querySelector('.sales-inv-wrap .ora12-title-bar__close');
  if (oraCloseBtn) {
    oraCloseBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var href = oraCloseBtn.getAttribute('href') || exitUrl;
      confirmUnsavedChanges(function () {
        if (href) {
          window.location.href = href;
        } else {
          window.history.back();
        }
      });
    }, true);
  }

  function setSalesRepFromInvoice(inv) {
    var custId = inv.customer_id ? parseInt(inv.customer_id, 10) : 0;
    var c = custId > 0 ? customersById[custId] : null;
    if (c) {
      applyCustomerSalesRepFromRecord(c, inv.sales_rep_id);
      return;
    }
    var sel = document.getElementById('inv_sales_rep');
    if (!sel) return;
    if (inv.sales_rep_id) {
      sel.innerHTML = '';
      sel.appendChild(
        salesRepOptionEl(String(inv.sales_rep_id), (inv.sales_rep_name || '').trim() || '—')
      );
      sel.value = String(inv.sales_rep_id);
      sel.disabled = true;
    } else {
      applyCustomerSalesRepFromRecord(null);
    }
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
      if (!no) return;
      loadInvoiceByNo(no);
    });
  }

  var printCloseBtn = document.getElementById('sales-inv-print-close');
  var printOverlay = document.getElementById('sales-inv-print-overlay');
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
    loadCustomersCatalog();
    initCustomerPicker();
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

    if (initialInvoiceId > 0) {
      var restoredSaved = false;
      try {
        var rawInit = sessionStorage.getItem('manager:inv_draft:' + draftKey);
        if (rawInit) {
          var draftInit = JSON.parse(rawInit);
          if (
            draftInit &&
            draftInit.v === 1 &&
            parseInt(draftInit.currentInvoiceId, 10) === initialInvoiceId &&
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
        fetchInvoiceResponse({ id: initialInvoiceId }).then(function (data) {
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
          if (bc) {
            bc.focus();
          }
        }, 80);
      }
    });
  }

  function invoiceHasMaterialLines() {
    var has = false;
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (parseInt(tr.dataset.itemId, 10) > 0) has = true;
    });
    return has;
  }

  function closeDeliveryPickModal() {
    var modal = document.getElementById('inv-delivery-pick-modal');
    if (modal) modal.hidden = true;
  }

  function applyDeliveryToInvoice(data) {
    var d = data.delivery;
    var lines = data.lines || [];
    if (!d) return;
    runWithoutDirtyMark(function () {
      linkedDeliveryId = parseInt(d.id, 10) || 0;
      syncDeliveryLinkHint(d.delivery_no || '');
      setCustomerById(d.customer_id || 0, true);
      var wh = document.getElementById('inv_wh');
      if (wh && d.warehouse_id) wh.value = String(d.warehouse_id);
      var notes = document.getElementById('inv_notes');
      if (notes && d.notes) notes.value = d.notes;
      tbody.innerHTML = '';
      ensureEntryRow();
      lines.forEach(function (ln) {
        addLineFromData(ln);
      });
      renumberRows();
      recalcAllItemRows();
      recalcFooter();
      syncJson();
      markFormDirty();
      refreshInvoiceEditState();
    });
  }

  function linkDeliveryToInvoice(deliveryId, deliveryNo, onDone) {
    if (!linkDeliveryUrl || currentInvoiceId < 1 || deliveryId < 1) {
      if (onDone) onDone(false);
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var fd = new FormData();
    fd.append('_csrf', csrfInput ? csrfInput.value : '');
    fd.append('invoice_id', String(currentInvoiceId));
    fd.append('delivery_id', String(deliveryId));
    fetch(linkDeliveryUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          AppDialog.error((data && data.error) || 'تعذر ربط السند.');
          if (onDone) onDone(false);
          return;
        }
        linkedDeliveryId = deliveryId;
        syncDeliveryLinkHint(deliveryNo || data.delivery_no || '');
        if (onDone) onDone(true);
      })
      .catch(function () {
        AppDialog.error('تعذر الاتصال بالخادم.');
        if (onDone) onDone(false);
      });
  }

  function pickDeliveryById(deliveryId) {
    if (!deliveryPickUrl || deliveryId < 1) return;
    fetch(deliveryPickUrl + '?id=' + encodeURIComponent(String(deliveryId)), {
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          AppDialog.error((data && data.error) || 'تعذر تحميل السند.');
          return;
        }
        closeDeliveryPickModal();
        var dNo = data.delivery.delivery_no || '';
        var pickedId = parseInt(data.delivery.id, 10) || deliveryId;

        if (invoiceIsPosted && currentInvoiceId > 0) {
          linkDeliveryToInvoice(pickedId, dNo, function (ok) {
            if (ok) {
              AppDialog.success(
                'تم ربط الفاتورة بسند «' + dNo + '». حدّث الصفحة ليختفي التنبيه.'
              );
            }
          });
          return;
        }

        var pulledLines = data.lines || [];
        if (!pulledLines.length) {
          AppDialog.error('سند «' + dNo + '» لا يحتوي على مواد. أضف بنودًا للسند ثم أعد المحاولة.');
          return;
        }

        applyDeliveryToInvoice(data);

        function afterPullSaved() {
          AppDialog.success(
            'تم سحب سند «' +
              dNo +
              '» وحفظ الربط. سيختفي من قائمة السحب؛ ويختفي الإشعار بعد ترحيل الفاتورة.'
          );
        }

        if (!validateInvoiceBeforeSave({ allowEmptyLines: false })) {
          AppDialog.alert(
            'تم تعبئة بيانات السند. أكمل الحقول ثم اضغط «حفظ» لإتمام الربط.',
            { type: 'info' }
          );
          return;
        }

        trySave(afterPullSaved);
      })
      .catch(function () {
        AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  function openDeliveryPickDialog() {
    if (ledgerView) return;
    if (linkedDeliveryId > 0) {
      AppDialog.alert('الفاتورة مربوطة بسند تسليم مسبقاً.', { type: 'warning' });
      return;
    }
    if (!deliveryPickUrl) {
      AppDialog.alert('سحب سند التسليم غير متاح.', { type: 'warning' });
      return;
    }
    function loadList() {
      var custEl = document.getElementById('inv_customer');
      var customerId = custEl ? parseInt(custEl.value, 10) || 0 : 0;
      var url =
        deliveryPickUrl +
        (customerId > 0 ? '?customer_id=' + encodeURIComponent(String(customerId)) : '');
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            AppDialog.error('تعذر تحميل قائمة السندات.');
            return;
          }
          var list = data.deliveries || [];
          var modal = document.getElementById('inv-delivery-pick-modal');
          var ul = document.getElementById('inv_delivery_pick_list');
          var empty = document.getElementById('inv_delivery_pick_empty');
          if (!modal || !ul) return;
          ul.innerHTML = '';
          if (!list.length) {
            if (empty) empty.hidden = false;
          } else if (empty) {
            empty.hidden = true;
          }
          list.forEach(function (row) {
            var li = document.createElement('li');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sales-inv-dlv-pick-item';
            btn.textContent =
              (row.delivery_no || '—') +
              ' — ' +
              (row.customer_name || '') +
              ' — ' +
              (row.delivery_date_dmy || '');
            btn.addEventListener('click', function () {
              var proceed = function () {
                pickDeliveryById(parseInt(row.id, 10) || 0);
              };
              if (invoiceHasMaterialLines() || currentInvoiceId > 0) {
                AppDialog.confirm(
                  'سيتم استبدال بيانات الفاتورة الحالية ببيانات السند. متابعة؟',
                  { title: 'سحب سند تسليم' }
                ).then(function (ok) {
                  if (ok) proceed();
                });
              } else {
                proceed();
              }
            });
            li.appendChild(btn);
            ul.appendChild(li);
          });
          modal.hidden = false;
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    }
    if (invoiceHasMaterialLines() && currentInvoiceId < 1 && linkedDeliveryId < 1) {
      AppDialog.confirm('سيتم استبدال الأسطر الحالية ببيانات السند. متابعة؟', {
        title: 'سحب سند تسليم',
      }).then(function (ok) {
        if (ok) loadList();
      });
      return;
    }
    loadList();
  }

  var pullDeliveryBtn = document.getElementById('inv_pull_delivery_btn');
  if (pullDeliveryBtn) {
    pullDeliveryBtn.addEventListener('click', openDeliveryPickDialog);
  }
  var unlinkDeliveryBtn = document.getElementById('inv_unlink_delivery_btn');
  if (unlinkDeliveryBtn) {
    unlinkDeliveryBtn.addEventListener('click', function (e) {
      e.preventDefault();
      unlinkDeliveryFromInvoice();
    });
  }
  var deliveryPickClose = document.getElementById('inv_delivery_pick_close');
  if (deliveryPickClose) {
    deliveryPickClose.addEventListener('click', closeDeliveryPickModal);
  }
  var deliveryPickModal = document.getElementById('inv-delivery-pick-modal');
  if (deliveryPickModal) {
    deliveryPickModal.addEventListener('click', function (e) {
      if (e.target && e.target.getAttribute('data-close') === '1') {
        closeDeliveryPickModal();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootInvoicePage);
  } else {
    bootInvoicePage();
  }

  window.addEventListener('beforeunload', function (e) {
    if (formSubmitting || !formDirty || invoiceIsPosted) return;
    persistDraft();
    e.preventDefault();
    e.returnValue = '';
  });

  window.ManagerSalesInvoice = {
    openPicker: function (el) {
      var tr = el && el.closest ? el.closest('tr[data-line-id]') : null;
      if (tr) openPickerForRow(tr);
    },
    refreshCustomerRep: applyCustomerSalesRep,
    closeItemPicker: closePicker,
  };
})();
