(function () {
  'use strict';

  var cfg = window.__JV__ || {};
  var accounts = cfg.accounts || [];
  var customers = cfg.customers || [];
  var suppliers = cfg.suppliers || [];
  var arId = Number(cfg.ar || 0);
  var apId = Number(cfg.ap || 0);
  var editable = !!cfg.editable;

  var tbody = document.getElementById('jv-lines-body');
  var linesJson = document.getElementById('jv_lines_json');
  var form = document.getElementById('jv-form');
  var totalDebitEl = document.getElementById('jv-total-debit');
  var totalCreditEl = document.getElementById('jv-total-credit');
  var balanceHint = document.getElementById('jv-balance-hint');
  var addBtn = document.getElementById('jv-add-line');
  var intentEl = document.getElementById('jv_intent');

  if (!tbody) return;

  function fmt(n) {
    var x = Number(n) || 0;
    return x.toFixed(3);
  }

  function partyRole(accountId) {
    var id = Number(accountId) || 0;
    if (arId > 0 && id === arId) return 'customer';
    if (apId > 0 && id === apId) return 'supplier';
    return '';
  }

  function accountOptions(selected) {
    var html = '<option value="">اختر حساباً…</option>';
    accounts.forEach(function (a) {
      var sel = Number(selected) === Number(a.id) ? ' selected' : '';
      html +=
        '<option value="' +
        a.id +
        '"' +
        sel +
        '>' +
        escapeHtml((a.code || '') + ' — ' + (a.name || '')) +
        '</option>';
    });
    return html;
  }

  function partyOptions(role, selected) {
    var list = role === 'customer' ? customers : suppliers;
    var label = role === 'customer' ? 'اختر عميلاً…' : 'اختر مورداً…';
    var html = '<option value="">' + label + '</option>';
    list.forEach(function (p) {
      var sel = Number(selected) === Number(p.id) ? ' selected' : '';
      html +=
        '<option value="' +
        p.id +
        '"' +
        sel +
        '>' +
        escapeHtml((p.code ? p.code + ' — ' : '') + (p.name || '')) +
        '</option>';
    });
    return html;
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function syncPartyCell(tr) {
    var accSel = tr.querySelector('.jv-acc');
    var partyCell = tr.querySelector('.jv-party-cell');
    if (!accSel || !partyCell) return;
    var role = partyRole(accSel.value);
    var prevType = partyCell.getAttribute('data-party-type') || '';
    var prevId = Number(partyCell.getAttribute('data-party-id') || 0);
    if (!role) {
      partyCell.innerHTML = '<span class="muted">—</span>';
      partyCell.setAttribute('data-party-type', '');
      partyCell.setAttribute('data-party-id', '0');
      return;
    }
    if (!editable) {
      var label = '—';
      var list = role === 'customer' ? customers : suppliers;
      list.forEach(function (p) {
        if (Number(p.id) === prevId) {
          label = (p.code ? p.code + ' — ' : '') + (p.name || '');
        }
      });
      partyCell.innerHTML = '<span>' + escapeHtml(label) + '</span>';
      return;
    }
    var keepId = prevType === role ? prevId : 0;
    partyCell.innerHTML =
      '<select class="si-field jv-party" data-role="' +
      role +
      '">' +
      partyOptions(role, keepId) +
      '</select>';
    partyCell.setAttribute('data-party-type', role);
    var sel = partyCell.querySelector('select');
    if (sel) {
      sel.addEventListener('change', function () {
        partyCell.setAttribute('data-party-id', sel.value || '0');
        serialize();
      });
      partyCell.setAttribute('data-party-id', sel.value || '0');
    }
  }

  function addRow(data) {
    data = data || {};
    var tr = document.createElement('tr');
    var debitVal =
      data.debit !== '' && data.debit != null && Number(data.debit) > 0
        ? String(data.debit)
        : '';
    var creditVal =
      data.credit !== '' && data.credit != null && Number(data.credit) > 0
        ? String(data.credit)
        : '';

    tr.innerHTML =
      (editable
        ? '<td class="no-print"><button type="button" class="jv-remove" title="حذف السطر">✕</button></td>'
        : '<td class="no-print"></td>') +
      '<td>' +
      (editable
        ? '<select class="si-field jv-acc">' +
          accountOptions(data.account_id || 0) +
          '</select>'
        : '<input class="si-field" readonly value="' +
          escapeHtml(labelForAccount(data.account_id)) +
          '"><input type="hidden" class="jv-acc-hidden" value="' +
          (Number(data.account_id) || 0) +
          '">') +
      '</td>' +
      '<td class="jv-party-cell" data-party-type="' +
      escapeHtml(data.party_type || '') +
      '" data-party-id="' +
      (Number(data.party_id) || 0) +
      '"></td>' +
      '<td><input class="si-field jv-debit" dir="ltr" inputmode="decimal" value="' +
      escapeHtml(debitVal) +
      '"' +
      (editable ? '' : ' readonly') +
      '></td>' +
      '<td><input class="si-field jv-credit" dir="ltr" inputmode="decimal" value="' +
      escapeHtml(creditVal) +
      '"' +
      (editable ? '' : ' readonly') +
      '></td>' +
      '<td><input class="si-field jv-memo" value="' +
      escapeHtml(data.memo || '') +
      '" placeholder="تفصيل الحركة"' +
      (editable ? '' : ' readonly') +
      '></td>';

    tbody.appendChild(tr);

    if (editable) {
      var rem = tr.querySelector('.jv-remove');
      if (rem) {
        rem.addEventListener('click', function () {
          if (tbody.querySelectorAll('tr').length <= 2) {
            alert('يُفضّل الإبقاء على سطرين على الأقل.');
            return;
          }
          tr.remove();
          serialize();
        });
      }
      var acc = tr.querySelector('.jv-acc');
      if (acc) {
        acc.addEventListener('change', function () {
          syncPartyCell(tr);
          serialize();
        });
      }
      var debit = tr.querySelector('.jv-debit');
      var credit = tr.querySelector('.jv-credit');
      if (debit) {
        debit.addEventListener('input', function () {
          if (parseFloat(debit.value) > 0 && credit) credit.value = '';
          serialize();
        });
      }
      if (credit) {
        credit.addEventListener('input', function () {
          if (parseFloat(credit.value) > 0 && debit) debit.value = '';
          serialize();
        });
      }
      var memo = tr.querySelector('.jv-memo');
      if (memo) memo.addEventListener('input', serialize);
    }

    syncPartyCell(tr);
  }

  function labelForAccount(id) {
    var n = Number(id) || 0;
    for (var i = 0; i < accounts.length; i++) {
      if (Number(accounts[i].id) === n) {
        return (accounts[i].code || '') + ' — ' + (accounts[i].name || '');
      }
    }
    return '—';
  }

  function collectLines() {
    var rows = [];
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var accEl = tr.querySelector('.jv-acc') || tr.querySelector('.jv-acc-hidden');
      var accountId = accEl ? Number(accEl.value || 0) : 0;
      var partyCell = tr.querySelector('.jv-party-cell');
      var partySel = tr.querySelector('.jv-party');
      var partyType = '';
      var partyId = 0;
      if (partySel) {
        partyType = partySel.getAttribute('data-role') || '';
        partyId = Number(partySel.value || 0);
      } else if (partyCell) {
        partyType = partyCell.getAttribute('data-party-type') || '';
        partyId = Number(partyCell.getAttribute('data-party-id') || 0);
      }
      var debit = parseFloat((tr.querySelector('.jv-debit') || {}).value || '0') || 0;
      var credit = parseFloat((tr.querySelector('.jv-credit') || {}).value || '0') || 0;
      var memo = (tr.querySelector('.jv-memo') || {}).value || '';
      rows.push({
        account_id: accountId,
        debit: debit,
        credit: credit,
        memo: memo,
        party_type: partyType,
        party_id: partyId,
      });
    });
    return rows;
  }

  function serialize() {
    var lines = collectLines();
    if (linesJson) linesJson.value = JSON.stringify(lines);
    var sumD = 0;
    var sumC = 0;
    lines.forEach(function (ln) {
      sumD += Number(ln.debit) || 0;
      sumC += Number(ln.credit) || 0;
    });
    if (totalDebitEl) totalDebitEl.textContent = fmt(sumD);
    if (totalCreditEl) totalCreditEl.textContent = fmt(sumC);
    if (balanceHint) {
      var ok = Math.abs(sumD - sumC) < 0.000001;
      balanceHint.textContent = ok ? 'متوازن' : 'غير متوازن';
      balanceHint.className = ok ? 'jv-bal-ok' : 'jv-bal-bad';
    }
  }

  (cfg.lines && cfg.lines.length ? cfg.lines : [{}, {}]).forEach(function (ln) {
    addRow(ln);
  });
  serialize();

  if (addBtn) {
    addBtn.addEventListener('click', function () {
      addRow({});
      serialize();
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      serialize();
      var lines = collectLines().filter(function (ln) {
        return ln.account_id > 0 && (ln.debit > 0 || ln.credit > 0);
      });
      if (lines.length < 2) {
        e.preventDefault();
        alert('أضف سطرين على الأقل (مدين ودائن).');
        return;
      }
      var sumD = 0;
      var sumC = 0;
      for (var i = 0; i < lines.length; i++) {
        if (lines[i].debit > 0 && lines[i].credit > 0) {
          e.preventDefault();
          alert('كل سطر يجب أن يكون مديناً أو دائناً فقط.');
          return;
        }
        sumD += lines[i].debit;
        sumC += lines[i].credit;
        var role = partyRole(lines[i].account_id);
        if (role && !(lines[i].party_id > 0 && lines[i].party_type === role)) {
          e.preventDefault();
          alert(
            role === 'customer'
              ? 'اختر العميل للسطر على حساب الذمم المدينة.'
              : 'اختر المورد للسطر على حساب الذمم الدائنة.'
          );
          return;
        }
      }
      if (Math.abs(sumD - sumC) >= 0.000001) {
        e.preventDefault();
        alert('مجموع المدين يجب أن يساوي مجموع الدائن.');
      }
    });

    form.querySelectorAll('button[type=submit][name=_intent]').forEach(function (btn) {
      // buttons are outside form via form= attribute — not found here
    });
  }

  document.querySelectorAll('button[form=jv-form][name=_intent]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (intentEl) intentEl.value = btn.value || 'save';
    });
  });
})();
