(function () {
  'use strict';

  /**
   * قائمة ذكية لاختيار المندوب (بحث بالاسم أو الرمز).
   * @param {HTMLElement} root
   * @param {Array<{id:number,name_ar:string,code:string}>} reps
   * @param {{ initialId?: number }} opts
   */
  function initRepPicker(root, reps, opts) {
    if (!root || !Array.isArray(reps)) return;

    opts = opts || {};
    var hidden = root.querySelector('[data-rep-id]');
    var input = root.querySelector('[data-rep-search]');
    var list = root.querySelector('[data-rep-list]');
    if (!hidden || !input || !list) return;

    var items = reps.map(function (r) {
      return {
        id: parseInt(r.id, 10) || 0,
        name_ar: String(r.name_ar || ''),
        code: String(r.code || ''),
      };
    });

    function norm(s) {
      return String(s || '')
        .trim()
        .toLowerCase();
    }

    function findById(id) {
      var n = parseInt(id, 10) || 0;
      if (n < 1) return null;
      for (var i = 0; i < items.length; i++) {
        if (items[i].id === n) return items[i];
      }
      return null;
    }

    function setSelection(rep) {
      if (rep) {
        hidden.value = String(rep.id);
        input.value = rep.name_ar;
      } else {
        hidden.value = '';
      }
      list.hidden = true;
    }

    var listNav = null;

    function renderList(q) {
      var needle = norm(q);
      var matches = items.filter(function (r) {
        if (!needle) return true;
        return (
          norm(r.name_ar).indexOf(needle) >= 0 || norm(r.code).indexOf(needle) >= 0
        );
      });
      list.innerHTML = '';
      if (listNav) listNav.reset();
      if (!matches.length) {
        var empty = document.createElement('div');
        empty.className = 'report-cust-pick-empty';
        empty.textContent = 'لا يوجد مندوب مطابق';
        list.appendChild(empty);
        list.hidden = false;
        return;
      }
      matches.slice(0, 80).forEach(function (r) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'report-cust-pick-item';
        btn.setAttribute('data-id', String(r.id));
        btn.textContent = r.name_ar;
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          setSelection(r);
        });
        list.appendChild(btn);
      });
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

    var initial = findById(opts.initialId || hidden.value);
    if (initial) {
      setSelection(initial);
    }
  }

  window.ReportRepPicker = { init: initRepPicker };
})();
