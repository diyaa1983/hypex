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
  var linesTbody = document.getElementById('m-lines-tbody');
  var tableWrap = document.getElementById('m-inv-table-wrap');
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
  var customerChosenEl = document.getElementById('m-customer-chosen');
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
  /** @type {Set<number>|null} */
  var pickerPendingIds = null;

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

  /** عرض كمية — فارغ عند الصفر */
  function inputDisplayNum(n) {
    var v = parseNum(n);
    if (v === 0) return '';
    if (Math.abs(v - Math.round(v)) < 0.0000001) {
      return String(Math.round(v));
    }
    return fmt(v);
  }

  /** عرض مبلغ — صفر بالخانات العشرية للنظام */
  function inputDisplayAmount(n) {
    return fmt(parseNum(n));
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
    var h = '<select class="m-input m-input--xs m-select m-inp-tax" aria-label="الضريبة">';
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

  function lineCountForItem(itemId) {
    var ln = lines.find(function (l) { return l.item_id === itemId; });
    return ln ? ln.qty : 0;
  }

  function initPickerPendingFromLines() {
    pickerPendingIds = new Set();
    lines.forEach(function (ln) {
      var iid = parseInt(ln.item_id, 10) || 0;
      if (iid > 0) pickerPendingIds.add(iid);
    });
  }

  function updatePickerBadge() {
    if (pickerSelCount) {
      if (pickerPendingIds) {
        pickerSelCount.textContent = pickerPendingIds.size > 0 ? pickerPendingIds.size + ' مادة' : '0';
      } else {
        pickerSelCount.textContent = lines.length > 0 ? lines.length + ' بند' : '0';
      }
    }
    if (!itemGrid || !pickerPendingIds || picker.hidden) return;
    itemGrid.querySelectorAll('.m-item-tile').forEach(function (tile) {
      var iid = parseInt(tile.getAttribute('data-item-id'), 10) || 0;
      var selected = pickerPendingIds.has(iid);
      tile.classList.toggle('is-selected', selected);
      var check = tile.querySelector('.m-item-tile-check');
      if (check) check.hidden = !selected;
    });
  }

  function togglePickerItem(it) {
    if (!pickerPendingIds || !it) return;
    var iid = parseInt(it.id, 10) || 0;
    if (iid < 1) return;
    if (pickerPendingIds.has(iid)) {
      pickerPendingIds.delete(iid);
    } else {
      pickerPendingIds.add(iid);
    }
    updatePickerBadge();
  }

  function findItemById(iid) {
    var i;
    for (i = 0; i < allItems.length; i++) {
      if ((parseInt(allItems[i].id, 10) || 0) === iid) return allItems[i];
    }
    return null;
  }

  function addPendingLineFromItem(it) {
    var iid = parseInt(it.id, 10) || 0;
    if (iid < 1) return;
    var lab = itemLabel(it);
    var tax = defaultTaxFields();
    lines.push({
      id: ++lineIdSeq,
      item_id: iid,
      item_name: lab.name,
      item_code: lab.code,
      qty: 0,
      qty_extra: 0,
      unit_price: 0,
      line_discount_input: '',
      tax_rate_id: tax.tax_rate_id,
      tax_rate_percent: tax.tax_rate_percent,
      default_unit_price: itemSalePrice(it),
    });
  }

  function confirmPickerSelection() {
    if (!pickerPendingIds) {
      closePicker();
      return;
    }
    if (pickerPendingIds.size < 1) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert('حدّد مادة واحدة على الأقل.', { type: 'warning' });
      }
      return;
    }
    lines = lines.filter(function (ln) {
      return pickerPendingIds.has(parseInt(ln.item_id, 10) || 0);
    });
    pickerPendingIds.forEach(function (iid) {
      var exists = lines.some(function (ln) {
        return (parseInt(ln.item_id, 10) || 0) === iid;
      });
      if (!exists) {
        var it = findItemById(iid);
        if (it) addPendingLineFromItem(it);
      }
    });
    pickerPendingIds = null;
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

  function syncAllFromDom() {
    if (!linesTbody) return;
    linesTbody.querySelectorAll('.m-inv-line-swipe').forEach(syncLineFromRow);
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

  function renderLinesTable() {
    if (!linesTbody) return;
    linesTbody.innerHTML = '';
    lines.forEach(function (ln, idx) {
      var a = amountsForLine(ln);
      var wrap = document.createElement('div');
      wrap.className = 'm-inv-line-swipe';
      wrap.setAttribute('data-line-id', String(ln.id));
      var card = document.createElement('article');
      card.className = 'm-inv-line m-inv-line__panel';
      var priceVal = inputDisplayAmount(ln.unit_price);
      var pricePh =
        ln.unit_price <= 0 && ln.default_unit_price > 0 ? inputDisplayAmount(ln.default_unit_price) : '';
      card.innerHTML =
        '<div class="m-inv-line-all">' +
        '<span class="m-inv-seq">' + (idx + 1) + '</span>' +
        '<div class="m-inv-item-name" title="' + escapeHtml(ln.item_code || '') + '">' + escapeHtml(ln.item_name) + '</div>' +
        '<label class="m-inv-mini m-inv-mini--qty"><span>كمية</span><input type="text" class="m-input m-input--xs m-input--num m-inp-qty" inputmode="decimal" autocomplete="off" placeholder="" value="' + escapeHtml(inputDisplayNum(ln.qty)) + '"></label>' +
        '<label class="m-inv-mini m-inv-mini--extra"><span>إضافية</span><input type="text" class="m-input m-input--xs m-input--num m-inp-qty-extra" inputmode="decimal" autocomplete="off" placeholder="" value="' + escapeHtml(inputDisplayNum(ln.qty_extra)) + '" title="للمخزون"></label>' +
        '<label class="m-inv-mini m-inv-mini--price"><span>سعر</span><input type="text" class="m-input m-input--xs m-input--num m-inp-price" inputmode="decimal" autocomplete="off" placeholder="' + escapeHtml(pricePh) + '" value="' + escapeHtml(priceVal) + '"></label>' +
        '<label class="m-inv-mini m-inv-mini--disc"><span>خصم</span><input type="text" class="m-input m-input--xs m-inp-disc" inputmode="decimal" autocomplete="off" placeholder="%" value="' + escapeHtml(ln.line_discount_input || '') + '"></label>' +
        '<label class="m-inv-mini m-inv-mini--tax"><span>ض%</span>' + buildTaxSelect(ln) + '</label>' +
        '<button type="button" class="m-inv-line-delete m-inv-line-delete--inline" aria-label="حذف البند" title="حذف">' +
        (trashIconHtml || '×') +
        '</button>' +
        '<span class="m-inv-gross m-inv-gross--inline">' + fmt(a.gross) + '</span>' +
        '</div>';
      var rail = document.createElement('div');
      rail.className = 'm-inv-line-swipe__rail';
      rail.innerHTML =
        '<button type="button" class="m-inv-swipe-delete" aria-label="حذف البند" title="حذف">' +
        (trashIconHtml || '×') +
        '</button>';
      wrap.appendChild(rail);
      wrap.appendChild(card);
      linesTbody.appendChild(wrap);
    });
    updateTotalsOnly();
    updatePickerBadge();
  }

  function syncAll() {
    var hasLines = lines.length > 0;
    if (linesEmpty) linesEmpty.style.display = hasLines ? 'none' : 'block';
    if (tableWrap) tableWrap.hidden = !hasLines;
    renderLinesTable();
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
    if (itemQuickQty) itemQuickQty.value = existing ? inputDisplayNum(existing.qty) : '';
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
      var selected = pickerPendingIds ? pickerPendingIds.has(iid) : lineCountForItem(iid) > 0;
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-item-tile' + (selected ? ' is-selected' : '');
      tile.setAttribute('data-item-id', String(iid));
      tile.setAttribute('role', 'option');
      tile.setAttribute('aria-selected', selected ? 'true' : 'false');
      tile.innerHTML =
        '<span class="m-item-tile-check"' + (selected ? '' : ' hidden') + ' aria-hidden="true">✓</span>' +
        '<span class="m-item-tile-name">' + escapeHtml(lab.name) + '</span>' +
        '<span class="m-item-tile-code muted">' + escapeHtml(lab.code || '—') + '</span>' +
        '<span class="m-item-tile-price">' + fmt(price) + '</span>';
      tile.addEventListener('click', function () {
        togglePickerItem(it);
      });
      itemGrid.appendChild(tile);
    });
    updatePickerBadge();
  }

  function openPicker() {
    if (!picker) return;
    initPickerPendingFromLines();
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
    pickerPendingIds = null;
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
      customerLabel.textContent = displayName;
    }
    if (customerChosenEl) {
      customerChosenEl.hidden = !displayName;
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

    if (id > 0) {
      vis.open = true;
      vis.print = true;
      vis.pdf = true;
      if (cfg.canArchive) vis.archive = true;
    }

    if (!locked) {
      vis.save = true;
      if (cfg.canArchive) vis.camera = true;
      if (cfg.canDelete && id > 0) vis.delete = true;
      if (cfg.canPost && id > 0) vis.post = true;
    }

    var cols = 0;
    Object.keys(vis).forEach(function (k) {
      if (vis[k]) cols++;
    });
    if (TB.show) {
      TB.show(vis, { formId: 'm-invoice-form', cols: cols > 0 ? cols : undefined });
    }
    if (photoArchive) photoArchive.refreshMeta();
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
    if (dateInp && inv.invoice_date) dateInp.value = inv.invoice_date;
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

  if (linesTbody) {
    linesTbody.addEventListener('input', function (e) {
      var row = e.target.closest('.m-inv-line-swipe');
      if (!row) return;
      syncLineFromRow(row);
      updateTotalsOnly();
    });
    linesTbody.addEventListener('change', function (e) {
      var row = e.target.closest('.m-inv-line-swipe');
      if (!row) return;
      syncLineFromRow(row);
      updateTotalsOnly();
    });
    linesTbody.addEventListener('click', function (e) {
      var delBtn = e.target.closest('.m-inv-line-delete');
      if (!delBtn || form.classList.contains('m-invoice-form--locked')) return;
      var row = delBtn.closest('.m-inv-line-swipe');
      if (!row) return;
      e.preventDefault();
      e.stopPropagation();
      var id = parseInt(row.getAttribute('data-line-id'), 10);
      if (id) deleteLineById(id);
    });
  }

  if (linesTbody && global.MobileInvLineSwipe && MobileInvLineSwipe.bind) {
    MobileInvLineSwipe.bind(linesTbody, {
      trashIconHtml: trashIconHtml,
      onDelete: deleteLineById,
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

  if (form && cfg.mobileSave) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
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
        var stock = Math.max(0, parseNum(ln.qty)) + Math.max(0, parseNum(ln.qty_extra));
        if (stock <= 0) {
          if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert('أدخل كمية أو كمية إضافية للمادة: ' + ln.item_name, { type: 'warning' });
          }
          return;
        }
        var unitPrice = parseNum(ln.unit_price);
        if (unitPrice <= 0 && ln.default_unit_price > 0) {
          unitPrice = parseNum(ln.default_unit_price);
          ln.unit_price = unitPrice;
        }
        if (unitPrice <= 0) {
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
          if (dateInp) dateInp.value = new Date().toISOString().slice(0, 10);
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
                  'تم حفظ الفاتورة، لكن تعذر رفع صورة الطلبية إلى السيرفر.\n\nاضغط «تصوير» مرة أخرى.'
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

  function bootActionBar() {
    if (TB.mountInto) TB.mountInto('m-invoice-actions-host');
    refreshActionBar();
  }

  bootActionBar();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootActionBar);
  }
  syncAll();
})();
