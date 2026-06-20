(function () {
  'use strict';

  var global = typeof window !== 'undefined' ? window : self;

  var form = document.getElementById('fin-py-form');
  if (!form) return;

  var apiVoucherUrl = form.getAttribute('data-api-voucher') || '';
  var apiEmployeeAdvancesUrl = form.getAttribute('data-api-employee-advances') || '';
  var apiEmployeeSalariesUrl = form.getAttribute('data-api-employee-salaries') || '';
  var salariesPayableAccountId =
    parseInt(form.getAttribute('data-salaries-payable-account-id') || '0', 10) || 0;
  var voucherPostUrl = form.getAttribute('data-voucher-post-url') || '';
  var voucherUnpostUrl = form.getAttribute('data-voucher-unpost-url') || '';
  var voucherDeleteUrl = form.getAttribute('data-voucher-delete-url') || '';
  var newUrl = form.getAttribute('data-new-url') || '';
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var initialVoucherId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var defaultDate = form.getAttribute('data-default-date') || '';
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

  function getPayMethod() {
    var check = document.getElementById('py_pay_check');
    return check && check.checked ? 'check' : 'cash';
  }

  function setPayMethod(method) {
    var isCheck = method === 'check';
    var cash = document.getElementById('py_pay_cash');
    var chk = document.getElementById('py_pay_check');
    if (cash) cash.checked = !isCheck;
    if (chk) chk.checked = isCheck;
    syncPayMethodUi();
  }

  function getRcWrap() {
    return form.closest('.fin-py-wrap') || form;
  }

  function setPanelVisible(el, visible) {
    if (!el) return;
    if (visible) {
      el.hidden = false;
      el.style.display = el.classList.contains('fin-py-party-panel') ? 'flex' : '';
    } else {
      el.hidden = true;
      el.style.display = 'none';
    }
  }

  function syncPayMethodUi() {
    var isCheck = getPayMethod() === 'check';
    var wrap = getRcWrap();
    wrap.classList.toggle('fin-py-pay-is-check', isCheck);
    wrap.classList.toggle('fin-py-pay-is-cash', !isCheck);
    form.classList.toggle('fin-py-pay-is-check', isCheck);
    form.classList.toggle('fin-py-pay-is-cash', !isCheck);

    var cashWrap = document.getElementById('py_cash_amount_wrap');
    if (cashWrap) {
      cashWrap.hidden = isCheck;
      cashWrap.style.display = isCheck ? 'none' : '';
    }

    ['py_check_fields', 'py_check_no_wrap', 'py_bank_wrap'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.hidden = !isCheck;
      el.style.display = isCheck ? '' : 'none';
    });

    var amountEl = document.getElementById('py_amount');
    var chkAmt = document.getElementById('py_check_amount');
    if (isCheck && amountEl && chkAmt) {
      var cashVal = parseNum(amountEl.value);
      if (cashVal > 0 && parseNum(chkAmt.value) <= 0) {
        chkAmt.value = amountEl.value;
      }
      amountEl.value = '';
    } else if (!isCheck && amountEl && chkAmt) {
      var checkVal = parseNum(chkAmt.value);
      if (checkVal > 0 && parseNum(amountEl.value) <= 0) {
        amountEl.value = chkAmt.value;
      }
    }
    suggestCashAccountForPayMethod();
    syncEmployeeAmountLock();
  }

  function suggestCashAccountForPayMethod() {
    var sel = document.getElementById('py_cash_account_id');
    if (!sel || sel.options.length < 1) return;
    var cur = sel.options[sel.selectedIndex];
    if (cur && cur.getAttribute('data-group') === 'partner') {
      return;
    }
    var isCheck = getPayMethod() === 'check';
    var preferred = isCheck ? ['112', '113'] : ['111'];
    for (var p = 0; p < preferred.length; p++) {
      for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].getAttribute('data-code') === preferred[p]) {
          sel.value = sel.options[i].value;
          return;
        }
      }
    }
  }

  function getCashAccountLabel() {
    var sel = document.getElementById('py_cash_account_id');
    if (!sel || sel.selectedIndex < 0) return '';
    return sel.options[sel.selectedIndex].textContent.trim();
  }

  var pyCustomerPickerApi = null;
  var pySupplierPickerApi = null;
  var pyEmployeePickerApi = null;
  var pyAccountPickerApi = null;
  var pendingEmployeeAdvances = [];
  var pendingEmployeeSalaries = [];
  var selectedHrAdvanceId = 0;
  var selectedHrSalaryId = 0;
  var employeeLockedAmount = null;
  var advancePayableAccountId = 0;

  var PARTY_TYPES = ['supplier', 'customer', 'employee', 'account'];

  function normalizePartyType(pt) {
    return PARTY_TYPES.indexOf(pt) >= 0 ? pt : 'supplier';
  }

  function getPartyType() {
    var hidden = document.getElementById('py_party_type');
    if (hidden && PARTY_TYPES.indexOf(hidden.value) >= 0) return hidden.value;
    var r = form.querySelector('input[name="party_type_ui"]:checked');
    return normalizePartyType(r ? r.value : 'supplier');
  }

  function setPartyType(pt) {
    var type = normalizePartyType(pt);
    var hidden = document.getElementById('py_party_type');
    if (hidden) hidden.value = type;
    var radios = form.querySelectorAll('input[name="party_type_ui"]');
    radios.forEach(function (radio) {
      radio.checked = radio.value === type;
    });
    syncPartyTypeUi();
  }

  function getEmployeePayKind() {
    var hidden = document.getElementById('py_employee_pay_kind');
    if (hidden && hidden.value === 'other') return 'other';
    var r = form.querySelector('input[name="employee_pay_kind_ui"]:checked');
    return r && r.value === 'other' ? 'other' : 'advance';
  }

  function setEmployeePayKind(kind) {
    var type = kind === 'other' ? 'other' : 'advance';
    var hidden = document.getElementById('py_employee_pay_kind');
    if (hidden) hidden.value = type;
    var radios = form.querySelectorAll('input[name="employee_pay_kind_ui"]');
    radios.forEach(function (radio) {
      radio.checked = radio.value === type;
    });
    syncEmployeePayKindUi();
  }

  function syncEmployeePayKindUi() {
    var kind = getEmployeePayKind();
    var advPanel = document.getElementById('py-employee-advance-panel');
    var otherPanel = document.getElementById('py-employee-other-panel');
    setPanelVisible(advPanel, kind === 'advance');
    setPanelVisible(otherPanel, kind === 'other');
    var wrap = getRcWrap();
    if (wrap) {
      wrap.classList.toggle('fin-py-emp-pay-is-advance', kind === 'advance');
      wrap.classList.toggle('fin-py-emp-pay-is-other', kind === 'other');
    }
    syncOffsetAccountField();
    syncEmployeeSalaryPanel();
    syncEmployeeAmountLock();
  }

  function isSalariesPayableOffsetSelected() {
    if (salariesPayableAccountId < 1) return false;
    var empOff = document.getElementById('py_employee_offset');
    return empOff && parseInt(empOff.value, 10) === salariesPayableAccountId;
  }

  function syncEmployeeSalaryPanel() {
    var panel = document.getElementById('py-employee-salary-panel');
    var show =
      getPartyType() === 'employee' &&
      getEmployeePayKind() === 'other' &&
      isSalariesPayableOffsetSelected();
    setPanelVisible(panel, show);
    if (!show) {
      selectedHrSalaryId = 0;
      syncHrSalaryHidden();
      if (getEmployeePayKind() === 'other' && !isSalariesPayableOffsetSelected()) {
        employeeLockedAmount = null;
      }
    }
  }

  function syncHrSalaryHidden() {
    var hidden = document.getElementById('py_hr_salary_id');
    if (!hidden) return;
    hidden.value =
      getPartyType() === 'employee' &&
      getEmployeePayKind() === 'other' &&
      isSalariesPayableOffsetSelected() &&
      selectedHrSalaryId > 0
        ? String(selectedHrSalaryId)
        : '';
  }

  function isEmployeeHrAmountLocked() {
    if (getPartyType() !== 'employee' || voucherIsPosted) return false;
    if (getEmployeePayKind() === 'advance') return selectedHrAdvanceId > 0;
    if (getEmployeePayKind() === 'other' && isSalariesPayableOffsetSelected()) {
      return selectedHrSalaryId > 0;
    }
    return false;
  }

  function syncEmployeeAmountLock() {
    var locked = isEmployeeHrAmountLocked();
    var amountEl = document.getElementById('py_amount');
    var chkAmt = document.getElementById('py_check_amount');
    var wrap = getRcWrap();
    if (wrap) wrap.classList.toggle('fin-py-amount-locked', locked);

    if (locked && employeeLockedAmount !== null && employeeLockedAmount > 0) {
      var formatted = fmtMoney(employeeLockedAmount);
      if (getPayMethod() === 'check') {
        if (chkAmt) {
          chkAmt.value = formatted;
          chkAmt.readOnly = true;
          chkAmt.classList.add('fin-py-amount-readonly');
        }
        if (amountEl) {
          amountEl.value = '';
          amountEl.readOnly = true;
        }
      } else {
        if (amountEl) {
          amountEl.value = formatted;
          amountEl.readOnly = true;
          amountEl.classList.add('fin-py-amount-readonly');
        }
        if (chkAmt) {
          chkAmt.readOnly = true;
        }
      }
      return;
    }

    if (amountEl) {
      amountEl.classList.remove('fin-py-amount-readonly');
      if (!(currentVoucherId > 0 && voucherIsPosted)) amountEl.readOnly = false;
    }
    if (chkAmt) {
      chkAmt.classList.remove('fin-py-amount-readonly');
      if (!(currentVoucherId > 0 && voucherIsPosted)) chkAmt.readOnly = false;
    }
  }

  function syncOffsetAccountField() {
    var hidden = document.getElementById('py_offset_account_id');
    if (!hidden) return;
    var type = getPartyType();
    if (type === 'employee') {
      if (getEmployeePayKind() === 'advance') {
        hidden.value = advancePayableAccountId > 0 ? String(advancePayableAccountId) : '';
      } else {
        var empOff = document.getElementById('py_employee_offset');
        hidden.value = empOff && empOff.value ? empOff.value : '';
      }
    } else if (type === 'account') {
      var accTarget = document.getElementById('py_account_target');
      hidden.value = accTarget && accTarget.value ? accTarget.value : '';
    } else {
      hidden.value = '';
    }
  }

  function syncHrAdvanceHidden() {
    var hidden = document.getElementById('py_hr_advance_id');
    if (!hidden) return;
    hidden.value =
      getPartyType() === 'employee' && getEmployeePayKind() === 'advance' && selectedHrAdvanceId > 0
        ? String(selectedHrAdvanceId)
        : '';
  }

  function renderEmployeeAdvancesList() {
    var box = document.getElementById('py_advances_list');
    if (!box) return;
    if (!pendingEmployeeAdvances.length) {
      box.innerHTML =
        '<p class="fin-py-advances-empty muted">لا توجد سلف معتمدة من الشؤون بانتظار الصرف لهذا الموظف.</p>';
      return;
    }
    var html =
      '<table class="fin-py-advances-table"><thead><tr>' +
      '<th></th><th>رقم السلفة</th><th>الفترة</th><th>النوع</th><th>المبلغ</th></tr></thead><tbody>';
    pendingEmployeeAdvances.forEach(function (adv) {
      var id = parseInt(adv.id, 10) || 0;
      var checked = id > 0 && id === selectedHrAdvanceId;
      html +=
        '<tr class="fin-py-advance-row' +
        (checked ? ' is-selected' : '') +
        '">' +
        '<td><input type="radio" name="py_advance_pick" value="' +
        id +
        '"' +
        (checked ? ' checked' : '') +
        '></td>' +
        '<td>' +
        escapeHtml(adv.advance_code || String(id)) +
        '</td>' +
        '<td>' +
        escapeHtml(adv.period_label || adv.start_date || '—') +
        '</td>' +
        '<td>' +
        escapeHtml(adv.advance_type_label || '') +
        '</td>' +
        '<td dir="ltr">' +
        escapeHtml(fmtMoney(adv.total_amount || 0)) +
        '</td></tr>';
    });
    html += '</tbody></table>';
    box.innerHTML = html;
    box.querySelectorAll('input[name="py_advance_pick"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (radio.checked) applySelectedAdvance(parseInt(radio.value, 10) || 0);
      });
    });
    box.querySelectorAll('.fin-py-advance-row').forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target && e.target.name === 'py_advance_pick') return;
        var radio = row.querySelector('input[name="py_advance_pick"]');
        if (radio) {
          radio.checked = true;
          applySelectedAdvance(parseInt(radio.value, 10) || 0);
        }
      });
    });
  }

  function applySelectedAdvance(advanceId) {
    selectedHrAdvanceId = advanceId > 0 ? advanceId : 0;
    syncHrAdvanceHidden();
    var adv = null;
    for (var i = 0; i < pendingEmployeeAdvances.length; i++) {
      if (parseInt(pendingEmployeeAdvances[i].id, 10) === selectedHrAdvanceId) {
        adv = pendingEmployeeAdvances[i];
        break;
      }
    }
    if (adv) {
      employeeLockedAmount = parseNum(adv.total_amount || 0);
      setEmployeePayKind('advance');
    } else {
      employeeLockedAmount = null;
    }
    renderEmployeeAdvancesList();
    syncOffsetAccountField();
    syncEmployeeAmountLock();
    markFormDirty();
  }

  function clearEmployeeAdvances() {
    pendingEmployeeAdvances = [];
    selectedHrAdvanceId = 0;
    advancePayableAccountId = 0;
    syncHrAdvanceHidden();
    renderEmployeeAdvancesList();
    if (getEmployeePayKind() === 'advance') {
      employeeLockedAmount = null;
      syncEmployeeAmountLock();
    }
  }

  function loadEmployeeAdvances(employeeId) {
    clearEmployeeAdvances();
    if (!apiEmployeeAdvancesUrl || employeeId < 1) return Promise.resolve();
    return fetch(apiEmployeeAdvancesUrl + '?employee_id=' + encodeURIComponent(String(employeeId)), {
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) return;
        pendingEmployeeAdvances = Array.isArray(data.advances) ? data.advances : [];
        advancePayableAccountId = parseInt(data.payable_account_id, 10) || 0;
        var disp = document.getElementById('py_advance_payable_display');
        if (disp && data.payable_account_label) disp.value = data.payable_account_label;
        if (pendingEmployeeAdvances.length === 1) {
          applySelectedAdvance(parseInt(pendingEmployeeAdvances[0].id, 10) || 0);
        } else {
          renderEmployeeAdvancesList();
          syncOffsetAccountField();
        }
        if (pendingEmployeeAdvances.length > 0) {
          setEmployeePayKind('advance');
        }
      })
      .catch(function () {
        clearEmployeeAdvances();
      });
  }

  function renderEmployeeSalariesList() {
    var box = document.getElementById('py_salaries_list');
    if (!box) return;
    if (!pendingEmployeeSalaries.length) {
      box.innerHTML =
        '<p class="fin-py-advances-empty muted">لا توجد رواتب مرحّلة بانتظار الصرف لهذا الموظف.</p>';
      return;
    }
    var html =
      '<table class="fin-py-advances-table"><thead><tr>' +
      '<th></th><th>الفترة</th><th>صافي الراتب</th></tr></thead><tbody>';
    pendingEmployeeSalaries.forEach(function (sal) {
      var id = parseInt(sal.id, 10) || 0;
      var checked = id > 0 && id === selectedHrSalaryId;
      html +=
        '<tr class="fin-py-advance-row' +
        (checked ? ' is-selected' : '') +
        '">' +
        '<td><input type="radio" name="py_salary_pick" value="' +
        id +
        '"' +
        (checked ? ' checked' : '') +
        '></td>' +
        '<td>' +
        escapeHtml(sal.period_label || '—') +
        '</td>' +
        '<td dir="ltr">' +
        escapeHtml(fmtMoney(sal.net_salary || 0)) +
        '</td></tr>';
    });
    html += '</tbody></table>';
    box.innerHTML = html;
    box.querySelectorAll('input[name="py_salary_pick"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (radio.checked) applySelectedSalary(parseInt(radio.value, 10) || 0);
      });
    });
    box.querySelectorAll('.fin-py-advance-row').forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target && e.target.name === 'py_salary_pick') return;
        var radio = row.querySelector('input[name="py_salary_pick"]');
        if (radio) {
          radio.checked = true;
          applySelectedSalary(parseInt(radio.value, 10) || 0);
        }
      });
    });
  }

  function applySelectedSalary(salaryId) {
    selectedHrSalaryId = salaryId > 0 ? salaryId : 0;
    syncHrSalaryHidden();
    var sal = null;
    for (var i = 0; i < pendingEmployeeSalaries.length; i++) {
      if (parseInt(pendingEmployeeSalaries[i].id, 10) === selectedHrSalaryId) {
        sal = pendingEmployeeSalaries[i];
        break;
      }
    }
    if (sal) {
      employeeLockedAmount = parseNum(sal.net_salary || 0);
    } else {
      employeeLockedAmount = null;
    }
    renderEmployeeSalariesList();
    syncEmployeeAmountLock();
    markFormDirty();
  }

  function clearEmployeeSalaries() {
    pendingEmployeeSalaries = [];
    selectedHrSalaryId = 0;
    syncHrSalaryHidden();
    renderEmployeeSalariesList();
    if (getEmployeePayKind() === 'other' && isSalariesPayableOffsetSelected()) {
      employeeLockedAmount = null;
      syncEmployeeAmountLock();
    }
  }

  function loadEmployeeSalaries(employeeId) {
    clearEmployeeSalaries();
    if (!apiEmployeeSalariesUrl || employeeId < 1) return Promise.resolve();
    return fetch(apiEmployeeSalariesUrl + '?employee_id=' + encodeURIComponent(String(employeeId)), {
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) return;
        pendingEmployeeSalaries = Array.isArray(data.salaries) ? data.salaries : [];
        if (
          isSalariesPayableOffsetSelected() &&
          pendingEmployeeSalaries.length === 1 &&
          selectedHrSalaryId < 1
        ) {
          applySelectedSalary(parseInt(pendingEmployeeSalaries[0].id, 10) || 0);
        } else {
          renderEmployeeSalariesList();
        }
        syncEmployeeSalaryPanel();
      })
      .catch(function () {
        clearEmployeeSalaries();
      });
  }

  function loadEmployeeHrData(employeeId) {
    return Promise.all([
      loadEmployeeAdvances(employeeId),
      loadEmployeeSalaries(employeeId),
    ]).then(function () {
      syncEmployeeSalaryPanel();
      syncEmployeeAmountLock();
    });
  }

  function syncPartyTypeUi() {
    var type = getPartyType();
    var wrap = getRcWrap();
    if (wrap) {
      wrap.classList.toggle('fin-py-party-is-customer', type === 'customer');
      wrap.classList.toggle('fin-py-party-is-supplier', type === 'supplier');
      wrap.classList.toggle('fin-py-party-is-employee', type === 'employee');
      wrap.classList.toggle('fin-py-party-is-account', type === 'account');
    }
    setPanelVisible(document.getElementById('py-party-customer-wrap'), type === 'customer');
    setPanelVisible(document.getElementById('py-party-supplier-wrap'), type === 'supplier');
    setPanelVisible(document.getElementById('py-party-employee-wrap'), type === 'employee');
    setPanelVisible(document.getElementById('py-party-account-wrap'), type === 'account');
    var repWrap = form.querySelector('.fin-py-party-customer-only');
    if (repWrap) {
      repWrap.hidden = type !== 'customer';
      repWrap.style.display = type === 'customer' ? '' : 'none';
    }
    if (type === 'employee') {
      syncEmployeePayKindUi();
    } else {
      employeeLockedAmount = null;
      syncOffsetAccountField();
      syncEmployeeAmountLock();
    }
  }

  function applyCustomerRep(apiRepName) {
    var rep = document.getElementById('py_sales_rep');
    if (!rep) return;
    if (apiRepName !== undefined && apiRepName !== null && String(apiRepName).trim() !== '') {
      rep.value = String(apiRepName).trim();
      return;
    }
    var cust = document.getElementById('py_customer');
    if (!cust || !cust.value) {
      rep.value = '';
      return;
    }
    if (pyCustomerPickerApi) {
      var c = pyCustomerPickerApi.getCustomer();
      rep.value = c && c.sales_rep_name ? String(c.sales_rep_name) : '';
      return;
    }
    rep.value = '';
  }

  function isSearchOnlyField(el) {
    if (!el || !el.id) return false;
    return el.id === 'py_no';
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

  function syncVoucherIdField() {
    var rec = document.getElementById('py_record_id');
    if (!rec) return;
    rec.value = currentVoucherId > 0 ? String(currentVoucherId) : '';
  }

  function syncVoucherNoDisplay(voucherNo) {
    var rcNo = document.getElementById('py_no');
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

  function refreshVoucherEditState() {
    var locked = currentVoucherId > 0 && !!voucherIsPosted;
    form.classList.toggle('fin-py-form-is-posted', locked);
    var wrap = getRcWrap();
    if (wrap) wrap.classList.toggle('fin-py-form-is-posted', locked);

    var fields = form.querySelectorAll(
      '#py_date, #py_customer, #py_supplier, #py_employee, #py_account_target, #py_employee_offset, #py_cash_account_id, #py_amount, #py_check_amount, #py_check_no, #py_bank_name, #py_notes, #py_pay_cash, #py_pay_check, input[name="party_type_ui"], #py_employee_open, #py_account_target_open'
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
    syncEmployeeAmountLock();
  }

  function updateVoucherNoPostedStyle() {
    var rcNo = document.getElementById('py_no');
    if (!rcNo) return;
    rcNo.classList.remove('is-posted', 'is-unposted');
    if (currentVoucherId < 1) return;
    if (voucherIsPosted) rcNo.classList.add('is-posted');
    else rcNo.classList.add('is-unposted');
  }

  function updateToolbarPostUnpost() {
    var postBtn = document.querySelector('#master-toolbar [data-master-action="post"]');
    var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
    var canPost = currentVoucherId > 0 && !voucherIsPosted;
    var canUnpost = currentVoucherId > 0 && voucherIsPosted;
    if (postBtn) {
      postBtn.disabled = !canPost;
      postBtn.title = canPost ? 'ترحيل السند' : 'احفظ السند أولاً أو السند مرحّل مسبقاً';
    }
    if (unpostBtn) {
      unpostBtn.disabled = !canUnpost;
      unpostBtn.title = canUnpost
        ? 'إلغاء الترحيل (يزيل أثر السند من الكشف والقيد)'
        : 'لا يوجد ترحيل لإلغائه';
    }
  }

  function updatePostedBadge() {
    var el = document.getElementById('py_posted_badge');
    if (currentVoucherId < 1) {
      if (el) el.hidden = true;
      updateVoucherNoPostedStyle();
      updateToolbarPostUnpost();
      return;
    }
    if (el) {
      el.hidden = false;
      if (voucherIsPosted) {
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

  function setBrowseNav(prevId, nextId) {
    browseNavPrevId = prevId > 0 ? prevId : 0;
    browseNavNextId = nextId > 0 ? nextId : 0;
    updateNavButtons(browseNavPrevId, browseNavNextId);
  }

  function updateNavButtons(prevId, nextId) {
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('py_no_prev', 'py_no_next', prevId, nextId, {
        onEmpty: currentVoucherId < 1,
        prevTitle: 'السند السابق',
        nextTitle: 'السند التالي',
        prevBeforeLatestTitle: 'السند قبل الأخير',
        latestTitle: 'آخر سند صرف',
      });
      return;
    }
    var prevBtn = document.getElementById('py_no_prev');
    var nextBtn = document.getElementById('py_no_next');
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
      emptyMessage: 'لا توجد سندات صرف محفوظة بعد.',
      loadLatestError: 'تعذر تحميل آخر سند صرف.',
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
    var cust = document.getElementById('py_customer');
    if (cust && cust.value !== '') return true;
    var supp = document.getElementById('py_supplier');
    if (supp && supp.value !== '') return true;
    var emp = document.getElementById('py_employee');
    if (emp && emp.value !== '') return true;
    var accTarget = document.getElementById('py_account_target');
    if (accTarget && accTarget.value !== '') return true;
    var empOff = document.getElementById('py_employee_offset');
    if (empOff && empOff.value !== '') return true;
    var amt = document.getElementById('py_amount');
    if (amt && String(amt.value).trim() !== '') return true;
    var chkAmt = document.getElementById('py_check_amount');
    if (chkAmt && String(chkAmt.value).trim() !== '') return true;
    var notes = document.getElementById('py_notes');
    if (notes && String(notes.value).trim() !== '') return true;
    return false;
  }

  function validateBeforeSave() {
    var partyType = getPartyType();
    var cashSel = document.getElementById('py_cash_account_id');
    if (!cashSel || !cashSel.value) {
      if (global.AppDialog) AppDialog.alert('اختر حساب الصرف (الصندوق/البنك).', { type: 'warning' });
      else alert('اختر حساب الصرف (الصندوق/البنك).');
      if (cashSel) cashSel.focus();
      return false;
    }

    if (partyType === 'supplier') {
      var supp = document.getElementById('py_supplier');
      if (!supp || !supp.value) {
        if (global.AppDialog) AppDialog.alert('اختر المورد قبل الحفظ.', { type: 'warning' });
        else alert('اختر المورد قبل الحفظ.');
        var suppOpen = document.getElementById('py_supplier_open');
        if (suppOpen) suppOpen.focus();
        return false;
      }
    } else if (partyType === 'customer') {
      var cust = document.getElementById('py_customer');
      if (!cust || !cust.value) {
        if (global.AppDialog) AppDialog.alert('اختر العميل قبل الحفظ.', { type: 'warning' });
        else alert('اختر العميل قبل الحفظ.');
        if (cust) {
          var openBtn = document.getElementById('py_customer_open');
          if (openBtn) openBtn.focus();
        }
        return false;
      }
    } else if (partyType === 'employee') {
      var employee = document.getElementById('py_employee');
      if (!employee || !employee.value) {
        if (global.AppDialog) AppDialog.alert('اختر الموظف قبل الحفظ.', { type: 'warning' });
        else alert('اختر الموظف قبل الحفظ.');
        var empOpen = document.getElementById('py_employee_open');
        if (empOpen) empOpen.focus();
        return false;
      }
      syncOffsetAccountField();
      syncHrAdvanceHidden();
      if (getEmployeePayKind() === 'advance') {
        if (selectedHrAdvanceId < 1) {
          if (global.AppDialog) {
            AppDialog.alert('اختر السلفة المعتمدة للصرف من القائمة.', { type: 'warning' });
          } else {
            alert('اختر السلفة المعتمدة للصرف.');
          }
          return false;
        }
        if (advancePayableAccountId < 1) {
          if (global.AppDialog) {
            AppDialog.alert('حساب «سلف موظفين مستحقة الصرف» غير مربوط.', { type: 'warning' });
          }
          return false;
        }
      } else {
        var empOffset = document.getElementById('py_employee_offset');
        if (!empOffset || !empOffset.value) {
          if (global.AppDialog) {
            AppDialog.alert('اختر حساب الالتزام (رواتب مستحقة / ضمان…).', { type: 'warning' });
          } else {
            alert('اختر حساب الالتزام.');
          }
          if (empOffset) empOffset.focus();
          return false;
        }
        if (isSalariesPayableOffsetSelected() && selectedHrSalaryId < 1) {
          if (global.AppDialog) {
            AppDialog.alert('اختر الراتب المرحّل للصرف من القائمة.', { type: 'warning' });
          } else {
            alert('اختر الراتب المرحّل للصرف.');
          }
          return false;
        }
      }
      syncEmployeeAmountLock();
    } else if (partyType === 'account') {
      syncOffsetAccountField();
      var accTarget = document.getElementById('py_account_target');
      if (!accTarget || !accTarget.value) {
        if (global.AppDialog) AppDialog.alert('اختر الحساب المُصروف إليه.', { type: 'warning' });
        else alert('اختر الحساب المُصروف إليه.');
        var accOpen = document.getElementById('py_account_target_open');
        if (accOpen) accOpen.focus();
        return false;
      }
    }
    var rcDate = document.getElementById('py_date');
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
      var chkAmt = document.getElementById('py_check_amount');
      if (parseNum(chkAmt ? chkAmt.value : 0) <= 0) {
        if (global.AppDialog) AppDialog.alert('أدخل قيمة الشيك.', { type: 'warning' });
        else alert('أدخل قيمة الشيك.');
        if (chkAmt) chkAmt.focus();
        return false;
      }
    } else {
      var amountEl = document.getElementById('py_amount');
      if (parseNum(amountEl ? amountEl.value : 0) <= 0) {
        if (global.AppDialog) AppDialog.alert('أدخل المبلغ.', { type: 'warning' });
        else alert('أدخل المبلغ.');
        if (amountEl) amountEl.focus();
        return false;
      }
    }
    return true;
  }

  function setSaveBusy(busy) {
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
        if (onDone) {
          onDone();
        } else if (global.AppDialog) {
          AppDialog.success('تم حفظ سند الصرف بنجاح.');
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

  /** عند الشيك: قيمة الشيك هي المبلغ المحفوظ في الحقل amount */
  function syncAmountForSubmit() {
    var amountEl = document.getElementById('py_amount');
    if (!amountEl) return;
    if (getPayMethod() === 'check') {
      var chkAmt = document.getElementById('py_check_amount');
      var val = parseNum(chkAmt ? chkAmt.value : 0);
      if (val > 0) {
        amountEl.value = String(val);
      }
    }
  }

  function submitPaymentForm() {
    formSubmitting = true;
    syncVoucherIdField();
    syncOffsetAccountField();
    syncHrAdvanceHidden();
    syncAmountForSubmit();
    setSaveBusy(true);

    var actionUrl = form.getAttribute('action') || window.location.href;
    var fd = new FormData(form);
    fd.set('_action', 'save_payment');
    fd.set('party_type', getPartyType());
    if (currentVoucherId < 1) {
      var noInput = document.getElementById('py_no');
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
        console.error('fin-payment save', err);
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
    if (voucherIsPosted) {
      if (global.AppDialog) AppDialog.alert('لا يمكن تعديل سند مرحّل.', { type: 'warning' });
      return;
    }
    if (!validateBeforeSave()) return;
    pendingAfterSave = typeof onSuccess === 'function' ? onSuccess : null;
    try {
      submitPaymentForm();
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
      voucherIsPosted = !!v.is_posted;
      syncVoucherIdField();
      syncVoucherNoDisplay(v.voucher_no || '');

      var rcDate = document.getElementById('py_date');
      if (rcDate) {
        rcDate.value = fmtDate(v.voucher_date_dmy || v.voucher_date || '') || defaultDate;
      }

      setPartyType(v.party_type || 'supplier');

      if (pyCustomerPickerApi) {
        pyCustomerPickerApi.setById(v.customer_id || 0, true);
      } else {
        var custSel = document.getElementById('py_customer');
        if (custSel) custSel.value = String(v.customer_id || '');
      }

      if (pySupplierPickerApi) {
        pySupplierPickerApi.setById(v.supplier_id || 0, true);
      } else {
        var suppSel = document.getElementById('py_supplier');
        if (suppSel) suppSel.value = String(v.supplier_id || '');
      }

      if (pyEmployeePickerApi) {
        pyEmployeePickerApi.setById(v.employee_id || 0, true);
      } else {
        var empSel = document.getElementById('py_employee');
        if (empSel) empSel.value = String(v.employee_id || '');
      }

      var empOffset = document.getElementById('py_employee_offset');
      if (empOffset) {
        empOffset.value =
          v.party_type === 'employee' &&
          (v.employee_pay_kind || '') === 'other' &&
          v.offset_account_id > 0
            ? String(v.offset_account_id)
            : '';
      }

      setEmployeePayKind(
        v.employee_pay_kind === 'other' || (!v.hr_advance_id && v.offset_account_id > 0 && v.party_type === 'employee')
          ? 'other'
          : 'advance'
      );
      selectedHrAdvanceId =
        v.party_type === 'employee' && v.hr_advance_id > 0 ? parseInt(v.hr_advance_id, 10) || 0 : 0;
      selectedHrSalaryId =
        v.party_type === 'employee' && v.hr_salary_id > 0 ? parseInt(v.hr_salary_id, 10) || 0 : 0;
      syncHrAdvanceHidden();
      syncHrSalaryHidden();

      var empIdForAdv = v.party_type === 'employee' ? parseInt(v.employee_id, 10) || 0 : 0;
      if (empIdForAdv > 0) {
        loadEmployeeHrData(empIdForAdv).then(function () {
          if (selectedHrAdvanceId > 0) applySelectedAdvance(selectedHrAdvanceId);
          if (selectedHrSalaryId > 0) applySelectedSalary(selectedHrSalaryId);
          if (selectedHrAdvanceId > 0) {
            employeeLockedAmount =
              v.hr_advance_amount > 0 ? parseNum(v.hr_advance_amount) : employeeLockedAmount;
          } else if (selectedHrSalaryId > 0) {
            employeeLockedAmount =
              v.hr_salary_amount > 0 ? parseNum(v.hr_salary_amount) : employeeLockedAmount;
          }
          syncEmployeeAmountLock();
        });
      } else {
        clearEmployeeAdvances();
        clearEmployeeSalaries();
      }

      if (pyAccountPickerApi && global.AccountPickerModal) {
        AccountPickerModal.setById(
          pyAccountPickerApi,
          v.party_type === 'account' && v.offset_account_id > 0 ? v.offset_account_id : 0,
          true
        );
      } else {
        var accTarget = document.getElementById('py_account_target');
        if (accTarget) {
          accTarget.value =
            v.party_type === 'account' && v.offset_account_id > 0
              ? String(v.offset_account_id)
              : '';
        }
      }
      syncOffsetAccountField();

      applyCustomerRep(v.sales_rep_name || '');

      var payMethod = v.pay_method === 'check' ? 'check' : 'cash';
      setPayMethod(payMethod);

      var amountEl = document.getElementById('py_amount');
      var chkAmt = document.getElementById('py_check_amount');
      var amt = v.amount > 0 ? v.amount : 0;
      var chkVal = v.check_amount > 0 ? v.check_amount : 0;
      if (payMethod === 'check') {
        if (chkAmt) chkAmt.value = (chkVal > 0 ? chkVal : amt) > 0 ? fmtMoney(chkVal > 0 ? chkVal : amt) : '';
        if (amountEl) amountEl.value = '';
      } else {
        if (amountEl) amountEl.value = amt > 0 ? fmtMoney(amt) : '';
        if (chkAmt) chkAmt.value = '';
      }

      var chkNo = document.getElementById('py_check_no');
      if (chkNo) chkNo.value = v.check_no || '';

      var bank = document.getElementById('py_bank_name');
      if (bank) bank.value = v.bank_name || '';

      var notes = document.getElementById('py_notes');
      if (notes) notes.value = v.notes || '';

      var cashAcc = document.getElementById('py_cash_account_id');
      if (cashAcc && v.cash_account_id > 0) cashAcc.value = String(v.cash_account_id);

      syncPayMethodUi();
      refreshVoucherEditState();
      updatePostedBadge();
      setBrowseNav(v.prev_id || 0, v.next_id || 0);
      updateHistory(currentVoucherId);
    });
  }

  function updateHistory(id) {
    if (!window.history || !window.history.replaceState) return;
    var base = newUrl || window.location.pathname + '?r=cash_payment';
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
            AppDialog.error((data && data.message) || 'لم يتم العثور على سند بهذا الرقم.');
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
    var rcNoEl = document.getElementById('py_no');
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

  function confirmUnsavedChanges(onProceed) {
    if (!formDirty || voucherIsPosted) {
      if (onProceed) onProceed();
      return;
    }
    if (!global.AppDialog || typeof AppDialog.confirm !== 'function') {
      if (onProceed) onProceed();
      return;
    }
    AppDialog.confirm('هل تريد حفظ التغييرات؟', {
      title: 'تغييرات غير محفوظة',
      okText: 'نعم، احفظ',
      cancelText: 'لا، اخرج بدون حفظ',
    }).then(function (saveFirst) {
      if (saveFirst) {
        trySave(function () {
          if (onProceed) onProceed();
        });
      } else {
        clearFormDirty();
        if (onProceed) onProceed();
      }
    });
  }

  function unpostCurrent(onDone) {
    if (!voucherUnpostUrl) {
      if (global.AppDialog) AppDialog.alert('إلغاء الترحيل غير متاح.', { type: 'warning' });
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
    var rcNoEl = document.getElementById('py_no');
    var rcLabel = rcNoEl && rcNoEl.value ? rcNoEl.value : String(currentVoucherId);
    var confirmMsg =
      'إلغاء ترحيل السند «' +
      rcLabel +
      '»؟\n' +
      'يُزال أثره من كشف المورد/العميل والقيد المحاسبي.\n' +
      'بعدها يمكنك تعديل المبلغ أو حذف السند وإنشاء سند جديد.';
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
            if (global.AppDialog) AppDialog.error(data.message || data.error || 'تعذر إلغاء الترحيل.');
            return;
          }
          voucherIsPosted = false;
          updatePostedBadge();
          refreshVoucherEditState();
          loadVoucherById(currentVoucherId, true);
          if (onDone) {
            onDone();
          } else if (global.AppDialog) {
            AppDialog.success(data.message || 'تم إلغاء الترحيل.');
          }
        })
        .catch(function () {
          if (global.AppDialog) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    };
    if (global.AppDialog) {
      AppDialog.confirm(confirmMsg, { title: 'إلغاء ترحيل السند', danger: true, okText: 'إلغاء الترحيل' }).then(
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
        if (onDone) {
          onDone();
        } else if (global.AppDialog) {
          AppDialog.success(data.message || 'تم حذف السند.').then(function () {
            if (newUrl) window.location.href = newUrl;
            else initNewPayment();
          });
        } else if (newUrl) {
          window.location.href = newUrl;
        } else {
          initNewPayment();
        }
      })
      .catch(function () {
        if (global.AppDialog) AppDialog.error('تعذر الاتصال بالخادم.');
      });
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
    if (voucherIsPosted) {
      if (global.AppDialog) AppDialog.alert('هذا السند مرحّل مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    if (global.AppDialog) {
      AppDialog.confirm(
        'ترحيل سند الصرف؟\nيُسجَّل القيد على الطرف وعلى الحساب: ' + (getCashAccountLabel() || '—') + '.',
        {
        title: 'ترحيل السند',
      }).then(function (ok) {
        if (!ok) return;
        doPostVoucher(csrfInput);
      });
    } else if (confirm('ترحيل سند الصرف؟')) {
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

  function resetPaymentForm() {
    runWithoutDirtyMark(function () {
      currentVoucherId = 0;
      voucherIsPosted = false;
      syncVoucherIdField();
      syncVoucherNoDisplay('');

      var rcDate = document.getElementById('py_date');
      if (rcDate && defaultDate) rcDate.value = defaultDate;

      var cust = document.getElementById('py_customer');
      if (cust) cust.value = '';
      var supp = document.getElementById('py_supplier');
      if (supp) supp.value = '';
      var emp = document.getElementById('py_employee');
      if (emp) emp.value = '';
      var empOffset = document.getElementById('py_employee_offset');
      if (empOffset) empOffset.value = '';
      if (pyCustomerPickerApi) pyCustomerPickerApi.setById(0, true);
      if (pySupplierPickerApi) pySupplierPickerApi.setById(0, true);
      if (pyEmployeePickerApi) pyEmployeePickerApi.setById(0, true);
      if (pyAccountPickerApi && global.AccountPickerModal) {
        AccountPickerModal.setById(pyAccountPickerApi, 0, true);
      } else {
        var accTarget = document.getElementById('py_account_target');
        if (accTarget) accTarget.value = '';
      }
      clearEmployeeAdvances();
      clearEmployeeSalaries();
      setEmployeePayKind('advance');
      syncHrAdvanceHidden();
      syncHrSalaryHidden();
      employeeLockedAmount = null;
      syncOffsetAccountField();

      setPartyType('supplier');
      applyCustomerRep('');

      setPayMethod('cash');

      var amountEl = document.getElementById('py_amount');
      if (amountEl) amountEl.value = '';

      var chkAmt = document.getElementById('py_check_amount');
      if (chkAmt) chkAmt.value = '';

      var chkNo = document.getElementById('py_check_no');
      if (chkNo) chkNo.value = '';

      var bank = document.getElementById('py_bank_name');
      if (bank) bank.value = '';

      var notes = document.getElementById('py_notes');
      if (notes) notes.value = '';

      syncPayMethodUi();
      refreshVoucherEditState();
      updatePostedBadge();
      refreshEmptyBrowseNav();
      updateHistory(0);
    });
  }

  function initNewPayment() {
    resetPaymentForm();
    var focusEl = null;
    var pt = getPartyType();
    if (pt === 'supplier') focusEl = document.getElementById('py_supplier_open');
    else if (pt === 'customer') focusEl = document.getElementById('py_customer_open');
    else if (pt === 'employee') focusEl = document.getElementById('py_employee_open');
    else if (pt === 'account') focusEl = document.getElementById('py_account_target_open');
    if (focusEl) {
      setTimeout(function () {
        focusEl.focus();
      }, 80);
    }
  }

  function getEmployeeLabel() {
    if (pyEmployeePickerApi && pyEmployeePickerApi.getLabel) {
      return pyEmployeePickerApi.getLabel();
    }
    return global.EmployeePickerModal
      ? EmployeePickerModal.getLabel('py_employee')
      : '';
  }

  function getAccountTargetLabel() {
    var display = document.getElementById('py_account_target_display');
    if (display && !display.classList.contains('is-placeholder')) {
      return display.textContent.trim();
    }
    return '';
  }

  function getPartyTypeLabel() {
    var pt = getPartyType();
    if (pt === 'customer') return 'صرف لعميل';
    if (pt === 'employee') return 'صرف لموظف';
    if (pt === 'account') return 'صرف لحساب';
    return 'صرف لمورد';
  }

  function getPartyLabel() {
    var pt = getPartyType();
    if (pt === 'supplier') {
      if (pySupplierPickerApi && pySupplierPickerApi.getName) return pySupplierPickerApi.getName();
      return global.SupplierPickerModal
        ? SupplierPickerModal.getLabel('py_supplier')
        : '';
    }
    if (pt === 'employee') return getEmployeeLabel();
    if (pt === 'account') return getAccountTargetLabel();
    return getCustomerLabel();
  }

  function getCustomerLabel() {
    if (pyCustomerPickerApi) return pyCustomerPickerApi.getName();
    return global.CustomerPickerModal ? CustomerPickerModal.getLabel('py_customer') : '';
  }

  function getDisplayAmount() {
    if (getPayMethod() === 'check') {
      var chkAmt = document.getElementById('py_check_amount');
      return fmtMoney(parseNum(chkAmt ? chkAmt.value : 0));
    }
    var amountEl = document.getElementById('py_amount');
    return fmtMoney(parseNum(amountEl ? amountEl.value : 0));
  }

  function buildPaymentPrintInnerHtml() {
    var dateEl = document.getElementById('py_date');
    var date = fmtDate(dateEl ? dateEl.value : '');
    var rcNoEl = document.getElementById('py_no');
    var rcNo = rcNoEl && rcNoEl.value ? rcNoEl.value : '—';
    var partyType = getPartyType();
    var partyFieldLabel =
      partyType === 'supplier'
        ? 'المورد'
        : partyType === 'customer'
          ? 'العميل'
          : partyType === 'employee'
            ? 'الموظف'
            : 'الحساب';
    var party = getPartyLabel();
    var repEl = document.getElementById('py_sales_rep');
    var rep = repEl ? String(repEl.value).trim() : '';
    var payLabel = getPayMethod() === 'check' ? 'شيك' : 'نقداً';
    var amount = getDisplayAmount();
    var notes = document.getElementById('py_notes');
    var notesVal = notes ? String(notes.value).trim() : '';
    var notesBlock = notesVal
      ? '<p style="margin:0.75rem 0 0;font-size:0.88rem;"><strong>ملاحظات:</strong> ' +
        escapeHtml(notesVal) +
        '</p>'
      : '';

    var checkRows = '';
    if (getPayMethod() === 'check') {
      var chkNo = document.getElementById('py_check_no');
      var bank = document.getElementById('py_bank_name');
      checkRows =
        '<tr><td style="padding:0.4rem;border:1px solid #cbd5e1;"><strong>رقم الشيك</strong></td><td style="padding:0.4rem;border:1px solid #cbd5e1;">' +
        escapeHtml(chkNo ? chkNo.value : '—') +
        '</td></tr>' +
        '<tr><td style="padding:0.4rem;border:1px solid #cbd5e1;"><strong>البنك</strong></td><td style="padding:0.4rem;border:1px solid #cbd5e1;">' +
        escapeHtml(bank ? bank.value : '—') +
        '</td></tr>';
    }

    var metaRows = [
      { label: 'رقم السند', value: rcNo },
      { label: 'التاريخ', value: date },
      { label: 'نوع السند', value: getPartyTypeLabel() },
      { label: partyFieldLabel, value: party },
    ];
    if (partyType === 'customer') {
      metaRows.push({ label: 'المندوب', value: rep || '—' });
    }
    if (partyType === 'employee') {
      var empOff = document.getElementById('py_employee_offset');
      var offLabel =
        empOff && empOff.selectedIndex >= 0
          ? empOff.options[empOff.selectedIndex].textContent.trim()
          : '—';
      metaRows.push({ label: 'يُصرف إلى حساب', value: offLabel });
    }
    metaRows.push(
      { label: 'يُخصم من', value: getCashAccountLabel() || '—' },
      { label: 'طريقة الدفع', value: payLabel },
      { label: getPayMethod() === 'check' ? 'قيمة الشيك' : 'المبلغ', value: amount }
    );
    var metaTable =
      window.DocumentHeader && typeof window.DocumentHeader.buildMetaTable === 'function'
        ? window.DocumentHeader.buildMetaTable(metaRows)
        : '<table><tr><td><strong>رقم السند:</strong> ' + escapeHtml(rcNo) + '</td></tr></table>';

    var inner =
      buildDocPrintHeader('سند صرف') +
      metaTable +
      (checkRows
        ? '<table style="margin-top:0.5rem;width:100%;border-collapse:collapse;">' + checkRows + '</table>'
        : '') +
      notesBlock +
      buildRecipientSignatureBlock();
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
      'th{background:#f1f5f9;padding:0.45rem;border:1px solid #94a3b8;font-size:12px;font-weight:700;}' +
      'td{padding:0.4rem;border:1px solid #cbd5e1;font-weight:700;}' +
      '.doc-print-meta{text-align:start;direction:rtl;}.doc-print-meta table{width:100%;border-collapse:collapse;}' +
      '.doc-print-meta td{border:none!important;padding:0.2rem 0!important;text-align:start!important;}' +
      '.doc-print-meta-value--party{font-weight:800;font-size:1.12em;color:#0f172a;}'
    );
  }

  function buildStandaloneReceiptHtml() {
    var bodyAttrs =
      window.DocumentHeader && window.DocumentHeader.bodyPrintAttrs
        ? window.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>سند صرف</title>' +
      '<style>' +
      getPrintFrameStyles() +
      '</style></head><body' +
      bodyAttrs +
      '>' +
      buildPaymentPrintInnerHtml() +
      '</body></html>'
    );
  }

  function getPrintFrame() {
    var frame = document.getElementById('fin-py-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'fin-py-print-frame';
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
    if (action === 'unpost') {
      e.preventDefault();
      e.stopImmediatePropagation();
      unpostCurrent();
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
        var rcNoEl = document.getElementById('py_no');
        var rcLabel = rcNoEl && rcNoEl.value ? rcNoEl.value : String(vId);
        if (!voucherDeleteUrl) {
          if (global.AppDialog) AppDialog.error('حذف السند غير متاح.');
          return;
        }
        var deleteMsg = voucherIsPosted
          ? 'السند «' +
            rcLabel +
            '» مرحّل.\n' +
            'سيتم أولاً إلغاء الترحيل (إزالة الكشف والقيد) ثم حذف السند نهائياً.\n' +
            'هل تريد المتابعة؟'
          : 'حذف السند «' + rcLabel + '» نهائياً؟\nلا يمكن التراجع عن هذا الإجراء.';
        if (global.AppDialog) {
          AppDialog.confirm(deleteMsg, {
            title: voucherIsPosted ? 'إلغاء الترحيل وحذف السند' : 'حذف السند',
            danger: true,
            okText: 'حذف',
          }).then(function (ok) {
            if (!ok) return;
            if (voucherIsPosted) {
              unpostCurrent(function () {
                deleteVoucherById(vId, rcLabel);
              });
            } else {
              deleteVoucherById(vId, rcLabel);
            }
          });
        }
        return;
      }
      if (!hasDraftContent()) {
        resetPaymentForm();
        return;
      }
      if (global.AppDialog) {
        AppDialog.confirm('مسح بيانات السند الحالي؟', {
          title: 'مسح السند',
          danger: true,
          okText: 'مسح',
        }).then(function (ok) {
          if (ok) resetPaymentForm();
        });
      } else if (confirm('مسح بيانات السند؟')) {
        resetPaymentForm();
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
        else initNewPayment();
      });
    });
  }

  var prevBtn = document.getElementById('py_no_prev');
  var nextBtn = document.getElementById('py_no_next');
  var rcNoInput = document.getElementById('py_no');
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
      if (!no) return;
      if (no.indexOf('-') > 0) loadVoucherByNo(no);
    });
  }

  var payRow = form.querySelector('.fin-py-pay-row');
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

  form.addEventListener('input', markFormDirtyFromEvent);
  form.addEventListener('change', markFormDirtyFromEvent);
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (voucherIsPosted) return;
    trySave();
  });

  function readDisburseBootstrap() {
    var el = document.getElementById('fin-py-disburse-bootstrap-json');
    if (!el || !el.textContent) return null;
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  function applyDisburseAdvanceBootstrap() {
    var bootstrap = readDisburseBootstrap();
    if (!bootstrap || !(parseInt(bootstrap.advance_id, 10) > 0)) {
      return Promise.resolve();
    }
    var employeeId = parseInt(bootstrap.employee_id, 10) || 0;
    var advanceId = parseInt(bootstrap.advance_id, 10) || 0;
    runWithoutDirtyMark(function () {
      setPartyType('employee');
      setEmployeePayKind('advance');
    });
    if (pyEmployeePickerApi) {
      pyEmployeePickerApi.setById(employeeId, true);
    } else {
      var empSel = document.getElementById('py_employee');
      if (empSel) empSel.value = String(employeeId);
    }
    return loadEmployeeAdvances(employeeId).then(function () {
      if (parseInt(bootstrap.payable_account_id, 10) > 0) {
        advancePayableAccountId = parseInt(bootstrap.payable_account_id, 10) || 0;
      }
      var disp = document.getElementById('py_advance_payable_display');
      if (disp && bootstrap.payable_account_label) {
        disp.value = bootstrap.payable_account_label;
      }
      if (advanceId > 0) {
        applySelectedAdvance(advanceId);
      }
      syncOffsetAccountField();
      syncEmployeeAmountLock();
    });
  }

  function bootReceiptPage() {
    form.querySelectorAll('input[name="party_type_ui"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        setPartyType(radio.value);
        markFormDirty();
      });
    });
    form.querySelectorAll('input[name="employee_pay_kind_ui"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        setEmployeePayKind(radio.value);
        if (radio.value === 'advance') {
          selectedHrSalaryId = 0;
          syncHrSalaryHidden();
          employeeLockedAmount = null;
          renderEmployeeAdvancesList();
        } else {
          selectedHrAdvanceId = 0;
          syncHrAdvanceHidden();
          employeeLockedAmount = null;
          renderEmployeeSalariesList();
        }
        syncEmployeeSalaryPanel();
        syncEmployeeAmountLock();
        markFormDirty();
      });
    });
    syncPartyTypeUi();
    syncEmployeePayKindUi();

    if (global.CustomerPickerModal) {
      pyCustomerPickerApi = CustomerPickerModal.bind({
        hidden: 'py_customer',
        open: 'py_customer_open',
        display: 'py_customer_display',
        jsonId: 'fin-py-customers-json',
        getDisabled: function () {
          return voucherIsPosted;
        },
        onSelect: function (c) {
          applyCustomerRep(c ? c.sales_rep_name : '');
          markFormDirty();
        },
      });
    }
    if (global.SupplierPickerModal) {
      pySupplierPickerApi = SupplierPickerModal.bind({
        hidden: 'py_supplier',
        open: 'py_supplier_open',
        display: 'py_supplier_display',
        jsonId: 'fin-py-suppliers-json',
        getDisabled: function () {
          return voucherIsPosted;
        },
        onSelect: function () {
          markFormDirty();
        },
      });
    }
    if (global.EmployeePickerModal) {
      pyEmployeePickerApi = EmployeePickerModal.bind({
        hidden: 'py_employee',
        open: 'py_employee_open',
        display: 'py_employee_display',
        jsonId: 'fin-py-employees-json',
        getDisabled: function () {
          return voucherIsPosted;
        },
        onSelect: function (emp) {
          var eid = emp && emp.id ? parseInt(emp.id, 10) || 0 : 0;
          if (eid > 0) {
            loadEmployeeHrData(eid);
          } else {
            clearEmployeeAdvances();
            clearEmployeeSalaries();
            employeeLockedAmount = null;
            syncEmployeeAmountLock();
          }
          markFormDirty();
        },
      });
    }
    if (global.AccountPickerModal) {
      pyAccountPickerApi = AccountPickerModal.bind({
        hidden: 'py_account_target',
        open: 'py_account_target_open',
        display: 'py_account_target_display',
        jsonId: 'fin-py-offset-accounts-json',
        placeholder: 'اضغط لاختيار حساب (خصوم / مصروف)',
        onSelect: function () {
          syncOffsetAccountField();
          markFormDirty();
        },
      });
    }
    var empOffsetSel = document.getElementById('py_employee_offset');
    if (empOffsetSel) {
      empOffsetSel.addEventListener('change', function () {
        syncOffsetAccountField();
        syncEmployeeSalaryPanel();
        if (!isSalariesPayableOffsetSelected()) {
          selectedHrSalaryId = 0;
          syncHrSalaryHidden();
          employeeLockedAmount = null;
        } else if (pendingEmployeeSalaries.length === 1 && selectedHrSalaryId < 1) {
          applySelectedSalary(parseInt(pendingEmployeeSalaries[0].id, 10) || 0);
        }
        syncEmployeeAmountLock();
        markFormDirty();
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
    applyDisburseAdvanceBootstrap().finally(function () {
      runWithoutDirtyMark(function () {
        refreshVoucherEditState();
        updatePostedBadge();
        refreshEmptyBrowseNav();
        applyCustomerRep();
        updateToolbarPostUnpost();
        var focusEl = null;
        var pt = getPartyType();
        if (pt === 'supplier') focusEl = document.getElementById('py_supplier_open');
        else if (pt === 'customer') focusEl = document.getElementById('py_customer_open');
        else if (pt === 'employee') focusEl = document.getElementById('py_employee_open');
        else if (pt === 'account') focusEl = document.getElementById('py_account_target_open');
        if (focusEl) {
          setTimeout(function () {
            focusEl.focus();
          }, 80);
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootReceiptPage);
  } else {
    bootReceiptPage();
  }

  window.addEventListener('beforeunload', function (e) {
    if (formSubmitting || !formDirty || voucherIsPosted) return;
    e.preventDefault();
    e.returnValue = '';
  });
})();
