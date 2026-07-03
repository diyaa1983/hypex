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

  /** إطار طباعة بعرض كامل — تجنّب width:0 الذي يُقصّ المحتوى على الهاتف */
  function printHtml(html, frameId) {
    frameId = frameId || 'm-doc-list-print-frame';
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
        frame.style.cssText =
          'position:fixed;left:0;top:0;width:210mm;min-width:680px;max-width:100vw;height:' +
          Math.ceil(h + 40) +
          'px;border:0;opacity:0;pointer-events:none;z-index:-1;overflow:visible;';
        setTimeout(function () {
          try {
            win.focus();
            win.print();
          } catch (_e) {}
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
