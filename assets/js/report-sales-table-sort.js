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

    var defaultSort = table.getAttribute('data-default-sort') || 'customer_name';
    var defaultDir = table.getAttribute('data-default-dir') || 'asc';

    function dataRows() {
      return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-sort-row]'));
    }

    function sortTypeFor(key) {
      var th = table.querySelector('.js-sort-th[data-sort="' + key + '"]');
      return th ? th.getAttribute('data-sort-type') || 'text' : 'text';
    }

    function compareValues(a, b, type, dir) {
      var mul = dir === 'desc' ? -1 : 1;
      if (type === 'number') {
        var na = parseFloat(a);
        var nb = parseFloat(b);
        if (!isFinite(na)) na = 0;
        if (!isFinite(nb)) nb = 0;
        if (na < nb) return -1 * mul;
        if (na > nb) return 1 * mul;
        return 0;
      }
      if (type === 'date') {
        var da = String(a || '');
        var db = String(b || '');
        if (da < db) return -1 * mul;
        if (da > db) return 1 * mul;
        return 0;
      }
      return String(a || '').localeCompare(String(b || ''), 'ar', {
        sensitivity: 'base',
        numeric: true,
      }) * mul;
    }

    function rowValue(tr, key) {
      return tr.getAttribute('data-sort-' + key) || '';
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
      dataRows().forEach(function (tr, idx) {
        var seqCell = tr.querySelector('.col-seq');
        if (seqCell) {
          seqCell.textContent = String(idx + 1);
        }
        tr.setAttribute('data-sort-seq', String(idx + 1));
      });
    }

    function sortBy(key, dir, type) {
      var rows = dataRows();
      if (!rows.length) {
        return;
      }
      type = type || sortTypeFor(key);
      rows.sort(function (ra, rb) {
        return compareValues(rowValue(ra, key), rowValue(rb, key), type, dir);
      });
      rows.forEach(function (tr) {
        tbody.appendChild(tr);
      });
      renumberSeq();
      updateHeaderState(key, dir);
    }

    var thead = table.querySelector('thead');
    if (thead) {
      thead.addEventListener('click', function (ev) {
        var th = ev.target.closest('.js-sort-th');
        if (!th || !table.contains(th)) {
          return;
        }
        var key = th.getAttribute('data-sort');
        if (!key) {
          return;
        }
        var curKey = table.getAttribute('data-sort-key');
        var curDir = table.getAttribute('data-sort-dir') || 'asc';
        var dir = curKey === key && curDir === 'asc' ? 'desc' : 'asc';
        sortBy(key, dir, th.getAttribute('data-sort-type') || 'text');
      });
    }

    if (dataRows().length) {
      sortBy(defaultSort, defaultDir, sortTypeFor(defaultSort));
    }
  }

  function initReportSalesTableSort(root) {
    root = root || document;
    var tables = root.querySelectorAll('.report-sales-table.js-sortable-report');
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
