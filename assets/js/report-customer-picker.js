(function () {
  'use strict';

  /**
   * قائمة ذكية لاختيار العميل (بحث بالاسم أو الرمز).
   * @param {HTMLElement} root
   * @param {Array<{id:number,name_ar:string,code:string}>} customers
   * @param {{ initialId?: number, allowAllCustomers?: boolean, onSelect?: function, maxResults?: number }} opts
   */
  function initCustomerPicker(root, customers, opts) {
    if (!root || !Array.isArray(customers)) return;

    opts = opts || {};
    var allowAll = !!opts.allowAllCustomers;
    var maxResults = parseInt(opts.maxResults, 10) || 100;
    var ALL_CUSTOMERS = { id: 0, name_ar: 'جميع العملاء', code: '' };
    var hidden = root.querySelector('[data-cust-id]');
    var input = root.querySelector('[data-cust-search]');
    var list = root.querySelector('[data-cust-list]');
    var toggleBtn = root.querySelector('[data-cust-toggle]');
    if (!hidden || !input || !list) return;

    var items = customers.map(function (c) {
      return {
        id: parseInt(c.id, 10) || 0,
        name_ar: String(c.name_ar || ''),
        code: String(c.code || ''),
      };
    });

    var activeIndex = -1;
    var listOpen = false;
    var closeTimer = null;

    function norm(s) {
      return String(s || '')
        .trim()
        .toLowerCase();
    }

    function escapeHtml(s) {
      var d = document.createElement('div');
      d.textContent = s == null ? '' : String(s);
      return d.innerHTML;
    }

    function findById(id) {
      var n = parseInt(id, 10);
      if (allowAll && n === 0) return ALL_CUSTOMERS;
      if (n < 1) return null;
      for (var i = 0; i < items.length; i++) {
        if (items[i].id === n) return items[i];
      }
      return null;
    }

    function getSelectedCustomer() {
      return findById(hidden.value);
    }

    function isBrowsingMode() {
      var q = norm(input.value);
      if (!q) return true;
      var sel = getSelectedCustomer();
      return !!(sel && norm(sel.name_ar) === q);
    }

    function setSelection(cust, silent) {
      if (cust) {
        hidden.value = String(cust.id);
        input.value = cust.name_ar;
      } else {
        hidden.value = '';
        input.value = '';
      }
      try {
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (evErr) {
        /* IE11 */
      }
      closeDropdown();
      if (!silent && typeof opts.onSelect === 'function') {
        opts.onSelect(cust || null);
      }
    }

    function setById(id, silent) {
      var c = findById(id);
      if (c) {
        setSelection(c, !!silent);
        return true;
      }
      hidden.value = id ? String(id) : '';
      input.value = '';
      closeDropdown();
      if (!silent && typeof opts.onSelect === 'function') {
        opts.onSelect(null);
      }
      return false;
    }

    function positionDropdown() {
      var rect = input.getBoundingClientRect();
      list.style.position = 'fixed';
      list.style.top = Math.round(rect.bottom + 2) + 'px';
      list.style.left = Math.round(rect.left) + 'px';
      list.style.width = Math.max(Math.round(rect.width), 220) + 'px';
      list.style.zIndex = '100600';
      list.style.maxHeight = 'min(280px, calc(100vh - ' + Math.round(rect.bottom + 12) + 'px))';
    }

    function openDropdown() {
      clearTimeout(closeTimer);
      if (!listOpen) {
        if (list.parentElement !== document.body) {
          document.body.appendChild(list);
        }
        list.classList.add('is-floating');
        listOpen = true;
      }
      positionDropdown();
      list.hidden = false;
      root.classList.add('is-open');
    }

    function closeDropdown() {
      clearTimeout(closeTimer);
      list.hidden = true;
      listOpen = false;
      activeIndex = -1;
      root.classList.remove('is-open');
      list.classList.remove('is-floating');
      if (list.parentElement === document.body) {
        root.appendChild(list);
      }
    }

    function scheduleClose() {
      clearTimeout(closeTimer);
      closeTimer = setTimeout(closeDropdown, 200);
    }

    function cancelScheduledClose() {
      clearTimeout(closeTimer);
    }

    function getMatchItems(needle) {
      if (!needle) return items.slice();
      return items.filter(function (c) {
        return (
          norm(c.name_ar).indexOf(needle) >= 0 || norm(c.code).indexOf(needle) >= 0
        );
      });
    }

    function highlightActive() {
      var buttons = list.querySelectorAll('.report-cust-pick-item[data-id]');
      buttons.forEach(function (btn, i) {
        btn.classList.toggle('is-active', i === activeIndex);
      });
      if (activeIndex >= 0 && buttons[activeIndex]) {
        buttons[activeIndex].scrollIntoView({ block: 'nearest' });
      }
    }

    function renderList(queryText, browseAll) {
      var needle = browseAll ? '' : norm(queryText);
      var matches = getMatchItems(needle);

      list.innerHTML = '';
      activeIndex = -1;

      if (allowAll && (!needle || norm(ALL_CUSTOMERS.name_ar).indexOf(needle) >= 0)) {
        var allBtn = document.createElement('button');
        allBtn.type = 'button';
        allBtn.className = 'report-cust-pick-item';
        allBtn.setAttribute('data-id', '0');
        allBtn.textContent = ALL_CUSTOMERS.name_ar;
        allBtn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          setSelection(ALL_CUSTOMERS);
        });
        list.appendChild(allBtn);
      }

      if (!matches.length && list.childNodes.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'report-cust-pick-empty';
        empty.textContent = needle ? 'لا يوجد عميل مطابق' : 'لا يوجد عملاء';
        list.appendChild(empty);
        openDropdown();
        return;
      }

      matches.slice(0, maxResults).forEach(function (c) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'report-cust-pick-item';
        btn.setAttribute('data-id', String(c.id));
        if (c.code) {
          btn.innerHTML =
            escapeHtml(c.name_ar) + ' <code>' + escapeHtml(c.code) + '</code>';
        } else {
          btn.textContent = c.name_ar;
        }
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          setSelection(c);
        });
        list.appendChild(btn);
      });

      openDropdown();
    }

    function openForBrowse() {
      renderList('', true);
    }

    function openForSearch() {
      if (isBrowsingMode()) {
        openForBrowse();
        return;
      }
      renderList(input.value, false);
    }

    input.addEventListener('focus', function () {
      openForSearch();
    });

    input.addEventListener('click', function () {
      cancelScheduledClose();
      openForSearch();
    });

    input.addEventListener('input', function () {
      var sel = getSelectedCustomer();
      if (!sel || norm(input.value) !== norm(sel.name_ar)) {
        hidden.value = '';
      }
      renderList(input.value, norm(input.value) === '');
    });

    input.addEventListener('blur', function () {
      scheduleClose();
    });

    list.addEventListener('mousedown', function (e) {
      e.preventDefault();
      cancelScheduledClose();
    });

    if (toggleBtn) {
      toggleBtn.addEventListener('mousedown', function (e) {
        e.preventDefault();
        cancelScheduledClose();
        if (listOpen) {
          closeDropdown();
        } else {
          input.focus();
          openForBrowse();
        }
      });
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeDropdown();
        return;
      }

      var buttons = list.querySelectorAll('.report-cust-pick-item[data-id]');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!listOpen) openForSearch();
        if (!buttons.length) return;
        activeIndex = activeIndex < buttons.length - 1 ? activeIndex + 1 : 0;
        highlightActive();
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!listOpen) openForSearch();
        if (!buttons.length) return;
        activeIndex = activeIndex > 0 ? activeIndex - 1 : buttons.length - 1;
        highlightActive();
        return;
      }
      if (e.key === 'Enter') {
        if (!listOpen) return;
        e.preventDefault();
        var pick =
          activeIndex >= 0 && buttons[activeIndex]
            ? buttons[activeIndex]
            : buttons[0];
        if (pick) {
          var id = parseInt(pick.getAttribute('data-id'), 10);
          setSelection(findById(id));
        }
      }
    });

    document.addEventListener('click', function (e) {
      if (root.contains(e.target) || list.contains(e.target)) return;
      closeDropdown();
    });

    window.addEventListener(
      'resize',
      function () {
        if (listOpen) positionDropdown();
      },
      { passive: true }
    );

    window.addEventListener(
      'scroll',
      function () {
        if (listOpen) positionDropdown();
      },
      true
    );

    var initialId = opts.initialId;
    if (initialId === undefined || initialId === null || initialId === '') {
      if (hidden.value !== '') {
        initialId = parseInt(hidden.value, 10);
      }
    }
    if (allowAll && (initialId === 0 || initialId === '0')) {
      setSelection(ALL_CUSTOMERS, true);
    } else {
      var initial = findById(initialId);
      if (initial) {
        setSelection(initial, true);
      }
    }

    return { setById: setById, getSelectedId: function () { return hidden.value; }, open: openForBrowse };
  }

  window.ReportCustomerPicker = { init: initCustomerPicker };
})();
