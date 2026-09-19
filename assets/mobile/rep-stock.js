(function () {
  'use strict';

  var cfg = window.MRepStockConfig || {};
  var pdfBtn = document.getElementById('m-rep-stock-pdf');
  var statusEl = document.getElementById('m-rep-stock-status');
  var pdfView = document.getElementById('m-rep-pdf-view');
  var pdfFrame = document.getElementById('m-rep-pdf-view-frame');
  var pdfViewClose = document.getElementById('m-rep-pdf-view-close');
  var pdfViewDl = document.getElementById('m-rep-pdf-view-dl');
  var searchInput = document.querySelector('.m-rep-stock-search input[name="q"]');
  var lastPdfBlobUrl = '';
  var busy = false;

  function showStatus(msg, type) {
    if (!statusEl) return;
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.className = 'm-alert m-alert--' + (type === 'error' ? 'error' : 'success');
  }

  function closePdfView() {
    if (pdfView) {
      pdfView.hidden = true;
      pdfView.setAttribute('aria-hidden', 'true');
    }
    if (pdfFrame) pdfFrame.removeAttribute('src');
    if (lastPdfBlobUrl) {
      URL.revokeObjectURL(lastPdfBlobUrl);
      lastPdfBlobUrl = '';
    }
    document.body.classList.remove('m-rep-pdf-open');
  }

  function showPdfBlob(blob, filename) {
    closePdfView();
    if (!blob || blob.size < 32) {
      showStatus('ملف PDF فارغ أو تالف.', 'error');
      return;
    }
    var url = URL.createObjectURL(blob);
    lastPdfBlobUrl = url;
    if (pdfFrame) pdfFrame.src = url;
    if (pdfViewDl) {
      pdfViewDl.href = url;
      pdfViewDl.download = filename || 'رصيد عهدة.pdf';
    }
    if (pdfView) {
      pdfView.hidden = false;
      pdfView.setAttribute('aria-hidden', 'false');
      document.body.classList.add('m-rep-pdf-open');
    }
  }

  function buildPdfUrl() {
    if (!cfg.pdfApi) return '';
    var q = searchInput ? String(searchInput.value || '').trim() : '';
    var url = cfg.pdfApi + (cfg.pdfApi.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
    if (q) url += '&q=' + encodeURIComponent(q);
    var wh = parseInt(cfg.warehouseId || 0, 10) || 0;
    if (!wh && pdfBtn) {
      wh = parseInt(pdfBtn.getAttribute('data-warehouse-id') || '0', 10) || 0;
    }
    if (wh > 0) url += '&warehouse_id=' + encodeURIComponent(String(wh));
    return url;
  }

  function requestStockPdf() {
    if (busy) return;
    var url = buildPdfUrl();
    if (!url) {
      showStatus('رابط PDF غير مهيأ.', 'error');
      return;
    }

    busy = true;
    if (pdfBtn) pdfBtn.disabled = true;
    showStatus('جاري تجهيز PDF...', 'success');

    fetch(url, { method: 'GET', credentials: 'same-origin' })
      .then(function (r) {
        var ct = (r.headers.get('Content-Type') || '').toLowerCase();
        if (!r.ok) {
          return r.text().then(function (t) {
            throw new Error((t || '').trim() || ('خطأ ' + r.status));
          });
        }
        if (ct.indexOf('json') >= 0) {
          return r.json().then(function (j) {
            throw new Error(j.message || j.error || 'تعذر PDF');
          });
        }
        return r.blob();
      })
      .then(function (blob) {
        busy = false;
        if (pdfBtn) pdfBtn.disabled = false;
        showPdfBlob(blob, cfg.pdfFilename || 'رصيد عهدة.pdf');
        showStatus('تم فتح PDF.', 'success');
      })
      .catch(function (err) {
        busy = false;
        if (pdfBtn) pdfBtn.disabled = false;
        showStatus(err && err.message ? err.message : 'تعذر تجهيز PDF.', 'error');
      });
  }

  if (pdfBtn) pdfBtn.addEventListener('click', requestStockPdf);
  if (pdfViewClose) pdfViewClose.addEventListener('click', closePdfView);
})();
