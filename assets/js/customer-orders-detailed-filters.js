(function () {
  function boxes() {
    return document.querySelectorAll('#co-det-form details.co-ms');
  }

  function listInputs(box) {
    return box.querySelectorAll('.co-ms__list input[type="checkbox"]');
  }

  function dayOff(el) {
    return !el || el.disabled || (el.parentNode && el.parentNode.classList.contains('co-ms__day-off'));
  }

  function searchMiss(el) {
    return el && el.parentNode && el.parentNode.classList.contains('co-ms__miss');
  }

  function refresh(box) {
    var valueEl = box.querySelector('.co-ms__value');
    var all = box.querySelector('[data-all]');
    var inputs = Array.prototype.filter.call(listInputs(box), function (el) {
      return !dayOff(el);
    });
    var visible = inputs.filter(function (el) { return !searchMiss(el); });
    var checked = inputs.filter(function (el) { return el.checked; });
    if (all) all.checked = visible.length > 0 && visible.every(function (el) { return el.checked; });
    if (!valueEl) return;
    var text = 'الكل';
    if (!inputs.length) text = box.id === 'co-det-item' ? 'اختر الفئة' : 'الكل';
    else if (!checked.length || checked.length === inputs.length) text = 'الكل';
    else if (checked.length <= 2) {
      text = checked.map(function (el) {
        return (el.parentNode.textContent || '').trim();
      }).join('، ');
    } else text = checked.length + ' محدد';
    valueEl.textContent = text;
    valueEl.classList.toggle('is-placeholder', text === 'الكل' || text === 'اختر الفئة');
  }

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function checkedVals(box, name) {
    if (!box) return [];
    return Array.prototype.map.call(
      box.querySelectorAll('input[name="' + name + '"]:checked'),
      function (el) { return el.value; }
    );
  }

  function optionsUrl(form, query) {
    var base = String(window.__HYPEX_BASE__ || '').replace(/\/$/, '');
    var real = String(window.__HYPEX_PATH__ || '');
    if (real.charAt(0) === '/') {
      return base + real.replace(/\/$/, '') + '/options?' + query;
    }
    var action = (form.getAttribute('action') || '').split('?')[0].replace(/\/$/, '');
    return action + '/options?' + query;
  }

  function setChecks(box, name, rows, labelOf, keep, emptyText) {
    var list = box.querySelector('.co-ms__list');
    if (!list) return;
    if (!rows.length) {
      var msg = emptyText || (box.id === 'co-det-item' ? 'اختر الفئة أولاً' : 'لا يوجد');
      list.innerHTML = '<p class="co-ms__empty">' + msg + '</p>';
      refresh(box);
      return;
    }
    list.innerHTML = rows.map(function (row) {
      var id = String(row.id);
      var on = keep.indexOf(id) !== -1 ? ' checked' : '';
      return '<label><input type="checkbox" name="' + name + '" value="' + id + '"' + on + '> ' + esc(labelOf(row)) + '</label>';
    }).join('');
    applyFind(box);
    refresh(box);
  }

  function applyFind(box) {
    var input = box.querySelector('.co-ms__find input');
    var q = input ? String(input.value || '').trim().toLowerCase() : '';
    listInputs(box).forEach(function (el) {
      var label = el.parentNode;
      if (!label) return;
      var miss = q && String(label.textContent || '').toLowerCase().indexOf(q) === -1;
      label.classList.toggle('co-ms__miss', !!miss);
    });
  }

  function ensureSearch(box) {
    if (!box || box.querySelector('.co-ms__find')) return;
    var panel = box.querySelector('.co-ms__panel');
    if (!panel) return;
    var head = document.createElement('div');
    head.className = 'co-ms__head';
    var find = document.createElement('div');
    find.className = 'co-ms__find';
    var input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'بحث';
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    input.addEventListener('click', function (e) { e.stopPropagation(); });
    input.addEventListener('keydown', function (e) {
      e.stopPropagation();
      if (e.key === 'Enter') e.preventDefault();
    });
    input.addEventListener('input', function () {
      applyFind(box);
      refresh(box);
    });
    find.appendChild(input);
    head.appendChild(find);
    var all = panel.querySelector('.co-ms__all');
    if (all) head.appendChild(all);
    panel.insertBefore(head, panel.firstChild);
  }

  function syncDays(yearBox, monthBox, dayBox) {
    if (!dayBox) return;
    var years = checkedVals(yearBox, 'year').map(Number);
    var months = checkedVals(monthBox, 'month').map(Number);
    var max = 31;
    if (months.length) {
      max = 0;
      (years.length ? years : [new Date().getFullYear()]).forEach(function (y) {
        months.forEach(function (m) {
          max = Math.max(max, new Date(y, m, 0).getDate());
        });
      });
    }
    dayBox.querySelectorAll('input[name="day"]').forEach(function (el) {
      var hide = Number(el.value) > max;
      el.disabled = hide;
      if (el.parentNode) el.parentNode.classList.toggle('co-ms__day-off', hide);
      if (hide) el.checked = false;
    });
    refresh(dayBox);
  }

  function boot() {
    var form = document.getElementById('co-det-form');
    if (!form) return;
    var yearBox = document.getElementById('co-det-years');
    var monthBox = document.getElementById('co-det-months');
    var dayBox = document.getElementById('co-det-days');
    var repBox = document.getElementById('co-det-reps');
    var cust = document.getElementById('co-det-customer');
    var cat = document.getElementById('co-det-category');
    var item = document.getElementById('co-det-item');

    boxes().forEach(function (box) {
      ensureSearch(box);
      refresh(box);
      box.addEventListener('toggle', function () {
        if (!box.open) return;
        boxes().forEach(function (other) {
          if (other !== box) other.open = false;
        });
        var find = box.querySelector('.co-ms__find input');
        if (find) setTimeout(function () { find.focus(); find.select(); }, 0);
      });
      box.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || t.type !== 'checkbox') return;
        if (t.hasAttribute('data-all')) {
          listInputs(box).forEach(function (el) {
            if (dayOff(el) || searchMiss(el)) return;
            el.checked = t.checked;
          });
        }
        refresh(box);
      });
    });

    function loadCustomers() {
      if (!cust) return;
      var ids = checkedVals(repBox, 'sales_rep_id');
      var keep = checkedVals(cust, 'customer_id');
      var all = cust.querySelector('[data-all]');
      if (all && all.checked) keep = [];
      var q = ids.map(function (id) { return 'sales_rep_id=' + encodeURIComponent(id); }).join('&');
      fetch(optionsUrl(form, 'kind=customers' + (q ? '&' + q : '')), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          setChecks(cust, 'customer_id', (j && j.rows) || [], function (c) {
            return (c.name_ar || '') + (c.code ? ' (' + c.code + ')' : '');
          }, keep);
        })
        .catch(function () {});
    }

    function loadItems() {
      if (!item) return;
      var ids = checkedVals(cat, 'category_id');
      var keep = checkedVals(item, 'item_id');
      var all = item.querySelector('[data-all]');
      if (all && all.checked) keep = [];
      if (!ids.length && cat) {
        ids = Array.prototype.map.call(cat.querySelectorAll('input[name="category_id"]'), function (el) {
          return el.value;
        });
      }
      if (!ids.length) {
        setChecks(item, 'item_id', [], function () { return ''; }, [], 'اختر الفئة أولاً');
        return;
      }
      var q = ids.map(function (id) { return 'category_id=' + encodeURIComponent(id); }).join('&');
      fetch(optionsUrl(form, 'kind=items&' + q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var rows = (j && j.rows) || [];
          if (!rows.length && listInputs(item).length) return;
          setChecks(item, 'item_id', rows, function (it) {
            var code = it.barcode || it.sku || '';
            return (code ? code + ' — ' : '') + (it.name_ar || '');
          }, keep, 'لا توجد مواد لهذه الفئة');
        })
        .catch(function () {
          if (!listInputs(item).length) {
            setChecks(item, 'item_id', [], function () { return ''; }, [], 'تعذر تحميل المواد');
          }
        });
    }

    if (repBox) repBox.addEventListener('change', loadCustomers);
    if (cat) cat.addEventListener('change', loadItems);
    if (item) item.addEventListener('toggle', function () {
      if (item.open) loadItems();
    });
    if (yearBox) yearBox.addEventListener('change', function () {
      syncDays(yearBox, monthBox, dayBox);
    });
    if (monthBox) monthBox.addEventListener('change', function () {
      syncDays(yearBox, monthBox, dayBox);
    });

    syncDays(yearBox, monthBox, dayBox);
    loadItems();

    form.addEventListener('submit', function () {
      form.querySelectorAll('input[data-keep-all]').forEach(function (el) { el.remove(); });
      boxes().forEach(function (box) {
        var inputs = Array.prototype.filter.call(listInputs(box), function (el) {
          return !dayOff(el);
        });
        var checked = inputs.filter(function (el) { return el.checked; });
        if (inputs.length > 60 && checked.length === inputs.length) {
          inputs.forEach(function (el) { el.disabled = true; });
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = inputs[0].name + '_all';
          hidden.value = '1';
          hidden.setAttribute('data-keep-all', '1');
          form.appendChild(hidden);
        }
      });
    });
  }

  function bootFolds() {
    document.querySelectorAll('.co-det-twist').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-fold');
        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        document.querySelectorAll('tr.co-det-fold[data-fold="' + key + '"]').forEach(function (row) {
          row.classList.toggle('is-open', !open);
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      boot();
      bootFolds();
    });
  } else {
    boot();
    bootFolds();
  }
})();
