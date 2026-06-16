(function () {
  'use strict';

  var MODAL_ID = 'app-employee-picker-modal';
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
    return document.getElementById('app-employee-picker-search');
  }

  function getResults() {
    return document.getElementById('app-employee-picker-results');
  }

  function isOtherPickerOpen() {
    if (window.CustomerPickerModal && CustomerPickerModal.isOpen && CustomerPickerModal.isOpen()) {
      return true;
    }
    if (window.ItemPickerModal && ItemPickerModal.isOpen && ItemPickerModal.isOpen()) {
      return true;
    }
    return false;
  }

  function closeOtherPickers() {
    if (window.CustomerPickerModal && CustomerPickerModal.close) {
      CustomerPickerModal.close();
    }
    if (window.ItemPickerModal && ItemPickerModal.close) {
      ItemPickerModal.close();
    }
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
      var resultsClose = getResults();
      if (resultsClose) {
        resultsClose.style.flex = '';
        resultsClose.style.minHeight = '';
        resultsClose.style.maxHeight = '';
        resultsClose.style.overflowY = '';
      }
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

  function employeeLabel(emp) {
    if (!emp) return '';
    return String(emp.label || emp.name_ar || '').trim();
  }

  function employeeMatches(emp, needle) {
    if (!needle) return true;
    var hay = norm(emp.search || '');
    if (hay && hay.indexOf(needle) >= 0) return true;
    var empId = parseInt(emp.id, 10);
    var idText = empId > 0 ? String(empId) : '';
    return (
      norm(emp.name_ar).indexOf(needle) >= 0 ||
      norm(emp.code).indexOf(needle) >= 0 ||
      norm(emp.label).indexOf(needle) >= 0 ||
      (idText !== '' && norm(idText).indexOf(needle) >= 0)
    );
  }

  function findEmployee(binding, id) {
    var n = parseInt(id, 10);
    if (binding.allowNew && n === 0) {
      return {
        id: 0,
        name_ar: binding.newLabel || '— موظف جديد —',
        label: binding.newLabel || '— موظف جديد —',
        code: '',
      };
    }
    if (n < 1) return null;
    return binding.byId[n] || null;
  }

  function updateDisplay(binding) {
    if (!binding.display) return;
    var id = binding.hidden ? parseInt(binding.hidden.value, 10) : 0;
    var emp = findEmployee(binding, id);
    if (emp) {
      binding.display.textContent = employeeLabel(emp);
      binding.display.classList.remove('is-placeholder');
    } else {
      binding.display.textContent = binding.placeholder;
      binding.display.classList.add('is-placeholder');
    }
  }

  function setSelection(binding, emp, silent) {
    if (!binding.hidden) return;
    if (emp) {
      binding.hidden.value = String(emp.id);
    } else {
      binding.hidden.value = '';
    }
    try {
      binding.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {}
    updateDisplay(binding);
    close();
    if (!silent && typeof binding.onSelect === 'function') {
      binding.onSelect(emp || null);
    }
  }

  function getPickableButtons() {
    var results = getResults();
    if (!results) return [];
    return Array.prototype.slice.call(
      results.querySelectorAll('.sales-inv-cust-pick-item[data-employee-id]')
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
    var id = parseInt(btn.getAttribute('data-employee-id'), 10);
    if (isNaN(id)) return;
    var emp = findEmployee(activeBinding, id);
    if (emp) {
      setSelection(activeBinding, emp);
    }
  }

  function renderResults(binding, q) {
    var results = getResults();
    if (!results || !binding) return;
    activeIndex = -1;
    var needle = norm(q);
    var matches = binding.list.filter(function (emp) {
      return employeeMatches(emp, needle);
    });
    results.innerHTML = '';
    if (binding.allowNew && (!needle || norm(binding.newLabel).indexOf(needle) >= 0)) {
      var newBtn = document.createElement('button');
      newBtn.type = 'button';
      newBtn.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      newBtn.setAttribute('data-employee-id', '0');
      newBtn.innerHTML =
        '<div class="sales-inv-pick-item-body"><span class="sales-inv-pick-item-name">' +
        escapeHtml(binding.newLabel) +
        '</span></div>';
      newBtn.addEventListener('click', function () {
        setSelection(binding, findEmployee(binding, 0));
      });
      results.appendChild(newBtn);
    }
    if (!matches.length && results.childNodes.length === 0) {
      results.innerHTML =
        '<div class="sales-inv-pick-empty">' +
        escapeHtml(needle ? 'لا يوجد موظف مطابق' : 'لا يوجد موظفون') +
        '</div>';
      return;
    }
    matches.slice(0, binding.maxResults).forEach(function (emp) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'sales-inv-pick-item sales-inv-cust-pick-item';
      row.setAttribute('data-employee-id', String(emp.id));
      var code = String(emp.code || '').trim();
      var body = document.createElement('div');
      body.className = 'sales-inv-pick-item-body';
      body.innerHTML =
        '<span class="sales-inv-pick-item-name">' +
        escapeHtml(employeeLabel(emp)) +
        '</span>' +
        (code
          ? '<span class="sales-inv-pick-item-barcode">' + escapeHtml(code) + '</span>'
          : '');
      row.appendChild(body);
      row.addEventListener('click', function () {
        setSelection(binding, emp);
      });
      results.appendChild(row);
    });
  }

  function loadEmployeesFromJson(jsonId) {
    var el = document.getElementById(jsonId);
    if (!el) return [];
    try {
      return JSON.parse(el.textContent || '[]');
    } catch (e) {
      return [];
    }
  }

  function reloadBindingList(binding) {
    if (!binding) return;
    var list = binding.list || [];
    if (binding.jsonId) {
      var fresh = loadEmployeesFromJson(binding.jsonId);
      if (Array.isArray(fresh)) {
        list = fresh;
      }
    }
    binding.list = list;
    binding.byId = {};
    list.forEach(function (emp) {
      var id = parseInt(emp.id, 10);
      if (id > 0) binding.byId[id] = emp;
    });
  }

  function open(binding) {
    if (!binding || (binding.getDisabled && binding.getDisabled())) return;
    install();
    closeOtherPickers();
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

    var list = opts.employees || [];
    if ((!list || !list.length) && opts.jsonId) {
      list = loadEmployeesFromJson(opts.jsonId);
    }
    var byId = {};
    list.forEach(function (emp) {
      var id = parseInt(emp.id, 10);
      if (id > 0) byId[id] = emp;
    });

    var binding = {
      hidden: hidden,
      open: openBtn,
      display: display,
      list: list,
      byId: byId,
      jsonId: opts.jsonId || '',
      placeholder: opts.placeholder || 'اضغط لاختيار الموظف',
      allowNew: !!opts.allowNew,
      newLabel: opts.newLabel || '— موظف جديد —',
      maxResults: parseInt(opts.maxResults, 10) || 120,
      onSelect: opts.onSelect || null,
      getDisabled: opts.getDisabled || null,
    };

    openBtn.addEventListener('click', function () {
      open(binding);
    });

    bindings[hidden.id || ''] = binding;

    var initialId = opts.initialId;
    if (initialId === undefined || initialId === null) {
      initialId = hidden.value !== '' ? parseInt(hidden.value, 10) : '';
    }
    if (initialId === '' || (initialId === 0 && hidden.value === '')) {
      if (binding.hidden) {
        binding.hidden.value = '';
      }
      updateDisplay(binding);
    } else {
      apiSetById(binding, initialId, true);
    }

    return binding;
  }

  function apiSetById(binding, id, silent) {
    var emp = findEmployee(binding, id);
    if (emp) {
      if (binding.hidden) {
        binding.hidden.value = String(emp.id);
      }
      updateDisplay(binding);
      if (!silent && typeof binding.onSelect === 'function') {
        binding.onSelect(emp);
      }
      return true;
    }
    if (binding.hidden) {
      var n = parseInt(id, 10);
      if (n > 0) {
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

  function bindFromSlot(slot) {
    if (!slot || slot.getAttribute('data-employee-picker-bound') === '1') return null;
    var hiddenId = slot.getAttribute('data-hidden-id') || '';
    var openId = slot.getAttribute('data-open-id') || '';
    var displayId = slot.getAttribute('data-display-id') || '';
    var jsonId = slot.getAttribute('data-json-id') || 'hr-employees-picker-json';
    var placeholder = slot.getAttribute('data-placeholder') || 'اضغط لاختيار الموظف';
    var allowNew = slot.getAttribute('data-allow-new') === '1';
    var newLabel = slot.getAttribute('data-new-label') || '— موظف جديد —';
    var initial = slot.getAttribute('data-initial') || '';
    var api = bind({
      hidden: hiddenId,
      open: openId,
      display: displayId,
      jsonId: jsonId,
      placeholder: placeholder,
      allowNew: allowNew,
      newLabel: newLabel,
      initialId: initial !== '' ? parseInt(initial, 10) : '',
    });
    slot.setAttribute('data-employee-picker-bound', '1');
    return api;
  }

  function bind(opts) {
    if (!installed) install();
    var binding = buildBinding(opts);
    if (!binding) return null;
    var key = binding.hidden.id || String(Object.keys(bindings).length);
    bindings[key] = binding;
    return {
      setById: function (id, silent) {
        return apiSetById(binding, id, !!silent);
      },
      getLabel: function () {
        var eid = parseInt(binding.hidden.value, 10);
        var emp = findEmployee(binding, eid);
        return emp ? employeeLabel(emp) : '';
      },
      getEmployee: function () {
        return findEmployee(binding, parseInt(binding.hidden.value, 10));
      },
      open: function () {
        open(binding);
      },
      close: close,
    };
  }

  function install() {
    if (installed) return;
    installed = true;
    var closeBtn = document.getElementById('app-employee-picker-close');
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
    document.querySelectorAll('[data-employee-picker]').forEach(function (slot) {
      bindFromSlot(slot);
    });
  }

  function getLabel(hiddenId) {
    var b = bindings[hiddenId];
    if (!b) return '';
    var emp = findEmployee(b, parseInt(b.hidden.value, 10));
    return emp ? employeeLabel(emp) : '';
  }

  window.EmployeePickerModal = {
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
