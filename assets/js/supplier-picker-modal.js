(function () {
  'use strict';

  var MODAL_ID = 'app-supplier-picker-modal';
  var BACKDROP_ID = 'app-supplier-picker-backdrop';
  var bindings = {};
  var activeBinding = null;
  var installed = false;
  var activeIndex = -1;

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function norm(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  function getModal() {
    return document.getElementById(MODAL_ID);
  }

  function getSearch() {
    return document.getElementById('app-supplier-picker-search');
  }

  function getResults() {
    return document.getElementById('app-supplier-picker-results');
  }

  function ensureBackdrop() {
    var el = document.getElementById(BACKDROP_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = BACKDROP_ID;
      el.className = 'sales-inv-pick-backdrop';
      el.hidden = true;
      el.setAttribute('aria-hidden', 'true');
      document.body.appendChild(el);
      el.addEventListener('click', close);
    }
    return el;
  }

  function setVisible(visible) {
    var modal = getModal();
    if (!modal) return;
    var backdrop = ensureBackdrop();
    if (visible) {
      document.body.appendChild(modal);
      modal.removeAttribute('hidden');
      modal.classList.add('is-open', 'is-screen-center');
      modal.style.display = 'flex';
      modal.style.flexDirection = 'column';
      modal.style.position = 'fixed';
      modal.style.zIndex = '100602';
      modal.style.top = '';
      modal.style.left = '';
      modal.style.right = '';
      modal.style.bottom = '';
      modal.style.transform = '';
      modal.style.margin = '';
      modal.style.width = 'min(440px, calc(100vw - 32px))';
      modal.style.maxHeight = 'min(85vh, calc(100vh - 32px))';
      modal.style.overflow = 'hidden';
      var results = getResults();
      if (results) {
        results.style.flex = '1 1 0';
        results.style.minHeight = '0';
        results.style.maxHeight = 'min(420px, calc(85vh - 120px))';
        results.style.overflowY = 'auto';
      }
      backdrop.removeAttribute('hidden');
      backdrop.classList.add('is-open');
      backdrop.setAttribute('aria-hidden', 'false');
    } else {
      modal.classList.remove('is-open', 'is-screen-center');
      modal.setAttribute('hidden', '');
      modal.style.display = '';
      modal.style.flexDirection = '';
      modal.style.position = '';
      modal.style.zIndex = '';
      modal.style.top = '';
      modal.style.left = '';
      modal.style.right = '';
      modal.style.bottom = '';
      modal.style.transform = '';
      modal.style.margin = '';
      modal.style.width = '';
      modal.style.maxHeight = '';
      modal.style.overflow = '';
      var resultsClose = getResults();
      if (resultsClose) {
        resultsClose.style.flex = '';
        resultsClose.style.minHeight = '';
        resultsClose.style.maxHeight = '';
        resultsClose.style.overflowY = '';
      }
      backdrop.classList.remove('is-open');
      backdrop.setAttribute('hidden', '');
      backdrop.setAttribute('aria-hidden', 'true');
      activeBinding = null;
    }
  }

  function close() {
    activeIndex = -1;
    setVisible(false);
  }

  function getPickableButtons() {
    var results = getResults();
    if (!results) return [];
    return Array.prototype.slice.call(
      results.querySelectorAll('.sales-inv-cust-pick-item[data-supplier-id]')
    );
  }

  function highlightActive() {
    var buttons = getPickableButtons();
    buttons.forEach(function (btn, i) {
      btn.classList.toggle('is-active', i === activeIndex);
    });
    if (activeIndex >= 0 && buttons[activeIndex]) {
      buttons[activeIndex].scrollIntoView({ block: 'nearest' });
    }
  }

  function selectPickableButton(btn) {
    if (!activeBinding || !btn) return;
    var id = parseInt(btn.getAttribute('data-supplier-id'), 10);
    if (isNaN(id)) return;
    var s = findSupplier(activeBinding, id);
    if (s) setSelection(activeBinding, s);
  }

  function isOpen() {
    var modal = getModal();
    return !!(modal && modal.classList.contains('is-open'));
  }

  function findSupplier(binding, id) {
    var n = parseInt(id, 10);
    if (binding.allowAll && n === 0) {
      return { id: 0, name_ar: binding.allLabel || 'جميع الموردين', code: '' };
    }
    if (n < 1) return null;
    return binding.byId[n] || null;
  }

  function updateDisplay(binding) {
    if (!binding.display) return;
    var id = binding.hidden ? parseInt(binding.hidden.value, 10) : 0;
    var s = findSupplier(binding, id);
    if (s) {
      binding.display.textContent = s.name_ar;
      binding.display.classList.remove('is-placeholder');
    } else {
      binding.display.textContent = binding.placeholder;
      binding.display.classList.add('is-placeholder');
    }
  }

  function setSelection(binding, sup, silent) {
    if (!binding.hidden) return;
    if (sup) {
      binding.hidden.value = String(sup.id);
    } else {
      binding.hidden.value = '';
    }
    try {
      binding.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {}
    updateDisplay(binding);
    if (!silent) {
      close();
    }
    if (!silent && typeof binding.onSelect === 'function') {
      binding.onSelect(sup || null);
    }
  }

  function renderResults(binding, q) {
    var results = getResults();
    if (!results || !binding) return;
    activeIndex = -1;
    var needle = norm(q);
    var matches = binding.list.filter(function (s) {
      if (!needle) return true;
      return (
        norm(s.name_ar).indexOf(needle) >= 0 || norm(s.code).indexOf(needle) >= 0
      );
    });
    results.innerHTML = '';
    if (binding.allowAll && (!needle || norm(binding.allLabel).indexOf(needle) >= 0)) {
      var allBtn = document.createElement('button');
      allBtn.type = 'button';
      allBtn.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      allBtn.setAttribute('data-supplier-id', '0');
      allBtn.innerHTML =
        '<div class="sales-inv-pick-item-body"><span class="sales-inv-pick-item-name">' +
        escapeHtml(binding.allLabel) +
        '</span></div>';
      allBtn.addEventListener('click', function () {
        setSelection(binding, { id: 0, name_ar: binding.allLabel, code: '' });
      });
      results.appendChild(allBtn);
    }
    if (!matches.length && results.childNodes.length === 0) {
      results.innerHTML =
        '<div class="sales-inv-pick-empty">' +
        escapeHtml(needle ? 'لا يوجد مورد مطابق' : 'لا يوجد موردون') +
        '</div>';
      return;
    }
    matches.slice(0, binding.maxResults).forEach(function (s) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      row.setAttribute('data-supplier-id', String(s.id));
      var code = String(s.code || '').trim();
      var body = document.createElement('div');
      body.className = 'sales-inv-pick-item-body';
      body.innerHTML =
        '<span class="sales-inv-pick-item-name">' +
        escapeHtml(s.name_ar) +
        '</span>' +
        (code
          ? '<span class="sales-inv-pick-item-barcode">' + escapeHtml(code) + '</span>'
          : '');
      row.appendChild(body);
      row.addEventListener('click', function () {
        setSelection(binding, s);
      });
      results.appendChild(row);
    });
  }

  function openPicker(binding) {
    if (!binding || (binding.getDisabled && binding.getDisabled())) return;
    activeBinding = binding;
    var search = getSearch();
    renderResults(binding, '');
    setVisible(true);
    if (search) {
      search.value = '';
      setTimeout(function () {
        search.focus();
      }, 60);
    }
  }

  function loadSuppliersFromJson(jsonId) {
    var el = document.getElementById(jsonId);
    if (!el) return [];
    try {
      return JSON.parse(el.textContent || '[]');
    } catch (e) {
      return [];
    }
  }

  function buildBinding(opts) {
    var hidden =
      typeof opts.hidden === 'string'
        ? document.getElementById(opts.hidden)
        : opts.hidden;
    var openBtn =
      typeof opts.open === 'string' ? document.getElementById(opts.open) : opts.open;
    var display =
      typeof opts.display === 'string'
        ? document.getElementById(opts.display)
        : opts.display;
    if (!hidden || !openBtn || !display) return null;

    var list = opts.suppliers || [];
    if ((!list || !list.length) && opts.jsonId) {
      list = loadSuppliersFromJson(opts.jsonId);
    }
    var byId = {};
    list.forEach(function (s) {
      var id = parseInt(s.id, 10);
      if (id > 0) byId[id] = s;
    });

    var binding = {
      hidden: hidden,
      open: openBtn,
      display: display,
      list: list,
      byId: byId,
      placeholder: opts.placeholder || 'اضغط لاختيار المورد',
      allowAll: !!opts.allowAll,
      allLabel: opts.allLabel || 'جميع الموردين',
      maxResults: parseInt(opts.maxResults, 10) || 120,
      onSelect: opts.onSelect || null,
      getDisabled: opts.getDisabled || null,
    };

    openBtn.addEventListener('click', function () {
      openPicker(binding);
    });

    bindings[hidden.id || ''] = binding;

    var initialId = opts.initialId;
    if (initialId === undefined || initialId === null || initialId === '') {
      initialId = hidden.value !== '' ? parseInt(hidden.value, 10) : 0;
    }
    apiSetById(binding, initialId, true);

    return binding;
  }

  function apiSetById(binding, id, silent) {
    var s = findSupplier(binding, id);
    if (s) {
      setSelection(binding, s, !!silent);
      return true;
    }
    if (binding.hidden) {
      var n = parseInt(id, 10);
      if (binding.allowAll && n === 0) {
        binding.hidden.value = '0';
      } else if (n > 0) {
        binding.hidden.value = String(n);
      } else {
        binding.hidden.value = '';
      }
    }
    updateDisplay(binding);
    if (!silent && typeof binding.onSelect === 'function') {
      binding.onSelect(null);
    }
    return false;
  }

  function bind(opts) {
    if (!installed) install();
    var binding = buildBinding(opts);
    if (!binding) return null;
    var key = binding.hidden.id || String(Object.keys(bindings).length);
    bindings[key] = binding;
    return {
      setById: function (id, silent) {
        return apiSetById(binding, id, silent);
      },
      getName: function () {
        var sid = parseInt(binding.hidden.value, 10);
        var s = findSupplier(binding, sid);
        return s ? s.name_ar : '';
      },
      getSupplier: function () {
        return findSupplier(binding, parseInt(binding.hidden.value, 10));
      },
      open: function () {
        openPicker(binding);
      },
      close: close,
    };
  }

  function bindFromSlot(slot) {
    if (!slot || slot.getAttribute('data-supplier-picker-bound') === '1') return null;
    var allowAll = slot.getAttribute('data-allow-all') === '1';
    var api = bind({
      hidden: slot.getAttribute('data-hidden-id') || '',
      open: slot.getAttribute('data-open-id') || '',
      display: slot.getAttribute('data-display-id') || '',
      jsonId: slot.getAttribute('data-json-id') || 'app-suppliers-json',
      placeholder: slot.getAttribute('data-placeholder') || 'اضغط لاختيار المورد',
      allowAll: allowAll,
      initialId: parseInt(slot.getAttribute('data-initial') || '0', 10),
    });
    slot.setAttribute('data-supplier-picker-bound', '1');
    return api;
  }

  function install() {
    if (installed) return;
    installed = true;
    var closeBtn = document.getElementById('app-supplier-picker-close');
    if (closeBtn) closeBtn.addEventListener('click', close);
    var search = getSearch();
    if (search) {
      search.addEventListener('input', function () {
        if (activeBinding) renderResults(activeBinding, search.value);
      });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
          return;
        }
        if (!isOpen() || !activeBinding) return;

        var buttons = getPickableButtons();
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (!buttons.length) return;
          activeIndex = activeIndex < buttons.length - 1 ? activeIndex + 1 : 0;
          highlightActive();
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (!buttons.length) return;
          activeIndex = activeIndex > 0 ? activeIndex - 1 : buttons.length - 1;
          highlightActive();
          return;
        }
        if (e.key === 'Enter') {
          e.preventDefault();
          if (!buttons.length) return;
          var pick =
            activeIndex >= 0 && buttons[activeIndex]
              ? buttons[activeIndex]
              : buttons[0];
          selectPickableButton(pick);
        }
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var modal = getModal();
      if (modal && modal.classList.contains('is-open')) close();
    });
  }

  function autoBindAll() {
    install();
    document.querySelectorAll('[data-supplier-picker]').forEach(function (slot) {
      bindFromSlot(slot);
    });
  }

  function getLabel(hiddenId) {
    var b = bindings[hiddenId];
    if (!b) return '';
    var s = findSupplier(b, parseInt(b.hidden.value, 10));
    return s ? s.name_ar : '';
  }

  window.SupplierPickerModal = {
    install: install,
    bind: bind,
    bindFromSlot: bindFromSlot,
    autoBindAll: autoBindAll,
    close: close,
    getLabel: getLabel,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoBindAll);
  } else {
    autoBindAll();
  }
})();
