/**
 * جدول بنود طلب شراء العملاء — حسابات/حفظ متوافقة مع فاتورة المبيعات.
 */
(function (global) {
  'use strict';

  function parseNum(v) {
    return parseFloat(String(v == null ? '' : v).replace(/,/g, '')) || 0;
  }

  function initCustomerOrderLines(opts) {
    opts = opts || {};
    var form = document.getElementById(opts.formId || 'customer-order-form');
    var tbody = document.getElementById(opts.tbodyId || 'co-lines-body');
    var tpl = document.getElementById(opts.templateId || 'sales-inv-line-template');
    if (!form || !tbody || !tpl) {
      return null;
    }

    var decimals = parseInt(form.getAttribute('data-decimals') || '2', 10) || 2;
    var unitPriceDp = parseInt(form.getAttribute('data-unit-price-decimals') || String(decimals), 10) || decimals;
    var defaultTax = parseNum(form.getAttribute('data-default-tax-rate') || '15');
    var warehouseId = parseInt(form.getAttribute('data-warehouse-id') || '0', 10) || 0;
    var itemsUrl = form.getAttribute('data-api-items') || '';
    var headerDiscountMode = false;
    var roundMoney = function (n) {
      var f = Math.pow(10, decimals);
      return Math.round((parseNum(n) + Number.EPSILON) * f) / f;
    };
    var roundUnit = function (n) {
      var f = Math.pow(10, unitPriceDp);
      return Math.round((parseNum(n) + Number.EPSILON) * f) / f;
    };
    var fmtAmount = function (n) {
      try {
        return roundMoney(n).toLocaleString('en-US', {
          minimumFractionDigits: Math.min(2, decimals),
          maximumFractionDigits: decimals
        });
      } catch (e) {
        return String(roundMoney(n));
      }
    };
    var formatAmountValue = function (n, empty) {
      n = roundMoney(n);
      if (!(n > 0) && empty === '') return '';
      var s = n.toFixed(Math.min(decimals, 6));
      s = s.replace(/\.?0+$/, '');
      return s === '' ? '0' : s;
    };

    function getRowItemId(tr) {
      return parseInt(tr.getAttribute('data-item-id') || tr.dataset.itemId || '0', 10) || 0;
    }

    function renumber() {
      var n = 0;
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        if (!getRowItemId(tr)) return;
        n += 1;
        var seq = tr.querySelector('.js-seq');
        if (seq) seq.textContent = String(n);
        tr.classList.remove('is-entry-row');
      });
    }

    function taxRateFromRow(tr) {
      var sel = tr.querySelector('.js-tax');
      if (!sel) return defaultTax;
      var opt = sel.options[sel.selectedIndex];
      if (opt && opt.getAttribute('data-rate') != null) {
        return parseNum(opt.getAttribute('data-rate'));
      }
      return defaultTax;
    }

    function selectTaxByRate(tr, rate) {
      var sel = tr.querySelector('.js-tax');
      if (!sel) return;
      var target = parseNum(rate);
      var best = -1;
      var bestDiff = Infinity;
      for (var i = 0; i < sel.options.length; i++) {
        var r = parseNum(sel.options[i].getAttribute('data-rate'));
        var d = Math.abs(r - target);
        if (d < bestDiff) {
          bestDiff = d;
          best = i;
        }
      }
      if (best >= 0) sel.selectedIndex = best;
    }

    function clearHeaderDiscountMode(clearInputs) {
      headerDiscountMode = false;
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        delete tr.dataset.headerDiscShare;
        var dEl = tr.querySelector('.js-discount');
        if (dEl) {
          dEl.readOnly = false;
          dEl.classList.remove('is-header-discount');
          if (clearInputs) dEl.value = '';
        }
      });
    }

    function getLineMerchandiseBeforeTax(tr) {
      var q = parseNum(tr.querySelector('.js-qty') ? tr.querySelector('.js-qty').value : 0);
      var p = parseNum(tr.querySelector('.js-price') ? tr.querySelector('.js-price').value : 0);
      return q > 0 ? roundMoney(q * p) : 0;
    }

    function getLineDiscountAmount(tr, lineBase) {
      if (!(lineBase > 0)) return 0;
      if (headerDiscountMode && tr.dataset.headerDiscShare != null && tr.dataset.headerDiscShare !== '') {
        return roundMoney(parseNum(tr.dataset.headerDiscShare));
      }
      var el = tr.querySelector('.js-discount');
      if (!el || !global.InvDiscount) return 0;
      return global.InvDiscount.amountForBase(lineBase, el.value, roundMoney);
    }

    function recalcRow(tr) {
      if (!getRowItemId(tr)) return;
      var qty = parseNum(tr.querySelector('.js-qty') ? tr.querySelector('.js-qty').value : 0);
      var price = roundUnit(parseNum(tr.querySelector('.js-price') ? tr.querySelector('.js-price').value : 0));
      var base = qty > 0 ? roundMoney(qty * price) : 0;
      var disc = getLineDiscountAmount(tr, base);
      var sub = Math.max(0, roundMoney(base - disc));
      var rate = taxRateFromRow(tr);
      var tax = roundMoney(sub * (rate / 100));
      var gross = roundMoney(sub + tax);

      tr.dataset.sub = String(sub);
      tr.dataset.disc = String(disc);
      tr.dataset.tax = String(tax);
      tr.dataset.gross = String(gross);

      var subEl = tr.querySelector('.js-line-sub');
      if (subEl) subEl.value = formatAmountValue(sub, '0');
      var taxEl = tr.querySelector('.js-tax-amt');
      if (taxEl) taxEl.textContent = fmtAmount(tax);
      var grossEl = tr.querySelector('.js-line-gross');
      if (grossEl) grossEl.value = formatAmountValue(gross, '0');
    }

    function recalcFooter() {
      var sub = 0, tax = 0, gross = 0, disc = 0;
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        if (!getRowItemId(tr)) return;
        sub += parseNum(tr.dataset.sub);
        tax += parseNum(tr.dataset.tax);
        gross += parseNum(tr.dataset.gross);
        disc += parseNum(tr.dataset.disc);
      });
      var elDisc = document.getElementById('sales-inv-sum-disc');
      if (elDisc) elDisc.textContent = fmtAmount(disc);
      var elSub = document.getElementById('sales-inv-sum-sub');
      if (elSub) elSub.textContent = fmtAmount(sub);
      var elTax = document.getElementById('sales-inv-sum-tax');
      if (elTax) elTax.textContent = fmtAmount(tax);
      var elGrand = document.getElementById('sales-inv-sum-grand');
      if (elGrand) elGrand.textContent = fmtAmount(gross);

      var hdrRow = document.getElementById('sales-inv-header-disc-row');
      var hdrSum = document.getElementById('sales-inv-sum-header-disc');
      var hdrInp = document.getElementById('inv-invoice-discount');
      var hdrRaw = hdrInp ? String(hdrInp.value || '').trim() : '';
      var showHdr = !!(headerDiscountMode && hdrRaw && disc > 0);
      if (hdrRow) {
        hdrRow.hidden = !showHdr;
        if (showHdr) hdrRow.removeAttribute('hidden');
        else hdrRow.setAttribute('hidden', '');
      }
      if (hdrSum) hdrSum.textContent = fmtAmount(showHdr ? disc : 0);
    }

    function recalcAll() {
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        if (getRowItemId(tr)) recalcRow(tr);
      });
      recalcFooter();
    }

    function applyHeaderDiscount() {
      var inpEl = document.getElementById('inv-invoice-discount');
      if (!inpEl || !global.InvDiscount) return;
      var raw = String(inpEl.value || '').trim();
      if (!raw) {
        clearHeaderDiscountMode(true);
        recalcAll();
        return;
      }
      var bases = [];
      var rows = [];
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        if (!getRowItemId(tr)) return;
        rows.push(tr);
        bases.push(getLineMerchandiseBeforeTax(tr));
      });
      if (!rows.length) return;
      var sumPreTax = bases.reduce(function (a, b) { return a + b; }, 0);
      var totalDisc = global.InvDiscount.amountForHeaderBase
        ? InvDiscount.amountForHeaderBase(sumPreTax, raw, roundMoney)
        : InvDiscount.amountForBase(sumPreTax, raw, roundMoney);
      var parts = InvDiscount.distribute(totalDisc, bases, roundMoney);
      headerDiscountMode = true;
      rows.forEach(function (tr, i) {
        tr.dataset.headerDiscShare = String(parts[i] || 0);
        var dEl = tr.querySelector('.js-discount');
        if (dEl) {
          dEl.readOnly = true;
          dEl.classList.add('is-header-discount');
          dEl.value = formatAmountValue(parts[i] || 0, '');
        }
      });
      recalcAll();
    }

    function unitFactor(tr) {
      var sel = tr.querySelector('.js-unit');
      if (!sel || sel.selectedIndex < 0) return 1;
      return parseNum(sel.options[sel.selectedIndex].getAttribute('data-factor') || '1') || 1;
    }

    function syncPackHint(tr) {
      var hint = tr.querySelector('.js-pack-hint');
      if (!hint) return;
      var f = unitFactor(tr);
      if (f > 1.0000001) {
        var t = Math.abs(f - Math.round(f)) < 1e-9 ? String(Math.round(f)) : String(Math.round(f * 1e6) / 1e6);
        hint.textContent = 'تعبئة × ' + t;
        hint.hidden = false;
      } else {
        hint.textContent = '';
        hint.hidden = true;
      }
    }

    function fillUnits(tr, units, selectedId) {
      var sel = tr.querySelector('.js-unit');
      if (!sel) return;
      sel.innerHTML = '';
      sel.disabled = false;
      var list = Array.isArray(units) ? units : [];
      if (!list.length) {
        var o = document.createElement('option');
        o.value = selectedId || '';
        o.textContent = 'قطعة';
        o.setAttribute('data-factor', '1');
        o.setAttribute('data-name', 'قطعة');
        sel.appendChild(o);
        return;
      }
      var pick = selectedId || 0;
      if (!pick) {
        list.forEach(function (u) {
          if (!pick && (u.is_default || u.is_default_issue)) {
            pick = parseInt(u.unit_id != null ? u.unit_id : u.id, 10) || 0;
          }
        });
      }
      list.forEach(function (u) {
        var uid = parseInt(u.unit_id != null ? u.unit_id : u.id, 10) || 0;
        var un = String(u.name || u.unit_name || 'قطعة');
        var uf = parseNum(u.factor != null ? u.factor : u.factor_to_base) || 1;
        var o = document.createElement('option');
        o.value = String(uid);
        o.textContent = un;
        o.setAttribute('data-name', un);
        o.setAttribute('data-factor', String(uf));
        if (uid === pick) o.selected = true;
        sel.appendChild(o);
      });
    }

    function newLineId() {
      return 'L-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    function createEmptyRow() {
      var node = tpl.content.cloneNode(true);
      var tr = node.querySelector('tr');
      tr.setAttribute('data-line-id', newLineId());
      tr.setAttribute('data-item-id', '');
      tr.classList.add('is-entry-row');
      return tr;
    }

    function ensureEntryRow() {
      var hasEmpty = false;
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        if (!getRowItemId(tr)) hasEmpty = true;
      });
      if (!hasEmpty) {
        var tr = createEmptyRow();
        tbody.appendChild(tr);
        bindRow(tr);
      }
    }

    function setItemName(tr, name) {
      var el = tr.querySelector('.js-name');
      if (!el) return;
      el.textContent = name || '';
      el.classList.toggle('is-placeholder', !name);
      var lov = tr.querySelector('.sales-inv-item-lov');
      if (lov) lov.classList.toggle('is-empty', !name);
    }

    function applyItemToRow(tr, it, qty) {
      if (!it || !(+it.id > 0)) return;
      var itemId = +it.id;
      var existing = tbody.querySelector('tr[data-item-id="' + itemId + '"]');
      if (existing && existing !== tr) {
        var qEl = existing.querySelector('.js-qty');
        if (qEl) qEl.value = String((parseInt(qEl.value, 10) || 0) + (qty || 1));
        recalcRow(existing);
        recalcFooter();
        if (!getRowItemId(tr)) {
          // keep entry empty
        }
        return existing;
      }
      tr.setAttribute('data-item-id', String(itemId));
      tr.dataset.itemId = String(itemId);
      tr.dataset.nameAr = String(it.name_ar || it.name || '');
      tr.classList.remove('is-entry-row');
      var sku = String(it.barcode || it.sku || '');
      var skuEl = tr.querySelector('.js-sku');
      if (skuEl) skuEl.textContent = sku;
      var bar = tr.querySelector('.js-barcode-inp');
      if (bar) {
        bar.value = '';
        bar.hidden = true;
      }
      if (skuEl) skuEl.hidden = false;
      setItemName(tr, String(it.name_ar || it.name || ''));
      fillUnits(tr, it.units || [], parseInt(it.unit_id, 10) || 0);
      var price = parseNum(it.default_sale != null ? it.default_sale : it.sale_price);
      var pEl = tr.querySelector('.js-price');
      if (pEl && !(parseNum(pEl.value) > 0)) pEl.value = formatAmountValue(roundUnit(price * unitFactor(tr)), '0');
      var qEl2 = tr.querySelector('.js-qty');
      if (qEl2 && !(parseInt(qEl2.value, 10) > 0)) qEl2.value = String(qty || 1);
      selectTaxByRate(tr, defaultTax);
      syncPackHint(tr);
      var rem = tr.querySelector('.js-remove');
      if (rem) rem.style.visibility = '';
      renumber();
      ensureEntryRow();
      recalcRow(tr);
      recalcFooter();
      return tr;
    }

    function bindRow(tr) {
      if (tr.dataset.bound === '1') return;
      tr.dataset.bound = '1';

      tr.addEventListener('input', function (e) {
        var el = e.target;
        if (!el) return;
        if (el.classList.contains('js-discount')) {
          clearHeaderDiscountMode(false);
        }
        if (
          el.classList.contains('js-qty') ||
          el.classList.contains('js-qty-extra') ||
          el.classList.contains('js-price') ||
          el.classList.contains('js-discount') ||
          el.classList.contains('js-line-sub') ||
          el.classList.contains('js-line-gross') ||
          el.classList.contains('js-tax')
        ) {
          if (el.classList.contains('js-line-sub') || el.classList.contains('js-line-gross')) {
            // reverse calc light: keep unit driver
          }
          if ((el.classList.contains('js-qty') || el.classList.contains('js-price')) && headerDiscountMode) {
            applyHeaderDiscount();
            return;
          }
          recalcRow(tr);
          recalcFooter();
        }
      });
      tr.addEventListener('change', function (e) {
        var el = e.target;
        if (!el) return;
        if (el.classList.contains('js-unit')) {
          syncPackHint(tr);
          recalcRow(tr);
          if (headerDiscountMode) applyHeaderDiscount();
          else recalcFooter();
        } else if (el.classList.contains('js-tax')) {
          recalcRow(tr);
          recalcFooter();
        }
      });
      var rem = tr.querySelector('.js-remove');
      if (rem) {
        rem.addEventListener('click', function () {
          if (!getRowItemId(tr) && tbody.querySelectorAll('tr[data-line-id]').length <= 1) return;
          tr.remove();
          renumber();
          ensureEntryRow();
          if (headerDiscountMode) applyHeaderDiscount();
          else {
            recalcAll();
          }
        });
      }
      var pick = tr.querySelector('.js-pick-open');
      if (pick) {
        pick.addEventListener('click', function () {
          openItemPicker(function (it) {
            applyItemToRow(tr, it, 1);
          });
        });
      }
      var bar = tr.querySelector('.js-barcode-inp');
      if (bar) {
        bar.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter') return;
          e.preventDefault();
          resolveBarcode(String(bar.value || '').trim(), tr);
        });
      }
      syncPackHint(tr);
    }

    function buildItemsUrl(q, listAll) {
      var parts = [];
      if (listAll || !q) parts.push('list=1');
      else parts.push('q=' + encodeURIComponent(q));
      if (warehouseId > 0) parts.push('warehouse_id=' + encodeURIComponent(String(warehouseId)));
      return itemsUrl + (itemsUrl.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
    }

    function openItemPicker(onOne) {
      if (!global.ItemPickerModal) return;
      ItemPickerModal.open({
        singleSelect: true,
        screenCenter: true,
        buildItemsUrl: buildItemsUrl,
        getWarehouseId: function () { return warehouseId; },
        emptyMessage: warehouseId > 0 ? 'لا توجد مواد في هذا المستودع' : 'لا توجد مواد مطابقة',
        onSelect: function (it) { onOne(it); },
        onConfirm: function (items) {
          (items || []).forEach(function (it) { onOne(it); });
        }
      });
    }

    function resolveBarcode(code, tr) {
      if (!code || !itemsUrl) return;
      var url = itemsUrl + (itemsUrl.indexOf('?') >= 0 ? '&' : '?') + 'code=' + encodeURIComponent(code);
      if (warehouseId > 0) url += '&warehouse_id=' + encodeURIComponent(String(warehouseId));
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (x) {
          var items = (x && x.items) || [];
          if (!items.length) return;
          applyItemToRow(tr, items[0], 1);
        })
        .catch(function () {});
    }

    function loadLine(ln) {
      var tr = createEmptyRow();
      tr.setAttribute('data-item-id', String(ln.item_id || ''));
      tr.dataset.itemId = String(ln.item_id || '');
      tr.dataset.nameAr = String(ln.item_name || '');
      tr.classList.remove('is-entry-row');
      var sku = String(ln.sku || ln.barcode || ln.item_sku || '');
      var skuEl = tr.querySelector('.js-sku');
      if (skuEl) {
        skuEl.textContent = sku;
        skuEl.hidden = false;
      }
      var bar = tr.querySelector('.js-barcode-inp');
      if (bar) bar.hidden = true;
      setItemName(tr, String(ln.item_name || ''));
      var units = Array.isArray(ln.units) ? ln.units : [];
      if (!units.length && ln.unit_id) {
        units = [{
          unit_id: ln.unit_id,
          name: ln.unit_name || 'قطعة',
          factor: ln.unit_factor || 1
        }];
      }
      fillUnits(tr, units, parseInt(ln.unit_id, 10) || 0);
      var q = tr.querySelector('.js-qty');
      if (q) q.value = String(Math.round(parseNum(ln.qty)) || '');
      var qe = tr.querySelector('.js-qty-extra');
      if (qe) qe.value = String(Math.round(parseNum(ln.qty_extra)) || '');
      var price = parseNum(ln.unit_price);
      if (!(price > 0) && parseNum(ln.item_default_sale) > 0) {
        price = parseNum(ln.item_default_sale) * (parseNum(ln.unit_factor) || unitFactor(tr) || 1);
      }
      var pEl = tr.querySelector('.js-price');
      if (pEl) pEl.value = formatAmountValue(roundUnit(price), '0');
      var dEl = tr.querySelector('.js-discount');
      if (dEl) dEl.value = String(ln.line_discount_input || '').trim();
      selectTaxByRate(tr, ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax);
      var rem = tr.querySelector('.js-remove');
      if (rem) rem.style.visibility = '';
      tbody.appendChild(tr);
      bindRow(tr);
      syncPackHint(tr);
      return tr;
    }

    function collectLines() {
      var lines = [];
      tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
        var itemId = getRowItemId(tr);
        if (!itemId) return;
        var unitSel = tr.querySelector('.js-unit');
        var unitId = 0, unitName = '', factor = 1;
        if (unitSel && unitSel.selectedIndex >= 0) {
          unitId = parseInt(unitSel.value, 10) || 0;
          unitName = unitSel.options[unitSel.selectedIndex].getAttribute('data-name')
            || unitSel.options[unitSel.selectedIndex].textContent.trim();
          factor = parseNum(unitSel.options[unitSel.selectedIndex].getAttribute('data-factor') || '1') || 1;
        }
        var qty = parseInt(tr.querySelector('.js-qty') ? tr.querySelector('.js-qty').value : 0, 10) || 0;
        var qtyExtra = parseInt(tr.querySelector('.js-qty-extra') ? tr.querySelector('.js-qty-extra').value : 0, 10) || 0;
        if (qty < 1) return;
        var discEl = tr.querySelector('.js-discount');
        lines.push({
          item_id: itemId,
          item_name: tr.dataset.nameAr || (tr.querySelector('.js-name') ? tr.querySelector('.js-name').textContent.trim() : ''),
          unit_id: unitId,
          unit_name: unitName,
          unit_factor: factor,
          qty: qty,
          qty_extra: qtyExtra,
          qty_base: (qty + qtyExtra) * factor,
          unit_price: roundUnit(parseNum(tr.querySelector('.js-price') ? tr.querySelector('.js-price').value : 0)),
          line_discount_input: discEl ? String(discEl.value || '').trim() : '',
          discount_pct: 0,
          discount_amount: parseNum(tr.dataset.disc),
          tax_rate_percent: taxRateFromRow(tr),
          line_total: parseNum(tr.dataset.sub),
          tax_amount: parseNum(tr.dataset.tax),
          line_gross: parseNum(tr.dataset.gross)
        });
      });
      return lines;
    }

    function getHeaderDiscount() {
      var el = document.getElementById('inv-invoice-discount');
      return el ? String(el.value || '').trim() : '';
    }

    // init existing server-rendered rows
    tbody.querySelectorAll('tr[data-line-id]').forEach(bindRow);

    var addBtn = document.getElementById('co-add-item');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        openItemPicker(function (it) {
          var empty = null;
          tbody.querySelectorAll('tr[data-line-id]').forEach(function (tr) {
            if (!empty && !getRowItemId(tr)) empty = tr;
          });
          if (!empty) {
            empty = createEmptyRow();
            tbody.appendChild(empty);
            bindRow(empty);
          }
          applyItemToRow(empty, it, 1);
        });
      });
    }

    var hdrDisc = document.getElementById('inv-invoice-discount');
    if (hdrDisc) {
      var hdrTimer = null;
      hdrDisc.addEventListener('input', function () {
        clearTimeout(hdrTimer);
        hdrTimer = setTimeout(applyHeaderDiscount, 250);
      });
      hdrDisc.addEventListener('change', applyHeaderDiscount);
    }

    // load initial lines from JSON blob if present
    var boot = document.getElementById('co-initial-lines-json');
    if (boot) {
      try {
        var initial = JSON.parse(boot.textContent || '[]');
        if (Array.isArray(initial) && initial.length) {
          tbody.innerHTML = '';
          initial.forEach(loadLine);
          var hdrVal = form.getAttribute('data-invoice-discount') || '';
          if (hdrDisc && hdrVal) {
            hdrDisc.value = hdrVal;
            applyHeaderDiscount();
          } else {
            renumber();
            ensureEntryRow();
            recalcAll();
          }
        } else {
          ensureEntryRow();
          renumber();
          recalcAll();
        }
      } catch (e) {
        ensureEntryRow();
      }
    } else {
      ensureEntryRow();
      renumber();
      recalcAll();
    }

    if (hdrDisc && String(hdrDisc.value || '').trim()) {
      applyHeaderDiscount();
    }

    return {
      collectLines: collectLines,
      getHeaderDiscount: getHeaderDiscount,
      recalcAll: recalcAll,
      applyHeaderDiscount: applyHeaderDiscount
    };
  }

  global.initCustomerOrderLines = initCustomerOrderLines;
})(window);
