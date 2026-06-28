(function () {
  'use strict';

  var page = document.querySelector('.fin-outgoing-checks-page');
  if (!page) return;

  var printApi = page.getAttribute('data-print-check-api') || '';
  var printCssUrl = page.getAttribute('data-print-check-css') || '';
  var activeCheckHtml = '';
  var activeCheckTitle = 'شيك صادر';

  function alertMsg(msg, type) {
    if (window.AppDialog && AppDialog.alert) {
      AppDialog.alert(msg, { type: type || 'warning' });
    } else {
      alert(msg);
    }
  }

  function isCheckPreviewOpen() {
    var overlay = document.getElementById('fin-oc-print-overlay');
    return overlay && !overlay.hidden;
  }

  function closeCheckPreview() {
    var overlay = document.getElementById('fin-oc-print-overlay');
    if (overlay) {
      overlay.hidden = true;
    }
    activeCheckHtml = '';
    activeCheckTitle = 'شيك صادر';
  }

  function buildDocumentHtml(innerHtml, title) {
    title = title || 'شيك صادر';
    var css = printCssUrl ? '<link rel="stylesheet" href="' + printCssUrl + '">' : '';
    var pageSize =
      '<style>@page{size:17.8cm 8.9cm;margin:0.35cm;}html,body{margin:0;padding:0;}</style>';
    return (
      '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">' +
      '<title>' +
      title +
      '</title>' +
      css +
      pageSize +
      '</head><body class="bank-check-page">' +
      innerHtml +
      '</body></html>'
    );
  }

  function getPrintFrame() {
    var frame = document.getElementById('fin-oc-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'fin-oc-print-frame';
      frame.className = 'sales-inv-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      document.body.appendChild(frame);
    }
    return frame;
  }

  function printHtmlInFrame(fullHtml) {
    var frame = getPrintFrame();
    var win = frame.contentWindow;
    win.document.open();
    win.document.write(fullHtml);
    win.document.close();
    setTimeout(function () {
      try {
        win.focus();
        win.print();
      } catch (e) {}
    }, 250);
  }

  function fetchCheckPrintHtml(checkId) {
    if (!printApi || checkId < 1) {
      return Promise.resolve(null);
    }
    var url =
      printApi + (printApi.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(checkId)) + '&embed=1';
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return null;
      });
  }

  function showCheckPrintPreview(html, title) {
    activeCheckHtml = html || '';
    activeCheckTitle = title || 'شيك صادر';
    var preview = document.getElementById('fin-oc-print-preview');
    var overlay = document.getElementById('fin-oc-print-overlay');
    var titleEl = document.getElementById('fin-oc-print-overlay-title');
    if (!preview || !overlay) {
      if (activeCheckHtml) {
        printHtmlInFrame(buildDocumentHtml(activeCheckHtml, activeCheckTitle));
      }
      return;
    }
    preview.innerHTML = activeCheckHtml;
    if (titleEl) {
      titleEl.textContent = 'معاينة الشيك — ' + activeCheckTitle;
    }
    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    overlay.hidden = false;
    overlay.style.display = 'flex';
    overlay.style.zIndex = '10050';
  }

  function runCheckPrintFromPreview() {
    if (!activeCheckHtml) {
      alertMsg('لا يوجد شيك للطباعة.');
      return;
    }
    printHtmlInFrame(buildDocumentHtml(activeCheckHtml, activeCheckTitle));
  }

  function openCheckPrintPreview(checkId) {
    checkId = parseInt(checkId, 10) || 0;
    if (checkId < 1) return;
    fetchCheckPrintHtml(checkId).then(function (data) {
      if (!data || !data.ok || !data.html) {
        alertMsg((data && data.message) || 'تعذر تحميل نموذج الشيك.');
        return;
      }
      showCheckPrintPreview(data.html, data.title || 'شيك صادر');
    });
  }

  function printList() {
    if (isCheckPreviewOpen()) {
      runCheckPrintFromPreview();
      return;
    }
    var table = document.getElementById('fin-outgoing-checks-table');
    if (!table || !table.tBodies.length || !table.tBodies[0].rows.length) {
      alertMsg('لا توجد شيكات للطباعة.', 'info');
      return;
    }
    window.print();
  }

  var printListBtn = document.getElementById('fin-outgoing-checks-print-list');
  if (printListBtn) {
    printListBtn.addEventListener('click', function () {
      closeCheckPreview();
      printList();
    });
  }

  page.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('.fin-outgoing-check-print-one') : null;
    if (!btn) return;
    ev.preventDefault();
    openCheckPrintPreview(btn.getAttribute('data-check-id'));
  });

  var closeBtn = document.getElementById('fin-oc-print-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', closeCheckPreview);
  }

  var runBtn = document.getElementById('fin-oc-print-run');
  if (runBtn) {
    runBtn.addEventListener('click', runCheckPrintFromPreview);
  }

  var overlay = document.getElementById('fin-oc-print-overlay');
  if (overlay) {
    overlay.addEventListener('click', function (ev) {
      if (ev.target === overlay) {
        closeCheckPreview();
      }
    });
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'print') return;
    if (!document.getElementById('fin-outgoing-checks-print-area')) return;
    e.preventDefault();
    printList();
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && isCheckPreviewOpen()) {
      closeCheckPreview();
    }
  });
})();
