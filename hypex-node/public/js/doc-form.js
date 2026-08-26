(function () {
  'use strict';

  var root = document.getElementById('df-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');
  var locked = !!state.is_locked;
  var defaultTax = Number((state.defaults && state.defaults.tax) || 16);
  var taxRates = Array.isArray(state.defaults && state.defaults.tax_rates)
    ? state.defaults.tax_rates
    : [];
  var msgEl = document.getElementById('df-msg');
  var tbody = document.getElementById('df-lines-body');
  var partyTimer = null;
  var itemTimers = {};

  function rateClose(a, b) {
    return Math.abs(Number(a) - Number(b)) < 0.0001;
  }

  function taxRateLabel(t, rate) {
    var pct =
      rate.toLocaleString('en-US', { maximumFractionDigits: 3 }) + '%';
    var name = String((t && t.name_ar) || '').trim();
    if (!name) return pct;
    var nameBare = name.replace(/%/g, '').replace(/,/g, '').replace(/\s+/g, '').trim();
    if (nameBare === '' || rateClose(nameBare, rate)) return pct;
    return pct + ' — ' + name;
  }

  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function taxSelectHtml(selected, disabled) {
    var cur = Number(selected != null && selected !== '' ? selected : defaultTax);
    if (!Number.isFinite(cur)) cur = defaultTax;
    var opts = '';
    var found = false;
    for (var i = 0; i < taxRates.length; i++) {
      var t = taxRates[i];
      var rate = Number(t.rate_percent);
      if (!Number.isFinite(rate)) continue;
      var sel = rateClose(rate, cur);
      if (sel) found = true;
      var label = taxRateLabel(t, rate);
      opts +=
        '<option value="' +
        escAttr(String(rate)) +
        '"' +
        (sel ? ' selected' : '') +
        '>' +
        escAttr(label) +
        '</option>';
    }
    if (!opts || !found) {
      opts =
        '<option value="' +
        escAttr(String(cur)) +
        '" selected>' +
        escAttr(cur + '%') +
        '</option>' +
        opts;
    }
    return (
      '<select class="js-tax"' +
      (disabled ? ' disabled' : '') +
      ' title="نسبة الضريبة">' +
      opts +
      '</select>'
    );
  }

  function r3(n) {
    if (window.HxDec && typeof window.HxDec.roundAmount === 'function') {
      return window.HxDec.roundAmount(n);
    }
    return Math.round((Number(n) || 0) * 1000) / 1000;
  }
  function priceStep() {
    return window.HxDec && window.HxDec.unitStep ? window.HxDec.unitStep() : '0.001';
  }
  function qtyStep() {
    return window.HxDec && window.HxDec.amountStep ? window.HxDec.amountStep() : '0.001';
  }
  function fmt(n) {
    if (window.HxDec && typeof window.HxDec.fmt === 'function') {
      return window.HxDec.fmt(n);
    }
    return r3(n).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  }
  function setMsg(text, type) {
    if (!msgEl) return;
    if (type === 'ok') {
      msgEl.textContent = '';
      msgEl.className = 'si-msg';
      if (text && window.HypexUI && typeof window.HypexUI.toast === 'function') {
        window.HypexUI.toast(text, 'ok', 1000);
      }
      return;
    }
    msgEl.textContent = text || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : '');
    if (text && type === 'error' && window.HypexUI && typeof window.HypexUI.toast === 'function') {
      window.HypexUI.toast(text, 'error', 4200);
    }
  }
  function lineTotals(ln) {
    var qty = Number(ln.qty) || 0;
    var price = Number(ln.unit_price) || 0;
    var discPct = Number(ln.discount_pct) || 0;
    var taxRate = Number(ln.tax_rate_percent) || 0;
    var sub = qty * price;
    var disc = discPct > 0 ? r3((sub * discPct) / 100) : 0;
    sub = r3(sub - disc);
    var tax = r3((sub * taxRate) / 100);
    return { sub: sub, tax: tax, gross: r3(sub + tax) };
  }
  function headerDiscountAmount(sumSub, raw) {
    raw = String(raw || '').trim();
    if (!raw || sumSub <= 0) return 0;
    var d = 0;
    if (raw.endsWith('%')) d = r3((sumSub * (parseFloat(raw) || 0)) / 100);
    else if (raw.indexOf('.') === -1 && Number(raw) >= 1 && Number(raw) <= 100)
      d = r3((sumSub * Number(raw)) / 100);
    else d = r3(parseFloat(raw) || 0);
    return Math.min(d, sumSub);
  }
  function recomputeFooter() {
    var sumSub = 0,
      sumTax = 0;
    (state.lines || []).forEach(function (ln) {
      if (!ln.item_id) return;
      var t = lineTotals(ln);
      sumSub += t.sub;
      sumTax += t.tax;
    });
    sumSub = r3(sumSub);
    sumTax = r3(sumTax);
    var hDisc = headerDiscountAmount(sumSub, (document.getElementById('df_discount') || {}).value);
    if (hDisc > 0 && sumSub > 0) {
      var ratio = (sumSub - hDisc) / sumSub;
      sumTax = r3(sumTax * ratio);
      sumSub = r3(sumSub - hDisc);
    }
    var elSub = document.getElementById('sum_sub');
    var elTax = document.getElementById('sum_tax');
    var elGrand = document.getElementById('sum_grand');
    if (elSub) elSub.textContent = fmt(sumSub);
    if (elTax) elTax.textContent = fmt(sumTax);
    if (elGrand) elGrand.textContent = fmt(r3(sumSub + sumTax));
  }
  function readLineFromRow(tr) {
    var idx = Number(tr.getAttribute('data-idx'));
    var ln = state.lines[idx] || {};
    ln.qty = tr.querySelector('.js-qty').value;
    ln.qty_extra = tr.querySelector('.js-qty-extra').value;
    ln.unit_price = tr.querySelector('.js-price').value;
    ln.discount_pct = tr.querySelector('.js-disc').value;
    ln.tax_rate_percent = tr.querySelector('.js-tax').value;
    state.lines[idx] = ln;
    var t = lineTotals(ln);
    tr.querySelector('.js-sub').textContent = fmt(t.sub);
    tr.querySelector('.js-gross').textContent = fmt(t.gross);
    recomputeFooter();
  }
  function renderLines() {
    if (!tbody) return;
    tbody.innerHTML = '';
    (state.lines || []).forEach(function (ln, idx) {
      var t = lineTotals(ln);
      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td dir="ltr">' +
        (idx + 1) +
        '</td><td class="si-item-cell"><input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '"><input class="js-item" type="search" placeholder="رمز / اسم" value="' +
        escAttr((ln.item_code ? ln.item_code + ' — ' : '') + (ln.name_ar || '')) +
        '" ' +
        (locked ? 'readonly' : '') +
        '><div class="si-suggest js-item-suggest" hidden></div></td>' +
        '<td><input class="js-qty" type="number" step="' +
        qtyStep() +
        '" min="0" value="' +
        escAttr(ln.qty) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-qty-extra" type="number" step="' +
        qtyStep() +
        '" min="0" value="' +
        escAttr(ln.qty_extra || 0) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-price" type="number" step="' +
        priceStep() +
        '" min="0" value="' +
        escAttr(ln.unit_price) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-disc" type="number" step="' +
        qtyStep() +
        '" min="0" max="100" value="' +
        escAttr(ln.discount_pct || 0) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td>' +
        taxSelectHtml(ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax, locked) +
        '</td>' +
        '<td class="js-sub si-num-out" dir="ltr">' +
        fmt(t.sub) +
        '</td><td class="js-gross si-num-out" dir="ltr">' +
        fmt(t.gross) +
        '</td><td>' +
        (locked ? '' : '<button type="button" class="si-del js-del">×</button>') +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr);
    });
    recomputeFooter();
  }
  function bindRow(tr) {
    ['js-qty', 'js-qty-extra', 'js-price', 'js-disc', 'js-tax'].forEach(function (cls) {
      var el = tr.querySelector('.' + cls);
      if (!el) return;
      var ev = el.tagName === 'SELECT' ? 'change' : 'input';
      el.addEventListener(ev, function () {
        readLineFromRow(tr);
      });
    });
    var del = tr.querySelector('.js-del');
    if (del)
      del.addEventListener('click', function () {
        state.lines.splice(Number(tr.getAttribute('data-idx')), 1);
        if (!state.lines.length) addEmptyLine();
        else renderLines();
      });
    var itemInput = tr.querySelector('.js-item');
    var suggest = tr.querySelector('.js-item-suggest');
    if (itemInput && suggest && !locked) {
      itemInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          searchItems(itemInput.value, suggest, tr, itemInput);
        }, 220);
      });
      itemInput.addEventListener('focus', function () {
        searchItems(itemInput.value || '', suggest, tr, itemInput);
      });
      itemInput.addEventListener('click', function () {
        if (!suggest.hidden) placeFloatSuggest(suggest, itemInput);
        else searchItems(itemInput.value || '', suggest, tr, itemInput);
      });
    }
  }

  function placeFloatSuggest(box, anchor) {
    if (!box || !anchor) return;
    if (!box._hxHome) box._hxHome = box.parentNode;
    if (box.parentNode !== document.body) {
      document.body.appendChild(box);
    }
    var r = anchor.getBoundingClientRect();
    var width = Math.min(Math.max(Math.round(r.width), 220), Math.min(420, window.innerWidth - 16));
    var left = Math.round(r.right - width);
    if (left < 8) left = 8;
    if (left + width > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - width - 8);
    }
    var top = Math.round(r.bottom + 3);
    var approxH = Math.min(260, window.innerHeight * 0.45);
    if (top + 100 > window.innerHeight && r.top > approxH + 8) {
      top = Math.max(8, Math.round(r.top - 3 - approxH));
    }
    box.hidden = false;
    box.removeAttribute('hidden');
    box.classList.add('si-suggest--float');
    box.style.display = 'block';
    box.style.position = 'fixed';
    box.style.zIndex = '12050';
    box.style.width = width + 'px';
    box.style.minWidth = width + 'px';
    box.style.maxWidth = width + 'px';
    box.style.left = left + 'px';
    box.style.right = 'auto';
    box.style.top = top + 'px';
    box.style.inset = 'auto';
    box.style.visibility = 'visible';
    box.style.opacity = '1';
    box.style.pointerEvents = 'auto';
  }

  function closeItemSuggest(box) {
    if (!box) return;
    box.hidden = true;
    box.setAttribute('hidden', '');
    box.classList.remove('si-suggest--float');
    box.style.display = '';
    box.style.position = '';
    box.style.left = '';
    box.style.right = '';
    box.style.top = '';
    box.style.width = '';
    box.style.minWidth = '';
    box.style.maxWidth = '';
    box.style.zIndex = '';
    box.style.inset = '';
    if (box._hxHome && box._hxHome.parentNode && box.parentNode === document.body) {
      try {
        box._hxHome.appendChild(box);
      } catch (e) {
        /* ignore */
      }
    }
  }

  function searchItems(q, box, tr, anchor) {
    if (!box) return;
    var token = String((searchItems._seq = (searchItems._seq || 0) + 1));
    box._dfSearchToken = token;
    fetch('/api/purchases/items?q=' + encodeURIComponent(q || ''))
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (box._dfSearchToken !== token) return;
        box.innerHTML = '';
        if (!data.ok) {
          closeItemSuggest(box);
          return;
        }
        var rows = data.rows || [];
        if (!rows.length) {
          var empty = document.createElement('div');
          empty.className = 'si-suggest-empty';
          empty.style.cssText = 'padding:.55rem .75rem;color:#64748b;font-size:.82rem;text-align:right';
          empty.textContent = q ? 'لا توجد نتائج مطابقة' : 'اكتب رمز أو اسم المادة…';
          box.appendChild(empty);
          placeFloatSuggest(box, anchor || tr.querySelector('.js-item'));
          return;
        }
        rows.slice(0, 25).forEach(function (it) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = (it.code || '') + ' — ' + (it.name_ar || '') + ' · ' + fmt(it.sale_price);
          b.addEventListener('mousedown', function (e) {
            e.preventDefault();
          });
          b.addEventListener('click', function () {
            var idx = Number(tr.getAttribute('data-idx'));
            state.lines[idx] = state.lines[idx] || {};
            state.lines[idx].item_id = it.id;
            state.lines[idx].item_code = it.code;
            state.lines[idx].name_ar = it.name_ar;
            state.lines[idx].unit_price = Number(it.sale_price) || 0;
            if (!state.lines[idx].qty) state.lines[idx].qty = 1;
            if (state.lines[idx].tax_rate_percent == null)
              state.lines[idx].tax_rate_percent = defaultTax;
            closeItemSuggest(box);
            renderLines();
            if (window.HxShortcuts && window.HxShortcuts.focusLineQty) {
              window.HxShortcuts.focusLineQty(idx, '#df-lines-body');
            }
          });
          box.appendChild(b);
        });
        placeFloatSuggest(box, anchor || tr.querySelector('.js-item'));
      })
      .catch(function () {
        if (box._dfSearchToken !== token) return;
        closeItemSuggest(box);
      });
  }
  function addEmptyLine() {
    state.lines = state.lines || [];
    state.lines.push({
      item_id: 0,
      item_code: '',
      name_ar: '',
      qty: 1,
      qty_extra: 0,
      unit_price: 0,
      discount_pct: 0,
      tax_rate_percent: defaultTax,
    });
    renderLines();
  }

  document.addEventListener('hx:item-picked', function (e) {
    if (locked || !document.getElementById('df-lines-body')) return;
    var it = e.detail;
    if (!it || !it.id) return;
    e.preventDefault();
    var idx = -1;
    for (var i = 0; i < (state.lines || []).length; i++) {
      if (!state.lines[i] || !state.lines[i].item_id) {
        idx = i;
        break;
      }
    }
    if (idx < 0) {
      addEmptyLine();
      idx = state.lines.length - 1;
    }
    state.lines[idx] = state.lines[idx] || {};
    state.lines[idx].item_id = it.id;
    state.lines[idx].item_code = it.code || it.sku || '';
    state.lines[idx].name_ar = it.name_ar || '';
    state.lines[idx].unit_price = Number(it.sale_price || it.default_sale || it.default_cost) || 0;
    if (!state.lines[idx].qty) state.lines[idx].qty = 1;
    if (state.lines[idx].tax_rate_percent == null) state.lines[idx].tax_rate_percent = defaultTax;
    renderLines();
    if (window.HxShortcuts && window.HxShortcuts.focusLineQty) {
      window.HxShortcuts.focusLineQty(idx, '#df-lines-body');
    }
  });

  document.addEventListener('hx:customer-picked', function (e) {
    if (locked || !document.getElementById('df_party')) return;
    var c = e.detail;
    if (!c || !c.id) return;
    e.preventDefault();
    if (partyId) partyId.value = c.id;
    if (partyInput) partyInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
    if (partyBox) partyBox.hidden = true;
  });

  document.addEventListener('hx:add-line', function (e) {
    if (locked || !document.getElementById('df-add-line')) return;
    e.preventDefault();
    addEmptyLine();
  });

  var partyInput = document.getElementById('df_party');
  var partyId = document.getElementById('df_party_id');
  var partyBox = document.getElementById('party_suggest');
  if (partyInput && partyBox && !locked) {
    partyInput.addEventListener('input', function () {
      clearTimeout(partyTimer);
      partyTimer = setTimeout(function () {
        fetch('/api/purchases/suppliers?q=' + encodeURIComponent(partyInput.value || ''))
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) return;
            partyBox.innerHTML = '';
            (data.rows || []).slice(0, 25).forEach(function (c) {
              var b = document.createElement('button');
              b.type = 'button';
              b.textContent = (c.code || '') + ' — ' + (c.name_ar || '');
              b.addEventListener('click', function () {
                partyId.value = c.id;
                partyInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
                partyBox.hidden = true;
              });
              partyBox.appendChild(b);
            });
            partyBox.hidden = !(data.rows && data.rows.length);
          });
      }, 220);
    });
    document.addEventListener('click', function (e) {
      if (!partyBox.contains(e.target) && e.target !== partyInput) partyBox.hidden = true;
    });
  }

  document.addEventListener('click', function (e) {
    if (!document.getElementById('df-lines-body')) return;
    document.querySelectorAll('.js-item-suggest').forEach(function (box) {
      if (box.hidden) return;
      var home = box._hxHome || box.parentNode;
      var tr = (home && home.closest && home.closest('tr[data-idx]')) || box.closest('tr[data-idx]');
      var itemEl = tr && tr.querySelector('.js-item');
      if (box.contains(e.target) || e.target === itemEl) return;
      closeItemSuggest(box);
    });
  });

  var addBtn = document.getElementById('df-add-line');
  if (addBtn) addBtn.addEventListener('click', addEmptyLine);
  var disc = document.getElementById('df_discount');
  if (disc) disc.addEventListener('input', recomputeFooter);

  var saveBtn = document.getElementById('df-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      if (locked) return;
      tbody.querySelectorAll('tr').forEach(readLineFromRow);
      var payload = {
        id: state.id || 0,
        doc_date: (document.getElementById('df_date') || {}).value || '',
        expected_date: (document.getElementById('df_expected') || {}).value || '',
        supplier_id: Number((document.getElementById('df_party_id') || {}).value || 0),
        warehouse_id: Number((document.getElementById('df_wh') || {}).value || 0) || null,
        payment_type: (document.getElementById('df_pay') || {}).value || 'credit',
        reference_no: (document.getElementById('df_ref') || {}).value || '',
        notes: (document.getElementById('df_notes') || {}).value || '',
        invoice_discount: (document.getElementById('df_discount') || {}).value || '',
        lines: (state.lines || []).filter(function (ln) {
          return ln && ln.item_id;
        }),
      };
      if (!payload.supplier_id) {
        setMsg('اختر المورد.', 'error');
        return;
      }
      if (!payload.warehouse_id) {
        setMsg('اختر المستودع.', 'error');
        return;
      }
      if (!payload.lines.length) {
        setMsg('أضف بنداً واحداً.', 'error');
        return;
      }
      for (var pi = 0; pi < payload.lines.length; pi++) {
        if (!(Number(payload.lines[pi].unit_price) > 0)) {
          setMsg('أدخل السعر لكل بند مادة. لا يمكن الحفظ بدون سعر.', 'error');
          return;
        }
      }
      setMsg('جاري الحفظ…');
      saveBtn.disabled = true;
      fetch(state.apiSave || '/api/purchases/orders', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          saveBtn.disabled = false;
          if (!data.ok) {
            setMsg(data.error || 'تعذر الحفظ', 'error');
            return;
          }
          setMsg('تم الحفظ · ' + (data.doc_no || ''), 'ok');
          if (data.id && Number(data.id) !== Number(state.id)) {
            if (state.kind === 'pur_invoice') window.location.href = '/purchases/invoices/' + data.id;
            else window.location.href = '/purchases/orders/' + data.id;
          } else {
            state.id = data.id;
            var noEl = document.getElementById('df_no');
            if (noEl && data.doc_no) noEl.value = data.doc_no;
          }
        })
        .catch(function () {
          saveBtn.disabled = false;
          setMsg('تعذر الاتصال', 'error');
        });
    });
  }

  renderLines();

  if (window.HypexDocNav) {
    window.HypexDocNav.bind({
      input: 'df_no',
      prevBtn: 'df_prev',
      nextBtn: 'df_next',
      prevId: state.prev_id,
      nextId: state.next_id,
      openPath: state.openPath || (state.kind === 'pur_invoice' ? '/purchases/invoices' : '/purchases/orders'),
      findApi: state.findApi || (state.kind === 'pur_invoice' ? '/api/purchases/invoices/by-no' : '/api/purchases/orders/by-no'),
      currentNo: state.doc_no || '',
    });
  }
})();
