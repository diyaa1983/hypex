(function (global) {
  'use strict';

  function initOneSortableTable(table) {
    if (!table || table.getAttribute('data-sort-init') === '1') {
      return;
    }
    table.setAttribute('data-sort-init', '1');

    var tbody = table.querySelector('tbody');
    if (!tbody) {
      return;
    }

    var defaultSort = table.getAttribute('data-default-sort') || '';
    var defaultDir = table.getAttribute('data-default-dir') || 'asc';
    var noDefault = table.getAttribute('data-no-default-sort') === '1';

    function sortTypeFor(key) {
      var th = table.querySelector('.js-sort-th[data-sort="' + key + '"]');
      return th ? th.getAttribute('data-sort-type') || 'text' : 'text';
    }

    function compareValues(a, b, type, dir) {
      var mul = dir === 'desc' ? -1 : 1;
      if (type === 'number') {
        var na = parseFloat(String(a).replace(/,/g, ''));
        var nb = parseFloat(String(b).replace(/,/g, ''));
        if (!isFinite(na)) na = 0;
        if (!isFinite(nb)) nb = 0;
        if (na < nb) return -1 * mul;
        if (na > nb) return 1 * mul;
        return 0;
      }
      if (type === 'date') {
        var da = normalizeDate(a);
        var db = normalizeDate(b);
        if (da < db) return -1 * mul;
        if (da > db) return 1 * mul;
        return 0;
      }
      return String(a || '').localeCompare(String(b || ''), 'ar', {
        sensitivity: 'base',
        numeric: true,
      }) * mul;
    }

    function normalizeDate(v) {
      var s = String(v || '').trim();
      var m = s.match(/^(\d{2})-(\d{2})-(\d{4})$/);
      if (m) return m[3] + '-' + m[2] + '-' + m[1];
      return s;
    }

    function rowValue(tr, key) {
      var attr = tr.getAttribute('data-sort-' + key);
      if (attr !== null && attr !== undefined) return attr;
      var ths = table.querySelectorAll('thead th.js-sort-th');
      var idx = -1;
      for (var i = 0; i < ths.length; i += 1) {
        if (ths[i].getAttribute('data-sort') === key) {
          idx = Array.prototype.indexOf.call(table.querySelectorAll('thead th'), ths[i]);
          break;
        }
      }
      if (idx < 0) return '';
      var cell = tr.cells[idx];
      return cell ? String(cell.textContent || '').trim() : '';
    }

    function updateHeaderState(key, dir) {
      table.querySelectorAll('.js-sort-th').forEach(function (th) {
        th.classList.remove('is-sort-asc', 'is-sort-desc');
        th.removeAttribute('aria-sort');
      });
      var active = table.querySelector('.js-sort-th[data-sort="' + key + '"]');
      if (active) {
        active.classList.add(dir === 'desc' ? 'is-sort-desc' : 'is-sort-asc');
        active.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
      }
      table.setAttribute('data-sort-key', key);
      table.setAttribute('data-sort-dir', dir);
    }

    function renumberSeq() {
      var n = 0;
      Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-sort-row]'), function (tr) {
        n += 1;
        var seqCell = tr.querySelector('.col-seq');
        if (seqCell) seqCell.textContent = String(n);
        tr.setAttribute('data-sort-seq', String(n));
      });
    }

    /** ترتيب صفوف البيانات داخل كل مقطع؛ صفوف المجاميع/العناوين تبقى فاصلة */
    function sortBy(key, dir, type) {
      type = type || sortTypeFor(key);
      var all = Array.prototype.slice.call(tbody.rows);
      var blocks = [];
      var buf = [];
      function flush() {
        if (!buf.length) return;
        blocks.push({ kind: 'data', rows: buf });
        buf = [];
      }
      all.forEach(function (tr) {
        if (tr.hasAttribute('data-sort-row')) {
          buf.push(tr);
        } else {
          flush();
          blocks.push({ kind: 'sticky', rows: [tr] });
        }
      });
      flush();
      blocks.forEach(function (block) {
        if (block.kind !== 'data') return;
        block.rows.sort(function (ra, rb) {
          return compareValues(rowValue(ra, key), rowValue(rb, key), type, dir);
        });
      });
      blocks.forEach(function (block) {
        block.rows.forEach(function (tr) {
          tbody.appendChild(tr);
        });
      });
      renumberSeq();
      updateHeaderState(key, dir);
    }

    var thead = table.querySelector('thead');
    if (thead) {
      thead.addEventListener('click', function (ev) {
        var th = ev.target.closest('.js-sort-th');
        if (!th || !table.contains(th)) return;
        var key = th.getAttribute('data-sort');
        if (!key) return;
        var curKey = table.getAttribute('data-sort-key');
        var curDir = table.getAttribute('data-sort-dir') || 'asc';
        var dir = curKey === key && curDir === 'asc' ? 'desc' : 'asc';
        sortBy(key, dir, th.getAttribute('data-sort-type') || 'text');
      });
    }

    if (!noDefault && defaultSort && tbody.querySelector('tr[data-sort-row]')) {
      sortBy(defaultSort, defaultDir, sortTypeFor(defaultSort));
    }
  }

  function initReportSalesTableSort(root) {
    root = root || document;
    var tables = root.querySelectorAll('.js-sortable-report');
    tables.forEach(initOneSortableTable);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initReportSalesTableSort(document);
    });
  } else {
    initReportSalesTableSort(document);
  }

  window.addEventListener('afterprint', function () {
    initReportSalesTableSort(document);
  });

  global.initReportSalesTableSort = initReportSalesTableSort;
})(typeof window !== 'undefined' ? window : this);
