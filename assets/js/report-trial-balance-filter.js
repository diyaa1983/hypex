(function () {
  'use strict';

  function norm(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  function digits(s) {
    return String(s || '').replace(/\D/g, '');
  }

  function rowMatches(haystack, needle) {
    if (needle === '') {
      return true;
    }
    var hay = norm(haystack);
    var n = norm(needle);
    if (hay.indexOf(n) >= 0) {
      return true;
    }
    var nDig = digits(n);
    if (nDig !== '' && digits(hay).indexOf(nDig) >= 0) {
      return true;
    }
    return false;
  }

  function initTrialBalanceAccountFilter() {
    var root = document.querySelector('.report-tb-account-filter');
    var table = document.querySelector('.report-trial-balance-table');
    if (!root || !table) {
      return;
    }

    var input = root.querySelector('.js-report-tb-filter-inp');
    var clearBtn = root.querySelector('.js-report-tb-filter-clear');
    var hint = root.querySelector('.js-report-tb-filter-hint');
    var tbody = table.querySelector('tbody');
    if (!input || !tbody) {
      return;
    }

    var emptyRow = tbody.querySelector('tr.report-tb-filter-empty');

    function applyFilter() {
      var needle = input.value;
      var visible = 0;

      tbody.querySelectorAll('tr.tb-data-row').forEach(function (tr) {
        var match = rowMatches(tr.getAttribute('data-tb-search') || '', needle);
        tr.hidden = !match;

        var next = tr.nextElementSibling;
        if (next && (next.classList.contains('tb-vat-detail-row') || next.classList.contains('tb-account-detail-row'))) {
          next.hidden = !match;
        }

        if (match) {
          visible += 1;
        }
      });

      if (emptyRow) {
        emptyRow.hidden = visible > 0 || norm(needle) === '';
      }

      if (hint) {
        if (norm(needle) === '') {
          hint.textContent = '';
          hint.hidden = true;
        } else {
          hint.hidden = false;
          hint.textContent =
            visible > 0
              ? 'عرض ' + visible + ' حساب/حسابات تطابق البحث'
              : 'لا يوجد حساب يطابق البحث';
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
    document.addEventListener('DOMContentLoaded', initTrialBalanceAccountFilter);
  } else {
    initTrialBalanceAccountFilter();
  }
})();
