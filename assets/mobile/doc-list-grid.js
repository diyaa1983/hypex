/**
 * شبكة مربعات للقوائم + طباعة/PDF مشتركة (فواتير، سندات).
 */
(function (global) {
  'use strict';

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function waitForImages(root, cb) {
    if (global.MobilePdfExport && MobilePdfExport.waitForImages) {
      MobilePdfExport.waitForImages(root, cb);
      return;
    }
    cb();
  }

  function ensurePrintIsolateStyles() {
    var id = 'm-doc-list-print-style';
    if (document.getElementById(id)) return;
    var st = document.createElement('style');
    st.id = id;
    st.textContent =
      '@media print{' +
      'body.m-doc-print-active > *:not(iframe.m-print-frame){display:none!important;visibility:hidden!important;}' +
      'body.m-doc-print-active iframe.m-print-frame{' +
      'display:block!important;position:fixed!important;left:0!important;top:0!important;' +
      'width:100%!important;min-width:100%!important;max-width:none!important;' +
      'height:100%!important;min-height:100%!important;opacity:1!important;' +
      'visibility:visible!important;z-index:2147483647!important;pointer-events:auto!important;' +
      'border:0!important;margin:0!important;padding:0!important;overflow:visible!important;}' +
      '}';
    document.head.appendChild(st);
  }

  function isMobilePrintEnv() {
    if (typeof navigator === 'undefined') return false;
    return /Android|iPhone|iPad|iPod|Mobile|webOS|BlackBerry|IEMobile|Opera Mini/i.test(
      navigator.userAgent || ''
    );
  }

  /** نافذة منفصلة — الأكثر موثوقية على الهاتف عند الضغط من زر المستخدم */
  function printHtmlViaPopup(html) {
    var w = null;
    try {
      w = global.open('', '_blank', 'noopener,noreferrer');
    } catch (_e) {
      w = null;
    }
    if (!w) return false;
    try {
      w.document.open();
      w.document.write(html);
      w.document.close();
    } catch (_e2) {
      try {
        w.close();
      } catch (_e3) {}
      return false;
    }
    waitForImages(w.document.body, function () {
      setTimeout(function () {
        try {
          w.focus();
          w.print();
        } catch (_e4) {}
        setTimeout(function () {
          try {
            w.close();
          } catch (_e5) {}
        }, 800);
      }, 400);
    });
    return true;
  }

  /** إطار طباعة — على الهاتف يُطبَع المستند المحدد فقط وليس قائمة الشاشة */
  function printHtml(html, frameId) {
    frameId = frameId || 'm-doc-list-print-frame';
    if (!html) return;

    if (isMobilePrintEnv() && printHtmlViaPopup(html)) {
      return;
    }

    ensurePrintIsolateStyles();
    var frame = document.getElementById(frameId);
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = frameId;
      frame.className = 'm-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      document.body.appendChild(frame);
    }
    var win = frame.contentWindow;
    if (!win) return;
    var doc = win.document;
    doc.open();
    doc.write(html);
    doc.close();
    waitForImages(doc.body, function () {
      requestAnimationFrame(function () {
        var body = doc.body;
        var de = doc.documentElement;
        var h = Math.max(
          body ? body.scrollHeight : 0,
          body ? body.offsetHeight : 0,
          de ? de.scrollHeight : 0,
          400
        );
        var hiddenFrameStyle =
          'position:fixed;left:0;top:0;width:210mm;min-width:680px;max-width:100vw;height:' +
          Math.ceil(h + 40) +
          'px;border:0;opacity:0;pointer-events:none;z-index:-1;overflow:visible;';
        frame.style.cssText = hiddenFrameStyle;

        var cleaned = false;
        function cleanup() {
          if (cleaned) return;
          cleaned = true;
          document.body.classList.remove('m-doc-print-active');
          frame.style.cssText = hiddenFrameStyle;
        }

        window.addEventListener('afterprint', cleanup, { once: true });
        if (win.addEventListener) {
          win.addEventListener('afterprint', cleanup, { once: true });
        }

        document.body.classList.add('m-doc-print-active');
        setTimeout(function () {
          try {
            if (isMobilePrintEnv()) {
              global.focus();
              global.print();
            } else {
              win.focus();
              win.print();
            }
          } catch (_e) {
            cleanup();
          }
          setTimeout(cleanup, 6000);
        }, 450);
      });
    });
  }

  function downloadPdf(doc, filename) {
    if (!doc || (!doc.inner_pdf && !doc.inner && !doc.html_pdf && !doc.html)) {
      if (global.AppDialog && AppDialog.error) AppDialog.error('تعذر تجهيز PDF.');
      return Promise.reject(new Error('no_html'));
    }
    if (typeof global.MobilePdfExport === 'undefined') {
      if (global.AppDialog && AppDialog.error) {
        AppDialog.error('وحدة PDF غير محمّلة. أعد تحميل الصفحة.');
      }
      return Promise.reject(new Error('no_mobile_pdf_export'));
    }
    if (MobilePdfExport.exportMobilePdf) {
      return MobilePdfExport.exportMobilePdf(doc, {
        filename: filename || 'document.pdf',
        delayMs: 550,
      });
    }
    if (MobilePdfExport.exportReceiptOnMobile) {
      return MobilePdfExport.exportReceiptOnMobile(doc, {
        filename: filename || 'document.pdf',
        delayMs: 550,
      });
    }
    if (MobilePdfExport.exportFromDoc) {
      return MobilePdfExport.exportFromDoc(doc, {
        filename: filename || 'document.pdf',
        margin: [8, 10, 10, 10],
        delayMs: 500,
      });
    }
    if (MobilePdfExport.runIframe) {
      return MobilePdfExport.runIframe({
        fullDocument: doc.html_pdf || doc.html || '',
        html: doc.inner_pdf || doc.inner || '',
        css: doc.styles_pdf || doc.styles || '',
        filename: filename || 'document.pdf',
        margin: [8, 10, 10, 10],
        delayMs: 500,
      });
    }
    return Promise.reject(new Error('no_export'));
  }

  function mobileConfirm(msg) {
    if (global.AppDialog && AppDialog.confirm) {
      return AppDialog.confirm(msg, { title: 'تأكيد', okText: 'نعم', cancelText: 'إلغاء' }).then(function (ok) {
        return !!ok;
      });
    }
    return Promise.resolve(global.confirm(msg));
  }

  /**
   * @param {{ hubEl?: HTMLElement, barEl: HTMLElement, titleEl?: HTMLElement, onOpen?: function, onEdit?: function, onPrint?: function, onPdf?: function, onDelete?: function, deleteBtn?: HTMLElement, editBtn?: HTMLElement, openBtn?: HTMLElement, printBtn?: HTMLElement, pdfBtn?: HTMLElement }} opts
   */
  function createActionBar(opts) {
    opts = opts || {};
    var selected = null;

    function setHubSelected(on) {
      if (opts.hubEl) {
        opts.hubEl.classList.toggle('m-hub--doc-selected', !!on);
      }
    }

    function refreshDockInset() {
      if (global.MobileToolbar && typeof MobileToolbar.refresh === 'function') {
        MobileToolbar.refresh();
      } else if (global.AppMobile && typeof AppMobile.refreshActionDock === 'function') {
        AppMobile.refreshActionDock();
      }
    }

    function updateBar() {
      if (!selected) {
        if (global.MobileToolbar && typeof MobileToolbar.resetRoute === 'function') {
          MobileToolbar.resetRoute();
        } else if (global.MobileToolbar && typeof MobileToolbar.hideAll === 'function') {
          MobileToolbar.hideAll();
        } else if (opts.barEl) {
          opts.barEl.hidden = true;
        }
        setHubSelected(false);
        refreshDockInset();
        return;
      }
      setHubSelected(true);
      if (opts.titleEl) {
        opts.titleEl.textContent = selected.title || selected.subtitle || '';
        opts.titleEl.hidden = false;
      }
      if (opts.deleteBtn) {
        opts.deleteBtn.hidden = !selected.canDelete;
      }
      if (opts.editBtn) {
        opts.editBtn.hidden = selected.canEdit === false;
      }
      if (opts.openBtn) {
        opts.openBtn.hidden = false;
      }
      if (opts.printBtn) {
        opts.printBtn.hidden = false;
      }
      if (opts.pdfBtn) {
        opts.pdfBtn.hidden = false;
      }
      if (global.MobileToolbar && typeof MobileToolbar.ensureVisible === 'function') {
        MobileToolbar.ensureVisible();
      } else if (opts.barEl) {
        opts.barEl.hidden = false;
      }
      refreshDockInset();
    }

    var itemSelector = opts.itemSelector || '.m-doc-tile';
    var selectedClass = opts.selectedClass || 'm-doc-tile--selected';

    function select(item, tileEl) {
      if (!item) {
        selected = null;
        document.querySelectorAll('.' + selectedClass).forEach(function (t) {
          t.classList.remove(selectedClass);
        });
        updateBar();
        return;
      }
      selected = item;
      document.querySelectorAll(itemSelector).forEach(function (t) {
        t.classList.toggle(selectedClass, t === tileEl);
      });
      updateBar();
    }

    function getSelected() {
      return selected;
    }

    function bindButton(btn, fn) {
      if (!btn || !fn) return;
      btn.addEventListener('click', function () {
        if (!selected) return;
        fn(selected);
      });
    }

    bindButton(opts.openBtn, opts.onOpen);
    bindButton(opts.editBtn, opts.onEdit);
    bindButton(opts.printBtn, opts.onPrint);
    bindButton(opts.pdfBtn, opts.onPdf);
    bindButton(opts.deleteBtn, opts.onDelete);

    return { select: select, getSelected: getSelected, updateBar: updateBar, mobileConfirm: mobileConfirm };
  }

  global.MobileDocList = {
    escapeHtml: escapeHtml,
    printHtml: printHtml,
    downloadPdf: downloadPdf,
    createActionBar: createActionBar,
  };
})(typeof window !== 'undefined' ? window : this);
