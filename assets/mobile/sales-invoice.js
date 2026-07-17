(function () {
  'use strict';

  var cfg = window.MSalesInvoice || {};
  var trashIconHtml = cfg.trashIconHtml || '';
  var form = document.getElementById('m-invoice-form');
  var invoiceIdInp = document.getElementById('m-invoice-id');
  var editBanner = document.getElementById('m-invoice-edit-banner');
  var TB = window.MobileToolbar || {};
  var saveBtn = TB.btn ? TB.btn('save') : null;
  var barDeleteBtn = TB.btn ? TB.btn('delete') : null;
  var barPrintBtn = TB.btn ? TB.btn('print') : null;
  var barPdfBtn = TB.btn ? TB.btn('pdf') : null;
  var barPostBtn = TB.btn ? TB.btn('post') : null;
  var barOpenBtn = TB.btn ? TB.btn('open') : null;
  var barEditBtn = TB.btn ? TB.btn('edit') : null;
  var barArchiveBtn = TB.btn ? TB.btn('archive') : null;
  var barCameraBtn = TB.btn ? TB.btn('camera') : null;
  var postFlowBusy = false;
  var photoArchive = null;
  if (cfg.canArchive && window.MobileInvoicePhotoArchive) {
    photoArchive = MobileInvoicePhotoArchive.create({
      apiUrl: cfg.archiveApi || '',
      csrf: cfg.csrf || '',
      kind: 'sales_invoice',
      getInvoiceId: function () {
        return parseInt(invoiceIdInp && invoiceIdInp.value, 10) || 0;
      },
      getInvoiceLabel: function () {
        var noEl = document.getElementById('m-inv-no');
        var no = noEl && noEl.textContent ? String(noEl.textContent).trim() : '';
        if (no) return no;
        var id = parseInt(invoiceIdInp && invoiceIdInp.value, 10) || 0;
        return id > 0 ? 'فاتورة #' + id : '';
      },
      isLocked: function () {
        return !!(form && form.classList.contains('m-invoice-form--locked'));
      },
      onPending: function (hasPending) {
        if (!editBanner) return;
        if (hasPending) {
          showEditBanner('صورة الطلبية جاهزة — احفظ الفاتورة لإرفاقها بالأرشيف.', 'info');
        } else if (editBanner.textContent.indexOf('صورة الطلبية') >= 0) {
          editBanner.hidden = true;
        }
      },
    });
    photoArchive.bindToolbar(TB);
  }
  var printCache = null;
  var linesJson = document.getElementById('m-lines-json');
  var linesList = document.getElementById('m-lines-list');
  var linesSwipeHint = document.getElementById('m-lines-swipe-hint');
  var linesEmpty = document.getElementById('m-lines-empty');
  var linesCountEl = document.getElementById('m-lines-count');
  var whEl = document.getElementById('m-warehouse-id');
  var dp = cfg.decimalPlaces != null ? cfg.decimalPlaces : 2;
  var mobileDefaultTax = parseFloat(cfg.mobileDefaultTax);
  if (!(mobileDefaultTax >= 0)) mobileDefaultTax = 5;
  var taxRates = cfg.taxRates || [];

  var picker = document.getElementById('m-item-picker');
  var pickerClose = document.getElementById('m-picker-close');
  var pickerDone = document.getElementById('m-picker-done');
  var pickerSearch = document.getElementById('m-picker-search');
  var itemGrid = document.getElementById('m-item-grid');
  var pickerEmpty = document.getElementById('m-picker-empty');
  var pickerLoading = document.getElementById('m-picker-loading');
  var pickerSelCount = document.getElementById('m-picker-selected-count');
  var btnOpenPicker = document.getElementById('m-open-picker');
  var itemQuick = document.getElementById('m-item-quick');
  var itemQuickBackdrop = document.getElementById('m-item-quick-backdrop');
  var itemQuickName = document.getElementById('m-item-quick-name');
  var itemQuickCode = document.getElementById('m-item-quick-code');
  var itemQuickQty = document.getElementById('m-item-quick-qty');
  var itemQuickUnit = document.getElementById('m-item-quick-unit');
  var itemQuickTotal = document.getElementById('m-item-quick-total');
  var itemQuickCancel = document.getElementById('m-item-quick-cancel');
  var itemQuickConfirm = document.getElementById('m-item-quick-confirm');
  var quickItemDraft = null;
  var quickAmountDriver = 'unit';

  var customerIdInp = document.getElementById('m-customer-id');
  var customerLabel = document.getElementById('m-customer-label');
  var customerAvatarEl = document.getElementById('m-customer-avatar');
  var btnOpenCustomer = document.getElementById('m-open-customer-picker');
  var customerPicker = document.getElementById('m-customer-picker');
  var customerPickerClose = document.getElementById('m-customer-picker-close');
  var customerPickerSearch = document.getElementById('m-customer-picker-search');
  var customerGrid = document.getElementById('m-customer-grid');
  var customerPickerEmpty = document.getElementById('m-customer-picker-empty');
  var allCustomers = cfg.customers || [];
  var customerSearchTimer = null;

  var subtotalEl = document.getElementById('m-subtotal');
  var taxTotalEl = document.getElementById('m-tax-total');
  var grandEl = document.getElementById('m-grand-total');

  var lines = [];
  var lineIdSeq = 0;
  var allItems = [];
  var itemsLoadedForWh = null;
  var searchTimer = null;

  function roundN(n) {
    var p = Math.pow(10, dp);
    return Math.round(n * p) / p;
  }

  function fmt(n) {
    return roundN(n).toFixed(dp);
  }

  function parseNum(val) {
    return parseFloat(String(val == null ? '' : val).replace(/,/g, '')) || 0;
  }

  /** تحديد كل النص عند التركيز — لتسهيل استبدال الكمية أو المبلغ */
  function selectInputAll(inp) {
    if (!inp || inp.readOnly || inp.disabled) return;
    var el = inp;
    setTimeout(function () {
      try {
        var len = String(el.value || '').length;
        if (len > 0 && typeof el.setSelectionRange === 'function') {
          el.setSelectionRange(0, len);
        } else if (typeof el.select === 'function') {
          el.select();
        }
      } catch (e) { /* ignore */ }
    }, 0);
  }

  var invoiceNumericFieldSel =
    '.m-inp-qty, .m-inp-qty-extra, .m-inp-price, .m-inp-disc, .m-input--num';

  /** عرض كمية بند — صفر عند عدم وجود كمية */
  function inputDisplayQty(n) {
    var v = parseNum(n);
    if (v === 0) return '0';
    if (Math.abs(v - Math.round(v)) < 0.0000001) {
      return String(Math.round(v));
    }
    return fmt(v);
  }

  /** عرض مبلغ — صفر بالخانات العشرية للنظام */
  function inputDisplayAmount(n) {
    return fmt(parseNum(n));
  }

  function formatDateDigits(iso) {
    var s = String(iso || '').trim();
    var m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[3] + '/' + m[2] + '/' + m[1];
    return s;
  }

  function todayDateDigits() {
    var d = new Date();
    var dd = String(d.getDate()).padStart(2, '0');
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    return dd + '/' + mm + '/' + d.getFullYear();
  }

  /** تحويل يوم/شهر/سنة إلى Y-m-d قبل الإرسال */
  function parseDateDigitsToIso(s) {
    var raw = String(s || '').trim();
    if (!raw) return '';
    var m = raw.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/);
    if (m) {
      var d = parseInt(m[1], 10);
      var mo = parseInt(m[2], 10);
      var y = parseInt(m[3], 10);
      if (mo >= 1 && mo <= 12 && d >= 1 && d <= 31 && y >= 1900) {
        return String(y).padStart(4, '0') + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
      }
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    return '';
  }

  function validateInvoiceDateValue() {
    var dateInp = document.getElementById('m-invoice-date');
    if (!dateInp) return '';
    var iso = parseDateDigitsToIso(dateInp.value);
    if (!iso) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert('تاريخ الفاتورة غير صالح. استخدم صيغة يوم/شهر/سنة مثل 06/07/2026', { type: 'warning' });
      }
      dateInp.focus();
      return null;
    }
    return iso;
  }

  function inputHasValue(el) {
    return el && String(el.value || '').trim() !== '';
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function discountAmount(lineBase, rawInput) {
    if (!(lineBase > 0)) return 0;
    if (window.InvDiscount && InvDiscount.amountForBase) {
      return InvDiscount.amountForBase(lineBase, rawInput, roundN);
    }
    return 0;
  }

  function lineAmounts(qty, unitPrice, discountInput, taxRatePct) {
    var lineBase = roundN(qty * unitPrice);
    var discAmt = discountAmount(lineBase, discountInput);
    var sub = roundN(Math.max(0, lineBase - discAmt));
    var tax = roundN((sub * taxRatePct) / 100);
    var gross = roundN(sub + tax);
    return { discAmt: discAmt, sub: sub, tax: tax, gross: gross };
  }

  function amountsForLine(ln) {
    return lineAmounts(
      ln.qty,
      ln.unit_price,
      ln.line_discount_input || '',
      ln.tax_rate_percent != null ? ln.tax_rate_percent : mobileDefaultTax
    );
  }

  function itemSalePrice(it) {
    var p = it.default_sale != null ? it.default_sale : (it.sale_price != null ? it.sale_price : it.unit_price);
    return parseFloat(p) || 0;
  }

  function itemLabel(it) {
    var name = (it.name_ar || it.name || '').trim();
    var code = (it.code || it.sku || '').trim();
    return { name: name, code: code };
  }

  function lineToPayload(ln) {
    var a = amountsForLine(ln);
    return {
      item_id: ln.item_id,
      qty: ln.qty,
      qty_extra: parseNum(ln.qty_extra),
      unit_price: ln.unit_price,
      line_discount_input: String(ln.line_discount_input || '').trim(),
      discount_amount: a.discAmt,
      tax_rate_percent: ln.tax_rate_percent != null ? ln.tax_rate_percent : mobileDefaultTax,
      amount_driver: 'unit',
      line_subtotal: a.sub,
      tax_amount: a.tax,
      line_gross: a.gross
    };
  }

  function buildTaxSelect(ln) {
    var h = '<select class="m-input m-input--xs m-select m-inp-tax" aria-label="نسبة الضريبة">';
    var i;
    for (i = 0; i < taxRates.length; i++) {
      var tr = taxRates[i];
      var id = parseInt(tr.id, 10) || 0;
      var rate = parseFloat(tr.rate_percent) || 0;
      var sel = false;
      if (ln.tax_rate_id != null && id === ln.tax_rate_id) {
        sel = true;
      } else if (ln.tax_rate_id == null && Math.abs(rate - (ln.tax_rate_percent || mobileDefaultTax)) < 0.001) {
        sel = true;
      }
      h += '<option value="' + id + '" data-rate="' + rate + '"' + (sel ? ' selected' : '') + '>' + rate + '%</option>';
    }
    return h + '</select>';
  }

  function defaultTaxFields() {
    var id = cfg.defaultTaxRateId != null ? cfg.defaultTaxRateId : 0;
    return {
      tax_rate_id: id,
      tax_rate_percent: mobileDefaultTax
    };
  }

  function syncItemTileSelection(tile, selected) {
    if (!tile) return;
    tile.classList.toggle('is-selected', !!selected);
    tile.setAttribute('aria-selected', selected ? 'true' : 'false');
  }

  function updatePickerBadge() {
    if (pickerSelCount) {
      pickerSelCount.textContent = lines.length > 0 ? lines.length + ' بند' : '0';
    }
    if (!itemGrid) return;
    itemGrid.querySelectorAll('.m-item-tile').forEach(function (tile) {
      var iid = parseInt(tile.getAttribute('data-item-id'), 10) || 0;
      var inLines = lines.some(function (l) {
        return (parseInt(l.item_id, 10) || 0) === iid;
      });
      syncItemTileSelection(tile, inLines);
    });
  }

  function findItemById(iid) {
    var i;
    for (i = 0; i < allItems.length; i++) {
      if ((parseInt(allItems[i].id, 10) || 0) === iid) return allItems[i];
    }
    return null;
  }

  function confirmPickerSelection() {
    closePicker();
  }

  function syncLineFromRow(row) {
    var id = parseInt(row.getAttribute('data-line-id'), 10);
    var ln = lines.find(function (l) { return l.id === id; });
    if (!ln) return;
    var qtyEl = row.querySelector('.m-inp-qty');
    var qeEl = row.querySelector('.m-inp-qty-extra');
    var priceEl = row.querySelector('.m-inp-price');
    var discEl = row.querySelector('.m-inp-disc');
    var taxEl = row.querySelector('.m-inp-tax');
    ln.qty = parseNum(qtyEl ? qtyEl.value : 0);
    ln.qty_extra = parseNum(qeEl ? qeEl.value : 0);
    ln.unit_price = parseNum(priceEl ? priceEl.value : 0);
    if (ln.unit_price <= 0 && priceEl && String(priceEl.placeholder || '').trim() !== '') {
      ln.unit_price = parseNum(priceEl.placeholder);
    }
    ln.line_discount_input = discEl ? String(discEl.value || '').trim() : '';
    if (taxEl) {
      ln.tax_rate_id = parseInt(taxEl.value, 10) || 0;
      var opt = taxEl.options[taxEl.selectedIndex];
      ln.tax_rate_percent = parseNum(opt ? opt.getAttribute('data-rate') : mobileDefaultTax);
    }
    var grossEl = row.querySelector('.m-inv-gross--inline, .m-inv-gross');
    if (grossEl) grossEl.textContent = fmt(amountsForLine(ln).gross);
  }

  function lineRowEl(row) {
    if (!row) return null;
    if (row.matches && row.matches('[data-line-id]')) return row;
    return row.closest ? row.closest('[data-line-id]') : null;
  }

  function syncAllFromDom() {
    if (!linesList) return;
    linesList.querySelectorAll('[data-line-id]').forEach(syncLineFromRow);
  }

  function updateTotalsOnly() {
    syncAllFromDom();
    var payload = lines.map(lineToPayload);
    linesJson.value = JSON.stringify(payload);
    var sumSub = 0;
    var sumTax = 0;
    var sumGross = 0;
    payload.forEach(function (p) {
      sumSub += p.line_subtotal;
      sumTax += p.tax_amount;
      sumGross += p.line_gross;
    });
    subtotalEl.textContent = fmt(sumSub);
    taxTotalEl.textContent = fmt(sumTax);
    grandEl.textContent = fmt(sumGross);
    linesCountEl.textContent = lines.length + ' سطر';
  }

  function deleteLineById(id) {
    lines = lines.filter(function (l) {
      return l.id !== id;
    });
    syncAll();
  }

  function renderLines() {
    if (!linesList) return;
    linesList.innerHTML = '';
    lines.forEach(function (ln, idx) {
      var a = amountsForLine(ln);
      var priceVal = inputDisplayAmount(ln.unit_price);
      var pricePh =
        ln.unit_price <= 0 && ln.default_unit_price > 0 ? inputDisplayAmount(ln.default_unit_price) : '';
      var wrap = document.createElement('div');
      wrap.className = 'm-inv-line-swipe';
      wrap.setAttribute('data-line-id', String(ln.id));
      wrap.innerHTML =
        '<div class="m-inv-line-swipe__rail">' +
        '<button type="button" class="m-inv-swipe-delete" aria-label="حذف البند" title="حذف">' +
        (trashIconHtml || '×') +
        '</button></div>' +
        '<div class="m-inv-line__panel m-inv-line m-inv-app-line-card">' +
        '<div class="m-inv-line-head">' +
        '<span class="m-inv-seq">' + (idx + 1) + '</span>' +
        '<div class="m-inv-item-text">' +
        '<div class="m-inv-item-name">' + escapeHtml(ln.item_name) + '</div>' +
        '<div class="m-inv-item-code muted">' + escapeHtml(ln.item_code || '—') + '</div>' +
        '</div>' +
        '<div class="m-inv-line-head-end">' +
        '<span class="m-inv-gross">' + fmt(a.gross) + '</span>' +
        '</div></div>' +
        '<div class="m-inv-line-body">' +
        '<div class="m-inv-field-grid m-inv-field-grid--5">' +
        '<label class="m-inv-mini"><span>كمية</span>' +
        '<input type="text" class="m-input m-input--xs m-input--num m-inp-qty" inputmode="decimal" autocomplete="off" aria-label="الكمية" value="' +
        escapeHtml(inputDisplayQty(ln.qty)) + '"></label>' +
        '<label class="m-inv-mini"><span>إضافي</span>' +
        '<input type="text" class="m-input m-input--xs m-input--num m-inp-qty-extra" inputmode="decimal" autocomplete="off" aria-label="الكمية الإضافية" value="' +
        escapeHtml(inputDisplayQty(ln.qty_extra)) + '" title="للمخزون"></label>' +
        '<label class="m-inv-mini"><span>سعر</span>' +
        '<input type="text" class="m-input m-input--xs m-input--num m-inp-price" inputmode="decimal" autocomplete="off" aria-label="السعر" placeholder="' +
        escapeHtml(pricePh) + '" value="' + escapeHtml(priceVal) + '"></label>' +
        '<label class="m-inv-mini"><span>خصم</span>' +
        '<input type="text" class="m-input m-input--xs m-inp-disc" inputmode="decimal" autocomplete="off" aria-label="الخصم" placeholder="%" value="' +
        escapeHtml(ln.line_discount_input || '') + '"></label>' +
        '<label class="m-inv-mini m-inv-mini--tax"><span>ضريبة</span>' + buildTaxSelect(ln) + '</label>' +
        '</div></div></div>';
      linesList.appendChild(wrap);
    });
    updateTotalsOnly();
    updatePickerBadge();
  }

  function syncAll() {
    var hasLines = lines.length > 0;
    if (linesEmpty) linesEmpty.style.display = hasLines ? 'none' : 'block';
    if (linesList) linesList.hidden = !hasLines;
    if (linesSwipeHint) linesSwipeHint.hidden = !hasLines;
    renderLines();
  }

  function upsertLineFromQuick(it, qty, unitPrice) {
    var iid = parseInt(it.id, 10) || 0;
    if (iid < 1) return;
    var lab = itemLabel(it);
    var tax = defaultTaxFields();
    var existing = lines.find(function (l) { return l.item_id === iid; });
    if (existing) {
      existing.qty = qty;
      existing.unit_price = unitPrice;
      existing.item_name = lab.name;
      existing.item_code = lab.code;
    } else {
      lines.push({
        id: ++lineIdSeq,
        item_id: iid,
        item_name: lab.name,
        item_code: lab.code,
        qty: qty,
        qty_extra: 0,
        unit_price: unitPrice,
        line_discount_input: '',
        tax_rate_id: tax.tax_rate_id,
        tax_rate_percent: tax.tax_rate_percent
      });
    }
    syncAll();
  }

  function recalcItemQuick(source) {
    if (!itemQuickQty || !itemQuickUnit || !itemQuickTotal) return;
    var qty = parseNum(itemQuickQty.value);
    if (qty < 0) qty = 0;
    var hasQty = inputHasValue(itemQuickQty);
    var hasUnit = inputHasValue(itemQuickUnit);
    if (source === 'total') {
      quickAmountDriver = 'total';
      var totalIn = parseNum(itemQuickTotal.value);
      if (qty > 0 && inputHasValue(itemQuickTotal)) {
        itemQuickUnit.value = inputDisplayAmount(roundN(totalIn / qty));
      }
      return;
    }
    if (source === 'qty' && quickAmountDriver === 'total') {
      var totalKeep = parseNum(itemQuickTotal.value);
      if (qty > 0 && inputHasValue(itemQuickTotal)) {
        itemQuickUnit.value = inputDisplayAmount(roundN(totalKeep / qty));
      }
      return;
    }
    if (source === 'unit' || source === 'qty') {
      quickAmountDriver = 'unit';
    }
    if (!hasQty && !hasUnit) {
      itemQuickTotal.value = '';
      return;
    }
    var unit = parseNum(itemQuickUnit.value);
    if (hasQty && hasUnit) {
      itemQuickTotal.value = inputDisplayAmount(roundN(qty * unit));
    } else if (source !== 'total') {
      itemQuickTotal.value = '';
    }
  }

  function openItemQuick(it) {
    if (!itemQuick || !it) return;
    quickItemDraft = it;
    quickAmountDriver = 'unit';
    var lab = itemLabel(it);
    var iid = parseInt(it.id, 10) || 0;
    var existing = lines.find(function (l) { return l.item_id === iid; });
    var defaultUnit = itemSalePrice(it);
    if (itemQuickName) itemQuickName.textContent = lab.name;
    if (itemQuickCode) itemQuickCode.textContent = lab.code ? 'الرمز: ' + lab.code : '';
    if (itemQuickQty) itemQuickQty.value = existing ? inputDisplayQty(existing.qty) : '';
    if (itemQuickUnit) {
      itemQuickUnit.value = existing ? inputDisplayAmount(existing.unit_price) : inputDisplayAmount(0);
      itemQuickUnit.placeholder = !existing && defaultUnit > 0 ? inputDisplayAmount(defaultUnit) : '';
    }
    if (itemQuickTotal) itemQuickTotal.value = '';
    recalcItemQuick('unit');
    itemQuick.hidden = false;
    itemQuick.setAttribute('aria-hidden', 'false');
    setTimeout(function () {
      if (itemQuickQty) {
        itemQuickQty.focus();
        itemQuickQty.select();
      }
    }, 80);
  }

  function closeItemQuick() {
    if (!itemQuick) return;
    itemQuick.hidden = true;
    itemQuick.setAttribute('aria-hidden', 'true');
    quickItemDraft = null;
  }

  function confirmItemQuick() {
    if (!quickItemDraft) return;
    var qty = parseNum(itemQuickQty ? itemQuickQty.value : 0);
    var unit = parseNum(itemQuickUnit ? itemQuickUnit.value : 0);
    if (unit <= 0 && itemQuickUnit && String(itemQuickUnit.placeholder || '').trim() !== '') {
      unit = parseNum(itemQuickUnit.placeholder);
    }
    if (qty <= 0) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert('أدخل كمية أكبر من صفر.', { type: 'warning' });
      }
      if (itemQuickQty) itemQuickQty.focus();
      return;
    }
    if (unit < 0) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert('السعر غير صالح.', { type: 'warning' });
      }
      return;
    }
    if (quickAmountDriver === 'total' && itemQuickTotal) {
      var total = parseNum(itemQuickTotal.value);
      unit = qty > 0 ? roundN(total / qty) : 0;
    }
    upsertLineFromQuick(quickItemDraft, qty, unit);
    closeItemQuick();
    syncAll();
    if (pickerSearch) {
      renderItemGrid(filterItems(pickerSearch.value));
    } else {
      renderItemGrid(allItems);
    }
  }

  function fetchAllItems(callback) {
    var wh = whEl ? whEl.value : '0';
    if (itemsLoadedForWh === wh && allItems.length) {
      callback(allItems);
      return;
    }
    var url = cfg.itemsApi + '?warehouse_id=' + encodeURIComponent(wh) + '&list=1';
    if (pickerLoading) pickerLoading.hidden = false;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        if (pickerLoading) pickerLoading.hidden = true;
        allItems = (data && data.ok && data.items) ? data.items : [];
        itemsLoadedForWh = wh;
        callback(allItems);
      })
      .catch(function () {
        if (pickerLoading) pickerLoading.hidden = true;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error('تعذر تحميل المواد.');
        }
        callback([]);
      });
  }

  function filterItems(q) {
    var s = String(q || '').trim().toLowerCase();
    if (!s) return allItems;
    return allItems.filter(function (it) {
      var name = String(it.name_ar || it.name || '').toLowerCase();
      var code = String(it.code || it.sku || '').toLowerCase();
      var bc = String(it.barcode || '').toLowerCase();
      return name.indexOf(s) >= 0 || code.indexOf(s) >= 0 || bc.indexOf(s) >= 0;
    });
  }

  function renderItemGrid(items) {
    if (!itemGrid) return;
    itemGrid.innerHTML = '';
    if (!items.length) {
      if (pickerEmpty) pickerEmpty.hidden = false;
      return;
    }
    if (pickerEmpty) pickerEmpty.hidden = true;
    items.forEach(function (it) {
      var lab = itemLabel(it);
      var iid = parseInt(it.id, 10) || 0;
      var price = itemSalePrice(it);
      var selected = lines.some(function (l) {
        return (parseInt(l.item_id, 10) || 0) === iid;
      });
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-item-tile' + (selected ? ' is-selected' : '');
      tile.setAttribute('data-item-id', String(iid));
      tile.setAttribute('role', 'option');
      tile.setAttribute('aria-selected', selected ? 'true' : 'false');
      tile.innerHTML =
        '<span class="m-item-tile-check" aria-hidden="true">✓</span>' +
        '<span class="m-item-tile-name">' + escapeHtml(lab.name) + '</span>' +
        '<span class="m-item-tile-code muted">' + escapeHtml(lab.code || '—') + '</span>' +
        '<span class="m-item-tile-price">' + fmt(price) + '</span>';
      tile.addEventListener('click', function () {
        openItemQuick(it);
      });
      itemGrid.appendChild(tile);
    });
    updatePickerBadge();
  }

  function openPicker() {
    if (!picker) return;
    closeItemQuick();
    picker.hidden = false;
    picker.setAttribute('aria-hidden', 'false');
    document.body.classList.add('m-picker-open');
    if (pickerSearch) pickerSearch.value = '';
    fetchAllItems(function (items) {
      renderItemGrid(items);
    });
  }

  function closePicker() {
    closeItemQuick();
    if (!picker) return;
    picker.hidden = true;
    picker.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('m-picker-open');
    syncAll();
  }

  if (itemQuickQty) {
    itemQuickQty.addEventListener('input', function () { recalcItemQuick('qty'); });
  }
  if (itemQuickUnit) {
    itemQuickUnit.addEventListener('input', function () { recalcItemQuick('unit'); });
  }
  if (itemQuickTotal) {
    itemQuickTotal.addEventListener('input', function () { recalcItemQuick('total'); });
  }
  if (itemQuickCancel) itemQuickCancel.addEventListener('click', closeItemQuick);
  if (itemQuickBackdrop) itemQuickBackdrop.addEventListener('click', closeItemQuick);
  if (itemQuickConfirm) itemQuickConfirm.addEventListener('click', confirmItemQuick);

  function customerInitials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '؟';
    if (parts.length === 1) return parts[0].charAt(0);
    return parts[0].charAt(0) + parts[parts.length - 1].charAt(0);
  }

  function customerAvatarHue(name) {
    var h = 0;
    var s = String(name || '');
    var i;
    for (i = 0; i < s.length; i++) {
      h = ((h << 5) - h + s.charCodeAt(i)) | 0;
    }
    return Math.abs(h) % 360;
  }

  function buildCustomerAvatarHtml(name, extraClass) {
    var initials = escapeHtml(customerInitials(name));
    var hue = customerAvatarHue(name);
    var cls = 'm-customer-avatar' + (extraClass ? ' ' + extraClass : '');
    return (
      '<span class="' + cls + '" style="--m-avatar-h:' + hue + '">' + initials + '</span>'
    );
  }

  function updateCustomerPickField(name) {
    var displayName = String(name || '').trim();
    if (customerLabel) {
      customerLabel.textContent = displayName || 'اضغط لاختيار العميل';
      customerLabel.classList.toggle('is-placeholder', !displayName);
    }
    if (customerAvatarEl) {
      if (displayName) {
        customerAvatarEl.innerHTML = buildCustomerAvatarHtml(displayName, 'm-inv-app-customer__avatar-inner');
      } else {
        customerAvatarEl.innerHTML = '<span class="m-inv-app-customer__avatar-empty" aria-hidden="true">👤</span>';
      }
    }
  }

  function updateCustomerLabel() {
    if (!customerIdInp) return;
    var id = parseInt(customerIdInp.value, 10) || 0;
    if (id < 1) {
      updateCustomerPickField('');
      return;
    }
    var c = allCustomers.find(function (x) { return parseInt(x.id, 10) === id; });
    updateCustomerPickField(c ? c.name_ar || '' : '');
  }

  function filterCustomers(q) {
    var s = String(q || '').trim().toLowerCase();
    if (!s) return allCustomers;
    return allCustomers.filter(function (c) {
      var name = String(c.name_ar || '').toLowerCase();
      var code = String(c.code || '').toLowerCase();
      return name.indexOf(s) >= 0 || code.indexOf(s) >= 0;
    });
  }

  function renderCustomerGrid(list) {
    if (!customerGrid) return;
    var selectedId = customerIdInp ? parseInt(customerIdInp.value, 10) || 0 : 0;
    customerGrid.innerHTML = '';
    if (!list.length) {
      if (customerPickerEmpty) customerPickerEmpty.hidden = false;
      return;
    }
    if (customerPickerEmpty) customerPickerEmpty.hidden = true;
    list.forEach(function (c) {
      var id = parseInt(c.id, 10) || 0;
      var tile = document.createElement('button');
      tile.type = 'button';
      var name = String(c.name_ar || '').trim() || '—';
      tile.className = 'm-customer-tile' + (id === selectedId ? ' is-selected' : '');
      tile.setAttribute('data-customer-id', String(id));
      tile.setAttribute('role', 'option');
      tile.setAttribute('aria-label', name);
      tile.innerHTML =
        buildCustomerAvatarHtml(name) +
        '<span class="m-customer-tile-name">' + escapeHtml(name) + '</span>' +
        '<span class="m-customer-tile-check" aria-hidden="true">✓</span>';
      tile.addEventListener('click', function () {
        if (customerIdInp) customerIdInp.value = String(id);
        updateCustomerLabel();
        closeCustomerPicker();
      });
      customerGrid.appendChild(tile);
    });
  }

  function openCustomerPicker() {
    if (!customerPicker) return;
    customerPicker.hidden = false;
    customerPicker.setAttribute('aria-hidden', 'false');
    document.body.classList.add('m-picker-open');
    if (customerPickerSearch) {
      customerPickerSearch.value = '';
    }
    renderCustomerGrid(allCustomers);
  }

  function closeCustomerPicker() {
    if (!customerPicker) return;
    customerPicker.hidden = true;
    customerPicker.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('m-picker-open');
  }

  function clearCustomerSelection() {
    if (customerIdInp) customerIdInp.value = '';
    updateCustomerLabel();
  }

  if (btnOpenCustomer) btnOpenCustomer.addEventListener('click', openCustomerPicker);
  if (customerPickerClose) customerPickerClose.addEventListener('click', closeCustomerPicker);
  if (customerPickerSearch) {
    customerPickerSearch.addEventListener('input', function () {
      clearTimeout(customerSearchTimer);
      var q = customerPickerSearch.value;
      customerSearchTimer = setTimeout(function () {
        renderCustomerGrid(filterCustomers(q));
      }, 200);
    });
  }

  function taxRateIdForPercent(pct) {
    var i;
    var rate = parseNum(pct);
    for (i = 0; i < taxRates.length; i++) {
      if (Math.abs((parseFloat(taxRates[i].rate_percent) || 0) - rate) < 0.001) {
        return parseInt(taxRates[i].id, 10) || 0;
      }
    }
    return cfg.defaultTaxRateId != null ? cfg.defaultTaxRateId : 0;
  }

  function showEditBanner(text, type) {
    if (!editBanner) return;
    editBanner.textContent = text;
    editBanner.className = 'm-alert m-alert--' + (type === 'error' ? 'error' : type === 'success' ? 'success' : 'info');
    editBanner.hidden = false;
  }

  function currentInvoiceId() {
    return parseInt(invoiceIdInp && invoiceIdInp.value, 10) || 0;
  }

  function refreshActionBar() {
    var id = currentInvoiceId();
    var locked = form && form.classList.contains('m-invoice-form--locked');
    var vis = {};
    var disabled = {};
    var saved = id > 0;

    if (!locked) {
      vis.save = true;
    }

    vis.post = true;
    vis.delete = true;
    vis.edit = true;
    vis.print = true;
    vis.pdf = true;
    vis.archive = true;

    if (!cfg.canPost) delete vis.post;
    if (!cfg.canDelete) delete vis.delete;
    if (!cfg.canEdit) delete vis.edit;
    if (!cfg.canArchive) {
      delete vis.archive;
    }

    if (!saved) {
      disabled.post = true;
      disabled.delete = true;
      disabled.edit = true;
      disabled.print = true;
      disabled.pdf = true;
    }

    if (locked) {
      disabled.save = true;
      disabled.post = true;
      disabled.delete = true;
      disabled.edit = true;
      disabled.print = false;
      disabled.pdf = false;
    }

    document.body.classList.add('m-inv-full-toolbar');

    if (TB.show) {
      TB.show(vis, {
        formId: locked ? undefined : 'm-invoice-form',
        disabled: disabled,
      });
    }
    if (photoArchive) photoArchive.refreshMeta();
  }

  function bootActionBar() {
    if (TB.pinToBottomDock) {
      TB.pinToBottomDock();
    }
    document.body.classList.add('m-inv-full-toolbar');
    document.body.classList.remove('m-inv-actions-inline');
    if (TB.refresh) {
      TB.refresh();
    } else if (TB.ensureVisible) {
      TB.ensureVisible();
    }
    refreshActionBar();
    requestAnimationFrame(refreshActionBar);
  }

  bootActionBar();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootActionBar);
  }
  window.addEventListener('load', bootActionBar);

  function editInvoiceHref() {
    var id = currentInvoiceId();
    if (id < 1) return '';
    var base = cfg.editUrl || '';
    if (!base && window.AppMobile && AppMobile.mobileUrl) {
      base = AppMobile.mobileUrl + '?r=m_sales_invoices';
    }
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + 'id=' + encodeURIComponent(String(id));
  }

  function viewInvoiceHref() {
    var id = currentInvoiceId();
    if (id < 1 || !cfg.viewUrl) return '';
    var base = cfg.viewUrl;
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + 'id=' + encodeURIComponent(String(id));
  }

  function mobileConfirm(message, options) {
    options = options || {};
    if (window.AppDialog && typeof AppDialog.confirm === 'function') {
      return AppDialog.confirm(String(message), {
        title: options.title || 'تأكيد',
        okText: options.okText || 'نعم',
        cancelText: options.cancelText || 'إلغاء',
        danger: !!options.danger,
      }).then(function (ok) {
        return !!ok;
      });
    }
    return Promise.resolve(window.confirm(String(message)));
  }

  function parseJsonResponse(r) {
    return r
      .text()
      .then(function (text) {
        var data = null;
        try {
          data = text ? JSON.parse(text) : null;
        } catch (e) {
          data = null;
        }
        if (!data || typeof data !== 'object') {
          return { ok: false, message: 'تعذر قراءة رد الخادم.' };
        }
        return data;
      });
  }

  function runBarPost() {
    if (postFlowBusy || !cfg.canPost || !cfg.postApi) return;
    var id = currentInvoiceId();
    if (id < 1) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert('احفظ الفاتورة أولاً ثم رحّلها.', { type: 'warning' });
      }
      return;
    }
    if (form && form.classList.contains('m-invoice-form--locked')) return;

    var gpsEnabled = !!(cfg.gpsEnabled || window.APP_GPS_ENABLED);
    var gpsPrimed = null;
    if (gpsEnabled && window.AppGeo && AppGeo.primePostGpsFromUserGesture) {
      gpsPrimed = AppGeo.primePostGpsFromUserGesture('mobile');
    }

    function submitPost(gps) {
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf || '');
      fd.append('invoice_id', String(id));
      if (window.AppGeo && AppGeo.appendToFormData && gps) {
        AppGeo.appendToFormData(fd, gps, 'mobile');
      }
      if (barPostBtn) barPostBtn.disabled = true;
      postFlowBusy = true;
      fetch(cfg.postApi, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(parseJsonResponse)
        .then(function (data) {
          if (barPostBtn) barPostBtn.disabled = false;
          postFlowBusy = false;
          if (!data || !data.ok) {
            var msg = (data && (data.message || data.error)) || 'تعذر ترحيل الفاتورة.';
            if (window.AppDialog && AppDialog.error) AppDialog.error(msg);
            return;
          }
          var href = viewInvoiceHref();
          if (href) {
            window.location.href = href;
            return;
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم ترحيل الفاتورة.');
          }
          loadInvoiceForEdit(id);
        })
        .catch(function () {
          postFlowBusy = false;
          if (barPostBtn) barPostBtn.disabled = false;
          if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    }

    function beginPost() {
      var confirmMsg =
        'هل تريد ترحيل هذه الفاتورة؟\n\nسيتم صرف المخزون وتسجيل حساب العميل.';
      if (gpsEnabled) {
        confirmMsg += '\n\n📍 قد يُطلب السماح بالموقع على الهاتف.';
      }
      mobileConfirm(confirmMsg, { title: 'تأكيد الترحيل', okText: 'ترحيل' }).then(function (ok) {
        if (!ok) {
          return;
        }
        if (window.AppGeo && AppGeo.captureForPost) {
          AppGeo.captureForPost('mobile', { primed: gpsPrimed })
            .then(function (gps) {
              submitPost(gps && gps.latitude != null ? gps : null);
            })
            .catch(function () {
              submitPost(null);
            });
          return;
        }
        submitPost(null);
      });
    }

    beginPost();
  }

  function fetchPrintDoc(id) {
    if (!cfg.printApi || id < 1) {
      return Promise.reject(new Error('no_api'));
    }
    if (printCache && printCache.id === id) {
      return Promise.resolve(printCache.doc);
    }
    return fetch(cfg.printApi + '?id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) throw new Error('bad_response');
        printCache = { id: id, doc: data };
        return data;
      });
  }

  function runBarPrint() {
    var id = currentInvoiceId();
    if (id < 1) return;
    if (barPrintBtn) barPrintBtn.disabled = true;
    fetchPrintDoc(id)
      .then(function (doc) {
        if (barPrintBtn) barPrintBtn.disabled = false;
        if (doc.html && window.MobileDocList && MobileDocList.printHtml) {
          MobileDocList.printHtml(doc.html, 'm-inv-form-print-frame');
        }
      })
      .catch(function () {
        if (barPrintBtn) barPrintBtn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الطباعة.');
      });
  }

  function runBarPdf() {
    var id = currentInvoiceId();
    if (id < 1) return;
    if (barPdfBtn) barPdfBtn.disabled = true;
    fetchPrintDoc(id)
      .then(function (doc) {
        if (barPdfBtn) barPdfBtn.disabled = false;
        var fname =
          window.MobilePdfFilename && MobilePdfFilename.invoice
            ? MobilePdfFilename.invoice('', '')
            : 'فاتورة.pdf';
        if (window.MobilePdfExport && MobilePdfExport.exportMobilePdf) {
          return MobilePdfExport.exportMobilePdf(doc, { filename: fname, delayMs: 550 });
        }
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تصدير PDF.');
      })
      .catch(function () {
        if (barPdfBtn) barPdfBtn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تصدير PDF.');
      });
  }

  function runBarDelete() {
    if (!cfg.canDelete || !cfg.deleteApi) return;
    var id = currentInvoiceId();
    if (id < 1) return;
    var confirmFn =
      window.AppDialog && AppDialog.confirm
        ? function (msg) {
            return AppDialog.confirm(msg, {
              title: 'تأكيد الحذف',
              okText: 'نعم، احذف',
              cancelText: 'إلغاء',
              danger: true,
            }).then(function (ok) {
              return !!ok;
            });
          }
        : function (msg) {
            return Promise.resolve(window.confirm(msg));
          };
    confirmFn('حذف الفاتورة؟ لا يمكن التراجع.').then(function (ok) {
      if (!ok) return;
      if (barDeleteBtn) barDeleteBtn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf || '');
      fd.append('invoice_id', String(id));
      fetch(cfg.deleteApi, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (barDeleteBtn) barDeleteBtn.disabled = false;
          if (!data || !data.ok) {
            if (window.AppDialog && AppDialog.error) {
              AppDialog.error((data && data.message) || 'تعذر حذف الفاتورة.');
            }
            return;
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم حذف الفاتورة.');
          }
          var listUrl =
            window.AppMobile && AppMobile.mobileUrl
              ? AppMobile.mobileUrl + '?r=m_sales_invoice_list'
              : '?r=m_sales_invoice_list';
          window.location.href = listUrl;
        })
        .catch(function () {
          if (barDeleteBtn) barDeleteBtn.disabled = false;
          if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  if (barPrintBtn) barPrintBtn.addEventListener('click', runBarPrint);
  if (barPdfBtn) barPdfBtn.addEventListener('click', runBarPdf);
  if (barDeleteBtn) barDeleteBtn.addEventListener('click', runBarDelete);
  if (barPostBtn) barPostBtn.addEventListener('click', runBarPost);
  if (barOpenBtn) {
    barOpenBtn.addEventListener('click', function () {
      var href = viewInvoiceHref();
      if (href) window.location.href = href;
    });
  }
  if (barEditBtn) {
    barEditBtn.addEventListener('click', function () {
      var id = currentInvoiceId();
      if (id < 1) {
        if (window.AppDialog && AppDialog.alert) {
          AppDialog.alert('احفظ الفاتورة أولاً ثم يمكنك تعديلها.', { type: 'warning' });
        }
        return;
      }
      var href = editInvoiceHref();
      if (href) window.location.href = href;
    });
  }

  function lockInvoiceForm(message) {
    if (form) form.classList.add('m-invoice-form--locked');
    showEditBanner(message, 'error');
    if (saveBtn) saveBtn.hidden = true;
    if (btnOpenPicker) btnOpenPicker.disabled = true;
    if (btnOpenCustomer) btnOpenCustomer.disabled = true;
    form.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (el.type === 'hidden') return;
      el.disabled = true;
    });
    refreshActionBar();
  }

  function applyInvoiceForEdit(inv) {
    if (!inv || !form) return;
    var invId = parseInt(inv.id, 10) || 0;
    if (invoiceIdInp) invoiceIdInp.value = String(invId);
    if (inv.is_posted || inv.einv_sent) {
      var why = inv.is_posted ? 'الفاتورة مرحّلة — لا يمكن تعديلها من الهاتف.' : 'الفاتورة أُرسلت للفوترة — لا يمكن تعديلها.';
      lockInvoiceForm(why);
      return;
    }
    var no = inv.invoice_no || '';
    showEditBanner(no ? 'تعديل فاتورة رقم ' + no + ' (غير مرحّلة)' : 'تعديل فاتورة غير مرحّلة', 'info');
    if (saveBtn) saveBtn.textContent = 'حفظ التعديلات';

    if (customerIdInp) customerIdInp.value = String(parseInt(inv.customer_id, 10) || '');
    if (customerIdInp && (inv.customer_id || inv.customer_name)) {
      var custName = (inv.customer_name || '').trim();
      if (custName) {
        updateCustomerPickField(custName);
      } else {
        updateCustomerLabel();
      }
    }
    var dateInp = form.querySelector('[name="invoice_date"]');
    if (dateInp && inv.invoice_date) dateInp.value = formatDateDigits(inv.invoice_date);
    var pay = document.getElementById('m-payment-type');
    if (pay && inv.payment_type) pay.value = inv.payment_type === 'cash' ? 'cash' : 'credit';
    if (whEl && inv.warehouse_id) whEl.value = String(parseInt(inv.warehouse_id, 10));
    var notesInp = form.querySelector('[name="notes"]');
    if (notesInp) notesInp.value = inv.notes || '';

    lines = [];
    lineIdSeq = 0;
    (inv.lines || []).forEach(function (ln) {
      var pct = ln.tax_rate_percent != null ? parseFloat(ln.tax_rate_percent) : mobileDefaultTax;
      lines.push({
        id: ++lineIdSeq,
        item_id: parseInt(ln.item_id, 10) || 0,
        item_name: (ln.name_ar || ln.line_desc || '').trim(),
        item_code: (ln.sku || ln.barcode || '').trim(),
        qty: parseNum(ln.qty),
        qty_extra: parseNum(ln.qty_extra),
        unit_price: parseNum(ln.unit_price),
        line_discount_input: String(ln.line_discount_input || '').trim(),
        tax_rate_id: taxRateIdForPercent(pct),
        tax_rate_percent: pct,
      });
    });
    allItems = [];
    itemsLoadedForWh = null;
    syncAll();
    printCache = null;
    refreshActionBar();
  }

  function loadInvoiceForEdit(id) {
    if (!cfg.invoiceApi || id < 1) return;
    showEditBanner('جاري تحميل الفاتورة...', 'info');
    fetch(cfg.invoiceApi + '?id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok || !data.invoice) {
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error((data && data.message) || 'الفاتورة غير موجودة.');
          }
          var viewBase = cfg.viewUrl || '';
          if (id > 0 && viewBase) {
            window.location.href = viewBase + (viewBase.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id;
          }
          return;
        }
        applyInvoiceForEdit(data.invoice);
      })
      .catch(function () {
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تحميل الفاتورة للتعديل.');
      });
  }

  updateCustomerLabel();

  if (cfg.editInvoiceId > 0) {
    loadInvoiceForEdit(cfg.editInvoiceId);
  }

  if (btnOpenPicker) btnOpenPicker.addEventListener('click', openPicker);
  if (pickerClose) pickerClose.addEventListener('click', closePicker);
  if (pickerDone) pickerDone.addEventListener('click', confirmPickerSelection);

  if (pickerSearch) {
    pickerSearch.addEventListener('input', function () {
      clearTimeout(searchTimer);
      var q = pickerSearch.value;
      searchTimer = setTimeout(function () {
        renderItemGrid(filterItems(q));
      }, 200);
    });
  }

  if (linesList) {
    if (window.MobileInvLineSwipe && MobileInvLineSwipe.bind) {
      MobileInvLineSwipe.bind(linesList, {
        onDelete: function (id) {
          if (form && form.classList.contains('m-invoice-form--locked')) return;
          deleteLineById(id);
        },
      });
    }
    linesList.addEventListener('input', function (e) {
      var row = lineRowEl(e.target);
      if (!row) return;
      syncLineFromRow(row);
      updateTotalsOnly();
    });
    linesList.addEventListener('change', function (e) {
      var row = lineRowEl(e.target);
      if (!row) return;
      syncLineFromRow(row);
      updateTotalsOnly();
    });
    linesList.addEventListener('click', function (e) {
      var delBtn = e.target.closest('.m-inv-line-delete');
      if (!delBtn || form.classList.contains('m-invoice-form--locked')) return;
      var row = lineRowEl(delBtn);
      if (!row) return;
      e.preventDefault();
      e.stopPropagation();
      var id = parseInt(row.getAttribute('data-line-id'), 10);
      if (id) deleteLineById(id);
    });
  }

  if (whEl) {
    whEl.addEventListener('change', function () {
      allItems = [];
      itemsLoadedForWh = null;
      if (picker && !picker.hidden) {
        fetchAllItems(function (items) {
          renderItemGrid(filterItems(pickerSearch ? pickerSearch.value : ''));
        });
      }
    });
  }

  if (form) {
    form.addEventListener('focusin', function (e) {
      var t = e.target;
      if (t && t.matches && t.matches(invoiceNumericFieldSel)) {
        selectInputAll(t);
      }
    });
  }
  if (itemQuick) {
    itemQuick.addEventListener('focusin', function (e) {
      var t = e.target;
      if (t && t.matches && t.matches('.m-input--num')) {
        selectInputAll(t);
      }
    });
  }

  if (form && cfg.mobileSave) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var invoiceDateIso = validateInvoiceDateValue();
      if (!invoiceDateIso) return;
      syncAllFromDom();
      updateTotalsOnly();
      if (lines.length < 1) {
        if (window.AppDialog && AppDialog.alert) {
          AppDialog.alert('أضف مادة واحدة على الأقل من «اختيار المواد».', { type: 'warning' });
        }
        return;
      }
      var i;
      for (i = 0; i < lines.length; i++) {
        var ln = lines[i];
        var qty = Math.max(0, parseNum(ln.qty));
        var qtyExtra = Math.max(0, parseNum(ln.qty_extra));
        var stock = qty + qtyExtra;
        if (qty <= 0 && qtyExtra <= 0) {
          if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert(
              'عند كمية صفر يجب إدخال كمية إضافية للمادة: ' + ln.item_name,
              { type: 'warning' }
            );
          }
          return;
        }
        if (stock <= 0) {
          if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert('أدخل كمية أو كمية إضافية للمادة: ' + ln.item_name, { type: 'warning' });
          }
          return;
        }
        var unitPrice = parseNum(ln.unit_price);
        var isBonusLine = qty <= 0 && qtyExtra > 0;
        if (unitPrice <= 0 && ln.default_unit_price > 0) {
          unitPrice = parseNum(ln.default_unit_price);
          ln.unit_price = unitPrice;
        }
        if (unitPrice <= 0 && !isBonusLine) {
          if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert('أدخل السعر للمادة: ' + ln.item_name, { type: 'warning' });
          }
          return;
        }
      }
      if (customerIdInp && !customerIdInp.value) {
        if (window.AppDialog && AppDialog.alert) {
          AppDialog.alert('اختر العميل من «اختيار العميل».', { type: 'warning' });
        }
        if (btnOpenCustomer) btnOpenCustomer.focus();
        return;
      }
      var btn = saveBtn;
      if (btn) btn.disabled = true;
      var fd = new FormData(form);
      fd.set('invoice_date', invoiceDateIso);
      var hasArchivePhoto = false;
      if (photoArchive && photoArchive.takePendingFile) {
        var pendingPhoto = photoArchive.takePendingFile();
        if (pendingPhoto && pendingPhoto.file) {
          fd.append('archive_photo', pendingPhoto.file, pendingPhoto.name);
          hasArchivePhoto = true;
        }
      }

      function handleSaveResponse(data) {
        if (btn) btn.disabled = false;
        if (!data || !data.ok) {
          var msg = (data && data.message) || 'تعذر الحفظ.';
          if (window.AppDialog && AppDialog.error) AppDialog.error(msg);
          return;
        }
        var invId = parseInt(data.invoice_id, 10) || 0;
        if (invoiceIdInp && invId > 0) {
          invoiceIdInp.value = String(invId);
        }
        function afterSaveRedirect() {
          if (invId > 0 && window.AppMobile && AppMobile.mobileUrl) {
            var viewUrl =
              AppMobile.mobileUrl + '?r=m_sales_invoice_view&id=' + encodeURIComponent(String(invId));
            window.location.href = viewUrl;
            return;
          }
          var no = data.invoice_no || '';
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success('تم حفظ الفاتورة' + (no ? ' رقم ' + no : '') + ' (غير مرحّلة).');
          }
          lines = [];
          allItems = [];
          itemsLoadedForWh = null;
          syncAll();
          form.reset();
          clearCustomerSelection();
          var dateInp = form.querySelector('[name="invoice_date"]');
          if (dateInp) dateInp.value = todayDateDigits();
          var pay = document.getElementById('m-payment-type');
          if (pay) pay.value = 'credit';
        }
        if (data.archive_uploaded) {
          if (photoArchive) photoArchive.refreshMeta();
          if (photoArchive && photoArchive.showBriefSuccess) {
            photoArchive.showBriefSuccess('تم التحميل بنجاح', 1000).then(afterSaveRedirect);
            return;
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success('تم حفظ الفاتورة ورفع صورة الطلبية إلى السيرفر.').then(afterSaveRedirect);
            return;
          }
          afterSaveRedirect();
          return;
        }
        if (data.archive_error) {
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error(
              'تم حفظ الفاتورة، لكن تعذر رفع الصورة إلى السيرفر:\n\n' + data.archive_error
            );
          }
          refreshActionBar();
          return;
        }
        if (invId > 0 && photoArchive && photoArchive.hasPending()) {
          photoArchive.flushPending(invId, { silent: true }).then(function (uploadOk) {
            if (!uploadOk) {
              if (window.AppDialog && AppDialog.error) {
                AppDialog.error(
                  'تم حفظ الفاتورة، لكن تعذر رفع صورة الطلبية إلى السيرفر.\n\nافتح «أرشيف» وحاول التصوير مرة أخرى.'
                );
              }
              refreshActionBar();
              return;
            }
            if (window.AppDialog && AppDialog.success) {
              AppDialog.success('تم حفظ الفاتورة ورفع صورة الطلبية إلى السيرفر.').then(afterSaveRedirect);
              return;
            }
            if (photoArchive && photoArchive.showBriefSuccess) {
              photoArchive.showBriefSuccess('تم التحميل بنجاح', 1000).then(afterSaveRedirect);
              return;
            }
            afterSaveRedirect();
          });
          return;
        }
        afterSaveRedirect();
      }

      if (hasArchivePhoto && photoArchive && photoArchive.xhrPost) {
        photoArchive.showUploadProgress('جاري حفظ الفاتورة ورفع الصورة...', 5);
        photoArchive
          .xhrPost(form.action, fd, {
            headers: { 'X-Invoice-Save': '1' },
            onProgress: function (pct) {
              photoArchive.updateUploadProgress(pct, 'جاري حفظ الفاتورة ورفع الصورة...');
            },
          })
          .then(function (data) {
            if (btn) btn.disabled = false;
            if (!data || !data.ok) {
              photoArchive.hideUploadProgress();
              var msg = (data && data.message) || 'تعذر الحفظ.';
              if (window.AppDialog && AppDialog.error) AppDialog.error(msg);
              return;
            }
            if (data.archive_uploaded && photoArchive.showBriefSuccess) {
              if (photoArchive) photoArchive.refreshMeta();
              var invId = parseInt(data.invoice_id, 10) || 0;
              if (invoiceIdInp && invId > 0) invoiceIdInp.value = String(invId);
              return photoArchive.showBriefSuccess('تم التحميل بنجاح', 1000).then(function () {
                if (invId > 0 && window.AppMobile && AppMobile.mobileUrl) {
                  window.location.href =
                    AppMobile.mobileUrl + '?r=m_sales_invoice_view&id=' + encodeURIComponent(String(invId));
                  return;
                }
                handleSaveResponse(data);
              });
            }
            photoArchive.hideUploadProgress();
            return handleSaveResponse(data);
          })
          .catch(function (err) {
            if (btn) btn.disabled = false;
            if (photoArchive.hideUploadProgress) photoArchive.hideUploadProgress();
            if (window.AppDialog && AppDialog.error) {
              AppDialog.error(err.message || 'تعذر الاتصال بالخادم.');
            }
          });
        return;
      }

      fetch(form.action, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Invoice-Save': '1'
        }
      })
        .then(function (r) { return r.json(); })
        .then(handleSaveResponse)
        .catch(function () {
          if (btn) btn.disabled = false;
          if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  syncAll();
})();
