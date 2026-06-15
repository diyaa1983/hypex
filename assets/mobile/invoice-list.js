(function () {
  'use strict';

  var cfg = window.MInvoiceList || {};
  var MDL = window.MobileDocList;
  var listEl = document.getElementById('m-inv-list');
  var hubEl = document.querySelector('.m-hub--invoice-list');
  var searchInp = document.getElementById('m-inv-list-search');
  var loadingEl = document.getElementById('m-inv-list-loading');
  var emptyEl = document.getElementById('m-inv-list-empty');
  var btnNew = document.getElementById('m-inv-list-new');
  var filterRadios = document.querySelectorAll('input[name="m_inv_filter"]');
  var timer = null;
  var printCache = {};

  if (!MDL) return;

  var stripIcon = cfg.stripIconHtml || '';

  function editLink(id) {
    var base = cfg.editUrl || cfg.newUrl || '';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(id));
  }

  var TB = window.MobileToolbar || {};
  var bar = MDL.createActionBar({
    hubEl: hubEl,
    barEl: TB.root ? TB.root() : null,
    titleEl: TB.titleEl ? TB.titleEl() : null,
    itemSelector: '.m-inv-strip',
    selectedClass: 'm-inv-strip--selected',
    editBtn: TB.btn ? TB.btn('edit') : null,
    printBtn: TB.btn ? TB.btn('print') : null,
    pdfBtn: TB.btn ? TB.btn('pdf') : null,
    deleteBtn: TB.btn ? TB.btn('delete') : null,
    onEdit: function (item) {
      window.location.href = editLink(item.id);
    },
    onPrint: function (item) {
      runPrint(item);
    },
    onPdf: function (item) {
      runPdf(item);
    },
    onDelete: function (item) {
      runDelete(item);
    },
  });

  function getFilter() {
    var r = document.querySelector('input[name="m_inv_filter"]:checked');
    return r ? r.value : 'all';
  }

  function viewLink(id) {
    var base = cfg.viewUrl || '';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(id));
  }

  function fetchPrintDoc(id) {
    if (printCache[id]) {
      return Promise.resolve(printCache[id]);
    }
    var url = (cfg.printApi || '') + '?id=' + encodeURIComponent(String(id));
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error('bad_response');
        }
        printCache[id] = data;
        return data;
      });
  }

  function runPrint(item) {
    var btn = TB.btn ? TB.btn('print') : null;
    if (btn) btn.disabled = true;
    fetchPrintDoc(item.id)
      .then(function (doc) {
        if (btn) btn.disabled = false;
        if (doc.html) MDL.printHtml(doc.html, 'm-inv-list-print-frame');
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الطباعة.');
      });
  }

  function runPdf(item) {
    var btn = TB.btn ? TB.btn('pdf') : null;
    if (btn) btn.disabled = true;
    fetchPrintDoc(item.id)
      .then(function (doc) {
        if (btn) btn.disabled = false;
        var fname =
          window.MobilePdfFilename && MobilePdfFilename.invoice
            ? MobilePdfFilename.invoice(item.no, item.customer)
            : 'فاتورة - ' + (item.no || 'doc') + '.pdf';
        return MDL.downloadPdf(doc, fname);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تصدير PDF.');
      });
  }

  function runDelete(item) {
    if (!cfg.canDelete || !cfg.deleteApi) return;
    bar.mobileConfirm('حذف الفاتورة؟ لا يمكن التراجع.').then(function (ok) {
      if (!ok) return;
      var btn = TB.btn ? TB.btn('delete') : null;
      if (btn) btn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf);
      fd.append('invoice_id', String(item.id));
      fetch(cfg.deleteApi, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (btn) btn.disabled = false;
          if (!data || !data.ok) {
            if (window.AppDialog && AppDialog.error) {
              AppDialog.error((data && data.message) || 'تعذر حذف الفاتورة.');
            }
            return;
          }
          delete printCache[item.id];
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم حذف الفاتورة.');
          }
          bar.select(null);
          load();
        })
        .catch(function () {
          if (btn) btn.disabled = false;
          if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  function buildTileItem(inv) {
    return {
      id: inv.id,
      no: inv.invoice_no || '',
      customer: inv.customer_name || '',
      title: (inv.invoice_no || '') + ' — ' + (inv.customer_name || '—'),
      subtitle: inv.customer_name || '',
      canDelete: !!(cfg.canDelete && !inv.is_posted && !inv.einv_sent),
      canEdit: !!(cfg.canEdit && !inv.is_posted && !inv.einv_sent),
      raw: inv,
    };
  }

  function render(rows) {
    if (!listEl) return;
    listEl.innerHTML = '';
    printCache = {};
    bar.select(null);
    if (!rows.length) {
      if (emptyEl) emptyEl.hidden = false;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;
    rows.forEach(function (inv) {
      var item = buildTileItem(inv);
      var posted = inv.is_posted
        ? '<span class="m-tag m-tag--ok">مرحّلة</span>'
        : '<span class="m-tag m-tag--warn">غير مرحّلة</span>';
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-inv-strip';
      tile.setAttribute('role', 'listitem');
      tile.setAttribute('aria-label', (inv.invoice_no || '') + ' ' + (inv.customer_name || ''));
      tile.innerHTML =
        '<span class="m-inv-strip-icon-wrap" aria-hidden="true">' +
        stripIcon +
        '</span>' +
        '<span class="m-inv-strip-body">' +
        '<span class="m-inv-strip-top">' +
        '<span class="m-inv-strip-no">' +
        MDL.escapeHtml(inv.invoice_no || '—') +
        '</span>' +
        '<span class="m-inv-strip-status">' +
        posted +
        '</span>' +
        '</span>' +
        '<span class="m-inv-strip-party">' +
        MDL.escapeHtml(inv.customer_name || '—') +
        '</span>' +
        '<span class="m-inv-strip-meta muted">' +
        MDL.escapeHtml(inv.invoice_date_dmy || inv.invoice_date || '') +
        (inv.payment_label ? ' · ' + MDL.escapeHtml(inv.payment_label) : '') +
        '</span>' +
        '</span>' +
        '<span class="m-inv-strip-amt">' +
        MDL.escapeHtml(inv.total_fmt || inv.total || '') +
        '</span>';
      tile.addEventListener('click', function () {
        var sel = bar.getSelected();
        if (sel && sel.id === inv.id) {
          window.location.href = viewLink(inv.id);
          return;
        }
        bar.select(item, tile);
      });
      listEl.appendChild(tile);
    });
  }

  function parseJsonResponse(r) {
    return r.json().catch(function () {
      return { ok: false, message: 'تعذر قراءة رد الخادم.' };
    });
  }

  function loadErrorMessage(err, fallback) {
    if (err && err.message && String(err.message).trim() !== '') {
      return String(err.message);
    }
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      return 'لا يوجد اتصال بالإنترنت.';
    }
    return fallback || 'تعذر الاتصال بالخادم.';
  }

  function load() {
    if (!cfg.listApi) {
      if (loadingEl) loadingEl.hidden = true;
      if (window.AppDialog && AppDialog.error) {
        AppDialog.error('عنوان قائمة الفواتير غير مضبوط.');
      }
      return;
    }
    var q = searchInp ? searchInp.value.trim() : '';
    var filter = getFilter();
    if (loadingEl) loadingEl.hidden = false;
    var url = cfg.listApi + '?filter=' + encodeURIComponent(filter);
    if (q) url += '&q=' + encodeURIComponent(q);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return parseJsonResponse(r).then(function (data) {
          if (!r.ok || !data || !data.ok) {
            throw new Error(
              (data && data.message) ||
                (r.status === 403 ? 'لا توجد صلاحية لعرض قائمة الفواتير.' : 'تعذر تحميل الفواتير.')
            );
          }
          return data;
        });
      })
      .then(function (data) {
        if (loadingEl) loadingEl.hidden = true;
        render(data.invoices || []);
      })
      .catch(function (err) {
        if (loadingEl) loadingEl.hidden = true;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(loadErrorMessage(err, 'تعذر الاتصال بالخادم.'));
        }
      });
  }

  if (searchInp) {
    searchInp.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(load, 280);
    });
  }
  filterRadios.forEach(function (radio) {
    radio.addEventListener('change', load);
  });
  if (btnNew && cfg.newUrl) {
    btnNew.addEventListener('click', function () {
      window.location.href = cfg.newUrl;
    });
  }

  load();
})();
