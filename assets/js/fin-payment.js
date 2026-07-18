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
  var voucherCancelUrl = form.getAttribute('data-voucher-cancel-url') || '';
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
    noInputId: 'py_no',
    prevBtnId: 'py_no_prev',
    nextBtnId: 'py_no_next',
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

  /** للمبالغ داخل حقول الإدخال — بدون فواصل آلاف حتى لا يفسدها PHP عند الحفظ. */
  function fmtMoneyInput(n) {
    if (global.AppFormat && AppFormat.formatFixedDecimalPlain) {
      return AppFormat.formatFixedDecimalPlain(n);
    }
    var x = Number(n);
    if (!isFinite(x)) return '0.00';
    var d =
      global.AppFormat && typeof AppFormat.decimals === 'function' ? AppFormat.decimals() : 2;
    return x.toFixed(d);
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
    var bank = document.getElementById('py_pay_bank');
    if (bank && bank.checked) return 'bank';
    var check = document.getElementById('py_pay_check');
    if (check && check.checked) return 'check';
    return 'cash';
  }

  function setPayMethod(method) {
    var isCheck = method === 'check';
    var isBank = method === 'bank';
    var cash = document.getElementById('py_pay_cash');
    var bank = document.getElementById('py_pay_bank');
    var chk = document.getElementById('py_pay_check');
    if (cash) cash.checked = !isCheck && !isBank;
    if (bank) bank.checked = isBank;
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

  function allowedCashGroupsForPayMethod(method) {
    if (method === 'check') return ['bank'];
    if (method === 'bank') return ['bank'];
    return ['cash'];
  }

  function syncBankNameFromCashAccount() {
    var inp = document.getElementById('py_bank_name');
    var sel = document.getElementById('py_cash_account_id');
    if (!inp) return;
    if (getPayMethod() !== 'check') {
      return;
    }
    if (!sel || !sel.value || sel.selectedIndex < 0) {
      inp.value = '';
      return;
    }
    var opt = sel.options[sel.selectedIndex];
    var label = opt ? String(opt.textContent || '').trim() : '';
    // من "1001003001 — البنك العربي" نأخذ الاسم بعد الشرطة
    var name = label;
    var sep = label.indexOf('—');
    if (sep < 0) sep = label.indexOf('-');
    if (sep >= 0) name = label.slice(sep + 1).trim();
    inp.value = name;
    syncCheckDisbursementNotes();
  }

  function filterCashAccountOptions() {
    var sel = document.getElementById('py_cash_account_id');
    if (!sel || sel.options.length < 1) return;
    var allowed = allowedCashGroupsForPayMethod(getPayMethod());
    var selectedStillVisible = false;
    var current = String(sel.value || '');
    for (var i = 0; i < sel.options.length; i++) {
      var opt = sel.options[i];
      if (!opt.value) continue;
      var group = opt.getAttribute('data-group') || '';
      var show = allowed.indexOf(group) >= 0;
      if (show) {
        opt.disabled = false;
        opt.hidden = false;
        opt.removeAttribute('disabled');
        opt.removeAttribute('hidden');
      } else {
        opt.disabled = true;
        opt.hidden = true;
        opt.setAttribute('disabled', 'disabled');
        opt.setAttribute('hidden', 'hidden');
      }
      if (show && opt.value === current) selectedStillVisible = true;
    }
    // إخفاء مجموعات optgroup الفارغة (optgroup لا يدعم .options في كل المتصفحات)
    var groups = sel.querySelectorAll('optgroup');
    for (var g = 0; g < groups.length; g++) {
      var og = groups[g];
      var anyVisible = false;
      var childOpts = og.querySelectorAll('option');
      for (var j = 0; j < childOpts.length; j++) {
        if (!childOpts[j].disabled && !childOpts[j].hidden) {
          anyVisible = true;
          break;
        }
      }
      og.hidden = !anyVisible;
      og.disabled = !anyVisible;
      if (!anyVisible) {
        og.setAttribute('hidden', 'hidden');
        og.setAttribute('disabled', 'disabled');
      } else {
        og.removeAttribute('hidden');
        og.removeAttribute('disabled');
      }
    }
    if (!selectedStillVisible) {
      suggestCashAccountForPayMethod();
    }
  }

  function updateCashAccountLabel() {
    var label = document.getElementById('py_cash_account_label');
    if (!label) return;
    var method = getPayMethod();
    if (method === 'bank') label.textContent = 'يُخصم من — البنوك *';
    else if (method === 'check') label.textContent = 'يُخصم من — البنوك *';
    else label.textContent = 'يُخصم من — الصناديق *';
  }

  function syncPayMethodUi() {
    var method = getPayMethod();
    var isCheck = method === 'check';
    var isBank = method === 'bank';
    var wrap = getRcWrap();
    wrap.classList.toggle('fin-py-pay-is-check', isCheck);
    wrap.classList.toggle('fin-py-pay-is-cash', method === 'cash');
    wrap.classList.toggle('fin-py-pay-is-bank', isBank);
    form.classList.toggle('fin-py-pay-is-check', isCheck);
    form.classList.toggle('fin-py-pay-is-cash', method === 'cash');
    form.classList.toggle('fin-py-pay-is-bank', isBank);

    var cashWrap = document.getElementById('py_cash_amount_wrap');
    if (cashWrap) {
      cashWrap.hidden = isCheck;
      cashWrap.style.display = isCheck ? 'none' : '';
    }

    ['py_check_fields', 'py_check_no_wrap', 'py_check_due_wrap'].forEach(function (id) {
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
    filterCashAccountOptions();
    updateCashAccountLabel();
    syncEmployeeAmountLock();
    syncBankNameFromCashAccount();
    syncCheckDisbursementNotes();
  }

  /** آخر نص تلقائي لكتابة الصرف (شيك) — لا نستبدل تعديلات المستخدم اليدوية. */
  var lastAutoCheckNotes = '';

  function buildCheckDisbursementNotes() {
    var party = String(getPartyLabel() || '').trim();
    var chkNoEl = document.getElementById('py_check_no');
    var bankEl = document.getElementById('py_bank_name');
    var dueEl = document.getElementById('py_check_due');
    var dateEl = document.getElementById('py_date');
    var checkNo = chkNoEl ? String(chkNoEl.value || '').trim() : '';
    var bankName = bankEl ? String(bankEl.value || '').trim() : '';
    var dateStr = '';
    var dueRaw = dueEl ? String(dueEl.value || '').trim() : '';
    if (dueRaw !== '') {
      dateStr = fmtDate(dueRaw);
      if (dateStr === '—' || dateStr === '') dateStr = dueRaw;
    } else if (dateEl && dateEl.value) {
      dateStr = fmtDate(dateEl.value);
      if (dateStr === '—' || dateStr === '') dateStr = String(dateEl.value).trim();
    }

    var head = party !== '' ? 'دفعة لـ' + party : 'دفعة';
    var parts = [];
    if (checkNo !== '') parts.push('شيك رقم ' + checkNo);
    else parts.push('شيك');
    if (bankName !== '') parts.push('من البنك ' + bankName);
    if (dateStr !== '') parts.push('بتاريخ ' + dateStr);

    return head + ' ( ' + parts.join(' ') + ' )';
  }

  function syncCheckDisbursementNotesLabel(isCheck) {
    var label = form.querySelector('label[for="py_notes"]');
    var notes = document.getElementById('py_notes');
    if (label) label.textContent = isCheck ? 'كتابة الصرف' : 'ملاحظات';
    if (notes) {
      notes.setAttribute('placeholder', isCheck ? 'يُعبَّأ تلقائياً من بيانات الشيك' : 'اختياري');
    }
  }

  function syncCheckDisbursementNotes() {
    var notes = document.getElementById('py_notes');
    if (!notes || voucherIsPosted || voucherIsCancelled) return;

    var isCheck = getPayMethod() === 'check';
    syncCheckDisbursementNotesLabel(isCheck);

    if (!isCheck) {
      if (lastAutoCheckNotes !== '' && String(notes.value).trim() === lastAutoCheckNotes) {
        notes.value = '';
      }
      lastAutoCheckNotes = '';
      return;
    }

    var autoText = buildCheckDisbursementNotes();
    var current = String(notes.value).trim();
    if (current === '' || current === lastAutoCheckNotes) {
      notes.value = autoText;
      lastAutoCheckNotes = autoText;
    }
  }

  function suggestCashAccountForPayMethod() {
    var sel = document.getElementById('py_cash_account_id');
    if (!sel || sel.options.length < 1) return;
    var method = getPayMethod();
    var defaultCash = parseInt(form.getAttribute('data-default-cash-id') || '0', 10);
    var defaultBank = parseInt(form.getAttribute('data-default-bank-id') || '0', 10);
    var defaultChecks = parseInt(form.getAttribute('data-default-checks-id') || '0', 10);
    var preferredIds =
      method === 'check'
        ? [defaultBank]
        : method === 'bank'
          ? [defaultBank]
          : [defaultCash];
    var preferredGroups = allowedCashGroupsForPayMethod(method);
    var preferredCodes =
      method === 'check'
        ? ['112', '1001003001', '1001003004']
        : method === 'bank'
          ? ['112', '1001003001', '1001003004']
          : ['111', '1001002001'];
    for (var d = 0; d < preferredIds.length; d++) {
      if (preferredIds[d] > 0) {
        for (var i = 0; i < sel.options.length; i++) {
          var optId = sel.options[i];
          if (optId.disabled || optId.hidden) continue;
          if (parseInt(optId.value, 10) === preferredIds[d]) {
            sel.value = optId.value;
            return;
          }
        }
      }
    }
    for (var g = 0; g < preferredGroups.length; g++) {
      for (var j = 0; j < sel.options.length; j++) {
        if (sel.options[j].disabled || sel.options[j].hidden) continue;
        if (sel.options[j].getAttribute('data-group') === preferredGroups[g]) {
          sel.value = sel.options[j].value;
          return;
        }
      }
    }
    for (var p = 0; p < preferredCodes.length; p++) {
      for (var k = 0; k < sel.options.length; k++) {
        if (sel.options[k].disabled || sel.options[k].hidden) continue;
        if (sel.options[k].getAttribute('data-code') === preferredCodes[p]) {
          sel.value = sel.options[k].value;
          return;
        }
      }
    }
    for (var x = 0; x < sel.options.length; x++) {
      if (!sel.options[x].disabled && !sel.options[x].hidden && sel.options[x].value) {
        sel.value = sel.options[x].value;
        return;
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
    return false;
  }

  function syncEmployeeAmountLock() {
    var amountEl = document.getElementById('py_amount');
    var chkAmt = document.getElementById('py_check_amount');
    var wrap = getRcWrap();
    if (wrap) wrap.classList.remove('fin-py-amount-locked');

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
    if (type === 'account') {
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
      getPartyType() === 'employee' && selectedHrAdvanceId > 0
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
      syncOffsetAccountField();
      syncEmployeeAmountLock();
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

  function isHrAdvanceDisburseLocked() {
    if (voucherIsPosted) {
      return false;
    }
    return !!readDisburseBootstrap();
  }

  function refreshHrAdvanceDisburseLock() {
    var hrLocked = isHrAdvanceDisburseLocked();
    var wrap = getRcWrap();
    if (wrap) {
      wrap.classList.toggle('fin-py-hr-advance-disburse-lock', hrLocked);
    }
    if (!hrLocked) {
      return;
    }
    form.querySelectorAll('input[name="party_type_ui"]').forEach(function (el) {
      el.disabled = true;
    });
    var empOpen = document.getElementById('py_employee_open');
    if (empOpen) {
      empOpen.disabled = true;
    }
    syncEmployeeAmountLock();
  }

  function voucherCancelledFromPayload(v) {
    if (!v) return false;
    if (v.is_cancelled === true || v.is_cancelled === 1 || v.is_cancelled === '1') return true;
    if (String(v.status || '') === 'cancelled') return true;
    if (String(v.status_label || '') === 'ملغى') return true;
    return false;
  }

  function lockPaymentPickerButtons(locked) {
    form.querySelectorAll(
      '#py_customer_open, #py_supplier_open, #py_employee_open, #py_account_target_open, ' +
        '.sales-inv-cust-open, .js-pick-open, input[name="party_type_ui"], ' +
        '#py_pay_cash, #py_pay_bank, #py_pay_check'
    ).forEach(function (el) {
      if (!el) return;
      el.disabled = !!locked;
    });
  }

  function refreshVoucherEditState() {
    var locked = currentVoucherId > 0 && (!!voucherIsPosted || !!voucherIsCancelled);
    form.classList.toggle('fin-py-form-is-posted', locked);
    var wrap = getRcWrap();
    if (wrap) {
      wrap.classList.toggle('fin-py-form-is-posted', locked);
      wrap.classList.toggle('fin-py-form-is-cancelled', currentVoucherId > 0 && !!voucherIsCancelled);
    }

    var fields = form.querySelectorAll(
      '#py_date, #py_customer, #py_supplier, #py_employee, #py_account_target, #py_cash_account_id, #py_amount, #py_check_amount, #py_check_no, #py_check_due, #py_notes, #py_pay_cash, #py_pay_bank, #py_pay_check, input[name="party_type_ui"], #py_employee_open, #py_account_target_open'
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
    refreshHrAdvanceDisburseLock();
    lockPaymentPickerButtons(locked);
    refreshPyChecksManageUi();
  }

  function getPayMethodValue() {
    return getPayMethod();
  }

  function refreshPyChecksManageUi() {
    var wrap = document.getElementById('py_check_manage_wrap');
    if (!wrap) return;
    var show =
      currentVoucherId > 0 &&
      voucherIsPosted &&
      !voucherIsCancelled &&
      getPayMethodValue() === 'check' &&
      lastChecksManageData.length > 0;
    if (!show) {
      wrap.hidden = true;
      wrap.innerHTML = '';
      return;
    }
    wrap.hidden = false;
    var html =
      '<div class="dashboard-ora-panel" style="margin-top:0.65rem;border:1px solid #b8c5d6;">' +
      '<h3 class="dashboard-ora-panel__title" style="font-size:0.85rem;padding:0.4rem 0.75rem;">متابعة الشيك</h3>' +
      '<div class="dashboard-ora-panel__body" style="padding:0.65rem 0.75rem;background:#fff;">';
    lastChecksManageData.forEach(function (data) {
      var lifecycle = data.lifecycle_status || 'pending';
      html += '<div class="fin-py-check-manage-row" style="margin-bottom:0.5rem;">';
      html +=
        '<strong>' +
        escapeHtml(data.check_no || ('#' + (data.id || ''))) +
        '</strong> — ' +
        escapeHtml(fmtMoney(data.check_amount || 0));
      if (data.action_was_undone) {
        html +=
          ' <span class="badge fin-chk-badge fin-chk-badge--undo">' +
          escapeHtml(data.status_display || data.execute_label || 'تم الإلغاء') +
          '</span>';
        if (data.action_date_dmy) {
          html += ' <small class="muted">(' + escapeHtml(data.action_date_dmy) + ')</small>';
        }
      } else if (lifecycle === 'pending') {
        html += ' <span class="badge badge-warn">قيد</span>';
      } else {
        html +=
          ' <span class="badge badge-ok">' + escapeHtml(data.status_display || lifecycle) + '</span>';
        if (data.action_date_dmy) {
          html += ' <small class="muted">(' + escapeHtml(data.action_date_dmy) + ')</small>';
        }
        if (data.can_undo && data.id) {
          html +=
            ' <button type="button" class="btn btn-secondary btn-sm fin-py-check-undo" data-check-id="' +
            String(data.id) +
            '" data-undo-label="' +
            escapeHtml(data.undo_label || 'إلغاء') +
            '">' +
            escapeHtml(data.undo_label || 'إلغاء') +
            '</button>';
        }
        if (data.journal_url) {
          html +=
            ' <a href="' + escapeHtml(data.journal_url) + '" class="btn btn-link btn-sm">القيد</a>';
        }
      }
      html += '</div>';
    });
    html += '</div></div>';
    wrap.innerHTML = html;
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

  function updateVoucherNoPostedStyle() {
    var rcNo = document.getElementById('py_no');
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
      deleteBtn.title = voucherIsCancelled
        ? 'لا يمكن حذف سند ملغى'
        : voucherIsPosted
          ? 'لا يمكن حذف سند مرحّل — استخدم «تعديل» أو «إلغاء السند»'
          : 'حذف مسودة السند';
    }
    if (global.FinVoucherArchive) {
      global.FinVoucherArchive.syncToolbar();
    }
  }

  function paymentArchiveState(id) {
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

  function updatePostedBadge() {
    var el = document.getElementById('py_posted_badge');
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
      if (global.AppDialog) AppDialog.alert('اختر حساب الصرف (صندوق، شيكات، أو بنك).', { type: 'warning' });
      else alert('اختر حساب الصرف (صندوق، شيكات، أو بنك).');
      if (cashSel) cashSel.focus();
      return false;
    }
    var selectedOpt = cashSel.options[cashSel.selectedIndex];
    if (selectedOpt) {
      var accountGroup = selectedOpt.getAttribute('data-group') || '';
      var payMethod = getPayMethod();
      var allowedGroups = allowedCashGroupsForPayMethod(payMethod);
      if (allowedGroups.indexOf(accountGroup) < 0) {
        var groupMsg =
          payMethod === 'bank' || payMethod === 'check'
            ? 'اختر حساب بنك يُخصم منه المبلغ.'
            : 'اختر حساباً من الصناديق النقدية فقط.';
        if (global.AppDialog) AppDialog.alert(groupMsg, { type: 'warning' });
        else alert(groupMsg);
        cashSel.focus();
        return false;
      }
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

  function setSaveBusy(busy, message) {
    if (global.AppBusy && AppBusy.setSaveBusy) {
      AppBusy.setSaveBusy(busy, message || 'جاري حفظ سند الصرف...');
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
    var chkAmt = document.getElementById('py_check_amount');
    if (getPayMethod() === 'check') {
      var val = parseNum(chkAmt ? chkAmt.value : 0);
      if (val > 0) {
        var plain = fmtMoneyInput(val);
        if (chkAmt) chkAmt.value = plain;
        if (amountEl) amountEl.value = plain;
      }
      return;
    }
    if (amountEl) {
      var cashVal = parseNum(amountEl.value);
      if (cashVal > 0) amountEl.value = fmtMoneyInput(cashVal);
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
      voucherIsPosted = !!v.is_posted && !voucherCancelledFromPayload(v);
      voucherIsCancelled = voucherCancelledFromPayload(v);
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

      selectedHrAdvanceId =
        v.party_type === 'employee' && v.hr_advance_id > 0 ? parseInt(v.hr_advance_id, 10) || 0 : 0;
      selectedHrSalaryId = 0;
      syncHrAdvanceHidden();
      syncHrSalaryHidden();

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

      var payMethod =
        v.pay_method === 'check' ? 'check' : v.pay_method === 'bank' ? 'bank' : 'cash';
      setPayMethod(payMethod);
      lastChecksManageData = Array.isArray(v.checks) ? v.checks.slice() : [];

      var amountEl = document.getElementById('py_amount');
      var chkAmt = document.getElementById('py_check_amount');
      var amt = v.amount > 0 ? v.amount : 0;
      var chkVal = v.check_amount > 0 ? v.check_amount : 0;
      if (payMethod === 'check') {
        if (chkAmt) chkAmt.value = (chkVal > 0 ? chkVal : amt) > 0 ? fmtMoneyInput(chkVal > 0 ? chkVal : amt) : '';
        if (amountEl) amountEl.value = '';
      } else {
        if (amountEl) amountEl.value = amt > 0 ? fmtMoneyInput(amt) : '';
        if (chkAmt) chkAmt.value = '';
        lastChecksManageData = [];
      }

      var chkNo = document.getElementById('py_check_no');
      if (chkNo) chkNo.value = v.check_no || '';

      var bank = document.getElementById('py_bank_name');
      if (bank) bank.value = v.bank_name || '';
      if (!bank || !String(bank.value || '').trim()) {
        syncBankNameFromCashAccount();
      }

      var dueEl = document.getElementById('py_check_due');
      if (dueEl) {
        var dueVal = '';
        if (v.check_due_date_dmy) dueVal = String(v.check_due_date_dmy);
        else if (v.check_due_date) dueVal = fmtDate(v.check_due_date) || '';
        else if (Array.isArray(v.checks) && v.checks[0]) {
          dueVal = v.checks[0].due_date_dmy || fmtDate(v.checks[0].due_date || '') || '';
        }
        dueEl.value = dueVal;
      }

      var notes = document.getElementById('py_notes');
      if (notes) notes.value = v.notes || '';
      lastAutoCheckNotes = '';
      if (getPayMethod() === 'check' && notes) {
        var loadedNotes = String(notes.value).trim();
        var autoNotes = buildCheckDisbursementNotes();
        if (loadedNotes === '' || loadedNotes === autoNotes) {
          notes.value = autoNotes;
          lastAutoCheckNotes = autoNotes;
        }
      }

      var cashAcc = document.getElementById('py_cash_account_id');
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
        AppDialog.alert('ليس لديك صلاحية تعديل سند صرف مرحّل.', { type: 'warning' });
      } else {
        alert('ليس لديك صلاحية تعديل سند صرف مرحّل.');
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

    var pyNoEl = document.getElementById('py_no');
    var pyLabel = pyNoEl && pyNoEl.value ? pyNoEl.value : String(currentVoucherId);
    var msgHtml =
      '<p>لتعديل السند «' +
      escapeHtml(pyLabel) +
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
    if (voucherIsCancelled) {
      AppDialog.alert('لا يمكن ترحيل سند ملغى.', { type: 'warning' });
      return;
    }
    if (voucherIsPosted) {
      if (global.AppDialog) AppDialog.alert('هذا السند مرحّل مسبقًا.', { type: 'info' });
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var payMethodEl = form.querySelector('[name="pay_method"]');
    var isCheckPay = payMethodEl && payMethodEl.value === 'check';
    var confirmMsg = isCheckPay
      ? 'ترحيل سند الصرف (شيك)؟\nلن يتأثر حساب البنك أو الطرف (مورد/عميل/موظف/حساب) الآن — الأثر المحاسبي عند «صرف» من سجل الشيكات الصادرة.'
      : 'ترحيل سند الصرف؟\nيُسجَّل القيد على الطرف وعلى الحساب: ' + (getCashAccountLabel() || '—') + '.';
    if (global.AppDialog) {
      AppDialog.confirm(confirmMsg, {
        title: 'ترحيل السند',
      }).then(function (ok) {
        if (!ok) return;
        doPostVoucher(csrfInput);
      });
    } else if (confirm(confirmMsg)) {
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
    var rcNoEl = document.getElementById('py_no');
    var rcLabel = rcNoEl && rcNoEl.value ? rcNoEl.value : String(currentVoucherId);
    AppDialog.confirm(
      'إلغاء السند «' +
        rcLabel +
        '»؟\n\n' +
        'يُلغى أثره المحاسبي ويبقى السند في السجل برقم التسلسل (لا يُحذف).',
      { title: 'إلغاء سند صرف', danger: true, okText: 'إلغاء السند' }
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

  function resetPaymentForm() {
    runWithoutDirtyMark(function () {
      if (window.DocumentNoNav) DocumentNoNav.clearSearch(docNoSearch);
      currentVoucherId = 0;
      voucherIsPosted = false;
      voucherIsCancelled = false;
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
      if (pyCustomerPickerApi) pyCustomerPickerApi.setById(0, true);
      if (pySupplierPickerApi) pySupplierPickerApi.setById(0, true);
      if (pyEmployeePickerApi) pyEmployeePickerApi.setById(0, true);
      if (pyAccountPickerApi && global.AccountPickerModal) {
        AccountPickerModal.setById(pyAccountPickerApi, 0, true);
      } else {
        var accTarget = document.getElementById('py_account_target');
        if (accTarget) accTarget.value = '';
      }
      selectedHrAdvanceId = 0;
      selectedHrSalaryId = 0;
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

      var dueEl = document.getElementById('py_check_due');
      if (dueEl) dueEl.value = '';

      var notes = document.getElementById('py_notes');
      if (notes) notes.value = '';
      lastAutoCheckNotes = '';

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
    var payLabel =
      getPayMethod() === 'check' ? 'شيك' : getPayMethod() === 'bank' ? 'بنك' : 'نقداً';
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
      var dueEl = document.getElementById('py_check_due');
      checkRows =
        '<tr><td style="padding:0.4rem;border:1px solid #cbd5e1;"><strong>رقم الشيك</strong></td><td style="padding:0.4rem;border:1px solid #cbd5e1;">' +
        escapeHtml(chkNo ? chkNo.value : '—') +
        '</td></tr>' +
        '<tr><td style="padding:0.4rem;border:1px solid #cbd5e1;"><strong>البنك</strong></td><td style="padding:0.4rem;border:1px solid #cbd5e1;">' +
        escapeHtml(bank && bank.value ? bank.value : '—') +
        '</td></tr>' +
        '<tr><td style="padding:0.4rem;border:1px solid #cbd5e1;"><strong>تاريخ صرف الشيك</strong></td><td style="padding:0.4rem;border:1px solid #cbd5e1;">' +
        escapeHtml(dueEl && dueEl.value ? fmtDate(dueEl.value) || dueEl.value : '—') +
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
        var rcNoEl = document.getElementById('py_no');
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
        var deleteMsg =
          'حذف مسودة السند «' + rcLabel + '»؟\nسيُعاد استخدام رقم السند في السند التالي إن وُجد.';
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
      if (window.DocumentNoNav && DocumentNoNav.shouldSkipBlurSearch(docNoSearch, currentVoucherId, no)) {
        return;
      }
      loadVoucherByNo(no);
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

  ['py_check_no', 'py_check_due', 'py_date'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', syncCheckDisbursementNotes);
    el.addEventListener('change', syncCheckDisbursementNotes);
  });
  var cashAccSel = document.getElementById('py_cash_account_id');
  if (cashAccSel) {
    cashAccSel.addEventListener('change', function () {
      syncBankNameFromCashAccount();
    });
  }
  form.addEventListener('change', function (e) {
    var t = e.target;
    if (!t) return;
    if (
      t.name === 'party_type_ui' ||
      t.id === 'py_customer' ||
      t.id === 'py_supplier' ||
      t.id === 'py_employee' ||
      t.id === 'py_account_target'
    ) {
      syncCheckDisbursementNotes();
    }
  });

  form.addEventListener('click', function (e) {
    var undoBtn = e.target.closest('.fin-py-check-undo');
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
      if (pyEmployeePickerApi) {
        pyEmployeePickerApi.setById(employeeId, true);
      } else {
        var empSel = document.getElementById('py_employee');
        if (empSel) empSel.value = String(employeeId);
      }
      var amountEl = document.getElementById('py_amount');
      if (amountEl && parseNum(bootstrap.amount) > 0) {
        amountEl.value = fmtMoneyInput(parseNum(bootstrap.amount));
      }
      var hrAdv = document.getElementById('py_hr_advance_id');
      if (hrAdv && advanceId > 0) {
        hrAdv.value = String(advanceId);
        selectedHrAdvanceId = advanceId;
      }
      var cashSel = document.getElementById('py_cash_account_id');
      if (cashSel && parseInt(bootstrap.cash_account_id, 10) > 0) {
        cashSel.value = String(parseInt(bootstrap.cash_account_id, 10));
      }
      syncOffsetAccountField();
      syncEmployeeAmountLock();
      refreshHrAdvanceDisburseLock();
    });
    return Promise.resolve();
  }

  function bootReceiptPage() {
    if (global.FinVoucherArchive) {
      global.FinVoucherArchive.init({
        apiUrl: form.getAttribute('data-archive-api') || '',
        csrf: (form.querySelector('input[name="_csrf"]') || {}).value || '',
        kind: form.getAttribute('data-archive-kind') || 'payment',
        title: 'سند صرف',
        canArchive: form.getAttribute('data-can-archive') === '1',
        getVoucherId: function () {
          return currentVoucherId;
        },
        getVoucherLabel: function () {
          return {
            no: (document.getElementById('py_no') || {}).value || '',
            date: (document.getElementById('py_date') || {}).value || '',
          };
        },
        companyName: form.getAttribute('data-company-name') || '',
        isArchiveAllowed: paymentArchiveState,
      });
    }
    form.querySelectorAll('input[name="party_type_ui"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        setPartyType(radio.value);
        markFormDirty();
      });
    });
    syncPartyTypeUi();

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
          syncCheckDisbursementNotes();
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
          syncCheckDisbursementNotes();
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
        onSelect: function () {
          markFormDirty();
          syncCheckDisbursementNotes();
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
          syncCheckDisbursementNotes();
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
