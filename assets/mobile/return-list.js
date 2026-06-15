(function () {
  'use strict';

  var cfg = window.MReturnList || {};
  var MDL = window.MobileDocList;
  var listEl = document.getElementById('m-ret-list');
  var hubEl = document.querySelector('.m-hub--return-list');
  var searchInp = document.getElementById('m-ret-list-search');
  var loadingEl = document.getElementById('m-ret-list-loading');
  var emptyEl = document.getElementById('m-ret-list-empty');
  var btnNew = document.getElementById('m-ret-list-new');
  var filterRadios = document.querySelectorAll('input[name="m_ret_filter"]');
  var timer = null;
  var printCache = {};

  if (!MDL) return;

  var stripIcon = cfg.stripIconHtml || '';
  var TB = window.MobileToolbar || {};

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
        if (doc.html) MDL.printHtml(doc.html, 'm-ret-list-print-frame');
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
          window.MobilePdfFilename && MobilePdfFilename.salesReturn
            ? MobilePdfFilename.salesReturn(item.no, item.customer)
            : 'مرتجع مبيعات - ' + (item.no || 'doc') + '.pdf';
        return MDL.downloadPdf(doc, fname);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر تصدير PDF.');
      });
  }

  var bar = MDL.createActionBar({
    hubEl: hubEl,
    barEl: TB.root ? TB.root() : null,
    titleEl: TB.titleEl ? TB.titleEl() : null,
    itemSelector: '.m-ret-strip',
    selectedClass: 'm-ret-strip--selected',
    openBtn: TB.btn ? TB.btn('open') : null,
    editBtn: TB.btn ? TB.btn('edit') : null,
    printBtn: TB.btn ? TB.btn('print') : null,
    pdfBtn: TB.btn ? TB.btn('pdf') : null,
    deleteBtn: TB.btn ? TB.btn('delete') : null,
    onOpen: function (item) {
      window.location.href = viewLink(item.id);
    },
    onEdit: function (item) {
      window.location.href = viewLink(item.id) + '&edit=1';
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
    var r = document.querySelector('input[name="m_ret_filter"]:checked');
    return r ? r.value : 'all';
  }

  function viewLink(id) {
    var base = cfg.viewUrl || '';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(id));
  }

  function buildTileItem(ret) {
    return {
      id: ret.id,
      no: ret.return_no || '',
      customer: ret.customer_name || '',
      title: (ret.return_no || '') + ' — ' + (ret.customer_name || '—'),
      subtitle: ret.ref_invoice_no ? 'فاتورة ' + ret.ref_invoice_no : '',
      canDelete: !!(cfg.canDelete && !ret.is_posted),
      canEdit: !!(cfg.canEdit && !ret.is_posted),
      raw: ret,
    };
  }

  function runPost(item) {
    if (!cfg.canPost || !cfg.postApi || item.raw.is_posted) return;
    bar.mobileConfirm('ترحيل مرتجع المبيعات؟').then(function (ok) {
      if (!ok) return;
      var postBtn = TB.btn ? TB.btn('post') : null;
      if (postBtn) postBtn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf || '');
      fd.append('return_id', String(item.id));
      fetch(cfg.postApi, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (postBtn) postBtn.disabled = false;
          if (!data || !data.ok) {
            throw new Error((data && data.message) || 'تعذر الترحيل.');
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم الترحيل.');
          }
          load();
        })
        .catch(function (err) {
          if (postBtn) postBtn.disabled = false;
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error(err.message || 'تعذر الترحيل.');
          }
        });
    });
  }

  function runDelete(item) {
    if (!cfg.canDelete || !cfg.deleteApi) return;
    bar.mobileConfirm('حذف مرتجع المبيعات؟ لا يمكن التراجع.').then(function (ok) {
      if (!ok) return;
      var btn = TB.btn ? TB.btn('delete') : null;
      if (btn) btn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', cfg.csrf || '');
      fd.append('return_id', String(item.id));
      fetch(cfg.deleteApi, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (btn) btn.disabled = false;
          if (!data || !data.ok) {
            throw new Error((data && data.message) || 'تعذر الحذف.');
          }
          if (window.AppDialog && AppDialog.success) {
            AppDialog.success((data && data.message) || 'تم الحذف.');
          }
          delete printCache[item.id];
          bar.select(null);
          load();
        })
        .catch(function (err) {
          if (btn) btn.disabled = false;
          if (window.AppDialog && AppDialog.error) {
            AppDialog.error(err.message || 'تعذر الحذف.');
          }
        });
    });
  }

  function refreshToolbarForSelection(item) {
    if (!TB.show || !item) return;
    var vis = { open: true, print: true, pdf: true };
    if (item.canEdit) vis.edit = true;
    if (cfg.canPost && !item.raw.is_posted) vis.post = true;
    if (item.canDelete) vis.delete = true;
    var cols = 0;
    Object.keys(vis).forEach(function (k) {
      if (vis[k]) cols++;
    });
    TB.show(vis, { title: item.title, cols: cols >= 2 ? cols : undefined });
    var postBtn = TB.btn ? TB.btn('post') : null;
    if (postBtn && !postBtn._mRetBound) {
      postBtn._mRetBound = true;
      postBtn.addEventListener('click', function () {
        var sel = bar.getSelected();
        if (sel) runPost(sel);
      });
    }
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
    rows.forEach(function (ret) {
      var item = buildTileItem(ret);
      var posted = ret.is_posted
        ? '<span class="m-tag m-tag--ok">مرحّل</span>'
        : '<span class="m-tag m-tag--warn">غير مرحّل</span>';
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-ret-strip';
      tile.setAttribute('role', 'listitem');
      tile.innerHTML =
        '<span class="m-ret-strip-icon-wrap" aria-hidden="true">' +
        stripIcon +
        '</span>' +
        '<span class="m-ret-strip-body">' +
        '<span class="m-ret-strip-top">' +
        '<span class="m-ret-strip-no">' +
        MDL.escapeHtml(ret.return_no || '—') +
        '</span>' +
        '<span class="m-ret-strip-status">' +
        posted +
        '</span>' +
        '</span>' +
        '<span class="m-ret-strip-party">' +
        MDL.escapeHtml(ret.customer_name || '—') +
        '</span>' +
        '<span class="m-ret-strip-meta muted">' +
        MDL.escapeHtml(ret.return_date_dmy || '') +
        (ret.ref_invoice_no ? ' · فاتورة ' + MDL.escapeHtml(ret.ref_invoice_no) : '') +
        '</span>' +
        '</span>' +
        '<span class="m-ret-strip-amt">' +
        MDL.escapeHtml(ret.total_fmt || '') +
        '</span>';
      tile.addEventListener('click', function () {
        var sel = bar.getSelected();
        if (sel && sel.id === ret.id) {
          window.location.href = viewLink(ret.id);
          return;
        }
        bar.select(item, tile);
        refreshToolbarForSelection(item);
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
    return fallback || 'تعذر الاتصال بالخادم.';
  }

  function load() {
    if (!cfg.listApi) return;
    if (loadingEl) loadingEl.hidden = false;
    if (emptyEl) emptyEl.hidden = true;
    var url =
      cfg.listApi +
      '?filter=' +
      encodeURIComponent(getFilter()) +
      '&q=' +
      encodeURIComponent((searchInp && searchInp.value) || '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return parseJsonResponse(r).then(function (data) {
          if (!r.ok || !data || !data.ok) {
            throw new Error(
              (data && data.message) ||
                (r.status === 403 ? 'لا توجد صلاحية.' : 'تعذر تحميل القائمة.')
            );
          }
          return data;
        });
      })
      .then(function (data) {
        if (loadingEl) loadingEl.hidden = true;
        render(data.returns || []);
      })
      .catch(function (err) {
        if (loadingEl) loadingEl.hidden = true;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(loadErrorMessage(err, 'تعذر تحميل المرتجعات.'));
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
