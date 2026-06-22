(function () {
  'use strict';

  var form = document.getElementById('journal-entry-form');
  if (!form) return;

  var global = typeof window !== 'undefined' ? window : self;
  var tbody = document.getElementById('journal-lines-body');
  var linesJson = document.getElementById('journal-lines-json');
  var actionField = document.getElementById('journal-form-action');
  var totalDebitEl = document.getElementById('journal-total-debit');
  var totalCreditEl = document.getElementById('journal-total-credit');
  var balanceHint = document.getElementById('journal-balance-hint');
  var addBtn = document.getElementById('journal-add-line');
  var readOnly = form.getAttribute('data-readonly') === '1';

  var accounts = [];
  try {
    var accEl = document.getElementById('journal-accounts-json');
    accounts = accEl ? JSON.parse(accEl.textContent || '[]') : [];
  } catch (e) {
    accounts = [];
  }

  var initialLines = [];
  try {
    var initEl = document.getElementById('journal-initial-lines-json');
    initialLines = initEl ? JSON.parse(initEl.textContent || '[]') : [];
  } catch (e2) {
    initialLines = [];
  }

  var lineUid = 0;

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
    var accBtn = tr.querySelector('.je-acc-picker-btn');
    var debitInp = tr.querySelector('.js-debit');
    var creditInp = tr.querySelector('.js-credit');

    if (accBtn && accBtn.getAttribute('data-je-enter-nav') !== '1') {
      accBtn.setAttribute('data-je-enter-nav', '1');
      accBtn.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        if (rowAccountId(tr) > 0) {
          e.preventDefault();
          focusRowDebit(tr);
        }
      });
    }

    if (debitInp && debitInp.getAttribute('data-je-enter-nav') !== '1') {
      debitInp.setAttribute('data-je-enter-nav', '1');
      debitInp.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        focusRowCredit(tr);
      });
    }

    if (creditInp && creditInp.getAttribute('data-je-enter-nav') !== '1') {
      creditInp.setAttribute('data-je-enter-nav', '1');
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
    var openBtn = tr.querySelector('.je-acc-picker-btn');
    var display = tr.querySelector('.sales-inv-cust-open-label');
    if (!hidden || !openBtn || !display) return;

    var binding = AccountPickerModal.bind({
      hidden: hidden,
      open: openBtn,
      display: display,
      jsonId: 'journal-accounts-json',
      placeholder: 'اختر حساباً…',
      allowClear: false,
      initialId: accountId > 0 ? accountId : hidden.value || '',
      onSelect: function () {
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

    var accId = parseInt(data.account_id, 10) || 0;
    var lineDebit = parseNum(data.debit);
    var lineCredit = parseNum(data.credit);

    var accCell = document.createElement('td');
    accCell.className = 'col-acc';
    if (readOnly) {
      accCell.textContent =
        (data.account_code || '') +
        (data.account_name ? ' — ' + data.account_name : '');
    } else {
      lineUid += 1;
      var uid = 'je_acc_' + lineUid;
      var openId = uid + '_open';
      var displayId = uid + '_display';

      var slot = document.createElement('div');
      slot.className = 'account-picker-slot je-acc-picker-slot';
      slot.setAttribute('data-account-picker', '');

      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.className = 'journal-account-id';
      hidden.id = uid;
      hidden.value = accId > 0 ? String(accId) : '';
      hidden.required = true;

      var openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.className = 'sales-inv-cust-open input je-acc-picker-btn';
      openBtn.id = openId;
      openBtn.title = 'اختيار حساب — ابحث بالرقم أو الاسم';

      var label = document.createElement('span');
      label.id = displayId;
      label.className =
        'sales-inv-cust-open-label' + (accId > 0 ? '' : ' is-placeholder');
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
      memoInp.value = data.memo || '';
      memoCell.appendChild(memoInp);
    }

    tr.appendChild(accCell);
    tr.appendChild(debitCell);
    tr.appendChild(creditCell);
    tr.appendChild(memoCell);

    if (!readOnly) {
      bindRowAccountPicker(tr, accId);
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
    return {
      debit: debitInp ? parseNum(debitInp.value) : 0,
      credit: creditInp ? parseNum(creditInp.value) : 0,
    };
  }

  function collectLines() {
    var lines = [];
    tbody.querySelectorAll('.journal-line-row').forEach(function (tr) {
      var accHidden = tr.querySelector('.journal-account-id');
      var memoInp = tr.querySelector('.journal-memo');
      var amounts = getRowDebitCredit(tr);
      var accountId = accHidden ? parseInt(accHidden.value, 10) || 0 : 0;
      if (accountId < 1 && amounts.debit <= 0 && amounts.credit <= 0) {
        return;
      }
      lines.push({
        account_id: accountId,
        debit: amounts.debit,
        credit: amounts.credit,
        memo: memoInp ? memoInp.value.trim() : '',
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

  if (initialLines.length) {
    initialLines.forEach(function (ln) {
      tbody.appendChild(createRow(ln));
    });
  }
  ensureMinRows();
  recalc();

  function syncExitGuard() {
    if (global.ScreenExitGuard && typeof global.ScreenExitGuard.syncFor === 'function') {
      global.ScreenExitGuard.syncFor(form);
    }
  }

  syncExitGuard();
  global.window.addEventListener('load', syncExitGuard);

  if (addBtn) {
    addBtn.addEventListener('click', function () {
      addLineAndFocusDebit();
    });
    addBtn.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || readOnly) return;
      e.preventDefault();
      addLineAndFocusDebit();
    });
  }

  if (!readOnly) {
    form.querySelectorAll('button[data-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (actionField) {
          actionField.value = btn.getAttribute('data-action') || 'save';
        }
      });
    });

    form.addEventListener('submit', function (ev) {
      recalc();
      var lines = collectLines();
      var sumD = 0;
      var sumC = 0;
      var missingAccount = false;
      lines.forEach(function (ln) {
        sumD += ln.debit;
        sumC += ln.credit;
        if (ln.account_id < 1 && (ln.debit > 0 || ln.credit > 0)) {
          missingAccount = true;
        }
      });
      if (missingAccount) {
        ev.preventDefault();
        alert('اختر حساباً لكل سطر فيه مبلغ.');
        return;
      }
      if (Math.abs(sumD - sumC) >= 0.000001) {
        ev.preventDefault();
        alert('مجموع المدين يجب أن يساوي مجموع الدائن.');
        return;
      }
      if (
        lines.filter(function (l) {
          return l.account_id > 0 && (l.debit > 0 || l.credit > 0);
        }).length < 2
      ) {
        ev.preventDefault();
        alert('أضف سطرين على الأقل بمبالغ صحيحة.');
        return;
      }
      if (linesJson) {
        linesJson.value = JSON.stringify(lines);
      }
    });
  }

  function doPrint() {
    if (!readOnly) return;
    window.print();
  }

  var printBtn = document.getElementById('journal-entry-print-btn');
  if (printBtn) {
    printBtn.addEventListener('click', doPrint);
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'print' || !readOnly) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    doPrint();
  }, true);

  var journalRoot = document.querySelector('.journal-entries-ora[data-check-undo-id]');
  var checkUndoBtn = document.getElementById('journal-check-undo-btn');
  if (journalRoot && checkUndoBtn) {
    checkUndoBtn.addEventListener('click', function () {
      var apiUrl = journalRoot.getAttribute('data-check-undo-api') || '';
      var checkId = journalRoot.getAttribute('data-check-undo-id') || '';
      var undoLabel = journalRoot.getAttribute('data-check-undo-label') || 'إلغاء';
      var csrfInput = form && form.querySelector('[name="_csrf"]');
      var csrfVal = csrfInput ? csrfInput.value : '';
      if (!apiUrl || !checkId) return;

      var msg =
        'تأكيد ' +
        undoLabel +
        '؟\n\nسيتم حذف هذا القيد وإعادة الشيك إلى «قيد» في شاشة الشيكات.';

      function runUndo() {
        var fd = new FormData();
        fd.append('_csrf', csrfVal);
        fd.append('action', 'undo');
        fd.append('check_id', checkId);
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (data && data.ok) {
              if (global.AppDialog && AppDialog.success) {
                AppDialog.success((data && data.message) || 'تم الإلغاء.').then(function () {
                  window.location.href = journalRoot.getAttribute('data-exit-url') || window.location.href;
                });
              } else {
                alert((data && data.message) || 'تم الإلغاء.');
                window.location.href = journalRoot.getAttribute('data-exit-url') || window.location.href;
              }
              return;
            }
            var err = (data && data.message) || 'تعذر الإلغاء.';
            if (global.AppDialog && AppDialog.error) AppDialog.error(err);
            else alert(err);
          })
          .catch(function () {
            if (global.AppDialog && AppDialog.error) AppDialog.error('خطأ في الاتصال.');
            else alert('خطأ في الاتصال.');
          });
      }

      if (global.AppDialog && AppDialog.confirm) {
        AppDialog.confirm(msg, { title: undoLabel, okText: undoLabel }).then(function (ok) {
          if (ok) runUndo();
        });
      } else if (window.confirm(msg)) {
        runUndo();
      }
    });
  }
})();
