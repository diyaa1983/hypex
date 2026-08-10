(function () {
  'use strict';

  var root = document.getElementById('co-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');
  var locked = !!state.is_approved;
  var defaultTax = Number((state.defaults && state.defaults.tax) || 16);
  var taxRates = Array.isArray(state.defaults && state.defaults.tax_rates)
    ? state.defaults.tax_rates
    : [];
  var msgEl = document.getElementById('co-msg');
  var tbody = document.getElementById('co-lines-body');
  var custTimer = null;
  var itemTimers = {};
  var busy = false;

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
    return Math.round((Number(n) || 0) * 1000) / 1000;
  }

  function fmt(n) {
    return r3(n).toLocaleString('en-US', {
      minimumFractionDigits: 3,
      maximumFractionDigits: 3,
    });
  }

  function setMsg(text, type) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : type === 'ok' ? ' is-ok' : '');
  }

  function setBusy(on) {
    busy = !!on;
    document.querySelectorAll('#co-doc-bar .si-tb').forEach(function (btn) {
      if (!btn) return;
      if (on) {
        if (!btn.hasAttribute('data-was-disabled')) {
          btn.setAttribute('data-was-disabled', btn.disabled ? '1' : '0');
        }
        btn.disabled = true;
      } else {
        var was = btn.getAttribute('data-was-disabled');
        if (was != null) {
          btn.disabled = was === '1';
          btn.removeAttribute('data-was-disabled');
        }
      }
    });
  }

  function hxConfirm(msg, opts) {
    opts = opts || {};
    if (window.HypexUI && window.HypexUI.confirm) {
      return window.HypexUI.confirm(msg, {
        title: opts.title || 'تأكيد',
        okLabel: opts.okLabel || 'موافق',
        cancelLabel: opts.cancelLabel || 'إلغاء',
      });
    }
    return Promise.resolve(window.confirm(msg));
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
    return { sub: sub, tax: tax, gross: r3(sub + tax), disc: disc };
  }

  function headerDiscountAmount(sumSub, raw) {
    raw = String(raw || '').trim();
    if (!raw || sumSub <= 0) return 0;
    var d = 0;
    if (raw.endsWith('%')) {
      d = r3((sumSub * (parseFloat(raw) || 0)) / 100);
    } else if (raw.indexOf('.') === -1 && Number(raw) >= 1 && Number(raw) <= 100) {
      d = r3((sumSub * Number(raw)) / 100);
    } else {
      d = r3(parseFloat(raw) || 0);
    }
    return Math.min(d, sumSub);
  }

  function recomputeFooter() {
    var sumSub = 0;
    var sumTax = 0;
    (state.lines || []).forEach(function (ln) {
      if (!ln.item_id) return;
      var t = lineTotals(ln);
      sumSub += t.sub;
      sumTax += t.tax;
    });
    sumSub = r3(sumSub);
    sumTax = r3(sumTax);
    var discInput = document.getElementById('co_discount');
    var hDisc = headerDiscountAmount(sumSub, discInput ? discInput.value : '');
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
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function syncLinesFromDom() {
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      readLineFromRow(tr);
    });
  }

  function readLineFromRow(tr) {
    var idx = Number(tr.getAttribute('data-idx'));
    var ln = state.lines[idx] || {};
    var qtyEl = tr.querySelector('.js-qty');
    var qtyExtraEl = tr.querySelector('.js-qty-extra');
    var priceEl = tr.querySelector('.js-price');
    var discEl = tr.querySelector('.js-disc');
    var taxEl = tr.querySelector('.js-tax');
    if (qtyEl) ln.qty = qtyEl.value;
    if (qtyExtraEl) ln.qty_extra = qtyExtraEl.value;
    if (priceEl) ln.unit_price = priceEl.value;
    if (discEl) ln.discount_pct = discEl.value;
    if (taxEl) ln.tax_rate_percent = taxEl.value;
    var hid = tr.querySelector('.js-item-id');
    if (hid && hid.value) ln.item_id = Number(hid.value) || 0;
    state.lines[idx] = ln;
    var t = lineTotals(ln);
    var subEl = tr.querySelector('.js-sub');
    var grossEl = tr.querySelector('.js-gross');
    if (subEl) subEl.textContent = fmt(t.sub);
    if (grossEl) grossEl.textContent = fmt(t.gross);
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
        '</td>' +
        '<td class="si-item-cell">' +
        '<input type="hidden" class="js-item-id" value="' +
        (ln.item_id || '') +
        '">' +
        '<input class="js-item" type="search" placeholder="رمز / باركود / اسم" value="' +
        escAttr((ln.item_code ? ln.item_code + ' — ' : '') + (ln.name_ar || '')) +
        '" ' +
        (locked ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td><input class="js-qty" type="number" step="1" min="0" value="' +
        escAttr(ln.qty) +
        '" ' +
        (locked ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-qty-extra" type="number" step="1" min="0" value="' +
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
        '<td>' +
        taxSelectHtml(ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax, locked) +
        '</td>' +
        '<td class="js-sub si-num-out" dir="ltr">' +
        fmt(t.sub) +
        '</td>' +
        '<td class="js-gross si-num-out" dir="ltr">' +
        fmt(t.gross) +
        '</td>' +
        '<td>' +
        (locked ? '' : '<button type="button" class="si-del js-del" title="حذف">×</button>') +
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
    var itemInput = tr.querySelector('.js-item');
    var suggest = tr.querySelector('.js-item-suggest');
    if (itemInput && suggest && !locked) {
      itemInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        var hid = tr.querySelector('.js-item-id');
        if (hid) hid.value = '';
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          searchItems(itemInput.value, suggest, tr);
        }, 220);
      });
      itemInput.addEventListener('focus', function () {
        searchItems(itemInput.value || '', suggest, tr);
      });
    }
  }

  function removeLineAt(idx) {
    if (locked) return;
    idx = Number(idx);
    if (!Number.isFinite(idx) || idx < 0) return;
    syncLinesFromDom();
    if (!state.lines || !state.lines[idx]) return;
    state.lines.splice(idx, 1);
    if (!state.lines.length) addEmptyLine();
    else renderLines();
    setMsg('تم حذف البند من الجدول.', 'ok');
  }

  function searchItems(q, box, tr) {
    fetch('/api/sales/customer-orders/items?q=' + encodeURIComponent(q || ''))
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
            if (state.lines[idx].tax_rate_percent == null) {
              state.lines[idx].tax_rate_percent = defaultTax;
            }
            box.hidden = true;
            renderLines();
          });
          box.appendChild(b);
        });
        box.hidden = !(data.rows && data.rows.length);
      })
      .catch(function () {
        box.hidden = true;
      });
  }

  function addEmptyLine() {
    if (locked) return;
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
    if (locked || !document.getElementById('co-lines-body')) return;
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
    state.lines[idx].unit_price = Number(it.sale_price) || 0;
    if (!state.lines[idx].qty) state.lines[idx].qty = 1;
    if (state.lines[idx].tax_rate_percent == null) state.lines[idx].tax_rate_percent = defaultTax;
    renderLines();
  });

  document.addEventListener('hx:customer-picked', function (e) {
    if (locked || !document.getElementById('co_customer')) return;
    var c = e.detail;
    if (!c || !c.id) return;
    e.preventDefault();
    if (custId) custId.value = c.id;
    if (custInput) custInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
    if (custBox) custBox.hidden = true;
  });

  document.addEventListener('hx:add-line', function (e) {
    if (locked || !document.getElementById('co-doc-bar')) return;
    e.preventDefault();
    addEmptyLine();
  });

  var custInput = document.getElementById('co_customer');
  var custId = document.getElementById('co_customer_id');
  var custBox = document.getElementById('cust_suggest');
  if (custInput && custBox && !locked) {
    custInput.addEventListener('input', function () {
      clearTimeout(custTimer);
      custTimer = setTimeout(function () {
        fetch('/api/sales/customer-orders/customers?q=' + encodeURIComponent(custInput.value || ''))
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data.ok) return;
            custBox.innerHTML = '';
            (data.rows || []).slice(0, 25).forEach(function (c) {
              var b = document.createElement('button');
              b.type = 'button';
              b.textContent = (c.code || '') + ' — ' + (c.name_ar || '');
              b.addEventListener('click', function () {
                custId.value = c.id;
                custInput.value = (c.code || '') + ' — ' + (c.name_ar || '');
                custBox.hidden = true;
              });
              custBox.appendChild(b);
            });
            custBox.hidden = !(data.rows && data.rows.length);
          });
      }, 220);
    });
    document.addEventListener('click', function (e) {
      if (!custBox.contains(e.target) && e.target !== custInput) custBox.hidden = true;
      document.querySelectorAll('.js-item-suggest').forEach(function (box) {
        if (!box.contains(e.target) && !box.parentElement.contains(e.target)) box.hidden = true;
      });
    });
  }

  var disc = document.getElementById('co_discount');
  if (disc) disc.addEventListener('input', recomputeFooter);

  function collectPayload() {
    syncLinesFromDom();
    return {
      id: state.id || 0,
      order_date: (document.getElementById('co_date') || {}).value || '',
      customer_id: Number((document.getElementById('co_customer_id') || {}).value || 0),
      sales_rep_id: Number((document.getElementById('co_rep') || {}).value || 0) || null,
      warehouse_id: Number((document.getElementById('co_wh') || {}).value || 0) || null,
      notes: (document.getElementById('co_notes') || {}).value || '',
      invoice_discount: (document.getElementById('co_discount') || {}).value || '',
      lines: (state.lines || []).filter(function (ln) {
        return ln && ln.item_id;
      }),
    };
  }

  function saveOrder() {
    if (locked || busy) return Promise.resolve(null);
    var payload = collectPayload();
    if (!payload.customer_id) {
      setMsg('اختر العميل.', 'error');
      return Promise.resolve(null);
    }
    if (!payload.warehouse_id) {
      setMsg('اختر المستودع.', 'error');
      return Promise.resolve(null);
    }
    if (!payload.lines.length) {
      setMsg('أضف بنداً واحداً على الأقل.', 'error');
      return Promise.resolve(null);
    }
    for (var pi = 0; pi < payload.lines.length; pi++) {
      if (!(Number(payload.lines[pi].unit_price) > 0)) {
        setMsg('أدخل السعر لكل بند مادة. لا يمكن الحفظ بدون سعر.', 'error');
        return Promise.resolve(null);
      }
    }
    setMsg('جاري الحفظ…');
    setBusy(true);
    return fetch('/api/sales/customer-orders', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          setMsg(data.error || 'تعذر الحفظ', 'error');
          return null;
        }
        setMsg('تم الحفظ · ' + (data.order_no || ''), 'ok');
        if (data.id && Number(data.id) !== Number(state.id || 0)) {
          if (window.history && window.history.replaceState) {
            state.id = data.id;
            state.order_no = data.order_no || '';
            var noEl = document.getElementById('co_no');
            if (noEl && data.order_no) noEl.value = data.order_no;
            window.history.replaceState({}, '', '/sales/orders/' + data.id);
            var bar = document.getElementById('co-doc-bar');
            if (bar) bar.setAttribute('data-order-id', String(data.id));
            // تفعيل أزرار الطباعة/الاعتماد بعد أول حفظ
            ['co-print', 'co-pdf', 'co-excel'].forEach(function (id) {
              var el = document.getElementById(id);
              if (el) el.disabled = false;
            });
            var appr = document.getElementById('co-approve');
            if (appr && state.can_approve) appr.disabled = false;
            var del = document.getElementById('co-delete');
            if (del && state.can_approve) del.disabled = false;
          } else {
            window.location.href = '/sales/orders/' + data.id;
          }
        } else {
          state.id = data.id;
          var noEl2 = document.getElementById('co_no');
          if (noEl2 && data.order_no) noEl2.value = data.order_no;
          state.order_no = data.order_no || state.order_no;
        }
        return data;
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
        return null;
      });
  }

  function postAction(url, okRedirect) {
    setMsg('جاري التنفيذ…');
    setBusy(true);
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          setMsg(data.error || 'فشل الإجراء', 'error');
          return;
        }
        if (okRedirect) window.location.href = okRedirect;
        else window.location.reload();
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
      });
  }

  function exportExcel() {
    if (!state.id) {
      setMsg('احفظ الطلب أولاً.', 'error');
      return;
    }
    syncLinesFromDom();
    var rows = [
      ['#', 'رمز', 'مادة', 'كمية', 'إضافية', 'سعر', 'خصم%', 'ضريبة%', 'صافي', 'إجمالي'],
    ];
    (state.lines || []).forEach(function (ln, i) {
      if (!ln.item_id) return;
      var t = lineTotals(ln);
      rows.push([
        i + 1,
        ln.item_code || '',
        ln.name_ar || '',
        ln.qty,
        ln.qty_extra || 0,
        ln.unit_price,
        ln.discount_pct || 0,
        ln.tax_rate_percent || 0,
        t.sub,
        t.gross,
      ]);
    });
    var csv = rows
      .map(function (r) {
        return r
          .map(function (c) {
            var s = String(c == null ? '' : c).replace(/"/g, '""');
            return '"' + s + '"';
          })
          .join(',');
      })
      .join('\r\n');
    var bom = '\uFEFF';
    var blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'order_' + (state.order_no || state.id) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    setMsg('تم تصدير Excel (CSV).', 'ok');
  }

  function openPrint() {
    if (!state.id) {
      setMsg('احفظ الطلب أولاً.', 'error');
      return;
    }
    window.open('/sales/orders/' + state.id + '/print', '_blank');
  }

  if (tbody && !tbody._hxDelBound) {
    tbody._hxDelBound = true;
    tbody.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.js-del') : null;
      if (!btn || locked) return;
      e.preventDefault();
      e.stopPropagation();
      var tr = btn.closest('tr');
      if (!tr) return;
      removeLineAt(Number(tr.getAttribute('data-idx')));
    });
  }

  var saveBtn = document.getElementById('co-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      if (locked || busy) return;
      saveOrder();
    });
  }

  var approveBtn = document.getElementById('co-approve');
  if (approveBtn) {
    approveBtn.addEventListener('click', function () {
      if (busy || locked) return;
      if (!state.id) {
        setMsg('احفظ الطلب أولاً ثم اعتمد.', 'error');
        return;
      }
      hxConfirm('اعتماد هذا الطلب؟', { title: 'اعتماد', okLabel: 'اعتماد' }).then(function (ok) {
        if (!ok) return;
        postAction('/api/sales/customer-orders/' + state.id + '/approve', '/sales/orders/' + state.id);
      });
    });
  }

  var unapproveBtn = document.getElementById('co-unapprove');
  if (unapproveBtn) {
    unapproveBtn.addEventListener('click', function () {
      if (busy || !state.id) return;
      hxConfirm('فك اعتماد الطلب وإعادته لمسودة؟', {
        title: 'فك الاعتماد',
        okLabel: 'فك الاعتماد',
      }).then(function (ok) {
        if (!ok) return;
        postAction(
          '/api/sales/customer-orders/' + state.id + '/unapprove',
          '/sales/orders/' + state.id
        );
      });
    });
  }

  var delBtn = document.getElementById('co-delete');
  if (delBtn) {
    delBtn.addEventListener('click', function () {
      if (busy || !state.id || locked) return;
      hxConfirm(
        'تحذير: سيتم حذف بنود الطلب ثم حذف الطلب نهائياً.\nلا يمكن التراجع.',
        { title: 'حذف الطلب', okLabel: 'حذف نهائياً', danger: true }
      ).then(function (ok) {
        if (!ok) return;
        postAction('/api/sales/customer-orders/' + state.id + '/delete', '/sales/orders');
      });
    });
  }

  var printBtn = document.getElementById('co-print');
  if (printBtn) printBtn.addEventListener('click', openPrint);

  var pdfBtn = document.getElementById('co-pdf');
  if (pdfBtn) pdfBtn.addEventListener('click', openPrint);

  var excelBtn = document.getElementById('co-excel');
  if (excelBtn) excelBtn.addEventListener('click', exportExcel);

  var searchBtn = document.getElementById('co-search');
  if (searchBtn) {
    searchBtn.addEventListener('click', function () {
      window.location.href = '/sales/orders';
    });
  }

  renderLines();

  if (window.HypexDocNav) {
    window.HypexDocNav.bind({
      input: 'co_no',
      prevBtn: 'co_prev',
      nextBtn: 'co_next',
      prevId: state.prev_id,
      nextId: state.next_id,
      openPath: '/sales/orders',
      findApi: '/api/sales/customer-orders/by-no',
      currentNo: state.order_no || '',
    });
  }
})();
