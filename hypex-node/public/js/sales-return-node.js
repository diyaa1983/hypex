(function () {
  'use strict';

  var root = document.getElementById('sr-initial');
  if (!root) return;
  var state = JSON.parse(root.textContent || '{}');
  var posted = !!state.is_posted;
  var msgEl = document.getElementById('sr-msg');
  var tbody = document.getElementById('sr-lines-body');
  var invSelect = document.getElementById('ret_invoice');
  var availableLines = [];
  var custTimer = null;
  var busy = false;

  function r3(n) {
    return Math.round((Number(n) || 0) * 1000) / 1000;
  }
  function fmt(n) {
    return r3(n).toLocaleString('en-US', {
      minimumFractionDigits: 3,
      maximumFractionDigits: 3,
    });
  }
  function setMsg(t, type) {
    if (!msgEl) return;
    msgEl.textContent = t || '';
    msgEl.className = 'si-msg' + (type === 'error' ? ' is-error' : type === 'ok' ? ' is-ok' : '');
    if (t && window.HypexUI && window.HypexUI.toast && (type === 'error' || type === 'ok')) {
      window.HypexUI.toast(t, type, type === 'error' ? 4200 : 2800);
    }
  }

  function calcLine(ln) {
    var qty = Number(ln.qty) || 0;
    var price = Number(ln.unit_price) || 0;
    var taxRate = Number(ln.tax_rate_percent) || 0;
    var sold = Number(ln.qty_sold) || 0;
    var lineTotalSold = Number(ln.line_total) || price * sold;
    var sub =
      sold > 0.000001 ? r3((lineTotalSold / sold) * qty) : r3(qty * price);
    var tax = r3(sub * (taxRate / 100));
    return { sub: sub, tax: tax, gross: r3(sub + tax) };
  }

  function recompute() {
    var sumSub = 0,
      sumTax = 0,
      sumGross = 0;
    availableLines.forEach(function (ln) {
      if (!ln.selected) return;
      if ((Number(ln.qty) || 0) <= 0 && (Number(ln.qty_extra) || 0) <= 0) return;
      var t = calcLine(ln);
      sumSub += t.sub;
      sumTax += t.tax;
      sumGross += t.gross;
    });
    var elSub = document.getElementById('sum_sub');
    var elTax = document.getElementById('sum_tax');
    var elG = document.getElementById('sum_grand');
    if (elSub) elSub.textContent = fmt(sumSub);
    if (elTax) elTax.textContent = fmt(sumTax);
    if (elG) elG.textContent = fmt(sumGross);
  }

  function renderLines() {
    if (!tbody) return;
    if (!availableLines.length) {
      tbody.innerHTML =
        '<tr><td colspan="11" class="muted" style="text-align:center;padding:1rem">لا بنود قابلة للإرجاع</td></tr>';
      recompute();
      return;
    }
    tbody.innerHTML = '';
    availableLines.forEach(function (ln, idx) {
      var t = calcLine(ln);
      var tr = document.createElement('tr');
      tr.setAttribute('data-idx', String(idx));
      tr.innerHTML =
        '<td class="sr-check"><input type="checkbox" class="js-sel" ' +
        (ln.selected ? 'checked' : '') +
        (posted ? ' disabled' : '') +
        '></td>' +
        '<td dir="ltr">' +
        (idx + 1) +
        '</td>' +
        '<td dir="ltr">' +
        esc(ln.barcode || '') +
        '</td>' +
        '<td>' +
        esc(ln.name_ar || ln.line_desc || '') +
        '</td>' +
        '<td dir="ltr">' +
        fmt(ln.qty_remaining != null ? ln.qty_remaining : ln.qty_sold) +
        '</td>' +
        '<td><input type="number" class="js-qty" step="0.001" min="0" max="' +
        (ln.qty_remaining != null ? ln.qty_remaining : ln.qty_sold) +
        '" value="' +
        esc(ln.qty || 0) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input type="number" class="js-extra" step="0.001" min="0" value="' +
        esc(ln.qty_extra || 0) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td dir="ltr">' +
        fmt(ln.unit_price) +
        '</td>' +
        '<td class="js-sub" dir="ltr">' +
        fmt(t.sub) +
        '</td>' +
        '<td class="js-tax" dir="ltr">' +
        fmt(t.tax) +
        '</td>' +
        '<td class="js-gross" dir="ltr">' +
        fmt(t.gross) +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr, idx);
    });
    recompute();
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function bindRow(tr, idx) {
    var sel = tr.querySelector('.js-sel');
    var qty = tr.querySelector('.js-qty');
    var extra = tr.querySelector('.js-extra');
    function sync() {
      var ln = availableLines[idx];
      ln.selected = sel.checked;
      ln.qty = Number(qty.value) || 0;
      ln.qty_extra = Number(extra.value) || 0;
      if ((ln.qty > 0 || ln.qty_extra > 0) && !sel.checked) {
        sel.checked = true;
        ln.selected = true;
      }
      var t = calcLine(ln);
      tr.querySelector('.js-sub').textContent = fmt(t.sub);
      tr.querySelector('.js-tax').textContent = fmt(t.tax);
      tr.querySelector('.js-gross').textContent = fmt(t.gross);
      recompute();
    }
    if (sel) sel.addEventListener('change', sync);
    if (qty) qty.addEventListener('input', sync);
    if (extra) extra.addEventListener('input', sync);
  }

  function loadInvoices(customerId, selectedId) {
    if (!invSelect) return Promise.resolve();
    invSelect.innerHTML = '<option value="">جاري التحميل…</option>';
    if (!customerId) {
      invSelect.innerHTML = '<option value="">— اختر العميل أولاً —</option>';
      return Promise.resolve();
    }
    return fetch('/api/sales/return-invoices?customer_id=' + encodeURIComponent(customerId))
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        invSelect.innerHTML = '<option value="">— اختر فاتورة —</option>';
        if (!data.ok) {
          setMsg(data.message || data.error || 'تعذر تحميل الفواتير', 'error');
          return;
        }
        (data.invoices || []).forEach(function (inv) {
          var o = document.createElement('option');
          o.value = inv.id;
          o.textContent =
            (inv.invoice_no || inv.id) +
            ' · ' +
            (inv.invoice_date || '') +
            ' · ' +
            fmt(inv.total);
          if (Number(selectedId) === Number(inv.id)) o.selected = true;
          invSelect.appendChild(o);
        });
        if (!data.invoices || !data.invoices.length) {
          invSelect.innerHTML = '<option value="">لا فواتير مرحّلة قابلة للإرجاع</option>';
        }
      });
  }

  function loadLines(invoiceId) {
    if (!invoiceId) {
      availableLines = [];
      renderLines();
      return Promise.resolve();
    }
    var cid = Number((document.getElementById('ret_customer_id') || {}).value || 0);
    var excl = state.id || 0;
    setMsg('جاري تحميل البنود…');
    return fetch(
      '/api/sales/return-lines?invoice_id=' +
        encodeURIComponent(invoiceId) +
        '&customer_id=' +
        cid +
        '&exclude_return_id=' +
        excl
    )
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          setMsg(data.message || data.error || 'تعذر تحميل البنود', 'error');
          availableLines = [];
          renderLines();
          return;
        }
        setMsg('');
        var saved = {};
        (state.lines || []).forEach(function (ln) {
          saved[ln.invoice_line_id] = ln;
        });
        availableLines = (data.lines || []).map(function (ln) {
          var s = saved[ln.invoice_line_id];
          return {
            invoice_line_id: ln.invoice_line_id,
            item_id: ln.item_id,
            barcode: ln.barcode,
            name_ar: ln.name_ar || ln.line_desc,
            line_desc: ln.line_desc,
            qty_sold: ln.qty_sold,
            qty_remaining: ln.qty_remaining,
            qty_extra_remaining: ln.qty_extra_remaining,
            unit_price: ln.unit_price,
            line_total: ln.line_total,
            tax_rate_percent: ln.tax_rate_percent,
            qty: s ? s.qty : 0,
            qty_extra: s ? s.qty_extra : 0,
            selected: s ? s.qty > 0 || s.qty_extra > 0 : false,
          };
        });
        // إن وُجدت كميات محفوظة غير في المتبقي (قديمة)
        renderLines();
      });
  }

  // customer
  var custInput = document.getElementById('ret_customer');
  var custId = document.getElementById('ret_customer_id');
  var custBox = document.getElementById('ret_cust_suggest');
  if (custInput && custBox && !posted) {
    custInput.addEventListener('input', function () {
      clearTimeout(custTimer);
      custTimer = setTimeout(function () {
        fetch('/api/sales/return-customers?q=' + encodeURIComponent(custInput.value || ''))
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
                state.lines = [];
                availableLines = [];
                renderLines();
                loadInvoices(c.id, 0);
              });
              custBox.appendChild(b);
            });
            custBox.hidden = !(data.rows && data.rows.length);
          });
      }, 220);
    });
    document.addEventListener('click', function (e) {
      if (!custBox.contains(e.target) && e.target !== custInput) custBox.hidden = true;
    });
  }

  if (invSelect && !posted) {
    invSelect.addEventListener('change', function () {
      state.lines = [];
      loadLines(invSelect.value);
    });
  }

  function buildPayload() {
    var lines = availableLines
      .filter(function (ln) {
        return ln.selected && ((Number(ln.qty) || 0) > 0 || (Number(ln.qty_extra) || 0) > 0);
      })
      .map(function (ln) {
        return {
          invoice_line_id: ln.invoice_line_id,
          item_id: ln.item_id,
          qty: Number(ln.qty) || 0,
          qty_extra: Number(ln.qty_extra) || 0,
        };
      });
    return {
      id: state.id || 0,
      return_date: (document.getElementById('ret_date') || {}).value || '',
      customer_id: Number((document.getElementById('ret_customer_id') || {}).value || 0),
      invoice_id: Number((document.getElementById('ret_invoice') || {}).value || 0),
      notes: (document.getElementById('ret_notes') || {}).value || '',
      reason_return: (document.getElementById('ret_reason') || {}).value || '',
      lines: lines,
    };
  }

  function validate(p) {
    if (!p.customer_id) {
      setMsg('اختر العميل.', 'error');
      return false;
    }
    if (!p.invoice_id) {
      setMsg('اختر فاتورة البيع.', 'error');
      return false;
    }
    if (!p.lines.length) {
      setMsg('أدخل كمية إرجاع لمادة واحدة على الأقل.', 'error');
      return false;
    }
    return true;
  }

  function saveThen(cb) {
    if (posted) {
      setMsg('المرتجع مرحّل — الحفظ غير متاح.', 'error');
      return;
    }
    var p = buildPayload();
    if (!validate(p)) return;
    busy = true;
    setMsg('جاري الحفظ…');
    fetch('/api/sales/returns', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(p),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        busy = false;
        if (!data.ok) {
          setMsg(data.error || data.message || 'تعذر الحفظ', 'error');
          return;
        }
        setMsg(data.message || 'تم الحفظ بدون قيود', 'ok');
        state.id = data.id || data.return_id;
        if (data.return_no) {
          var no = document.getElementById('ret_no');
          if (no) no.value = data.return_no;
          state.return_no = data.return_no;
        }
        if (typeof cb === 'function') cb(data);
        else if (location.pathname.indexOf('/form/new') !== -1) {
          location.href = '/sales/returns/form/' + state.id;
        }
      })
      .catch(function () {
        busy = false;
        setMsg('تعذر الاتصال', 'error');
      });
  }

  function postDoc() {
    if (posted) return;
    var p = buildPayload();
    if (!validate(p)) return;
    var reason =
      p.reason_return ||
      prompt('سبب الإرجاع (مطلوب للفوترة):', 'إرجاع بضاعة') ||
      '';
    if (!reason) {
      setMsg('سبب الإرجاع مطلوب للترحيل/الفوترة.', 'error');
      return;
    }
    if (!confirm('ترحيل المرتجع؟\nمخزون + قيود محاسبية، ثم الإرسال للفوترة.')) return;
    busy = true;
    setMsg('جاري الحفظ ثم الترحيل…');
    fetch('/api/sales/returns', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({}, p, { reason_return: reason })),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (saved) {
        if (!saved.ok) {
          busy = false;
          setMsg(saved.error || 'تعذر الحفظ', 'error');
          return null;
        }
        state.id = saved.id || saved.return_id;
        setMsg('تم الحفظ — جاري الترحيل…');
        return fetch('/api/sales/returns/' + state.id + '/post', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ auto_einvoice: true, reason: reason }),
        }).then(function (r) {
          return r.json();
        });
      })
      .then(function (data) {
        busy = false;
        if (!data) return;
        if (!data.ok) {
          setMsg(data.error || data.message || 'تعذر الترحيل', 'error');
          if (state.id) location.href = '/sales/returns/form/' + state.id;
          return;
        }
        setMsg(data.message || 'تم', 'ok');
        setTimeout(function () {
          location.href = '/sales/returns/form/' + (data.return_id || state.id);
        }, 500);
      })
      .catch(function () {
        busy = false;
        setMsg('تعذر الاتصال', 'error');
      });
  }

  function on(id, fn) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', fn);
  }

  on('sr-save', function () {
    if (!busy && !posted) saveThen();
  });
  on('sr-post', function () {
    if (!busy && !posted) postDoc();
  });
  on('sr-search', function () {
    location.href = '/sales/returns/documents';
  });
  on('sr-unpost', function () {
    if (!state.id || !posted) return;
    if (!confirm('فك ترحيل المرتجع؟')) return;
    fetch('/api/sales/returns/' + state.id + '/unpost', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        if (!d.ok) setMsg(d.error || 'فشل', 'error');
        else location.reload();
      });
  });
  on('sr-delete', function () {
    if (!state.id || posted) {
      setMsg(posted ? 'لا يمكن حذف مرحّل' : 'احفظ أولاً', 'error');
      return;
    }
    if (!confirm('حذف المرتجع؟')) return;
    fetch('/api/sales/returns/' + state.id + '/delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        if (!d.ok) setMsg(d.error || 'فشل', 'error');
        else location.href = '/sales/returns/documents';
      });
  });
  on('sr-einvoice', function () {
    if (!state.id || !posted) {
      setMsg('رحّل المرتجع أولاً', 'error');
      return;
    }
    var reason =
      (document.getElementById('ret_reason') || {}).value ||
      prompt('سبب الإرجاع:', 'إرجاع بضاعة') ||
      '';
    if (!reason) return;
    fetch('/api/sales/returns/' + state.id + '/einvoice', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reason: reason }),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        setMsg(d.message || d.error || (d.ok ? 'تم' : 'فشل'), d.ok ? 'ok' : 'error');
      });
  });
  on('sr-print', function () {
    if (!state.id) return setMsg('احفظ أولاً', 'error');
    window.open('/sales/returns/' + state.id + '/print', '_blank');
  });
  on('sr-pdf', function () {
    if (!state.id) return setMsg('احفظ أولاً', 'error');
    window.open('/sales/returns/' + state.id + '/print?pdf=1', '_blank');
  });
  on('sr-archive', function () {
    if (state.archiveUrl) window.open(state.archiveUrl, '_blank');
    else setMsg('الأرشيف من واجهة PHP عند الحاجة', 'error');
  });
  on('sr-email', function () {
    if (!state.id) return setMsg('احفظ أولاً', 'error');
    window.open('/sales/returns/' + state.id + '/print?pdf=1', '_blank');
    setMsg('اطبع PDF ثم أرفقه بالبريد.', 'ok');
  });

  // init
  if (state.customer_id) {
    loadInvoices(state.customer_id, state.invoice_id).then(function () {
      if (state.invoice_id) {
        if (invSelect) invSelect.value = String(state.invoice_id);
        // merge saved lines into available after load
        loadLines(state.invoice_id);
      } else if (state.lines && state.lines.length) {
        availableLines = state.lines.map(function (ln) {
          return Object.assign({}, ln, {
            selected: true,
            qty_remaining: ln.qty_sold || ln.qty,
            line_total: (ln.unit_price || 0) * (ln.qty_sold || ln.qty || 0),
          });
        });
        renderLines();
      }
    });
  } else {
    renderLines();
  }
})();
