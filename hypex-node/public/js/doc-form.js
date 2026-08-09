(function () {
  'use strict';

  var root = document.getElementById('df-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');
  var locked = !!state.is_locked;
  var defaultTax = Number((state.defaults && state.defaults.tax) || 16);
  var msgEl = document.getElementById('df-msg');
  var tbody = document.getElementById('df-lines-body');
  var partyTimer = null;
  var itemTimers = {};

  function r3(n) {
    return Math.round((Number(n) || 0) * 1000) / 1000;
  }
  function fmt(n) {
    return r3(n).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  }
  function setMsg(text, type) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : type === 'ok' ? ' is-ok' : '');
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
  function escAttr(s) {
    return String(s == null ? '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
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
        '<td><input class="js-qty" type="number" step="0.001" min="0" value="' +
        escAttr(ln.qty) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-qty-extra" type="number" step="0.001" min="0" value="' +
        escAttr(ln.qty_extra || 0) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-price" type="number" step="0.001" min="0" value="' +
        escAttr(ln.unit_price) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-disc" type="number" step="0.001" min="0" max="100" value="' +
        escAttr(ln.discount_pct || 0) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-tax" type="number" step="0.001" min="0" value="' +
        escAttr(ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
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
      if (el)
        el.addEventListener('input', function () {
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
          searchItems(itemInput.value, suggest, tr);
        }, 220);
      });
    }
  }
  function searchItems(q, box, tr) {
    fetch('/api/purchases/items?q=' + encodeURIComponent(q || ''))
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) return;
        box.innerHTML = '';
        (data.rows || []).slice(0, 25).forEach(function (it) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = (it.code || '') + ' — ' + (it.name_ar || '') + ' · ' + fmt(it.sale_price);
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
            box.hidden = true;
            renderLines();
          });
          box.appendChild(b);
        });
        box.hidden = !(data.rows && data.rows.length);
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
})();
