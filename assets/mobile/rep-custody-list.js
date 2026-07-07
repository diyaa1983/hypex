(function () {
  'use strict';

  var cfg = window.MRepCustodyList || {};
  var listEl = document.getElementById('m-rep-custody-list');
  var hubEl = document.querySelector('.m-hub--rep-custody-list');
  var searchInp = document.getElementById('m-rep-custody-list-search');
  var searchBtn = document.getElementById('m-rep-custody-list-search-btn');
  var loadingEl = document.getElementById('m-rep-custody-list-loading');
  var emptyEl = document.getElementById('m-rep-custody-list-empty');
  var btnNew = document.getElementById('m-rep-custody-list-new');
  var filterRadios = document.querySelectorAll('input[name="m_rep_custody_filter"]');
  var timer = null;
  var printCache = {};
  var bootAttempts = 0;
  var TB = window.MobileToolbar || {};
  var bar = null;
  var photoArchive = null;
  var selectedMoveId = 0;
  var selectedIsPosted = false;
  var stripIcon = cfg.stripIconHtml || '';

  function getMdl() {
    return window.MobileDocList || null;
  }

  function failBoot(msg) {
    if (loadingEl) {
      loadingEl.hidden = false;
      loadingEl.textContent = msg;
    }
  }

  function getFilter() {
    var r = document.querySelector('input[name="m_rep_custody_filter"]:checked');
    return r ? r.value : 'all';
  }

  function editLink(id) {
    var base = cfg.editUrl || cfg.newUrl || '';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(id));
  }

  function ensureBar() {
    var MDL = getMdl();
    if (!MDL || bar) {
      return MDL;
    }
    bar = MDL.createActionBar({
      hubEl: hubEl,
      barEl: TB.root ? TB.root() : null,
      titleEl: TB.titleEl ? TB.titleEl() : null,
      itemSelector: '.m-inv-strip',
      selectedClass: 'm-inv-strip--selected',
      editBtn: TB.btn ? TB.btn('edit') : null,
      printBtn: TB.btn ? TB.btn('print') : null,
      pdfBtn: TB.btn ? TB.btn('pdf') : null,
      onEdit: function (item) {
        window.location.href = editLink(item.id);
      },
      onPrint: function (item) {
        runPrint(item);
      },
      onPdf: function (item) {
        runPdf(item);
      },
    });

    if (cfg.canArchive && window.MobileInvoicePhotoArchive) {
      photoArchive = MobileInvoicePhotoArchive.create({
        apiUrl: cfg.archiveApi,
        csrf: cfg.csrf,
        kind: 'warehouse_move',
        getInvoiceId: function () {
          return selectedMoveId;
        },
        getInvoiceLabel: function () {
          var sel = bar && bar.getSelected ? bar.getSelected() : null;
          return sel && sel.no ? String(sel.no) : '';
        },
        isLocked: function () {
          return selectedIsPosted;
        },
      });
      photoArchive.bindToolbar(TB);
    }

    return MDL;
  }

  function fetchPrintDoc(id) {
    if (printCache[id]) {
      return Promise.resolve(printCache[id]);
    }
    var url =
      (cfg.printApi || '') +
      '?id=' +
      encodeURIComponent(String(id)) +
      '&direction=load';
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
    var MDL = getMdl();
    if (!MDL) return;
    var btn = TB.btn ? TB.btn('print') : null;
    if (btn) btn.disabled = true;
    fetchPrintDoc(item.id)
      .then(function (doc) {
        if (btn) btn.disabled = false;
        if (doc.html) MDL.printHtml(doc.html, 'm-rep-custody-list-print-frame');
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (window.AppDialog && AppDialog.error) AppDialog.error('تعذر الطباعة.');
      });
  }

  function runPdf(item) {
    var btn = TB.btn ? TB.btn('pdf') : null;
    if (btn) btn.disabled = true;
    var pdfUrl =
      (cfg.pdfApi || '') +
      '?id=' +
      encodeURIComponent(String(item.id)) +
      '&direction=load';
    if (btn) btn.disabled = false;
    window.open(pdfUrl, '_blank', 'noopener');
  }

  function buildTileItem(mv) {
    return {
      id: mv.id,
      no: mv.move_no || '',
      title: (mv.move_no || '') + ' — ' + (mv.rep_name || mv.direction_label || 'تحميل عهدة'),
      subtitle: mv.rep_name || mv.direction_label || '',
      canEdit: !!(cfg.canEdit && !mv.is_posted),
      raw: mv,
    };
  }

  function render(rows) {
    var MDL = ensureBar();
    if (!MDL || !listEl || !bar) return;
    listEl.innerHTML = '';
    printCache = {};
    selectedMoveId = 0;
    selectedIsPosted = false;
    bar.select(null);
    if (!rows.length) {
      if (emptyEl) emptyEl.hidden = false;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;
    rows.forEach(function (mv) {
      var item = buildTileItem(mv);
      var posted = mv.is_posted
        ? '<span class="m-tag m-tag--ok">مرحّلة</span>'
        : '<span class="m-tag m-tag--warn">غير مرحّلة</span>';
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-inv-strip';
      tile.setAttribute('role', 'listitem');
      tile.setAttribute(
        'aria-label',
        (mv.move_no || '') + ' ' + (mv.rep_name || mv.direction_label || '')
      );
      tile.innerHTML =
        '<span class="m-inv-strip-icon-wrap" aria-hidden="true">' +
        stripIcon +
        '</span>' +
        '<span class="m-inv-strip-body">' +
        '<span class="m-inv-strip-top">' +
        '<span class="m-inv-strip-no">' +
        MDL.escapeHtml(mv.move_no || '—') +
        '</span>' +
        '<span class="m-inv-strip-status">' +
        posted +
        '</span>' +
        '</span>' +
        '<span class="m-inv-strip-party">' +
        MDL.escapeHtml(mv.rep_name || mv.direction_label || 'تحميل عهدة') +
        '</span>' +
        '<span class="m-inv-strip-meta muted">' +
        MDL.escapeHtml(mv.move_date_dmy || mv.move_date || '') +
        ' · ' +
        MDL.escapeHtml(mv.direction_label || 'تحميل عهدة') +
        '</span>' +
        '</span>' +
        '<span class="m-inv-strip-amt">' +
        MDL.escapeHtml((mv.line_count_fmt || '0') + ' مادة') +
        '</span>';
      tile.addEventListener('click', function () {
        var sel = bar.getSelected();
        if (sel && sel.id === mv.id) {
          if (!mv.is_posted && cfg.editUrl) {
            window.location.href = editLink(mv.id);
            return;
          }
          if (mv.is_posted) {
            runPdf(item);
          }
          return;
        }
        selectedMoveId = mv.id;
        selectedIsPosted = !!mv.is_posted;
        bar.select(item, tile);
        if (photoArchive) photoArchive.refreshMeta();
      });
      listEl.appendChild(tile);
    });
  }

  function loadErrorMessage(err, fallback) {
    if (err && err.name === 'AbortError') {
      return 'انتهت مهلة التحميل — تحقق من الاتصال بالسيرفر.';
    }
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
      failBoot('عنوان القائمة غير مضبوط.');
      return;
    }
    var q = searchInp ? searchInp.value.trim() : '';
    var filter = getFilter();
    if (loadingEl) {
      loadingEl.hidden = false;
      loadingEl.textContent = 'جاري التحميل...';
    }
    var url = cfg.listApi + '?filter=' + encodeURIComponent(filter);
    if (q) url += '&q=' + encodeURIComponent(q);
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timeoutId = ctrl
      ? setTimeout(function () {
          ctrl.abort();
        }, 25000)
      : null;
    fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: ctrl ? ctrl.signal : undefined,
    })
      .then(function (r) {
        return r.json().then(function (data) {
          if (!r.ok || !data || !data.ok) {
            throw new Error(
              (data && data.message) ||
                (r.status === 403 ? 'لا توجد صلاحية لعرض القائمة.' : 'تعذر تحميل العهود.')
            );
          }
          return data;
        });
      })
      .then(function (data) {
        if (loadingEl) loadingEl.hidden = true;
        render(data.moves || []);
      })
      .catch(function (err) {
        if (loadingEl) loadingEl.hidden = true;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(loadErrorMessage(err, 'تعذر الاتصال بالخادم.'));
        }
      })
      .finally(function () {
        if (timeoutId) clearTimeout(timeoutId);
      });
  }

  function runSearch() {
    clearTimeout(timer);
    if (searchInp) searchInp.blur();
    load();
  }

  function bindUi() {
    if (searchInp) {
      searchInp.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(load, 280);
      });
      searchInp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          runSearch();
        }
      });
    }
    if (searchBtn) searchBtn.addEventListener('click', runSearch);
    filterRadios.forEach(function (radio) {
      radio.addEventListener('change', load);
    });
    if (btnNew && cfg.newUrl) {
      btnNew.addEventListener('click', function () {
        window.location.href = cfg.newUrl;
      });
    }
    load();
  }

  function boot() {
    bootAttempts += 1;
    if (!getMdl()) {
      if (bootAttempts < 80) {
        setTimeout(boot, 50);
        return;
      }
      failBoot('تعذر تحميل القائمة — أعد تحميل الصفحة.');
      return;
    }
    ensureBar();
    bindUi();
  }

  boot();
})();
