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
  var timer = null;
  var printCache = {};
  var bootAttempts = 0;
  var TB = window.MobileToolbar || {};
  var bar = null;
  var photoArchive = null;
  var selectedMoveId = 0;
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

  function ensureBar() {
    var MDL = getMdl();
    if (!MDL || bar) {
      return MDL;
    }
    var vis = { print: true, pdf: true };
    if (cfg.canArchive) vis.archive = true;
    if (TB.show) TB.show(vis, { title: 'اختر عهدة من القائمة' });

    bar = MDL.createActionBar({
      hubEl: hubEl,
      barEl: TB.root ? TB.root() : null,
      titleEl: TB.titleEl ? TB.titleEl() : null,
      itemSelector: '.m-rep-custody-strip',
      selectedClass: 'm-rep-custody-strip--selected',
      printBtn: TB.btn ? TB.btn('print') : null,
      pdfBtn: TB.btn ? TB.btn('pdf') : null,
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
          return true;
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
    var MDL = getMdl();
    if (!MDL) return;
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
      title: (mv.move_no || '') + ' — ' + (mv.direction_label || 'تحميل عهدة'),
      subtitle: (mv.line_count_fmt || '0') + ' مادة',
      raw: mv,
    };
  }

  function render(rows) {
    var MDL = ensureBar();
    if (!MDL || !listEl || !bar) return;
    listEl.innerHTML = '';
    printCache = {};
    selectedMoveId = 0;
    bar.select(null);
    if (!rows.length) {
      if (emptyEl) emptyEl.hidden = false;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;
    rows.forEach(function (mv) {
      var item = buildTileItem(mv);
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'm-inv-strip m-rep-custody-strip';
      tile.setAttribute('role', 'listitem');
      tile.setAttribute('aria-label', (mv.move_no || '') + ' ' + (mv.direction_label || ''));
      tile.innerHTML =
        '<span class="m-inv-strip-icon-wrap" aria-hidden="true">' +
        stripIcon +
        '</span>' +
        '<span class="m-inv-strip-body">' +
        '<span class="m-inv-strip-top">' +
        '<span class="m-inv-strip-no">' +
        MDL.escapeHtml(mv.move_no || '—') +
        '</span>' +
        '<span class="m-inv-strip-status"><span class="m-tag m-tag--ok">مرحّلة</span></span>' +
        '</span>' +
        '<span class="m-inv-strip-party">' +
        MDL.escapeHtml(mv.direction_label || 'تحميل عهدة') +
        '</span>' +
        '<span class="m-inv-strip-meta muted">' +
        MDL.escapeHtml(mv.move_date_dmy || mv.move_date || '') +
        ' · ' +
        MDL.escapeHtml((mv.line_count_fmt || '0') + ' مادة') +
        '</span>' +
        '</span>';
      tile.addEventListener('click', function () {
        var sel = bar.getSelected();
        if (sel && sel.id === mv.id) {
          runPdf(item);
          return;
        }
        selectedMoveId = mv.id;
        bar.select(item, tile);
        if (photoArchive) photoArchive.refreshMeta();
      });
      listEl.appendChild(tile);
    });
  }

  function load() {
    if (!cfg.listApi) {
      failBoot('عنوان القائمة غير مضبوط.');
      return;
    }
    var q = searchInp ? searchInp.value.trim() : '';
    if (loadingEl) {
      loadingEl.hidden = false;
      loadingEl.textContent = 'جاري التحميل...';
    }
    var url = cfg.listApi;
    if (q) url += (url.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (loadingEl) loadingEl.hidden = true;
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر تحميل القائمة.');
        }
        render(data.moves || []);
      })
      .catch(function (err) {
        if (loadingEl) loadingEl.hidden = true;
        if (window.AppDialog && AppDialog.error) {
          AppDialog.error(err && err.message ? err.message : 'تعذر الاتصال بالخادم.');
        }
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
