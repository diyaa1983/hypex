(function () {
  function boxes() {
    return document.querySelectorAll('#co-det-form details.co-ms');
  }

  function listInputs(box) {
    return box.querySelectorAll('.co-ms__list input[type="checkbox"]');
  }

  function refresh(box) {
    var valueEl = box.querySelector('.co-ms__value');
    var all = box.querySelector('[data-all]');
    var inputs = Array.prototype.filter.call(listInputs(box), function (el) {
      return !el.disabled && el.parentNode && el.parentNode.style.display !== 'none';
    });
    var checked = inputs.filter(function (el) { return el.checked; });
    if (all) all.checked = inputs.length > 0 && checked.length === inputs.length;
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
    var action = (form.getAttribute('action') || '').replace(/\/$/, '');
    return action + '/options?' + query;
  }

  function setChecks(box, name, rows, labelOf, keep) {
    var list = box.querySelector('.co-ms__list');
    if (!list) return;
    if (!rows.length) {
      list.innerHTML = '<p class="co-ms__empty">' + (box.id === 'co-det-item' ? 'اختر الفئة أولاً' : 'لا يوجد') + '</p>';
      refresh(box);
      return;
    }
    list.innerHTML = rows.map(function (row) {
      var id = String(row.id);
      var on = keep.indexOf(id) !== -1 ? ' checked' : '';
      return '<label><input type="checkbox" name="' + name + '" value="' + id + '"' + on + '> ' + esc(labelOf(row)) + '</label>';
    }).join('');
    refresh(box);
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
      if (el.parentNode) el.parentNode.style.display = hide ? 'none' : '';
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
      refresh(box);
      box.addEventListener('toggle', function () {
        if (!box.open) return;
        boxes().forEach(function (other) {
          if (other !== box) other.open = false;
        });
      });
      box.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || t.type !== 'checkbox') return;
        if (t.hasAttribute('data-all')) {
          listInputs(box).forEach(function (el) {
            if (!el.disabled && el.parentNode && el.parentNode.style.display !== 'none') el.checked = t.checked;
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
      if (!ids.length) {
        setChecks(item, 'item_id', [], function () { return ''; }, []);
        return;
      }
      var q = ids.map(function (id) { return 'category_id=' + encodeURIComponent(id); }).join('&');
      fetch(optionsUrl(form, 'kind=items&' + q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          setChecks(item, 'item_id', (j && j.rows) || [], function (it) {
            var code = it.barcode || it.sku || '';
            return (code ? code + ' — ' : '') + (it.name_ar || '');
          }, keep);
        })
        .catch(function () {});
    }

    if (repBox) repBox.addEventListener('change', loadCustomers);
    if (cat) cat.addEventListener('change', loadItems);
    if (yearBox) yearBox.addEventListener('change', function () {
      syncDays(yearBox, monthBox, dayBox);
    });
    if (monthBox) monthBox.addEventListener('change', function () {
      syncDays(yearBox, monthBox, dayBox);
    });

    syncDays(yearBox, monthBox, dayBox);

    form.addEventListener('submit', function () {
      boxes().forEach(function (box) {
        var inputs = Array.prototype.filter.call(listInputs(box), function (el) {
          return !el.disabled && el.parentNode && el.parentNode.style.display !== 'none';
        });
        var checked = inputs.filter(function (el) { return el.checked; });
        if (inputs.length && checked.length === inputs.length) {
          inputs.forEach(function (el) { el.disabled = true; });
        }
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
