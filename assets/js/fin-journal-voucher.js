(function () {
  'use strict';

  var form = document.getElementById('fin-jv-form');
  if (!form) return;

  var global = typeof window !== 'undefined' ? window : self;
  var apiView = form.getAttribute('data-api-view') || '';
  var apiDelete = form.getAttribute('api-delete') || form.getAttribute('data-api-delete') || '';
  var apiPost = form.getAttribute('data-api-post') || '';
  var apiUnpost = form.getAttribute('data-api-unpost') || '';
  var apiEditUnlock = form.getAttribute('data-api-edit-unlock') || '';
  var apiCancel = form.getAttribute('data-api-cancel') || '';
  var canUnpostByPermission = form.getAttribute('data-can-unpost') === '1';
  var canEditByPermission = form.getAttribute('data-can-edit') === '1';
  var newUrl = form.getAttribute('data-new-url') || '';
  var defaultDate = form.getAttribute('data-default-date') || '';
  var initialId = parseInt(form.getAttribute('data-initial-id') || '0', 10);
  var entryStatus = '';
  var noDeleteEntry = false;
  var isManualEntry = true;

  var tbody = document.getElementById('jv-lines-body');
  var linesJson = document.getElementById('jv_lines_json');
  var entryIdEl = document.getElementById('jv_entry_id');
  var noEl = document.getElementById('jv_no');
  var dateEl = document.getElementById('jv_date');
  var descEl = document.getElementById('jv_description');
  var totalDebitEl = document.getElementById('jv-total-debit');
  var totalCreditEl = document.getElementById('jv-total-credit');
  var balanceHint = document.getElementById('jv-balance-hint');
  var addBtn = document.getElementById('jv-add-line');
  var statusBadge = document.getElementById('jv_posted_badge');
  var wrap = form.closest('.fin-jv-wrap');

  var currentId = 0;
  var browsePrevId = 0;
  var browseNextId = 0;
  var docNoSearch = window.DocumentNoNav ? DocumentNoNav.createSearchState() : { matchIds: [], matchIndex: -1, query: '', currentDocNo: '' };
  var DOC_NO_SEARCH_UI = {
    noInputId: 'jv_no',
    prevBtnId: 'jv_no_prev',
    nextBtnId: 'jv_no_next',
    defaultNoTitle: 'اكتب جزءاً من رقم السند واضغط Enter للبحث',
  };
  var readOnly = false;
  var formSubmitting = false;
  var lineUid = 0;
  var loadedEntryNo = '';
  var exitUrl = form.getAttribute('data-exit-url') || '';
  var companyName = form.getAttribute('data-company-name') || '';
  var companyLogoUrl = form.getAttribute('data-company-logo') || '';
  var arAccountId = parseInt(form.getAttribute('data-ar-account-id') || '0', 10) || 0;
  var apAccountId = parseInt(form.getAttribute('data-ap-account-id') || '0', 10) || 0;
  var printBtn = document.getElementById('jv-print-btn');

  function syncExitGuard() {
    if (global.ScreenExitGuard && typeof global.ScreenExitGuard.syncFor === 'function') {
      global.ScreenExitGuard.syncFor(form);
    }
  }

  var accounts = [];
  try {
    var accEl = document.getElementById('jv-accounts-json');
    accounts = accEl ? JSON.parse(accEl.textContent || '[]') : [];
  } catch (e) {
    accounts = [];
  }

  function parseNum(v) {
    var n = parseFloat(String(v || '').replace(/,/g, ''));
    return isNaN(n) ? 0 : Math.max(0, n);
  }

  function formatNum(n) {
    if (global.AppFormat && global.AppFormat.fmt) return global.AppFormat.fmt(n);
    return n.toFixed(2);
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function accountLabelById(accountId) {
    var id = parseInt(accountId, 10);
    if (id < 1) return '';
    for (var i = 0; i < accounts.length; i++) {
      if (parseInt(accounts[i].id, 10) === id) {
        var code = String(accounts[i].code || '').trim();
        var name = String(accounts[i].name_ar || '').trim();
        return code && name ? code + ' — ' + name : name || code;
      }
    }
    return '';
  }

  function rowAccountId(tr) {
    var hidden = tr && tr.querySelector('.journal-account-id');
    return hidden ? parseInt(hidden.value, 10) || 0 : 0;
  }

  function partyRoleForAccount(accountId) {
    var id = parseInt(accountId, 10) || 0;
    if (id < 1) return '';
    if (arAccountId > 0 && id === arAccountId) return 'customer';
    if (apAccountId > 0 && id === apAccountId) return 'supplier';
    return '';
  }

  function partyLabelFromData(data) {
    if (!data) return '';
    var name = String(data.party_name || '').trim();
    var code = String(data.party_code || '').trim();
    if (name && code) return code + ' — ' + name;
    return name || code || '';
  }

  function clearRowParty(tr) {
    if (!tr) return;
    var typeEl = tr.querySelector('.jv-party-type');
    var idEl = tr.querySelector('.jv-party-id');
    if (typeEl) typeEl.value = '';
    if (idEl) idEl.value = '';
    var custLabel = tr.querySelector('.jv-party-customer-label');
    var supLabel = tr.querySelector('.jv-party-supplier-label');
    if (custLabel) {
      custLabel.textContent = 'اختر عميلاً…';
      custLabel.classList.add('is-placeholder');
    }
    if (supLabel) {
      supLabel.textContent = 'اختر مورداً…';
      supLabel.classList.add('is-placeholder');
    }
  }

  function syncRowPartyVisibility(tr) {
    if (!tr) return;
    var role = partyRoleForAccount(rowAccountId(tr));
    var partyCell = tr.querySelector('.col-party');
    var custSlot = tr.querySelector('.jv-party-customer-slot');
    var supSlot = tr.querySelector('.jv-party-supplier-slot');
    if (partyCell) {
      partyCell.classList.toggle('is-hidden-party', !role);
    }
    if (custSlot) custSlot.classList.toggle('is-hidden', role !== 'customer');
    if (supSlot) supSlot.classList.toggle('is-hidden', role !== 'supplier');
    var typeEl = tr.querySelector('.jv-party-type');
    if (typeEl) typeEl.value = role || '';
    if (!role) clearRowParty(tr);
  }

  function bindRowPartyPicker(tr, data) {
    if (!tr || readOnly) return;
    data = data || {};
    syncRowPartyVisibility(tr);
    if (tr.getAttribute('data-party-picker-bound') === '1') return;

    var custHidden = tr.querySelector('.jv-party-customer-id');
    var supHidden = tr.querySelector('.jv-party-supplier-id');
    var typeEl = tr.querySelector('.jv-party-type');
    var idEl = tr.querySelector('.jv-party-id');

    function syncPartyHiddenFromRole() {
      var role = partyRoleForAccount(rowAccountId(tr));
      if (!typeEl || !idEl) return;
      typeEl.value = role || '';
      if (role === 'customer' && custHidden) {
        idEl.value = custHidden.value || '';
      } else if (role === 'supplier' && supHidden) {
        idEl.value = supHidden.value || '';
      } else {
        idEl.value = '';
      }
    }

    if (global.CustomerPickerModal && custHidden) {
      var custOpen = tr.querySelector('.jv-party-customer-open');
      var custLabel = tr.querySelector('.jv-party-customer-label');
      if (custOpen && custLabel) {
        CustomerPickerModal.bind({
          hidden: custHidden,
          open: custOpen,
          display: custLabel,
          jsonId: 'jv-customers-json',
          placeholder: 'اختر عميلاً…',
          allowClear: false,
          initialId: data.party_type === 'customer' ? data.party_id : 0,
          onSelect: function () {
            syncPartyHiddenFromRole();
            focusRowDebit(tr);
          },
        });
      }
    }

    if (global.SupplierPickerModal && supHidden) {
      var supOpen = tr.querySelector('.jv-party-supplier-open');
      var supLabel = tr.querySelector('.jv-party-supplier-label');
      if (supOpen && supLabel) {
        SupplierPickerModal.bind({
          hidden: supHidden,
          open: supOpen,
          display: supLabel,
          jsonId: 'jv-suppliers-json',
          placeholder: 'اختر مورداً…',
          allowClear: false,
          initialId: data.party_type === 'supplier' ? data.party_id : 0,
          onSelect: function () {
            syncPartyHiddenFromRole();
            focusRowDebit(tr);
          },
        });
      }
    }

    if (data.party_type === 'customer' && custHidden && data.party_id) {
      custHidden.value = String(data.party_id);
    }
    if (data.party_type === 'supplier' && supHidden && data.party_id) {
      supHidden.value = String(data.party_id);
    }
    syncPartyHiddenFromRole();
    tr.setAttribute('data-party-picker-bound', '1');
  }

  function focusInput(inp) {
    if (!inp || readOnly) return;
    try {
      inp.focus();
      if (typeof inp.select === 'function') {
        inp.select();
      }
    } catch (e) {}
  }

  function focusRowDebit(tr) {
    focusInput(tr && tr.querySelector('.js-debit'));
  }

  function focusRowCredit(tr) {
    focusInput(tr && tr.querySelector('.js-credit'));
  }

  function addLineAndFocusDebit() {
    if (readOnly) return;
    var tr = createRow({});
    tbody.appendChild(tr);
    recalc();
    focusRowDebit(tr);
  }

  function bindLineKeyboardNav(tr) {
    if (!tr || readOnly) return;
    var accBtn = tr.querySelector('.jv-acc-picker-btn');
    var debitInp = tr.querySelector('.js-debit');
    var creditInp = tr.querySelector('.js-credit');

    if (accBtn && accBtn.getAttribute('data-jv-enter-nav') !== '1') {
      accBtn.setAttribute('data-jv-enter-nav', '1');
      accBtn.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        if (rowAccountId(tr) > 0) {
          e.preventDefault();
          focusRowDebit(tr);
        }
      });
    }

    if (debitInp && debitInp.getAttribute('data-jv-enter-nav') !== '1') {
      debitInp.setAttribute('data-jv-enter-nav', '1');
      debitInp.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        focusRowCredit(tr);
      });
    }

    if (creditInp && creditInp.getAttribute('data-jv-enter-nav') !== '1') {
      creditInp.setAttribute('data-jv-enter-nav', '1');
      creditInp.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        addLineAndFocusDebit();
      });
    }
  }

  function bindRowAccountPicker(tr, accountId) {
    if (!tr || readOnly || !global.AccountPickerModal) return;
    var slot = tr.querySelector('[data-account-picker]');
    if (!slot || slot.getAttribute('data-account-picker-bound') === '1') return;
    var hidden = tr.querySelector('.journal-account-id');
    var openBtn = tr.querySelector('.jv-acc-picker-btn');
    var display = tr.querySelector('.sales-inv-cust-open-label');
    if (!hidden || !openBtn || !display) return;

    var binding = AccountPickerModal.bind({
      hidden: hidden,
      open: openBtn,
      display: display,
      jsonId: 'jv-accounts-json',
      placeholder: 'اختر حساباً…',
      allowClear: false,
      initialId: accountId > 0 ? accountId : hidden.value || '',
      onSelect: function () {
        syncRowPartyVisibility(tr);
        bindRowPartyPicker(tr, {});
        var role = partyRoleForAccount(rowAccountId(tr));
        if (role === 'customer' || role === 'supplier') {
          var openBtnParty = tr.querySelector(
            role === 'customer' ? '.jv-party-customer-open' : '.jv-party-supplier-open'
          );
          if (openBtnParty) {
            openBtnParty.focus();
            return;
          }
        }
        focusRowDebit(tr);
      },
    });
    slot.setAttribute('data-account-picker-bound', '1');
    if (binding && accountId > 0) {
      AccountPickerModal.setById(binding, accountId);
    }
    bindLineKeyboardNav(tr);
  }

  function createRow(data) {
    data = data || {};
    var tr = document.createElement('tr');
    tr.className = 'journal-line-row';

    var accCell = document.createElement('td');
    accCell.className = 'col-acc';
    var accId = parseInt(data.account_id, 10) || 0;
    var lineDebit = parseNum(data.debit);
    var lineCredit = parseNum(data.credit);
    tr.dataset.debit = String(lineDebit);
    tr.dataset.credit = String(lineCredit);
    if (data.memo) {
      tr.dataset.memo = String(data.memo);
    }
    if (accId > 0) {
      tr.dataset.accountId = String(accId);
    }
    if (readOnly) {
      accCell.textContent =
        (data.account_code || '') +
        (data.account_name ? ' — ' + data.account_name : '');
    } else {
      lineUid += 1;
      var uid = 'jv_acc_' + lineUid;
      var openId = uid + '_open';
      var displayId = uid + '_display';
      var slot = document.createElement('\x64iv');
      slot.className = 'account-picker-slot jv-acc-picker-slot';
      slot.setAttribute('data-account-picker', '');
      slot.setAttribute('data-hidden-id', uid);
      slot.setAttribute('data-open-id', openId);
      slot.setAttribute('data-display-id', displayId);
      slot.setAttribute('data-json-id', 'jv-accounts-json');
      slot.setAttribute('data-placeholder', 'اختر حساباً…');
      slot.setAttribute('data-allow-clear', '0');
      slot.setAttribute('data-initial', accId > 0 ? String(accId) : '');

      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.className = 'journal-account-id';
      hidden.id = uid;
      hidden.name = uid;
      hidden.value = accId > 0 ? String(accId) : '';
      hidden.required = true;

      var openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.className = 'sales-inv-cust-open input jv-acc-picker-btn';
      openBtn.id = openId;
      openBtn.title = 'اختيار حساب — ابحث بالرقم أو الاسم';

      var label = document.createElement('span');
      label.id = displayId;
      label.className = 'sales-inv-cust-open-label' + (accId > 0 ? '' : ' is-placeholder');
      label.textContent = accId > 0 ? accountLabelById(accId) || 'اختر حساباً…' : 'اختر حساباً…';

      var ico = document.createElement('span');
      ico.className = 'sales-inv-cust-open-ico';
      ico.setAttribute('aria-hidden', 'true');
      ico.textContent = '▾';

      openBtn.appendChild(label);
      openBtn.appendChild(ico);
      slot.appendChild(hidden);
      slot.appendChild(openBtn);
      accCell.appendChild(slot);
    }

    var partyCell = document.createElement('td');
    partyCell.className = 'col-party';
    var partyRole = partyRoleForAccount(accId);
    var partyLabel = partyLabelFromData(data);
    if (readOnly) {
      partyCell.textContent = partyLabel || '—';
    } else {
      lineUid += 1;
      var partyUid = 'jv_party_' + lineUid;
      var typeHidden = document.createElement('input');
      typeHidden.type = 'hidden';
      typeHidden.className = 'jv-party-type';
      typeHidden.value = data.party_type || partyRole || '';

      var idHidden = document.createElement('input');
      idHidden.type = 'hidden';
      idHidden.className = 'jv-party-id';
      idHidden.value = data.party_id ? String(data.party_id) : '';

      var custSlot = document.createElement('div');
      custSlot.className = 'jv-party-slot jv-party-customer-slot' + (partyRole === 'customer' ? '' : ' is-hidden');

      var custHidden = document.createElement('input');
      custHidden.type = 'hidden';
      custHidden.className = 'jv-party-customer-id';
      custHidden.id = partyUid + '_cust';
      if (data.party_type === 'customer' && data.party_id) {
        custHidden.value = String(data.party_id);
      }

      var custOpen = document.createElement('button');
      custOpen.type = 'button';
      custOpen.className = 'sales-inv-cust-open input jv-party-picker-btn jv-party-customer-open';
      custOpen.title = 'اختيار عميل';
      var custLabel = document.createElement('span');
      custLabel.className =
        'sales-inv-cust-open-label jv-party-customer-label' +
        (data.party_type === 'customer' && partyLabel ? '' : ' is-placeholder');
      custLabel.textContent =
        data.party_type === 'customer' && partyLabel ? partyLabel : 'اختر عميلاً…';
      var custIco = document.createElement('span');
      custIco.className = 'sales-inv-cust-open-ico';
      custIco.setAttribute('aria-hidden', 'true');
      custIco.textContent = '▾';
      custOpen.appendChild(custLabel);
      custOpen.appendChild(custIco);
      custSlot.appendChild(custHidden);
      custSlot.appendChild(custOpen);

      var supSlot = document.createElement('div');
      supSlot.className = 'jv-party-slot jv-party-supplier-slot' + (partyRole === 'supplier' ? '' : ' is-hidden');

      var supHidden = document.createElement('input');
      supHidden.type = 'hidden';
      supHidden.className = 'jv-party-supplier-id';
      supHidden.id = partyUid + '_sup';
      if (data.party_type === 'supplier' && data.party_id) {
        supHidden.value = String(data.party_id);
      }

      var supOpen = document.createElement('button');
      supOpen.type = 'button';
      supOpen.className = 'sales-inv-cust-open input jv-party-picker-btn jv-party-supplier-open';
      supOpen.title = 'اختيار مورد';
      var supLabel = document.createElement('span');
      supLabel.className =
        'sales-inv-cust-open-label jv-party-supplier-label' +
        (data.party_type === 'supplier' && partyLabel ? '' : ' is-placeholder');
      supLabel.textContent =
        data.party_type === 'supplier' && partyLabel ? partyLabel : 'اختر مورداً…';
      var supIco = document.createElement('span');
      supIco.className = 'sales-inv-cust-open-ico';
      supIco.setAttribute('aria-hidden', 'true');
      supIco.textContent = '▾';
      supOpen.appendChild(supLabel);
      supOpen.appendChild(supIco);
      supSlot.appendChild(supHidden);
      supSlot.appendChild(supOpen);

      partyCell.appendChild(typeHidden);
      partyCell.appendChild(idHidden);
      partyCell.appendChild(custSlot);
      partyCell.appendChild(supSlot);
      if (!partyRole) partyCell.classList.add('is-hidden-party');
    }

    var debitCell = document.createElement('td');
    debitCell.className = 'col-money';
    if (readOnly) {
      debitCell.textContent = lineDebit > 0 ? formatNum(lineDebit) : '—';
    } else {
      var debitInp = document.createElement('input');
      debitInp.type = 'text';
      debitInp.className = 'input journal-amount js-debit';
      debitInp.inputMode = 'decimal';
      debitInp.dir = 'ltr';
      debitInp.value = lineDebit > 0 ? formatNum(lineDebit) : '';
      debitCell.appendChild(debitInp);
    }

    var creditCell = document.createElement('td');
    creditCell.className = 'col-money';
    if (readOnly) {
      creditCell.textContent = lineCredit > 0 ? formatNum(lineCredit) : '—';
    } else {
      var creditInp = document.createElement('input');
      creditInp.type = 'text';
      creditInp.className = 'input journal-amount js-credit';
      creditInp.inputMode = 'decimal';
      creditInp.dir = 'ltr';
      creditInp.value = lineCredit > 0 ? formatNum(lineCredit) : '';
      creditCell.appendChild(creditInp);
    }

    var memoCell = document.createElement('td');
    memoCell.className = 'col-memo';
    if (readOnly) {
      memoCell.textContent = data.memo || '—';
    } else {
      var memoInp = document.createElement('input');
      memoInp.type = 'text';
      memoInp.className = 'input journal-memo';
      memoInp.placeholder = 'تفصيل الحركة';
      memoInp.value = data.memo || '';
      memoCell.appendChild(memoInp);
    }

    tr.appendChild(accCell);
    tr.appendChild(partyCell);
    tr.appendChild(debitCell);
    tr.appendChild(creditCell);
    tr.appendChild(memoCell);

    if (!readOnly) {
      bindRowAccountPicker(tr, accId);
      bindRowPartyPicker(tr, data);
      var actCell = document.createElement('td');
      actCell.className = 'col-act';
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'btn btn-danger btn-sm journal-remove-line';
      rm.textContent = '×';
      rm.title = 'حذف السطر';
      actCell.appendChild(rm);
      tr.appendChild(actCell);

      tr.addEventListener('input', recalc);
      rm.addEventListener('click', function () {
        if (tbody.querySelectorAll('.journal-line-row').length <= 2) {
          alert('يجب الإبقاء على سطرين على الأقل.');
          return;
        }
        tr.remove();
        recalc();
      });

      var debitInp2 = tr.querySelector('.js-debit');
      var creditInp2 = tr.querySelector('.js-credit');
      if (debitInp2 && creditInp2) {
        debitInp2.addEventListener('input', function () {
          if (parseNum(debitInp2.value) > 0) {
            creditInp2.value = '';
          }
        });
        creditInp2.addEventListener('input', function () {
          if (parseNum(creditInp2.value) > 0) {
            debitInp2.value = '';
          }
        });
      }
      bindLineKeyboardNav(tr);
    }

    return tr;
  }

  function getRowDebitCredit(tr) {
    var debitInp = tr.querySelector('.js-debit');
    var creditInp = tr.querySelector('.js-credit');
    if (debitInp || creditInp) {
      return {
        debit: debitInp ? parseNum(debitInp.value) : 0,
        credit: creditInp ? parseNum(creditInp.value) : 0,
      };
    }
    if (tr.dataset.debit != null || tr.dataset.credit != null) {
      return {
        debit: parseNum(tr.dataset.debit),
        credit: parseNum(tr.dataset.credit),
      };
    }
    var moneyCells = tr.querySelectorAll('td.col-money');
    return {
      debit: moneyCells[0] ? parseNum(moneyCells[0].textContent) : 0,
      credit: moneyCells[1] ? parseNum(moneyCells[1].textContent) : 0,
    };
  }

  function collectLines() {
    var lines = [];
    tbody.querySelectorAll('.journal-line-row').forEach(function (tr) {
      var accHidden = tr.querySelector('.journal-account-id');
      var memoInp = tr.querySelector('.journal-memo');
      var amounts = getRowDebitCredit(tr);
      var accountId = 0;
      if (accHidden) {
        accountId = parseInt(accHidden.value, 10) || 0;
      } else if (tr.dataset.accountId) {
        accountId = parseInt(tr.dataset.accountId, 10) || 0;
      }
      if (accountId < 1 && amounts.debit <= 0 && amounts.credit <= 0) {
        return;
      }
      var partyTypeEl = tr.querySelector('.jv-party-type');
      var partyIdEl = tr.querySelector('.jv-party-id');
      var partyType = partyTypeEl ? String(partyTypeEl.value || '').trim() : '';
      var partyId = partyIdEl ? parseInt(partyIdEl.value, 10) || 0 : 0;
      var partyName = '';
      if (partyType === 'customer') {
        var custLbl = tr.querySelector('.jv-party-customer-label');
        if (custLbl && !custLbl.classList.contains('is-placeholder')) {
          partyName = String(custLbl.textContent || '').trim();
        }
      } else if (partyType === 'supplier') {
        var supLbl = tr.querySelector('.jv-party-supplier-label');
        if (supLbl && !supLbl.classList.contains('is-placeholder')) {
          partyName = String(supLbl.textContent || '').trim();
        }
      }
      lines.push({
        account_id: accountId,
        debit: amounts.debit,
        credit: amounts.credit,
        memo: memoInp ? memoInp.value.trim() : tr.dataset.memo || '',
        party_type: partyType,
        party_id: partyId,
        party_name: partyName,
      });
    });
    return lines;
  }

  function recalc() {
    var sumD = 0;
    var sumC = 0;
    tbody.querySelectorAll('.journal-line-row').forEach(function (tr) {
      var amounts = getRowDebitCredit(tr);
      sumD += amounts.debit;
      sumC += amounts.credit;
    });
    if (totalDebitEl) totalDebitEl.textContent = formatNum(sumD);
    if (totalCreditEl) totalCreditEl.textContent = formatNum(sumC);
    if (balanceHint) {
      var diff = Math.abs(sumD - sumC);
      var hasAmount = sumD > 0.000001 || sumC > 0.000001;
      if (diff < 0.000001 && hasAmount) {
        balanceHint.textContent = 'متوازن';
        balanceHint.className = 'journal-balance-ok';
      } else if (!hasAmount) {
        balanceHint.textContent = 'متوازن';
        balanceHint.className = 'journal-balance-ok';
      } else {
        balanceHint.textContent = 'غير متوازن (فرق ' + formatNum(diff) + ')';
        balanceHint.className = 'journal-balance-bad';
      }
    }
  }

  function ensureMinRows() {
    var count = tbody.querySelectorAll('.journal-line-row').length;
    while (count < 2) {
      tbody.appendChild(createRow({}));
      count++;
    }
  }

  function clearLines() {
    tbody.innerHTML = '';
    ensureMinRows();
    recalc();
  }

  function setLines(lines) {
    tbody.innerHTML = '';
    if (lines && lines.length) {
      lines.forEach(function (ln) {
        tbody.appendChild(createRow(ln));
      });
    }
    ensureMinRows();
    recalc();
  }

  function updateEntryNoPostedStyle() {
    if (!noEl) return;
    noEl.classList.remove('is-posted', 'is-unposted', 'is-cancelled');
    if (currentId < 1) return;
    if (entryStatus === 'cancelled') {
      noEl.classList.add('is-cancelled');
    } else if (entryStatus === 'posted') {
      noEl.classList.add('is-posted');
    } else if (entryStatus === 'draft') {
      noEl.classList.add('is-unposted');
    }
  }

  function updateJvToolbar() {
    var postBtn = document.querySelector('#master-toolbar [data-master-action="post"]');
    var editBtn = document.querySelector('#master-toolbar [data-master-action="edit"]');
    var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
    var cancelBtn = document.querySelector('#master-toolbar [data-master-action="cancel_voucher"]');
    var deleteBtn = document.querySelector('#master-toolbar [data-master-action="delete"]');
    var canPost = isManualEntry && currentId > 0 && entryStatus === 'draft';
    var canEdit = canEditByPermission && isManualEntry && currentId > 0 && entryStatus === 'posted';
    var canUnpost = isManualEntry && currentId > 0 && entryStatus === 'posted' && canUnpostByPermission;
    var canCancel = isManualEntry && currentId > 0 && entryStatus === 'posted';
    if (postBtn) {
      postBtn.disabled = !canPost;
      postBtn.title = canPost ? 'ترحيل السند' : 'احفظ السند أولاً أو السند مرحّل/ملغى';
    }
    if (editBtn) {
      editBtn.disabled = !canEdit;
      editBtn.title = canEdit
        ? 'تعديل السند بعد التحقق بكلمة المرور'
        : entryStatus === 'draft'
          ? 'السند قابل للتعديل — احفظ ثم رحّل'
          : 'يمكن تعديل السندات المرحّلة فقط';
    }
    if (unpostBtn) {
      unpostBtn.disabled = !canUnpost;
      unpostBtn.title = canUnpost ? 'فك الترحيل (للتعديل ثم إعادة الترحيل)' : 'لا يوجد ترحيل لفكّه';
    }
    if (cancelBtn) {
      cancelBtn.disabled = !canCancel;
      cancelBtn.title = canCancel
        ? 'إلغاء السند (يبقى برقم التسلسل ويُلغى أثره المحاسبي)'
        : 'يمكن إلغاء السندات المرحّلة اليدوية فقط';
    }
    var saveBtn = document.querySelector('#master-toolbar [data-master-action="save"]');
    if (saveBtn) {
      saveBtn.disabled = currentId > 0 && !isManualEntry;
      if (currentId > 0 && !isManualEntry) {
        saveBtn.title = 'لا يمكن حفظ قيد تلقائي من سند القيد';
      }
    }
    if (deleteBtn) {
      deleteBtn.disabled =
        !isManualEntry ||
        currentId < 1 ||
        entryStatus === 'posted' ||
        entryStatus === 'cancelled' ||
        noDeleteEntry;
      deleteBtn.title =
        !isManualEntry
          ? 'لا يمكن حذف قيد تلقائي من سند القيد'
          : entryStatus === 'cancelled'
          ? 'لا يمكن حذف سند ملغى'
          : entryStatus === 'posted'
            ? 'لا يمكن حذف سند مرحّل — استخدم «تعديل» أو «إلغاء السند»'
            : noDeleteEntry
              ? 'لا يمكن حذف سند كان مرحّلاً مسبقاً'
              : 'حذف مسودة السند';
    }
  }

  function updatePostedBadge() {
    if (!statusBadge) return;
    if (currentId < 1) {
      statusBadge.hidden = true;
      updateEntryNoPostedStyle();
      updateJvToolbar();
      return;
    }
    statusBadge.hidden = false;
    if (!isManualEntry) {
      statusBadge.textContent = 'قيد تلقائي';
      statusBadge.className = 'sales-inv-posted-badge badge badge-auto';
    } else if (entryStatus === 'posted') {
      statusBadge.textContent = 'مرحّل';
      statusBadge.className = 'sales-inv-posted-badge badge badge-posted';
    } else if (entryStatus === 'draft') {
      statusBadge.textContent = 'غير مرحّل';
      statusBadge.className = 'sales-inv-posted-badge badge badge-unposted';
    } else if (entryStatus === 'cancelled') {
      statusBadge.textContent = 'ملغى';
      statusBadge.className = 'sales-inv-posted-badge badge badge-cancelled';
    } else {
      statusBadge.textContent = String(entryStatus || '');
      statusBadge.className = 'sales-inv-posted-badge badge badge-posted';
    }
    updateEntryNoPostedStyle();
    updateJvToolbar();
  }

  function applyEntry(entry) {
    if (!entry) return;
    currentId = parseInt(entry.id, 10) || 0;
    applyBrowseNavFromEntry(entry);
    entryStatus = String(entry.status || 'draft');
    if (entry.is_cancelled === true || entryStatus === 'cancelled') {
      entryStatus = 'cancelled';
    }
    noDeleteEntry = entry.no_delete === true;
    isManualEntry = entry.is_manual !== false;
    if (entryIdEl) entryIdEl.value = currentId > 0 ? String(currentId) : '';
    if (noEl) noEl.value = entry.entry_no || '';
    loadedEntryNo = String(entry.entry_no || '').trim();
    if (dateEl) dateEl.value = entry.entry_date_dmy || entry.entry_date || defaultDate;
    if (descEl) descEl.value = entry.description_ar || '';

    var editable = entry.is_editable !== false && entry.status === 'draft' && isManualEntry;
    readOnly = !editable || entryStatus === 'cancelled' || !isManualEntry;
    if (wrap) {
      wrap.classList.toggle('is-readonly', readOnly);
      wrap.classList.toggle('fin-jv-form-is-cancelled', entryStatus === 'cancelled');
      wrap.classList.toggle('fin-jv-form-is-auto', !isManualEntry);
    }
    if (dateEl) dateEl.readOnly = readOnly;
    if (descEl) descEl.readOnly = readOnly;
    updatePostedBadge();

    tbody.innerHTML = '';
    (entry.lines || []).forEach(function (ln) {
      tbody.appendChild(createRow(ln));
    });
    ensureMinRows();
    recalc();

    if (currentId > 0 && window.history && window.history.replaceState) {
      var u = new URL(window.location.href);
      u.searchParams.set('id', String(currentId));
      window.history.replaceState({}, '', u.pathname + u.search);
    }
    syncExitGuard();
    updateJvNavButtons();
  }

  function resetNew() {
    if (window.DocumentNoNav) DocumentNoNav.clearSearch(docNoSearch);
    currentId = 0;
    browsePrevId = 0;
    browseNextId = 0;
    entryStatus = '';
    noDeleteEntry = false;
    isManualEntry = true;
    readOnly = false;
    if (wrap) wrap.classList.remove('is-readonly');
    if (entryIdEl) entryIdEl.value = '';
    if (noEl) {
      noEl.value = '';
      noEl.readOnly = false;
    }
    loadedEntryNo = '';
    if (dateEl) {
      dateEl.value = defaultDate;
      dateEl.readOnly = false;
    }
    if (descEl) {
      descEl.value = '';
      descEl.readOnly = false;
    }
    updatePostedBadge();
    clearLines();
    syncExitGuard();
    refreshEmptyBrowseNav();
  }

  function setBrowseNavFromSearch(prevId, nextId) {
    browsePrevId = prevId > 0 ? prevId : 0;
    browseNextId = nextId > 0 ? nextId : 0;
    updateJvNavButtons();
  }

  function applyBrowseNavFromEntry(entry) {
    if (window.DocumentNoNav && DocumentNoNav.applyBrowseNav) {
      DocumentNoNav.applyBrowseNav(docNoSearch, entry, setBrowseNavFromSearch, DOC_NO_SEARCH_UI);
      return;
    }
    browsePrevId = parseInt(entry.prev_id, 10) || 0;
    browseNextId = parseInt(entry.next_id, 10) || 0;
    updateJvNavButtons();
  }

  function updateJvNavButtons() {
    var prevId = currentId < 1 ? browsePrevId : browsePrevId;
    var nextId = currentId < 1 ? browseNextId : browseNextId;
    if (window.DocumentNoNav) {
      DocumentNoNav.updateButtons('jv_no_prev', 'jv_no_next', prevId, nextId, {
        onEmpty: currentId < 1,
        prevTitle: 'السند السابق',
        nextTitle: 'السند التالي',
        prevBeforeLatestTitle: 'السند قبل الأخير',
        latestTitle: 'آخر سند قيد',
      });
      return;
    }
    var prevBtn = document.getElementById('jv_no_prev');
    var nextBtn = document.getElementById('jv_no_next');
    if (prevBtn) prevBtn.disabled = !(prevId > 0);
    if (nextBtn) nextBtn.disabled = !(nextId > 0);
  }

  function refreshEmptyBrowseNav() {
    if (!apiView) {
      browsePrevId = 0;
      browseNextId = 0;
      updateJvNavButtons();
      return;
    }
    fetchEntry({ edge: 'first' }).then(function (data) {
      if (!data.ok || !data.entry) {
        browsePrevId = 0;
        browseNextId = 0;
        updateJvNavButtons();
        return;
      }
      browsePrevId = parseInt(data.entry.prev_id, 10) || 0;
      browseNextId = parseInt(data.entry.id, 10) || 0;
      updateJvNavButtons();
    });
  }

  function navigateJv(dir) {
    if (currentId < 1) {
      if (window.DocumentNoNav) {
        DocumentNoNav.navigateEmpty(dir, {
          browseNavPrevId: browsePrevId,
          browseNavNextId: browseNextId,
          fetchById: function (id) {
            return fetchEntry({ id: String(id) });
          },
          fetchLatest: function () {
            return fetchEntry({ edge: 'first' });
          },
          isOk: function (data) {
            return !!(data && data.ok && data.entry);
          },
          getPayload: function (data) {
            return data.entry;
          },
          apply: applyEntry,
          emptyMessage: 'لا توجد سندات.',
          loadLatestError: 'تعذر تحميل آخر سند.',
          loadError: 'تعذر تحميل السند.',
        });
        return;
      }
      fetchEntry({ edge: 'first' })
        .then(function (data) {
          if (!data.ok) throw new Error(data.message || '');
          applyEntry(data.entry);
        })
        .catch(function (err) {
          alert(err.message || 'لا توجد سندات.');
        });
      return;
    }
    if (window.DocumentNoNav && DocumentNoNav.isSearchActive(docNoSearch)) {
      DocumentNoNav.navigateSearchMatch(dir, docNoSearch, {
        fetchById: function (id) {
          return fetchEntry({ id: String(id) });
        },
        isOk: function (data) {
          return !!(data && data.ok && data.entry);
        },
        getPayload: function (data) {
          return data.entry;
        },
        apply: applyEntry,
        loadError: 'تعذر تحميل السند.',
      });
      return;
    }
    if (dir === 'prev') {
      if (browsePrevId > 0) {
        loadById(currentId, 'prev').catch(function () {
          alert('لا يوجد سند سابق.');
        });
      } else {
        alert('لا يوجد سند سابق.');
      }
      return;
    }
    if (browseNextId > 0) {
      loadById(currentId, 'next').catch(function () {
        alert('لا يوجد سند لاحق.');
      });
    } else {
      alert('لا يوجد سند لاحق.');
    }
  }

  function fetchEntry(params) {
    if (!apiView) return Promise.reject(new Error('no api'));
    var qs = new URLSearchParams(params).toString();
    return fetch(apiView + (qs ? '?' + qs : ''), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (r) {
      return r.json();
    });
  }

  function loadById(id, dir) {
    var params = { id: String(id) };
    if (dir) params.dir = dir;
    return fetchEntry(params).then(function (data) {
      if (!data.ok) {
        throw new Error(data.message || 'غير موجود');
      }
      applyEntry(data.entry);
    });
  }

  function loadByNo(no) {
    return fetchEntry({ no: no }).then(function (data) {
      if (!data.ok) {
        if (data.error === 'auto_entry') {
          var msg = data.message || 'هذا قيد تلقائي — افتح المستند الأصلي للتعديل.';
          if (data.ref_url && global.AppDialog && AppDialog.confirm) {
            return AppDialog.confirm(msg + '\n\nهل تريد فتح المستند الأصلي؟', {
              title: 'قيد تلقائي',
              okText: 'فتح المستند',
              cancelText: 'إغلاق',
            }).then(function (ok) {
              if (ok) window.location.href = data.ref_url;
              throw new Error(msg);
            });
          }
          throw new Error(msg);
        }
        throw new Error(data.message || 'غير موجود');
      }
      applyEntry(data.entry);
    });
  }

  function validateBeforeSave() {
    recalc();
    var lines = collectLines();
    var sumD = 0;
    var sumC = 0;
    lines.forEach(function (ln) {
      sumD += ln.debit;
      sumC += ln.credit;
    });
    if (Math.abs(sumD - sumC) >= 0.000001) {
      alert('مجموع المدين يجب أن يساوي مجموع الدائن.');
      return false;
    }
    var valid = lines.filter(function (l) {
      return l.account_id > 0 && (l.debit > 0 || l.credit > 0);
    });
    if (valid.length < 2) {
      alert('أضف سطرين على الأقل: حساب مدين وحساب دائن بمبالغ صحيحة.');
      return false;
    }
    for (var i = 0; i < lines.length; i++) {
      var ln = lines[i];
      var role = partyRoleForAccount(ln.account_id);
      if (role && !(parseInt(ln.party_id, 10) > 0)) {
        alert(role === 'customer' ? 'اختر العميل للسطر على حساب ذمم العملاء.' : 'اختر المورد للسطر على حساب ذمم الموردين.');
        return false;
      }
    }
    if (linesJson) linesJson.value = JSON.stringify(lines);
    return true;
  }

  function submitSave() {
    if (!isManualEntry && currentId > 0) {
      alert('هذا قيد تلقائي من مستند آخر. عدّله من شاشة المستند الأصلي.');
      return;
    }
    if (readOnly) {
      alert('لا يمكن تعديل سند مرحّل أو ملغى.');
      return;
    }
    if (!validateBeforeSave()) return;
    if (formSubmitting) return;
    formSubmitting = true;

    var fd = new FormData(form);
    fetch(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Invoice-Save': '1',
      },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        formSubmitting = false;
        if (!data.ok) {
          alert(data.message || 'تعذر الحفظ.');
          return;
        }
        if (data.entry) {
          applyEntry(data.entry);
        } else if (data.id) {
          return loadById(data.id);
        }
        if (global.AppDialog && AppDialog.alert) {
          AppDialog.alert(data.message || 'تم الحفظ.', { type: 'success' });
        }
      })
      .catch(function () {
        formSubmitting = false;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
  }

  function deleteEntry() {
    if (currentId < 1) {
      alert('لا يوجد سند محفوظ للحذف.');
      return;
    }
    if (entryStatus === 'cancelled') {
      alert('لا يمكن حذف سند ملغى. يبقى في السجل للحفاظ على التسلسل.');
      return;
    }
    if (entryStatus === 'posted') {
      alert('لا يمكن حذف سند مرحّل. استخدم «تعديل» أو «إلغاء السند» من الشريط العلوي.');
      return;
    }
    if (noDeleteEntry) {
      alert('لا يمكن حذف هذا السند لأنه كان مرحّلاً مسبقاً.');
      return;
    }
    if (readOnly) {
      alert('لا يمكن حذف هذا السند.');
      return;
    }
    if (!confirm('حذف مسودة سند القيد؟\nسيُعاد استخدام رقم السند في السند التالي إن وُجد.')) return;

    var fd = new FormData();
    fd.append('_csrf', form.querySelector('[name="_csrf"]').value);
    fd.append('id', String(currentId));

    fetch(apiDelete, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          alert(data.message || 'تعذر الحذف.');
          return;
        }
        window.location.href = newUrl;
      })
      .catch(function () {
        alert('تعذر الحذف.');
      });
  }

  function tryLoadByNoField() {
    var no = noEl ? String(noEl.value || '').trim() : '';
    if (!no) return;
    if (window.DocumentNoNav && DocumentNoNav.shouldSkipBlurSearch(docNoSearch, currentId, no)) {
      return;
    }
    return loadByNo(no).catch(function (err) {
      alert(err.message || 'لم يتم العثور على سند يحتوي على هذا الرقم.');
    });
  }

  function searchByNo() {
    var no = noEl ? String(noEl.value || '').trim() : '';
    if (!no) {
      alert('أدخل رقم السند في الحقل ثم اضغط Enter أو زر بحث.');
      if (noEl) noEl.focus();
      return;
    }
    tryLoadByNoField();
  }

  function postCurrentEntry() {
    if (!apiPost) {
      alert('الترحيل غير متاح.');
      return;
    }
    if (currentId < 1) {
      alert('احفظ السند أولاً ثم اضغط ترحيل.');
      return;
    }
    if (entryStatus === 'posted') {
      alert('هذا السند مرحّل مسبقاً.');
      return;
    }
    if (entryStatus === 'cancelled') {
      alert('لا يمكن ترحيل سند ملغى.');
      return;
    }
    if (entryStatus !== 'draft') {
      alert('لا يمكن ترحيل هذا السند.');
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var fd = new FormData();
    fd.append('_csrf', csrfInput ? csrfInput.value : '');
    fd.append('entry_id', String(currentId));
    fetch(apiPost, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          alert(data.message || 'تعذر الترحيل.');
          return;
        }
        if (data.entry) {
          applyEntry(data.entry);
        } else {
          return loadById(currentId);
        }
        if (global.AppDialog && AppDialog.success) {
          AppDialog.success(data.message || 'تم الترحيل.');
        }
      })
      .catch(function () {
        alert('تعذر الاتصال بالخادم.');
      });
  }

  function promptUserPassword(message) {
    if (global.AppDialog && AppDialog.prompt) {
      return AppDialog.prompt(message, {
        title: 'التحقق بكلمة المرور',
        okText: 'متابعة',
        cancelText: 'إلغاء',
        placeholder: 'كلمة المرور',
        inputType: 'password',
        multiline: false,
      });
    }
    return Promise.resolve(window.prompt(message, ''));
  }

  function editCurrentEntry() {
    if (!isManualEntry) {
      alert('هذا قيد تلقائي من مستند آخر. لا يمكن تعديله من سند القيد.');
      return;
    }
    if (!canEditByPermission) {
      if (global.AppDialog && AppDialog.alert) {
        AppDialog.alert('ليس لديك صلاحية تعديل سند قيد مرحّل.', { type: 'warning' });
      } else {
        alert('ليس لديك صلاحية تعديل سند قيد مرحّل.');
      }
      return;
    }
    if (!apiEditUnlock) {
      alert('التعديل غير متاح.');
      return;
    }
    if (currentId < 1) {
      alert('افتح السند أولاً.');
      return;
    }
    if (entryStatus !== 'posted') {
      alert(entryStatus === 'draft' ? 'السند غير مرحّل — يمكنك التعديل مباشرة.' : 'لا يمكن تعديل هذا السند.');
      return;
    }

    var label = noEl && noEl.value ? noEl.value : String(currentId);
    var msg =
      'لتعديل السند «' +
      label +
      '» أدخل كلمة مرورك.\n\nسيتم فك الترحيل تلقائياً ثم يمكنك تعديل التاريخ والحركات، وبعدها احفظ وأعد الترحيل.';

    promptUserPassword(msg).then(function (password) {
      if (password === null || String(password).trim() === '') {
        return;
      }
      var csrfInput = form.querySelector('[name="_csrf"]');
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('entry_id', String(currentId));
      fd.append('password', String(password));
      fetch(apiEditUnlock, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            if (global.AppDialog && AppDialog.alert) {
              AppDialog.alert((data && data.message) || 'تعذر بدء التعديل.', { type: 'warning' });
            } else {
              alert((data && data.message) || 'تعذر بدء التعديل.');
            }
            return;
          }
          if (data.entry) {
            applyEntry(data.entry);
          } else {
            return loadById(currentId);
          }
          if (global.AppDialog && AppDialog.success) {
            AppDialog.success(data.message || 'يمكنك التعديل الآن.');
          }
        })
        .catch(function () {
          alert('تعذر الاتصال بالخادم.');
        });
    });
  }

  function unpostCurrentEntry() {
    if (!canUnpostByPermission) {
      if (global.AppDialog && AppDialog.alert) {
        AppDialog.alert('ليس لديك صلاحية فك ترحيل سند القيد.', { type: 'warning' });
      } else {
        alert('ليس لديك صلاحية فك ترحيل سند القيد.');
      }
      return;
    }
    if (!apiUnpost) {
      alert('فك الترحيل غير متاح.');
      return;
    }
    if (currentId < 1) {
      alert('افتح السند أولاً.');
      return;
    }
    if (entryStatus !== 'posted') {
      alert('السند غير مرحّل.');
      return;
    }
    var doUnpost = function () {
      var csrfInput = form.querySelector('[name="_csrf"]');
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('entry_id', String(currentId));
      fetch(apiUnpost, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) {
            if (global.AppDialog && AppDialog.alert) {
              AppDialog.alert(data.message || 'تعذر فك الترحيل.', { type: 'warning' });
            } else {
              alert(data.message || 'تعذر فك الترحيل.');
            }
            return;
          }
          if (data.entry) {
            applyEntry(data.entry);
          } else {
            return loadById(currentId);
          }
          if (global.AppDialog && AppDialog.success) {
            AppDialog.success(data.message || 'تم فك الترحيل.');
          }
        })
        .catch(function () {
          alert('تعذر الاتصال بالخادم.');
        });
    };
    var msg = 'فك ترحيل هذا السند سيُلغي أثره من التقارير المحاسبية. هل تريد المتابعة؟';
    if (global.AppDialog && AppDialog.confirm) {
      AppDialog.confirm(msg, {
        type: 'warning',
        confirmText: 'نعم، فك الترحيل',
        cancelText: 'تراجع',
      }).then(function (ok) {
        if (ok) doUnpost();
      });
    } else if (window.confirm(msg)) {
      doUnpost();
    }
  }

  function cancelCurrentEntry() {
    if (!apiCancel) {
      alert('إلغاء السند غير متاح.');
      return;
    }
    if (currentId < 1 || entryStatus !== 'posted') {
      alert('يمكن إلغاء السندات المرحّلة فقط.');
      return;
    }
    var csrfInput = form.querySelector('[name="_csrf"]');
    var label = noEl && noEl.value ? noEl.value : String(currentId);
    var proceed = function () {
      var fd = new FormData();
      fd.append('_csrf', csrfInput ? csrfInput.value : '');
      fd.append('entry_id', String(currentId));
      fetch(apiCancel, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            if (global.AppDialog && AppDialog.alert) {
              AppDialog.alert((data && data.message) || 'تعذر الإلغاء.', { type: 'warning' });
            } else {
              alert((data && data.message) || 'تعذر الإلغاء.');
            }
            return;
          }
          if (data.entry) {
            applyEntry(data.entry);
          } else {
            return loadById(currentId);
          }
          if (global.AppDialog && AppDialog.success) {
            AppDialog.success(data.message || 'تم إلغاء السند.');
          }
        })
        .catch(function () {
          alert('تعذر الاتصال بالخادم.');
        });
    };
    var cancelMsg =
      'إلغاء السند «' +
      label +
      '»؟\n\nيُلغى أثره المحاسبي ويبقى السند في السجل برقم التسلسل (لا يُحذف).';
    if (global.AppDialog && AppDialog.confirm) {
      AppDialog.confirm(cancelMsg, { title: 'إلغاء سند قيد', danger: true, okText: 'إلغاء السند' }).then(
        function (ok) {
          if (ok) proceed();
        }
      );
    } else if (window.confirm(cancelMsg)) {
      proceed();
    }
  }

  if (addBtn) {
    addBtn.addEventListener('click', function () {
      if (readOnly) return;
      addLineAndFocusDebit();
    });
    addBtn.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || readOnly) return;
      e.preventDefault();
      addLineAndFocusDebit();
    });
  }

  var prevBtn = document.getElementById('jv_no_prev');
  var nextBtn = document.getElementById('jv_no_next');
  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      navigateJv('prev');
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      navigateJv('next');
    });
  }

  if (noEl) {
    noEl.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      searchByNo();
    });
    noEl.addEventListener('blur', function () {
      tryLoadByNoField();
    });
  }

  function buildDocPrintHeader(title) {
    var DH = global.DocumentHeader;
    if (!DH || typeof DH.build !== 'function') {
      return '<h1>' + escapeHtml(title) + '</h1>';
    }
    return DH.build({
      companyName: companyName,
      logoUrl: companyLogoUrl,
      title: title,
    });
  }

  function statusLabelForPrint() {
    if (entryStatus === 'posted') return 'مرحّل';
    if (entryStatus === 'draft') return 'مسودة';
    if (entryStatus === 'cancelled') return 'ملغى';
    return entryStatus || '—';
  }

  function buildJvPrintInnerHtml() {
    var rcNo = noEl && noEl.value ? String(noEl.value).trim() : '—';
    var date = dateEl && dateEl.value ? String(dateEl.value).trim() : '—';
    var desc = descEl && descEl.value ? String(descEl.value).trim() : '—';
    var lines = collectLines().filter(function (ln) {
      return ln.account_id > 0 || ln.debit > 0 || ln.credit > 0;
    });

    var metaRows = [
      { label: 'رقم السند', value: rcNo },
      { label: 'التاريخ', value: date },
      { label: 'بيان السند', value: desc },
      { label: 'الحالة', value: statusLabelForPrint() },
    ];
    var metaTable =
      global.DocumentHeader && typeof global.DocumentHeader.buildMetaTable === 'function'
        ? global.DocumentHeader.buildMetaTable(metaRows)
        : '';

    var bodyRows = '';
    var sumD = 0;
    var sumC = 0;
    lines.forEach(function (ln) {
      sumD += ln.debit;
      sumC += ln.credit;
      bodyRows +=
        '<tr>' +
        '<td class="jv-print-td jv-print-td-acc">' +
        escapeHtml(accountLabelById(ln.account_id) || '—') +
        '</td>' +
        '<td class="jv-print-td">' +
        escapeHtml(ln.party_name || '—') +
        '</td>' +
        '<td class="jv-print-td jv-print-td-money">' +
        escapeHtml(ln.debit > 0 ? formatNum(ln.debit) : '—') +
        '</td>' +
        '<td class="jv-print-td jv-print-td-money">' +
        escapeHtml(ln.credit > 0 ? formatNum(ln.credit) : '—') +
        '</td>' +
        '<td class="jv-print-td">' +
        escapeHtml(ln.memo || '—') +
        '</td>' +
        '</tr>';
    });

    if (!bodyRows) {
      bodyRows =
        '<tr><td class="jv-print-td" colspan="5" style="text-align:center;">—</td></tr>';
    }

    var linesTable =
      '<table class="jv-print-lines">' +
      '<thead><tr>' +
      '<th class="jv-print-th">الحساب</th>' +
      '<th class="jv-print-th">عميل / مورد</th>' +
      '<th class="jv-print-th jv-print-th-money">مدين</th>' +
      '<th class="jv-print-th jv-print-th-money">دائن</th>' +
      '<th class="jv-print-th">البيان</th>' +
      '</tr></thead>' +
      '<tbody>' +
      bodyRows +
      '</tbody>' +
      '<tfoot><tr>' +
      '<td class="jv-print-td jv-print-tfoot-label"><strong>المجموع</strong></td>' +
      '<td class="jv-print-td"></td>' +
      '<td class="jv-print-td jv-print-td-money"><strong>' +
      escapeHtml(formatNum(sumD)) +
      '</strong></td>' +
      '<td class="jv-print-td jv-print-td-money"><strong>' +
      escapeHtml(formatNum(sumC)) +
      '</strong></td>' +
      '<td class="jv-print-td"></td>' +
      '</tr></tfoot></table>';

    var signature =
      global.DocumentHeader && typeof global.DocumentHeader.buildRecipientSignature === 'function'
        ? global.DocumentHeader.buildRecipientSignature()
        : '';

    var inner = buildDocPrintHeader('سند قيد') + metaTable + linesTable + signature;
    return global.DocumentHeader && global.DocumentHeader.wrapPrintContent
      ? global.DocumentHeader.wrapPrintContent(inner, companyLogoUrl)
      : inner;
  }

  function docPrintWatermarkStyles() {
    var dh = global.DocumentHeader;
    return dh && companyLogoUrl && dh.buildPrintWatermarkStyles
      ? dh.buildPrintWatermarkStyles(companyLogoUrl)
      : '';
  }

  function getPrintFrameStyles() {
    var hdr = global.DocumentHeader && global.DocumentHeader.css ? global.DocumentHeader.css : '';
    var bold =
      global.DocumentHeader && global.DocumentHeader.printBoldCss
        ? global.DocumentHeader.printBoldCss
        : '';
    return (
      docPrintWatermarkStyles() +
      hdr +
      bold +
      '@page{size:A4 portrait;margin:10mm 12mm 12mm 12mm;}' +
      'html,body{max-width:100%;}' +
      'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0f172a;margin:6mm 12mm 12mm;direction:rtl;}' +
      'table{border-collapse:collapse;width:100%;margin-top:0.5rem;}' +
      '.jv-print-lines{margin-top:0.65rem;table-layout:fixed;width:100%;}' +
      '.jv-print-th{background:#f1f5f9;padding:0.45rem 0.55rem;border:1px solid #94a3b8;font-size:12px;font-weight:800;text-align:center;}' +
      '.jv-print-th-money{width:14%;}' +
      '.jv-print-td{padding:0.4rem 0.55rem;border:1px solid #cbd5e1;font-weight:700;font-size:12px;vertical-align:middle;}' +
      '.jv-print-td-acc{text-align:start;word-break:break-word;}' +
      '.jv-print-td-money{text-align:center;font-variant-numeric:tabular-nums;direction:ltr;}' +
      '.jv-print-tfoot-label{text-align:start;}' +
      '.jv-print-lines tbody tr:nth-child(even) .jv-print-td{background:#f8fafc;}' +
      '.jv-print-lines tfoot .jv-print-td{background:#f1f5f9;border-top:2px solid #64748b;}' +
      '@media print{' +
      '@page{size:A4 portrait;margin:10mm 12mm 12mm 12mm;}' +
      'body{margin:0;}' +
      '.jv-print-th,.jv-print-td,.jv-print-lines tfoot .jv-print-td{print-color-adjust:exact;-webkit-print-color-adjust:exact;}' +
      '}'
    );
  }

  function buildStandaloneJvHtml() {
    var bodyAttrs =
      global.DocumentHeader && global.DocumentHeader.bodyPrintAttrs
        ? global.DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
        : '';
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>سند قيد</title>' +
      '<style>' +
      getPrintFrameStyles() +
      '</style></head><body' +
      bodyAttrs +
      '>' +
      buildJvPrintInnerHtml() +
      '</body></html>'
    );
  }

  function printHtmlInFrame(fullHtml) {
    var frame = document.getElementById('fin-jv-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'fin-jv-print-frame';
      frame.className = 'sales-inv-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden;';
      document.body.appendChild(frame);
    }
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
    printHtmlInFrame(buildStandaloneJvHtml());
  }

  if (printBtn) {
    printBtn.addEventListener('click', handleToolbarPrint);
  }

  document.addEventListener('master-toolbar', function (e) {
    var action = e.detail && e.detail.action;
    if (!action) return;
    if (action === 'save') {
      e.preventDefault();
      submitSave();
    } else if (action === 'post') {
      e.preventDefault();
      postCurrentEntry();
    } else if (action === 'edit') {
      e.preventDefault();
      editCurrentEntry();
    } else if (action === 'unpost') {
      e.preventDefault();
      unpostCurrentEntry();
    } else if (action === 'cancel_voucher') {
      e.preventDefault();
      cancelCurrentEntry();
    } else if (action === 'delete') {
      e.preventDefault();
      deleteEntry();
    } else if (action === 'search') {
      e.preventDefault();
      searchByNo();
    } else if (action === 'new') {
      e.preventDefault();
      window.location.href = newUrl;
    } else if (action === 'print') {
      e.preventDefault();
      e.stopImmediatePropagation();
      handleToolbarPrint();
    }
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    submitSave();
  });

  ensureMinRows();
  recalc();

  if (initialId > 0) {
    loadById(initialId).catch(function () {
      resetNew();
    });
  } else {
    resetNew();
  }

  global.window.addEventListener('load', syncExitGuard);
})();
