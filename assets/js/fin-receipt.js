(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('fin-rc-form');
  if (!form) return;

  var apiVoucherUrl = form.getAttribute('data-api-voucher') || '';
  var voucherPostUrl = form.getAttribute('data-voucher-post-url') || '';
  var voucherUnpostUrl = form.getAttribute('data-voucher-unpost-url') || '';
  var voucherCancelUrl = form.getAttribute('data-voucher-cancel-url') || '';
  var voucherDeleteUrl = form.getAttribute('data-voucher-delete-url') || '';
  var apiEditUnlock = form.getAttribute('data-api-edit-unlock') || '';
  var canEditByPermission = form.getAttribute('data-can-edit') === '1';
  var checkActionUrl = form.getAttribute('data-check-action-url') || '';
  var newUrl = form.getAttribute('data-new-url') || '';
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var initialVoucherId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var defaultDate = form.getAttribute('data-default-date') || '';
  var companyName = form.getAttribute('data-company-name') || '';
  var companyLogoUrl = form.getAttribute('data-company-logo') || '';

  var currentVoucherId = 0;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var docNoSearch = window.DocumentNoNav ? DocumentNoNav.createSearchState() : { matchIds: [], matchIndex: -1, query: '', currentDocNo: '' };
  var DOC_NO_SEARCH_UI = {
    noInputId: 'rc_no',
    prevBtnId: 'rc_no_prev',
    nextBtnId: 'rc_no_next',
    defaultNoTitle: 'اكتب جزءاً من رقم السند واضغط Enter للبحث',
  };
  var voucherIsPosted = false;
  var voucherIsCancelled = false;
  var formDirty = false;
  var formSubmitting = false;
  var suppressDirtyMark = 0;
  var pendingAfterSave = null;
  var lastChecksManageData = [];

  function fmtDate(value) {
    return global.AppFormat && AppFormat.formatDateDmY
      ? AppFormat.formatDateDmY(value)
      : String(value == null ? '' : value);
  }

  function fmtMoney(n) {
    if (global.AppFormat && AppFormat.fmt) return AppFormat.fmt(n);
    var x = Number(n);
    if (!isFinite(x)) return '0.00';
    return x.toFixed(2);
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  // ================= تفقيط بالعربية =================
  function arabicWordsBelow1000(n) {
    var ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
    var teens = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر',
      'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    var tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    var hundreds = ['', 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة',
      'ثمانمئة', 'تسعمئة'];
    if (n === 0) return '';
    var h = Math.floor(n / 100);
    var rem = n % 100;
    var parts = [];
    if (h > 0) parts.push(hundreds[h]);
    if (rem >= 10 && rem < 20) {
      parts.push(teens[rem - 10]);
    } else {
      var t = Math.floor(rem / 10);
      var o = rem % 10;
      if (o > 0 && t > 0) parts.push(ones[o] + ' و' + tens[t]);
      else if (o > 0) parts.push(ones[o]);
      else if (t > 0) parts.push(tens[t]);
    }
    return parts.join(' و');
  }

  function arabicGroupName(n, singular, dual, pluralFew, accusative) {
    var ones3to10 = ['', '', '', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة'];
    if (n === 0) return '';
    if (n === 1) return singular;
    if (n === 2) return dual;
    if (n >= 3 && n <= 10) return ones3to10[n] + ' ' + pluralFew;
    return arabicWordsBelow1000(n) + ' ' + accusative;
  }

  function arabicWordsInt(num) {
    num = Math.floor(Math.abs(num));
    if (num === 0) return 'صفر';
    var billions = Math.floor(num / 1000000000);
    var millions = Math.floor((num % 1000000000) / 1000000);
    var thousands = Math.floor((num % 1000000) / 1000);
    var units = num % 1000;
    var parts = [];
    if (billions > 0) {
      parts.push(arabicGroupName(billions, 'مليار', 'ملياران', 'مليارات', 'ملياراً'));
    }
    if (millions > 0) {
      parts.push(arabicGroupName(millions, 'مليون', 'مليونان', 'ملايين', 'مليوناً'));
    }
    if (thousands > 0) {
      parts.push(arabicGroupName(thousands, 'ألف', 'ألفان', 'آلاف', 'ألفاً'));
    }
    if (units > 0) {
      parts.push(arabicWordsBelow1000(units));
    }
    return parts.join(' و');
  }

  /**
   * تفقيط المبلغ بصياغة عربية كاملة: «مبلغ وقدره … ريالاً و… هللة فقط لا غير».
   */
  function getAppCurrency() {
    var c = (typeof window !== 'undefined' && window.AppCurrency) ? window.AppCurrency : null;
    if (!c || typeof c !== 'object') {
      return { code: 'SAR', main_ar: 'ريالاً', fraction_ar: 'هللة', fraction_units: 100 };
    }
    return {
      code: c.code || 'SAR',
      main_ar: c.main_ar || 'ريالاً',
      fraction_ar: c.fraction_ar || 'هللة',
      fraction_units: parseInt(c.fraction_units, 10) === 1000 ? 1000 : 100,
    };
  }

  function arabicTafqit(amount, mainCurrency, fractionCurrency, fractionUnits) {
    if (!isFinite(amount)) return '';
    var cur = getAppCurrency();
    mainCurrency = mainCurrency || cur.main_ar;
    fractionCurrency = fractionCurrency || cur.fraction_ar;
    fractionUnits = parseInt(fractionUnits, 10);
    if (fractionUnits !== 1000) fractionUnits = (fractionUnits === 100 ? 100 : cur.fraction_units);
    var sign = amount < 0 ? 'سالب ' : '';
    var abs = Math.abs(amount);
    var intPart = Math.floor(abs);
    var fracPart = Math.round((abs - intPart) * fractionUnits);
    if (fracPart === fractionUnits) {
      intPart += 1;
      fracPart = 0;
    }
    var out = sign;
    if (intPart > 0) {
      out += arabicWordsInt(intPart) + ' ' + mainCurrency;
    } else {
      out += 'صفر ' + mainCurrency;
    }
    if (fracPart > 0) {
      out += ' و' + arabicWordsInt(fracPart) + ' ' + fractionCurrency;
    }
    out += ' فقط لا غير.';
    return out;
  }
  // ================ /تفقيط بالعربية =================

  function parseNum(v) {
    if (v === '' || v === null || v === undefined) return 0;
    var s = String(v).replace(/,/g, '');
    var x = parseFloat(s);
    return isFinite(x) ? x : 0;
  }

  function getPayMethod() {
    var bank = document.getElementById('rc_pay_bank');
    if (bank && bank.checked) return 'bank';
    var check = document.getElementById('rc_pay_check');
    if (check && check.checked) return 'check';
    return 'cash';
  }

  function setPayMethod(method) {
    var isCheck = method === 'check';
    var isBank = method === 'bank';
    var cash = document.getElementById('rc_pay_cash');
    var bank = document.getElementById('rc_pay_bank');
    var chk = document.getElementById('rc_pay_check');
    if (cash) cash.checked = !isCheck && !isBank;
    if (bank) bank.checked = isBank;
    if (chk) chk.checked = isCheck;
    syncPayMethodUi();
  }

  function getRcWrap() {
    return form.closest('.fin-rc-wrap') || form;
  }

  function syncPayMethodUi() {
    var method = getPayMethod();
    var isCheck = method === 'check';
    var isBank = method === 'bank';
    var wrap = getRcWrap();
    wrap.classList.toggle('fin-rc-pay-is-check', isCheck);
    wrap.classList.toggle('fin-rc-pay-is-cash', method === 'cash');
    wrap.classList.toggle('fin-rc-pay-is-bank', isBank);
    form.classList.toggle('fin-rc-pay-is-check', isCheck);
    form.classList.toggle('fin-rc-pay-is-cash', method === 'cash');
    form.classList.toggle('fin-rc-pay-is-bank', isBank);

    var cashWrap = document.getElementById('rc_cash_amount_wrap');
    if (cashWrap) {
      cashWrap.hidden = isCheck;
      cashWrap.style.display = isCheck ? 'none' : '';
    }

    var checksWrap = document.getElementById('rc_checks_wrap');
    if (checksWrap) {
      checksWrap.hidden = !isCheck;
      checksWrap.style.display = isCheck ? '' : 'none';
    }

    var cashAcc = document.getElementById('rc_cash_account_id');
    if (cashAcc) {
      var defaultCash = parseInt(form.getAttribute('data-default-cash-id') || '0', 10);
      var bankId = parseInt(form.getAttribute('data-bank-account-id') || '0', 10);
      var checksFund = parseInt(form.getAttribute('data-checks-fund-account-id') || '0', 10);
      if (isCheck && checksFund > 0) cashAcc.value = String(checksFund);
      else if (isBank && bankId > 0) cashAcc.value = String(bankId);
      else if (defaultCash > 0) cashAcc.value = String(defaultCash);
    }

    if (isCheck) {
      ensureAtLeastOneCheckRow();
      var amountEl = document.getElementById('rc_amount');
      if (amountEl) amountEl.value = '';
      recalcChecksTotal();
    }
  }

  // ============== إدارة صفوف الشيكات ==============
  function checksTbody() {
    return document.getElementById('rc_checks_tbody');
  }

  function checksRowCount() {
    var tb = checksTbody();
    return tb ? tb.querySelectorAll('tr.fin-rc-check-row').length : 0;
  }

  function reindexCheckRows() {
    var tb = checksTbody();
    if (!tb) return;
    var rows = tb.querySelectorAll('tr.fin-rc-check-row');
    rows.forEach(function (tr, idx) {
      var n = idx + 1;
      var lbl = tr.querySelector('.fin-rc-row-index');
      if (lbl) lbl.textContent = String(n);
      var inputs = tr.querySelectorAll('input[name]');
      inputs.forEach(function (inp) {
        var nm = inp.getAttribute('name') || '';
        nm = nm.replace(/checks\[\d+\]/, 'checks[' + idx + ']');
        nm = nm.replace('__IDX__', String(idx));
        inp.setAttribute('name', nm);
      });
    });
  }

  function attachCheckRowEvents(tr) {
    if (!tr) return;
    var removeBtn = tr.querySelector('.fin-rc-check-remove');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (voucherIsPosted || voucherIsCancelled) return;
        tr.parentNode.removeChild(tr);
        if (checksRowCount() === 0) addCheckRow();
        reindexCheckRows();
        recalcChecksTotal();
        markFormDirty();
      });
    }
    var amt = tr.querySelector('.fin-rc-check-amount');
    if (amt) {
      amt.addEventListener('input', recalcChecksTotal);
      amt.addEventListener('change', recalcChecksTotal);
      amt.addEventListener('blur', function () {
        var v = parseNum(amt.value);
        amt.value = v > 0 ? fmtMoney(v) : '';
        recalcChecksTotal();
      });
    }
    bindCheckRowDatePicker(tr);
  }

  function bindCheckRowDatePicker(tr) {
    if (!tr) return;
    var due = tr.querySelector('.fin-rc-check-due');
    if (!due) return;
    function doInit() {
      if (global.AppDatePicker && typeof AppDatePicker.init === 'function') {
        AppDatePicker.init(tr);
      }
    }
    doInit();
    if (due.dataset && due.dataset.datePickerBound !== '1') {
      // إذا لم يُربط (مثلاً سكربت التقويم لم يُحمَّل بعد) نُعيد المحاولة في الـ tick التالي
      setTimeout(doInit, 0);
    }
  }

  function addCheckRow(data) {
    var tb = checksTbody();
    var tpl = document.getElementById('rc_check_row_tpl');
    if (!tb || !tpl || !tpl.content) return null;
    var clone = tpl.content.firstElementChild.cloneNode(true);
    var idx = checksRowCount();
    clone.querySelectorAll('input[name]').forEach(function (inp) {
      var nm = inp.getAttribute('name') || '';
      inp.setAttribute('name', nm.replace('__IDX__', String(idx)));
    });
    var idxLbl = clone.querySelector('.fin-rc-row-index');
    if (idxLbl) idxLbl.textContent = String(idx + 1);
    if (data) {
      var no = clone.querySelector('.fin-rc-check-no');
      if (no) no.value = data.check_no || '';
      var bank = clone.querySelector('.fin-rc-check-bank');
      if (bank) bank.value = data.bank_name || '';
      var amt = clone.querySelector('.fin-rc-check-amount');
      if (amt && data.check_amount > 0) amt.value = fmtMoney(data.check_amount);
      var due = clone.querySelector('.fin-rc-check-due');
      if (due) due.value = data.due_date_dmy || fmtDate(data.due_date || '') || '';
    }
    tb.appendChild(clone);
    attachCheckRowEvents(clone);
    return clone;
  }

  function ensureAtLeastOneCheckRow() {
    if (checksRowCount() === 0) addCheckRow();
  }

  function clearCheckRows() {
    var tb = checksTbody();
    if (tb) tb.innerHTML = '';
  }

  function recalcChecksTotal() {
    var total = 0;
    var tb = checksTbody();
    if (tb) {
      tb.querySelectorAll('.fin-rc-check-amount').forEach(function (inp) {
        total += parseNum(inp.value);
      });
    }
    var totEl = document.getElementById('rc_checks_total');
    if (totEl) totEl.textContent = fmtMoney(total);
    var chkAmt = document.getElementById('rc_check_amount');
    if (chkAmt) chkAmt.value = total > 0 ? String(total) : '';
    return total;
  }

  function getChecksRows() {
    var rows = [];
    var tb = checksTbody();
    if (!tb) return rows;
    tb.querySelectorAll('tr.fin-rc-check-row').forEach(function (tr) {
      var amt = parseNum((tr.querySelector('.fin-rc-check-amount') || {}).value || 0);
      if (amt <= 0) return;
      rows.push({
        check_no: (tr.querySelector('.fin-rc-check-no') || {}).value || '',
        bank_name: (tr.querySelector('.fin-rc-check-bank') || {}).value || '',
        check_amount: amt,
        due_date: (tr.querySelector('.fin-rc-check-due') || {}).value || '',
      });
    });
    return rows;
  }

  function getPayMethodValue() {
    var chk = document.getElementById('rc_pay_check');
    if (chk && chk.checked) return 'check';
    var bank = document.getElementById('rc_pay_bank');
    if (bank && bank.checked) return 'bank';
    return 'cash';
  }

  function renderCheckStatusCell(tr, data) {
    var td = tr.querySelector('.fin-rc-check-status-cell');
    if (!td) return;
    data = data || {};
    var lifecycle = data.lifecycle_status || 'pending';
    var html = '';
    if (data.action_was_undone) {
      html =
        '<span class="badge fin-chk-badge fin-chk-badge--undo">' +
        escapeHtml(data.status_display || data.execute_label || 'تم الإلغاء') +
        '</span>';
      if (data.action_date_dmy) {
        html += '<br><small class="muted">' + escapeHtml(data.action_date_dmy) + '</small>';
      }
      if (data.undone_action_label) {
        html += '<br><small class="muted">إلغاء ' + escapeHtml(data.undone_action_label) + '</small>';
      }
    } else if (lifecycle === 'pending') {
      html = '<span class="badge badge-warn">قيد</span>';
    } else {
      html =
        '<span class="badge badge-ok">' +
        escapeHtml(data.status_display || lifecycle) +
        '</span>';
      if (data.action_date_dmy) {
        html += '<br><small class="muted">' + escapeHtml(data.action_date_dmy) + '</small>';
      }
      if (data.endorsed_party_name) {
        html += '<br><small class="muted">إلى: ' + escapeHtml(data.endorsed_party_name) + '</small>';
      }
      if (data.can_undo && data.id) {
        html +=
          '<br><button type="button" class="btn btn-secondary btn-sm fin-rc-check-undo" data-check-id="' +
          String(data.id) +
          '" data-undo-label="' +
          escapeHtml(data.undo_label || 'إلغاء') +
          '">' +
          escapeHtml(data.undo_label || 'إلغاء') +
          '</button>';
      }
      if (data.journal_url) {
        html +=
          ' <a href="' +
          escapeHtml(data.journal_url) +
          '" class="btn btn-link btn-sm">القيد</a>';
      }
    }
    td.innerHTML = html;
  }

  function refreshChecksManageUi() {
    var show =
      currentVoucherId > 0 &&
      voucherIsPosted &&
      !voucherIsCancelled &&
      getPayMethodValue() === 'check';
    var col = document.getElementById('rc_checks_status_col');
    if (col) col.hidden = !show;
    document.querySelectorAll('.fin-rc-check-status-cell').forEach(function (td) {
      td.hidden = !show;
    });
    if (!show) return;
    var tb = checksTbody();
    if (!tb) return;
    var rows = tb.querySelectorAll('tr.fin-rc-check-row');
    rows.forEach(function (tr, idx) {
      renderCheckStatusCell(tr, lastChecksManageData[idx] || {});
    });
  }

  function undoCheckFromVoucher(checkId, undoLabel) {
    if (!checkActionUrl || checkId < 1) return;
    var csrfEl = form.querySelector('input[name="_csrf"]');
    var csrf = csrfEl ? csrfEl.value : '';
    var msg =
      'تأكيد ' +
      (undoLabel || 'إلغاء') +
      '؟\n\nسيتم حذف القيد المحاسبي وإعادة الشيك إلى «قيد».';
    var confirmFn =
      global.AppDialog && AppDialog.confirm
        ? function (m) {
            return AppDialog.confirm(m, { title: undoLabel || 'تأكيد' });
          }
        : function (m) {
            return Promise.resolve(window.confirm(m));
          };
    confirmFn(msg).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('action', 'undo');
      fd.append('check_id', String(checkId));
      fetch(checkActionUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (data && data.ok) {
            if (global.AppDialog && AppDialog.success) {
              AppDialog.success((data && data.message) || 'تم الإلغاء.');
            }
            loadVoucherById(currentVoucherId, true);
            return;
          }
          var errMsg = (data && data.message) || 'تعذر الإلغاء.';
          if (global.AppDialog && AppDialog.error) AppDialog.error(errMsg);
          else window.alert(errMsg);
        })
        .catch(function () {
          if (global.AppDialog && AppDialog.error) AppDialog.error('خطأ في الاتصال بالخادم.');
        });
    });
  }
  // ============== /إدارة صفوف الشيكات ==============

  var rcCustomerPickerApi = null;

  function applyCustomerRep(apiRepName) {
    var rep = document.getElementById('rc_sales_rep');
    if (!rep) return;
    if (apiRepName !== undefined && apiRepName !== null && String(apiRepName).trim() !== '') {
      rep.value = String(apiRepName).trim();
      return;
    }
    var cust = document.getElementById('rc_customer');
    if (!cust || !cust.value) {
      rep.value = '';
      return;
    }
    if (rcCustomerPickerApi) {
      var c = rcCustomerPickerApi.getCustomer();
      rep.value = c && c.sales_rep_name ? String(c.sales_rep_name) : '';
      return;
    }
    rep.value = '';
  }

  function isSearchOnlyField(el) {
    if (!el || !el.id) return false;
    return el.id === 'rc_no';
  }

  function markFormDirty() {
    if (suppressDirtyMark > 0 || voucherIsPosted || voucherIsCancelled) return;
    formDirty = true;
  }

  function markFormDirtyFromEvent(e) {
    if (e && e.target && isSearchOnlyField(e.target)) return;
    markFormDirty();
  }

  function clearFormDirty() {
    formDirty = false;
  }

  function runWithoutDirtyMark(fn) {
    suppressDirtyMark++;
    try {
      fn();
    } finally {
      suppressDirtyMark--;
    }
    clearFormDirty();
  }

  function buildRecipientSignatureBlock() {
    if (window.DocumentHeader && typeof window.DocumentHeader.buildRecipientSignature === 'function') {
      return window.DocumentHeader.buildRecipientSignature();
    }
    return (
      '<div class="doc-print-signature-block">' +
      '<span class="doc-print-signature-label">توقيع المستلم</span>' +
      '<span class="doc-print-signature-line" aria-hidden="true"></span>' +
      '</div>'
    );
  }

  function buildDocPrintHeader(title) {
    if (window.DocumentHeader && typeof window.DocumentHeader.build === 'function') {
      return window.DocumentHeader.build({
        companyName: companyName,
        logoUrl: companyLogoUrl,
        title: title || '',
      });
    }
    return (
      '<header class="doc-print-header"><div class="doc-print-header-top"><div class="doc-print-header-brand"><div class="doc-print-header-co">' +
      escapeHtml(companyName) +
      '</div><div class="doc-print-header-logo"></div></div></div><div class="doc-print-header-title">' +
      escapeHtml(title || '') +
      '</div></header>'
    );
  }

  function syncVoucherIdField() {
    var rec = document.getElementById('rc_record_id');
    if (!rec) return;
    rec.value = currentVoucherId > 0 ? String(currentVoucherId) : '';
  }

  function syncVoucherNoDisplay(voucherNo) {
    var rcNo = document.getElementById('rc_no');
    if (!rcNo) return;
    if (currentVoucherId > 0) {
      var no =
        voucherNo !== undefined && voucherNo !== null ? String(voucherNo) : rcNo.value;
      rcNo.value = no.trim();
    } else {
      rcNo.value = '';
    }
    updateVoucherNoPostedStyle();
  }

  function getRcWrap() {
    return form.closest('.fin-rc-wrap');
  }

  function voucherCancelledFromPayload(v) {
    if (!v) return false;
    if (v.is_cancelled === true || v.is_cancelled === 1 || v.is_cancelled === '1') return true;
    if (String(v.status || '') === 'cancelled') return true;
    if (String(v.status_label || '') === 'ملغى') return true;
    return false;
  }

  function lockVoucherPickerButtons(locked) {
    form.querySelectorAll(
      '#rc_customer_open, .sales-inv-cust-open, .js-pick-open, #rc_check_add, #rc_pay_cash, #rc_pay_check, #rc_pay_bank'
    ).forEach(function (el) {
      if (!el) return;
      if (locked) {
        el.disabled = true;
        if (el.type === 'radio' || el.tagName === 'SELECT') {
          el.disabled = true;
        }
      } else if (el.id === 'rc_check_add') {
        el.disabled = false;
      } else if (el.type === 'radio') {
        el.disabled = false;
      } else if (el.classList && el.classList.contains('sales-inv-cust-open')) {
        el.disabled = false;
      }
    });
  }

  function refreshVoucherEditState() {
    var locked = currentVoucherId > 0 && (!!voucherIsPosted || !!voucherIsCancelled);
    form.classList.toggle('fin-rc-form-is-posted', locked);
    var wrap = getRcWrap();
    if (wrap) {
      wrap.classList.toggle('fin-rc-form-is-posted', locked);
      wrap.classList.toggle('fin-rc-form-is-cancelled', currentVoucherId > 0 && !!voucherIsCancelled);
    }

    var fields = form.querySelectorAll(
      '#rc_date, #rc_customer, #rc_amount, #rc_notes, #rc_pay_cash, #rc_pay_check, #rc_pay_bank, ' +
        '.fin-rc-check-no, .fin-rc-check-bank, .fin-rc-check-amount, .fin-rc-check-due, #rc_cash_account_id'
    );
    fields.forEach(function (el) {
      if (!el) return;
      if (locked) {
        if (el.type === 'radio' || el.tagName === 'SELECT') el.disabled = true;
        else el.setAttribute('readonly', 'readonly');
      } else {
        if (el.type === 'radio' || el.tagName === 'SELECT') el.disabled = false;
        else el.removeAttribute('readonly');
      }
    });

    var addBtn = document.getElementById('rc_check_add');
    if (addBtn) addBtn.disabled = locked;
    form.querySelectorAll('.fin-rc-check-remove').forEach(function (btn) {
      btn.disabled = locked;
    });
    lockVoucherPickerButtons(locked);
    refreshChecksManageUi();
  }

  function updateVoucherNoPostedStyle() {
    var rcNo = document.getElementById('rc_no');
    if (!rcNo) return;
    rcNo.classList.remove('is-posted', 'is-unposted', 'is-cancelled');
    if (currentVoucherId < 1) return;
    if (voucherIsCancelled) {
      rcNo.classList.add('is-cancelled');
    } else if (voucherIsPosted) {
      rcNo.classList.add('is-posted');
    } else {
      rcNo.classList.add('is-unposted');
    }
  }

  function updatePostedBadge() {
    var el = document.getElementById('rc_posted_badge');
    var wrap = el ? el.closest('.fin-voucher-status-wrap') : null;
    if (currentVoucherId < 1) {
      if (el) {
        el.hidden = true;
        el.textContent = '';
      }
      if (wrap) wrap.hidden = true;
      updateVoucherNoPostedStyle();
      updateToolbarPostUnpost();
      return;
    }
    if (wrap) wrap.hidden = false;
    if (el) {
      el.hidden = false;
      if (voucherIsCancelled) {
        el.textContent = 'ملغى';
        el.className = 'sales-inv-posted-badge badge badge-cancelled';
      } else if (voucherIsPosted) {
        el.textContent = 'مرحّل';
        el.className = 'sales-inv-posted-badge badge badge-posted';
      } else {
        el.textContent = 'غير مرحّل';
        el.className = 'sales-inv-posted-badge badge badge-unposted';
      }
    }
    updateVoucherNoPostedStyle();
    updateToolbarPostUnpost();
  }

  function updateToolbarPostUnpost() {
    var postBtn = document.querySelector('#master-toolbar [data-master-action="post"]');
    var editBtn = document.querySelector('#master-toolbar [data-master-action="edit"]');
    var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
    var cancelBtn = document.querySelector('#master-toolbar [data-master-action="cancel_voucher"]');
    var deleteBtn = document.querySelector('#master-toolbar [data-master-action="delete"]');
    var canPost = currentVoucherId > 0 && !voucherIsPosted && !voucherIsCancelled;
    var canEdit = canEditByPermission && currentVoucherId > 0 && voucherIsPosted && !voucherIsCancelled;
    var canUnpost = currentVoucherId > 0 && voucherIsPosted && !voucherIsCancelled;
    var canCancel = currentVoucherId > 0 && voucherIsPosted && !voucherIsCancelled;
    if (postBtn) {
      postBtn.disabled = !canPost;
      postBtn.title = canPost ? 'ترحيل السند' : 'احفظ السند أولاً أو السند مرحّل/ملغى';
    }
    if (editBtn) {
      editBtn.disabled = !canEdit;
      editBtn.title = canEdit
        ? 'تعديل السند بعد التحقق بكلمة المرور'
        : voucherIsCancelled
          ? 'لا يمكن تعديل سند ملغى'
          : !voucherIsPosted
            ? 'السند قابل للتعديل — احفظ ثم رحّل'
            : 'يمكن تعديل السندات المرحّلة فقط';
    }
    if (unpostBtn) {
      unpostBtn.disabled = !canUnpost;
      unpostBtn.title = canUnpost
        ? 'فك الترحيل (للتعديل ثم إعادة الترحيل)'
        : 'لا يوجد ترحيل لفكّه';
    }
    if (cancelBtn) {
      cancelBtn.disabled = !canCancel;
      cancelBtn.title = canCancel
        ? 'إلغاء السند (يبقى برقم التسلسل ويُلغى أثره المحاسبي)'
        : 'يمكن إلغاء السندات المرحّلة فقط';
    }
    if (deleteBtn) {
      deleteBtn.disabled = currentVoucherId > 0 && (voucherIsPosted || voucherIsCancelled);
      deleteBtn.title =
        voucherIsCancelled
          ? 'لا يمكن حذف سند ملغى'
          : voucherIsPosted
            ? 'لا يمكن حذف سند مرحّل — استخدم «تعديل» أو «إلغاء السند»'
            : 'حذف مسودة السند';
    }
    if (global.FinVoucherArchive) {
      global.FinVoucherArchive.syncToolbar();
    }
  }

  function receiptArchiveState(id) {
    id = parseInt(String(id), 10) || 0;
    if (id < 1) {
      return { allowed: false, reason: 'not_saved' };
    }
    if (voucherIsCancelled) {
      return { allowed: false, reason: 'cancelled' };
    }
    if (voucherIsPosted) {
      return { allowed: true, readOnly: true, reason: '' };
    }
    return { allowed: true, readOnly: false, reason: '' };
  }

  function applyBrowseNavFromPayload(payload) {
    if (window.DocumentNoNav && DocumentNoNav.applyBrowseNav) {
      DocumentNoNav.applyBrowseNav(docNoSearch, payload, setBrowseNav, DOC_NO_SEARCH_UI);
      return;
    }
    setBrowseNav(payload.prev_id || 0, payload.next_id || 0);
  }

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    updateNavButtons(browseNavPrevId, browseNavNextId);
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      var onEmpty = currentVoucherId < 1;
      var prevBeforeLatest =
        onEmpty && prevId > 0 && nextId > 0 && prevId < nextId
          ? 'أول سند قبض'
          : 'السند قبل الأخير';
      DocumentNoNav.updateButtons('rc_no_prev', 'rc_no_next', prevId, nextId, {
        onEmpty: onEmpty,
        prevTitle: 'السند السابق',
        nextTitle: 'السند التالي',
        prevBeforeLatestTitle: prevBeforeLatest,
        latestTitle: 'آخر سند قبض',
      });
      return;
    }
    var prevBtn = document.getElementById('rc_no_prev');
    var nextBtn = document.getElementById('rc_no_next');
    if (prevBtn) prevBtn.disabled = !(prevId > 0);
    if (nextBtn) nextBtn.disabled = !(nextId > 0);
  }

  function navigateEmptyVoucher(dir) {
    var opts = {
      browseNavPrevId: browseNavPrevId,
      browseNavNextId: browseNavNextId,
      fetchById: function (id) {
        return fetchVoucherResponse({ id: id });
      },
      fetchLatest: function () {
        return fetchVoucherResponse({ edge: 'first' });
      },
      isOk: function (data) {
        return !!(data && data.ok && data.voucher);
      },
      getPayload: function (data) {
        return data.voucher;
      },
      apply: applyVoucherData,
      emptyMessage: 'لا توجد سندات قبض محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر سند قبض.',
      loadError: 'تعذر تحميل السند.',
    };
    if (window.DocumentNoNav) {
      return DocumentNoNav.navigateEmpty(dir, opts);
    }
    return opts.fetchLatest().then(function (data) {
      if (opts.isOk(data)) opts.apply(opts.getPayload(data));
      else if (global.AppDialog) AppDialog.alert(opts.emptyMessage, { type: 'info' });
    });
  }

  function refreshEmptyBrowseNav() {
    if (!apiVoucherUrl) {
      setBrowseNav(0, 0);
      return;
    }
    Promise.all([
      fetchVoucherResponse({ edge: 'first' }),
      fetchVoucherResponse({ edge: 'last' }),
    ]).then(function (results) {
      var latestRes = results[0];
      var oldestRes = results[1];
      if (!latestRes || !latestRes.ok || !latestRes.voucher) {
        setBrowseNav(0, 0);
        return;
      }
      var newestId = parseInt(latestRes.voucher.id, 10) || 0;
      var oldestId =
        oldestRes && oldestRes.ok && oldestRes.voucher
          ? parseInt(oldestRes.voucher.id, 10) || 0
          : 0;
      if (oldestId < 1) {
        oldestId = newestId;
      }
      setBrowseNav(oldestId, newestId);
    });
  }

  function hasDraftContent() {
    var cust = document.getElementById('rc_customer');
    if (cust && cust.value !== '') return true;
    var amt = document.getElementById('rc_amount');
    if (amt && String(amt.value).trim() !== '') return true;
    if (recalcChecksTotal() > 0) return true;
    var notes = document.getElementById('rc_notes');
    if (notes && String(notes.value).trim() !== '') return true;
    return false;
  }

  function validateBeforeSave() {
    var cust = document.getElementById('rc_customer');
    if (!cust || !cust.value) {
      if (global.AppDialog) AppDialog.alert('اختر العميل قبل الحفظ.', { type: 'warning' });
      else alert('اختر العميل قبل الحفظ.');
      if (cust) cust.focus();
      return false;
    }
    var rcDate = document.getElementById('rc_date');
    if (rcDate && !rcDate.value.trim()) {
      if (global.AppDialog) AppDialog.alert('أدخل تاريخ السند.', { type: 'warning' });
      else alert('أدخل تاريخ السند.');
      if (rcDate) rcDate.focus();
      return false;
    }
    if (rcDate && global.AppFormat && AppFormat.parseDateToIso) {
      if (!AppFormat.parseDateToIso(rcDate.value)) {
        if (global.AppDialog) {
          AppDialog.alert('تاريخ السند غير صالح. استخدم يوم-شهر-سنة (مثل 16-05-2026).', {
            type: 'warning',
          });
        }
        if (rcDate) rcDate.focus();
        return false;
      }
    }
    if (getPayMethod() === 'check') {
      var total = recalcChecksTotal();
      if (total <= 0) {
        if (global.AppDialog) AppDialog.alert('أدخل قيمة شيك واحد على الأقل.', { type: 'warning' });
        else alert('أدخل قيمة شيك واحد على الأقل.');
        var firstAmt = form.querySelector('.fin-rc-check-amount');
        if (firstAmt) firstAmt.focus();
        return false;
      }
    } else {
      var amountEl = document.getElementById('rc_amount');
      if (parseNum(amountEl ? amountEl.value : 0) <= 0) {
        if (global.AppDialog) AppDialog.alert('أدخل المبلغ.', { type: 'warning' });
        else alert('أدخل المبلغ.');
        if (amountEl) amountEl.focus();
        return false;
      }
    }
    return true;
  }

  function setSaveBusy(busy, message) {
    if (global.AppBusy && AppBusy.setSaveBusy) {
      AppBusy.setSaveBusy(busy, message || 'جاري حفظ سند القبض...');
      return;
    }
    var saveBtn = document.querySelector('#master-toolbar [data-master-action="save"]');
    if (saveBtn) saveBtn.disabled = !!busy;
  }

  function finishSaveFromJson(data, onDone) {
    formSubmitting = false;
    setSaveBusy(false);
    clearFormDirty();
    var savedId = parseInt(data.voucher_id, 10) || 0;
    if (savedId > 0) {
      loadSavedVoucherAfterSubmit(savedId, onDone);
      return;
    }
    window.location.reload();
  }

  function loadSavedVoucherAfterSubmit(voucherId, onDone) {
    fetchVoucherResponse({ id: voucherId }).then(function (data) {
      if (data && data.ok && data.voucher) {
        applyVoucherData(data);
        updateHistory(voucherId);
        if (global.FinVoucherArchive) {
          global.FinVoucherArchive.syncToolbar();
        }
        if (onDone) {
          onDone();
        } else if (global.AppDialog) {
          AppDialog.success('تم حفظ سند القبض بنجاح.');
        }
        return;
      }
      window.location.href =
        (newUrl || window.location.pathname) +
        (newUrl.indexOf('?') >= 0 ? '&' : '?') +
        'id=' +
        voucherId;
    }).catch(function () {
      window.location.reload();
    });
  }

  /** عند الشيك: قيمة الشيك هي المبلغ المحفوظ في الحقل amount + تعبئة legacy fields من أول صف */
  function syncAmountForSubmit() {
    var amountEl = document.getElementById('rc_amount');
    if (getPayMethod() === 'check') {
      document.querySelectorAll('.fin-rc-check-amount').forEach(function (inp) {
        var v = parseNum(inp.value);
        if (v > 0) inp.value = String(v);
      });
      var total = recalcChecksTotal();
      if (amountEl && total > 0) amountEl.value = String(total);

      var rows = getChecksRows();
      var first = rows[0] || {};
      var chkNo = document.getElementById('rc_check_no');
      if (chkNo) chkNo.value = first.check_no || '';
      var bank = document.getElementById('rc_bank_name');
      if (bank) bank.value = first.bank_name || '';
    }
  }

  function submitReceiptForm() {
    formSubmitting = true;
    syncVoucherIdField();
    syncAmountForSubmit();
    setSaveBusy(true);

    var actionUrl = form.getAttribute('action') || window.location.href;
    var fd = new FormData(form);
    fd.set('_action', 'save_receipt');
    if (currentVoucherId < 1) {
      var noInput = document.getElementById('rc_no');
      var noVal = noInput ? String(noInput.value || '').trim() : '';
      if (!noVal) {
        fd.set('voucher_no', '');
      }
    }
    var onDone = pendingAfterSave;
    pendingAfterSave = null;

    fetch(actionUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        var ct = res.headers.get('Content-Type') || '';
        if (ct.indexOf('application/json') >= 0) {
          return res.json().then(function (data) {
            if (!data || !data.ok) {
              formSubmitting = false;
              setSaveBusy(false);
              var msg = (data && data.message) || 'تعذر حفظ السند.';
              if (global.AppDialog) AppDialog.error(msg);
              else alert(msg);
              return;
            }
            finishSaveFromJson(data, onDone);
          });
        }
        if (onDone) {
          formSubmitting = false;
          setSaveBusy(false);
          onDone();
          return;
        }
        if (res.status >= 300 && res.status < 400) {
          var loc = res.headers.get('Location');
          if (loc) {
            window.location.href = new URL(loc, window.location.href).href;
            return;
          }
        }
        window.location.reload();
      })
      .catch(function (err) {
        console.error('fin-receipt save', err);
        formSubmitting = false;
        setSaveBusy(false);
        if (global.AppDialog) {
          AppDialog.error('تعذر حفظ السند. تحقق من الاتصال وحاول مرة أخرى.');
        } else {
          alert('تعذر حفظ السند.');
        }
      });
  }

  function trySave(onSuccess) {
    if (formSubmitting) return;
    if (voucherIsCancelled) {
      AppDialog.alert('لا يمكن تعديل سند ملغى.', { type: 'warning' });
      return;
    }
    if (voucherIsPosted) {
      if (global.AppDialog) AppDialog.alert('لا يمكن تعديل سند مرحّل.', { type: 'warning' });
      return;
    }
    if (!validateBeforeSave()) return;
    pendingAfterSave = typeof onSuccess === 'function' ? onSuccess : null;
    try {
      submitReceiptForm();
    } catch (err) {
      pendingAfterSave = null;
      formSubmitting = false;
      setSaveBusy(false);
      if (global.AppDialog) {
        AppDialog.error('تعذر حفظ السند: ' + (err.message || 'خطأ غير معروف'));
      }
    }
  }

  function fetchVoucherResponse(query) {
    if (!apiVoucherUrl) return Promise.resolve(null);
    var qs = Object.keys(query)
      .map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
      })
      .join('&');
    return fetch(apiVoucherUrl + '?' + qs, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function applyVoucherData(data) {
    var v = data && data.voucher ? data.voucher : data && data.id ? data : null;
    if (!v) return;

    runWithoutDirtyMark(function () {
      currentVoucherId = parseInt(v.id, 10) || 0;
      voucherIsPosted = !!v.is_posted && !voucherCancelledFromPayload(v);
      voucherIsCancelled = voucherCancelledFromPayload(v);
      syncVoucherIdField();
      syncVoucherNoDisplay(v.voucher_no || '');

      var rcDate = document.getElementById('rc_date');
      if (rcDate) {
        rcDate.value = fmtDate(v.voucher_date_dmy || v.voucher_date || '') || defaultDate;
      }

      if (rcCustomerPickerApi) {
        rcCustomerPickerApi.setById(v.customer_id || 0, true);
      } else {
        var custSel = document.getElementById('rc_customer');
        if (custSel) custSel.value = String(v.customer_id || '');
      }

      applyCustomerRep(v.sales_rep_name || '');

      var payMethod = v.pay_method === 'check' ? 'check' : (v.pay_method === 'bank' ? 'bank' : 'cash');
      setPayMethod(payMethod);

      var amountEl = document.getElementById('rc_amount');
      var chkAmt = document.getElementById('rc_check_amount');
      var amt = v.amount > 0 ? v.amount : 0;
      var chkVal = v.check_amount > 0 ? v.check_amount : 0;
      if (payMethod === 'check') {
        clearCheckRows();
        var list = Array.isArray(v.checks) ? v.checks : [];
        lastChecksManageData = list.slice();
        if (list.length === 0 && (chkVal > 0 || amt > 0)) {
          list = [
            {
              check_no: v.check_no || '',
              bank_name: v.bank_name || '',
              check_amount: chkVal > 0 ? chkVal : amt,
              due_date: '',
              due_date_dmy: '',
            },
          ];
        }
        list.forEach(function (row) {
          addCheckRow(row);
        });
        ensureAtLeastOneCheckRow();
        recalcChecksTotal();
        if (amountEl) amountEl.value = '';
      } else {
        if (amountEl) amountEl.value = amt > 0 ? fmtMoney(amt) : '';
        clearCheckRows();
        lastChecksManageData = [];
        if (chkAmt) chkAmt.value = '';
      }

      var chkNo = document.getElementById('rc_check_no');
      if (chkNo) chkNo.value = v.check_no || '';

      var bank = document.getElementById('rc_bank_name');
      if (bank) bank.value = v.bank_name || '';

      var notes = document.getElementById('rc_notes');
      if (notes) notes.value = v.notes || '';

      var cashAcc = document.getElementById('rc_cash_account_id');
      if (cashAcc && v.cash_account_id > 0) cashAcc.value = String(v.cash_account_id);

      syncPayMethodUi();
      refreshVoucherEditState();
      updatePostedBadge();
      applyBrowseNavFromPayload(v);
      updateHistory(currentVoucherId);
    });
  }

  function updateHistory(id) {
    if (!window.history || !window.history.replaceState) return;
    var base = newUrl || window.location.pathname + '?r=cash_receipt';
    var url = id > 0 ? base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + id : base;
    window.history.replaceState({ voucherId: id }, '', url);
  }

  function loadVoucherById(id, skipConfirm) {
    if (id < 1) return;
    function doLoad() {
      fetchVoucherResponse({ id: id }).then(function (data) {
        if (!data || !data.ok || !data.voucher) {
          if (global.AppDialog) {
            AppDialog.error((data && data.message) || 'تعذر تحميل السند.');
          }
          return;
        }
        applyVoucherData(data);
      });
    }
    if (skipConfirm) doLoad();
    else confirmUnsavedChanges(doLoad);
  }

  function loadVoucherByNo(no) {
    no = String(no || '').trim();
    if (!no) return;
    confirmUnsavedChanges(function () {
      fetchVoucherResponse({ no: no }).then(function (data) {
        if (!data || !data.ok || !data.voucher) {
          if (global.AppDialog) {
            AppDialog.error((data && data.message) || 'لم يتم العثور على سند يحتوي على هذا الرقم.');
          }
          return;
        }
        applyVoucherData(data);
      });
    });
  }

  function navigateVoucher(dir) {
    confirmUnsavedChanges(function () {
      navigateVoucherCore(dir);
    });
  }

  function navigateVoucherCore(dir) {
    if (currentVoucherId < 1) {
      navigateEmptyVoucher(dir);
      return;
    }
    if (window.DocumentNoNav && DocumentNoNav.isSearchActive(docNoSearch)) {
      DocumentNoNav.navigateSearchMatch(dir, docNoSearch, {
        fetchById: function (id) {
          return fetchVoucherResponse({ id: id });
        },
        isOk: function (data) {
          return !!(data && data.ok && data.voucher);
        },
        getPayload: function (data) {
          return data.voucher;
        },
        apply: applyVoucherData,
        loadError: 'تعذر تحميل السند.',
      });
      return;
    }
    fetchVoucherResponse({ id: currentVoucherId, dir: dir }).then(function (data) {
      if (!data || !data.ok || !data.voucher) {
        if (global.AppDialog) {
          AppDialog.alert(
            (data && data.message) ||
              (dir === 'prev' ? 'لا يوجد سند أقدم.' : 'لا يوجد سند أحدث.'),
            { type: 'info' }
          );
        }
        return;
      }
      applyVoucherData(data);
    });
  }

  function runToolbarVoucherSearch() {
    var rcNoEl = document.getElementById('rc_no');
    var no = rcNoEl ? String(rcNoEl.value || '').trim() : '';
    if (!no) {
      if (global.AppDialog) {
        AppDialog.alert('أدخل رقم السند في الحقل أعلاه ثم اضغط بحث.', { type: 'warning' });
      } else {
        alert('أدخل رقم السند.');
      }
      if (rcNoEl) rcNoEl.focus();
      return;
    }
    loadVoucherByNo(no);
  }

  function confirmUnsavedChanges(onProceed, onCancel) {
    if (global.ScreenExitGuard && typeof global.ScreenExitGuard.confirmSaveDiscardLeave === 'function') {
      global.ScreenExitGuard.confirmSaveDiscardLeave({
        when: function () {
          return formDirty && !voucherIsPosted && !voucherIsCancelled;
        },
        onSave: function (proceed) {
          trySave(proceed);
        },
        onDiscard: function (proceed) {
          clearFormDirty();
          if (proceed) proceed();
        },
        onProceed: onProceed,
        onCancel: onCancel,
      });
      return;
    }
    if (!formDirty || voucherIsPosted || voucherIsCancelled) {
      if (onProceed) onProceed();
      return;
    }
    if (onProceed) onProceed();
  }

  function postCurrent() {
    if (!voucherPostUrl) {
      if (global.AppDialog) AppDialog.alert('الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentVoucherId < 1) {
      if (global.AppDialog) AppDialog.alert('احفظ السند أولًا قبل الترحيل.', { type: 'warning' });
      return;
    }
    if (voucherIsCancelled) {
      AppDialog.alert('لا يمكن تعديل سند ملغى.', { type: 'warning' });
      return;
    }
    if (voucherIsPosted) {
      if (global.AppDialog) AppDialog.alert('هذا السند مرحّل مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    if (global.AppDialog) {
      AppDialog.confirm('ترحيل سند القبض؟\nيُنشأ قيد دائن على حساب العميل (دفعة).', {
        title: 'ترحيل السند',
      }).then(function (ok) {
        if (!ok) return;
        doPostVoucher(csrfInput);
      });
    } else if (confirm('ترحيل سند القبض؟')) {
      doPostVoucher(csrfInput);
    }
  }

  function doPostVoucher(csrfInput) {
    var fd = new FormData();
    fd.append('_csrf', csrfInput ? csrfInput.value : '');
    fd.append('voucher_id', String(currentVoucherId));
    fetch(voucherPostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          if (global.AppDialog) AppDialog.error(data.error || data.message || 'تعذر الترحيل.');
          return;
        }
        voucherIsPosted = true;
        updatePostedBadge();
        refreshVoucherEditState();
        if (global.AppDialog) {
          AppDialog.success(data.message || 'تم الترحيل.');
        }
        loadVoucherById(currentVoucherId, true);
      })
      .catch(function () {
        if (global.AppDialog) AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  function cancelCurrent() {
    if (!voucherCancelUrl) {
      if (global.AppDialog) AppDialog.alert('إلغاء السند غير متاح.', { type: 'warning' });
      return;
    }
    if (currentVoucherId < 1 || !voucherIsPosted || voucherIsCancelled) {
      if (global.AppDialog) AppDialog.alert('يمكن إلغاء السندات المرحّلة فقط.', { type: 'warning' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var rcNoEl = document.getElementById('rc_no');
    var rcLabel = rcNoEl && rcNoEl.value ? rcNoEl.value : String(currentVoucherId);
    AppDialog.confirm(
      'إلغاء السند «' +
        rcLabel +
        '»؟\n\n' +
        'يُلغى أثره المحاسبي ويبقى السند في السجل برقم التسلسل (لا يُحذف).',
      { title: 'إلغاء سند قبض', danger: true, okText: 'إلغاء السند' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('voucher_id', String(currentVoucherId));
      fetch(voucherCancelUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            AppDialog.error((data && data.message) || 'تعذر الإلغاء.');
            return;
          }
          voucherIsPosted = false;
          voucherIsCancelled = true;
          updatePostedBadge();
          refreshVoucherEditState();
          loadVoucherById(currentVoucherId, true);
          AppDialog.success(data.message || 'تم إلغاء السند.');
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function promptUserPassword(message) {
    if (global.AppDialog && AppDialog.prompt) {
      return AppDialog.prompt(message, {
        title: 'التحقق بكلمة المرور',
        type: 'confirm',
        theme: 'oracle',
        okText: 'متابعة',
        cancelText: 'إلغاء',
        placeholder: 'كلمة المرور',
        inputType: 'password',
        multiline: false,
        html: true,
      });
    }
    return Promise.resolve(window.prompt(message.replace(/<[^>]+>/g, ''), ''));
  }

  function editCurrentVoucher() {
    if (!canEditByPermission) {
      if (global.AppDialog && AppDialog.alert) {
        AppDialog.alert('ليس لديك صلاحية تعديل سند قبض مرحّل.', { type: 'warning' });
      } else {
        alert('ليس لديك صلاحية تعديل سند قبض مرحّل.');
      }
      return;
    }
    if (!apiEditUnlock) {
      if (global.AppDialog) AppDialog.alert('التعديل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentVoucherId < 1) {
      if (global.AppDialog) AppDialog.alert('افتح سنداً محفوظاً أولاً.', { type: 'warning' });
      return;
    }
    if (voucherIsCancelled) {
      if (global.AppDialog) AppDialog.alert('لا يمكن تعديل سند ملغى.', { type: 'warning' });
      return;
    }
    if (!voucherIsPosted) {
      if (global.AppDialog) {
        AppDialog.alert('السند غير مرحّل — يمكنك التعديل مباشرة.', { type: 'info' });
      }
      return;
    }

    var rcNoEl = document.getElementById('rc_no');
    var rcLabel = rcNoEl && rcNoEl.value ? rcNoEl.value : String(currentVoucherId);
    var msgHtml =
      '<p>لتعديل السند «' +
      escapeHtml(rcLabel) +
      '» أدخل كلمة مرورك.</p>' +
      '<p class="ui-dialog-warn">سيتم فك الترحيل تلقائياً ثم يمكنك تعديل التاريخ والبيانات، وبعدها احفظ وأعد الترحيل.</p>';

    promptUserPassword(msgHtml).then(function (password) {
      if (password === null || String(password).trim() === '') {
        return;
      }
      var csrfInput = form.querySelector('[name="_csrf"]');
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('voucher_id', String(currentVoucherId));
      fd.append('password', String(password));
      fetch(apiEditUnlock, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            if (global.AppDialog) {
              AppDialog.alert((data && data.message) || 'تعذر بدء التعديل.', { type: 'warning' });
            } else {
              alert((data && data.message) || 'تعذر بدء التعديل.');
            }
            return;
          }
          voucherIsPosted = false;
          updatePostedBadge();
          refreshVoucherEditState();
          loadVoucherById(currentVoucherId, true);
          if (global.AppDialog && AppDialog.success) {
            AppDialog.success(data.message || 'يمكنك التعديل الآن.');
          }
        })
        .catch(function () {
          if (global.AppDialog) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function unpostCurrent(onDone) {
    if (!voucherUnpostUrl) {
      if (global.AppDialog) AppDialog.alert('فك الترحيل غير متاح.', { type: 'warning' });
      return;
    }
    if (currentVoucherId < 1) {
      if (global.AppDialog) AppDialog.alert('افتح سنداً محفوظاً أولاً.', { type: 'warning' });
      return;
    }
    if (!voucherIsPosted) {
      if (global.AppDialog) AppDialog.alert('هذا السند غير مرحّل.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var rcNoEl = document.getElementById('rc_no');
    var rcLabel = rcNoEl && rcNoEl.value ? rcNoEl.value : String(currentVoucherId);
    var confirmMsg =
      'فك ترحيل السند «' +
      rcLabel +
      '»؟\n' +
      'يُزال أثره من كشف العميل والقيد المحاسبي.\n' +
      'بعدها يمكنك تعديل المبلغ أو حذف السند أو إعادة ترحيله.';
    var proceed = function () {
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('voucher_id', String(currentVoucherId));
      fetch(voucherUnpostUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            if (global.AppDialog) AppDialog.error(data.message || data.error || 'تعذر فك الترحيل.');
            return;
          }
          voucherIsPosted = false;
          updatePostedBadge();
          refreshVoucherEditState();
          loadVoucherById(currentVoucherId, true);
          if (onDone) {
            onDone();
          } else if (global.AppDialog) {
            AppDialog.success(data.message || 'تم فك الترحيل.');
          }
        })
        .catch(function () {
          if (global.AppDialog) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    };
    if (global.AppDialog) {
      AppDialog.confirm(confirmMsg, { title: 'فك ترحيل السند', danger: true, okText: 'فك الترحيل' }).then(
        function (ok) {
          if (ok) proceed();
        }
      );
    } else if (confirm(confirmMsg)) {
      proceed();
    }
  }

  function deleteVoucherById(vId, rcLabel, onDone) {
    if (!voucherDeleteUrl) {
      if (global.AppDialog) AppDialog.error('حذف السند غير متاح.');
      return;
    }
    var fd = new FormData();
    var csrfInp = form.querySelector('input[name="_csrf"]');
    fd.append('_csrf', csrfInp ? csrfInp.value : '');
    fd.append('voucher_id', String(vId));
    fetch(voucherDeleteUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          if (global.AppDialog) AppDialog.error(data.error || data.message || 'تعذر حذف السند.');
          return;
        }
        if (global.AppDialog) {
          AppDialog.success(data.message || 'تم حذف السند.').then(function () {
            if (onDone) onDone();
            else if (newUrl) window.location.href = newUrl;
            else initNewReceipt();
          });
        } else {
          if (onDone) onDone();
          else if (newUrl) window.location.href = newUrl;
          else initNewReceipt();
        }
      })
      .catch(function () {
        if (global.AppDialog) AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  function resetReceiptForm() {
    runWithoutDirtyMark(function () {
      if (window.DocumentNoNav) DocumentNoNav.clearSearch(docNoSearch);
      currentVoucherId = 0;
      voucherIsPosted = false;
      voucherIsCancelled = false;
      syncVoucherIdField();
      syncVoucherNoDisplay('');

      var rcDate = document.getElementById('rc_date');
      if (rcDate && defaultDate) rcDate.value = defaultDate;

      var cust = document.getElementById('rc_customer');
      if (cust) cust.value = '';

      applyCustomerRep('');

      setPayMethod('cash');

      var amountEl = document.getElementById('rc_amount');
      if (amountEl) amountEl.value = '';

      var chkAmt = document.getElementById('rc_check_amount');
      if (chkAmt) chkAmt.value = '';

      var chkNo = document.getElementById('rc_check_no');
      if (chkNo) chkNo.value = '';

      var bank = document.getElementById('rc_bank_name');
      if (bank) bank.value = '';

      clearCheckRows();
      recalcChecksTotal();

      var notes = document.getElementById('rc_notes');
      if (notes) notes.value = '';

      syncPayMethodUi();
      refreshVoucherEditState();
      updatePostedBadge();
      refreshEmptyBrowseNav();
      updateHistory(0);
    });
  }

  function initNewReceipt() {
    resetReceiptForm();
    var cust = document.getElementById('rc_customer');
    if (cust) {
      setTimeout(function () {
        cust.focus();
      }, 80);
    }
  }

  function getCustomerLabel() {
    if (rcCustomerPickerApi) return rcCustomerPickerApi.getName();
    return global.CustomerPickerModal ? CustomerPickerModal.getLabel('rc_customer') : '';
  }

  function getDisplayAmount() {
    if (getPayMethod() === 'check') {
      var chkAmt = document.getElementById('rc_check_amount');
      return fmtMoney(parseNum(chkAmt ? chkAmt.value : 0));
    }
    var amountEl = document.getElementById('rc_amount');
    return fmtMoney(parseNum(amountEl ? amountEl.value : 0));
  }

  function buildReceiptPrintInnerHtml() {
    var dateEl = document.getElementById('rc_date');
    var date = fmtDate(dateEl ? dateEl.value : '') || '—';
    var rcNoEl = document.getElementById('rc_no');
    var rcNo = rcNoEl && rcNoEl.value ? rcNoEl.value : '—';
    var cust = getCustomerLabel() || '—';
    var repEl = document.getElementById('rc_sales_rep');
    var rep = repEl ? String(repEl.value).trim() : '';
    var isCheck = getPayMethod() === 'check';
    var payLabel = isCheck ? 'شيك' : 'نقداً';
    var amount = getDisplayAmount();
    var notes = document.getElementById('rc_notes');
    var notesVal = notes ? String(notes.value).trim() : '';

    var posted = !!voucherIsPosted;
    var postedTag =
      currentVoucherId > 0
        ? posted
          ? '<span class="rcp-status rcp-status-posted">مرحَّل</span>'
          : '<span class="rcp-status rcp-status-unposted">غير مرحَّل</span>'
        : '';

    var docInfo =
      '<table class="rcp-docinfo"><tr>' +
      '<td class="rcp-docinfo-cell"><span class="rcp-docinfo-lbl">رقم السند</span>' +
      '<span class="rcp-docinfo-val">' + escapeHtml(rcNo) + '</span></td>' +
      '<td class="rcp-docinfo-cell"><span class="rcp-docinfo-lbl">التاريخ</span>' +
      '<span class="rcp-docinfo-val">' + escapeHtml(date) + '</span></td>' +
      '<td class="rcp-docinfo-cell rcp-docinfo-status">' + postedTag + '</td>' +
      '</tr></table>';

    var amountNumeric = isCheck
      ? parseNum((document.getElementById('rc_check_amount') || {}).value || 0)
      : parseNum((document.getElementById('rc_amount') || {}).value || 0);
    var amountWords = arabicTafqit(amountNumeric);

    var amountBox =
      '<div class="rcp-amount-box">' +
      '<div class="rcp-amount-row">' +
      '<span class="rcp-amount-lbl">مبلغ وقدره</span>' +
      '<span class="rcp-amount-val">' + escapeHtml(amount) + '</span>' +
      '</div>' +
      '<div class="rcp-amount-words">' +
      '<span class="rcp-amount-words-lbl">تفقيطاً:</span> ' +
      '<span class="rcp-amount-words-val">' + escapeHtml(amountWords) + '</span>' +
      '</div>' +
      '</div>';

    var detailsRows =
      '<tr>' +
      '<th class="rcp-th">استلمنا من السيد/السادة</th>' +
      '<td class="rcp-td rcp-td-strong" colspan="3">' + escapeHtml(cust) + '</td>' +
      '</tr>' +
      '<tr>' +
      '<th class="rcp-th">طريقة الدفع</th>' +
      '<td class="rcp-td">' + escapeHtml(payLabel) + '</td>' +
      '<th class="rcp-th">المندوب</th>' +
      '<td class="rcp-td">' + escapeHtml(rep || '—') + '</td>' +
      '</tr>' +
      '<tr>' +
      '<th class="rcp-th">وذلك عن</th>' +
      '<td class="rcp-td rcp-td-reason" colspan="3">' + escapeHtml(notesVal || '—') + '</td>' +
      '</tr>';

    var detailsTable =
      '<table class="rcp-main"><tbody>' + detailsRows + '</tbody></table>';

    var checksTable = '';
    if (isCheck) {
      var rows = getChecksRows();
      var total = 0;
      var bodyHtml = '';
      rows.forEach(function (r, i) {
        total += parseNum(r.check_amount);
        bodyHtml +=
          '<tr>' +
          '<td class="rcp-chk-td rcp-chk-no-col">' + (i + 1) + '</td>' +
          '<td class="rcp-chk-td">' + escapeHtml(r.check_no || '—') + '</td>' +
          '<td class="rcp-chk-td rcp-chk-amount">' + fmtMoney(r.check_amount) + '</td>' +
          '<td class="rcp-chk-td">' + escapeHtml(r.bank_name || '—') + '</td>' +
          '<td class="rcp-chk-td">' + escapeHtml(fmtDate(r.due_date) || '—') + '</td>' +
          '</tr>';
      });
      if (rows.length === 0) {
        bodyHtml = '<tr><td class="rcp-chk-td" colspan="5">—</td></tr>';
      }
      checksTable =
        '<div class="rcp-section-title">تفاصيل الشيكات</div>' +
        '<table class="rcp-checks">' +
        '<thead><tr>' +
        '<th class="rcp-chk-th rcp-chk-no-col">#</th>' +
        '<th class="rcp-chk-th">رقم الشيك</th>' +
        '<th class="rcp-chk-th">المبلغ</th>' +
        '<th class="rcp-chk-th">البنك</th>' +
        '<th class="rcp-chk-th">تاريخ الاستحقاق</th>' +
        '</tr></thead>' +
        '<tbody>' + bodyHtml + '</tbody>' +
        '<tfoot><tr>' +
        '<td class="rcp-chk-td rcp-chk-total-label" colspan="2">إجمالي الشيكات</td>' +
        '<td class="rcp-chk-td rcp-chk-amount rcp-chk-total-val">' + fmtMoney(total) + '</td>' +
        '<td class="rcp-chk-td" colspan="2"></td>' +
        '</tr></tfoot>' +
        '</table>';
    }

    var signatures =
      '<table class="rcp-signs"><tr>' +
      '<td class="rcp-sign-cell"><span class="rcp-sign-lbl">اسم المستلم</span>' +
      '<span class="rcp-sign-line"></span></td>' +
      '<td class="rcp-sign-cell"><span class="rcp-sign-lbl">التوقيع</span>' +
      '<span class="rcp-sign-line"></span></td>' +
      '</tr></table>';

    var inner =
      buildDocPrintHeader('سند قبض') +
      docInfo +
      amountBox +
      detailsTable +
      checksTable +
      signatures;

    return window.DocumentHeader && window.DocumentHeader.wrapPrintContent
      ? window.DocumentHeader.wrapPrintContent(inner, companyLogoUrl)
      : inner;
  }

  function docPrintWatermarkStyles() {
    var dh = window.DocumentHeader;
    return dh && companyLogoUrl && dh.buildPrintWatermarkStyles
      ? dh.buildPrintWatermarkStyles(companyLogoUrl)
      : '';
  }

  function getPrintFrameStyles() {
    var hdr = window.DocumentHeader && window.DocumentHeader.css ? window.DocumentHeader.css : '';
    var bold =
      window.DocumentHeader && window.DocumentHeader.printBoldCss
        ? window.DocumentHeader.printBoldCss
        : '';
    return (
      docPrintWatermarkStyles() +
      hdr +
      bold +
      'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0f172a;margin:6mm 12mm 12mm;direction:rtl;}' +
      'table{border-collapse:collapse;width:100%;margin-top:0.5rem;}' +

      // === Receipt voucher specific print styling ===
      '.rcp-docinfo{width:100%;border-collapse:collapse;margin:0.6rem 0 0.4rem;border:none;}' +
      '.rcp-docinfo-cell{border:1px solid #94a3b8;padding:0.45rem 0.7rem;width:33.33%;text-align:center;background:#f8fafc;}' +
      '.rcp-docinfo-lbl{display:block;font-size:0.78rem;color:#475569;font-weight:700;margin-bottom:2px;}' +
      '.rcp-docinfo-val{display:block;font-size:1rem;font-weight:800;color:#0f172a;letter-spacing:0.5px;}' +
      '.rcp-docinfo-status{vertical-align:middle;}' +
      '.rcp-status{display:inline-block;padding:0.18rem 0.7rem;border-radius:20px;font-size:0.78rem;font-weight:800;border:1.5px solid;}' +
      '.rcp-status-posted{color:#065f46;border-color:#10b981;background:#ecfdf5;}' +
      '.rcp-status-unposted{color:#92400e;border-color:#f59e0b;background:#fffbeb;}' +

      '.rcp-amount-box{display:flex;flex-direction:column;border:2px solid #0f172a;border-radius:6px;padding:0.55rem 1rem;margin:0.6rem 0;background:#fffbeb;}' +
      '.rcp-amount-row{display:flex;align-items:center;justify-content:space-between;width:100%;}' +
      '.rcp-amount-lbl{font-size:0.95rem;font-weight:800;color:#0f172a;}' +
      '.rcp-amount-val{font-size:1.4rem;font-weight:900;color:#0f172a;letter-spacing:1px;direction:ltr;display:inline-block;}' +
      '.rcp-amount-words{margin-top:0.45rem;padding-top:0.4rem;border-top:1px dashed #b45309;font-size:0.9rem;color:#1f2937;line-height:1.55;text-align:start;}' +
      '.rcp-amount-words-lbl{font-weight:800;color:#7c2d12;margin-inline-end:0.25rem;}' +
      '.rcp-amount-words-val{font-weight:700;}' +

      '.rcp-main{width:100%;border-collapse:collapse;margin-top:0.4rem;table-layout:fixed;}' +
      '.rcp-main .rcp-th{background:#f1f5f9;color:#0f172a;font-weight:800;font-size:0.85rem;padding:0.45rem 0.65rem;border:1px solid #94a3b8;text-align:start;width:22%;}' +
      '.rcp-main .rcp-td{padding:0.45rem 0.65rem;border:1px solid #cbd5e1;font-weight:700;font-size:0.92rem;color:#0f172a;text-align:start;}' +
      '.rcp-main .rcp-td-strong{font-size:1rem;font-weight:800;}' +
      '.rcp-main .rcp-td-reason{min-height:2.4rem;line-height:1.6;font-style:italic;color:#1e293b;}' +

      '.rcp-section-title{margin:0.85rem 0 0.3rem;font-weight:800;font-size:0.95rem;color:#0f172a;border-bottom:2px solid #0f172a;padding-bottom:0.2rem;}' +
      '.rcp-checks{width:100%;border-collapse:collapse;margin-top:0.25rem;}' +
      '.rcp-checks .rcp-chk-th{background:#e2e8f0;color:#0f172a;font-weight:800;font-size:0.82rem;padding:0.4rem 0.45rem;border:1px solid #64748b;text-align:center;}' +
      '.rcp-checks .rcp-chk-td{padding:0.4rem 0.45rem;border:1px solid #94a3b8;font-weight:700;font-size:0.9rem;text-align:center;color:#0f172a;}' +
      '.rcp-checks tbody tr:nth-child(even) .rcp-chk-td{background:#f8fafc;}' +
      '.rcp-chk-no-col{width:3rem;}' +
      '.rcp-chk-amount{font-family:Arial,Helvetica,sans-serif;direction:ltr;}' +
      '.rcp-chk-total-label{text-align:end!important;font-weight:800;background:#f1f5f9;}' +
      '.rcp-chk-total-val{font-weight:900;background:#fffbeb;font-size:1rem;}' +

      '.rcp-signs{width:100%;margin-top:1.6rem;border:none;border-collapse:separate;border-spacing:1.5rem 0;}' +
      '.rcp-signs .rcp-sign-cell{border:none!important;text-align:center;padding:0;vertical-align:bottom;}' +
      '.rcp-sign-lbl{display:block;font-size:0.85rem;font-weight:800;color:#0f172a;margin-bottom:1.6rem;}' +
      '.rcp-sign-line{display:block;border-top:1.5px solid #0f172a;width:90%;margin:0 auto;}' +

      '@media print{' +
      '.rcp-docinfo-cell,.rcp-amount-box,.rcp-main .rcp-th,.rcp-main .rcp-td,' +
      '.rcp-checks .rcp-chk-th,.rcp-checks .rcp-chk-td{print-color-adjust:exact;-webkit-print-color-adjust:exact;}' +
      '}'
    );
  }

  function buildStandaloneReceiptHtml() {
    var bodyAttrs =
      window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
        ? window.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>سند قبض</title>' +
      '<style>' +
      getPrintFrameStyles() +
      '</style></head><body' +
      bodyAttrs +
      '>' +
      buildReceiptPrintInnerHtml() +
      '</body></html>'
    );
  }

  function getPrintFrame() {
    var frame = document.getElementById('fin-rc-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'fin-rc-print-frame';
      frame.className = 'sales-inv-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      document.body.appendChild(frame);
    }
    return frame;
  }

  function printHtmlInFrame(fullHtml) {
    var frame = getPrintFrame();
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(fullHtml);
    win.document.close();
    setTimeout(function () {
      try {
        win.focus();
        win.print();
      } catch (e) {}
    }, 200);
  }

  function handleToolbarPrint() {
    printHtmlInFrame(buildStandaloneReceiptHtml());
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!form || !e.detail) return;
    var action = e.detail.action;

    if (action === 'search') {
      e.preventDefault();
      e.stopImmediatePropagation();
      runToolbarVoucherSearch();
      return;
    }
    if (action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();
      trySave();
      return;
    }
    if (action === 'post') {
      e.preventDefault();
      postCurrent();
      return;
    }
    if (action === 'edit') {
      e.preventDefault();
      e.stopImmediatePropagation();
      editCurrentVoucher();
      return;
    }
    if (action === 'unpost') {
      e.preventDefault();
      e.stopImmediatePropagation();
      unpostCurrent();
      return;
    }
    if (action === 'cancel_voucher') {
      e.preventDefault();
      e.stopImmediatePropagation();
      cancelCurrent();
      return;
    }
    if (action === 'print') {
      e.preventDefault();
      e.stopImmediatePropagation();
      handleToolbarPrint();
      return;
    }
    if (action === 'delete') {
      e.preventDefault();
      if (currentVoucherId > 0) {
        var vId = currentVoucherId;
        var rcNoEl = document.getElementById('rc_no');
        var rcLabel = rcNoEl && rcNoEl.value ? rcNoEl.value : String(vId);
        if (!voucherDeleteUrl) {
          if (global.AppDialog) AppDialog.error('حذف السند غير متاح.');
          return;
        }
        if (voucherIsCancelled) {
          if (global.AppDialog) {
            AppDialog.alert('لا يمكن حذف سند ملغى. يبقى في السجل للحفاظ على التسلسل.', { type: 'warning' });
          }
          return;
        }
        if (voucherIsPosted) {
          if (global.AppDialog) {
            AppDialog.alert(
              'لا يمكن حذف سند مرحّل. استخدم «إلغاء السند» من الشريط العلوي.',
              { type: 'warning' }
            );
          }
          return;
        }
        var deleteMsg = 'حذف مسودة السند «' + rcLabel + '»؟\nسيُعاد استخدام رقم السند في السند التالي إن وُجد.';
        if (global.AppDialog) {
          AppDialog.confirm(deleteMsg, {
            title: 'حذف السند',
            danger: true,
            okText: 'حذف',
          }).then(function (ok) {
            if (!ok) return;
            deleteVoucherById(vId, rcLabel);
          });
        }
        return;
      }
      if (!hasDraftContent()) {
        resetReceiptForm();
        return;
      }
      if (global.AppDialog) {
        AppDialog.confirm('مسح بيانات السند الحالي؟', {
          title: 'مسح السند',
          danger: true,
          okText: 'مسح',
        }).then(function (ok) {
          if (ok) resetReceiptForm();
        });
      } else if (confirm('مسح بيانات السند؟')) {
        resetReceiptForm();
      }
      return;
    }
    if (action === 'exit') {
      e.preventDefault();
      confirmUnsavedChanges(function () {
        if (exitUrl) {
          window.location.href = exitUrl;
        } else {
          var bar = document.getElementById('master-toolbar');
          var url = bar ? bar.getAttribute('data-exit-url') : '';
          if (url) window.location.href = url;
          else window.history.back();
        }
      });
    }
  });

  var newRcBtn = document.querySelector('.sales-inv-btn-new');
  if (newRcBtn) {
    newRcBtn.addEventListener('click', function (e) {
      e.preventDefault();
      confirmUnsavedChanges(function () {
        if (newUrl) window.location.href = newUrl;
        else initNewReceipt();
      });
    });
  }

  var prevBtn = document.getElementById('rc_no_prev');
  var nextBtn = document.getElementById('rc_no_next');
  var rcNoInput = document.getElementById('rc_no');
  if (prevBtn) {
    prevBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      navigateVoucher('prev');
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      navigateVoucher('next');
    });
  }
  if (rcNoInput) {
    rcNoInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      runToolbarVoucherSearch();
    });
    rcNoInput.addEventListener('blur', function () {
      var no = rcNoInput.value.trim();
      if (window.DocumentNoNav && DocumentNoNav.shouldSkipBlurSearch(docNoSearch, currentVoucherId, no)) {
        return;
      }
      loadVoucherByNo(no);
    });
  }

  var payRow = form.querySelector('.fin-rc-pay-row');
  if (payRow) {
    payRow.addEventListener('change', function (e) {
      if (e.target && e.target.name === 'pay_method') syncPayMethodUi();
    });
    payRow.addEventListener('click', function (e) {
      if (e.target && e.target.name === 'pay_method') {
        setTimeout(syncPayMethodUi, 0);
      }
    });
  }

  var addCheckBtn = document.getElementById('rc_check_add');
  if (addCheckBtn) {
    addCheckBtn.addEventListener('click', function () {
      if (voucherIsPosted) return;
      var tr = addCheckRow();
      reindexCheckRows();
      recalcChecksTotal();
      if (tr) {
        var nm = tr.querySelector('.fin-rc-check-no');
        if (nm) nm.focus();
      }
      markFormDirty();
    });
  }

  form.addEventListener('click', function (e) {
    var undoBtn = e.target.closest('.fin-rc-check-undo');
    if (!undoBtn) return;
    e.preventDefault();
    var checkId = parseInt(undoBtn.getAttribute('data-check-id') || '0', 10) || 0;
    undoCheckFromVoucher(checkId, undoBtn.getAttribute('data-undo-label') || 'إلغاء');
  });

  form.addEventListener('input', markFormDirtyFromEvent);
  form.addEventListener('change', markFormDirtyFromEvent);
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (voucherIsPosted) return;
    trySave();
  });

  function bootReceiptPage() {
    if (global.FinVoucherArchive) {
      global.FinVoucherArchive.init({
        apiUrl: form.getAttribute('data-archive-api') || '',
        csrf: (form.querySelector('input[name="_csrf"]') || {}).value || '',
        kind: form.getAttribute('data-archive-kind') || 'receipt',
        title: 'سند قبض',
        canArchive: form.getAttribute('data-can-archive') === '1',
        getVoucherId: function () {
          return currentVoucherId;
        },
        getVoucherLabel: function () {
          return {
            no: (document.getElementById('rc_no') || {}).value || '',
            date: (document.getElementById('rc_date') || {}).value || '',
          };
        },
        companyName: form.getAttribute('data-company-name') || '',
        isArchiveAllowed: receiptArchiveState,
      });
    }
    if (global.CustomerPickerModal) {
      rcCustomerPickerApi = CustomerPickerModal.bind({
        hidden: 'rc_customer',
        open: 'rc_customer_open',
        display: 'rc_customer_display',
        jsonId: 'fin-rc-customers-json',
        getDisabled: function () {
          return voucherIsPosted;
        },
        onSelect: function (c) {
          applyCustomerRep(c ? c.sales_rep_name : '');
          markFormDirty();
        },
      });
    }
    syncPayMethodUi();
    if (initialVoucherId > 0) {
      fetchVoucherResponse({ id: initialVoucherId }).then(function (data) {
        if (data && data.ok && data.voucher) {
          applyVoucherData(data);
        }
      });
      return;
    }
    runWithoutDirtyMark(function () {
      refreshVoucherEditState();
      updatePostedBadge();
      refreshEmptyBrowseNav();
      applyCustomerRep();
      var openBtn = document.getElementById('rc_customer_open');
      if (openBtn) {
        setTimeout(function () {
          openBtn.focus();
        }, 80);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootReceiptPage);
  } else {
    bootReceiptPage();
  }

  window.addEventListener('beforeunload', function (e) {
    if (window.__managerAllowUnload) return;
    if (formSubmitting || !formDirty || voucherIsPosted || voucherIsCancelled) return;
    e.preventDefault();
    e.returnValue = '';
  });

  if (global.ScreenExitGuard && typeof global.ScreenExitGuard.registerScreenExitDeferred === 'function') {
    global.ScreenExitGuard.registerScreenExitDeferred({
      hasUnsaved: function () {
        return formDirty && !voucherIsPosted && !voucherIsCancelled;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  } else if (global.ScreenExitGuard && typeof global.ScreenExitGuard.registerScreenExit === 'function') {
    global.ScreenExitGuard.registerScreenExit({
      hasUnsaved: function () {
        return formDirty && !voucherIsPosted && !voucherIsCancelled;
      },
      confirmLeave: confirmUnsavedChanges,
    });
  } else {
    global.ManagerScreenExit = {
      hasUnsaved: function () {
        return formDirty && !voucherIsPosted && !voucherIsCancelled;
      },
      confirmLeave: confirmUnsavedChanges,
    };
  }
})();
