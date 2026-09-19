(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('cash-rc-form');
  if (!form) return;

  var apiVoucherUrl = form.getAttribute('data-api-voucher') || '';
  var voucherPostUrl = form.getAttribute('data-voucher-post-url') || '';
  var voucherDeleteUrl = form.getAttribute('data-voucher-delete-url') || '';
  var newUrl = form.getAttribute('data-new-url') || '';
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var initialVoucherId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var defaultDate = form.getAttribute('data-default-date') || '';
  var defaultCashId = parseInt(form.getAttribute('data-default-cash-id') || '0', 10);
  var companyName = form.getAttribute('data-company-name') || '';
  var companyLogoUrl = form.getAttribute('data-company-logo') || '';

  var currentVoucherId = 0;
  var browseNavPrevId = 0;
  var browseNavNextId = 0;
  var voucherIsPosted = false;
  var formDirty = false;
  var formSubmitting = false;
  var suppressDirtyMark = 0;
  var pendingAfterSave = null;

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

  function parseNum(v) {
    if (v === '' || v === null || v === undefined) return 0;
    var s = String(v).replace(/,/g, '');
    var x = parseFloat(s);
    return isFinite(x) ? x : 0;
  }

  function getPaymentMethod() {
    var checked = form.querySelector('input[name="payment_method"]:checked');
    return checked && checked.value === 'check' ? 'check' : 'cash';
  }

  function setPaymentMethod(method) {
    var val = method === 'check' ? 'check' : 'cash';
    form.querySelectorAll('input[name="payment_method"]').forEach(function (inp) {
      inp.checked = inp.value === val;
    });
    syncPaymentMethodUi();
  }

  function syncPaymentMethodUi() {
    var isCheck = getPaymentMethod() === 'check';
    var wrapCash = document.getElementById('rc-wrap-cash-amount');
    if (wrapCash) wrapCash.hidden = isCheck;
    ['rc-wrap-check-amount', 'rc-wrap-check-no', 'rc-wrap-bank'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.hidden = !isCheck;
    });
    syncPrintOnlyMeta();
  }

  function syncSalesRepDisplay(fromApiName) {
    var rep = document.getElementById('rc_sales_rep_display');
    if (!rep) return;
    if (fromApiName !== undefined && fromApiName !== null && String(fromApiName).trim() !== '') {
      rep.value = String(fromApiName);
      return;
    }
    var cust = document.getElementById('rc_customer');
    if (!cust || !cust.value) {
      rep.value = '';
      return;
    }
    var opt = cust.options[cust.selectedIndex];
    rep.value = opt ? opt.getAttribute('data-sales-rep-name') || '' : '';
  }

  function isSearchOnlyField(el) {
    if (!el || !el.id) return false;
    return el.id === 'rc_no';
  }

  function markFormDirty() {
    if (suppressDirtyMark > 0 || voucherIsPosted) return;
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


})();
