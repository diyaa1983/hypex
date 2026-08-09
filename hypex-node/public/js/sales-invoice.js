(function () {
  'use strict';

  var root = document.getElementById('si-initial');
  if (!root) return;

  var state = JSON.parse(root.textContent || '{}');
  var posted = !!state.is_posted;
  var defaultTax = Number((state.defaults && state.defaults.tax) || 16);
  var msgEl = document.getElementById('si-msg');
  var tbody = document.getElementById('si-lines-body');
  var custTimer = null;
  var itemTimers = {};
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
    var sumGross = 0;
    (state.lines || []).forEach(function (ln) {
      if (!ln.item_id) return;
      var t = lineTotals(ln);
      sumSub += t.sub;
      sumTax += t.tax;
      sumGross += t.gross;
    });
    sumSub = r3(sumSub);
    sumTax = r3(sumTax);
    sumGross = r3(sumGross);
    var discInput = document.getElementById('inv_discount');
    var hDisc = headerDiscountAmount(sumSub, discInput ? discInput.value : '');
    if (hDisc > 0 && sumSub > 0) {
      var ratio = (sumSub - hDisc) / sumSub;
      sumTax = r3(sumTax * ratio);
      sumSub = r3(sumSub - hDisc);
      sumGross = r3(sumSub + sumTax);
    }
    var elSub = document.getElementById('sum_sub');
    var elTax = document.getElementById('sum_tax');
    var elGrand = document.getElementById('sum_grand');
    if (elSub) elSub.textContent = fmt(sumSub);
    if (elTax) elTax.textContent = fmt(sumTax);
    if (elGrand) elGrand.textContent = fmt(sumGross);
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

  function syncLinesFromDom() {
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      readLineFromRow(tr);
    });
  }

  function buildPayload() {
    syncLinesFromDom();
    return {
      id: state.id || 0,
      invoice_no: (document.getElementById('inv_no') || {}).value || '',
      invoice_date: (document.getElementById('inv_date') || {}).value || '',
      customer_id: Number((document.getElementById('inv_customer_id') || {}).value || 0),
      payment_type: (document.getElementById('inv_pay') || {}).value || 'credit',
      warehouse_id: Number((document.getElementById('inv_wh') || {}).value || 0) || null,
      notes: (document.getElementById('inv_notes') || {}).value || '',
      invoice_discount: (document.getElementById('inv_discount') || {}).value || '',
      lines: (state.lines || []).filter(function (ln) {
        return ln && ln.item_id;
      }),
    };
  }

  function validatePayload(payload) {
    if (!payload.customer_id) {
      setMsg('اختر العميل.', 'error');
      return false;
    }
    if (!payload.lines.length) {
      setMsg('أضف بنداً واحداً على الأقل.', 'error');
      return false;
    }
    return true;
  }

  function setBusy(on) {
    busy = !!on;
    ['si-save', 'si-post', 'si-unpost', 'si-delete', 'si-einvoice'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el && !el.dataset.keepDisabled) {
        if (on) el.disabled = true;
      }
    });
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
        (posted ? 'readonly' : '') +
        '>' +
        '<div class="si-suggest js-item-suggest" hidden></div>' +
        '</td>' +
        '<td><input class="js-qty" type="number" step="0.001" min="0" value="' +
        escAttr(ln.qty) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-qty-extra" type="number" step="0.001" min="0" value="' +
        escAttr(ln.qty_extra || 0) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-price" type="number" step="0.001" min="0" value="' +
        escAttr(ln.unit_price) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-disc" type="number" step="0.001" min="0" max="100" value="' +
        escAttr(ln.discount_pct || 0) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td><input class="js-tax" type="number" step="0.001" min="0" value="' +
        escAttr(ln.tax_rate_percent != null ? ln.tax_rate_percent : defaultTax) +
        '" ' +
        (posted ? 'readonly' : '') +
        '></td>' +
        '<td class="js-sub si-num-out" dir="ltr">' +
        fmt(t.sub) +
        '</td>' +
        '<td class="js-gross si-num-out" dir="ltr">' +
        fmt(t.gross) +
        '</td>' +
        '<td>' +
        (posted ? '' : '<button type="button" class="si-del js-del" title="حذف">×</button>') +
        '</td>';
      tbody.appendChild(tr);
      bindRow(tr);
    });
    recomputeFooter();
  }

  function escAttr(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
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
    if (del) {
      del.addEventListener('click', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        state.lines.splice(idx, 1);
        if (!state.lines.length) addEmptyLine();
        else renderLines();
      });
    }
    var itemInput = tr.querySelector('.js-item');
    var suggest = tr.querySelector('.js-item-suggest');
    if (itemInput && suggest && !posted) {
      itemInput.addEventListener('input', function () {
        var idx = Number(tr.getAttribute('data-idx'));
        clearTimeout(itemTimers[idx]);
        itemTimers[idx] = setTimeout(function () {
          searchItems(itemInput.value, suggest, tr);
        }, 220);
      });
      itemInput.addEventListener('focus', function () {
        if (itemInput.value.length < 1) searchItems('', suggest, tr);
      });
    }
  }

  function searchItems(q, box, tr) {
    fetch('/api/items?q=' + encodeURIComponent(q || ''))
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) return;
        box.innerHTML = '';
        (data.rows || []).slice(0, 25).forEach(function (it) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent =
            (it.code || '') + ' — ' + (it.name_ar || '') + ' · ' + fmt(it.sale_price);
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

  // customer search
  var custInput = document.getElementById('inv_customer');
  var custId = document.getElementById('inv_customer_id');
  var custBox = document.getElementById('cust_suggest');
  if (custInput && custBox && !posted) {
    custInput.addEventListener('input', function () {
      clearTimeout(custTimer);
      custTimer = setTimeout(function () {
        fetch('/api/customers?q=' + encodeURIComponent(custInput.value || ''))
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

  var addBtn = document.getElementById('si-add-line');
  if (addBtn) addBtn.addEventListener('click', addEmptyLine);

  var disc = document.getElementById('inv_discount');
  if (disc) disc.addEventListener('input', recomputeFooter);

  function saveInvoice(then) {
    if (posted) {
      setMsg('الفاتورة مرحّلة — الحفظ غير متاح.', 'error');
      return Promise.resolve(null);
    }
    var payload = buildPayload();
    if (!validatePayload(payload)) return Promise.resolve(null);
    setMsg('جاري الحفظ…');
    setBusy(true);
    return fetch('/api/sales/invoices', {
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
        setMsg(data.message || 'تم الحفظ بدون قيود محاسبية · ' + (data.invoice_no || ''), 'ok');
        state.id = data.id;
        if (data.invoice_no) {
          var noEl = document.getElementById('inv_no');
          if (noEl) noEl.value = data.invoice_no;
          state.invoice_no = data.invoice_no;
        }
        var bar = document.getElementById('si-doc-bar');
        if (bar) bar.setAttribute('data-invoice-id', String(data.id));
        if (typeof then === 'function') return then(data);
        if (data.id && location.pathname.indexOf('/sales/invoices/new') !== -1) {
          window.location.href = '/sales/invoices/' + data.id;
        }
        return data;
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
        return null;
      });
  }

  function postInvoice() {
    if (posted) {
      setMsg('الفاتورة مرحّلة مسبقاً.', 'error');
      return;
    }
    var payload = buildPayload();
    if (!validatePayload(payload)) return;
    if (
      !confirm(
        'ترحيل الفاتورة؟\nسيتم: إنشاء القيود المحاسبية + خصم المستودع، ثم الإرسال إلى الفوترة الإلكترونية.'
      )
    ) {
      return;
    }
    setMsg('جاري الحفظ ثم الترحيل…');
    setBusy(true);
    // احفظ أولاً ثم رحّل
    fetch('/api/sales/invoices', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (saved) {
        if (!saved.ok) {
          setBusy(false);
          setMsg(saved.error || 'تعذر الحفظ قبل الترحيل', 'error');
          return null;
        }
        state.id = saved.id;
        setMsg('تم الحفظ — جاري الترحيل والفوترة…');
        return fetch('/api/sales/invoices/' + saved.id + '/post', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ auto_einvoice: true }),
        }).then(function (r) {
          return r.json();
        });
      })
      .then(function (data) {
        setBusy(false);
        if (!data) return;
        if (!data.ok) {
          setMsg(data.error || data.message || 'تعذر الترحيل', 'error');
          if (state.id) window.location.href = '/sales/invoices/' + state.id;
          return;
        }
        setMsg(data.message || 'تم الترحيل.', 'ok');
        setTimeout(function () {
          window.location.href = '/sales/invoices/' + (data.invoice_id || state.id);
        }, 600);
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال بالخادم', 'error');
      });
  }

  function unpostInvoice() {
    if (!state.id || !posted) return;
    if (!confirm('فك ترحيل الفاتورة؟ (عكس القيود وإرجاع المخزون)')) return;
    setBusy(true);
    setMsg('جاري فك الترحيل…');
    fetch('/api/sales/invoices/' + state.id + '/unpost', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          setMsg(data.error || data.message || 'تعذر فك الترحيل', 'error');
          return;
        }
        setMsg(data.message || 'تم فك الترحيل', 'ok');
        setTimeout(function () {
          location.reload();
        }, 500);
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال', 'error');
      });
  }

  function deleteInvoice() {
    if (!state.id || posted) {
      setMsg(posted ? 'لا يمكن حذف فاتورة مرحّلة.' : 'احفظ الفاتورة أولاً.', 'error');
      return;
    }
    if (!confirm('حذف الفاتورة نهائياً؟')) return;
    setBusy(true);
    fetch('/api/sales/invoices/' + state.id + '/delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          setMsg(data.error || data.message || 'تعذر الحذف', 'error');
          return;
        }
        window.location.href = '/sales/invoices';
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال', 'error');
      });
  }

  function sendEinvoice() {
    if (!state.id || !posted) {
      setMsg('يجب ترحيل الفاتورة قبل الإرسال إلى الفوترة.', 'error');
      return;
    }
    if (!confirm('إرسال الفاتورة إلى الفوترة الإلكترونية؟')) return;
    setBusy(true);
    setMsg('جاري الإرسال للفوترة…');
    fetch('/api/sales/invoices/' + state.id + '/einvoice', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setBusy(false);
        if (!data.ok) {
          setMsg(data.error || data.message || 'فشل الإرسال', 'error');
          return;
        }
        setMsg(data.message || 'تم الإرسال', 'ok');
      })
      .catch(function () {
        setBusy(false);
        setMsg('تعذر الاتصال', 'error');
      });
  }

  function exportExcel() {
    if (!state.id) {
      setMsg('احفظ الفاتورة أولاً.', 'error');
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
    a.download = 'invoice_' + (state.invoice_no || state.id) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    setMsg('تم تصدير Excel (CSV).', 'ok');
  }

  function openPrint(pdf) {
    if (!state.id) {
      setMsg('احفظ الفاتورة أولاً.', 'error');
      return;
    }
    var url = '/sales/invoices/' + state.id + '/print' + (pdf ? '?pdf=1' : '');
    window.open(url, '_blank');
  }

  // toolbar bindings
  var saveBtn = document.getElementById('si-save');
  if (saveBtn) saveBtn.addEventListener('click', function () {
    if (busy || posted) return;
    saveInvoice();
  });

  var postBtn = document.getElementById('si-post');
  if (postBtn) postBtn.addEventListener('click', function () {
    if (busy || posted) return;
    postInvoice();
  });

  var unpostBtn = document.getElementById('si-unpost');
  if (unpostBtn) unpostBtn.addEventListener('click', unpostInvoice);

  var delBtn = document.getElementById('si-delete');
  if (delBtn) delBtn.addEventListener('click', deleteInvoice);

  var einvBtn = document.getElementById('si-einvoice');
  if (einvBtn) einvBtn.addEventListener('click', sendEinvoice);

  var printBtn = document.getElementById('si-print');
  if (printBtn) printBtn.addEventListener('click', function () {
    openPrint(false);
  });

  var pdfBtn = document.getElementById('si-pdf');
  if (pdfBtn) pdfBtn.addEventListener('click', function () {
    openPrint(true);
  });

  var excelBtn = document.getElementById('si-excel');
  if (excelBtn) excelBtn.addEventListener('click', exportExcel);

  var searchBtn = document.getElementById('si-search');
  if (searchBtn) {
    searchBtn.addEventListener('click', function () {
      window.location.href = '/sales/invoices';
    });
  }

  var archiveBtn = document.getElementById('si-archive');
  if (archiveBtn) {
    archiveBtn.addEventListener('click', function () {
      if (!state.id) {
        setMsg('احفظ الفاتورة أولاً.', 'error');
        return;
      }
      var url = (state.defaults && state.defaults.archiveUrl) || '';
      if (url) window.open(url, '_blank');
      else setMsg('الأرشيف متاح من واجهة PHP عند الحاجة.', 'error');
    });
  }

  var emailBtn = document.getElementById('si-email');
  if (emailBtn) {
    emailBtn.addEventListener('click', function () {
      if (!state.id) {
        setMsg('احفظ الفاتورة أولاً.', 'error');
        return;
      }
      openPrint(true);
      setMsg('اطبع/احفظ PDF من النافذة ثم أرفقه يدوياً بالبريد إن لزم.', 'ok');
    });
  }

  renderLines();
})();
