(function () {
  'use strict';

  var MODAL_ID = 'app-customer-picker-modal';
  var BACKDROP_ID = 'app-shared-picker-backdrop';
  var LEGACY_BACKDROP_IDS = ['app-customer-picker-backdrop', 'app-item-picker-backdrop'];
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
    return document.getElementById('app-customer-picker-search');
  }

  function getResults() {
    return document.getElementById('app-customer-picker-results');
  }

  function hideLegacyBackdrops() {
    LEGACY_BACKDROP_IDS.forEach(function (id) {
      var legacy = document.getElementById(id);
      if (!legacy) return;
      legacy.classList.remove('is-open');
      legacy.hidden = true;
      legacy.setAttribute('aria-hidden', 'true');
    });
  }

  function isItemPickerOpen() {
    var itemModal = document.getElementById('app-item-picker-modal');
    return !!(itemModal && itemModal.classList.contains('is-open'));
  }

  function ensureBackdrop() {
    hideLegacyBackdrops();
    var el = document.getElementById(BACKDROP_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = BACKDROP_ID;
      el.className = 'sales-inv-pick-backdrop';
      el.hidden = true;
      el.setAttribute('aria-hidden', 'true');
      document.body.appendChild(el);
      el.addEventListener('click', function () {
        if (window.ItemPickerModal && ItemPickerModal.isOpen()) {
          ItemPickerModal.close();
        }
        close();
      });
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
      modal.style.transform = '';
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
      if (!isItemPickerOpen()) {
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('hidden', '');
        backdrop.setAttribute('aria-hidden', 'true');
      }
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
      results.querySelectorAll('.sales-inv-cust-pick-item[data-customer-id]')
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
    var id = parseInt(btn.getAttribute('data-customer-id'), 10);
    if (isNaN(id)) return;
    var c = findCustomer(activeBinding, id);
    if (c) {
      setSelection(activeBinding, c);
    }
  }

  function isOpen() {
    var modal = getModal();
    return !!(modal && modal.classList.contains('is-open'));
  }

  function findCustomer(binding, id) {
    var n = parseInt(id, 10);
    if (binding.allowAll && n === 0) {
      return { id: 0, name_ar: binding.allLabel || 'جميع العملاء', code: '' };
    }
    if (n < 1) return null;
    return binding.byId[n] || null;
  }

  function updateDisplay(binding) {
    if (!binding.display) return;
    var id = binding.hidden ? parseInt(binding.hidden.value, 10) : 0;
    var c = findCustomer(binding, id);
    if (c) {
      binding.display.textContent = c.name_ar;
      binding.display.classList.remove('is-placeholder');
    } else {
      binding.display.textContent = binding.placeholder;
      binding.display.classList.add('is-placeholder');
    }
  }

  function setSelection(binding, cust, silent) {
    if (!binding.hidden) return;
    if (cust) {
      binding.hidden.value = String(cust.id);
    } else {
      binding.hidden.value = '';
    }
    try {
      binding.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {}
    updateDisplay(binding);
    close();
    if (!silent && typeof binding.onSelect === 'function') {
      binding.onSelect(cust || null);
    }
  }

  function renderResults(binding, q) {
    var results = getResults();
    if (!results || !binding) return;
    activeIndex = -1;
    var needle = norm(q);
    var matches = binding.list.filter(function (c) {
      if (!needle) return true;
      return (
        norm(c.name_ar).indexOf(needle) >= 0 || norm(c.code).indexOf(needle) >= 0
      );
    });
    results.innerHTML = '';
    if (binding.allowAll && (!needle || norm(binding.allLabel).indexOf(needle) >= 0)) {
      var allBtn = document.createElement('button');
      allBtn.type = 'button';
      allBtn.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      allBtn.setAttribute('data-customer-id', '0');
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
        escapeHtml(needle ? 'لا يوجد عميل مطابق' : 'لا يوجد عملاء') +
        '</div>';
      return;
    }
    matches.slice(0, binding.maxResults).forEach(function (c) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      row.setAttribute('data-customer-id', String(c.id));
      var code = String(c.code || '').trim();
      var body = document.createElement('div');
      body.className = 'sales-inv-pick-item-body';
      body.innerHTML =
        '<span class="sales-inv-pick-item-name">' +
        escapeHtml(c.name_ar) +
        '</span>' +
        (code
          ? '<span class="sales-inv-pick-item-barcode">' + escapeHtml(code) + '</span>'
          : '');
      row.appendChild(body);
      row.addEventListener('click', function () {
        setSelection(binding, c);
      });
      results.appendChild(row);
    });
  }

  function reloadBindingList(binding) {
    if (!binding) return;
    var list = binding.list || [];
    if (binding.jsonId) {
      var fresh = loadCustomersFromJson(binding.jsonId);
      if (fresh && fresh.length) {
        list = fresh;
      }
    }
    binding.list = list;
    binding.byId = {};
    list.forEach(function (c) {
      var id = parseInt(c.id, 10);
      if (id > 0) binding.byId[id] = c;
    });
  }

  function open(binding) {
    if (!binding || (binding.getDisabled && binding.getDisabled())) return;
    install();
    if (window.ItemPickerModal) {
      ItemPickerModal.close();
    }
    hideLegacyBackdrops();
    reloadBindingList(binding);
    activeBinding = binding;
    var search = getSearch();
    if (search) {
      search.value = '';
    }
    setVisible(true);
    renderResults(binding, search ? search.value : '');
    if (search) {
      setTimeout(function () {
        search.focus();
      }, 60);
    }
  }

  function loadCustomersFromJson(jsonId) {
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

    var list = opts.customers || [];
    if ((!list || !list.length) && opts.jsonId) {
      list = loadCustomersFromJson(opts.jsonId);
    }
    var byId = {};
    list.forEach(function (c) {
      var id = parseInt(c.id, 10);
      if (id > 0) byId[id] = c;
    });

    var binding = {
      hidden: hidden,
      open: openBtn,
      display: display,
      list: list,
      byId: byId,
      jsonId: opts.jsonId || '',
      placeholder: opts.placeholder || 'اضغط لاختيار العميل',
      allowAll: !!opts.allowAll,
      allLabel: opts.allLabel || 'جميع العملاء',
      maxResults: parseInt(opts.maxResults, 10) || 120,
      onSelect: opts.onSelect || null,
      getDisabled: opts.getDisabled || null,
    };

    openBtn.addEventListener('click', function () {
      open(binding);
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
    var c = findCustomer(binding, id);
    if (c) {
      setSelection(binding, c, !!silent);
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
    var key = binding.hidden.id || String(bindings.length);
    bindings[key] = binding;
    return {
      setById: function (id, silent) {
        return apiSetById(binding, id, silent);
      },
      getName: function () {
        var cid = parseInt(binding.hidden.value, 10);
        var c = findCustomer(binding, cid);
        return c ? c.name_ar : '';
      },
      getCustomer: function () {
        return findCustomer(binding, parseInt(binding.hidden.value, 10));
      },
      open: function () {
        open(binding);
      },
      close: close,
    };
  }

  function bindFromSlot(slot) {
    if (!slot || slot.getAttribute('data-customer-picker-bound') === '1') return null;
    var hiddenId = slot.getAttribute('data-hidden-id') || '';
    var openId = slot.getAttribute('data-open-id') || '';
    var displayId = slot.getAttribute('data-display-id') || '';
    var jsonId = slot.getAttribute('data-json-id') || 'app-customers-json';
    var placeholder = slot.getAttribute('data-placeholder') || 'اضغط لاختيار العميل';
    var allowAll = slot.getAttribute('data-allow-all') === '1';
    var initial = parseInt(slot.getAttribute('data-initial') || '0', 10);
    var api = bind({
      hidden: hiddenId,
      open: openId,
      display: displayId,
      jsonId: jsonId,
      placeholder: placeholder,
      allowAll: allowAll,
      initialId: initial,
    });
    slot.setAttribute('data-customer-picker-bound', '1');
    return api;
  }

  function install() {
    if (installed) return;
    installed = true;
    var closeBtn = document.getElementById('app-customer-picker-close');
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
    document.querySelectorAll('[data-customer-picker]').forEach(function (slot) {
      bindFromSlot(slot);
    });
  }

  function getLabel(hiddenId) {
    var b = bindings[hiddenId];
    if (!b) return '';
    var c = findCustomer(b, parseInt(b.hidden.value, 10));
    return c ? c.name_ar : '';
  }

  window.CustomerPickerModal = {
    install: install,
    bind: bind,
    bindFromSlot: bindFromSlot,
    autoBindAll: autoBindAll,
    close: close,
    isOpen: isOpen,
    getLabel: getLabel,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoBindAll);
  } else {
    autoBindAll();
  }
})();
