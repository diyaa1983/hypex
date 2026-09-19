(function () {
  'use strict';

  var cfg = window.MReceiptList || {};
  var MDL = window.MobileDocList;
  var listEl = document.getElementById('m-rc-list');
  var hubEl = document.querySelector('.m-hub--receipt-list');
  var searchInp = document.getElementById('m-rc-list-search');
  var loadingEl = document.getElementById('m-rc-list-loading');
  var emptyEl = document.getElementById('m-rc-list-empty');
  var btnNew = document.getElementById('m-rc-list-new');
  var filterRadios = document.querySelectorAll('input[name="m_rc_filter"]');
  var timer = null;
  var printCache = {};
  var rowsById = {};

  if (!MDL) return;

  var stripIcon = cfg.stripIconHtml || '';

  var TB = window.MobileToolbar || {};
  var bar = MDL.createActionBar({
    hubEl: hubEl,
    barEl: TB.root ? TB.root() : null,
    titleEl: TB.titleEl ? TB.titleEl() : null,
    itemSelector: '.m-rc-strip',
    selectedClass: 'm-rc-strip--selected',
    openBtn: TB.btn ? TB.btn('open') : null,
    printBtn: TB.btn ? TB.btn('print') : null,
    pdfBtn: TB.btn ? TB.btn('pdf') : null,
    deleteBtn: TB.btn ? TB.btn('delete') : null,
    onOpen: function (item) {
      window.location.href = viewLink(item.id);
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
    var r = document.querySelector('input[name="m_rc_filter"]:checked');
    return r ? r.value : 'all';
  }

  function viewLink(id) {
    var base = cfg.viewUrl || '';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(id));
  }

  function fetchPrintDoc(id, force) {
    if (!force && printCache[id]) {
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
        if (doc.html) MDL.printHtml(doc.html, 'm-rc-list-print-frame');
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الطباعة.');
      });
  }

  function runPdf(item) {
    var btn = TB.btn ? TB.btn('pdf') : null;
    if (btn) btn.disabled = true;
    fetchPrintDoc(item.id, true)
      .then(function (doc) {
        if (btn) btn.disabled = false;
        var fname =
          window.MobilePdfFilename && MobilePdfFilename.receipt
            ? MobilePdfFilename.receipt(item.no, item.customer)
            : 'سند قبض - ' + (item.no || 'doc') + '.pdf';
        return MDL.downloadPdf(doc, fname);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تصدير PDF.');
      });
  }

  function runDelete(item) {
    if (!cfg.canDelete || !cfg.deleteApi) return;
    bar.mobileConfirm('حذف سند القبض؟ لا يمكن التراجع.').then(function (ok) {
      if (!ok) return;
      var btn = TB.btn ? TB.btn('delete') : null;
      if (btn) btn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf);
      fd.append('voucher_id', String(item.id));
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
              AppDialog.error((data && data.message) || 'تعذر حذف السند.');
            }
            return;
          }
          delete printCache[item.id];
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم حذف السند.');
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

  function buildTileItem(rc) {
    return {
      id: rc.id,
      no: rc.voucher_no || '',
      customer: rc.party_name || '',
      title: (rc.voucher_no || '') + ' — ' + (rc.party_name || '—'),
      subtitle: rc.party_name || '',
      canDelete: !!(cfg.canDelete && !rc.is_posted),
      raw: rc,
    };
  }

  function render(rows) {
    if (!listEl) return;
    listEl.innerHTML = '';
    printCache = {};
    rowsById = {};
    bar.select(null);
    if (!rows.length) {
      if (emptyEl) emptyEl.hidden = false;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;
    rows.forEach(function (rc) {
      rowsById[rc.id] = rc;
      var item = buildTileItem(rc);
      var posted = rc.is_posted
        ? '<span class="m-tag m-tag--ok">مرحّل</span>'
        : '<span class="m-tag m-tag--warn">غير مرحّل</span>';
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-rc-strip';
      tile.setAttribute('role', 'listitem');
      tile.setAttribute('aria-label', (rc.voucher_no || '') + ' ' + (rc.party_name || ''));
      tile.innerHTML =
        '<span class="m-rc-strip-icon-wrap" aria-hidden="true">' +
        stripIcon +
        '</span>' +
        '<span class="m-rc-strip-body">' +
        '<span class="m-rc-strip-top">' +
        '<span class="m-rc-strip-no">' +
        MDL.escapeHtml(rc.voucher_no || '—') +
        '</span>' +
        '<span class="m-rc-strip-status">' +
        posted +
        '</span>' +
        '</span>' +
        '<span class="m-rc-strip-party">' +
        MDL.escapeHtml(rc.party_name || '—') +
        '</span>' +
        '<span class="m-rc-strip-meta muted">' +
        MDL.escapeHtml(rc.voucher_date_dmy || rc.voucher_date || '') +
        (rc.pay_label ? ' · ' + MDL.escapeHtml(rc.pay_label) : '') +
        '</span>' +
        '</span>' +
        '<span class="m-rc-strip-amt">' +
        MDL.escapeHtml(rc.amount_fmt || rc.amount || '') +
        '</span>';
      tile.addEventListener('click', function () {
        var sel = bar.getSelected();
        if (sel && sel.id === rc.id) {
          window.location.href = viewLink(rc.id);
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
        AppDialog.error('عنوان قائمة السندات غير مضبوط.');
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
                (r.status === 403 ? 'لا توجد صلاحية لعرض قائمة السندات.' : 'تعذر تحميل السندات.')
            );
          }
          return data;
        });
      })
      .then(function (data) {
        if (loadingEl) loadingEl.hidden = true;
        render(data.receipts || []);
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
