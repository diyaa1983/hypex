(function () {
  'use strict';

  var cfg = window.MReceipt || {};
  var TB = window.MobileToolbar || {};
  var form = document.getElementById('m-rc-form');
  var voucherIdInp = document.getElementById('m-rc-id');
  var dp = cfg.decimalPlaces != null ? cfg.decimalPlaces : 2;
  var voucherIsPosted = false;
  var printDoc = null;
  var checkIdx = 0;
  var customersMap = {};

  (cfg.customers || []).forEach(function (c) {
    customersMap[c.id] = c;
  });

  function parseNum(v) {
    return parseFloat(String(v == null ? '' : v).replace(/,/g, '')) || 0;
  }

  function fmt(n) {
    var p = Math.pow(10, dp);
    return (Math.round(n * p) / p).toFixed(dp);
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function mobileConfirm(msg) {
    if (window.AppDialog && AppDialog.confirm) {
      return AppDialog.confirm(msg, { title: 'تأكيد', okText: 'نعم', cancelText: 'إلغاء' }).then(function (ok) {
        return !!ok;
      });
    }
    return Promise.resolve(window.confirm(msg));
  }

  function getPayMethod() {
    var bank = document.getElementById('m-rc-pay-bank');
    if (bank && bank.checked) return 'bank';
    var chk = document.getElementById('m-rc-pay-check');
    if (chk && chk.checked) return 'check';
    return 'cash';
  }

  function syncPayUi() {
    var method = getPayMethod();
    var isCheck = method === 'check';
    var amtWrap = document.getElementById('m-rc-amount-wrap');
    var checksSec = document.getElementById('m-rc-checks-section');
    if (amtWrap) amtWrap.hidden = isCheck;
    if (checksSec) checksSec.hidden = !isCheck;
    var cashAcc = document.getElementById('m-rc-cash-account');
    if (cashAcc) {
      if (method === 'bank' && cfg.bankAccountId > 0) {
        cashAcc.value = String(cfg.bankAccountId);
      } else if (method === 'cash' && cfg.defaultCashId > 0) {
        cashAcc.value = String(cfg.defaultCashId);
      }
    }
  }

  document.querySelectorAll('input[name="pay_method"]').forEach(function (r) {
    r.addEventListener('change', syncPayUi);
  });
  syncPayUi();

  function addCheckRow(data) {
    var tpl = document.getElementById('m-rc-check-tpl');
    var list = document.getElementById('m-rc-checks-list');
    if (!tpl || !list) return;
    var node = tpl.content.cloneNode(true);
    var card = node.querySelector('.m-rc-check-card');
    if (!card) return;
    var idx = checkIdx++;
    card.dataset.idx = String(idx);
    if (data) {
      var no = card.querySelector('.m-rc-chk-no');
      var amt = card.querySelector('.m-rc-chk-amt');
      var bank = card.querySelector('.m-rc-chk-bank');
      var due = card.querySelector('.m-rc-chk-due');
      if (no) no.value = data.check_no || '';
      if (amt) amt.value = data.check_amount > 0 ? String(data.check_amount) : '';
      if (bank) bank.value = data.bank_name || '';
      if (due) due.value = data.due_date_dmy || data.due_date || '';
    }
    card.querySelector('.m-rc-check-remove').addEventListener('click', function () {
      card.remove();
    });
    list.appendChild(node);
    reindexChecks();
  }

  function reindexChecks() {
    var list = document.getElementById('m-rc-checks-list');
    if (!list) return;
    var cards = list.querySelectorAll('.m-rc-check-card');
    cards.forEach(function (card, i) {
      card.dataset.idx = String(i);
      var title = card.querySelector('.m-rc-check-title');
      if (title) title.textContent = 'شيك ' + (i + 1);
      card.querySelectorAll('input').forEach(function (inp) {
        var cls = inp.classList.contains('m-rc-chk-no')
          ? 'check_no'
          : inp.classList.contains('m-rc-chk-amt')
            ? 'check_amount'
            : inp.classList.contains('m-rc-chk-bank')
              ? 'bank_name'
              : 'due_date';
        inp.name = 'checks[' + i + '][' + cls + ']';
      });
    });
  }

  var btnAddCheck = document.getElementById('m-rc-check-add');
  if (btnAddCheck) btnAddCheck.addEventListener('click', function () { addCheckRow(null); });

  /* عميل */
  var custPicker = document.getElementById('m-rc-customer-picker');
  var custIdInp = document.getElementById('m-rc-customer-id');
  var custGrid = document.getElementById('m-rc-customer-grid');
  var custSearch = document.getElementById('m-rc-customer-search');

  function renderCustomers(q) {
    if (!custGrid) return;
    var query = String(q || '').trim().toLowerCase();
    var items = (cfg.customers || []).filter(function (c) {
      return !query || String(c.name_ar || '').toLowerCase().indexOf(query) >= 0;
    });
    var colors = ['#4f46e5', '#0d9488', '#d97706', '#db2777', '#2563eb'];
    var empty = document.getElementById('m-rc-customer-empty');
    if (!items.length) {
      custGrid.innerHTML = '';
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    custGrid.innerHTML = items
      .map(function (c, i) {
        var letter = String(c.name_ar || '?').charAt(0);
        return (
          '<button type="button" class="m-customer-grid-item" data-id="' +
          c.id +
          '"><span class="m-customer-avatar" style="background:' +
          colors[i % colors.length] +
          '">' +
          escapeHtml(letter) +
          '</span><span class="m-customer-grid-name">' +
          escapeHtml(c.name_ar) +
          '</span></button>'
        );
      })
      .join('');
    custGrid.querySelectorAll('.m-customer-grid-item').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id'), 10);
        var c = customersMap[id];
        if (custIdInp) custIdInp.value = String(id);
        var lbl = document.getElementById('m-rc-customer-label');
        var chosen = document.getElementById('m-rc-customer-chosen');
        if (lbl && c) lbl.textContent = c.name_ar;
        if (chosen) chosen.hidden = false;
        var rep = document.getElementById('m-rc-rep');
        if (rep) rep.value = (c && c.sales_rep_name) || '—';
        if (custPicker) {
          custPicker.hidden = true;
          custPicker.setAttribute('aria-hidden', 'true');
        }
      });
    });
  }

  if (custSearch) custSearch.addEventListener('input', function () { renderCustomers(custSearch.value); });
  renderCustomers('');
  var openCust = document.getElementById('m-rc-open-customer');
  if (openCust)
    openCust.addEventListener('click', function () {
      if (custPicker) {
        custPicker.hidden = false;
        custPicker.removeAttribute('hidden');
      }
    });
  var closeCust = document.getElementById('m-rc-customer-close');
  if (closeCust)
    closeCust.addEventListener('click', function () {
      if (custPicker) {
        custPicker.hidden = true;
        custPicker.setAttribute('aria-hidden', 'true');
      }
    });

  function setPostedUi(posted) {
    voucherIsPosted = !!posted;
    var hasId = parseInt(voucherIdInp && voucherIdInp.value, 10) > 0;
    var badge = document.getElementById('m-rc-posted-badge');
    if (badge) {
      badge.hidden = posted;
      badge.textContent = 'غير مرحّل';
    }
    var vis = { save: !posted };
    if (hasId) {
      vis.print = true;
      vis.pdf = true;
    }
    if (cfg.canPost && hasId && !posted) vis.post = true;
    if (cfg.canDelete && hasId && !posted) vis.delete = true;
    if (TB.show) TB.show(vis);
    var printBtn = TB.btn ? TB.btn('print') : null;
    var pdfBtn = TB.btn ? TB.btn('pdf') : null;
    if (printBtn) printBtn.disabled = !hasId;
    if (pdfBtn) pdfBtn.disabled = !hasId;
    form.querySelectorAll('input, textarea, select, button').forEach(function (el) {
      if (el.closest('#m-main-toolbar') || el.classList.contains('m-rc-check-remove')) {
        return;
      }
      if (el.tagName === 'BUTTON' && (el.id === 'm-rc-open-customer' || el.id === 'm-rc-check-add')) {
        el.disabled = posted;
      } else if (el.name && el.name !== 'voucher_no') {
        el.readOnly = posted;
        el.disabled = posted && el.type === 'button';
      }
    });
  }

  function deleteVoucher() {
    var id = parseInt(voucherIdInp && voucherIdInp.value, 10);
    if (!id || voucherIsPosted || !cfg.canDelete || !cfg.deleteApi) return;
    mobileConfirm('حذف سند القبض؟ لا يمكن التراجع.').then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf);
      fd.append('voucher_id', String(id));
      var deleteBtn = TB.btn ? TB.btn('delete') : null;
      if (deleteBtn) deleteBtn.disabled = true;
      fetch(cfg.deleteApi, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            if (deleteBtn) deleteBtn.disabled = false;
            if (window.AppDialog && AppDialog.error) {
              AppDialog.error((data && data.message) || 'تعذر حذف السند.');
            }
            return;
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم حذف السند.');
          }
          window.location.href = cfg.listUrl || mobile_url_fallback();
        })
        .catch(function () {
          if (deleteBtn) deleteBtn.disabled = false;
          if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function mobile_url_fallback() {
    if (cfg.listUrl) return cfg.listUrl;
    if (window.AppMobile && AppMobile.mobileUrl) return AppMobile.mobileUrl + '?r=m_receipt_list';
    return '';
  }

  function applyVoucher(v) {
    if (!v) return;
    if (voucherIdInp) voucherIdInp.value = String(v.id || '');
    var no = document.getElementById('m-rc-no');
    var date = document.getElementById('m-rc-date');
    var notes = document.getElementById('m-rc-notes');
    var amt = document.getElementById('m-rc-amount');
    if (no) no.value = v.voucher_no || '';
    if (date) date.value = v.voucher_date_dmy || v.voucher_date || '';
    if (notes) notes.value = v.notes || '';
    if (amt) amt.value = v.amount > 0 ? fmt(v.amount) : '';
    if (custIdInp) custIdInp.value = String(v.customer_id || '');
    var c = customersMap[v.customer_id];
    if (c) {
      var lbl = document.getElementById('m-rc-customer-label');
      var chosen = document.getElementById('m-rc-customer-chosen');
      if (lbl) lbl.textContent = c.name_ar;
      if (chosen) chosen.hidden = false;
    }
    var rep = document.getElementById('m-rc-rep');
    if (rep) rep.value = v.sales_rep_name || (c && c.sales_rep_name) || '—';
    var cash = document.getElementById('m-rc-pay-cash');
    var bank = document.getElementById('m-rc-pay-bank');
    var check = document.getElementById('m-rc-pay-check');
    if (v.pay_method === 'check') {
      if (check) check.checked = true;
    } else if (v.pay_method === 'bank') {
      if (bank) bank.checked = true;
    } else if (cash) {
      cash.checked = true;
    }
    syncPayUi();
    var list = document.getElementById('m-rc-checks-list');
    if (list) list.innerHTML = '';
    checkIdx = 0;
    (v.checks || []).forEach(function (chk) {
      addCheckRow(chk);
    });
    if (getPayMethod() === 'check' && !(v.checks || []).length) addCheckRow(null);
    setPostedUi(v.is_posted);
    fetchPrintDoc(v.id);
  }

  function loadVoucher(id) {
    if (!id) return Promise.resolve();
    return fetch(cfg.viewApi + '?id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.voucher) throw new Error('not_found');
        applyVoucher(data.voucher);
      });
  }

  function fetchPrintDoc(id) {
    if (!id) {
      printDoc = null;
      return Promise.resolve();
    }
    return fetch(cfg.printApi + '?id=' + encodeURIComponent(String(id)), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) printDoc = data;
      })
      .catch(function () {
        printDoc = null;
      });
  }

  function trySave() {
    if (!form) return;
    if (voucherIsPosted) {
      if (window.AppDialog && AppDialog.error) AppDialog.error('السند مرحّل — لا يمكن تعديله.');
      return;
    }
    var fd = new FormData(form);
    return fetch(cfg.saveUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Invoice-Save': '1' },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر الحفظ.');
        }
        if (voucherIdInp) voucherIdInp.value = String(data.voucher_id || '');
        var no = document.getElementById('m-rc-no');
        if (no && data.voucher_no) no.value = data.voucher_no;
        setPostedUi(data.is_posted);
        if (window.AppDialog && AppDialog.success) AppDialog.success(data.message || 'تم الحفظ.');
        return loadVoucher(data.voucher_id);
      })
      .catch(function (err) {
        if (window.AppDialog && AppDialog.error) AppDialog.error(err.message || 'تعذر الحفظ.');
      });
  }

  function postVoucher() {
    var id = parseInt(voucherIdInp && voucherIdInp.value, 10);
    if (!id) return;
    mobileConfirm('ترحيل سند القبض؟').then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf);
      fd.append('voucher_id', String(id));
      fetch(cfg.postApi, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) throw new Error((data && data.message) || 'تعذر الترحيل.');
          if (window.AppDialog && AppDialog.success) AppDialog.success(data.message || 'تم الترحيل.');
          loadVoucher(id);
        })
        .catch(function (err) {
          if (window.AppDialog && AppDialog.error) AppDialog.error(err.message || 'تعذر الترحيل.');
        });
    });
  }

  function printHtml(html) {
    if (window.MobileDocList && MobileDocList.printHtml) {
      MobileDocList.printHtml(html, 'm-rc-print-frame');
      return;
    }
    var frame = document.getElementById('m-rc-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'm-rc-print-frame';
      frame.className = 'm-print-frame';
      document.body.appendChild(frame);
    }
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(html);
    win.document.close();
    setTimeout(function () {
      win.focus();
      win.print();
    }, 450);
  }

  function downloadPdf() {
    if (!printDoc || (!printDoc.html && !printDoc.html_pdf && !printDoc.inner_pdf && !printDoc.inner)) {
      if (window.AppDialog && AppDialog.error) AppDialog.error('احفظ السند أولاً أو أعد التحميل.');
      return;
    }
    if (!window.MobilePdfExport || (!MobilePdfExport.exportMobilePdf && !MobilePdfExport.exportReceiptOnMobile)) {
      if (window.AppDialog && AppDialog.error) AppDialog.error('وحدة PDF غير محمّلة. أعد تحميل الصفحة.');
      return;
    }
    var noEl = document.getElementById('m-rc-no');
    var lblEl = document.getElementById('m-rc-customer-label');
    var voucherNo = noEl ? noEl.value : '';
    var customerName = lblEl ? lblEl.textContent.trim() : '';
    var fname =
      window.MobilePdfFilename && MobilePdfFilename.receipt
        ? MobilePdfFilename.receipt(voucherNo, customerName)
        : 'سند قبض - ' + (voucherNo || 'doc') + '.pdf';
    var exportPdf = MobilePdfExport.exportMobilePdf || MobilePdfExport.exportReceiptOnMobile;
    exportPdf(printDoc, { filename: fname, delayMs: 550 }).catch(function () {
      if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تنزيل PDF. جرّب مرة أخرى.');
    });
  }

  var saveBtn = TB.btn ? TB.btn('save') : null;
  if (saveBtn) saveBtn.addEventListener('click', function (e) { e.preventDefault(); trySave(); });
  var postBtn = TB.btn ? TB.btn('post') : null;
  if (postBtn) postBtn.addEventListener('click', postVoucher);
  var printBtn = TB.btn ? TB.btn('print') : null;
  if (printBtn) {
    printBtn.addEventListener('click', function () {
      if (!printDoc || !printDoc.html) {
        if (window.AppDialog && AppDialog.error) AppDialog.error('احفظ السند أولاً.');
        return;
      }
      printHtml(printDoc.html);
    });
  }
  var pdfBtn = TB.btn ? TB.btn('pdf') : null;
  if (pdfBtn) pdfBtn.addEventListener('click', downloadPdf);
  var deleteBtn = TB.btn ? TB.btn('delete') : null;
  if (deleteBtn) deleteBtn.addEventListener('click', deleteVoucher);

  if (cfg.initialId > 0) {
    loadVoucher(cfg.initialId);
  } else {
    setPostedUi(false);
    addCheckRow(null);
  }
})();
