(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('sales-ret-form');
  if (!form) return;

  var ledgerView = form.getAttribute('data-ledger-view') === '1';

  var apiInvoices = form.getAttribute('data-api-invoices') || '';
  var apiLines = form.getAttribute('data-api-lines') || '';
  var apiReturn = form.getAttribute('data-api-return') || '';
  var returnPostUrl = form.getAttribute('data-return-post-url') || '';
  var returnUnpostUrl = form.getAttribute('data-return-unpost-url') || '';
  var canUnpostByPermission = form.getAttribute('data-can-unpost') === '1';
  var returnDeleteUrl = form.getAttribute('data-return-delete-url') || '';
  var sendEmailUrl = form.getAttribute('data-send-email-url') || '';
  var listReturnsUrl = form.getAttribute('data-list-url') || '';
  var newReturnUrl = form.getAttribute('data-new-url') || '';
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var ledgerReturnQs = form.getAttribute('data-ledger-return-qs') || '';
  var companyName = form.getAttribute('data-company-name') || '';

  function appendLedgerReturnQs(url) {
    if (!ledgerReturnQs) return url;
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + ledgerReturnQs;
  }
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

  var decimals = parseInt(form.getAttribute('data-decimals') || '', 10);
  if (isNaN(decimals) || decimals < 0) {
    decimals = window.AppFormat ? AppFormat.decimals() : 2;
  }

  var customerSel = document.getElementById('ret_customer');
  var customerOpenBtn = document.getElementById('ret_customer_open');
  var customerPickerApi = null;
  var invoiceSel = document.getElementById('ret_invoice');
  var retNoInp = document.getElementById('ret_no');
  var retDateInp = document.getElementById('ret_date');
  var retNotes = document.getElementById('ret_notes');
  var retReasonReturn = document.getElementById('ret_reason_return');
  var retReasonReturnWrap = document.getElementById('ret_reason_return_wrap');
  var returnEinvQr = '';
  var returnEinvQrDataUrl = '';
  var returnEinvSent = false;
  var returnEinvTrackingRequired = true;
  var returnEinvNum = '';
  var originalInvoiceEinvSent = false;
  var originalInvoiceEinvNum = '';
  var originalInvoiceUuid = '';
  var originalInvoiceNoForEinvoice = '';
  var needsOriginalInvoiceUuid = false;
  var recordIdInp = document.getElementById('ret_record_id');
  var tbody = document.getElementById('sales-ret-lines-body');
  var linesJson = document.getElementById('sales-ret-lines-json');
  var hint = document.getElementById('sales-ret-hint');
  var defaultHintHtml = hint ? hint.innerHTML : '';
  var lineTpl = document.getElementById('sales-ret-line-template');
  var picker = document.getElementById('sales-ret-picker');
  var pickerSearch = document.getElementById('sales-ret-picker-search');
  var pickerResults = document.getElementById('sales-ret-picker-results');
  var pickerClose = document.getElementById('sales-ret-picker-close');
  var sumSub = document.getElementById('sales-ret-sum-sub');
  var sumTax = document.getElementById('sales-ret-sum-tax');
  var sumGrand = document.getElementById('sales-ret-sum-grand');

  var availableLines = [];
  var activePickRow = null;
  var pickerTimer = null;
  var currentReturnId = 0;
  var returnIsPosted = false;
  var isSavedMode = false;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var docNoSearch = window.DocumentNoNav ? DocumentNoNav.createSearchState() : { matchIds: [], matchIndex: -1, query: '', currentDocNo: '' };
  var DOC_NO_SEARCH_UI = {
    noInputId: 'ret_no',
    prevBtnId: 'ret_no_prev',
    nextBtnId: 'ret_no_next',
    defaultNoTitle: 'اكتب جزءاً من رقم المرتجع واضغط Enter للبحث',
  };
  var searchTimer = null;
  /** @type {object|null} */
  var lastLoadedReturn = null;

  function returnArchiveState(id) {
    id = parseInt(String(id), 10) || 0;
    if (id < 1) {
      return { allowed: false, reason: 'not_saved' };
    }
    if (returnIsPosted) {
      return { allowed: true, readOnly: true, reason: '' };
    }
    return { allowed: true, readOnly: false, reason: '' };
  }

  if (global.FinVoucherArchive) {
    global.FinVoucherArchive.init({
      apiUrl: form.getAttribute('data-archive-api') || '',
      csrf: (form.querySelector('input[name="_csrf"]') || {}).value || '',
      kind: form.getAttribute('data-archive-kind') || 'sales_return',
      title: 'مرتجع مبيعات',
      canArchive: form.getAttribute('data-can-archive') === '1',
      getVoucherId: function () {
        return currentReturnId;
      },
      getVoucherLabel: function () {
        return {
          no: (document.getElementById('ret_no') || {}).value || '',
          date: (document.getElementById('ret_date') || {}).value || '',
        };
      },
      companyName: companyName,
      isArchiveAllowed: returnArchiveState,
    });
  }

  function fmtDate(value) {
    return window.AppFormat && AppFormat.formatDateDmY
      ? AppFormat.formatDateDmY(value)
      : String(value == null ? '' : value);
  }

  function fmt(n) {
    if (window.AppFormat && AppFormat.fmt) return AppFormat.fmt(n, decimals);
    return Number(n || 0).toFixed(decimals);
  }

  function roundMoney(n) {
    if (window.AppFormat && AppFormat.round) return AppFormat.round(n, decimals);
    var p = Math.pow(10, decimals);
    return Math.round(Number(n) * p) / p;
  }

  function applyDecimalPlacesFromSettings() {
    if (window.AppFormat && AppFormat.decimals) {
      decimals = AppFormat.decimals();
      form.setAttribute('data-decimals', String(decimals));
    }
    tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
      if (parseInt(tr.dataset.itemId, 10) > 0) recalcRow(tr);
    });
    recalcFooter();
  }

  window.addEventListener('app:decimal-places', applyDecimalPlacesFromSettings);

  function calcLine(qty, unitPrice, taxRate) {
    var sub = roundMoney(qty * unitPrice);
    var tax = roundMoney(sub * (taxRate / 100));
    var gross = roundMoney(sub + tax);
    return { sub: sub, tax: tax, gross: gross };
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function buildApiUrl(base, params) {
    if (!base) return '';
    var parts = [];
    if (params) {
      Object.keys(params).forEach(function (k) {
        var v = params[k];
        if (v !== '' && v != null) {
          parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
        }
      });
    }
    if (!parts.length) return base;
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
  }

  function setHint(msg) {
    if (hint) hint.textContent = msg;
  }

  function getCustomerName() {
    if (customerPickerApi) return customerPickerApi.getName();
    if (window.CustomerPickerModal) {
      return CustomerPickerModal.getLabel('ret_customer') || '';
    }
    return '';
  }

  function setCustomerId(customerId, silent) {
    var id = parseInt(customerId, 10) || 0;
    if (customerPickerApi) {
      customerPickerApi.setById(id, !!silent);
      return;
    }
    if (customerSel) customerSel.value = id > 0 ? String(id) : '';
  }

  function initCustomerPicker() {
    if (!window.CustomerPickerModal) {
      setTimeout(initCustomerPicker, 40);
      return;
    }
    if (customerPickerApi) return;
    customerPickerApi = CustomerPickerModal.bind({
      hidden: 'ret_customer',
      open: 'ret_customer_open',
      display: 'ret_customer_display',
      jsonId: 'sales-ret-customers-json',
      getDisabled: function () {
        return isSavedMode;
      },
      onSelect: function () {
        onCustomerSelected();
      },
    });
  }

  function setSavedMode(on) {
    isSavedMode = !!on;
    form.classList.toggle('sales-ret-form-is-saved', isSavedMode);
    if (customerOpenBtn) customerOpenBtn.disabled = isSavedMode;
    if (invoiceSel) invoiceSel.disabled = isSavedMode;
    if (retDateInp) retDateInp.readOnly = isSavedMode;
    if (retNotes) retNotes.readOnly = isSavedMode;
    if (tbody) {
      tbody.querySelectorAll('.js-qty-ret, .js-qty-extra-ret, .js-ret-pick').forEach(function (el) {
        el.disabled = isSavedMode;
      });
    }
    var pickAll = document.getElementById('sales-ret-pick-all');
    if (pickAll) pickAll.disabled = isSavedMode;
  }

  /** قفل مصدر المرتجع (عميل/فاتورة) مع الإبقاء على تعديل المواد لمرتجع غير مرحّل. */
  function setSourceLocked(on) {
    if (customerOpenBtn) customerOpenBtn.disabled = !!on || isSavedMode;
    if (invoiceSel) invoiceSel.disabled = !!on || isSavedMode;
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('ret_no_prev', 'ret_no_next', prevId, nextId, {
        onEmpty: currentReturnId < 1,
        prevTitle: 'المرتجع السابق',
        nextTitle: 'المرتجع التالي',
        prevBeforeLatestTitle: 'المرتجع قبل الأخير',
        latestTitle: 'آخر مرتجع بيع',
      });
      return;
    }
    var prevBtn = document.getElementById('ret_no_prev');
    var nextBtn = document.getElementById('ret_no_next');
    if (prevBtn) prevBtn.disabled = !(prevId > 0);
    if (nextBtn) nextBtn.disabled = !(nextId > 0);
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

  function refreshEmptyBrowseNav() {
    if (!apiReturn) {
      setBrowseNav(0, 0);
      return;
    }
    fetch(apiReturn + '?edge=first', { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok || !data.return) {
          setBrowseNav(0, 0);
          return;
        }
        var ret = data.return;
        var newestId = parseInt(ret.id, 10) || 0;
        setBrowseNav(ret.prev_id || 0, newestId);
      });
  }

  function clearLines() {
    if (tbody) tbody.innerHTML = '';
    availableLines = [];
    recalcFooter();
    syncJson();
    updatePickAllState();
  }

  function resetInvoiceSelect(message) {
    if (!invoiceSel) return;
    invoiceSel.innerHTML = '';
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = message || '— اختر فاتورة —';
    invoiceSel.appendChild(opt);
    invoiceSel.disabled = true;
    invoiceSel.value = '';
  }

  function getDataRows() {
    if (!tbody) return [];
    return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-invoice-line-id]'));
  }

  function isRowPicked(tr) {
    var pick = tr.querySelector('.js-ret-pick');
    return !!(pick && pick.checked);
  }

  function rowHasReturnQty(tr) {
    if (!tr) return false;
    var qtyInp = tr.querySelector('.js-qty-ret');
    var extraInp = tr.querySelector('.js-qty-extra-ret');
    var qty = qtyInp ? parseFloat(qtyInp.value) || 0 : 0;
    var extra = extraInp ? parseFloat(extraInp.value) || 0 : 0;
    return qty > 0 || extra > 0;
  }

  function isRowPrintable(tr) {
    var gross = parseFloat(tr.getAttribute('data-line-gross')) || 0;
    if (isSavedMode) {
      return rowHasReturnQty(tr) || gross > 0 || tr.classList.contains('is-picked');
    }
    return isRowPicked(tr) && rowHasReturnQty(tr);
  }

  function createRow() {
    if (!lineTpl || !tbody) return null;
    var node = lineTpl.content.cloneNode(true);
    var tr = node.querySelector('tr');
    if (!tr) return null;
    bindRowEvents(tr);
    return tr;
  }

  function renumberRows() {
    var n = 0;
    getDataRows().forEach(function (tr) {
      n++;
      var seq = tr.querySelector('.js-seq');
      if (seq) seq.textContent = String(n);
    });
  }

  function lineUsed(invoiceLineId) {
    return getDataRows().some(function (tr) {
      return parseInt(tr.getAttribute('data-invoice-line-id'), 10) === invoiceLineId;
    });
  }

  function recalcRow(tr) {
    if (!isRowPicked(tr)) {
      tr.querySelector('.js-line-sub').textContent = fmt(0);
      tr.querySelector('.js-tax-amt').textContent = fmt(0);
      tr.querySelector('.js-line-gross').textContent = fmt(0);
      tr.setAttribute('data-line-sub', '0');
      tr.setAttribute('data-line-tax', '0');
      tr.setAttribute('data-line-gross', '0');
      return;
    }
    var extraInp = tr.querySelector('.js-qty-extra-ret');
    if (extraInp) {
      var extra = parseFloat(extraInp.value) || 0;
      var maxExtra = parseFloat(tr.getAttribute('data-qty-extra-remaining')) || 0;
      if (extra > maxExtra + 0.000001) {
        extra = maxExtra;
        extraInp.value = maxExtra > 0 ? formatQtyForInput(maxExtra) : '';
      }
    }
    var qty = parseFloat(tr.querySelector('.js-qty-ret').value) || 0;
    var max = parseFloat(tr.getAttribute('data-qty-remaining')) || 0;
    var up = parseFloat(tr.getAttribute('data-unit-price')) || 0;
    var trate = parseFloat(tr.getAttribute('data-tax-rate')) || 0;
    var qtySold = parseFloat(tr.getAttribute('data-qty-sold')) || 0;
    var lineTotalSold = parseFloat(tr.getAttribute('data-line-total-sold')) || 0;
    if (qty > max + 0.000001) {
      qty = max;
      tr.querySelector('.js-qty-ret').value = max > 0 ? String(max) : '';
    }
    var c;
    if (qtySold > 0.000001) {
      var sub = roundMoney((lineTotalSold / qtySold) * qty);
      var tax = roundMoney(sub * (trate / 100));
      c = { sub: sub, tax: tax, gross: roundMoney(sub + tax) };
    } else {
      c = calcLine(qty, up, trate);
    }
    tr.querySelector('.js-line-sub').textContent = fmt(c.sub);
    tr.querySelector('.js-tax-amt').textContent = fmt(c.tax);
    tr.querySelector('.js-line-gross').textContent = fmt(c.gross);
    tr.setAttribute('data-line-sub', String(c.sub));
    tr.setAttribute('data-line-tax', String(c.tax));
    tr.setAttribute('data-line-gross', String(c.gross));
  }

  function recalcFooter() {
    var sub = 0;
    var tax = 0;
    var gross = 0;
    getDataRows().forEach(function (tr) {
      if (!isRowPicked(tr)) return;
      if (!rowHasReturnQty(tr)) return;
      sub += parseFloat(tr.getAttribute('data-line-sub')) || 0;
      tax += parseFloat(tr.getAttribute('data-line-tax')) || 0;
      gross += parseFloat(tr.getAttribute('data-line-gross')) || 0;
    });
    if (sumSub) sumSub.textContent = fmt(sub);
    if (sumTax) sumTax.textContent = fmt(tax);
    if (sumGrand) sumGrand.textContent = fmt(gross);
  }

  function syncJson() {
    if (!linesJson) return;
    var lines = [];
    getDataRows().forEach(function (tr) {
      if (!isRowPicked(tr)) return;
      if (!rowHasReturnQty(tr)) return;
      var qty = parseFloat(tr.querySelector('.js-qty-ret').value) || 0;
      var qtyExtra = 0;
      var extraInp = tr.querySelector('.js-qty-extra-ret');
      if (extraInp) qtyExtra = parseFloat(extraInp.value) || 0;
      lines.push({
        invoice_line_id: parseInt(tr.getAttribute('data-invoice-line-id'), 10),
        item_id: parseInt(tr.getAttribute('data-item-id'), 10),
        qty: qty,
        qty_extra: qtyExtra,
        unit_price: parseFloat(tr.getAttribute('data-unit-price')) || 0,
        tax_rate_percent: parseFloat(tr.getAttribute('data-tax-rate')) || 0,
        line_subtotal: parseFloat(tr.getAttribute('data-line-sub')) || 0,
        tax_amount: parseFloat(tr.getAttribute('data-line-tax')) || 0,
        line_gross: parseFloat(tr.getAttribute('data-line-gross')) || 0,
      });
    });
    linesJson.value = JSON.stringify(lines);
  }

  function setRowItemDisplay(tr, name, barcode) {
    var nameEl = tr.querySelector('.js-name');
    if (nameEl) {
      nameEl.textContent = name || '—';
      nameEl.classList.toggle('is-placeholder', !name);
    }
    var bc = tr.querySelector('.js-barcode-display');
    if (bc) bc.textContent = barcode || '—';
  }

  function fillRowFromCatalogLine(tr, line) {
    var qtyRemainingNum = parseFloat(line.qty_remaining);
    if (!isFinite(qtyRemainingNum) || qtyRemainingNum < 0) qtyRemainingNum = 0;
    var unitPriceNum = parseFloat(line.unit_price);
    if (!isFinite(unitPriceNum)) unitPriceNum = 0;
    var taxRateNum = parseFloat(line.tax_rate_percent);
    if (!isFinite(taxRateNum)) taxRateNum = 0;
    var qtySoldNum = parseFloat(line.qty_sold);
    if (!isFinite(qtySoldNum)) qtySoldNum = 0;
    var qtyExtraSoldNum = parseFloat(line.qty_extra_sold);
    if (!isFinite(qtyExtraSoldNum)) qtyExtraSoldNum = 0;
    var qtyExtraRemainingNum = parseFloat(line.qty_extra_remaining);
    if (!isFinite(qtyExtraRemainingNum) || qtyExtraRemainingNum < 0) qtyExtraRemainingNum = 0;
    tr.setAttribute('data-invoice-line-id', String(line.invoice_line_id));
    tr.setAttribute('data-item-id', String(line.item_id));
    tr.setAttribute('data-qty-remaining', String(qtyRemainingNum));
    tr.setAttribute('data-qty-extra-remaining', String(qtyExtraRemainingNum));
    tr.setAttribute('data-qty-sold', String(qtySoldNum));
    tr.setAttribute('data-qty-extra-sold', String(qtyExtraSoldNum));
    tr.setAttribute('data-line-total-sold', String(parseFloat(line.line_total) || 0));
    tr.setAttribute('data-unit-price', String(unitPriceNum));
    tr.setAttribute('data-tax-rate', String(taxRateNum));
    var name = line.name_ar || line.line_desc || '—';
    setRowItemDisplay(tr, name, line.barcode || '');
    tr.querySelector('.js-price-readonly').textContent = fmt(unitPriceNum);
    tr.querySelector('.js-qty-ret').value = '';
    tr.querySelector('.js-qty-ret').max = String(qtyRemainingNum);
    tr.querySelector('.js-qty-ret').disabled = true;
    var extraInp = tr.querySelector('.js-qty-extra-ret');
    if (extraInp) {
      extraInp.value = '';
      extraInp.max = String(qtyExtraRemainingNum);
      extraInp.disabled = true;
      var extraCell = extraInp.closest('td');
      if (extraCell) {
        extraCell.hidden = qtyExtraSoldNum <= 0.000001 && qtyExtraRemainingNum <= 0.000001;
      }
    }
    var meta = tr.querySelector('.js-qty-meta');
    if (meta) {
      var metaText =
        'مباع: ' + fmt(qtySoldNum) + ' · متبقي للإرجاع: ' + fmt(qtyRemainingNum);
      if (qtyExtraSoldNum > 0.000001 || qtyExtraRemainingNum > 0.000001) {
        metaText +=
          ' · إضافية مباعة: ' + fmt(qtyExtraSoldNum) + ' · متبقي: ' + fmt(qtyExtraRemainingNum);
      }
      meta.textContent = metaText;
    }
    recalcRow(tr);
  }

  function formatQtyForInput(raw) {
    if (raw == null || raw === '') return '';
    var n = parseFloat(raw);
    if (!isFinite(n) || n <= 0) return '';
    // نُنظّف الأصفار الزائدة من الجزء العشري (مثلاً "12.000000" ⇒ "12").
    // هذا يُجنّب ظهور قيمة "0.000000" عند الـ input بسبب نصوص DECIMAL(18,6) المُعادة من قاعدة البيانات.
    var s = String(n);
    return s;
  }

  function setRowPicked(tr, picked, qty, qtyExtra) {
    var pick = tr.querySelector('.js-ret-pick');
    var qtyInp = tr.querySelector('.js-qty-ret');
    var extraInp = tr.querySelector('.js-qty-extra-ret');
    var maxExtra = parseFloat(tr.getAttribute('data-qty-extra-remaining')) || 0;
    if (pick) pick.checked = !!picked;
    tr.classList.toggle('is-picked', !!picked);
    if (qtyInp) {
      qtyInp.disabled = !picked || isSavedMode;
      if (picked) {
        if (qty != null && qty !== '') {
          qtyInp.value = formatQtyForInput(qty);
        } else if (!qtyInp.value) {
          qtyInp.value = formatQtyForInput(tr.getAttribute('data-qty-remaining'));
        }
      } else {
        qtyInp.value = '';
      }
    }
    if (extraInp) {
      extraInp.disabled = !picked || isSavedMode || maxExtra <= 0.000001;
      if (picked) {
        if (qtyExtra != null && qtyExtra !== '') {
          extraInp.value = formatQtyForInput(qtyExtra);
        }
      } else {
        extraInp.value = '';
      }
    }
    recalcRow(tr);
  }

  function createInvoiceLineRow(line, opts) {
    opts = opts || {};
    var tr = createRow();
    if (!tr) return null;
    fillRowFromCatalogLine(tr, line);
    setRowPicked(tr, !!opts.picked, opts.qty, opts.qtyExtra);
    if (isSavedMode) {
      var pick = tr.querySelector('.js-ret-pick');
      if (pick) pick.disabled = true;
    }
    return tr;
  }

  function bindRowEvents(tr) {
    var qtyInp = tr.querySelector('.js-qty-ret');
    if (qtyInp) {
      qtyInp.addEventListener('input', function () {
        recalcRow(tr);
        recalcFooter();
        syncJson();
      });
    }
    var extraInp = tr.querySelector('.js-qty-extra-ret');
    if (extraInp) {
      extraInp.addEventListener('input', function () {
        recalcRow(tr);
        recalcFooter();
        syncJson();
      });
    }
    var pickCb = tr.querySelector('.js-ret-pick');
    if (pickCb) {
      pickCb.addEventListener('change', function () {
        if (isSavedMode) return;
        setRowPicked(tr, pickCb.checked);
        recalcFooter();
        syncJson();
        updatePickAllState();
      });
    }
  }

  function updatePickAllState() {
    var allCb = document.getElementById('sales-ret-pick-all');
    if (!allCb) return;
    var rows = getDataRows();
    if (!rows.length) {
      allCb.checked = false;
      allCb.indeterminate = false;
      return;
    }
    var n = 0;
    rows.forEach(function (tr) {
      if (isRowPicked(tr)) n++;
    });
    allCb.checked = n === rows.length;
    allCb.indeterminate = n > 0 && n < rows.length;
  }

  function getTableWrap() {
    return document.getElementById('sales-ret-table-wrap');
  }

  function mountPickerPortal() {
    if (!picker || picker.parentNode === document.body) return;
    document.body.appendChild(picker);
  }

  function isInvoicePostedInUi() {
    if (!invoiceSel || invoiceSel.selectedIndex < 0) return true;
    var opt = invoiceSel.options[invoiceSel.selectedIndex];
    if (!opt || !opt.value) return true;
    return opt.getAttribute('data-posted') === '1';
  }

  function ensureCatalogThenPick(entryRow) {
    var iid = invoiceSel ? invoiceSel.value : '';
    var cid = customerSel ? customerSel.value : '';
    if (!iid) {
      AppDialog.alert('اختر فاتورة البيع أولاً.', { type: 'warning' });
      return;
    }
    function openIfReady() {
      if (!availableLines.length) {
        var msg = !isInvoicePostedInUi()
          ? 'هذه الفاتورة غير مرحّلة. رحّلها من «ترحيل فواتير المبيعات» ثم حدّث الصفحة.'
          : 'لا توجد مواد متبقية للإرجاع في هذه الفاتورة (أو تم إرجاعها بالكامل).';
        AppDialog.alert(msg, { type: 'warning' });
        return;
      }
      activePickRow = entryRow;
      openPicker();
    }
    if (availableLines.length) {
      openIfReady();
      return;
    }
    var embedded = getEmbeddedLines(iid);
    if (embedded && embedded.length) {
      availableLines = embedded;
      openIfReady();
      return;
    }
    setHint('جاري تحميل مواد الفاتورة…');
    fetch(buildApiUrl(apiLines, { invoice_id: iid, customer_id: cid }), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          AppDialog.alert(data.message || 'تعذر تحميل مواد الفاتورة.', { type: 'warning' });
          return;
        }
        availableLines = data.lines || [];
        if (availableLines.length) {
          setHint('اختر من القائمة المواد التي تريد إرجاعها فقط.');
        }
        openIfReady();
      })
      .catch(function () {
        AppDialog.alert('تعذر تحميل مواد الفاتورة. حدّث الصفحة وحاول مجدداً.', { type: 'warning' });
      });
  }

  function openPicker() {
    if (!picker) return;
    mountPickerPortal();
    picker.hidden = false;
    renderPickerList(pickerSearch ? pickerSearch.value : '');
    if (pickerSearch) {
      pickerSearch.value = '';
      pickerSearch.focus();
    }
  }

  function closePicker() {
    if (picker) picker.hidden = true;
    activePickRow = null;
  }

  var pickerListNav = null;

  function renderPickerList(q) {
    if (!pickerResults) return;
    if (pickerListNav) pickerListNav.reset();
    var ql = (q || '').trim().toLowerCase();
    var list = availableLines.filter(function (line) {
      if (lineUsed(line.invoice_line_id)) return false;
      if (!ql) return true;
      var name = (line.name_ar || line.line_desc || '').toLowerCase();
      var bc = (line.barcode || '').toLowerCase();
      return name.indexOf(ql) >= 0 || bc.indexOf(ql) >= 0;
    });
    if (!list.length) {
      pickerResults.innerHTML = '<div class="sales-inv-pick-empty">لا توجد مواد متبقية للإرجاع</div>';
      return;
    }
    pickerResults.innerHTML = '';
    list.forEach(function (line) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'sales-inv-pick-item';
      btn.innerHTML =
        '<strong class="sales-inv-pick-item-name">' +
        escapeHtml(line.name_ar || line.line_desc || '—') +
        '</strong><span class="sales-inv-pick-item-meta">متبقي: ' +
        escapeHtml(fmt(line.qty_remaining));
      var extraRem = parseFloat(line.qty_extra_remaining) || 0;
      if (extraRem > 0.000001) {
        btn.innerHTML +=
          ' · إضافية متبقية: ' + escapeHtml(fmt(extraRem));
      }
      btn.innerHTML +=
        ' · مباع: ' +
        escapeHtml(fmt(line.qty_sold)) +
        '</span>';
      btn.addEventListener('click', function () {
        if (activePickRow && activePickRow.classList.contains('is-entry-row')) {
          addLineFromCatalog(line);
        }
      });
      pickerResults.appendChild(btn);
    });
  }

  if (
    pickerSearch &&
    pickerResults &&
    window.AppListKeyboard &&
    !pickerSearch.getAttribute('data-list-kbd')
  ) {
    pickerSearch.setAttribute('data-list-kbd', '1');
    pickerSearch.addEventListener('input', function () {
      renderPickerList(pickerSearch.value);
    });
    pickerListNav = AppListKeyboard.bindModalSearchList({
      search: pickerSearch,
      results: pickerResults,
      isOpen: function () {
        return picker && !picker.hidden;
      },
      onPick: function (btn) {
        btn.click();
      },
    });
  }

  function refreshReturnEinvQrDataUrl(cb) {
    if (!returnEinvQr) {
      returnEinvQrDataUrl = '';
      if (cb) cb();
      return;
    }
    var ipp = window.InvInvoicePrint;
    if (ipp && ipp.einvQrResolveDataUrl) {
      ipp.einvQrResolveDataUrl(returnEinvQr, function (url) {
        returnEinvQrDataUrl = url || '';
        if (cb) cb();
      });
      return;
    }
    returnEinvQrDataUrl = '';
    if (cb) cb();
  }

  function buildReturnEinvoiceQrBox() {
    if (!returnEinvQrDataUrl && !returnEinvQr) return '';
    if (returnEinvQrDataUrl && window.InvInvoicePrint && window.InvInvoicePrint.buildEinvQrBoxHtml) {
      return window.InvInvoicePrint.buildEinvQrBoxHtml(returnEinvQrDataUrl);
    }
    return '';
  }

  function buildReturnMetaTable(retNo, retDate, cust, inv) {
    if (window.DocumentHeader && typeof window.DocumentHeader.buildMetaTable === 'function') {
      return window.DocumentHeader.buildMetaTable([
        { label: 'رقم المرتجع', value: retNo },
        { label: 'التاريخ', value: retDate },
        { label: 'العميل', value: cust },
        { label: 'فاتورة البيع', value: inv },
      ]);
    }
    var cell = 'padding:0.2rem 0;direction:rtl;unicode-bidi:isolate;';
    return (
      '<div class="doc-print-meta"><table><tr><td style="' + cell + '"><strong>رقم المرتجع:\u200F</strong> <bdi>' +
      escapeHtml(retNo) +
      '</bdi></td></tr><tr><td style="' + cell + '"><strong>التاريخ:\u200F</strong> <bdi>' +
      escapeHtml(retDate) +
      '</bdi></td></tr><tr><td style="' + cell + '"><strong>العميل:\u200F</strong> ' +
      '<bdi class="doc-print-meta-value doc-print-meta-value--party">' +
      escapeHtml(cust) +
      '</bdi>' +
      '</td></tr><tr><td style="' + cell + '"><strong>فاتورة البيع:\u200F</strong> <bdi>' +
      escapeHtml(inv) +
      '</bdi></td></tr></table></div>'
    );
  }

  function printTd(html, align) {
    return (
      '<td style="padding:0.4rem;border:1px solid #cbd5e1;text-align:' +
      (align || 'center') +
      ';font-family:Arial,Helvetica,sans-serif;">' +
      html +
      '</td>'
    );
  }

  function formatReturnPrintQty(tr) {
    var qtyInp = tr.querySelector('.js-qty-ret');
    var extraInp = tr.querySelector('.js-qty-extra-ret');
    var qty = qtyInp ? parseFloat(qtyInp.value) || 0 : 0;
    var extra = extraInp ? parseFloat(extraInp.value) || 0 : 0;
    if (extra > 0.000001) {
      return fmt(qty) + ' + ' + fmt(extra) + ' إضافية';
    }
    return fmt(qty);
  }

  function buildReturnPrintRow(tr, seq) {
    var name = tr.querySelector('.js-name') ? tr.querySelector('.js-name').textContent : '';
    var bc = tr.querySelector('.js-barcode-display') ? tr.querySelector('.js-barcode-display').textContent : '';
    return (
      '<tr>' +
      printTd(escapeHtml(bc)) +
      printTd(escapeHtml(String(seq))) +
      printTd(escapeHtml(name), 'start') +
      printTd(escapeHtml(formatReturnPrintQty(tr))) +
      printTd(escapeHtml(tr.querySelector('.js-price-readonly').textContent)) +
      printTd(escapeHtml(tr.querySelector('.js-line-sub').textContent)) +
      printTd(escapeHtml(tr.querySelector('.js-tax-amt').textContent)) +
      printTd(escapeHtml(tr.querySelector('.js-line-gross').textContent)) +
      '</tr>'
    );
  }

  function buildReturnPrintRowFromLine(line, seq) {
    var qty = parseFloat(line.qty) || 0;
    var qtyExtra = parseFloat(line.qty_extra) || 0;
    var qtyText = qtyExtra > 0.000001 ? fmt(qty) + ' + ' + fmt(qtyExtra) + ' إضافية' : fmt(qty);
    var name = line.name_ar || line.line_desc || '—';
    var bc = line.barcode || '—';
    var gross =
      line.line_gross != null ? line.line_gross : (line.line_subtotal || 0) + (line.tax_amount || 0);
    return (
      '<tr>' +
      printTd(escapeHtml(bc)) +
      printTd(escapeHtml(String(seq))) +
      printTd(escapeHtml(name), 'start') +
      printTd(escapeHtml(qtyText)) +
      printTd(escapeHtml(fmt(line.unit_price))) +
      printTd(escapeHtml(fmt(line.line_subtotal))) +
      printTd(escapeHtml(fmt(line.tax_amount))) +
      printTd(escapeHtml(fmt(gross))) +
      '</tr>'
    );
  }

  /** نفس بنية طباعة فاتورة المبيعات */
  function buildReturnPrintInnerHtml() {
    syncJson();
    var retNo = retNoInp && retNoInp.value ? retNoInp.value : '—';
    var retDate = fmtDate(retDateInp ? retDateInp.value : '');
    var cust = getCustomerName();
    var inv =
      invoiceSel && invoiceSel.selectedIndex > 0 ? invoiceSel.options[invoiceSel.selectedIndex].text : '';
    if (lastLoadedReturn && lastLoadedReturn.customer_name && !cust) {
      cust = lastLoadedReturn.customer_name;
    }
    if (lastLoadedReturn && lastLoadedReturn.invoice_no && !inv) {
      inv = lastLoadedReturn.invoice_no;
    }

    var notesVal = retNotes ? String(retNotes.value).trim() : '';
    var notesBlock = notesVal
      ? '<p style="margin:0.75rem 0 0;font-size:0.88rem;direction:rtl;unicode-bidi:isolate;"><strong>ملاحظات:\u200F</strong> <bdi>' +
        escapeHtml(notesVal) +
        '</bdi></p>'
      : '';

    var subT = sumSub ? sumSub.textContent : '0';
    var taxT = sumTax ? sumTax.textContent : '0';
    var grandT = sumGrand ? sumGrand.textContent : '0';

    var rowHtml = '';
    var seq = 0;
    getDataRows().forEach(function (tr) {
      if (!isRowPrintable(tr)) return;
      if (!rowHasReturnQty(tr)) return;
      seq++;
      rowHtml += buildReturnPrintRow(tr, seq);
    });
    if (!rowHtml && lastLoadedReturn && lastLoadedReturn.lines) {
      lastLoadedReturn.lines.forEach(function (line) {
        var qty = parseFloat(line.qty) || 0;
        var qtyExtra = parseFloat(line.qty_extra) || 0;
        if (qty <= 0 && qtyExtra <= 0) return;
        seq++;
        rowHtml += buildReturnPrintRowFromLine(line, seq);
      });
    }
    if (!rowHtml) {
      rowHtml =
        '<tr><td colspan="8" style="padding:1rem;text-align:center;color:#64748b;border:1px solid #cbd5e1;">لا توجد بنود</td></tr>';
    }
    if (lastLoadedReturn && lastLoadedReturn.subtotal != null && seq === 0) {
      subT = fmt(lastLoadedReturn.subtotal);
      taxT = fmt(lastLoadedReturn.tax_amount);
      grandT = fmt(lastLoadedReturn.total);
    }

    var metaTable = buildReturnMetaTable(retNo, retDate, cust, inv);
    var einvBox = buildReturnEinvoiceQrBox();
    var headerBlock = einvBox
      ? '<table class="inv-print-header-row" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0.3rem 0 0.6rem;direction:rtl;table-layout:fixed;">' +
          '<tr>' +
          '<td class="inv-print-header-meta" style="border:none;padding:0;vertical-align:top;">' + metaTable + '</td>' +
          '<td class="inv-print-header-qr" style="border:none;padding:0;vertical-align:top;width:' +
          (window.InvInvoicePrint ? window.InvInvoicePrint.EINV_QR_HEADER_COL_PX : 158) +
          'px;text-align:center;">' + einvBox + '</td>' +
        '</tr></table>'
      : metaTable;

    var inner =
      buildDocPrintHeader('مرتجع مبيعات') +
      headerBlock +
      '<table><thead><tr>' +
      '<th>Barcode</th><th>#</th><th>المادة</th><th>كمية الإرجاع</th><th>سعر الوحدة</th><th>قبل الضريبة</th><th>الضريبة</th><th>الإجمالي</th>' +
      '</tr></thead><tbody>' +
      rowHtml +
      '</tbody></table>' +
      '<div class="sales-inv-print-tot"><div><span>المجموع بدون ضريبة</span><span>' +
      escapeHtml(subT) +
      '</span></div><div><span>مجموع الضريبة</span><span>' +
      escapeHtml(taxT) +
      '</span></div><div class="g"><span>الإجمالي</span><span>' +
      escapeHtml(grandT) +
      '</span></div></div>' +
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

  var docHeaderCssFallback =
    '.doc-print-header{margin-top:0;margin-bottom:0.65rem;padding-top:0;}' +
    '.doc-print-header-top{padding-top:0;padding-bottom:0.5rem;border-bottom:1px solid #cbd5e1;}' +
    '.doc-print-header-brand{display:flex;flex-direction:row;align-items:center;justify-content:space-between;width:100%;gap:0.75rem;flex-wrap:wrap;direction:rtl;}' +
    '.doc-print-header-co{flex:1 1 auto;min-width:0;font-family:Arial,Helvetica,sans-serif;font-weight:800;font-size:1.1rem;color:#0f172a;text-align:start;line-height:1.3;}' +
    '.doc-print-header-logo{display:flex;align-items:center;justify-content:flex-end;flex-shrink:0;overflow:visible;padding:2px 0;}' +
    '.doc-print-header-logo img{max-height:130px;max-width:130px;width:auto;height:auto;object-fit:contain;object-position:center;display:block;}' +
    '.doc-print-header-title{text-align:center;font-weight:700;font-size:1.1rem;color:#1e293b;padding-top:0.45rem;margin:0;}' +
    '.doc-print-meta{margin:0.35rem 0 0.65rem;font-size:12px;font-weight:700;color:#334155;line-height:1.55;text-align:start;direction:rtl;}' +
    '.doc-print-meta table{width:100%;border-collapse:collapse;}' +
    '.doc-print-meta td{padding:0.2rem 0;border:none!important;text-align:start!important;font-weight:700;}';

  function getPrintFrameStyles() {
    var hdr =
      window.DocumentHeader && window.DocumentHeader.css
        ? window.DocumentHeader.css
        : docHeaderCssFallback;
    var bold =
      window.DocumentHeader && window.DocumentHeader.printBoldCss
        ? window.DocumentHeader.printBoldCss
        : '';
    return (
      docPrintWatermarkStyles() +
      hdr +
      bold +
      'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0f172a;margin:6mm 12mm 12mm;direction:rtl;}' +
      'table{border-collapse:collapse;width:100%;margin-top:0.5rem;}' +
      'th{background:#f1f5f9;padding:0.45rem;border:1px solid #94a3b8;font-size:12px;font-weight:700;}' +
      'td{padding:0.4rem;border:1px solid #cbd5e1;text-align:center;font-weight:700;}' +
      '.doc-print-meta{text-align:start;direction:rtl;}.doc-print-meta table{width:100%;border-collapse:collapse;}' +
      '.doc-print-meta td{border:none!important;padding:0.2rem 0!important;text-align:start!important;}' +
      '.doc-print-meta-value--party{font-weight:800;font-size:1.12em;color:#0f172a;}' +
      '.sales-inv-print-tot{margin-top:0.75rem;text-align:left;max-width:280px;margin-right:0;margin-left:auto;}' +
      '.sales-inv-print-tot div{display:flex;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid #e2e8f0;font-weight:700;}' +
      '.sales-inv-print-tot .g{font-weight:800;font-size:1.05rem;border-top:2px solid #334155;margin-top:0.35rem;padding-top:0.45rem;}' +
      '.inv-print-header-row{width:100%;border-collapse:collapse;margin:0.3rem 0 0.6rem;direction:rtl;}' +
      '.inv-print-header-row td{border:none!important;padding:0!important;vertical-align:top;}' +
      '.inv-print-header-row td.inv-print-header-meta{width:auto;}' +
      (window.InvInvoicePrint && window.InvInvoicePrint.einvQrPrintCss
        ? window.InvInvoicePrint.einvQrPrintCss()
        : '')
    );
  }

  function buildStandaloneReturnHtml() {
    var bodyAttrs =
      window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
        ? window.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>مرتجع مبيعات</title>' +
      '<style>' +
      getPrintFrameStyles() +
      '</style></head><body' +
      bodyAttrs +
      '>' +
      buildReturnPrintInnerHtml() +
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
    var waitForImages = function (doc, cb) {
      var imgs = doc.images ? Array.prototype.slice.call(doc.images) : [];
      if (!imgs.length) { cb(); return; }
      var pending = imgs.length;
      var done = false;
      var finish = function () { if (!done) { done = true; cb(); } };
      var safety = setTimeout(finish, 4000);
      imgs.forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) {
          if (--pending <= 0) { clearTimeout(safety); finish(); }
        } else {
          img.addEventListener('load', function () { if (--pending <= 0) { clearTimeout(safety); finish(); } });
          img.addEventListener('error', function () { if (--pending <= 0) { clearTimeout(safety); finish(); } });
        }
      });
    };
    setTimeout(function () {
      try {
        waitForImages(win.document, function () {
          try { win.focus(); win.print(); } catch (_e) {}
        });
      } catch (e) {
        try { win.focus(); win.print(); } catch (_e) {}
      }
    }, 200);
  }

  function closePrintPreview() {
    var overlay = document.getElementById('sales-inv-print-overlay');
    if (overlay) overlay.hidden = true;
  }

  function openPrintPreview(forPdf) {
    var go = function () {
      syncJson();
      var preview = document.getElementById('sales-inv-print-preview');
      var overlay = document.getElementById('sales-inv-print-overlay');
      var title = document.querySelector('.sales-inv-print-overlay-title');
      if (!preview || !overlay) {
        printHtmlInFrame(buildStandaloneReturnHtml());
        return;
      }
      preview.innerHTML = buildReturnPrintInnerHtml();
      if (title) {
        title.textContent = forPdf
          ? 'معاينة — اختر «حفظ كـ PDF» من نافذة الطباعة'
          : 'معاينة الطباعة';
      }
      overlay.hidden = false;
    };
    if (returnEinvQr && !returnEinvQrDataUrl) {
      refreshReturnEinvQrDataUrl(go);
    } else {
      go();
    }
  }

  function runPrintFromPreview() {
    var go = function () {
      printHtmlInFrame(buildStandaloneReturnHtml());
    };
    if (returnEinvQr && !returnEinvQrDataUrl) {
      refreshReturnEinvQrDataUrl(go);
    } else {
      go();
    }
  }

  function handleToolbarPrint() {
    var overlay = document.getElementById('sales-inv-print-overlay');
    var previewOpen = overlay && !overlay.hidden;
    if (previewOpen) {
      runPrintFromPreview();
      return;
    }
    var go = function () { openPrintPreview(false); };
    if (returnEinvQr && !returnEinvQrDataUrl) {
      refreshReturnEinvQrDataUrl(go);
    } else {
      go();
    }
  }

  function getReturnFileBase() {
    var no = retNoInp && retNoInp.value ? String(retNoInp.value).trim() : '';
    if (!no) no = 'draft';
    return 'return-' + no.replace(/[^\w\u0600-\u06FF\-]+/g, '_');
  }

  function downloadReturnPdf() {
    syncJson();
    if (typeof html2pdf === 'undefined') {
      AppDialog.error('تعذر تحميل مكتبة PDF. تحقق من الاتصال بالإنترنت ثم أعد تحميل الصفحة.');
      return;
    }
    var go = function () {
      var fname = getReturnFileBase() + '.pdf';
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
      preview.innerHTML = buildReturnPrintInnerHtml();
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
      var waitForImagesInHost = function (cb) {
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
        waitForImagesInHost(function () {
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
    if (returnEinvQr && !returnEinvQrDataUrl) {
      refreshReturnEinvQrDataUrl(go);
    } else {
      go();
    }
  }

  function sendReturnByEmail() {
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
      var docNo = retNoInp && retNoInp.value ? String(retNoInp.value).trim() : '';
      window.DocSendEmail.send({
        url: sendEmailUrl,
        docType: 'sales_return',
        docNo: docNo,
        fileBase: getReturnFileBase(),
        csrfToken: csrfInp ? csrfInp.value : '',
        buildHtml: buildReturnPrintInnerHtml,
        overlayId: 'sales-inv-print-overlay',
        previewId: 'sales-inv-print-preview',
      });
    };
    if (returnEinvQr && !returnEinvQrDataUrl) {
      refreshReturnEinvQrDataUrl(go);
    } else {
      go();
    }
  }

  function getEmbeddedInvoices(customerId) {
    if (typeof window.SalesRetGetInvoicesByCustomer !== 'function') {
      return null;
    }
    var map = window.SalesRetGetInvoicesByCustomer();
    var cid = String(customerId);
    var list = map[cid] || map[parseInt(cid, 10)];
    return Array.isArray(list) ? list : null;
  }

  function loadInvoices(customerId, selectedInvoiceId, options) {
    options = options || {};
    if (!customerId || !invoiceSel) {
      resetInvoiceSelect('— اختر العميل أولًا —');
      return Promise.resolve({ ok: true, invoices: [] });
    }
    if (!options.keepLines) {
      clearLines();
    }

    var embedded = getEmbeddedInvoices(customerId);
    if (embedded && !options.forceFetch && embedded.length > 0) {
      invoiceSel.innerHTML = '';
      var phEmb0 = document.createElement('option');
      phEmb0.value = '';
      phEmb0.textContent = '— اختر فاتورة —';
      invoiceSel.appendChild(phEmb0);
      embedded.forEach(function (inv) {
        var o = document.createElement('option');
        o.value = String(inv.id);
        var posted = inv.is_posted === 1 || inv.is_posted === true || inv.is_posted === '1';
        o.textContent =
          (inv.invoice_no || '#' + inv.id) +
          ' — ' +
          fmtDate(inv.invoice_date || '') +
          ' (' +
          fmt(inv.total) +
          ')';
        o.dataset.posted = posted ? '1' : '0';
        if (selectedInvoiceId && String(inv.id) === String(selectedInvoiceId)) {
          o.selected = true;
        }
        invoiceSel.appendChild(o);
      });
      if (options.keepLines) {
        invoiceSel.disabled = true;
      } else {
        invoiceSel.disabled = false;
        invoiceSel.removeAttribute('disabled');
      }
      if (selectedInvoiceId && invoiceSel.value && !options.skipCatalog) {
        loadCatalogLines(invoiceSel.value, customerId);
      } else if (!options.skipCatalog) {
        setHint('اختر فاتورة البيع ثم حدّد ☑ المواد المراد إرجاعها.');
      }
      if (options.onReady) options.onReady({ ok: true, invoices: embedded });
      return Promise.resolve({ ok: true, invoices: embedded });
    }

    resetInvoiceSelect('— جاري التحميل —');
    if (!apiInvoices) {
      resetInvoiceSelect('— تعذر الاتصال بالخادم —');
      setHint('رابط تحميل الفواتير غير مضبوط.');
      return Promise.resolve({ ok: false, invoices: [] });
    }
    return fetch(buildApiUrl(apiInvoices, { customer_id: customerId }), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('http_' + r.status);
        }
        return r.json();
      })
      .then(function (data) {
        resetInvoiceSelect();
        if (!data || !data.ok || !data.invoices || !data.invoices.length) {
          resetInvoiceSelect('— لا توجد فواتير قابلة للإرجاع —');
          if (!options.skipCatalog) {
            setHint('لا توجد فواتير مرحّلة بكميات متبقية للإرجاع لهذا العميل.');
          }
          if (options.onReady) options.onReady(data || { ok: true, invoices: [] });
          return data || { ok: true, invoices: [] };
        }
        invoiceSel.disabled = false;
        invoiceSel.removeAttribute('disabled');
        data.invoices.forEach(function (inv) {
          var o = document.createElement('option');
          o.value = String(inv.id);
          var posted = inv.is_posted === 1 || inv.is_posted === true || inv.is_posted === '1';
          var label =
            inv.invoice_no + ' — ' + fmtDate(inv.invoice_date || '') + ' (' + fmt(inv.total) + ')';
          o.textContent = label;
          o.dataset.posted = posted ? '1' : '0';
          if (selectedInvoiceId && String(inv.id) === String(selectedInvoiceId)) o.selected = true;
          invoiceSel.appendChild(o);
        });
        if (selectedInvoiceId && invoiceSel.value && !options.skipCatalog) {
          loadCatalogLines(invoiceSel.value, customerId);
        } else if (!options.skipCatalog) {
          setHint('اختر فاتورة البيع ثم «اضغط لاختيار المادة» لإضافة مواد الإرجاع.');
        }
        if (options.onReady) options.onReady(data);
        return data;
      })
      .catch(function () {
        resetInvoiceSelect('— تعذر تحميل الفواتير —');
        if (!options.skipCatalog) {
          setHint('تعذر تحميل فواتير العميل. حدّث الصفحة أو تحقق من الاتصال.');
        }
        if (options.onReady) options.onReady({ ok: false, invoices: [] });
        return { ok: false, invoices: [] };
      });
  }

  function onCustomerSelected() {
    if (isSavedMode || !customerSel) return;
    var cid = customerSel.value;
    if (!cid) {
      resetInvoiceSelect('— اختر العميل أولًا —');
      clearLines();
      if (hint && defaultHintHtml) {
        hint.innerHTML = defaultHintHtml;
      }
      return;
    }
    clearLines();
    loadInvoices(cid);
  }

  window.SalesRetLoadInvoices = function (customerId) {
    if (isSavedMode) return;
    var cid = customerId != null ? String(customerId) : '';
    if (!cid) {
      resetInvoiceSelect('— اختر العميل أولًا —');
      clearLines();
      return;
    }
    loadInvoices(cid, null, { forceFetch: true });
  };

  window.SalesRetClearCatalog = function (hintMsg) {
    if (isSavedMode) return;
    availableLines = [];
    if (tbody) tbody.innerHTML = '';
    recalcFooter();
    syncJson();
    updatePickAllState();
    if (hintMsg) {
      setHint(hintMsg);
    } else if (hint && defaultHintHtml) {
      hint.innerHTML = defaultHintHtml;
    }
  };

  function applySavedReturnLines(ret) {
    clearLines();
    (ret.lines || []).forEach(function (line) {
      // PDO MySQL يُعيد قيم DECIMAL كنصوص (مثل "12.000000")، فنُحوّلها لأرقام
      // لتجنّب ظهور الأصفار العشرية الزائدة في حقل الكمية بعد الحفظ.
      var qtyNum = parseFloat(line.qty);
      if (!isFinite(qtyNum) || qtyNum < 0) qtyNum = 0;
      var unitPriceNum = parseFloat(line.unit_price);
      if (!isFinite(unitPriceNum)) unitPriceNum = 0;
      var taxRateNum = parseFloat(line.tax_rate_percent);
      if (!isFinite(taxRateNum)) taxRateNum = 0;
      var qtySoldNum = parseFloat(line.qty_sold);
      if (!isFinite(qtySoldNum)) qtySoldNum = 0;
      var qtyExtraNum = parseFloat(line.qty_extra);
      if (!isFinite(qtyExtraNum) || qtyExtraNum < 0) qtyExtraNum = 0;
      var qtyExtraSoldNum = parseFloat(line.qty_extra_sold);
      if (!isFinite(qtyExtraSoldNum)) qtyExtraSoldNum = 0;
      var lineTotalSold = parseFloat(line.line_total);
      if (!isFinite(lineTotalSold)) {
        // تقدير من سعر الوحدة × الكمية المباعة إن لم يُرجع line_total
        lineTotalSold = unitPriceNum * qtySoldNum;
      }
      var tr = createInvoiceLineRow(
        {
          invoice_line_id: line.invoice_line_id,
          item_id: line.item_id,
          qty_remaining: qtyNum,
          qty_extra_remaining: qtyExtraNum,
          unit_price: unitPriceNum,
          tax_rate_percent: taxRateNum,
          name_ar: line.name_ar,
          line_desc: line.line_desc,
          barcode: line.barcode,
          qty_sold: qtySoldNum,
          qty_extra_sold: qtyExtraSoldNum,
          line_total: lineTotalSold,
        },
        { picked: true, qty: qtyNum, qtyExtra: qtyExtraNum }
      );
      if (tr) {
        // استخدم مبالغ السطر المحفوظة إن وُجدت (أدق من إعادة الحساب عند نقص بيانات الفاتورة)
        var savedSub = parseFloat(line.line_subtotal);
        var savedTax = parseFloat(line.tax_amount);
        var savedGross = parseFloat(line.line_gross);
        if (isFinite(savedSub) || isFinite(savedTax) || isFinite(savedGross)) {
          var sub = isFinite(savedSub) ? savedSub : 0;
          var tax = isFinite(savedTax) ? savedTax : 0;
          var gross = isFinite(savedGross) ? savedGross : roundMoney(sub + tax);
          tr.querySelector('.js-line-sub').textContent = fmt(sub);
          tr.querySelector('.js-tax-amt').textContent = fmt(tax);
          tr.querySelector('.js-line-gross').textContent = fmt(gross);
          tr.setAttribute('data-line-sub', String(sub));
          tr.setAttribute('data-line-tax', String(tax));
          tr.setAttribute('data-line-gross', String(gross));
        }
        tbody.appendChild(tr);
      }
    });
    if (ret.subtotal != null && sumSub) sumSub.textContent = fmt(ret.subtotal);
    if (ret.tax_amount != null && sumTax) sumTax.textContent = fmt(ret.tax_amount);
    if (ret.total != null && sumGrand) sumGrand.textContent = fmt(ret.total);
    setSavedMode(true);
    renumberRows();
    syncJson();
    if (ledgerView) {
      setSavedMode(true);
      setHint('عرض من كشف حركات مادة — للعودة استخدم «كشف حركات مادة».');
    } else if (returnIsPosted) {
      setHint('مرتجع محفوظ ومرحّل — يمكنك الطباعة أو التنقل بالأسهم.');
    } else {
      setHint('مرتجع محفوظ — لم يُكتمل الترحيل. استخدم «ترحيل» لإتمام الأثر المالي والمستودعي.');
    }
  }

  /**
   * تحميل مواد الفاتورة لمرتجع غير مرحّل مع تحديد الأسطر المحفوظة مسبقاً.
   * يتيح إضافة مواد أخرى من نفس الفاتورة.
   */
  function applyEditableReturnCatalog(ret, catalogLines) {
    var pickedByLine = {};
    (ret.lines || []).forEach(function (line) {
      var lid = parseInt(line.invoice_line_id, 10) || 0;
      if (lid < 1) return;
      pickedByLine[lid] = {
        qty: line.qty,
        qtyExtra: line.qty_extra,
        line_subtotal: line.line_subtotal,
        tax_amount: line.tax_amount,
        line_gross: line.line_gross,
      };
    });
    availableLines = catalogLines || [];
    if (tbody) tbody.innerHTML = '';
    availableLines.forEach(function (line) {
      var lid = parseInt(line.invoice_line_id, 10) || 0;
      var picked = pickedByLine[lid];
      var tr = createInvoiceLineRow(line, {
        picked: !!picked,
        qty: picked ? picked.qty : null,
        qtyExtra: picked ? picked.qtyExtra : null,
      });
      if (tr && picked) {
        var savedSub = parseFloat(picked.line_subtotal);
        var savedTax = parseFloat(picked.tax_amount);
        var savedGross = parseFloat(picked.line_gross);
        if (isFinite(savedSub) || isFinite(savedTax) || isFinite(savedGross)) {
          var sub = isFinite(savedSub) ? savedSub : 0;
          var tax = isFinite(savedTax) ? savedTax : 0;
          var gross = isFinite(savedGross) ? savedGross : roundMoney(sub + tax);
          tr.querySelector('.js-line-sub').textContent = fmt(sub);
          tr.querySelector('.js-tax-amt').textContent = fmt(tax);
          tr.querySelector('.js-line-gross').textContent = fmt(gross);
          tr.setAttribute('data-line-sub', String(sub));
          tr.setAttribute('data-line-tax', String(tax));
          tr.setAttribute('data-line-gross', String(gross));
        }
      }
      if (tr) tbody.appendChild(tr);
    });
    // أسطر محفوظة لم تعد في الكتالوج (نادر) — أظهرها مقروءة
    (ret.lines || []).forEach(function (line) {
      var lid = parseInt(line.invoice_line_id, 10) || 0;
      if (lid < 1 || lineUsed(lid)) return;
      var qtyNum = parseFloat(line.qty) || 0;
      var qtyExtraNum = parseFloat(line.qty_extra) || 0;
      var tr = createInvoiceLineRow(
        {
          invoice_line_id: line.invoice_line_id,
          item_id: line.item_id,
          qty_remaining: qtyNum,
          qty_extra_remaining: qtyExtraNum,
          unit_price: line.unit_price,
          tax_rate_percent: line.tax_rate_percent,
          name_ar: line.name_ar,
          line_desc: line.line_desc,
          barcode: line.barcode,
          qty_sold: line.qty_sold,
          qty_extra_sold: line.qty_extra_sold,
          line_total: line.line_total,
        },
        { picked: true, qty: qtyNum, qtyExtra: qtyExtraNum }
      );
      if (tr) tbody.appendChild(tr);
    });
    setSavedMode(false);
    setSourceLocked(true);
    renumberRows();
    recalcFooter();
    syncJson();
    updatePickAllState();
    setHint(
      'مرتجع غير مرحّل — يمكنك تحديد ☑ مواد إضافية من الفاتورة أو تعديل الكميات ثم الحفظ، أو استخدم «ترحيل».'
    );
  }

  function loadEditableReturnLines(ret) {
    var invoiceId = ret.invoice_id;
    var customerId = ret.customer_id;
    var returnId = parseInt(ret.id, 10) || 0;
    if (!invoiceId) {
      applySavedReturnLines(ret);
      return;
    }
    setHint('جاري تحميل مواد الفاتورة للتعديل…');
    var params = {
      invoice_id: invoiceId,
      customer_id: customerId || '',
    };
    if (returnId > 0) {
      params.exclude_return_id = returnId;
    }
    fetch(buildApiUrl(apiLines, params), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          applySavedReturnLines(ret);
          return;
        }
        if (typeof window.SalesRetSetLinesForInvoice === 'function') {
          window.SalesRetSetLinesForInvoice(invoiceId, data.lines || []);
        }
        applyEditableReturnCatalog(ret, data.lines || []);
      })
      .catch(function () {
        applySavedReturnLines(ret);
      });
  }

  function getEmbeddedLines(invoiceId) {
    var iid = String(invoiceId);
    var map = null;
    if (typeof window.SalesRetGetLinesByInvoice === 'function') {
      map = window.SalesRetGetLinesByInvoice();
    } else {
      var el = document.getElementById('sales-ret-lines-by-invoice');
      if (!el) return null;
      try {
        map = JSON.parse(el.textContent || '{}');
      } catch (e) {
        return null;
      }
    }
    if (!map || typeof map !== 'object') return null;
    if (
      !Object.prototype.hasOwnProperty.call(map, iid) &&
      !Object.prototype.hasOwnProperty.call(map, String(parseInt(iid, 10)))
    ) {
      return null;
    }
    var list = map[iid] || map[parseInt(iid, 10)];
    return Array.isArray(list) ? list : [];
  }

  /** عرض مواد الفاتورة في الجدول — الاختيار بـ checkbox فقط. */
  function populateInvoiceLines(lines) {
    availableLines = lines || [];
    if (tbody) tbody.innerHTML = '';
    if (!availableLines.length) {
      recalcFooter();
      syncJson();
      updatePickAllState();
      setHint('لا توجد مواد قابلة للإرجاع في هذه الفاتورة (أو تم إرجاعها بالكامل).');
      return;
    }
    availableLines.forEach(function (line) {
      var tr = createInvoiceLineRow(line, { picked: false });
      if (tr) tbody.appendChild(tr);
    });
    renumberRows();
    recalcFooter();
    syncJson();
    updatePickAllState();
    setHint('حدّد ☑ المواد المراد إرجاعها وعدّل كمية الإرجاع (والكمية الإضافية إن وُجدت) لكل مادة.');
  }

  window.SalesRetLoadCatalog = function (lines) {
    if (isSavedMode) return;
    populateInvoiceLines(lines || []);
  };
  window.SalesRetPopulateInvoiceLines = window.SalesRetLoadCatalog;

  window.SalesRetFetchCatalog = function (invoiceId) {
    if (isSavedMode) return;
    var cid = customerSel ? customerSel.value : '';
    loadCatalogLines(invoiceId, cid);
  };

  function loadCatalogLines(invoiceId, customerId) {
    if (!invoiceId) {
      availableLines = [];
      clearLines();
      return;
    }
    var embedded = getEmbeddedLines(invoiceId);
    if (embedded !== null && embedded.length > 0) {
      populateInvoiceLines(embedded);
      return;
    }
    // لا تعتمد على مصفوفة فارغة مضمّنة — قد تكون الفاتورة محمّلة عبر AJAX بلا بنود
    if (tbody) tbody.innerHTML = '';
    setHint('جاري تحميل مواد الفاتورة…');
    var params = { invoice_id: invoiceId, customer_id: customerId || '' };
    if (currentReturnId > 0 && !returnIsPosted) {
      params.exclude_return_id = currentReturnId;
    }
    fetch(buildApiUrl(apiLines, params), {
      credentials: 'same-origin',
    })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          availableLines = [];
          if (tbody) tbody.innerHTML = '';
          recalcFooter();
          syncJson();
          updatePickAllState();
          var msg = data.message || 'تعذر تحميل بنود الفاتورة.';
          setHint(msg);
          if (data.message) {
            AppDialog.alert(data.message, { type: 'warning' });
          }
          return;
        }
        if (typeof window.SalesRetSetLinesForInvoice === 'function') {
          window.SalesRetSetLinesForInvoice(invoiceId, data.lines || []);
        }
        populateInvoiceLines(data.lines || []);
      })
      .catch(function () {
        setHint('تعذر تحميل بنود الفاتورة.');
      });
  }

  function updateReturnNoPostedStyle() {
    if (!retNoInp) return;
    retNoInp.classList.remove('is-posted', 'is-unposted');
    if (currentReturnId < 1) return;
    if (returnIsPosted) {
      retNoInp.classList.add('is-posted');
    } else {
      retNoInp.classList.add('is-unposted');
    }
  }

  function updatePostedBadge() {
    var el = document.getElementById('ret_posted_badge');
    var einvEl = document.getElementById('ret_einv_badge');
    if (currentReturnId < 1) {
      if (el) el.hidden = true;
      if (einvEl) einvEl.hidden = true;
      updateReturnNoPostedStyle();
      updateReturnEinvButtonState();
      updateReasonReturnVisibility();
      return;
    }
    if (el) {
      el.hidden = false;
      if (returnIsPosted) {
        el.textContent = 'مرحّلة';
        el.className = 'sales-inv-posted-badge badge badge-ok';
      } else {
        el.textContent = 'غير مرحّلة';
        el.className = 'sales-inv-posted-badge badge badge-warn';
      }
    }
    if (einvEl) {
      if (!returnEinvTrackingRequired) {
        einvEl.hidden = false;
        einvEl.textContent = 'قبل نطاق الفوترة';
        einvEl.className = 'sales-inv-posted-badge badge badge-off';
      } else if (returnEinvSent) {
        einvEl.hidden = false;
        einvEl.textContent = 'مُرسَل للفوترة' + (returnEinvNum ? ' (' + returnEinvNum + ')' : '');
      } else {
        einvEl.hidden = true;
      }
    }
    updateReturnNoPostedStyle();
    updateReturnEinvButtonState();
    updateToolbarUnpostButton();
    updateReasonReturnVisibility();
    if (global.FinVoucherArchive) {
      global.FinVoucherArchive.syncToolbar();
    }
  }

  function updateToolbarUnpostButton() {
    var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
    if (!unpostBtn) return;
    var canUnpost = canUnpostByPermission && currentReturnId > 0 && returnIsPosted && !returnEinvSent;
    unpostBtn.disabled = !canUnpost;
    unpostBtn.classList.toggle('is-inactive', !canUnpost);
    if (returnEinvSent) {
      unpostBtn.title = 'لا يمكن فك ترحيل مرتجع أُرسل إلى نظام الفوترة.';
    } else if (canUnpost) {
      unpostBtn.title = 'فك ترحيل المرتجع (عكس القيود والمستودع وذمة العميل)';
    } else if (currentReturnId < 1) {
      unpostBtn.title = 'احفظ المرتجع أولاً.';
    } else {
      unpostBtn.title = 'المرتجع غير مرحّل.';
    }
  }

  function updateReasonReturnVisibility() {
    if (!retReasonReturnWrap) return;
    var show = returnEinvTrackingRequired && originalInvoiceEinvSent && !returnEinvSent;
    retReasonReturnWrap.hidden = !show;
  }

  function updateReturnEinvButtonState() {
    var btn = document.querySelector('#master-toolbar [data-master-action="send_return_einvoice"]');
    if (!btn) return;
    var disable = false;
    var tooltip = '';
    if (currentReturnId < 1) {
      disable = true;
      tooltip = 'احفظ المرتجع أولاً.';
    } else if (returnEinvSent) {
      disable = true;
      tooltip = 'هذا المرتجع تم إرساله للفوترة مسبقًا — لا يمكن إعادة الإرسال.';
    } else if (!originalInvoiceEinvSent) {
      disable = true;
      tooltip = 'لا يمكن إرسال الإرجاع للفوترة قبل إرسال الفاتورة الأصلية للفوترة.';
    } else if (!returnIsPosted) {
      disable = true;
      tooltip = 'يجب ترحيل المرتجع قبل إرساله للفوترة.';
    } else if (!returnEinvTrackingRequired) {
      disable = true;
      tooltip = 'مرتجع قبل تاريخ متابعة الفوترة في النظام (01-06-2026).';
    }
    if (disable) {
      btn.disabled = true;
      btn.setAttribute('title', tooltip);
      btn.style.opacity = '0.55';
      btn.style.cursor = 'not-allowed';
    } else {
      btn.disabled = false;
      btn.removeAttribute('title');
      btn.style.opacity = '';
      btn.style.cursor = '';
    }
  }

  function refreshCustomerReturnCaches(customerId, invoiceId) {
    var cid =
      customerId != null && customerId !== ''
        ? String(customerId)
        : customerSel
          ? customerSel.value
          : '';
    if (!cid || !apiInvoices) {
      return Promise.resolve();
    }
    var invId =
      invoiceId != null && invoiceId !== ''
        ? String(invoiceId)
        : invoiceSel
          ? invoiceSel.value
          : '';
    return fetch(buildApiUrl(apiInvoices, { customer_id: cid }), { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        var list = data && data.ok && data.invoices ? data.invoices : [];
        if (typeof window.SalesRetSetInvoicesForCustomer === 'function') {
          window.SalesRetSetInvoicesForCustomer(cid, list);
        }
        if (!invId || !apiLines) {
          return;
        }
        return fetch(
          buildApiUrl(apiLines, { invoice_id: invId, customer_id: cid }),
          { credentials: 'same-origin' }
        )
          .then(function (r) {
            return r.json();
          })
          .then(function (ld) {
            var lines = ld && ld.ok && ld.lines ? ld.lines : [];
            if (typeof window.SalesRetSetLinesForInvoice === 'function') {
              window.SalesRetSetLinesForInvoice(invId, lines);
            }
          });
      })
      .catch(function () {});
  }

  /** بعد حذف مرتجع غير مرحّل: تحديث الكميات المتاحة وإعادة فتح الفاتورة للإرجاع. */
  function reopenReturnEntryForInvoice(customerId, invoiceId) {
    if (!customerId || !customerSel) {
      return Promise.resolve();
    }
    var cid = String(customerId);
    var iid = invoiceId ? String(invoiceId) : '';
    return refreshCustomerReturnCaches(cid, iid).then(function () {
      setCustomerId(cid, true);
      if (typeof window.SalesRetPickCustomer === 'function') {
        window.SalesRetPickCustomer(customerSel);
      } else {
        return loadInvoices(cid, iid || null, { forceFetch: true });
      }
      if (iid && invoiceSel) {
        invoiceSel.value = iid;
        if (typeof window.SalesRetPickInvoice === 'function') {
          window.SalesRetPickInvoice(invoiceSel);
        } else {
          loadCatalogLines(iid, cid);
        }
      }
    });
  }

  function postCurrentReturn() {
    if (!returnPostUrl) {
      AppDialog.alert('الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentReturnId < 1) {
      AppDialog.alert('احفظ المرتجع أولًا قبل الترحيل.', { type: 'warning' });
      return;
    }
    if (returnIsPosted) {
      AppDialog.alert('هذا المرتجع مرحّل مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm(
      'ترحيل هذا المرتجع؟\nسيتم إرجاع الكميات إلى المستودع وتخفيض ذمة العميل (عكس الفاتورة).',
      { title: 'ترحيل المرتجع' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('return_id', String(currentReturnId));
      if (window.AppBusy) AppBusy.show('جاري ترحيل المرتجع...');
      fetch(returnPostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            var errMsg =
              data.error ||
              data.message ||
              (data.errors && data.errors.length ? data.errors.join('\n') : '') ||
              'تعذر الترحيل.';
            AppDialog.error(errMsg);
            return;
          }
          returnIsPosted = true;
          if (lastLoadedReturn) lastLoadedReturn.is_posted = 1;
          updatePostedBadge();
          setHint('مرتجع محفوظ ومرحّل — تم إرجاع المخزون وتخفيض ذمة العميل.');
          if (currentReturnId > 0) {
            loadReturn({ id: currentReturnId });
          }
          refreshCustomerReturnCaches().then(function () {
            if (customerSel && typeof window.SalesRetPickCustomer === 'function') {
              window.SalesRetPickCustomer(customerSel);
            }
          });
          if (data.warning || (data.warnings && data.warnings.length)) {
            AppDialog.alert(data.warning || data.warnings.join('\n'), { type: 'warning' }).then(function () {
              AppDialog.success(data.message || 'تم الترحيل.');
            });
          } else {
            AppDialog.success(data.message || 'تم الترحيل.');
          }
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        })
        .finally(function () {
          if (window.AppBusy) AppBusy.hide();
        });
    });
  }

  function unpostCurrentReturn() {
    if (!canUnpostByPermission) {
      AppDialog.alert('ليس لديك صلاحية فك ترحيل مرتجع المبيعات.', { type: 'warning' });
      return;
    }
    if (!returnUnpostUrl) {
      AppDialog.alert('فك الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentReturnId < 1) {
      AppDialog.alert('احفظ المرتجع أولًا.', { type: 'warning' });
      return;
    }
    if (returnEinvSent) {
      AppDialog.alert('لا يمكن فك ترحيل مرتجع أُرسل إلى نظام الفوترة.', { type: 'warning' });
      return;
    }
    if (!returnIsPosted) {
      AppDialog.alert('هذا المرتجع غير مرحّل.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var msg =
      '<p><strong>سيتم فك ترحيل المرتجع:</strong></p>' +
      '<ul>' +
      '<li>إلغاء القيد المحاسبي تلقائياً.</li>' +
      '<li>عكس حركات إدخال المخزون (إرجاع الكميات من المستودع).</li>' +
      '<li>إلغاء أثر حساب العميل (الذمم المدينة).</li>' +
      '</ul>' +
      '<p>بعد فك الترحيل يمكنك تعديل المرتجع وإعادة ترحيله.</p>' +
      '<p class="ui-dialog-question">متابعة؟</p>';
    AppDialog.confirm(msg, {
      title: 'فك ترحيل المرتجع',
      okText: 'فك الترحيل',
      danger: true,
      html: true,
    }).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('return_id', String(currentReturnId));
      if (window.AppBusy) AppBusy.show('جاري فك ترحيل المرتجع...');
      fetch(returnUnpostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            AppDialog.error((data && (data.error || data.message)) || 'تعذر فك الترحيل.');
            return;
          }
          returnIsPosted = false;
          AppDialog.success(data.message || 'تم فك الترحيل.');
          if (currentReturnId > 0) {
            loadReturn({ id: currentReturnId });
          }
          refreshCustomerReturnCaches();
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        })
        .finally(function () {
          if (window.AppBusy) AppBusy.hide();
        });
    });
  }

  function populateFromReturn(ret) {
    lastLoadedReturn = ret;
    currentReturnId = parseInt(ret.id, 10) || 0;
    returnIsPosted =
      ret.is_posted === 1 || ret.is_posted === true || ret.is_posted === '1';
    returnEinvQr = ret && ret.einv_qr ? String(ret.einv_qr) : '';
    returnEinvQrDataUrl = '';
    returnEinvSent = !!(ret && (ret.einv_sent || ret.einv_qr));
    returnEinvNum = ret && ret.einv_num ? String(ret.einv_num) : '';
    returnEinvTrackingRequired =
      !ret || ret.einv_tracking_required === undefined || ret.einv_tracking_required === null
        ? true
        : !!ret.einv_tracking_required;
    originalInvoiceEinvSent = !!(
      ret && (ret.invoice_einv_sent || ret.invoice_einv_qr || ret.invoice_einv_legacy)
    );
    originalInvoiceEinvNum = ret && ret.invoice_einv_num ? String(ret.invoice_einv_num) : '';
    originalInvoiceUuid = ret && ret.original_invoice_uuid ? String(ret.original_invoice_uuid).trim() : '';
    originalInvoiceNoForEinvoice =
      ret && ret.original_invoice_no_for_einvoice
        ? String(ret.original_invoice_no_for_einvoice).trim()
        : ret && ret.invoice_no
          ? String(ret.invoice_no).trim()
          : '';
    needsOriginalInvoiceUuid = !!(ret && ret.needs_original_uuid) || originalInvoiceUuid === '';
    try {
      console.log('[einvoice-return] loadReturn flags', {
        id: currentReturnId,
        returnEinvSent: returnEinvSent,
        returnEinvNum: returnEinvNum,
        originalInvoiceEinvSent: originalInvoiceEinvSent,
        ret_einv_qr_present: !!(ret && ret.einv_qr),
        ret_einv_num: ret && ret.einv_num,
        ret_einv_sent: ret && ret.einv_sent,
        ret_invoice_einv_qr_present: !!(ret && ret.invoice_einv_qr),
      });
    } catch (_e) {}
    updatePostedBadge();
    if (recordIdInp) recordIdInp.value = String(currentReturnId);
    if (retNoInp) retNoInp.value = ret.return_no || '';
    if (retDateInp) retDateInp.value = fmtDate(ret.return_date || '');
    if (retNotes) retNotes.value = ret.notes || '';
    if (retReasonReturn) retReasonReturn.value = ret.reason_return || '';
    if (returnEinvQr) {
      refreshReturnEinvQrDataUrl();
    }
    setCustomerId(ret.customer_id || 0, true);
    applyBrowseNavFromPayload(ret);
    loadInvoices(ret.customer_id, ret.invoice_id, {
      skipCatalog: true,
      keepLines: true,
      onReady: function () {
        if (!invoiceSel || !ret.invoice_id) {
          finishPopulateReturnLines(ret);
          return;
        }
        if (!invoiceSel.value) {
          var o = document.createElement('option');
          o.value = String(ret.invoice_id);
          o.textContent = ret.invoice_no
            ? ret.invoice_no + (ret.invoice_date ? ' — ' + fmtDate(ret.invoice_date) : '')
            : 'فاتورة #' + ret.invoice_id;
          o.selected = true;
          invoiceSel.appendChild(o);
          invoiceSel.disabled = true;
        }
        finishPopulateReturnLines(ret);
      },
    });
  }

  function finishPopulateReturnLines(ret) {
    var posted =
      ret.is_posted === 1 || ret.is_posted === true || ret.is_posted === '1';
    var einvSent = !!(ret && (ret.einv_sent || ret.einv_qr));
    if (ledgerView || posted || einvSent) {
      applySavedReturnLines(ret);
      return;
    }
    loadEditableReturnLines(ret);
  }

  function fetchReturnResponse(opts) {
    if (!apiReturn) return Promise.resolve(null);
    var url = apiReturn;
    if (opts.id) url += '?id=' + encodeURIComponent(opts.id);
    else if (opts.no) url += '?no=' + encodeURIComponent(opts.no);
    else if (opts.dir && opts.fromId) {
      url += '?id=' + encodeURIComponent(opts.fromId) + '&dir=' + encodeURIComponent(opts.dir);
    } else if (opts.edge === 'first') url += '?edge=first';
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  function applyReturnFetchData(data) {
    if (!data || !data.ok) {
      if (data && (data.error === 'no_neighbor' || data.error === 'not_found')) {
        AppDialog.alert(data.message || 'غير موجود.', { type: 'info' });
      }
      return;
    }
    if (!ledgerView) {
      setSavedMode(false);
    }
    populateFromReturn(data.return);
    if (window.history && window.history.replaceState && currentReturnId > 0) {
      var u = new URL(window.location.href);
      u.searchParams.set('id', String(currentReturnId));
      window.history.replaceState({}, '', u.pathname + u.search);
    }
  }

  function loadReturn(opts) {
    fetchReturnResponse(opts).then(applyReturnFetchData);
  }

  function navigateEmptyReturn(dir) {
    var opts = {
      browseNavPrevId: browseNavPrevId,
      browseNavNextId: browseNavNextId,
      fetchById: function (id) {
        return fetchReturnResponse({ id: id });
      },
      fetchLatest: function () {
        return fetchReturnResponse({ edge: 'first' });
      },
      isOk: function (data) {
        return !!(data && data.ok && data.return);
      },
      getPayload: function (data) {
        return data.return;
      },
      apply: function (ret) {
        applyReturnFetchData({ ok: true, return: ret });
      },
      emptyMessage: 'لا توجد مرتجعات محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر مرتجع.',
      loadError: 'تعذر تحميل المرتجع.',
    };
    if (window.DocumentNoNav) {
      return DocumentNoNav.navigateEmpty(dir, opts);
    }
    loadReturn({ edge: 'first' });
  }

  function navigateReturn(dir) {
    if (currentReturnId < 1) {
      navigateEmptyReturn(dir);
      return;
    }
    if (window.DocumentNoNav && DocumentNoNav.isSearchActive(docNoSearch)) {
      DocumentNoNav.navigateSearchMatch(dir, docNoSearch, {
        fetchById: function (id) {
          return fetchReturnResponse({ id: id });
        },
        isOk: function (data) {
          return !!(data && data.ok && data.return);
        },
        getPayload: function (data) {
          return data.return;
        },
        apply: function (ret) {
          applyReturnFetchData({ ok: true, return: ret });
        },
        loadError: 'تعذر تحميل المرتجع.',
      });
      return;
    }
    loadReturn({ fromId: currentReturnId, dir: dir });
  }

  function runToolbarReturnSearch() {
    var no = retNoInp ? String(retNoInp.value || '').trim() : '';
    if (!no) {
      AppDialog.alert('أدخل رقم المرتجع ثم اضغط بحث.', { type: 'warning' });
      if (retNoInp) retNoInp.focus();
      return;
    }
    loadReturn({ no: no });
  }

  function trySave() {
    if (returnEinvSent) {
      AppDialog.alert('لا يمكن تعديل مرتجع أُرسل إلى نظام الفوترة.', { type: 'warning' });
      return;
    }
    if (isSavedMode) {
      if (!returnIsPosted) {
        AppDialog.alert('المرتجع محفوظ. استخدم زر «ترحيل» لإتمام الأثر المالي والمستودعي.', { type: 'info' });
      } else {
        AppDialog.alert('المرتجع محفوظ ومرحّل. لمرتجع جديد استخدم «+ مرتجع جديد».', { type: 'info' });
      }
      return;
    }
    if (!customerSel || !customerSel.value) {
      AppDialog.alert('اختر العميل.', { type: 'warning' });
      if (customerOpenBtn && !customerOpenBtn.disabled) customerOpenBtn.focus();
      return;
    }
    if (!invoiceSel.value) {
      AppDialog.alert('اختر فاتورة البيع.', { type: 'warning' });
      return;
    }
    syncJson();
    var lines = JSON.parse(linesJson.value || '[]');
    if (!lines.length) {
      AppDialog.alert('حدّد مادة واحدة على الأقل (☑) وأدخل كمية إرجاع أو كمية إضافية.', { type: 'warning' });
      return;
    }
    if (recordIdInp && currentReturnId > 0) {
      recordIdInp.value = String(currentReturnId);
    }
    form.submit();
  }

  function updateReturnHistory() {
    if (!window.history || !window.history.replaceState) return;
    var base = newReturnUrl || window.location.pathname + '?r=sales_returns';
    try {
      var u = new URL(base, window.location.href);
      var href = appendLedgerReturnQs(u.pathname + u.search);
      window.history.replaceState({ returnId: 0 }, '', href);
    } catch (e) {
      var path = window.location.pathname;
      window.history.replaceState({}, '', appendLedgerReturnQs(path + '?r=sales_returns'));
    }
  }

  function hasDraftContent() {
    if (currentReturnId > 0 || isSavedMode) {
      return false;
    }
    if (customerSel && customerSel.value) return true;
    if (invoiceSel && invoiceSel.value) return true;
    return getDataRows().some(function (tr) {
      return isRowPicked(tr);
    });
  }

  function initNewReturn() {
    if (window.DocumentNoNav) DocumentNoNav.clearSearch(docNoSearch);
    currentReturnId = 0;
    returnIsPosted = false;
    lastLoadedReturn = null;
    setSavedMode(false);
    if (recordIdInp) recordIdInp.value = '';
    if (retNoInp) retNoInp.value = '';
    var defaultDate = form.getAttribute('data-default-date') || '';
    if (retDateInp && defaultDate) retDateInp.value = defaultDate;
    if (retNotes) retNotes.value = '';
    if (retReasonReturn) retReasonReturn.value = '';
    returnEinvQr = '';
    returnEinvQrDataUrl = '';
    returnEinvSent = false;
    returnEinvNum = '';
    originalInvoiceEinvSent = false;
    originalInvoiceEinvNum = '';
    originalInvoiceUuid = '';
    originalInvoiceNoForEinvoice = '';
    needsOriginalInvoiceUuid = false;
    setCustomerId(0, true);
    resetInvoiceSelect('— اختر العميل أولًا —');
    clearLines();
    updatePostedBadge();
    refreshEmptyBrowseNav();
    var pickAllCb = document.getElementById('sales-ret-pick-all');
    if (pickAllCb) pickAllCb.checked = false;
    if (hint && defaultHintHtml) {
      hint.innerHTML = defaultHintHtml;
    }
    updateReturnHistory();
    if (customerOpenBtn && !customerOpenBtn.disabled) customerOpenBtn.focus();
  }

  function isOraUi() {
    return document.body && document.body.classList.contains('hr-ora-ui');
  }

  function confirmLeaveScreen(onProceed, onCancel) {
    if (isSavedMode || currentReturnId > 0) {
      if (onProceed) onProceed();
      return;
    }
    if (!hasDraftContent()) {
      if (onProceed) onProceed();
      return;
    }
    if (isOraUi() && window.AppDialog && typeof AppDialog.confirmSaveDiscard === 'function') {
      AppDialog.confirmSaveDiscard('هل تريد حفظ التغييرات قبل مغادرة الشاشة؟', {
        title: 'حفظ التغييرات',
        saveText: 'نعم، احفظ',
        discardText: 'لا، بدون حفظ',
        cancelText: 'إلغاء',
        theme: 'oracle',
      }).then(function (choice) {
        if (choice === 'save') {
          trySave();
        } else if (choice === 'discard') {
          if (onProceed) onProceed();
        } else if (onCancel) {
          onCancel();
        }
      });
      return;
    }
    AppDialog.confirm('هل تريد حفظ التغييرات قبل المغادرة؟', {
      title: 'تغييرات غير محفوظة',
      okText: 'نعم، احفظ',
      cancelText: 'لا، اخرج بدون حفظ',
    }).then(function (saveFirst) {
      if (saveFirst) {
        trySave();
      } else if (onProceed) {
        onProceed();
      }
    });
  }

  function confirmNewReturn(onProceed) {
    if (!hasDraftContent()) {
      if (onProceed) onProceed();
      return;
    }
    AppDialog.confirm('فتح مرتجع جديد؟ ستُمسح البيانات الحالية على الشاشة.', {
      title: 'مرتجع جديد',
      okText: 'نعم',
      theme: isOraUi() ? 'oracle' : '',
    }).then(function (ok) {
      if (ok && onProceed) onProceed();
    });
  }

  function newReturn() {
    initNewReturn();
  }

  initCustomerPicker();

  if (invoiceSel) {
    invoiceSel.addEventListener('change', function () {
      if (isSavedMode) return;
      if (typeof window.SalesRetPickInvoice === 'function') {
        window.SalesRetPickInvoice(invoiceSel);
        return;
      }
      var iid = invoiceSel.value;
      var cid = customerSel ? customerSel.value : '';
      if (!iid || !cid) {
        clearLines();
        return;
      }
      loadCatalogLines(iid, cid);
    });
  }

  document.addEventListener('sales-ret-invoice-picked', function (e) {
    if (isSavedMode || !e.detail) return;
    // عند fetched:false يتم الجلب عبر SalesRetFetchCatalog — لا تفرّغ الجدول هنا
    if (e.detail.fetched === false || e.detail.cleared) return;
    populateInvoiceLines(e.detail.lines || []);
  });

  var pickAllCb = document.getElementById('sales-ret-pick-all');
  if (pickAllCb) {
    pickAllCb.addEventListener('change', function () {
      if (isSavedMode) {
        pickAllCb.checked = false;
        return;
      }
      var on = pickAllCb.checked;
      getDataRows().forEach(function (tr) {
        setRowPicked(tr, on);
      });
      recalcFooter();
      syncJson();
    });
  }

  if (retNoInp) {
    retNoInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        runToolbarReturnSearch();
      }
    });
  }

  var prevBtn = document.getElementById('ret_no_prev');
  var nextBtn = document.getElementById('ret_no_next');
  if (prevBtn) prevBtn.addEventListener('click', function () {
    navigateReturn('prev');
  });
  if (nextBtn) nextBtn.addEventListener('click', function () {
    navigateReturn('next');
  });

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    var action = e.detail.action;

    if (ledgerView && action !== 'exit' && action !== 'print' && action !== 'pdf' && action !== 'excel') {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }

    if (action === 'search') {
      e.preventDefault();
      runToolbarReturnSearch();
    } else if (action === 'save') {
      e.preventDefault();
      trySave();
    } else if (action === 'post') {
      e.preventDefault();
      postCurrentReturn();
    } else if (action === 'unpost') {
      e.preventDefault();
      unpostCurrentReturn();
    } else if (action === 'print') {
      e.preventDefault();
      handleToolbarPrint();
    } else if (action === 'pdf') {
      e.preventDefault();
      downloadReturnPdf();
    } else if (action === 'delete') {
      e.preventDefault();
      if (currentReturnId > 0) {
        if (returnIsPosted) {
          AppDialog.alert(
            returnEinvSent
              ? 'لا يمكن حذف مرتجع أُرسل إلى نظام الفوترة.'
              : 'لا يمكن حذف مرتجع مرحّل.',
            { type: 'warning' }
          );
          return;
        }
        if (returnEinvSent) {
          AppDialog.alert('لا يمكن حذف مرتجع أُرسل إلى نظام الفوترة.', { type: 'warning' });
          return;
        }
        var retId =
          currentReturnId > 0
            ? currentReturnId
            : recordIdInp
              ? parseInt(recordIdInp.value, 10)
              : 0;
        if (retId < 1) {
          confirmNewReturn(initNewReturn);
          return;
        }
        var retLabel =
          retNoInp && retNoInp.value ? retNoInp.value : String(retId);
        if (!returnDeleteUrl) {
          AppDialog.error('حذف المرتجع غير متاح.');
          return;
        }
        AppDialog.confirm(
          'حذف المرتجع «' +
            retLabel +
            '» نهائياً؟\nلا يمكن التراجع. يُسمح فقط بالمرتجعات غير المرحّلة.',
          { title: 'حذف المرتجع', danger: true, okText: 'حذف' }
        ).then(function (ok) {
          if (!ok) return;
          var fd = new FormData();
          var csrfInp = form.querySelector('input[name="_csrf"]');
          fd.append('_csrf', csrfInp ? csrfInp.value : '');
          fd.append('return_id', String(retId));
          fetch(returnDeleteUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              if (!data.ok) {
                var errMsg =
                  data.error ||
                  (data.errors && data.errors.length ? data.errors.join('؛ ') : '') ||
                  data.message ||
                  'تعذر حذف المرتجع.';
                AppDialog.error(errMsg);
                return;
              }
              var cidBefore = customerSel ? customerSel.value : '';
              var iidBefore = invoiceSel ? invoiceSel.value : '';
              if ((!cidBefore || !iidBefore) && lastLoadedReturn) {
                if (!cidBefore) cidBefore = String(lastLoadedReturn.customer_id || '');
                if (!iidBefore) iidBefore = String(lastLoadedReturn.invoice_id || '');
              }
              AppDialog.success(data.message || 'تم حذف المرتجع.').then(function () {
                initNewReturn();
                if (cidBefore) {
                  reopenReturnEntryForInvoice(cidBefore, iidBefore);
                }
              });
            })
            .catch(function () {
              AppDialog.error('تعذر الاتصال بالخادم.');
            });
        });
        return;
      }
      confirmNewReturn(initNewReturn);
    } else if (action === 'send_email') {
      e.preventDefault();
      sendReturnByEmail();
    } else if (action === 'send_return_einvoice') {
      e.preventDefault();
      try {
        sendCurrentReturnToEinvoice();
      } catch (err) {
        try { console.error('send_return_einvoice failed:', err); } catch (_e) {}
        AppDialog.error('خطأ داخلي: ' + (err && err.message ? err.message : err));
      }
    } else if (action === 'exit') {
      e.preventDefault();
      var bar = document.getElementById('master-toolbar');
      var url = bar ? bar.getAttribute('data-exit-url') : exitUrl;
      confirmLeaveScreen(function () {
        if (url) {
          window.location.href = url;
        } else {
          window.history.back();
        }
      });
    }
  });

  function sendCurrentReturnToEinvoice() {
    try { console.log('[einvoice-return] start', { currentReturnId: currentReturnId, returnIsPosted: returnIsPosted }); } catch (_e) {}
    var retId = currentReturnId > 0
      ? currentReturnId
      : recordIdInp
        ? parseInt(recordIdInp.value, 10)
        : 0;
    if (!retId || retId < 1) {
      AppDialog.alert('يجب حفظ المرتجع أولاً قبل إرساله للفوترة.', { type: 'warning' });
      return;
    }
    if (!returnIsPosted) {
      AppDialog.alert('يجب ترحيل المرتجع قبل إرساله للفوترة.', { type: 'warning' });
      return;
    }
    if (returnEinvSent) {
      AppDialog.alert(
        'هذا المرتجع تم إرساله للفوترة الإلكترونية مسبقًا' + (returnEinvNum ? ' (رقم: ' + returnEinvNum + ')' : '') + '.\nلا يمكن إعادة الإرسال.',
        { type: 'warning', title: 'مُرسَل مسبقًا' }
      );
      return;
    }
    if (!originalInvoiceEinvSent) {
      AppDialog.alert(
        'لا يمكن إرسال الإرجاع للفوترة الإلكترونية لأن الفاتورة الأصلية لم تُرسَل للفوترة.\n\nأرسل الفاتورة الأصلية للفوترة أولًا من شاشة فواتير المبيعات، ثم أعد المحاولة.',
        { type: 'warning', title: 'الفاتورة الأصلية غير مُرسَلة' }
      );
      return;
    }
    var savedReason = retReasonReturn ? String(retReasonReturn.value || '').trim() : '';
    if (!window.AppDialog || typeof AppDialog.prompt !== 'function') {
      try { console.error('[einvoice-return] AppDialog.prompt is missing'); } catch (_e) {}
      var fallbackReason = window.prompt('أدخل سبب الإرجاع (إلزامي للفوترة الإلكترونية):', savedReason);
      handleReturnReason(fallbackReason, retId);
      return;
    }
    AppDialog.prompt('أدخل سبب الإرجاع (إلزامي للفوترة الإلكترونية):', {
      title: 'إرجاع للفوترة',
      placeholder: 'مثال: المنتج معيب / لم يطابق الطلب / ...',
      value: savedReason,
      okText: 'إرسال',
    }).then(function (reason) {
      handleReturnReason(reason, retId);
    }).catch(function (err) {
      try { console.error('[einvoice-return] prompt error', err); } catch (_e) {}
      AppDialog.error('تعذر فتح نافذة إدخال السبب.');
    });
  }

  function looksLikeUuid(v) {
    return /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/.test(
      String(v || '').trim()
    );
  }

  /** رقم صالح للربط: EIN أو رقم النظام. */
  function looksLikeInvoiceRefNo(no) {
    var s = String(no || '').trim();
    return s.length >= 3;
  }

  function looksLikeEinvNum(no) {
    return /^EIN/i.test(String(no || '').trim());
  }

  function handleReturnReason(reason, retId) {
    if (reason === undefined || reason === null) return;
    reason = String(reason).trim();
    if (reason.length < 3) {
      AppDialog.error('السبب قصير جداً، يجب 3 أحرف على الأقل.');
      return;
    }
    var isLegacy = !!(lastLoadedReturn && lastLoadedReturn.invoice_einv_legacy);
    var needUuid = needsOriginalInvoiceUuid || !looksLikeUuid(originalInvoiceUuid);
    var needEinvNo = isLegacy && !looksLikeEinvNum(originalInvoiceNoForEinvoice) && !looksLikeEinvNum(originalInvoiceEinvNum);
    if (needUuid || needEinvNo) {
      promptOriginalInvoiceRefs(reason, retId, needUuid, needEinvNo);
      return;
    }
    var sendNo = looksLikeEinvNum(originalInvoiceNoForEinvoice)
      ? originalInvoiceNoForEinvoice
      : looksLikeEinvNum(originalInvoiceEinvNum)
        ? originalInvoiceEinvNum
        : originalInvoiceNoForEinvoice;
    confirmAndSubmitReturnEinvoice(reason, retId, originalInvoiceUuid, sendNo);
  }

  function promptOriginalInvoiceRefs(reason, retId, askUuid, askEinvNo) {
    if (askEinvNo === undefined) askEinvNo = true;
    var sysNo =
      lastLoadedReturn && lastLoadedReturn.invoice_no
        ? String(lastLoadedReturn.invoice_no).trim()
        : '';
    var invNoDefault = looksLikeEinvNum(originalInvoiceNoForEinvoice)
      ? originalInvoiceNoForEinvoice
      : looksLikeEinvNum(originalInvoiceEinvNum)
        ? originalInvoiceEinvNum
        : 'EIN00013';
    var uuidDefault = originalInvoiceUuid || '';

    function askNo(uuidVal) {
      var msgNo =
        'أدخل رقم الفاتورة الأصلية كما في JoFotara (مثل EIN00013)' +
        (sysNo ? ' — رقم النظام عندنا: ' + sysNo : '') +
        '.';
      var afterNo = function (noVal) {
        if (noVal === undefined || noVal === null) return;
        noVal = String(noVal).trim();
        if (!looksLikeInvoiceRefNo(noVal)) {
          AppDialog.error('رقم الفاتورة مطلوب (مثال: EIN00013).');
          return;
        }
        originalInvoiceNoForEinvoice = noVal;
        originalInvoiceEinvNum = looksLikeEinvNum(noVal) ? noVal : originalInvoiceEinvNum;
        confirmAndSubmitReturnEinvoice(reason, retId, uuidVal, noVal);
      };
      if (!window.AppDialog || typeof AppDialog.prompt !== 'function') {
        afterNo(window.prompt(msgNo, invNoDefault));
        return;
      }
      AppDialog.prompt(msgNo, {
        title: 'رقم الفاتورة في JoFotara',
        placeholder: 'EIN00013',
        value: invNoDefault,
        okText: 'متابعة',
      })
        .then(afterNo)
        .catch(function () {
          AppDialog.error('تعذر فتح نافذة إدخال رقم الفاتورة.');
        });
    }

    function afterUuid(uuidVal) {
      if (askEinvNo || !looksLikeEinvNum(originalInvoiceNoForEinvoice)) {
        askNo(uuidVal);
        return;
      }
      confirmAndSubmitReturnEinvoice(reason, retId, uuidVal, originalInvoiceNoForEinvoice);
    }

    if (!askUuid && looksLikeUuid(uuidDefault)) {
      afterUuid(uuidDefault);
      return;
    }

    var msg =
      'أدخل معرف الفاتورة (UUID) من JoFotara.\nمثال: 86d0818a-3509-4641-8e92-10aa1865f11a';
    var afterPrompt = function (uuid) {
      if (uuid === undefined || uuid === null) return;
      uuid = String(uuid).trim();
      if (!looksLikeUuid(uuid)) {
        AppDialog.error('صيغة UUID غير صحيحة.');
        return;
      }
      originalInvoiceUuid = uuid;
      needsOriginalInvoiceUuid = false;
      afterUuid(uuid);
    };
    if (!window.AppDialog || typeof AppDialog.prompt !== 'function') {
      afterPrompt(window.prompt(msg, uuidDefault));
      return;
    }
    AppDialog.prompt(msg, {
      title: 'UUID الفاتورة الأصلية',
      placeholder: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
      value: uuidDefault,
      okText: 'متابعة',
    })
      .then(afterPrompt)
      .catch(function () {
        AppDialog.error('تعذر فتح نافذة إدخال UUID.');
      });
  }

  function confirmAndSubmitReturnEinvoice(reason, retId, uuid, invoiceNo) {
    AppDialog.confirm(
      'هل تريد إكمال إرسال الإرجاع لنظام الفوترة الإلكترونية؟\n\nسبب الإرجاع: ' +
        reason +
        (uuid ? '\nUUID: ' + uuid : '') +
        (invoiceNo ? '\nرقم الفاتورة: ' + invoiceNo : ''),
      {
        title: 'تأكيد الإرجاع للفوترة',
        okText: 'نعم، إكمال الإرجاع',
        cancelText: 'لا، إلغاء الإرجاع',
        danger: true,
      }
    ).then(function (ok) {
      if (!ok) return;
      submitReturnEinvoice(reason, retId, uuid || '', invoiceNo || '');
    });
  }

  function submitReturnEinvoice(reason, retId, originalUuid, originalNo) {
    var apiUrl = form.getAttribute('data-return-einvoice-url') || '';
    if (!apiUrl) {
      AppDialog.error('رابط API غير مهيأ.');
      return;
    }
    var csrfInp = form.querySelector('input[name="_csrf"]');
    var fd = new FormData();
    fd.append('_csrf', csrfInp ? csrfInp.value : '');
    fd.append('return_id', String(retId));
    fd.append('reason', reason);
    if (originalUuid) {
      fd.append('original_invoice_uuid', String(originalUuid).trim());
    }
    if (originalNo) {
      fd.append('original_invoice_no', String(originalNo).trim());
    }
    if (window.AppBusy) AppBusy.show('جاري إرسال المرتجع للفوترة...');
    try { console.log('[einvoice-return] sending', { apiUrl: apiUrl, return_id: retId, originalNo: originalNo }); } catch (_e) {}
    fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, data: j }; }); })
      .then(function (res) {
        var data = res.data || {};
        try { console.log('[einvoice-return] response', res.status, data); } catch (_e) {}
        if (!data.ok) {
          var msg = data.error || 'تعذر إرسال الإرجاع للفوترة.';
          if (data.http_code) msg += ' (HTTP ' + data.http_code + ')';
          try { if (data.response) console.warn('JoFotara return response:', data.response); } catch (_e) {}
          if (data.need_original_uuid || data.need_original_invoice_no) {
            needsOriginalInvoiceUuid = !!data.need_original_uuid;
            AppDialog.error(msg).then(function () {
              promptOriginalInvoiceRefs(
                reason,
                retId,
                !!data.need_original_uuid,
                !!data.need_original_invoice_no
              );
            });
            return;
          }
          AppDialog.error(msg);
          return;
        }
        AppDialog.success(data.message || 'تم إرسال الإرجاع للفوترة بنجاح.').then(function () {
          window.location.reload();
        });
      })
      .catch(function (err) {
        try { console.error('[einvoice-return] fetch failed', err); } catch (_e) {}
        AppDialog.error('تعذر الاتصال بالخادم.');
      })
      .finally(function () {
        if (window.AppBusy) AppBusy.hide();
      });
  }

  var newRetBtn = document.querySelector('.sales-ret-btn-new');
  if (newRetBtn) {
    newRetBtn.addEventListener('click', function (e) {
      e.preventDefault();
      confirmNewReturn(initNewReturn);
    });
  }

  var oraCloseBtn = document.querySelector('.sales-ret-wrap .ora12-title-bar__close');
  if (oraCloseBtn) {
    oraCloseBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var closeUrl = (document.getElementById('master-toolbar') || {}).getAttribute?.('data-close-url') || exitUrl;
      var href = oraCloseBtn.getAttribute('href') || closeUrl;
      confirmLeaveScreen(function () {
        if (window.ScreenExitGuard && typeof window.ScreenExitGuard.navigateExit === 'function') {
          window.ScreenExitGuard.navigateExit(href || '');
          return;
        }
        if (href) {
          window.location.href = href;
        } else {
          window.history.back();
        }
      });
    }, true);
  }

  var initialId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  if (initialId > 0) {
    loadReturn({ id: initialId });
  } else if (customerSel && customerSel.value) {
    onCustomerSelected();
    refreshEmptyBrowseNav();
  } else {
    resetInvoiceSelect('— اختر العميل أولًا —');
    refreshEmptyBrowseNav();
  }

  if (window._salesRetPendingCatalog && window.SalesRetLoadCatalog) {
    window.SalesRetLoadCatalog(window._salesRetPendingCatalog);
    window._salesRetPendingCatalog = null;
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
})();
