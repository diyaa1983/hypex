(function () {
  'use strict';

  function norm(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  function formatMoney(n) {
    var AF = window.AppFormat;
    if (AF && AF.fmt) {
      return AF.fmt(n);
    }
    var x = Number(n);
    if (!isFinite(x)) x = 0;
    return x.toFixed(3);
  }

  function initReportSalesItemFilter() {
    var root = document.querySelector('.report-sales-item-filter');
    var table = document.querySelector('.report-sales-table.js-sortable-report');
    if (!root || !table) return;

    var input = root.querySelector('.js-report-item-filter-inp');
    var clearBtn = root.querySelector('.js-report-item-filter-clear');
    var hint = root.querySelector('.js-report-item-filter-hint');
    var tbody = table.querySelector('tbody');
    var tfoot = table.querySelector('tfoot');
    if (!input || !tbody) return;

    var emptyRow = tbody.querySelector('tr.report-sales-filter-empty');

    function applyFilter() {
      var needle = norm(input.value);
      var visible = 0;
      var sumTotal = 0;
      var sumSub = 0;
      var seq = 0;

      tbody.querySelectorAll('tr[data-sort-row]').forEach(function (tr) {
        var hay = norm(tr.getAttribute('data-filter-items') || '');
        var match = needle === '' || hay.indexOf(needle) >= 0;
        tr.hidden = !match;
        if (match) {
          visible += 1;
          seq += 1;
          var seqCell = tr.querySelector('.col-seq');
          if (seqCell) seqCell.textContent = String(seq);
          sumTotal += parseFloat(tr.getAttribute('data-sort-total') || '0') || 0;
          sumSub += parseFloat(tr.getAttribute('data-sort-subtotal') || '0') || 0;
        }
      });

      if (emptyRow) {
        emptyRow.hidden = visible > 0 || needle === '';
      }

      if (tfoot) {
        var moneyCells = tfoot.querySelectorAll('.col-money');
        if (moneyCells.length >= 2) {
          moneyCells[0].textContent = formatMoney(sumTotal);
          moneyCells[1].textContent = formatMoney(sumSub);
        }
      }

      if (hint) {
        if (needle === '') {
          hint.textContent = '';
          hint.hidden = true;
        } else {
          hint.hidden = false;
          hint.textContent =
            visible > 0
              ? 'عرض ' + visible + ' فاتورة تحتوي على المادة المطلوبة'
              : 'لا توجد فواتير تحتوي على هذا البحث';
        }
      }
    }

    input.addEventListener('input', applyFilter);
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        applyFilter();
        input.focus();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReportSalesItemFilter);
  } else {
    initReportSalesItemFilter();
  }
})();
