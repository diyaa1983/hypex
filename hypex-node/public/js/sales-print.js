/**
 * طباعة Node 2027
 * - sheet: طباعة الصفحة كما هي (فواتير — ترتيب DOM محفوظ)
 * - iframe: ترويسة + محتوى منفصل (التقارير)
 */
(function () {
  'use strict';

  var LOGO_MAX = 72;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function baseUrl(path) {
    var b =
      typeof window.__HYPEX_BASE__ === 'string' && window.__HYPEX_BASE__
        ? window.__HYPEX_BASE__
        : '';
    if (b && b.charAt(b.length - 1) === '/') b = b.slice(0, -1);
    if (!path || path.charAt(0) !== '/') path = '/' + (path || '');
    return b + path;
  }

  function brandFromDom() {
    var b = document.body || {};
    return {
      company: b.getAttribute('data-hx-company') || 'Hypex',
      logo: b.getAttribute('data-hx-logo') || '',
      user: b.getAttribute('data-hx-user') || '—',
      title: b.getAttribute('data-hx-print-title') || document.title || 'تقرير',
    };
  }

  function fetchBrandThen(cb) {
    var fallback = brandFromDom();
    var url = baseUrl('/api/print-brand');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('brand http ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          if (data.companyName) fallback.company = String(data.companyName);
          if (data.logoUrl != null) fallback.logo = String(data.logoUrl || '');
          if (document.body) {
            document.body.setAttribute('data-hx-company', fallback.company);
            if (fallback.logo) document.body.setAttribute('data-hx-logo', fallback.logo);
            else document.body.removeAttribute('data-hx-logo');
          }
        }
        cb(fallback);
      })
      .catch(function () {
        cb(fallback);
      });
  }

  function stampNow() {
    var now = new Date();
    var pad = function (n) {
      return String(n).padStart(2, '0');
    };
    return (
      pad(now.getDate()) +
      '-' +
      pad(now.getMonth() + 1) +
      '-' +
      now.getFullYear() +
      ' ' +
      pad(now.getHours()) +
      ':' +
      pad(now.getMinutes())
    );
  }

  function moveTotalsToTableEnd(root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('table').forEach(function (table) {
      // جداول فواتير الطباعة: لا تُضف صف إجمالي داخل الجدول
      if (table.classList && table.classList.contains('inv-print-table')) return;
      var tf = table.tFoot || table.querySelector('tfoot');
      if (!tf) return;
      var tb =
        (table.tBodies && table.tBodies[table.tBodies.length - 1]) || null;
      if (!tb) {
        tb = document.createElement('tbody');
        table.appendChild(tb);
      }
      Array.prototype.slice.call(tf.querySelectorAll('tr')).forEach(function (tr) {
        tr.classList.add('hx-print-total-row');
        tb.appendChild(tr);
      });
      tf.remove();
    });
  }

  /** مجاميع فاتورة المبيعات دائماً بعد جدول البنود */
  function ensureInvoiceTotalsAfterTable(root) {
    if (!root || !root.querySelector) return;
    var scope = root.querySelector
      ? root.querySelector('.inv-print-doc, .ora-stmt') || root
      : root;
    if (!scope.querySelector) return;
    var lines = scope.querySelector('.inv-print-lines, .ora-stmt-body');
    var totals = scope.querySelector('.inv-print-totals');
    if (lines && totals && lines.parentNode) {
      lines.parentNode.appendChild(totals);
    }
    scope.querySelectorAll('.inv-print-table tr.hx-print-total-row').forEach(function (tr) {
      tr.remove();
    });
  }

  function getContentHtml() {
    var area =
      document.querySelector('.si-print-area') ||
      document.querySelector('.ora-stmt') ||
      document.querySelector('.hx-print-content') ||
      document.querySelector('main');
    if (!area) return '';
    var clone = area.cloneNode(true);
    clone
      .querySelectorAll(
        '.no-print, .si-rail, .ora-filters, script, style, .si-hero, .sidebar, .hx-doc-bar, .hx-doc-head'
      )
      .forEach(function (el) {
        el.remove();
      });
    ensureInvoiceTotalsAfterTable(clone);
    moveTotalsToTableEnd(clone);
    return clone.innerHTML;
  }

  function buildHeader(b, when) {
    var logo =
      b.logo !== ''
        ? '<img src="' +
          esc(b.logo) +
          '" alt="" width="' +
          LOGO_MAX +
          '" height="' +
          LOGO_MAX +
          '" style="display:block;max-width:' +
          LOGO_MAX +
          'px;max-height:' +
          LOGO_MAX +
          'px;width:auto;height:auto;object-fit:contain">'
        : '<span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid #333;font:800 14px Arial,sans-serif">H</span>';

    var userLine =
      b.user && b.user !== '—'
        ? ' · <span style="font-weight:600">' + esc(b.user) + '</span>'
        : '';

    return (
      '<header class="hx-print-head" style="margin:0 0 12px 0;padding:0 0 10px 0;border-bottom:1px solid #222">' +
      '<div style="display:flex;direction:ltr;align-items:center;justify-content:space-between;width:100%;gap:14px">' +
      '<div style="flex:0 0 auto;max-width:90px;max-height:72px;overflow:hidden">' +
      logo +
      '</div>' +
      '<div dir="rtl" style="flex:1 1 auto;text-align:right;font:800 15pt/1.3 Arial,Helvetica,sans-serif;color:#0f172a">' +
      esc(b.company) +
      '</div>' +
      '</div>' +
      '<div style="text-align:center;font:700 12pt/1.3 Arial,Helvetica,sans-serif;margin-top:10px;color:#1e293b">' +
      esc(b.title) +
      '</div>' +
      '<div dir="rtl" style="text-align:center;font:500 7.5pt Arial,Helvetica,sans-serif;color:#64748b;margin-top:4px">' +
      'طُبع <span dir="ltr">' +
      esc(when) +
      '</span>' +
      userLine +
      '</div>' +
      '</header>'
    );
  }

  function tableCss() {
    return (
      'table{width:100%;border-collapse:collapse;font-size:9pt}' +
      'th,td{border:1px solid #334155;padding:3px 4px;vertical-align:top}' +
      'th{background:#e2e8f0;font-weight:800}' +
      'thead{display:table-header-group}' +
      'tfoot{display:table-row-group}' +
      'tbody{display:table-row-group}' +
      'tr{page-break-inside:avoid;break-inside:avoid}' +
      'tr.hx-print-total-row,tr.ora-foot,tfoot tr{font-weight:800;background:#f1f5f9}' +
      'tr.hx-print-total-row td,tr.ora-foot td{border-top:2px solid #0f172a}' +
      '.empty,.muted{color:#64748b}' +
      '.ora-stmt-head,.inv-print-meta{display:block;margin:0 0 10px;padding:0 0 8px;border-bottom:1px solid #ccc}' +
      '.ora-stmt-head__main{margin:0 0 8px}' +
      '.ora-stmt-name{font:800 12pt Arial,Helvetica,sans-serif;margin:2px 0}' +
      '.ora-stmt-kicker{font-size:8pt;color:#334155;margin:0}' +
      '.ora-stmt-meta{font-size:9pt;color:#334155;margin:2px 0 0}' +
      '.inv-print-lines{display:block;margin:0 0 8px}' +
      '.inv-print-totals,.ora-stmt-totals.inv-print-totals{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin:12px 0 0!important;width:100%;page-break-inside:avoid}' +
      '.ora-stat{display:flex;flex-direction:column;gap:2px;border:1px solid #cbd5e1;padding:4px 6px;background:#fff}' +
      '.ora-stat span{font-size:7.5pt;color:#64748b;font-weight:700}' +
      '.ora-stat strong{font-size:9pt;font-weight:800;font-variant-numeric:tabular-nums}' +
      '.ora-stat--balance{background:#e8f5f4!important;border:2px solid #0f6e6a!important}' +
      '.ora-stat--balance span,.ora-stat--balance strong{color:#0a4f4c!important}' +
      '.ora-stmt-cheques{margin-top:10px;padding:6px 0 4px;border-top:1px dashed #000;border-bottom:1px dashed #000;background:transparent;color:#000}' +
      '.ora-stmt-cheques__title{margin:0 0 6px;font:800 10pt Arial,Helvetica,sans-serif;text-decoration:underline;text-align:right;color:#000;border:0}' +
      '.ora-stmt-chq-wrap{max-width:320px}' +
      '.ora-stmt-chq-table{width:100%;max-width:320px;border-collapse:collapse;table-layout:fixed;font:800 9pt Arial,Helvetica,sans-serif;color:#000}' +
      '.ora-stmt-chq-table th,.ora-stmt-chq-table td{border:0!important;background:transparent!important;font-weight:800!important;color:#000!important;padding:1px 4px;text-align:right}' +
      '.ora-stmt-chq-table thead th{text-decoration:underline;padding-bottom:4px}' +
      '.ora-stmt-chq-table td.col-money,.ora-stmt-chq-table th.col-money{text-align:left}' +
      '.ora-stmt-chq-table tfoot .ora-stmt-chq-total td{border-top:1px dashed #000!important;text-decoration:underline;padding-top:5px}' +
      '.ora-stmt-chq-table tfoot strong{font-weight:800;text-decoration:underline}' +
      '.si-surface,.ora-stmt-body,.inv-print-lines{border:1px solid #bbb;padding:0;margin:0 0 8px;overflow:visible!important}' +
      '.si-surface-head{padding:4px 6px;border-bottom:1px solid #ccc;font-weight:700}' +
      '.si-table-wrap,.ora-stmt,.hx-print-doc,.inv-print-doc{overflow:visible!important}' +
      '@page{size:A4 portrait;margin:10mm 8mm 12mm 8mm}' +
      'html,body{margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;color:#0f172a;background:#fff}' +
      '.hx-print-doc{padding-bottom:2mm;margin:0}' +
      '.hx-print-head{page-break-after:avoid}' +
      '.si-hero,.sidebar,.no-print,.si-rail,.ora-filters,.si-btn{display:none!important}' +
      'img{max-width:72px!important;max-height:72px!important;width:auto!important;height:auto!important;object-fit:contain!important}'
    );
  }

  function buildHtml(content, b) {
    var when = stampNow();
    return (
      '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">' +
      '<title>' +
      esc(b.title) +
      '</title><style>' +
      tableCss() +
      '</style></head><body>' +
      buildHeader(b, when) +
      '<div class="hx-print-doc">' +
      content +
      '</div>' +
      '</body></html>'
    );
  }

  function runStandalonePrint() {
    var content = getContentHtml();
    if (!content || !String(content).replace(/\s+/g, '')) {
      window.alert('لا يوجد محتوى للطباعة.');
      return;
    }

    fetchBrandThen(function (b) {
      var html = buildHtml(content, b);
      var frame = document.getElementById('hx-print-frame');
      if (frame) frame.remove();
      frame = document.createElement('iframe');
      frame.id = 'hx-print-frame';
      frame.setAttribute('title', 'print');
      frame.style.cssText =
        'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none';
      document.body.appendChild(frame);

      var win = frame.contentWindow;
      var doc = win.document;
      doc.open();
      doc.write(html);
      doc.close();

      var printed = false;
      function doPrint() {
        if (printed) return;
        printed = true;
        try {
          win.focus();
          win.print();
        } catch (e) {
          window.print();
        }
        setTimeout(function () {
          try {
            frame.remove();
          } catch (err) {
            /* ignore */
          }
        }, 1500);
      }

      var imgs = doc.images ? Array.prototype.slice.call(doc.images) : [];
      if (!imgs.length) {
        setTimeout(doPrint, 50);
        return;
      }
      var left = imgs.length;
      var done = function () {
        left -= 1;
        if (left <= 0) setTimeout(doPrint, 30);
      };
      imgs.forEach(function (img) {
        if (img.complete) done();
        else {
          img.onload = done;
          img.onerror = done;
        }
      });
      setTimeout(doPrint, 2500);
    });
  }

  function isSheetMode() {
    return document.body && document.body.getAttribute('data-hx-print-mode') === 'sheet';
  }

  /** طباعة صفحة الفاتورة كما تظهر — المجاميع تبقى تحت الجدول */
  function printCurrentSheet() {
    ensureInvoiceTotalsAfterTable(document);
    try {
      window.focus();
      window.print();
    } catch (e) {
      /* ignore */
    }
  }

  function runPrint() {
    if (isSheetMode()) printCurrentSheet();
    else runStandalonePrint();
  }

  function bind() {
    document.querySelectorAll('.si-btn--print, [data-print]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        runPrint();
      });
    });
    if (document.body && document.body.getAttribute('data-hx-auto-print') === '1') {
      setTimeout(runPrint, 400);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  window.HypexPrint = {
    run: runPrint,
    sheet: printCurrentSheet,
    iframe: runStandalonePrint,
  };
})();
