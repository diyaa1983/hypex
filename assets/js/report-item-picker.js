(function () {
  'use strict';

  var ALL_ITEMS = { id: 0, name_ar: 'جميع المواد', sku: '', barcode: '' };

  /**
   * قائمة ذكية لاختيار المادة (أو جميع المواد).
   * @param {HTMLElement} root
   * @param {Array<{id:number,name_ar:string,sku?:string,barcode?:string}>} items
   * @param {{ initialId?: number }} opts
   */
  function initItemPicker(root, items, opts) {
    if (!root || !Array.isArray(items)) return;

    opts = opts || {};
    /** @type {boolean} إن كانت false لا تُعرض خيار «جميع المواد» (لتقارير تتطلب مادة محددة). */
    var allowAll = opts.allowAll !== false;
    var hidden = root.querySelector('[data-item-id]');
    var input = root.querySelector('[data-item-search]');
    var list = root.querySelector('[data-item-list]');
    if (!hidden || !input || !list) return;

    var catalog = items.map(function (it) {
      return {
        id: parseInt(it.id, 10) || 0,
        name_ar: String(it.name_ar || ''),
        sku: String(it.sku || ''),
        barcode: String(it.barcode || ''),
      };
    });

    function norm(s) {
      return String(s || '')
        .trim()
        .toLowerCase();
    }

    function findById(id) {
      var n = parseInt(id, 10);
      if (n === 0) return allowAll ? ALL_ITEMS : null;
      if (n < 1) return null;
      for (var i = 0; i < catalog.length; i++) {
        if (catalog[i].id === n) return catalog[i];
      }
      return null;
    }

    function setSelection(it) {
      if (it) {
        hidden.value = String(it.id);
        input.value = it.name_ar;
      } else {
        hidden.value = '';
        input.value = '';
      }
      list.hidden = true;
    }

    function appendPickButton(it) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'report-cust-pick-item';
      btn.setAttribute('data-id', String(it.id));
      btn.textContent = it.name_ar;
      btn.addEventListener('mousedown', function (e) {
        e.preventDefault();
        setSelection(it);
      });
      list.appendChild(btn);
    }

    var listNav = null;

    function renderList(q) {
      var needle = norm(q);
      list.innerHTML = '';
      if (listNav) listNav.reset();

      if (allowAll && (!needle || norm(ALL_ITEMS.name_ar).indexOf(needle) >= 0)) {
        appendPickButton(ALL_ITEMS);
      }

      var matches = catalog.filter(function (it) {
        if (!needle) return true;
        return (
          norm(it.name_ar).indexOf(needle) >= 0 ||
          norm(it.sku).indexOf(needle) >= 0 ||
          norm(it.barcode).indexOf(needle) >= 0
        );
      });

      if (!matches.length && list.childNodes.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'report-cust-pick-empty';
        empty.textContent = 'لا توجد مادة مطابقة';
        list.appendChild(empty);
        list.hidden = false;
        return;
      }

      matches.slice(0, 80).forEach(appendPickButton);
      list.hidden = false;
    }

    input.addEventListener('focus', function () {
      renderList(input.value);
    });

    input.addEventListener('input', function () {
      hidden.value = '';
      renderList(input.value);
    });

    if (window.AppListKeyboard) {
      listNav = AppListKeyboard.bindSearchDropdown({
        input: input,
        list: list,
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

    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) {
        list.hidden = true;
      }
    });

    var initialId = opts.initialId;
    if (initialId === undefined || initialId === null || initialId === '') {
      if (hidden.value !== '') {
        initialId = parseInt(hidden.value, 10);
      }
    }
    if (allowAll && (initialId === 0 || initialId === '0')) {
      setSelection(ALL_ITEMS);
    } else {
      var initial = findById(initialId);
      if (initial) {
        setSelection(initial);
      }
    }
  }

  window.ReportItemPicker = { init: initItemPicker };
})();
