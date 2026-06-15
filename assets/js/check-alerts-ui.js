(function (global) {
  'use strict';

  function initCheckAlertsUi(opts) {
    opts = opts || {};
    var jsonEl = document.getElementById(opts.jsonId || 'dashboard-checks-json');
    var modal = document.getElementById(opts.modalId || 'dashboard-check-modal');
    if (!jsonEl || !modal) {
      return null;
    }

    var bodyOpenClass = opts.bodyOpenClass || 'dashboard-check-modal-open';
    var checks = [];
    try {
      checks = JSON.parse(jsonEl.textContent || '[]') || [];
    } catch (e) {
      checks = [];
    }

    var apiReceipt = modal.getAttribute('data-api-receipt') || '';
    var backdrop = modal.querySelector('.dashboard-check-modal-backdrop');
    var closeBtn = modal.querySelector('.js-check-modal-close');
    var titleEl = modal.querySelector('.dashboard-check-modal-title');
    var bodyEl = modal.querySelector('.dashboard-check-modal-body');
    var footEl = modal.querySelector('.dashboard-check-modal-foot');

    function esc(s) {
      var d = document.createElement('div');
      d.textContent = s == null ? '' : String(s);
      return d.innerHTML;
    }

    function fmtMoney(n) {
      if (global.AppFormat && AppFormat.fmt) {
        return AppFormat.fmt(n);
      }
      var x = Number(n);
      return isFinite(x) ? x.toFixed(2) : '0.00';
    }

    function fmtDate(d) {
      if (global.AppFormat && AppFormat.formatDateDmY) {
        return AppFormat.formatDateDmY(d);
      }
      return d == null ? '' : String(d);
    }

    function findCheck(id) {
      id = parseInt(id, 10);
      for (var i = 0; i < checks.length; i++) {
        if (parseInt(checks[i].check_id, 10) === id) {
          return checks[i];
        }
      }
      return null;
    }

    function filterChecks(filter) {
      if (!filter || filter === 'all') {
        return checks.slice();
      }
      return checks.filter(function (c) {
        return (c.urgency || '') === filter;
      });
    }

    function openModal() {
      modal.hidden = false;
      document.body.classList.add(bodyOpenClass);
    }

    function closeModal() {
      modal.hidden = true;
      document.body.classList.remove(bodyOpenClass);
      if (bodyEl) {
        bodyEl.innerHTML = '';
      }
      if (footEl) {
        footEl.innerHTML = '';
      }
    }

    function renderDetailRows(rows) {
      var html =
        '<table class="dashboard-check-detail-table"><thead><tr>' +
        '<th>الحالة</th><th>رقم الشيك</th><th>البنك</th><th>العميل</th>' +
        '<th>سند القبض</th><th>تاريخ الاستحقاق</th><th>المبلغ</th>' +
        '</tr></thead><tbody>';
      rows.forEach(function (c) {
        var due = c.due_date ? fmtDate(c.due_date) : '—';
        html +=
          '<tr class="dashboard-check-detail-row js-check-detail-pick" data-check-id="' +
          esc(c.check_id) +
          '" tabindex="0" role="button">' +
          '<td><span class="dashboard-check-status dashboard-check-status--' +
          esc(c.urgency || 'pending') +
          '">' +
          esc(c.urgency_label || '') +
          '</span></td>' +
          '<td>' +
          esc(c.check_no || '—') +
          '</td>' +
          '<td>' +
          esc(c.bank_name || '—') +
          '</td>' +
          '<td>' +
          esc(c.party_name || '—') +
          '</td>' +
          '<td>' +
          esc(c.voucher_no || '') +
          '</td>' +
          '<td>' +
          esc(due) +
          '</td>' +
          '<td>' +
          esc(fmtMoney(c.amount)) +
          '</td>' +
          '</tr>';
      });
      html += '</tbody></table>';
      return html;
    }

    function showList(filter, title) {
      var rows = filterChecks(filter);
      if (titleEl) {
        titleEl.textContent = title;
      }
      if (!bodyEl) {
        return;
      }
      if (!rows.length) {
        bodyEl.innerHTML = '<p class="dashboard-empty">لا توجد شيكات في هذه الفئة.</p>';
        if (footEl) {
          footEl.innerHTML = '';
        }
        openModal();
        return;
      }
      bodyEl.innerHTML = renderDetailRows(rows);
      bodyEl.querySelectorAll('.js-check-detail-pick').forEach(function (tr) {
        tr.addEventListener('click', function () {
          var id = parseInt(tr.getAttribute('data-check-id'), 10);
          if (id > 0) {
            showSingle(id);
          }
        });
        tr.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            tr.click();
          }
        });
      });
      if (footEl) {
        footEl.innerHTML =
          '<span class="muted">اضغط على أي سطر لعرض التفاصيل الكاملة للشيك</span>';
      }
      openModal();
    }

    function buildSingleHtml(c, voucherExtra) {
      var v = voucherExtra || {};
      var due = c.due_date ? fmtDate(c.due_date) : '—';
      var vDate = c.voucher_date ? fmtDate(c.voucher_date) : '—';
      var daysTxt = '';
      if (c.days_until_due !== null && c.days_until_due !== undefined && c.due_date) {
        var d = parseInt(c.days_until_due, 10);
        if (d < 0) {
          daysTxt = 'متأخر ' + Math.abs(d) + ' يوم';
        } else if (d === 0) {
          daysTxt = 'مستحق اليوم';
        } else {
          daysTxt = 'بعد ' + d + ' يوم';
        }
      }

      var siblingHtml = '';
      var allChecks = v.checks || [];
      if (allChecks.length > 1) {
        siblingHtml =
          '<h4 class="dashboard-check-detail-subtitle">كل شيكات هذا السند</h4>' +
          '<table class="dashboard-check-detail-table"><thead><tr>' +
          '<th>رقم الشيك</th><th>البنك</th><th>الاستحقاق</th><th>المبلغ</th><th>ملاحظات</th>' +
          '</tr></thead><tbody>';
        allChecks.forEach(function (ch) {
          var hl =
            parseInt(ch.check_id, 10) === parseInt(c.check_id, 10)
              ? ' dashboard-check-detail-row--active'
              : '';
          siblingHtml +=
            '<tr class="dashboard-check-detail-row' +
            hl +
            '">' +
            '<td>' +
            esc(ch.check_no || '—') +
            '</td>' +
            '<td>' +
            esc(ch.bank_name || '—') +
            '</td>' +
            '<td>' +
            esc(ch.due_date_dmy || (ch.due_date ? fmtDate(ch.due_date) : '—')) +
            '</td>' +
            '<td>' +
            esc(fmtMoney(ch.check_amount)) +
            '</td>' +
            '<td>' +
            esc(ch.notes || '—') +
            '</td>' +
            '</tr>';
        });
        siblingHtml += '</tbody></table>';
      }

      return (
        '<div class="dashboard-check-detail-card">' +
        '<div class="dashboard-check-detail-head">' +
        '<span class="dashboard-check-status dashboard-check-status--' +
        esc(c.urgency || 'pending') +
        '">' +
        esc(c.urgency_label || '') +
        '</span>' +
        (daysTxt ? '<span class="dashboard-check-detail-days">' + esc(daysTxt) + '</span>' : '') +
        '</div>' +
        '<dl class="dashboard-check-detail-dl">' +
        '<div><dt>رقم الشيك</dt><dd>' +
        esc(c.check_no || '—') +
        '</dd></div>' +
        '<div><dt>البنك</dt><dd>' +
        esc(c.bank_name || '—') +
        '</dd></div>' +
        '<div><dt>المبلغ</dt><dd><strong>' +
        esc(fmtMoney(c.amount)) +
        '</strong></dd></div>' +
        '<div><dt>تاريخ الاستحقاق</dt><dd>' +
        esc(due) +
        '</dd></div>' +
        '<div><dt>العميل</dt><dd>' +
        esc(v.customer_name || c.party_name || '—') +
        '</dd></div>' +
        '<div><dt>سند القبض</dt><dd>' +
        esc(c.voucher_no || '') +
        ' — ' +
        esc(vDate) +
        '</dd></div>' +
        '<div><dt>مبلغ السند</dt><dd>' +
        esc(fmtMoney(v.amount != null ? v.amount : c.voucher_amount)) +
        '</dd></div>' +
        (v.sales_rep_name
          ? '<div><dt>المندوب</dt><dd>' + esc(v.sales_rep_name) + '</dd></div>'
          : '') +
        '<div><dt>ملاحظات الشيك</dt><dd>' +
        esc(c.notes || v.notes || '—') +
        '</dd></div>' +
        (v.notes && v.notes !== (c.notes || '')
          ? '<div><dt>ملاحظات السند</dt><dd>' + esc(v.notes) + '</dd></div>'
          : '') +
        '</dl>' +
        siblingHtml +
        '</div>'
      );
    }

    function showSingle(checkId) {
      var c = findCheck(checkId);
      if (!c) {
        return;
      }
      if (titleEl) {
        titleEl.textContent = 'تفاصيل الشيك' + (c.check_no ? ' — ' + c.check_no : '');
      }
      if (bodyEl) {
        bodyEl.innerHTML =
          '<p class="muted" style="text-align:center;padding:1rem">جاري التحميل…</p>';
      }
      if (footEl && c.url) {
        footEl.innerHTML =
          '<a class="btn btn-primary btn-sm" href="' + esc(c.url) + '">فتح سند القبض</a>';
      }
      openModal();

      function render(voucherExtra) {
        if (bodyEl) {
          bodyEl.innerHTML = buildSingleHtml(c, voucherExtra);
        }
      }

      render({ amount: c.voucher_amount, checks: [] });

      if (!apiReceipt || !(c.voucher_id > 0)) {
        return;
      }

      fetch(apiReceipt + '?id=' + encodeURIComponent(String(c.voucher_id)), {
        credentials: 'same-origin',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok || !data.voucher) {
            return;
          }
          var v = data.voucher;
          var mappedChecks = (v.checks || []).map(function (ch, idx) {
            return {
              check_id: idx,
              check_no: ch.check_no,
              bank_name: ch.bank_name,
              check_amount: ch.check_amount,
              due_date: ch.due_date,
              due_date_dmy: ch.due_date_dmy,
              notes: ch.notes,
            };
          });
          var match = (v.checks || []).find(function (ch) {
            var local = findCheck(checkId);
            if (!local) {
              return false;
            }
            if (local.check_no && ch.check_no && local.check_no === ch.check_no) {
              return true;
            }
            return (
              Math.abs(parseFloat(ch.check_amount) - parseFloat(local.amount)) < 0.0001 &&
              (ch.due_date || '') === (local.due_date || '')
            );
          });
          if (match) {
            c.notes = c.notes || match.notes || '';
          }
          render({
            amount: v.amount,
            customer_name: v.customer_name,
            sales_rep_name: v.sales_rep_name,
            notes: v.notes,
            checks: mappedChecks,
          });
        })
        .catch(function () {
          /* keep basic detail */
        });
    }

    function bindOpenTriggers(root) {
      (root || document).querySelectorAll('.js-check-alert-open').forEach(function (el) {
        if (el.getAttribute('data-check-alerts-bound') === '1') {
          return;
        }
        el.setAttribute('data-check-alerts-bound', '1');
        el.addEventListener('click', function (e) {
          if (e.target.closest('a')) {
            return;
          }
          var checkId = parseInt(el.getAttribute('data-check-id'), 10);
          if (checkId > 0) {
            e.preventDefault();
            if (global.AppHeaderCheckNotify && AppHeaderCheckNotify.closePanel) {
              AppHeaderCheckNotify.closePanel();
            }
            showSingle(checkId);
            return;
          }
          if (el.classList.contains('dashboard-check-row-click')) {
            var rowId = parseInt(el.getAttribute('data-check-id'), 10);
            if (rowId > 0) {
              e.preventDefault();
              showSingle(rowId);
              return;
            }
          }
          e.preventDefault();
          var filter = el.getAttribute('data-filter') || 'all';
          var title = el.getAttribute('data-title') || 'تفاصيل الشيكات';
          if (global.AppHeaderCheckNotify && AppHeaderCheckNotify.closePanel) {
            AppHeaderCheckNotify.closePanel();
          }
          if (filter === 'all' && checks.length === 1) {
            showSingle(parseInt(checks[0].check_id, 10));
          } else {
            showList(filter, title);
          }
        });
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            el.click();
          }
        });
      });
    }

    bindOpenTriggers(document);

    if (backdrop) {
      backdrop.addEventListener('click', closeModal);
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });

    return { showSingle: showSingle, showList: showList, bindOpenTriggers: bindOpenTriggers };
  }

  global.AppCheckAlertsUi = { init: initCheckAlertsUi };

  initCheckAlertsUi({
    jsonId: 'dashboard-checks-json',
    modalId: 'dashboard-check-modal',
    bodyOpenClass: 'dashboard-check-modal-open',
  });

  initCheckAlertsUi({
    jsonId: 'app-checks-json',
    modalId: 'app-check-notify-modal',
    bodyOpenClass: 'app-check-modal-open',
  });
})(typeof window !== 'undefined' ? window : this);
