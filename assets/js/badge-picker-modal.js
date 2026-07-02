(function () {
  'use strict';

  var MODAL_ID = 'app-badge-picker-modal';
  var BACKDROP_ID = 'app-shared-picker-backdrop';
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
    return normalizeDigits(String(s || '')).trim().toLowerCase().replace(/\s+/g, ' ');
  }

  function normalizeDigits(value) {
    return String(value || '')
      .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
      .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
  }

  function getModal() {
    return document.getElementById(MODAL_ID);
  }

  function getSearch() {
    return document.getElementById('app-badge-picker-search');
  }

  function getResults() {
    return document.getElementById('app-badge-picker-results');
  }

  function closeOtherPickers() {
    if (window.EmployeePickerModal && EmployeePickerModal.close) {
      EmployeePickerModal.close();
    }
    if (window.CustomerPickerModal && CustomerPickerModal.close) {
      CustomerPickerModal.close();
    }
    if (window.ItemPickerModal && ItemPickerModal.close) {
      ItemPickerModal.close();
    }
  }

  function isOtherPickerOpen() {
    if (window.EmployeePickerModal && EmployeePickerModal.isOpen && EmployeePickerModal.isOpen()) {
      return true;
    }
    if (window.CustomerPickerModal && CustomerPickerModal.isOpen && CustomerPickerModal.isOpen()) {
      return true;
    }
    if (window.ItemPickerModal && ItemPickerModal.isOpen && ItemPickerModal.isOpen()) {
      return true;
    }
    return false;
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
      el.addEventListener('click', function () {
        closeOtherPickers();
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
      modal.style.width = 'min(440px, calc(100vw - 32px))';
      modal.style.maxHeight = 'min(85vh, calc(100vh - 32px))';
      modal.style.overflow = 'hidden';
      var results = getResults();
      if (results) {
        results.style.flex = '1 1 auto';
        results.style.minHeight = '160px';
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
      modal.style.width = '';
      modal.style.maxHeight = '';
      modal.style.overflow = '';
      if (!isOtherPickerOpen()) {
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

  function isOpen() {
    var modal = getModal();
    return !!(modal && modal.classList.contains('is-open'));
  }

  function badgeLabel(item) {
    if (!item) return '';
    return String(item.label || item.badge || '').trim();
  }

  function itemMatches(item, needle) {
    if (!needle) return true;
    var hay = norm(item.search || '');
    if (hay && hay.indexOf(needle) >= 0) return true;
    return (
      norm(item.badge).indexOf(needle) >= 0 ||
      norm(item.name).indexOf(needle) >= 0 ||
      norm(item.label).indexOf(needle) >= 0 ||
      norm(item.id).indexOf(needle) >= 0
    );
  }

  function findItem(binding, id) {
    var n = parseInt(id, 10);
    if (binding.allowNone && (n === 0 || id === 0 || id === '0' || id === '')) {
      return {
        id: 0,
        badge: '',
        name: binding.noneLabel || '— بلا بصمة —',
        label: binding.noneLabel || '— بلا بصمة —',
      };
    }
    if (n < 1) return null;
    return binding.byId[n] || null;
  }

  function updateDisplay(binding) {
    if (!binding.display) return;
    var raw = binding.hidden ? binding.hidden.value : '';
    var id = raw === '' ? 0 : parseInt(raw, 10);
    var item = findItem(binding, id);
    if (item && (item.id > 0 || binding.allowNone)) {
      binding.display.textContent = badgeLabel(item);
      binding.display.classList.toggle('is-placeholder', item.id === 0);
    } else {
      binding.display.textContent = binding.placeholder;
      binding.display.classList.add('is-placeholder');
    }
  }

  function setSelection(binding, item, silent) {
    if (!binding.hidden) return;
    if (item && parseInt(item.id, 10) > 0) {
      binding.hidden.value = String(item.id);
    } else {
      binding.hidden.value = '';
    }
    try {
      binding.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {}
    updateDisplay(binding);
    close();
    if (!silent && typeof binding.onSelect === 'function') {
      binding.onSelect(item || null);
    }
  }

  function renderResults(binding, q) {
    var results = getResults();
    if (!results || !binding) return;
    activeIndex = -1;
    var needle = norm(q);
    var matches = binding.list.filter(function (item) {
      return itemMatches(item, needle);
    });
    results.innerHTML = '';
    if (binding.allowNone && (!needle || norm(binding.noneLabel).indexOf(needle) >= 0)) {
      var noneBtn = document.createElement('button');
      noneBtn.type = 'button';
      noneBtn.className = 'sales-inv-pick-item sales-inv-cust-pick-item sales-inv-cust-pick-item--all';
      noneBtn.setAttribute('data-badge-id', '0');
      noneBtn.innerHTML =
        '<div class="sales-inv-pick-item-body"><span class="sales-inv-pick-item-name">' +
        escapeHtml(binding.noneLabel) +
        '</span></div>';
      noneBtn.addEventListener('click', function () {
        setSelection(binding, findItem(binding, 0));
      });
      results.appendChild(noneBtn);
    }
    if (!matches.length && results.childNodes.length === 0) {
      results.innerHTML =
        '<div class="sales-inv-pick-empty">' +
        escapeHtml(needle ? 'لا يوجد رقم بصمة مطابق' : 'لا توجد أرقام بصمة') +
        '</div>';
      return;
    }
    matches.slice(0, binding.maxResults).forEach(function (item) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      row.setAttribute('data-badge-id', String(item.id));
      var badge = String(item.badge || '').trim();
      var body = document.createElement('div');
      body.className = 'sales-inv-pick-item-body';
      body.innerHTML =
        '<span class="sales-inv-pick-item-name">' +
        escapeHtml(String(item.name || '—')) +
        '</span>' +
        (badge
          ? '<span class="sales-inv-pick-item-barcode">' + escapeHtml(badge) + '</span>'
          : '<span class="sales-inv-pick-item-barcode">' + escapeHtml('ZK #' + item.id) + '</span>');
      row.appendChild(body);
      row.addEventListener('click', function () {
        setSelection(binding, item);
      });
      results.appendChild(row);
    });
  }

  function loadListFromJson(jsonId) {
    var el = document.getElementById(jsonId);
    if (!el) return [];
    try {
      return JSON.parse(el.textContent || '[]');
    } catch (e) {
      return [];
    }
  }

  function open(binding) {
    if (!binding || (binding.getDisabled && binding.getDisabled())) return;
    closeOtherPickers();
    activeBinding = binding;
    var search = getSearch();
    if (search) search.value = '';
    setVisible(true);
    renderResults(binding, '');
    if (search) {
      setTimeout(function () {
        search.focus();
      }, 60);
    }
  }

  function buildBinding(opts) {
    var hidden =
      typeof opts.hidden === 'string' ? document.getElementById(opts.hidden) : opts.hidden;
    var openBtn = typeof opts.open === 'string' ? document.getElementById(opts.open) : opts.open;
    var display =
      typeof opts.display === 'string' ? document.getElementById(opts.display) : opts.display;
    if (!hidden || !openBtn || !display) return null;

    var list = opts.badges || [];
    if ((!list || !list.length) && opts.jsonId) {
      list = loadListFromJson(opts.jsonId);
    }
    var byId = {};
    list.forEach(function (item) {
      var id = parseInt(item.id, 10);
      if (id > 0) byId[id] = item;
    });

    var binding = {
      hidden: hidden,
      open: openBtn,
      display: display,
      list: list,
      byId: byId,
      jsonId: opts.jsonId || '',
      placeholder: opts.placeholder || '— بلا بصمة —',
      allowNone: opts.allowNone !== false,
      noneLabel: opts.noneLabel || '— بلا بصمة —',
      maxResults: parseInt(opts.maxResults, 10) || 120,
      onSelect: opts.onSelect || null,
      getDisabled: opts.getDisabled || null,
    };

    openBtn.addEventListener('click', function () {
      if (openBtn.disabled) return;
      open(binding);
    });

    var initialId = opts.initialId;
    if (initialId === undefined || initialId === null) {
      initialId = hidden.value !== '' ? parseInt(hidden.value, 10) : 0;
    }
    if (initialId === '' || initialId === 0) {
      if (binding.allowNone) {
        setSelection(binding, findItem(binding, 0), true);
      } else {
        hidden.value = '';
        updateDisplay(binding);
      }
    } else {
      var found = findItem(binding, initialId);
      if (found) {
        hidden.value = String(found.id);
        updateDisplay(binding);
      } else if (parseInt(initialId, 10) > 0) {
        hidden.value = String(parseInt(initialId, 10));
        updateDisplay(binding);
      }
    }

    return binding;
  }

  function bind(opts) {
    if (!installed) install();
    var binding = buildBinding(opts);
    if (!binding) return null;
    var key = binding.hidden.id || String(Object.keys(bindings).length);
    bindings[key] = binding;
    return {
      setById: function (id, silent) {
        var item = findItem(binding, id);
        if (item) {
          setSelection(binding, item, !!silent);
          return true;
        }
        binding.hidden.value = parseInt(id, 10) > 0 ? String(parseInt(id, 10)) : '';
        updateDisplay(binding);
        return false;
      },
      getLabel: function () {
        return badgeLabel(findItem(binding, binding.hidden.value === '' ? 0 : binding.hidden.value));
      },
    };
  }

  function install() {
    if (installed) return;
    installed = true;
    var closeBtn = document.getElementById('app-badge-picker-close');
    if (closeBtn) closeBtn.addEventListener('click', close);
    var search = getSearch();
    if (search) {
      search.addEventListener('input', function () {
        if (activeBinding) renderResults(activeBinding, search.value);
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var modal = getModal();
      if (modal && modal.classList.contains('is-open')) close();
    });
  }

  window.BadgePickerModal = {
    bind: bind,
    close: close,
    isOpen: isOpen,
  };
})();
