(function () {
  'use strict';

  function initTableSearch() {
    var input = document.getElementById('item-stock-table-search');
    var table = document.getElementById('item-stock-ledger-table');
    if (!input || !table) return;

    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    function norm(s) {
      return String(s || '')
        .trim()
        .toLowerCase();
    }

    function applyFilter() {
      var needle = norm(input.value);
      var visible = 0;
      tbody.querySelectorAll('tr.item-stock-ledger-row').forEach(function (tr) {
        if (!needle) {
          tr.classList.remove('is-filtered-out');
          visible += 1;
          return;
        }
        var text = norm(tr.textContent);
        var show = text.indexOf(needle) >= 0;
        tr.classList.toggle('is-filtered-out', !show);
        if (show) visible += 1;
      });

      var empty = tbody.querySelector('.item-stock-ledger-filter-empty');
      if (needle && visible === 0) {
        if (!empty) {
          empty = document.createElement('tr');
          empty.className = 'item-stock-ledger-filter-empty';
          empty.innerHTML =
            '<td colspan="12" class="muted" style="text-align:center;padding:1.25rem;">لا توجد نتائج مطابقة للبحث.</td>';
          tbody.appendChild(empty);
        }
        empty.hidden = false;
      } else if (empty) {
        empty.hidden = true;
      }
    }

    input.addEventListener('input', applyFilter);
    input.addEventListener('search', applyFilter);
  }

  function initRowNavigation() {
    var table = document.getElementById('item-stock-ledger-table');
    if (!table) return;

    function openRow(tr) {
      var href = tr.getAttribute('data-href');
      if (!href) return;
      window.location.href = href;
    }

    table.addEventListener('click', function (e) {
      var tr = e.target.closest('tr.item-stock-ledger-row.is-clickable');
      if (!tr || tr.classList.contains('is-filtered-out')) return;
      openRow(tr);
    });

    table.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var tr = e.target.closest('tr.item-stock-ledger-row.is-clickable');
      if (!tr) return;
      e.preventDefault();
      openRow(tr);
    });
  }

  /** يمنع أي ترتيب عكسي — الصفوف تبقى كما أرسلها الخادمة (أقدم حركة أولاً). */
  function lockChronologicalRowOrder() {
    var table = document.getElementById('item-stock-ledger-table');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;
    var rows = Array.prototype.slice.call(
      tbody.querySelectorAll('tr.item-stock-ledger-row')
    );
    rows.forEach(function (tr) {
      tbody.appendChild(tr);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.querySelector('.item-stock-ledger-page')) return;
    lockChronologicalRowOrder();
    initTableSearch();
    initRowNavigation();
  });
})();
