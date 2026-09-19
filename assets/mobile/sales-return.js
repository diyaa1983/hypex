(function () {
  'use strict';

  var cfg = window.MSalesReturn || {};
  var TB = window.MobileToolbar || {};
  var MDL = window.MobileDocList;
  var form = document.getElementById('m-ret-form');
  if (!form) return;

  var dp = cfg.decimalPlaces != null ? cfg.decimalPlaces : 2;
  var linesJson = document.getElementById('m-ret-lines-json');
  var linesEl = document.getElementById('m-ret-lines');
  var linesWrap = document.getElementById('m-ret-lines-wrap');
  var linesEmpty = document.getElementById('m-ret-lines-empty');
  var linesCountEl = document.getElementById('m-ret-lines-count');
  var pickAll = document.getElementById('m-ret-pick-all');
  var custIdInp = document.getElementById('m-ret-customer-id');
  var invIdInp = document.getElementById('m-ret-invoice-id');
  var retDateInp = document.getElementById('m-ret-date');
  var notesInp = document.getElementById('m-ret-notes');
  var reasonInp = document.getElementById('m-ret-reason');
  var statusBanner = document.getElementById('m-ret-status-banner');
  var subtotalEl = document.getElementById('m-ret-subtotal');
  var taxTotalEl = document.getElementById('m-ret-tax-total');
  var grandEl = document.getElementById('m-ret-grand-total');

  var currentReturnId = 0;
  var returnIsPosted = false;
  var isViewMode = false;
  var isEditing = false;
  var loadedReturnTitle = '';
  var loadedReturnNo = '';
  var loadedCustomerName = '';
  var printDoc = null;
  var printLoading = false;
  var lastSavedLines = [];
  var availableLines = [];
  var recordIdInp = document.getElementById('m-ret-record-id');

  function roundN(n) {
    var p = Math.pow(10, dp);
    return Math.round(n * p) / p;
  }

  function fmt(n) {
    return roundN(n).toFixed(dp);
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function showBanner(text, type) {
    if (!statusBanner) return;
    if (!text) {
      statusBanner.hidden = true;
      return;
    }
    statusBanner.textContent = text;
    statusBanner.className = 'm-alert m-alert--' + (type === 'error' ? 'error' : type === 'success' ? 'success' : 'info');
    statusBanner.hidden = false;
  }

  function calcLine(qty, unitPrice, taxRate) {
    var sub = roundN(qty * unitPrice);
    var tax = roundN(sub * (taxRate / 100));
    return { sub: sub, tax: tax, gross: roundN(sub + tax) };
  }

  function getLineCards() {
    return linesEl ? Array.prototype.slice.call(linesEl.querySelectorAll('.m-ret-line')) : [];
  }

  function isPicked(card) {
    var cb = card.querySelector('.m-ret-pick');
    return !!(cb && cb.checked);
  }

  function recalcCard(card) {
    if (!isPicked(card)) {
      card.dataset.lineSub = '0';
      card.dataset.lineTax = '0';
      card.dataset.lineGross = '0';
      var subEl = card.querySelector('.m-ret-line-sub');
      var taxEl = card.querySelector('.m-ret-line-tax');
      var grossEl = card.querySelector('.m-ret-line-gross');
      if (subEl) subEl.textContent = fmt(0);
      if (taxEl) taxEl.textContent = fmt(0);
      if (grossEl) grossEl.textContent = fmt(0);
      return;
    }
    var qtyInp = card.querySelector('.m-ret-qty');
    var qty = parseFloat(qtyInp && qtyInp.value) || 0;
    var max = parseFloat(card.dataset.qtyRemaining) || 0;
    var up = parseFloat(card.dataset.unitPrice) || 0;
    var tr = parseFloat(card.dataset.taxRate) || 0;
    if (qty > max + 0.000001) {
      qty = max;
      if (qtyInp) qtyInp.value = max > 0 ? String(max) : '';
    }
    var c = calcLine(qty, up, tr);
    card.dataset.lineSub = String(c.sub);
    card.dataset.lineTax = String(c.tax);
    card.dataset.lineGross = String(c.gross);
    var subEl = card.querySelector('.m-ret-line-sub');
    var taxEl = card.querySelector('.m-ret-line-tax');
    var grossEl = card.querySelector('.m-ret-line-gross');
    if (subEl) subEl.textContent = fmt(c.sub);
    if (taxEl) taxEl.textContent = fmt(c.tax);
    if (grossEl) grossEl.textContent = fmt(c.gross);
  }

  function recalcFooter() {
    var sub = 0;
    var tax = 0;
    var gross = 0;
    var picked = 0;
    getLineCards().forEach(function (card) {
      if (!isPicked(card)) return;
      var qty = parseFloat(card.querySelector('.m-ret-qty') && card.querySelector('.m-ret-qty').value) || 0;
      if (qty <= 0) return;
      picked++;
      sub += parseFloat(card.dataset.lineSub) || 0;
      tax += parseFloat(card.dataset.lineTax) || 0;
      gross += parseFloat(card.dataset.lineGross) || 0;
    });
    if (subtotalEl) subtotalEl.textContent = fmt(sub);
    if (taxTotalEl) taxTotalEl.textContent = fmt(tax);
    if (grandEl) grandEl.textContent = fmt(gross);
    if (linesCountEl) linesCountEl.textContent = picked + ' سطر';
    syncJson();
    updatePickAllState();
  }

  function syncJson() {
    if (!linesJson || isViewMode) return;
    var lines = [];
    getLineCards().forEach(function (card) {
      if (!isPicked(card)) return;
      var qty = parseFloat(card.querySelector('.m-ret-qty') && card.querySelector('.m-ret-qty').value) || 0;
      if (qty <= 0) return;
      lines.push({
        invoice_line_id: parseInt(card.dataset.invoiceLineId, 10),
        item_id: parseInt(card.dataset.itemId, 10),
        qty: qty,
        unit_price: parseFloat(card.dataset.unitPrice) || 0,
        tax_rate_percent: parseFloat(card.dataset.taxRate) || 0,
        line_subtotal: parseFloat(card.dataset.lineSub) || 0,
        tax_amount: parseFloat(card.dataset.lineTax) || 0,
        line_gross: parseFloat(card.dataset.lineGross) || 0,
      });
    });
    linesJson.value = JSON.stringify(lines);
  }

  function updatePickAllState() {
    if (!pickAll) return;
    var cards = getLineCards();
    if (!cards.length) {
      pickAll.checked = false;
      pickAll.indeterminate = false;
      return;
    }
    var on = 0;
    cards.forEach(function (c) {
      if (isPicked(c)) on++;
    });
    pickAll.checked = on === cards.length && cards.length > 0;
    pickAll.indeterminate = on > 0 && on < cards.length;
  }

  function bindCard(card) {
    var pick = card.querySelector('.m-ret-pick');
    var qty = card.querySelector('.m-ret-qty');
    if (pick) {
      pick.addEventListener('change', function () {
        card.classList.toggle('m-ret-line--picked', pick.checked);
        if (pick.checked && qty && !(parseFloat(qty.value) > 0)) {
          var max = parseFloat(card.dataset.qtyRemaining) || 0;
          qty.value = max > 0 ? String(max) : '';
        }
        if (qty) qty.disabled = !pick.checked || isViewMode;
        recalcCard(card);
        recalcFooter();
      });
    }
    if (qty) {
      qty.addEventListener('input', function () {
        recalcCard(card);
        recalcFooter();
      });
    }
  }

  function renderLines(lines, savedRows) {
    if (!linesEl) return;
    linesEl.innerHTML = '';
    availableLines = lines || [];
    savedRows = savedRows || null;

    if (!availableLines.length) {
      if (linesWrap) linesWrap.hidden = true;
      if (linesEmpty) {
        linesEmpty.hidden = false;
        linesEmpty.textContent = invIdInp && invIdInp.value
          ? 'لا توجد مواد متبقية للإرجاع في هذه الفاتورة.'
          : 'اختر العميل وفاتورة البيع لعرض المواد القابلة للإرجاع.';
      }
      recalcFooter();
      return;
    }

    if (linesWrap) linesWrap.hidden = false;
    if (linesEmpty) linesEmpty.hidden = true;

    availableLines.forEach(function (ln) {
      var card = document.createElement('article');
      card.className = 'm-ret-line';
      card.dataset.invoiceLineId = String(ln.invoice_line_id);
      card.dataset.itemId = String(ln.item_id);
      card.dataset.qtyRemaining = String(ln.qty_remaining);
      card.dataset.unitPrice = String(ln.unit_price);
      card.dataset.taxRate = String(ln.tax_rate_percent);

      var savedQty = 0;
      if (savedRows) {
        savedRows.forEach(function (sr) {
          if (parseInt(sr.invoice_line_id, 10) === parseInt(ln.invoice_line_id, 10)) {
            savedQty = parseFloat(sr.qty) || 0;
          }
        });
      }
      var picked = savedQty > 0;
      var name = ln.name_ar || ln.line_desc || '—';
      var meta =
        'مباع: ' +
        fmt(ln.qty_sold) +
        ' · مُرجَع: ' +
        fmt(ln.qty_returned) +
        ' · متبقي: ' +
        fmt(ln.qty_remaining);

      card.innerHTML =
        '<header class="m-ret-line-head">' +
        '<label class="m-ret-line-pick">' +
        '<input type="checkbox" class="m-ret-pick"' +
        (picked ? ' checked' : '') +
        (isViewMode ? ' disabled' : '') +
        '>' +
        '<span>إرجاع</span></label>' +
        '<div class="m-ret-line-title">' +
        '<strong>' +
        escapeHtml(name) +
        '</strong>' +
        (ln.barcode ? '<code class="m-ret-barcode">' + escapeHtml(ln.barcode) + '</code>' : '') +
        '</div></header>' +
        '<p class="m-ret-line-meta muted">' +
        escapeHtml(meta) +
        '</p>' +
        '<div class="m-ret-line-grid">' +
        '<label class="m-inv-mini"><span>كمية الإرجاع</span>' +
        '<input type="text" class="m-input m-input--sm m-input--num m-ret-qty" inputmode="decimal" value="' +
        (picked ? escapeHtml(String(savedQty)) : '') +
        '"' +
        (isViewMode || !picked ? ' disabled' : '') +
        '></label>' +
        '<div class="m-inv-mini"><span>قبل الضريبة</span><span class="m-ret-line-sub">0</span></div>' +
        '<div class="m-inv-mini"><span>الضريبة</span><span class="m-ret-line-tax">0</span></div>' +
        '<div class="m-inv-mini"><span>مع الضريبة</span><span class="m-ret-line-gross">0</span></div>' +
        '</div>';
      if (picked) card.classList.add('m-ret-line--picked');
      linesEl.appendChild(card);
      bindCard(card);
      recalcCard(card);
    });
    recalcFooter();
  }

  function setFormLocked(locked, posted) {
    isViewMode = locked && !isEditing;
    returnIsPosted = !!posted;
    form.classList.toggle('m-ret-form--locked', isViewMode);
    var fieldsLocked = isViewMode || (currentReturnId > 0 && !isEditing);
    [retDateInp, notesInp, reasonInp].forEach(function (el) {
      if (el) el.readOnly = fieldsLocked;
    });
    var openCust = document.getElementById('m-ret-open-customer');
    var openInv = document.getElementById('m-ret-open-invoice');
    if (openCust) openCust.disabled = fieldsLocked || currentReturnId > 0;
    var clearCustBtn = document.getElementById('m-ret-clear-customer');
    if (clearCustBtn) clearCustBtn.disabled = fieldsLocked || currentReturnId > 0;
    if (openInv) openInv.disabled = fieldsLocked || currentReturnId > 0 || !(custIdInp && custIdInp.value);
    if (pickAll) pickAll.disabled = fieldsLocked;
    getLineCards().forEach(function (card) {
      var pick = card.querySelector('.m-ret-pick');
      var qty = card.querySelector('.m-ret-qty');
      if (pick) pick.disabled = fieldsLocked;
      if (qty) qty.disabled = fieldsLocked || !(pick && pick.checked);
    });
    refreshToolbar();
  }

  function fetchPrintDocument(force) {
    if (!force && printDoc) {
      return Promise.resolve(printDoc);
    }
    if (!cfg.printApi || currentReturnId < 1) {
      return Promise.reject(new Error('no_print_api'));
    }
    if (printLoading) {
      return new Promise(function (resolve, reject) {
        var tries = 0;
        var t = setInterval(function () {
          tries++;
          if (printDoc) {
            clearInterval(t);
            resolve(printDoc);
          } else if (!printLoading || tries > 80) {
            clearInterval(t);
            reject(new Error('timeout'));
          }
        }, 100);
      });
    }
    printLoading = true;
    var url = cfg.printApi + '?id=' + encodeURIComponent(String(currentReturnId));
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        printLoading = false;
        if (!data || !data.ok || !data.html) {
          throw new Error('bad_response');
        }
        printDoc = data;
        return printDoc;
      })
      .catch(function (err) {
        printLoading = false;
        throw err;
      });
  }

  function runPrint() {
    if (currentReturnId < 1) return;
    var btn = TB.btn ? TB.btn('print') : null;
    if (btn) btn.disabled = true;
    fetchPrintDocument(false)
      .then(function (doc) {
        if (btn) btn.disabled = false;
        if (doc.html && MDL && MDL.printHtml) {
          MDL.printHtml(doc.html, 'm-ret-print-frame');
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الطباعة.');
      });
  }

  function runPdf() {
    if (currentReturnId < 1) return;
    var btn = TB.btn ? TB.btn('pdf') : null;
    if (btn) btn.disabled = true;
    fetchPrintDocument(false)
      .then(function (doc) {
        if (btn) btn.disabled = false;
        if (!MDL || !MDL.downloadPdf) {
          throw new Error('no_pdf');
        }
        var fname =
          window.MobilePdfFilename && MobilePdfFilename.salesReturn
            ? MobilePdfFilename.salesReturn(loadedReturnNo, loadedCustomerName)
            : 'مرتجع مبيعات - ' + (loadedReturnNo || 'doc') + '.pdf';
        return MDL.downloadPdf(doc, fname);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تصدير PDF.');
      });
  }

  function refreshToolbar() {
    if (!TB.show) return;
    if (currentReturnId > 0) {
      var vis = {};
      var title = loadedReturnTitle || '';
      if (!isEditing) {
        vis.print = true;
        vis.pdf = true;
      }
      if (!returnIsPosted) {
        if (isEditing) {
          vis.save = true;
        } else {
          if (cfg.canEdit) vis.edit = true;
          if (cfg.canPost) vis.post = true;
          if (cfg.canDelete) vis.delete = true;
        }
      }
      var cols = 0;
      Object.keys(vis).forEach(function (k) {
        if (vis[k]) cols++;
      });
      TB.show(vis, {
        title: title,
        cols: cols >= 2 ? cols : undefined,
        formId: isEditing ? 'm-ret-form' : undefined,
      });
      return;
    }
    isEditing = false;
    TB.show({ save: true }, { formId: 'm-ret-form' });
  }

  function enterEditMode() {
    if (!cfg.canEdit || returnIsPosted || currentReturnId < 1) return;
    isEditing = true;
    var invId = parseInt(invIdInp && invIdInp.value, 10) || 0;
    if (invId < 1) return;
    loadInvoiceLines(invId, lastSavedLines)
      .then(function () {
        setFormLocked(false, false);
      })
      .catch(function (err) {
        isEditing = false;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(err.message || 'تعذر تحميل البنود للتعديل.');
        }
      });
  }

  function clearInvoicePick() {
    if (invIdInp) invIdInp.value = '';
    var chosen = document.getElementById('m-ret-invoice-chosen');
    var openInv = document.getElementById('m-ret-open-invoice');
    if (chosen) chosen.hidden = true;
    if (openInv) openInv.hidden = false;
    renderLines([]);
  }

  function clearCustomerPick() {
    if (custIdInp) custIdInp.value = '';
    var lbl = document.getElementById('m-ret-customer-label');
    var chosen = document.getElementById('m-ret-customer-chosen');
    var openBtn = document.getElementById('m-ret-open-customer');
    if (lbl) lbl.textContent = '';
    if (chosen) chosen.hidden = true;
    if (openBtn) {
      openBtn.hidden = false;
      openBtn.disabled = isViewMode;
    }
    clearInvoicePick();
  }

  function setCustomer(id, name) {
    if (custIdInp) custIdInp.value = String(id);
    var lbl = document.getElementById('m-ret-customer-label');
    var chosen = document.getElementById('m-ret-customer-chosen');
    var openBtn = document.getElementById('m-ret-open-customer');
    if (lbl) lbl.textContent = name;
    if (chosen) chosen.hidden = false;
    if (openBtn) openBtn.hidden = true;
    var openInv = document.getElementById('m-ret-open-invoice');
    if (openInv) openInv.disabled = isViewMode || id < 1;
    clearInvoicePick();
  }

  function setInvoice(id, label) {
    if (invIdInp) invIdInp.value = String(id);
    var lbl = document.getElementById('m-ret-invoice-label');
    var chosen = document.getElementById('m-ret-invoice-chosen');
    var openBtn = document.getElementById('m-ret-open-invoice');
    if (lbl) lbl.textContent = label;
    if (chosen) chosen.hidden = false;
    if (openBtn) openBtn.hidden = true;
  }

  function loadInvoiceLines(invoiceId, savedRows) {
    if (!cfg.linesApi || invoiceId < 1) return Promise.resolve();
    var url =
      cfg.linesApi +
      '?invoice_id=' +
      encodeURIComponent(String(invoiceId)) +
      '&customer_id=' +
      encodeURIComponent(String((custIdInp && custIdInp.value) || '0'));
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر تحميل بنود الفاتورة.');
        }
        renderLines(data.lines || [], savedRows);
      });
  }

  function loadInvoicesForPicker() {
    var list = document.getElementById('m-ret-invoice-list');
    var empty = document.getElementById('m-ret-invoice-empty');
    var loading = document.getElementById('m-ret-invoice-loading');
    var cid = parseInt(custIdInp && custIdInp.value, 10) || 0;
    if (!list || cid < 1) return;
    list.innerHTML = '';
    if (loading) loading.hidden = false;
    if (empty) empty.hidden = true;
    fetch(cfg.invoicesApi + '?customer_id=' + encodeURIComponent(String(cid)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (loading) loading.hidden = true;
        var rows = (data && data.invoices) || [];
        if (!rows.length) {
          if (empty) empty.hidden = false;
          return;
        }
        rows.forEach(function (inv) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'm-ret-invoice-item';
          btn.innerHTML =
            '<strong>' +
            escapeHtml(inv.invoice_no || '') +
            '</strong><span class="muted">' +
            escapeHtml(inv.invoice_date || '') +
            ' · ' +
            escapeHtml(fmt(inv.total)) +
            '</span>';
          btn.addEventListener('click', function () {
            var label = (inv.invoice_no || '') + ' — ' + (inv.invoice_date || '');
            setInvoice(inv.id, label);
            closeInvoicePicker();
            loadInvoiceLines(parseInt(inv.id, 10)).catch(function (err) {
              if (window.AppDialog && AppDialog.error) {
                AppDialog.error(err.message || 'تعذر تحميل البنود.');
              }
            });
          });
          list.appendChild(btn);
        });
      })
      .catch(function () {
        if (loading) loading.hidden = true;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تحميل فواتير العميل.');
      });
  }

  function openInvoicePicker() {
    var picker = document.getElementById('m-ret-invoice-picker');
    if (!picker) return;
    picker.hidden = false;
    picker.setAttribute('aria-hidden', 'false');
    document.body.classList.add('m-picker-open');
    loadInvoicesForPicker();
  }

  function closeInvoicePicker() {
    var picker = document.getElementById('m-ret-invoice-picker');
    if (!picker) return;
    picker.hidden = true;
    picker.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('m-picker-open');
  }

  var custPicker = document.getElementById('m-ret-customer-picker');
  var custSearch = document.getElementById('m-ret-customer-search');
  var customerColors = ['#4f46e5', '#0d9488', '#d97706', '#db2777', '#2563eb', '#7c3aed'];

  function renderCustomerGrid(filter) {
    var grid = document.getElementById('m-ret-customer-grid');
    var empty = document.getElementById('m-ret-customer-empty');
    if (!grid) return;
    var q = String(filter || '')
      .trim()
      .toLowerCase();
    var items = (cfg.customers || []).filter(function (c) {
      return !q || String(c.name_ar || '').toLowerCase().indexOf(q) >= 0;
    });
    if (!items.length) {
      grid.innerHTML = '';
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    grid.innerHTML = items
      .map(function (c, i) {
        var letter = String(c.name_ar || '?').trim().charAt(0) || '?';
        var bg = customerColors[i % customerColors.length];
        return (
          '<button type="button" class="m-customer-grid-item" data-id="' +
          escapeHtml(String(c.id)) +
          '" data-name="' +
          escapeHtml(String(c.name_ar || '')) +
          '">' +
          '<span class="m-customer-avatar" style="background:' +
          bg +
          '">' +
          escapeHtml(letter) +
          '</span>' +
          '<span class="m-customer-grid-name">' +
          escapeHtml(String(c.name_ar || '')) +
          '</span></button>'
        );
      })
      .join('');
    grid.querySelectorAll('.m-customer-grid-item').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        var name = btn.getAttribute('data-name') || '';
        setCustomer(id, name);
        closeCustomerPicker();
      });
    });
  }

  function openCustomerPicker() {
    if (!custPicker || isViewMode) return;
    custPicker.hidden = false;
    custPicker.removeAttribute('hidden');
    custPicker.setAttribute('aria-hidden', 'false');
    document.body.classList.add('m-picker-open');
    renderCustomerGrid(custSearch ? custSearch.value : '');
  }

  function closeCustomerPicker() {
    if (!custPicker) return;
    custPicker.hidden = true;
    custPicker.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('m-picker-open');
  }

  function loadExistingReturn(id) {
    if (!cfg.returnApi || id < 1) return;
    fetch(cfg.returnApi + '?id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok || !data.return) {
          throw new Error((data && data.message) || 'المرتجع غير موجود.');
        }
        var ret = data.return;
        currentReturnId = parseInt(ret.id, 10) || 0;
        if (recordIdInp) recordIdInp.value = String(currentReturnId);
        var posted = !!(ret.is_posted) || String(ret.status || '') === 'posted';
        loadedReturnTitle = (ret.return_no || '') + ' — ' + (ret.customer_name || '');
        loadedReturnNo = ret.return_no || '';
        loadedCustomerName = ret.customer_name || '';
        printDoc = null;
        showBanner(
          'مرتجع رقم ' +
            (ret.return_no || '') +
            (posted ? ' — مرحّل' : ' — غير مرحّل'),
          posted ? 'info' : 'success'
        );
        isEditing = false;
        if (retDateInp && ret.return_date) {
          var iso = String(ret.return_date);
          if (iso.indexOf('-') >= 0 && iso.length >= 10) retDateInp.value = iso.slice(0, 10);
        }
        if (notesInp) notesInp.value = ret.notes || '';
        if (reasonInp) reasonInp.value = ret.reason_return || '';
        setCustomer(ret.customer_id, ret.customer_name || '');
        setInvoice(ret.invoice_id, (ret.invoice_no || '') + '');
        var lines = (ret.lines || []).map(function (ln) {
          return {
            invoice_line_id: ln.invoice_line_id,
            item_id: ln.item_id,
            qty_sold: ln.qty_sold,
            qty_returned: 0,
            qty_remaining: ln.qty,
            unit_price: ln.unit_price,
            tax_rate_percent: ln.tax_rate_percent,
            name_ar: ln.name_ar,
            line_desc: ln.line_desc,
            barcode: ln.barcode,
          };
        });
        lastSavedLines = ret.lines || [];
        renderLines(lines, lastSavedLines);
        setFormLocked(true, posted);
        if (cfg.startEdit && !posted && cfg.canEdit) {
          enterEditMode();
        }
      })
      .catch(function (err) {
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(err.message || 'تعذر تحميل المرتجع.');
        }
      });
  }

  function runPost() {
    if (!cfg.postApi || currentReturnId < 1 || returnIsPosted) return;
    var postBtn = TB.btn ? TB.btn('post') : null;
    if (postBtn) postBtn.disabled = true;
    var body = new FormData();
    body.append('_csrf', cfg.csrf || '');
    body.append('return_id', String(currentReturnId));
    fetch(cfg.postApi, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (postBtn) postBtn.disabled = false;
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر الترحيل.');
        }
        if (window.AppDialog && AppDialog.success) {
          AppDialog.success((data && data.message) || 'تم الترحيل.');
        }
        loadExistingReturn(currentReturnId);
      })
      .catch(function (err) {
        if (postBtn) postBtn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error(err.message || 'تعذر الترحيل.');
      });
  }

  function runDelete() {
    if (!cfg.deleteApi || currentReturnId < 1 || returnIsPosted) return;
    if (!window.confirm('حذف مرتجع المبيعات؟ لا يمكن التراجع.')) return;
    var delBtn = TB.btn ? TB.btn('delete') : null;
    if (delBtn) delBtn.disabled = true;
    var body = new FormData();
    body.append('_csrf', cfg.csrf || '');
    body.append('return_id', String(currentReturnId));
    fetch(cfg.deleteApi, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (delBtn) delBtn.disabled = false;
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر الحذف.');
        }
        window.location.href = cfg.newUrl || window.location.pathname;
      })
      .catch(function (err) {
        if (delBtn) delBtn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error(err.message || 'تعذر الحذف.');
      });
  }

  if (pickAll) {
    pickAll.addEventListener('change', function () {
      var on = pickAll.checked;
      getLineCards().forEach(function (card) {
        var pick = card.querySelector('.m-ret-pick');
        if (!pick || pick.disabled) return;
        pick.checked = on;
        pick.dispatchEvent(new Event('change'));
      });
    });
  }

  var openCust = document.getElementById('m-ret-open-customer');
  if (openCust) openCust.addEventListener('click', openCustomerPicker);
  var closeCust = document.getElementById('m-ret-customer-close');
  if (closeCust) closeCust.addEventListener('click', closeCustomerPicker);
  var clearCust = document.getElementById('m-ret-clear-customer');
  if (clearCust) clearCust.addEventListener('click', clearCustomerPick);
  if (custSearch) custSearch.addEventListener('input', function () { renderCustomerGrid(custSearch.value); });
  renderCustomerGrid('');

  var openInv = document.getElementById('m-ret-open-invoice');
  if (openInv) openInv.addEventListener('click', openInvoicePicker);
  var closeInv = document.getElementById('m-ret-invoice-close');
  if (closeInv) closeInv.addEventListener('click', closeInvoicePicker);

  function bindToolbarBtn(name, handler) {
    var b = TB.btn ? TB.btn(name) : null;
    if (!b || b._mRetBound) return;
    b._mRetBound = true;
    b.addEventListener('click', handler);
  }

  bindToolbarBtn('post', runPost);
  bindToolbarBtn('delete', runDelete);
  bindToolbarBtn('edit', enterEditMode);
  bindToolbarBtn('print', runPrint);
  bindToolbarBtn('pdf', runPdf);

  form.addEventListener('submit', function (e) {
    if (currentReturnId > 0 && !isEditing) {
      e.preventDefault();
    }
  });

  function bootToolbar() {
    refreshToolbar();
  }

  if (cfg.editReturnId > 0) {
    loadExistingReturn(cfg.editReturnId);
  } else {
    bootToolbar();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootToolbar);
  }
})();
