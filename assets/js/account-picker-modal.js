(function () {
  'use strict';

  var MODAL_ID = 'app-account-picker-modal';
  var BACKDROP_ID = 'app-shared-picker-backdrop';
  var bindings = {};
  var activeBinding = null;
  var installed = false;
  var activeIndex = -1;
  var searchRequestId = 0;
  var searchDebounceTimer = null;

  function getSearchApiUrl() {
    var modal = getModal();
    if (!modal) return 'api/accounts_search.php';
    return modal.getAttribute('data-search-api') || 'api/accounts_search.php';
  }

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

  function digitsOnly(s) {
    return String(s || '').replace(/\D/g, '');
  }

  function getModal() {
    return document.getElementById(MODAL_ID);
  }

  function getSearch() {
    return document.getElementById('app-account-picker-search');
  }

  function getResults() {
    return document.getElementById('app-account-picker-results');
  }

  function ensureBackdrop() {
    var el = document.getElementById(BACKDROP_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = BACKDROP_ID;
      el.className = 'sales-inv-pick-backdrop';
      el.hidden = true;
      document.body.appendChild(el);
      el.addEventListener('click', function () {
        if (window.CustomerPickerModal && CustomerPickerModal.isOpen()) {
          CustomerPickerModal.close();
        }
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
      results.querySelectorAll('button.sales-inv-cust-pick-item')
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
    if (btn.getAttribute('data-account-clear') === '1') {
      setSelection(activeBinding, null);
      return;
    }
    var id = parseInt(btn.getAttribute('data-account-id'), 10);
    if (id > 0) {
      var a = findAccount(activeBinding, id);
      if (a) setSelection(activeBinding, a);
    }
  }

  function isOpen() {
    var modal = getModal();
    return !!(modal && modal.classList.contains('is-open'));
  }

  function accountLabel(a) {
    var code = String(a.code || '').trim();
    var name = String(a.name_ar || '').trim();
    return code && name ? code + ' — ' + name : name || code || '—';
  }

  function findAccount(binding, id) {
    var n = parseInt(id, 10);
    if (n < 1) return null;
    return binding.byId[n] || null;
  }

  function updateDisplay(binding) {
    if (!binding.display) return;
    var id = binding.hidden ? parseInt(binding.hidden.value, 10) : 0;
    var a = findAccount(binding, id);
    if (a) {
      binding.display.textContent = accountLabel(a);
      binding.display.classList.remove('is-placeholder');
    } else {
      binding.display.textContent = binding.placeholder;
      binding.display.classList.add('is-placeholder');
    }
  }

  function setSelection(binding, account, silent) {
    if (!binding.hidden) return;
    if (account && parseInt(account.id, 10) > 0) {
      binding.hidden.value = String(account.id);
    } else {
      binding.hidden.value = '';
    }
    try {
      binding.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {}
    updateDisplay(binding);
    close();
    if (!silent && typeof binding.onSelect === 'function') {
      binding.onSelect(account || null);
    }
  }

  function matchesAccount(a, needle, needleDigits) {
    if (!needle && !needleDigits) return true;
    var name = norm(a.name_ar);
    var code = norm(a.code);
    var codeDigits = digitsOnly(a.code);
    if (needle && (name.indexOf(needle) >= 0 || code.indexOf(needle) >= 0)) {
      return true;
    }
    if (needleDigits && codeDigits.indexOf(needleDigits) >= 0) {
      return true;
    }
    return false;
  }

  function mergeAccountsIntoBinding(binding, accounts) {
    (accounts || []).forEach(function (a) {
      var id = parseInt(a.id, 10);
      if (id < 1) return;
      binding.byId[id] = a;
    });
  }

  function renderResults(binding, q, accountsOverride) {
    var results = getResults();
    if (!results || !binding) return;
    activeIndex = -1;
    var needle = norm(q);
    var needleDigits = digitsOnly(q);
    var matches;
    if (accountsOverride) {
      matches = accountsOverride.slice();
      mergeAccountsIntoBinding(binding, matches);
    } else if (needle || needleDigits) {
      matches = binding.list.filter(function (a) {
        return matchesAccount(a, needle, needleDigits);
      });
    } else {
      matches = binding.list.slice();
    }
    results.innerHTML = '';

    if (binding.allowClear && (!needle || norm('غير مربوط').indexOf(needle) >= 0)) {
      var clearBtn = document.createElement('button');
      clearBtn.type = 'button';
      clearBtn.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      clearBtn.setAttribute('data-account-clear', '1');
      clearBtn.innerHTML =
        '<div class="sales-inv-pick-item-body"><span class="sales-inv-pick-item-name">— غير مربوط —</span></div>';
      clearBtn.addEventListener('click', function () {
        setSelection(binding, null);
      });
      results.appendChild(clearBtn);
    }

    if (!matches.length && results.childNodes.length === 0) {
      results.innerHTML =
        '<div class="sales-inv-pick-empty">' +
        escapeHtml(needle || needleDigits ? 'لا يوجد حساب مطابق' : 'لا توجد حسابات نهائية في الشجرة') +
        '</div>';
      return;
    }

    var maxShow = needle || needleDigits ? Math.max(binding.maxResults, 200) : binding.maxResults;
    matches.slice(0, maxShow).forEach(function (a) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      row.setAttribute('data-account-id', String(a.id));
      var code = String(a.code || '').trim();
      var body = document.createElement('div');
      body.className = 'sales-inv-pick-item-body';
      body.innerHTML =
        '<span class="sales-inv-pick-item-name">' +
        escapeHtml(a.name_ar) +
        '</span>' +
        (code
          ? '<span class="sales-inv-pick-item-barcode">' + escapeHtml(code) + '</span>'
          : '');
      row.appendChild(body);
      row.addEventListener('click', function () {
        setSelection(binding, a);
      });
      results.appendChild(row);
    });
  }

  function fetchAccountsSearch(binding, q) {
    var results = getResults();
    var reqId = ++searchRequestId;
    var url = getSearchApiUrl() + '?q=' + encodeURIComponent(q) + '&limit=120';
    if (binding && binding.searchWithMovements) {
      url += '&with_movements=1';
    }
    if (binding && binding.searchForMapping) {
      url += '&for_mapping=1';
    }
    if (results) {
      results.innerHTML = '<div class="sales-inv-pick-empty">جاري البحث…</div>';
    }
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (reqId !== searchRequestId || activeBinding !== binding) return;
        if (!data || !data.ok) {
          renderResults(binding, q);
          return;
        }
        renderResults(binding, q, data.accounts || []);
      })
      .catch(function () {
        if (reqId !== searchRequestId || activeBinding !== binding) return;
        renderResults(binding, q);
      });
  }

  function scheduleAccountSearch(binding, q) {
    if (searchDebounceTimer) {
      clearTimeout(searchDebounceTimer);
    }
    var trimmed = String(q || '').trim();
    if (!trimmed) {
      searchRequestId++;
      renderResults(binding, '');
      return;
    }
    searchDebounceTimer = setTimeout(function () {
      fetchAccountsSearch(binding, trimmed);
    }, 180);
  }

  function loadAccountsFromJson(jsonId) {
    var el = document.getElementById(jsonId);
    if (!el) return [];
    try {
      return JSON.parse(el.textContent || '[]');
    } catch (e) {
      return [];
    }
  }

  function reloadBindingList(binding) {
    var list = binding.list || [];
    if (binding.jsonId) {
      var fresh = loadAccountsFromJson(binding.jsonId);
      if (fresh && fresh.length) list = fresh;
    }
    binding.list = list;
    binding.byId = {};
    list.forEach(function (a) {
      var id = parseInt(a.id, 10);
      if (id > 0) binding.byId[id] = a;
    });
  }

  function open(binding) {
    if (!binding) return;
    install();
    if (window.CustomerPickerModal && CustomerPickerModal.isOpen()) {
      CustomerPickerModal.close();
    }
    reloadBindingList(binding);
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
    var openBtn =
      typeof opts.open === 'string' ? document.getElementById(opts.open) : opts.open;
    var display =
      typeof opts.display === 'string' ? document.getElementById(opts.display) : opts.display;
    if (!hidden || !openBtn || !display) return null;

    var list = opts.accounts || [];
    if ((!list || !list.length) && opts.jsonId) {
      list = loadAccountsFromJson(opts.jsonId);
    }
    var byId = {};
    list.forEach(function (a) {
      var id = parseInt(a.id, 10);
      if (id > 0) byId[id] = a;
    });

    var binding = {
      hidden: hidden,
      open: openBtn,
      display: display,
      list: list,
      byId: byId,
      jsonId: opts.jsonId || 'app-accounts-json',
      placeholder: opts.placeholder || 'اضغط لاختيار حساب',
      allowClear: opts.allowClear !== false,
      maxResults: parseInt(opts.maxResults, 10) || 150,
      searchWithMovements: opts.searchWithMovements === true,
      searchForMapping: opts.searchForMapping === true,
      onSelect: opts.onSelect || null,
    };

    openBtn.addEventListener('click', function () {
      open(binding);
    });

    var initialId = opts.initialId;
    if (initialId === undefined || initialId === null || initialId === '') {
      initialId = hidden.value !== '' ? parseInt(hidden.value, 10) : 0;
    }
    apiSetById(binding, initialId, true);

    return binding;
  }

  function apiSetById(binding, id, silent) {
    var a = findAccount(binding, id);
    if (a && binding.hidden) {
      binding.hidden.value = String(a.id);
      updateDisplay(binding);
      if (!silent && typeof binding.onSelect === 'function') {
        binding.onSelect(a);
      }
      return true;
    }
    if (binding.hidden) {
      binding.hidden.value = '';
    }
    updateDisplay(binding);
    if (!silent && typeof binding.onSelect === 'function') {
      binding.onSelect(null);
    }
    return false;
  }

  function bind(opts) {
    install();
    return buildBinding(opts);
  }

  function bindFromSlot(slot) {
    if (!slot || slot.getAttribute('data-account-picker-bound') === '1') return null;
    var api = bind({
      hidden: slot.getAttribute('data-hidden-id') || '',
      open: slot.getAttribute('data-open-id') || '',
      display: slot.getAttribute('data-display-id') || '',
      jsonId: slot.getAttribute('data-json-id') || 'app-accounts-json',
      placeholder: slot.getAttribute('data-placeholder') || 'اضغط لاختيار حساب',
      allowClear: slot.getAttribute('data-allow-clear') !== '0',
      initialId: slot.getAttribute('data-initial') || '',
      searchWithMovements: slot.getAttribute('data-search-with-movements') === '1',
      searchForMapping: slot.getAttribute('data-search-for-mapping') === '1',
    });
    slot.setAttribute('data-account-picker-bound', '1');
    return api;
  }

  function install() {
    if (installed) return;
    installed = true;
    var closeBtn = document.getElementById('app-account-picker-close');
    if (closeBtn) closeBtn.addEventListener('click', close);
    var search = getSearch();
    if (search) {
      search.addEventListener('input', function () {
        if (activeBinding) scheduleAccountSearch(activeBinding, search.value);
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
      if (e.key === 'Escape' && isOpen()) close();
    });
  }

  function autoBindAll() {
    install();
    document.querySelectorAll('[data-account-picker]').forEach(function (slot) {
      bindFromSlot(slot);
    });
  }

  window.AccountPickerModal = {
    install: install,
    bind: bind,
    bindFromSlot: bindFromSlot,
    autoBindAll: autoBindAll,
    close: close,
    isOpen: isOpen,
    setById: function (binding, accountId) {
      if (binding && binding.hidden) {
        return apiSetById(binding, accountId, true);
      }
      return false;
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoBindAll);
  } else {
    autoBindAll();
  }
})();
