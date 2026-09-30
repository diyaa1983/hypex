(function () {
  'use strict';

  /**
   * قائمة ذكية لاختيار المندوب (بحث بالاسم أو الرمز).
   * القائمة تُثبَّت فوق الصفحة (fixed) حتى لا تُقصّ داخل si-rail / overflow.
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

    var listOpen = false;
    var closeTimer = null;
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

    function positionDropdown() {
      var rect = input.getBoundingClientRect();
      var top = Math.round(rect.bottom + 2);
      var spaceBelow = window.innerHeight - top - 8;
      var maxH = Math.max(120, Math.min(280, spaceBelow));
      list.style.position = 'fixed';
      list.style.top = top + 'px';
      list.style.left = Math.round(rect.left) + 'px';
      list.style.width = Math.max(Math.round(rect.width), 220) + 'px';
      list.style.right = 'auto';
      list.style.insetInlineStart = 'auto';
      list.style.insetInlineEnd = 'auto';
      list.style.zIndex = '100600';
      list.style.maxHeight = maxH + 'px';
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
      root.classList.remove('is-open');
      list.classList.remove('is-floating');
      if (list.parentElement === document.body) {
        root.appendChild(list);
      }
    }

    function setSelection(rep) {
      if (rep) {
        hidden.value = String(rep.id);
        input.value = rep.name_ar;
      } else {
        hidden.value = '';
      }
      closeDropdown();
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
        openDropdown();
        return;
      }
      matches.slice(0, 80).forEach(function (r) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'report-cust-pick-item';
        btn.setAttribute('data-id', String(r.id));
        btn.textContent = r.name_ar + (r.code ? ' (' + r.code + ')' : '');
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          setSelection(r);
        });
        list.appendChild(btn);
      });
      openDropdown();
    }

    input.addEventListener('focus', function () {
      renderList(input.value);
    });

    input.addEventListener('input', function () {
      hidden.value = '';
      renderList(input.value);
    });

    input.addEventListener('blur', function () {
      clearTimeout(closeTimer);
      closeTimer = setTimeout(closeDropdown, 180);
    });

    list.addEventListener('mousedown', function (e) {
      e.preventDefault();
      clearTimeout(closeTimer);
    });

    window.addEventListener(
      'scroll',
      function () {
        if (listOpen) positionDropdown();
      },
      true
    );
    window.addEventListener('resize', function () {
      if (listOpen) positionDropdown();
    });

    if (window.AppListKeyboard) {
      listNav = AppListKeyboard.bindSearchDropdown({
        input: input,
        list: list,
        isOpen: function () {
          return listOpen && !list.hidden;
        },
        ensureOpen: function () {
          renderList(input.value);
        },
        onEscape: function () {
          closeDropdown();
        },
        onPick: function (btn) {
          var id = parseInt(btn.getAttribute('data-id'), 10);
          setSelection(findById(id));
        },
      });
    }

    document.addEventListener('click', function (e) {
      if (!root.contains(e.target) && !list.contains(e.target)) {
        closeDropdown();
      }
    });

    var initial = findById(opts.initialId || hidden.value);
    if (initial) {
      setSelection(initial);
    }
  }

  window.ReportRepPicker = { init: initRepPicker };
})();
