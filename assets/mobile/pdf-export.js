/**

 * تصدير html2pdf للموبايل — معاينة مخفية (موثوق) أو iframe احتياطي.

 */

(function (global) {

  'use strict';



  var PDF_ROOT_ID = 'pdf-export-root';

  var PDF_STYLE_ID = 'mobile-pdf-export-style';

  var IFRAME_W = 720;

  var html2pdfLoadPromise = null;

  function ensureHtml2Pdf() {
    if (typeof global.html2pdf !== 'undefined') {
      return Promise.resolve();
    }
    if (html2pdfLoadPromise) {
      return html2pdfLoadPromise;
    }
    html2pdfLoadPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
      s.crossOrigin = 'anonymous';
      s.async = true;
      s.onload = function () {
        resolve();
      };
      s.onerror = function () {
        html2pdfLoadPromise = null;
        reject(new Error('no_html2pdf'));
      };
      document.head.appendChild(s);
    });
    return html2pdfLoadPromise;
  }

  function isReceiptDoc(doc) {
    if (!doc) return false;
    var s = String(doc.inner_pdf || doc.inner || doc.html_pdf || doc.html || '');
    return s.indexOf('rcp-print-root') >= 0 || s.indexOf('m-rc-pdf-sheet') >= 0;
  }

  function isMobileReceiptPdfSheet(doc) {
    if (!doc) return false;
    var s = String(doc.inner_pdf || doc.html_pdf || '');
    return s.indexOf('m-rc-pdf-sheet') >= 0;
  }

  function pdfCaptureScale() {
    try {
      if (global.matchMedia && global.matchMedia('(max-width: 900px)').matches) return 1.5;
    } catch (_e) {}
    if (global.navigator && global.navigator.maxTouchPoints > 0) return 1.5;
    return 2;
  }

  function measureElement(el) {
    if (!el) return { w: 680, h: 900 };
    var w = Math.max(el.scrollWidth || 0, el.offsetWidth || 0, 640);
    var h = Math.max(el.scrollHeight || 0, el.offsetHeight || 0, 200);
    var inner = el.querySelector && el.querySelector('.rcp-print-root');
    if (inner) {
      w = Math.max(w, inner.scrollWidth || 0, inner.offsetWidth || 0);
      h = Math.max(h, inner.scrollHeight || 0, inner.offsetHeight || 0);
    }
    return {
      w: Math.min(Math.max(Math.ceil(w), 640), IFRAME_W),
      h: Math.ceil(h) + 32,
    };
  }

  function buildHtml2CanvasOpts(el, scale, forReceipt) {
    var m = measureElement(el);
    var opts = {
      scale: scale != null ? scale : pdfCaptureScale(),
      useCORS: true,
      allowTaint: true,
      logging: false,
      backgroundColor: '#ffffff',
      windowWidth: m.w,
      width: m.w,
      scrollX: 0,
      scrollY: 0,
      x: 0,
      y: 0,
    };
    if (!forReceipt) {
      opts.height = m.h;
      opts.windowHeight = m.h;
    }
    return opts;
  }

  function buildReceiptHtml2CanvasOpts(el, scale, mobileSheet) {
    var w = mobileSheet ? 400 : 680;
    var s = scale != null ? scale : mobileSheet ? 2 : pdfCaptureScale();
    return {
      scale: s,
      useCORS: true,
      allowTaint: true,
      logging: false,
      backgroundColor: '#ffffff',
      windowWidth: w,
      width: w,
      scrollX: 0,
      scrollY: 0,
      letterRendering: true,
    };
  }

  function getJsPDFCtor() {
    if (global.jspdf && global.jspdf.jsPDF) return global.jspdf.jsPDF;
    if (global.jsPDF) return global.jsPDF;
    return null;
  }

  /** ضبط صورة السند كاملة داخل صفحة A4 واحدة */
  function saveReceiptCanvasFitA4(canvas, filename, marginMm) {
    var JsPDF = getJsPDFCtor();
    if (!JsPDF) {
      throw new Error('no_jspdf');
    }
    marginMm = marginMm || [5, 6, 6, 6];
    var mt = marginMm[0] || 5;
    var ml = marginMm[1] || 6;
    var mb = marginMm[2] || 6;
    var mr = marginMm[3] || 6;
    var pdf = new JsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
    var pageW = pdf.internal.pageSize.getWidth();
    var pageH = pdf.internal.pageSize.getHeight();
    var availW = pageW - ml - mr;
    var availH = pageH - mt - mb;
    var pxW = canvas.width;
    var pxH = canvas.height;
    if (pxW < 1 || pxH < 1) {
      throw new Error('empty_canvas');
    }
    var ratio = pxH / pxW;
    var pdfW = availW;
    var pdfH = pdfW * ratio;
    if (pdfH > availH) {
      pdfH = availH;
      pdfW = pdfH / ratio;
    }
    var x = ml + (availW - pdfW) / 2;
    pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', x, mt, pdfW, pdfH);
    pdf.save(filename || 'document.pdf');
  }

  function prepareReceiptIframeCapture(doc, root, frame) {
    var w = root.querySelector && root.querySelector('.m-rc-pdf-sheet') ? 400 : 680;
    if (doc.body) {
      doc.body.style.margin = '0';
      doc.body.style.padding = '8px';
      doc.body.style.overflow = 'visible';
      doc.body.style.width = w + 'px';
    }
    if (doc.documentElement) {
      doc.documentElement.style.overflow = 'visible';
    }
    root.style.width = w + 'px';
    root.style.maxWidth = w + 'px';
    root.style.overflow = 'visible';
    var h = Math.max(root.scrollHeight || 0, root.offsetHeight || 0, 400) + 48;
    frame.style.width = w + 'px';
    frame.style.height = h + 'px';
    return { w: w, h: h };
  }

  function wrapPdfExportInner(inner) {
    if (!inner) return '';
    if (inner.indexOf('id="' + PDF_ROOT_ID + '"') >= 0 || inner.indexOf("id='" + PDF_ROOT_ID + "'") >= 0) {
      return inner;
    }
    return '<div id="' + PDF_ROOT_ID + '">' + inner + '</div>';
  }

  /** @param {HTMLElement|Document} root */
  function waitForImages(root, cb) {

    if (!root) {

      cb();

      return;

    }

    var scope = root.querySelectorAll ? root : root.documentElement || root;

    var imgs = scope.querySelectorAll ? scope.querySelectorAll('img') : [];

    if (!imgs.length) {

      cb();

      return;

    }

    var left = imgs.length;

    var done = function () {

      left -= 1;

      if (left <= 0) cb();

    };

    imgs.forEach(function (img) {

      if (img.complete) {

        done();

      } else {

        img.addEventListener('load', done);

        img.addEventListener('error', done);

      }

    });

  }



  function removeFrame(frame) {

    if (frame && frame.parentNode) {

      frame.parentNode.removeChild(frame);

    }

  }



  function stripPdfWatermarks(html) {

    var box = document.createElement('div');

    box.innerHTML = html;

    box.querySelectorAll('.doc-print-watermark, .doc-print-watermark--overlay').forEach(function (el) {

      el.parentNode.removeChild(el);

    });

    var root = box.querySelector('.doc-print-watermark-root');

    if (root && root.parentNode) {

      while (root.firstChild) {

        root.parentNode.insertBefore(root.firstChild, root);

      }

      root.parentNode.removeChild(root);

    }

    return box.innerHTML;

  }



  function extractInnerFromFullDocument(fullDocument) {

    if (!fullDocument) return '';

    var box = document.createElement('div');

    box.innerHTML = fullDocument;

    var root =
      box.querySelector('#' + PDF_ROOT_ID) ||
      box.querySelector('.m-rc-pdf-sheet') ||
      box.querySelector('.rcp-print-root');

    if (root) {
      if (root.classList && root.classList.contains('m-rc-pdf-sheet')) {
        return root.outerHTML;
      }
      return root.innerHTML;
    }

    var body = box.querySelector('body');

    return body ? body.innerHTML : fullDocument;

  }



  function injectPdfStyles(css, styleId) {

    styleId = styleId || PDF_STYLE_ID;

    var el = document.getElementById(styleId);

    if (!el) {

      el = document.createElement('style');

      el.id = styleId;

      document.head.appendChild(el);

    }

    el.textContent = css || '';

  }



  function clearPdfStyles(styleId) {

    var el = document.getElementById(styleId || PDF_STYLE_ID);

    if (el) el.textContent = '';

  }



  function findPdfPreviewUi() {

    var pairs = [

      ['m-rc-list-pdf-overlay', 'm-rc-list-pdf-preview'],

      ['m-rc-pdf-overlay', 'm-rc-pdf-preview'],

      ['m-inv-pdf-overlay', 'm-inv-pdf-preview'],

    ];

    for (var i = 0; i < pairs.length; i++) {

      var overlay = document.getElementById(pairs[i][0]);

      var preview = document.getElementById(pairs[i][1]);

      if (overlay && preview) {

        return { overlay: overlay, preview: preview };

      }

    }

    return null;

  }



  /**

   * تصدير من معاينة مخفية — نفس نهج فاتورة الموبايل (الأكثر ثباتاً).

   * @param {object} doc

   * @param {{ overlay?: HTMLElement, preview?: HTMLElement, filename?: string, margin?: number[], delayMs?: number }} opts

   */

  function exportFromDoc(doc, opts) {

    opts = opts || {};

    return ensureHtml2Pdf().then(function () {
      return exportFromDocInner(doc, opts);
    });
  }

  function exportFromDocInner(doc, opts) {

    if (typeof global.html2pdf === 'undefined') {

      return Promise.reject(new Error('no_html2pdf'));

    }



    var ui = opts.overlay && opts.preview ? { overlay: opts.overlay, preview: opts.preview } : findPdfPreviewUi();

    if (!ui) {
      return runIframe({
        fullDocument: doc.html_pdf || doc.html || '',
        html: doc.inner_pdf || doc.inner || '',
        css: doc.styles_pdf || doc.styles || '',
        filename: opts.filename,
        margin: opts.margin,
        delayMs: opts.delayMs,
        isReceipt: isReceiptDoc(doc),
      });
    }

    var inner = doc.inner_pdf || doc.inner || '';

    if (!inner && (doc.html_pdf || doc.html)) {

      inner = extractInnerFromFullDocument(doc.html_pdf || doc.html || '');

    }

    inner = wrapPdfExportInner(stripPdfWatermarks(inner));

    if (!inner) {
      return Promise.reject(new Error('no_html'));
    }



    var css = doc.styles_pdf || doc.styles || '';

    var overlay = ui.overlay;

    var preview = ui.preview;

    var wasHidden = overlay.hidden;

    if (overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }

    injectPdfStyles(css);
    preview.innerHTML = inner;
    var isMobileSheet =
      isMobileReceiptPdfSheet(doc) || !!preview.querySelector('.m-rc-pdf-sheet');
    var isReceipt = isMobileSheet || !!preview.querySelector('.rcp-print-root');
    var margin = opts.margin != null ? opts.margin : isReceipt ? [5, 6, 6, 6] : [8, 10, 10, 10];
    var sheetW = isMobileSheet ? 400 : 680;

    preview.style.boxSizing = 'border-box';
    preview.style.width = isMobileSheet ? sheetW + 'px' : '210mm';
    preview.style.minWidth = isMobileSheet ? sheetW + 'px' : '210mm';
    preview.style.maxWidth = isMobileSheet ? sheetW + 'px' : '210mm';
    preview.style.margin = '0 auto';
    preview.style.padding = isMobileSheet ? '0' : isReceipt ? '5mm 4mm 8mm' : '8mm 6mm 12mm';
    preview.style.fontSize = '';
    preview.style.background = '#fff';
    preview.style.direction = 'rtl';
    preview.style.overflow = 'visible';
    preview.style.position = 'relative';
    preview.style.left = '0';
    preview.style.top = '0';

    var captureEl =
      preview.querySelector('.m-rc-pdf-sheet') ||
      preview.querySelector('#' + PDF_ROOT_ID) ||
      preview.querySelector('.rcp-print-root') ||
      preview;

    overlay.removeAttribute('hidden');
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    overlay.style.display = 'block';
    overlay.style.opacity = '0.01';
    overlay.style.visibility = 'visible';
    overlay.style.position = 'fixed';
    overlay.style.left = '-12000px';
    overlay.style.top = '0';
    overlay.style.right = 'auto';
    overlay.style.zIndex = '99999';
    overlay.style.overflow = 'visible';
    overlay.style.background = '#fff';

    function syncCaptureBox() {
      var capSize = measureElement(captureEl);
      var boxW = isMobileSheet ? sheetW : capSize.w;
      overlay.style.width = boxW + 'px';
      overlay.style.maxWidth = boxW + 'px';
      overlay.style.height = capSize.h + 'px';
      overlay.style.minHeight = capSize.h + 'px';
      preview.style.minHeight = capSize.h + 'px';
      preview.style.height = 'auto';
      preview.style.overflow = 'visible';
    }
    syncCaptureBox();



    function cleanup() {

      preview.innerHTML = '';

      preview.style.width = '';

      preview.style.minWidth = '';

      preview.style.maxWidth = '';

      preview.style.margin = '';

      preview.style.padding = '';

      preview.style.direction = '';

      preview.style.overflow = '';

      preview.style.position = '';

      preview.style.left = '';

      preview.style.top = '';
      preview.style.minHeight = '';
      preview.style.height = '';
      preview.style.fontSize = '';

      overlay.style.opacity = '';
      overlay.style.minHeight = '';

      overlay.style.display = '';

      overlay.style.visibility = '';

      overlay.style.left = '';

      overlay.style.right = '';

      overlay.style.width = '';

      overlay.style.maxWidth = '';

      overlay.style.overflow = '';

      if (wasHidden) {

        overlay.hidden = true;

        overlay.setAttribute('hidden', '');

        overlay.setAttribute('aria-hidden', 'true');

      }

      clearPdfStyles();

    }



    return new Promise(function (resolve, reject) {

      waitForImages(preview, function () {

        requestAnimationFrame(function () {

          requestAnimationFrame(function () {

            setTimeout(function () {
              captureEl =
                preview.querySelector('.m-rc-pdf-sheet') ||
                preview.querySelector('#' + PDF_ROOT_ID) ||
                preview.querySelector('.rcp-print-root') ||
                preview;
              syncCaptureBox();
              var fname = opts.filename || 'document.pdf';
              var canvasScale = isReceipt ? pdfCaptureScale() : 2;

              function runSave() {
                var canvasOpts = isMobileSheet
                  ? buildReceiptHtml2CanvasOpts(captureEl, canvasScale, true)
                  : buildHtml2CanvasOpts(captureEl, canvasScale, isReceipt);
                return global
                  .html2pdf()
                  .set({
                    margin: margin,
                    filename: fname,
                    image: { type: 'png', quality: 1 },
                    html2canvas: canvasOpts,
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'] },
                  })
                  .from(captureEl)
                  .save();
              }

              function runSaveFallback() {
                if (!doc.html_pdf) {
                  return Promise.reject(new Error('no_html_pdf'));
                }
                cleanup();
                return runIframe({
                  fullDocument: doc.html_pdf,
                  filename: fname,
                  margin: margin,
                  delayMs: 100,
                  isReceipt: true,
                });
              }

              try {
                runSave()
                  .then(function () {
                    cleanup();
                    resolve();
                  })
                  .catch(function () {
                    runSaveFallback()
                      .then(resolve)
                      .catch(function (err) {
                        cleanup();
                        reject(err);
                      });
                  });

              } catch (e) {

                cleanup();

                reject(e);

              }

            }, opts.delayMs != null ? opts.delayMs : 450);

          });

        });

      });

    });

  }



  function measureCaptureSize(doc, root) {
    var el =
      (root && root.querySelector && root.querySelector('#' + PDF_ROOT_ID)) ||
      (doc && doc.getElementById && doc.getElementById(PDF_ROOT_ID)) ||
      root ||
      (doc && doc.body);
    var m = measureElement(el);
    if (m.h < 200) {
      m.h = 900;
    }
    return m;
  }



  /**

   * تصدير من iframe — احتياطي عند غياب عناصر المعاينة.

   */

  function runIframe(opts) {

    opts = opts || {};

    return ensureHtml2Pdf().then(function () {
      return runIframeInner(opts);
    });
  }

  function runIframeInner(opts) {

    if (typeof global.html2pdf === 'undefined') {

      return Promise.reject(new Error('no_html2pdf'));

    }

    var fullDocument = opts.fullDocument || '';

    var html = opts.html || '';

    var css = opts.css || '';

    if (!fullDocument && !html) {

      return Promise.reject(new Error('no_html'));

    }



    var frame = document.createElement('iframe');

    frame.setAttribute('aria-hidden', 'true');

    frame.setAttribute('tabindex', '-1');

    frame.style.cssText =
      'position:fixed;left:-12000px;top:0;width:' +
      IFRAME_W +
      'px;height:1200px;border:0;opacity:0.01;pointer-events:none;z-index:99999;overflow:visible;';

    document.body.appendChild(frame);



    var doc = frame.contentDocument;

    if (!doc) {

      removeFrame(frame);

      return Promise.reject(new Error('no_iframe_doc'));

    }



    doc.open();

    if (fullDocument) {

      doc.write(fullDocument);

    } else {

      doc.write(

        '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">' +

          '<style>' +

          css +

          '</style></head><body><div id="' +

          PDF_ROOT_ID +

          '">' +

          html +

          '</div></body></html>'

      );

    }

    doc.close();



    var margin = opts.margin != null ? opts.margin : opts.isReceipt ? [5, 6, 6, 6] : [8, 10, 10, 10];

    return new Promise(function (resolve, reject) {
      waitForImages(doc.body, function () {

        requestAnimationFrame(function () {

          requestAnimationFrame(function () {

            setTimeout(function () {

              var root =
                doc.getElementById(PDF_ROOT_ID) ||
                doc.querySelector('.rcp-print-root') ||
                doc.body;

              if (!root) {
                removeFrame(frame);
                reject(new Error('no_root'));
                return;
              }

              if (opts.isReceipt) {
                doc.querySelectorAll('.doc-print-watermark--overlay').forEach(function (el) {
                  if (el.parentNode) el.parentNode.removeChild(el);
                });
              }

              try {
                var canvasScale = opts.isReceipt ? pdfCaptureScale() : 2;
                var fname = opts.filename || 'document.pdf';

                if (opts.isReceipt) {
                  prepareReceiptIframeCapture(doc, root, frame);
                  var sheetEl = root.querySelector('.m-rc-pdf-sheet') || root;
                  var mobileSheet = !!root.querySelector('.m-rc-pdf-sheet');
                  global
                    .html2pdf()
                    .set({
                      margin: margin,
                      filename: fname,
                      image: { type: 'png', quality: 1 },
                      html2canvas: buildReceiptHtml2CanvasOpts(sheetEl, canvasScale, mobileSheet),
                      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                      pagebreak: { mode: ['css', 'legacy'] },
                    })
                    .from(sheetEl)
                    .save()
                    .then(function () {
                      removeFrame(frame);
                      resolve();
                    })
                    .catch(function (err2) {
                      removeFrame(frame);
                      reject(err2);
                    });
                  return;
                }

                var size = measureCaptureSize(doc, root);
                frame.style.height = Math.min(size.h + 60, 16000) + 'px';
                frame.style.width = size.w + 'px';

                global
                  .html2pdf()
                  .set({
                    margin: margin,
                    filename: fname,
                    image: { type: 'jpeg', quality: 0.95 },
                    html2canvas: buildHtml2CanvasOpts(root, canvasScale, false),
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'] },
                  })
                  .from(root)
                  .save()
                  .then(function () {
                    removeFrame(frame);
                    resolve();
                  })
                  .catch(function (err) {
                    removeFrame(frame);
                    reject(err);
                  });

              } catch (e) {

                removeFrame(frame);

                reject(e);

              }

            }, opts.delayMs != null ? opts.delayMs : 500);

          });

        });

      });

    });

  }



  /** @deprecated */

  function run(preview, opts) {

    return exportFromDoc(

      { inner: preview ? preview.innerHTML : '', styles: '' },

      { preview: preview, overlay: opts.overlay, filename: opts.filename, margin: opts.margin }

    );

  }



  function isMobileDevice() {
    try {
      if (global.matchMedia && global.matchMedia('(max-width: 900px)').matches) return true;
    } catch (_e) {}
    return /Android|webOS|iPhone|iPad|iPod|Mobile|IEMobile|Opera Mini/i.test(
      String(global.navigator && global.navigator.userAgent ? global.navigator.userAgent : '')
    );
  }

  function isReceiptMobileDoc(doc) {
    if (!doc) return false;
    if (doc.mobile_pdf) return true;
    var s = String(doc.inner_pdf || doc.html_pdf || '');
    return s.indexOf('m-rc-pdf-sheet') >= 0 || s.indexOf('m-inv-pdf-sheet') >= 0;
  }

  /** تنزيل ملف PDF من الخادم (Dompdf) — تنزيل مباشر بدون طباعة */
  function downloadServerPdf(url, filename) {
    if (!url) {
      return Promise.reject(new Error('no_pdf_url'));
    }
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('pdf_http_' + r.status);
        }
        var ct = (r.headers.get('Content-Type') || '').toLowerCase();
        if (ct.indexOf('pdf') < 0 && ct.indexOf('octet-stream') < 0) {
          throw new Error('not_pdf');
        }
        return r.blob();
      })
      .then(function (blob) {
        var name = filename || 'document.pdf';
        var a = document.createElement('a');
        var objUrl = URL.createObjectURL(blob);
        a.href = objUrl;
        a.download = name;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
          URL.revokeObjectURL(objUrl);
          if (a.parentNode) a.parentNode.removeChild(a);
        }, 200);
      });
  }

  /**
   * @deprecated احتياطي — طباعة المتصفح (حفظ كـ PDF). لا يُستخدم لسند القبض.
   */
  function printDocumentAsPdf(fullHtml, opts) {
    opts = opts || {};
    if (!fullHtml) {
      return Promise.reject(new Error('no_html'));
    }

    function openPrint() {
      return new Promise(function (resolve, reject) {
        var frame = document.getElementById('m-mobile-pdf-print-frame');
        if (!frame) {
          frame = document.createElement('iframe');
          frame.id = 'm-mobile-pdf-print-frame';
          frame.setAttribute('aria-hidden', 'true');
          frame.setAttribute('tabindex', '-1');
          document.body.appendChild(frame);
        }
        var win = frame.contentWindow;
        var fdoc = win.document;
        fdoc.open();
        fdoc.write(fullHtml);
        fdoc.close();
        if (opts.filename) {
          try {
            fdoc.title = String(opts.filename);
          } catch (_e) {}
        }
        waitForImages(fdoc.body, function () {
          requestAnimationFrame(function () {
            var body = fdoc.body;
            var de = fdoc.documentElement;
            var h = Math.max(
              body ? body.scrollHeight : 0,
              body ? body.offsetHeight : 0,
              de ? de.scrollHeight : 0,
              480
            );
            frame.style.cssText =
              'position:fixed;left:0;top:0;width:400px;min-width:400px;max-width:100vw;height:' +
              Math.ceil(h + 48) +
              'px;border:0;opacity:0;pointer-events:none;z-index:-1;overflow:visible;';
            setTimeout(function () {
              try {
                win.focus();
                win.print();
                resolve();
              } catch (e) {
                reject(e);
              }
            }, opts.delayMs != null ? opts.delayMs : 550);
          });
        });
      });
    }

    if (opts.skipHint) {
      return openPrint();
    }
    if (global.AppDialog && AppDialog.info) {
      return AppDialog.info(
        'ستُفتح شاشة الطباعة. اختر «حفظ كـ PDF» أو «طباعة إلى PDF» من القائمة (وليس «طباعة» عادية فقط).',
        { title: 'تصدير PDF' }
      ).then(openPrint);
    }
    return openPrint();
  }

  /** تنزيل PDF من الخادم (mPDF) — سند قبض، فاتورة، كشف حساب. احتياطي: html2pdf */
  function exportMobilePdf(doc, opts) {
    opts = opts || {};
    var serverUrl = doc && doc.pdf_download_url ? String(doc.pdf_download_url) : '';
    if (serverUrl) {
      return downloadServerPdf(serverUrl, opts.filename || 'document.pdf').catch(function (err) {
        if (typeof global.html2pdf !== 'undefined' && MobilePdfExport.exportFromDoc) {
          return MobilePdfExport.exportFromDoc(doc, opts);
        }
        return Promise.reject(err);
      });
    }
    return exportFromDoc(doc, opts);
  }

  /** @deprecated استخدم exportMobilePdf */
  function exportReceiptOnMobile(doc, opts) {
    return exportMobilePdf(doc, opts);
  }

  global.MobilePdfExport = {
    PDF_ROOT_ID: PDF_ROOT_ID,
    isMobileDevice: isMobileDevice,
    isReceiptMobileDoc: isReceiptMobileDoc,
    downloadServerPdf: downloadServerPdf,
    exportMobilePdf: exportMobilePdf,
    printDocumentAsPdf: printDocumentAsPdf,
    exportReceiptOnMobile: exportReceiptOnMobile,
    waitForImages: waitForImages,
    exportFromDoc: exportFromDoc,
    runIframe: runIframe,
    ensureHtml2Pdf: ensureHtml2Pdf,
    run: run,

    resetPreviewStyles: function (preview) {

      if (!preview) return;

      preview.style.width = '';

      preview.style.maxWidth = '';

      preview.style.minWidth = '';

      preview.style.margin = '';

      preview.style.overflow = '';

      preview.style.position = '';

      preview.style.left = '';

      preview.style.right = '';

      preview.style.top = '';

      preview.style.direction = '';

    },

  };

})(typeof window !== 'undefined' ? window : this);


