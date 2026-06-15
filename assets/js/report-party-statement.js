(function () {
  'use strict';

  var win = typeof window !== 'undefined' ? window : self;

  function boot() {
    var page = document.querySelector('.party-stmt-page');
    if (!page) return;

    var form = document.getElementById('party-stmt-form');
    var typeHidden = document.getElementById('party_type_hidden');
    var partyIdField = document.getElementById('party_id_field');
    var custWrap = document.querySelector('.party-stmt-pick-customer');
    var suppWrap = document.querySelector('.party-stmt-pick-supplier');
    var custHidden = document.getElementById('party_stmt_cust_hidden');
    var suppHidden = document.getElementById('party_stmt_supp_hidden');
    var radios = form ? form.querySelectorAll('input[name="party_type_radio"]') : [];

    function syncPartyIdFromPickers() {
      if (!partyIdField || !typeHidden) return;
      var type = typeHidden.value === 'supplier' ? 'supplier' : 'customer';
      if (type === 'customer') {
        partyIdField.value = custHidden && custHidden.value ? custHidden.value : '';
      } else {
        partyIdField.value = suppHidden && suppHidden.value ? suppHidden.value : '';
      }
    }

    function syncPartyType(type) {
      type = type === 'supplier' ? 'supplier' : 'customer';
      if (typeHidden) typeHidden.value = type;
      if (custWrap) custWrap.style.display = type === 'customer' ? '' : 'none';
      if (suppWrap) suppWrap.style.display = type === 'supplier' ? '' : 'none';
      syncPartyIdFromPickers();
    }

    radios.forEach(function (r) {
      r.addEventListener('change', function () {
        if (r.checked) syncPartyType(r.value);
      });
    });

    if (custHidden) {
      custHidden.addEventListener('change', syncPartyIdFromPickers);
    }
    if (suppHidden) {
      suppHidden.addEventListener('change', syncPartyIdFromPickers);
    }

    if (form) {
      form.addEventListener('submit', function () {
        var checked = form.querySelector('input[name="party_type_radio"]:checked');
        syncPartyType(checked ? checked.value : 'customer');
      });
    }

    syncPartyType(typeHidden ? typeHidden.value : 'customer');
  }

  function start() {
    boot();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  win.addEventListener('load', function () {
    if (win.CustomerPickerModal && win.CustomerPickerModal.autoBindAll) {
      win.CustomerPickerModal.autoBindAll();
    }
    if (win.SupplierPickerModal && win.SupplierPickerModal.autoBindAll) {
      win.SupplierPickerModal.autoBindAll();
    }
  });
})();
