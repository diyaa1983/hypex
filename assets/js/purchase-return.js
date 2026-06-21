(function () {
  'use strict';

  var form = document.getElementById('sales-ret-form');
  if (!form) return;

  var ledgerView = form.getAttribute('data-ledger-view') === '1';

  var apiInvoices = form.getAttribute('data-api-invoices') || '';
  var apiLines = form.getAttribute('data-api-lines') || '';
  var apiReturn = form.getAttribute('data-api-return') || '';
  var returnPostUrl = form.getAttribute('data-return-post-url') || '';
  var sendEmailUrl = form.getAttribute('data-send-email-url') || '';
  var listReturnsUrl = form.getAttribute('data-list-url') || '';
  var newReturnUrl = form.getAttribute('data-new-url') || '';
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

  var decimals = parseInt(form.getAttribute('data-decimals') || '', 10);
  if (isNaN(decimals) || decimals < 0) {
    decimals = window.AppFormat ? AppFormat.decimals() : 2;
  }

  var customerSel = document.getElementById('ret_supplier');
  var invoiceSel = document.getElementById('ret_invoice');
  var retNoInp = document.getElementById('ret_no');
  var retDateInp = document.getElementById('ret_date');
  var retNotes = document.getElementById('ret_notes');
  var recordIdInp = document.getElementById('ret_record_id');
  var tbody = document.getElementById('sales-ret-lines-body');
  var linesJson = document.getElementById('sales-ret-lines-json');
  var hint = document.getElementById('sales-ret-hint');
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
    defaultNoTitle: 'اكتب جزءاً من رقم المردود واضغط Enter للبحث',
  };
  var searchTimer = null;
  /** @type {object|null} */
  var lastLoadedReturn = null;

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

  function setSavedMode(on) {
    isSavedMode = !!on;
    form.classList.toggle('sales-ret-form-is-saved', isSavedMode);
    if (customerSel) customerSel.disabled = isSavedMode;
    if (invoiceSel) invoiceSel.disabled = isSavedMode;
    if (retDateInp) retDateInp.readOnly = isSavedMode;
    if (retNotes) retNotes.readOnly = isSavedMode;
    if (tbody) {
      tbody.querySelectorAll('.js-qty-ret, .js-ret-pick').forEach(function (el) {
        el.disabled = isSavedMode;
      });
    }
    var pickAll = document.getElementById('sales-ret-pick-all');
    if (pickAll) pickAll.disabled = isSavedMode;
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('ret_no_prev', 'ret_no_next', prevId, nextId, {
        onEmpty: currentReturnId < 1,
        prevTitle: 'المردود السابق',
        nextTitle: 'المردود التالي',
        prevBeforeLatestTitle: 'المردود قبل الأخير',
        latestTitle: 'آخر مردود شراء',
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

  function isRowPrintable(tr) {
    var qtyInp = tr.querySelector('.js-qty-ret');
    var qty = qtyInp ? parseFloat(qtyInp.value) || 0 : 0;
    var gross = parseFloat(tr.getAttribute('data-line-gross')) || 0;
    if (isSavedMode) {
      return qty > 0 || gross > 0 || tr.classList.contains('is-picked');
    }
    return isRowPicked(tr) && qty > 0;
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
    var qty = parseFloat(tr.querySelector('.js-qty-ret').value) || 0;
    var max = parseFloat(tr.getAttribute('data-qty-remaining')) || 0;
    var up = parseFloat(tr.getAttribute('data-unit-price')) || 0;
    var trate = parseFloat(tr.getAttribute('data-tax-rate')) || 0;
    if (qty > max + 0.000001) {
      qty = max;
      tr.querySelector('.js-qty-ret').value = max > 0 ? String(max) : '';
    }
    var c = calcLine(qty, up, trate);
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
      var qty = parseFloat(tr.querySelector('.js-qty-ret').value) || 0;
      if (qty <= 0) return;
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
      var qty = parseFloat(tr.querySelector('.js-qty-ret').value) || 0;
      if (qty <= 0) return;
      lines.push({
        invoice_line_id: parseInt(tr.getAttribute('data-invoice-line-id'), 10),
        item_id: parseInt(tr.getAttribute('data-item-id'), 10),
        qty: qty,
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
    tr.setAttribute('data-invoice-line-id', String(line.invoice_line_id));
    tr.setAttribute('data-item-id', String(line.item_id));
    tr.setAttribute('data-qty-remaining', String(line.qty_remaining));
    tr.setAttribute('data-unit-price', String(line.unit_price));
    tr.setAttribute('data-tax-rate', String(line.tax_rate_percent));
    var name = line.name_ar || line.line_desc || '—';
    setRowItemDisplay(tr, name, line.barcode || '');
    tr.querySelector('.js-price-readonly').textContent = fmt(line.unit_price);
    tr.querySelector('.js-qty-ret').value = '';
    tr.querySelector('.js-qty-ret').max = String(line.qty_remaining);
    tr.querySelector('.js-qty-ret').disabled = true;
    var meta = tr.querySelector('.js-qty-meta');
    if (meta) {
      meta.textContent =
        'مباع: ' + fmt(line.qty_sold) + ' · متبقي للإرجاع: ' + fmt(line.qty_remaining);
    }
    recalcRow(tr);
  }

  function setRowPicked(tr, picked, qty) {
    var pick = tr.querySelector('.js-ret-pick');
    var qtyInp = tr.querySelector('.js-qty-ret');
    if (pick) pick.checked = !!picked;
    tr.classList.toggle('is-picked', !!picked);
    if (qtyInp) {
      qtyInp.disabled = !picked || isSavedMode;
      if (picked) {
        if (qty != null && qty !== '') qtyInp.value = String(qty);
        else if (!qtyInp.value) qtyInp.value = String(tr.getAttribute('data-qty-remaining') || '');
      } else {
        qtyInp.value = '';
      }
    }
    recalcRow(tr);
  }

  function createInvoiceLineRow(line, opts) {
    opts = opts || {};
    var tr = createRow();
    if (!tr) return null;
    fillRowFromCatalogLine(tr, line);
    setRowPicked(tr, !!opts.picked, opts.qty);
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
      AppDialog.alert('اختر فاتورة الشراء أولاً.', { type: 'warning' });
      return;
    }
    function openIfReady() {
      if (!availableLines.length) {
        var msg = !isInvoicePostedInUi()
          ? 'هذه الفاتورة غير مرحّلة. رحّلها من «ترحيل فواتير الشراء» ثم حدّث الصفحة.'
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
    fetch(buildApiUrl(apiLines, { invoice_id: iid, supplier_id: cid }), { credentials: 'same-origin' })
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
        if (data.message) setHint(data.message);
        else if (availableLines.length) {
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
        escapeHtml(fmt(line.qty_remaining)) +
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

  function buildReturnMetaTable(retNo, retDate, cust, inv) {
    if (window.DocumentHeader && typeof window.DocumentHeader.buildMetaTable === 'function') {
      return window.DocumentHeader.buildMetaTable([
        { label: 'رقم المردود', value: retNo },
        { label: 'التاريخ', value: retDate },
        { label: 'المورد', value: cust },
        { label: 'فاتورة الشراء', value: inv },
      ]);
    }
    var cell = 'padding:0.2rem 0;direction:rtl;unicode-bidi:isolate;';
    return (
      '<div class="doc-print-meta"><table><tr><td style="' + cell + '"><strong>رقم المردود:\u200F</strong> <bdi>' +
      escapeHtml(retNo) +
      '</bdi></td></tr><tr><td style="' + cell + '"><strong>التاريخ:\u200F</strong> <bdi>' +
      escapeHtml(retDate) +
      '</bdi></td></tr><tr><td style="' + cell + '"><strong>المورد:\u200F</strong> ' +
      '<bdi class="doc-print-meta-value doc-print-meta-value--party">' +
      escapeHtml(cust) +
      '</bdi>' +
      '</td></tr><tr><td style="' + cell + '"><strong>فاتورة الشراء:\u200F</strong> <bdi>' +
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

  function buildReturnPrintRow(tr, seq) {
    var name = tr.querySelector('.js-name') ? tr.querySelector('.js-name').textContent : '';
    var bc = tr.querySelector('.js-barcode-display') ? tr.querySelector('.js-barcode-display').textContent : '';
    var qtyInp = tr.querySelector('.js-qty-ret');
    var qty = qtyInp ? parseFloat(qtyInp.value) || 0 : 0;
    return (
      '<tr>' +
      printTd(escapeHtml(bc)) +
      printTd(escapeHtml(String(seq))) +
      printTd(escapeHtml(name), 'start') +
      printTd(escapeHtml(fmt(qty))) +
      printTd(escapeHtml(tr.querySelector('.js-price-readonly').textContent)) +
      printTd(escapeHtml(tr.querySelector('.js-line-sub').textContent)) +
      printTd(escapeHtml(tr.querySelector('.js-tax-amt').textContent)) +
      printTd(escapeHtml(tr.querySelector('.js-line-gross').textContent)) +
      '</tr>'
    );
  }

  function buildReturnPrintRowFromLine(line, seq) {
    var qty = parseFloat(line.qty) || 0;
    var name = line.name_ar || line.line_desc || '—';
    var bc = line.barcode || '—';
    var gross =
      line.line_gross != null ? line.line_gross : (line.line_subtotal || 0) + (line.tax_amount || 0);
    return (
      '<tr>' +
      printTd(escapeHtml(bc)) +
      printTd(escapeHtml(String(seq))) +
      printTd(escapeHtml(name), 'start') +
      printTd(escapeHtml(fmt(qty))) +
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
    var cust =
      customerSel && customerSel.selectedIndex > 0
        ? customerSel.options[customerSel.selectedIndex].text
        : '';
    var inv =
      invoiceSel && invoiceSel.selectedIndex > 0 ? invoiceSel.options[invoiceSel.selectedIndex].text : '';
    if (lastLoadedReturn && !cust) {
      cust =
        lastLoadedReturn.supplier_name ||
        lastLoadedReturn.customer_name ||
        '';
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
      var qty = parseFloat(tr.querySelector('.js-qty-ret').value) || 0;
      if (qty <= 0) return;
      seq++;
      rowHtml += buildReturnPrintRow(tr, seq);
    });
    if (!rowHtml && lastLoadedReturn && lastLoadedReturn.lines) {
      lastLoadedReturn.lines.forEach(function (line) {
        var qty = parseFloat(line.qty) || 0;
        if (qty <= 0) return;
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

    var inner =
      buildDocPrintHeader('مردود مشتريات') +
      buildReturnMetaTable(retNo, retDate, cust, inv) +
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
      '.sales-inv-print-tot .g{font-weight:800;font-size:1.05rem;border-top:2px solid #334155;margin-top:0.35rem;padding-top:0.45rem;}'
    );
  }

  function buildStandaloneReturnHtml() {
    var bodyAttrs =
      window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
        ? window.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>مردود مشتريات</title>' +
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
    if (!preview || !overlay) {
      printHtmlInFrame(buildStandaloneReturnHtml());
      return;
    }
    preview.innerHTML = buildReturnPrintInnerHtml();
    if (title) {
      title.textContent = forPdf
        ? 'معاينة — اختر «حفظ كـ PDF» من نافذة الطباعة'
        : 'معاينة الطباعة — اضغط «طباعة» في الشريط العلوي';
    }
    overlay.hidden = false;
  }

  function runPrintFromPreview() {
    printHtmlInFrame(buildStandaloneReturnHtml());
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

  function getReturnFileBase() {
    var no = retNoInp && retNoInp.value ? String(retNoInp.value).trim() : '';
    if (!no) no = 'draft';
    return 'purchase-return-' + no.replace(/[^\w\u0600-\u06FF\-]+/g, '_');
  }

  function downloadReturnPdf() {
    syncJson();
    if (typeof html2pdf === 'undefined') {
      AppDialog.error('تعذر تحميل مكتبة PDF. تحقق من الاتصال بالإنترنت ثم أعد تحميل الصفحة.');
      return;
    }
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
    var csrfInp = form.querySelector('input[name="_csrf"]');
    var docNo = retNoInp && retNoInp.value ? String(retNoInp.value).trim() : '';
    window.DocSendEmail.send({
      url: sendEmailUrl,
      docType: 'purchase_return',
      docNo: docNo,
      fileBase: getReturnFileBase(),
      csrfToken: csrfInp ? csrfInp.value : '',
      buildHtml: buildReturnPrintInnerHtml,
      overlayId: 'sales-inv-print-overlay',
      previewId: 'sales-inv-print-preview',
    });
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
      resetInvoiceSelect('— اختر المورد أولًا —');
      return Promise.resolve({ ok: true, invoices: [] });
    }
    if (!options.keepLines) {
      clearLines();
    }

    var embedded = getEmbeddedInvoices(customerId);
    if (embedded && !options.forceFetch) {
      if (!options.keepLines && typeof window.SalesRetPickCustomer === 'function' && customerSel) {
        window.SalesRetPickCustomer(customerSel);
        if (selectedInvoiceId) {
          invoiceSel.value = String(selectedInvoiceId);
        }
      } else if (options.keepLines && invoiceSel) {
        var listEmb = embedded;
        invoiceSel.innerHTML = '';
        var phEmb = document.createElement('option');
        phEmb.value = '';
        phEmb.textContent = '— اختر فاتورة —';
        invoiceSel.appendChild(phEmb);
        listEmb.forEach(function (inv) {
          var o = document.createElement('option');
          o.value = String(inv.id);
          o.textContent =
            (inv.invoice_no || '#' + inv.id) + ' — ' + fmtDate(inv.invoice_date || '');
          if (selectedInvoiceId && String(inv.id) === String(selectedInvoiceId)) {
            o.selected = true;
          }
          invoiceSel.appendChild(o);
        });
        invoiceSel.disabled = true;
      }
      if (selectedInvoiceId && invoiceSel.value && !options.skipCatalog) {
        loadCatalogLines(invoiceSel.value, customerId);
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
    return fetch(buildApiUrl(apiInvoices, { supplier_id: customerId }), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('http_' + r.status);
        }
        return r.json();
      })
      .then(function (data) {
        resetInvoiceSelect();
        if (!data || !data.ok || !data.invoices || !data.invoices.length) {
          resetInvoiceSelect('— لا توجد فواتير مرحّلة —');
          if (!options.skipCatalog) {
            setHint('لا توجد فواتير مرحّلة لهذا المورد. رحّل الفاتورة من «ترحيل فواتير الشراء».');
          }
          if (options.onReady) options.onReady(data || { ok: true, invoices: [] });
          return data || { ok: true, invoices: [] };
        }
        invoiceSel.disabled = false;
        invoiceSel.removeAttribute('disabled');
        data.invoices.forEach(function (inv) {
          var o = document.createElement('option');
          o.value = String(inv.id);
          o.textContent =
            inv.invoice_no + ' — ' + fmtDate(inv.invoice_date || '') + ' (' + fmt(inv.total) + ')';
          o.dataset.posted = '1';
          if (selectedInvoiceId && String(inv.id) === String(selectedInvoiceId)) o.selected = true;
          invoiceSel.appendChild(o);
        });
        if (selectedInvoiceId && invoiceSel.value && !options.skipCatalog) {
          loadCatalogLines(invoiceSel.value, customerId);
        } else if (!options.skipCatalog) {
          setHint('اختر فاتورة الشراء ثم «اضغط لاختيار المادة» لإضافة مواد الإرجاع.');
        }
        if (options.onReady) options.onReady(data);
        return data;
      })
      .catch(function () {
        resetInvoiceSelect('— تعذر تحميل الفواتير —');
        if (!options.skipCatalog) {
          setHint('تعذر تحميل فواتير المورد. حدّث الصفحة أو تحقق من الاتصال.');
        }
        if (options.onReady) options.onReady({ ok: false, invoices: [] });
        return { ok: false, invoices: [] };
      });
  }

  function onCustomerSelected() {
    if (isSavedMode || !customerSel) return;
    var cid = customerSel.value;
    if (!cid) {
      resetInvoiceSelect('— اختر المورد أولًا —');
      clearLines();
      return;
    }
    clearLines();
    if (typeof window.SalesRetPickCustomer === 'function') {
      window.SalesRetPickCustomer(customerSel);
      return;
    }
    loadInvoices(cid);
  }

  function applySavedReturnLines(ret) {
    clearLines();
    (ret.lines || []).forEach(function (line) {
      var tr = createInvoiceLineRow(
        {
          invoice_line_id: line.invoice_line_id,
          item_id: line.item_id,
          qty_remaining: line.qty,
          unit_price: line.unit_price,
          tax_rate_percent: line.tax_rate_percent,
          name_ar: line.name_ar,
          line_desc: line.line_desc,
          barcode: line.barcode,
          qty_sold: line.qty_sold,
        },
        { picked: true, qty: line.qty }
      );
      if (tr) tbody.appendChild(tr);
    });
    if (ret.subtotal != null && sumSub) sumSub.textContent = fmt(ret.subtotal);
    if (ret.tax_amount != null && sumTax) sumTax.textContent = fmt(ret.tax_amount);
    if (ret.total != null && sumGrand) sumGrand.textContent = fmt(ret.total);
    setSavedMode(true);
    renumberRows();
    syncJson();
    if (ledgerView) {
      setHint('عرض من كشف حركات مادة — للعودة استخدم «كشف حركات مادة».');
    } else if (returnIsPosted) {
      setHint('مردود محفوظ ومرحّل — يمكنك الطباعة أو التنقل بالأسهم.');
    } else {
      setHint('مردود محفوظ — غير مرحّل. استخدم «ترحيل» لتسجيل صرف المخزون وذمة المورد.');
    }
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
    setHint('حدّد ☑ المواد المراد إرجاعها وعدّل كمية الإرجاع لكل مادة.');
  }

  window.SalesRetLoadCatalog = function (lines) {
    if (isSavedMode) return;
    populateInvoiceLines(lines || []);
  };
  window.SalesRetPopulateInvoiceLines = window.SalesRetLoadCatalog;

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
    if (embedded !== null && embedded.length === 0) {
      populateInvoiceLines([]);
      return;
    }
    if (tbody) tbody.innerHTML = '';
    setHint('جاري تحميل مواد الفاتورة…');
    fetch(buildApiUrl(apiLines, { invoice_id: invoiceId, supplier_id: customerId }), {
      credentials: 'same-origin',
    })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          clearLines();
          setHint(
            data.message ||
              (data.error === 'invoice_not_posted'
                ? 'لا يمكن إرجاع إلا فواتير شراء مرحّلة. رحّل الفاتورة أولاً.'
                : 'تعذر تحميل بنود الفاتورة.')
          );
          return;
        }
        populateInvoiceLines(data.lines || []);
        if (!(data.lines && data.lines.length)) {
          setHint('لا توجد كميات متبقية للإرجاع على هذه الفاتورة.');
        }
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
    if (currentReturnId < 1) {
      if (el) el.hidden = true;
      updateReturnNoPostedStyle();
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
    updateReturnNoPostedStyle();
  }

  function postCurrentReturn() {
    if (!returnPostUrl) {
      AppDialog.alert('الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentReturnId < 1) {
      AppDialog.alert('احفظ المردود أولًا قبل الترحيل.', { type: 'warning' });
      return;
    }
    if (returnIsPosted) {
      AppDialog.alert('هذا المردود مرحّل مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    AppDialog.confirm('ترحيل هذا المردود (صرف مخزون وتعديل ذمة المورد)؟', { title: 'ترحيل' }).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('return_id', String(currentReturnId));
      fetch(returnPostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            AppDialog.error(data.error || data.message || 'تعذر الترحيل.');
            return;
          }
          returnIsPosted = true;
          if (lastLoadedReturn) lastLoadedReturn.is_posted = 1;
          updatePostedBadge();
          setHint('مردود محفوظ ومرحّل — يمكنك الطباعة أو التنقل بالأسهم.');
          AppDialog.success(data.message || 'تم الترحيل.');
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function populateFromReturn(ret) {
    lastLoadedReturn = ret;
    currentReturnId = parseInt(ret.id, 10) || 0;
    returnIsPosted =
      ret.is_posted === 1 || ret.is_posted === true || ret.is_posted === '1';
    updatePostedBadge();
    if (recordIdInp) recordIdInp.value = String(currentReturnId);
    if (retNoInp) retNoInp.value = ret.return_no || '';
    if (retDateInp) retDateInp.value = fmtDate(ret.return_date || '');
    if (retNotes) retNotes.value = ret.notes || '';
    if (customerSel) customerSel.value = String(ret.supplier_id || '');
    applyBrowseNavFromPayload(ret);
    loadInvoices(ret.supplier_id, ret.invoice_id, {
      skipCatalog: true,
      keepLines: true,
      onReady: function () {
        if (!invoiceSel || !ret.invoice_id) {
          applySavedReturnLines(ret);
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
        applySavedReturnLines(ret);
      },
    });
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
      emptyMessage: 'لا توجد مردودات محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر مردود.',
      loadError: 'تعذر تحميل المردود.',
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
        loadError: 'تعذر تحميل المردود.',
      });
      return;
    }
    loadReturn({ fromId: currentReturnId, dir: dir });
  }

  function runToolbarReturnSearch() {
    var no = retNoInp ? String(retNoInp.value || '').trim() : '';
    if (!no) {
      AppDialog.alert('أدخل رقم المردود ثم اضغط بحث.', { type: 'warning' });
      if (retNoInp) retNoInp.focus();
      return;
    }
    loadReturn({ no: no });
  }

  function trySave() {
    if (isSavedMode) {
      AppDialog.alert('المردود محفوظ مسبقاً. لمردود جديد استخدم «حذف» ثم أعد الإدخال.', { type: 'info' });
      return;
    }
    if (!customerSel.value) {
      AppDialog.alert('اختر المورد.', { type: 'warning' });
      return;
    }
    if (!invoiceSel.value) {
      AppDialog.alert('اختر فاتورة الشراء.', { type: 'warning' });
      return;
    }
    syncJson();
    var lines = JSON.parse(linesJson.value || '[]');
    if (!lines.length) {
      AppDialog.alert('حدّد مادة واحدة على الأقل (☑) وأدخل كمية إرجاع.', { type: 'warning' });
      return;
    }
    form.submit();
  }

  function newReturn() {
    window.location.href = newReturnUrl || window.location.pathname + '?r=purchase_returns';
  }

  if (customerSel) {
    customerSel.addEventListener('change', onCustomerSelected);
  }

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
    } else if (action === 'print') {
      e.preventDefault();
      handleToolbarPrint();
    } else if (action === 'pdf') {
      e.preventDefault();
      downloadReturnPdf();
    } else if (action === 'send_email') {
      e.preventDefault();
      sendReturnByEmail();
    } else if (action === 'delete') {
      e.preventDefault();
      AppDialog.confirm('مسح الشاشة وفتح مردود جديد؟', { title: 'مردود جديد', okText: 'نعم' }).then(function (ok) {
        if (ok) newReturn();
      });
    } else if (action === 'exit') {
      e.preventDefault();
      var bar = document.getElementById('master-toolbar');
      var url = bar ? bar.getAttribute('data-exit-url') : form.getAttribute('data-exit-url');
      if (url) window.location.href = url;
    }
  });

  var initialId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  if (initialId > 0) {
    loadReturn({ id: initialId });
  } else if (customerSel && customerSel.value) {
    onCustomerSelected();
    refreshEmptyBrowseNav();
  } else {
    resetInvoiceSelect('— اختر المورد أولًا —');
    refreshEmptyBrowseNav();
  }

  if (window._salesRetPendingCatalog && window.SalesRetLoadCatalog) {
    window.SalesRetLoadCatalog(window._salesRetPendingCatalog);
    window._salesRetPendingCatalog = null;
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
})();
