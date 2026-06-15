(function () {
  'use strict';

  var form = document.getElementById('fin-voucher-form');
  if (!form) return;

  var partyTypeSel = document.getElementById('fin-party-type');
  var partyIdField = document.getElementById('fin-party-id');
  var custWrap = document.getElementById('fin-pick-customer-wrap');
  var suppWrap = document.getElementById('fin-pick-supplier-wrap');

  function parseJson(id) {
    var el = document.getElementById(id);
    if (!el || !el.textContent) return [];
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return [];
    }
  }

  function initSupplierPicker(root, suppliers) {
    if (!root || !Array.isArray(suppliers)) return;
    var hidden = root.querySelector('[data-supp-id]');
    var input = root.querySelector('[data-supp-search]');
    var list = root.querySelector('[data-supp-list]');
    if (!hidden || !input || !list) return;

    var items = suppliers.map(function (s) {
      return {
        id: parseInt(s.id, 10) || 0,
        name_ar: String(s.name_ar || ''),
        code: String(s.code || ''),
      };
    });

    function norm(s) {
      return String(s || '')
        .trim()
        .toLowerCase();
    }

    function findById(id) {
      var n = parseInt(id, 10);
      if (n < 1) return null;
      for (var i = 0; i < items.length; i++) {
        if (items[i].id === n) return items[i];
      }
      return null;
    }

    function setSelection(sup) {
      if (sup) {
        hidden.value = String(sup.id);
        input.value = sup.name_ar + (sup.code ? ' (' + sup.code + ')' : '');
        if (partyTypeSel && partyTypeSel.value === 'supplier' && partyIdField) {
          partyIdField.value = String(sup.id);
        }
      } else {
        hidden.value = '';
        if (partyTypeSel && partyTypeSel.value === 'supplier' && partyIdField) {
          partyIdField.value = '';
        }
      }
      list.hidden = true;
    }

    var listNav = null;

    function renderList(q) {
      var needle = norm(q);
      var matches = items.filter(function (c) {
        if (!needle) return true;
        return norm(c.name_ar).indexOf(needle) >= 0 || norm(c.code).indexOf(needle) >= 0;
      });
      list.innerHTML = '';
      if (listNav) listNav.reset();
      if (!matches.length) {
        var empty = document.createElement('div');
        empty.className = 'report-cust-pick-empty';
        empty.textContent = 'لا يوجد مورد مطابق';
        list.appendChild(empty);
        list.hidden = false;
        return;
      }
      matches.slice(0, 40).forEach(function (c) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'report-cust-pick-opt';
        btn.setAttribute('data-id', String(c.id));
        btn.textContent = c.name_ar + (c.code ? ' (' + c.code + ')' : '');
        btn.addEventListener('click', function () {
          setSelection(c);
        });
        list.appendChild(btn);
      });
      list.hidden = false;
    }

    input.addEventListener('input', function () {
      renderList(input.value);
    });
    input.addEventListener('focus', function () {
      renderList(input.value);
    });

    if (window.AppListKeyboard) {
      listNav = AppListKeyboard.bindSearchDropdown({
        input: input,
        list: list,
        itemSelector: 'button.report-cust-pick-opt[data-id]',
        isOpen: function () {
          return !list.hidden;
        },
        ensureOpen: function () {
          renderList(input.value);
        },
        onEscape: function () {
          list.hidden = true;
        },
        onPick: function (btn) {
          var id = parseInt(btn.getAttribute('data-id'), 10);
          setSelection(findById(id));
        },
      });
    }

    document.addEventListener('click', function (ev) {
      if (!root.contains(ev.target)) list.hidden = true;
    });

    var initId = parseInt(hidden.value, 10);
    if (initId > 0) setSelection(findById(initId));
  }

  function syncPartyPickers() {
    var pt = partyTypeSel ? partyTypeSel.value : 'other';
    if (custWrap) custWrap.style.display = pt === 'customer' ? '' : 'none';
    if (suppWrap) suppWrap.style.display = pt === 'supplier' ? '' : 'none';
    if (partyIdField) {
      if (pt === 'customer') {
        var ch = document.getElementById('fin_cust_hidden');
        partyIdField.value = ch && ch.value ? ch.value : '';
      } else if (pt === 'supplier') {
        var sh = document.querySelector('#fin-supp-pick [data-supp-id]');
        partyIdField.value = sh && sh.value ? sh.value : '';
      } else {
        partyIdField.value = '';
      }
    }
  }

  if (partyTypeSel) {
    partyTypeSel.addEventListener('change', syncPartyPickers);
  }

  var customers = parseJson('fin-customers-json');
  var suppliers = parseJson('fin-suppliers-json');
  var suppRoot = document.getElementById('fin-supp-pick');

  if (window.CustomerPickerModal) {
    CustomerPickerModal.bind({
      hidden: 'fin_cust_hidden',
      open: 'fin_cust_hidden_open',
      display: 'fin_cust_hidden_display',
      jsonId: 'fin-customers-json',
      onSelect: function () {
        syncPartyPickers();
      },
    });
    var custHidden = document.getElementById('fin_cust_hidden');
    if (custHidden) {
      custHidden.addEventListener('change', syncPartyPickers);
    }
  }

  initSupplierPicker(suppRoot, suppliers);
  syncPartyPickers();

  form.addEventListener('submit', function () {
    syncPartyPickers();
  });
})();
